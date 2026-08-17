<?php

namespace Plugin\SiteMigration\Backend\Bundle;

use App\Models\Asset;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Plugin\SiteMigration\Backend\Runs\Run;
use RuntimeException;
use Throwable;

/**
 * Hands a finished bundle to the operator's own computer.
 *
 * **This used to go through the webroot, and it no longer does.** A plugin registers no routes, so
 * it cannot stream a file, and `savePageData` always wraps its return in `response()->json()` —
 * there is no path by which a package returns bytes. Until core 1.4.0 the only directory nginx
 * served was `public`, so the bundle was *copied* there under a 64-hex random name, downloaded,
 * and swept within the hour. It transited the web; it was never meant to live there.
 *
 * Core 1.4.0 finished the private-asset path that `Asset::path()` had always assumed — a
 * `protected` disk, a signed `assets.view` route, and a configurable expiry — so the bundle is now
 * copied to that disk instead and handed over as a **temporary signed URL**. Three things went
 * away with the webroot: the unguessable name (the signature is the credential now), the mirror
 * into `public/storage` that non-symlinked hosts needed, and the window in which anyone who
 * guessed the URL could have the whole site.
 *
 * **Why a copy at all.** A signed link addresses an `Asset`, and an Asset's path is relative to a
 * configured disk — while a run's bundle lives in the run's own directory under
 * `storage/app/site-migration`, which is not one. Copying into `protected` is the same single copy
 * the webroot version made, minus the exposure. The copy is removed by the operator's press, by
 * the sweep on the next screen load, and in any case within the hour.
 */
class Download
{
    /** Where transit copies live on the protected disk. */
    private const DIRECTORY = 'site-migration-downloads';

    /** The disk core 1.4.0 added for exactly this: uploads that must not be reachable by URL. */
    private const DISK = 'protected';

    /**
     * How long a copy is allowed to exist before it is swept.
     *
     * Longer than the signed link it is fetched with, deliberately: the link expires on its own
     * (`ovynt.assets.private_link_minutes`, ten by default), and this only has to outlive a slow
     * download of a large bundle on a poor connection.
     */
    private const MINUTES = 60;

    /**
     * Publish a run's bundle and return a signed URL to fetch it from.
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

        $key    = self::DIRECTORY . '/' . $run->id . '.zip';
        $source = fopen($bundle, 'rb');

        if ($source === false) {
            throw new RuntimeException('The bundle could not be read.');
        }

        try {
            // `writeStream`, not `put`: a media-included bundle is tens or hundreds of megabytes,
            // and reading it into a PHP string to hand to the disk is that much memory for a copy.
            Storage::disk(self::DISK)->writeStream($key, $source);
        } finally {
            fclose($source);
        }

        $asset = Asset::create([
            'title'   => $run->id . '.zip',
            'usage'   => 'MIGRATION_DOWNLOAD',
            'path'    => $key,
            'format'  => 'zip',
            'size'    => (int) Storage::disk(self::DISK)->size($key),
            'disk'    => self::DISK,
            'user_id' => Auth::id(),
        ]);

        $run->set('download_asset_id', (int) $asset->getKey())
            ->set('download_at', now()->toDateTimeString())
            ->save();

        // The accessor signs the link. Minted here, inside a request already behind the admin
        // guard — which is where the authorisation for it happens; see PrivateAssetController.
        return (string) $asset->path;
    }

    /**
     * Take a run's published copy back off the disk.
     *
     * Called by the operator's own press, before publishing a fresh copy, and by the sweep. Safe
     * to call when there is nothing to remove.
     */
    public function withdraw(Run $run): void
    {
        $assetId = (int) $run->get('download_asset_id', 0);

        if ($assetId > 0) {
            $this->forget(Asset::query()->find($assetId));
        }

        $run->set('download_asset_id', null)->set('download_at', null)->save();
    }

    /**
     * A fresh signed URL for a run's published copy, or null if it has none.
     *
     * **Freshly signed every time, and that matters:** a signed link expires in minutes while a screen can sit
     * open for hours, so re-minting on each page load is what makes the button work when the
     * operator comes back to it. The copy on disk is what persists; the URL never is.
     */
    public function url(Run $run): ?string
    {
        $assetId = (int) $run->get('download_asset_id', 0);

        if ($assetId <= 0) {
            return null;
        }

        $asset = Asset::query()->find($assetId);

        if ($asset === null || ! Storage::disk(self::DISK)->exists((string) $asset->getRawOriginal('path'))) {
            return null;
        }

        return (string) $asset->path;
    }

    /**
     * Remove every published copy older than the window.
     *
     * Swept on screen load rather than on a schedule, because a plugin ships no console command
     * and no scheduled work — `PluginServiceProvider` registers an autoloader and listeners,
     * nothing else. The screens where downloads are made are the ones the operator visits.
     */
    public function sweep(): void
    {
        try {
            $cutoff = now()->subMinutes(self::MINUTES)->getTimestamp();
            $disk   = Storage::disk(self::DISK);

            foreach ($disk->files(self::DIRECTORY) as $file) {
                if ($disk->lastModified($file) >= $cutoff) {
                    continue;
                }

                // The row first, so a file that cannot be deleted does not leave an Asset
                // pointing at nothing — which would throw for every screen that lists assets.
                $this->forget(
                    Asset::query()->where('disk', self::DISK)->where('path', $file)->first(),
                    $file
                );
            }
        } catch (Throwable) {
            // Housekeeping. A sweep that could not run is not a reason to refuse the screen, and
            // the next visit tries again.
        }
    }

    /** Delete one transit copy: its bytes and its asset row. */
    private function forget(?Asset $asset, ?string $key = null): void
    {
        try {
            $key ??= $asset === null ? null : (string) $asset->getRawOriginal('path');

            if ($key !== null && $key !== '') {
                Storage::disk(self::DISK)->delete($key);
            }

            $asset?->delete();
        } catch (Throwable) {
            // Best effort: a copy that could not be removed is swept again on the next visit.
        }
    }
}
