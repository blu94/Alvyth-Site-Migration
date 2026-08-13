<?php

namespace Plugin\SiteMigration\Backend\Bundle;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Plugin\SiteMigration\Backend\Runs\Run;
use RuntimeException;
use Throwable;

/**
 * Hands a finished bundle to the operator's own computer, and takes it back off the web afterwards.
 *
 * **Why this is not simply a link.** A plugin registers no routes, so it cannot stream a file, and
 * `savePageData` always wraps its return in `response()->json()` — there is no path by which a
 * package returns bytes. The schema engine *does* have a `download` action that fetches a URL as an
 * authenticated blob, but it lives in `useModuleWrapper`, which custom pages do not use, and it
 * would need an endpoint that returns a blob in any case.
 *
 * That leaves exactly one mechanism: the **public disk**, which is the only directory nginx serves.
 * So the bundle — which is generated and kept under `storage/app`, off the web — is *copied* there
 * under a name nobody can guess, downloaded, and the copy removed.
 *
 * **It transits; it is not stored.** Three things bound the window:
 *
 * 1. The name is 32 random bytes. Guessing it is not a realistic attack.
 * 2. The copy is removed the next time any Site Migration screen is opened, and by the operator's
 *    own "Remove the download link" press.
 * 3. Nothing links to the directory and nothing indexes it.
 *
 * A shorter window is not available without a route, and a route is not available to a plugin.
 */
class Download
{
    /** Where the transit copies live on the public disk. */
    private const DIRECTORY = 'site-migration-downloads';

    /**
     * How long a link is allowed to exist before it is swept.
     *
     * Long enough to survive a slow download of a large bundle on a poor connection; short enough
     * that a link nobody used is gone within the hour.
     */
    private const MINUTES = 60;

    /**
     * Publish a run's bundle and return the URL to fetch it from.
     *
     * @throws RuntimeException when the run has no bundle to publish
     */
    public function publish(Run $run): string
    {
        $bundle = $run->bundlePath();

        if ($bundle === null) {
            throw new RuntimeException('This run has not finished writing its bundle yet.');
        }

        $this->sweep();
        $this->withdraw($run);

        $name = bin2hex(random_bytes(32)) . '.zip';
        $key  = self::DIRECTORY . '/' . $name;

        $source = fopen($bundle, 'rb');

        if ($source === false) {
            throw new RuntimeException('The bundle could not be read.');
        }

        try {
            // `writeStream`, not `put`: a media-included bundle is tens or hundreds of megabytes,
            // and reading it into a PHP string to hand to the disk is that much memory for a copy.
            Storage::disk('public')->writeStream($key, $source);
        } finally {
            fclose($source);
        }

        // `AssetRepository` does the same dance: when `public/storage` is a real directory rather
        // than a symlink — the usual state on Windows and on hosts that forbid symlinks — a file
        // written to the disk is not under the webroot until it is copied there.
        $this->mirrorToWebroot($key);

        $run->set('download_name', $name)
            ->set('download_at', now()->toDateTimeString())
            ->save();

        return rtrim((string) config('app.url'), '/') . '/storage/' . $key;
    }

    /**
     * Take a run's published copy back off the web.
     *
     * Called by the operator's own press, before publishing a fresh copy, and by the sweep. Safe to
     * call when there is nothing to remove.
     */
    public function withdraw(Run $run): void
    {
        $name = (string) $run->get('download_name', '');

        if ($name === '') {
            return;
        }

        $this->forget(self::DIRECTORY . '/' . $name);

        $run->set('download_name', null)->set('download_at', null)->save();
    }

    /** The live URL for a run's published copy, or null if it has none. */
    public function url(Run $run): ?string
    {
        $name = (string) $run->get('download_name', '');

        if ($name === '' || ! Storage::disk('public')->exists(self::DIRECTORY . '/' . $name)) {
            return null;
        }

        return rtrim((string) config('app.url'), '/') . '/storage/' . self::DIRECTORY . '/' . $name;
    }

    /**
     * Remove every published copy older than the window.
     *
     * Swept on screen load rather than on a schedule, because a plugin ships no console command and
     * no scheduled work — `PluginServiceProvider` registers an autoloader and listeners, nothing
     * else. The screens where downloads are made are the ones the operator visits.
     */
    public function sweep(): void
    {
        try {
            $cutoff = now()->subMinutes(self::MINUTES)->getTimestamp();
            $disk   = Storage::disk('public');

            foreach ($disk->files(self::DIRECTORY) as $file) {
                if ($disk->lastModified($file) < $cutoff) {
                    $this->forget($file);
                }
            }
        } catch (Throwable) {
            // Housekeeping. A sweep that could not run is not a reason to refuse the screen, and
            // the next visit tries again.
        }
    }

    /** Delete one transit copy from both the disk and the webroot mirror. */
    private function forget(string $key): void
    {
        try {
            Storage::disk('public')->delete($key);

            $mirrored = public_path('storage/' . ltrim($key, '/'));

            if (is_file($mirrored)) {
                File::delete($mirrored);
            }
        } catch (Throwable) {
            // Best effort: a copy that could not be removed is swept again on the next visit.
        }
    }

    private function mirrorToWebroot(string $key): void
    {
        $target = public_path('storage/' . ltrim($key, '/'));

        if (is_file($target)) {
            return;
        }

        try {
            $absolute = Storage::disk('public')->path($key);

            if (! is_file($absolute)) {
                return;
            }

            File::ensureDirectoryExists(dirname($target));

            // `copy` rather than a stream pair: this is the fallback path for hosts without a
            // working symlink, and `File::copy` is already what core uses here.
            File::copy($absolute, $target);
        } catch (Throwable) {
            // If the mirror fails the symlink is probably doing its job, and the URL resolves
            // anyway. A failure here that is real surfaces as a 404 on the link, which the
            // operator can act on.
        }
    }
}
