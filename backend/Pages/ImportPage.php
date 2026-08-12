<?php

namespace Plugin\SiteMigration\Backend\Pages;

use App\Models\Asset;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Plugin\SiteMigration\Backend\Bundle\BundleReader;
use Plugin\SiteMigration\Backend\Resources\DriverRegistry;
use Plugin\SiteMigration\Backend\Runs\Run;
use Plugin\SiteMigration\Backend\Runs\RunStore;
use Plugin\SiteMigration\Backend\Services\Importer;
use Plugin\SiteMigration\Backend\Support\Permissions;
use RuntimeException;
use Throwable;

/**
 * The import wizard: upload a bundle, read what is in it, see what would change, then write.
 *
 * **Getting a file in, without a file field.** There is no file-input type in the closed set, but
 * the `image`/`media` field renders `DropzoneUploader`, and `BuilderField` binds its
 * `accepted-files` from `ui.props.acceptedFiles` — the default is `image/*`, so it is set to
 * `.zip` explicitly. The upload goes to `POST /admin/assets`, whose `StoreAssetRequest` validates
 * `'file' => 'required|file'` with **no mime restriction**, so a zip is accepted today. The field
 * yields an `asset_id`, which is what this page takes.
 *
 * **The upload lands on the public disk, and this page's first act is to get it off there.**
 * `protectedDisk: true` is not an option on this build — there is no `protected` disk configured,
 * so the upload would 500 — which leaves the public disk as the only way in. A bundle sitting in
 * the webroot is readable by anyone who knows its URL, so {@see claim()} moves the bytes under
 * `storage/app` and deletes both the public file and its asset row. The exposure window is the
 * seconds between the drop and the button; it is not zero and the screen says so.
 */
class ImportPage
{
    public function __construct(
        private readonly RunStore $runs,
        private readonly DriverRegistry $drivers,
        private readonly Importer $importer,
    ) {
    }

    /**
     * How long an unclaimed upload is left alone before it is swept.
     *
     * Long enough that a slow operator reading the screen is never robbed of the file they just
     * dropped; short enough that one abandoned upload is not still downloadable next week.
     */
    private const ORPHAN_MINUTES = 30;

    /** @return array<string,mixed> */
    public function data(): array
    {
        $this->sweepOrphanedUploads();

        $run = $this->runs->latest(Run::DIRECTION_IMPORT);

        return [
            'bundle'         => [],
            'module_options' => $this->drivers->options($this->drivers->contentKeys()),
            'record_options' => $this->drivers->options($this->drivers->recordKeys()),
            'modules'      => [],
            'records'      => [],
            'on_conflict'  => 'skip',
            'run_id'       => $run?->id,
            'inspection'   => $this->inspection($run),
            'progress'     => $this->progress($run),
            'preview_text' => '',
            'passphrase'   => '',
            'can_continue' => $run !== null && $run->status() === Run::STATUS_PAUSED,
            'errors_text'  => $this->errors($run),
        ];
    }

    /**
     * **Read bundle** — take ownership of the upload and read its manifest, nothing else.
     *
     * Always starts a fresh run: pressing this is how an operator says "I have a different file
     * now", and continuing the previous run against a new bundle would resume a cursor that
     * refers to lines in a file that is gone.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    public function inspect(array $data): array
    {
        Permissions::assertMayRun();

        $run = $this->begin($data);

        $manifest = BundleReader::peek($this->bundleOf($run));

        $manifest->assertReadable();

        $run->set('manifest', $manifest->toArray())->save();

        return $this->respond($run, [
            'inspection' => $this->describe($manifest->toArray()),
        ]);
    }

    /**
     * **Preview** — resolve every record against this install and report, writing nothing.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    public function preview(array $data): array
    {
        Permissions::assertMayRun();

        $run = $this->current($data);

        $result = $this->importer->dryRun($this->applySelection($run, $data));

        return $this->respond($run, [
            'preview_text' => $result['message'] . $this->localeWarning($result['locales'] ?? []),
        ]);
    }

    /**
     * **Import / Continue** — write, for as long as the budget allows.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    public function apply(array $data): array
    {
        Permissions::assertMayRun();

        $run = $this->current($data);

        // In memory for this press only, exactly as on the export side.
        $run->withPassphrase((string) ($data['passphrase'] ?? ''));

        $this->importer->step($this->applySelection($run, $data));

        return $this->respond($run, []);
    }

    /**
     * The run this press belongs to.
     *
     * A finished run is not resumed — pressing Import after one completed is a fresh import of
     * the same bundle, which is safe precisely because every write is an upsert on a natural key.
     *
     * @param  array<string,mixed>  $data
     */
    private function current(array $data): Run
    {
        $run = $this->runs->find($data['run_id'] ?? null);

        if ($run === null) {
            throw new RuntimeException('Upload a bundle and press Read bundle first.');
        }

        return $run;
    }

    /**
     * Fold the form's current choices into the run.
     *
     * Re-read on every press rather than trusted from when the run was created: the operator sees
     * what is in the bundle only *after* Read bundle, so the conflict policy they finally want is
     * usually chosen after the run row already exists.
     *
     * @param  array<string,mixed>  $data
     */
    private function applySelection(Run $run, array $data): Run
    {
        $selection = $run->selection();

        $selection['on_conflict'] = ($data['on_conflict'] ?? 'skip') === 'overwrite' ? 'overwrite' : 'skip';

        $modules = array_values(array_filter(
            (array) ($data['modules'] ?? []),
            fn ($key) => is_string($key) && $this->drivers->has($key)
        ));

        $selection['modules'] = $modules;
        $selection['records'] = array_values(array_filter(
            (array) ($data['records'] ?? []),
            fn ($key) => is_string($key) && in_array($key, $this->drivers->recordKeys(), true)
        ));

        $run->set('selection', $selection)->save();

        return $run;
    }

    /**
     * Everything the screen rebinds after a press, merged over whatever the action returned.
     *
     * The run is re-read from disk first: each service writes state through its own handle, so
     * returning progress computed before the call is how a finished run renders as still running.
     *
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function respond(Run $run, array $extra): array
    {
        $fresh = $this->runs->find($run->id) ?? $run;

        return $extra + [
            'run_id'      => $fresh->id,
            'progress'    => $this->progress($fresh),
            'inspection'  => $this->inspection($fresh),
            'errors_text' => $this->errors($fresh),
        ];
    }

    /** @param array<int,string> $locales */
    private function localeWarning(array $locales): string
    {
        if ($locales === []) {
            return '';
        }

        // A warning, never a refusal: importing a locale this site has no home for stores a
        // translation nothing renders, which is recoverable and mostly harmless. It is still
        // worth saying — an operator who reads this fixes it in a minute and would otherwise not
        // notice for months.
        return "\n\nThis bundle carries content in " . implode(', ', $locales)
            . ', which this site has no locale for. Those translations will be stored but nothing '
            . 'will render them until you add the locale under Settings.';
    }

    private function bundleOf(Run $run): string
    {
        $bundle = $run->bundlePath();

        if ($bundle === null) {
            throw new RuntimeException('This run has no bundle — upload one and start again.');
        }

        return $bundle;
    }

    /**
     * Create the run and take ownership of the uploaded file.
     *
     * @param  array<string,mixed>  $data
     */
    private function begin(array $data): Run
    {
        $asset = $this->locateUpload($data);

        $run = $this->runs->create(Run::DIRECTION_IMPORT, [
            'modules'     => array_values(array_filter(
                (array) ($data['modules'] ?? []),
                fn ($key) => is_string($key) && $this->drivers->has($key)
            )),
            'on_conflict' => ($data['on_conflict'] ?? 'skip') === 'overwrite' ? 'overwrite' : 'skip',
        ]);

        $this->claim($run, $asset);

        return $run;
    }

    /**
     * Move the uploaded bundle out of the webroot and into the run's own directory.
     *
     * The public copy and the asset row are both removed. Leaving either would keep the bundle
     * downloadable by URL long after the import finished, which for a file that can carry the
     * whole site is the kind of thing nobody discovers until it matters.
     */
    private function claim(Run $run, Asset $asset): void
    {
        $assetId = (int) $asset->getKey();

        // `getRawOriginal`, because the `path` accessor returns a *URL* — and on a non-public
        // disk it throws outright, since `assets.view` is not a route on this build.
        $relative = (string) $asset->getRawOriginal('path');
        $disk     = $asset->disk ?: 'public';

        if ($relative === '' || ! Storage::disk($disk)->exists($relative)) {
            throw new RuntimeException('That upload could not be read. Try dropping the file again.');
        }

        File::ensureDirectoryExists($run->directory);

        $target = $run->directory . '/bundle.zip';
        $source = Storage::disk($disk)->readStream($relative);
        $out    = fopen($target, 'wb');

        if ($source === false || $out === false) {
            throw new RuntimeException('The uploaded bundle could not be moved into place.');
        }

        try {
            // Stream to stream. A bundle is exactly the file where `file_get_contents` would be
            // hundreds of megabytes of PHP memory for no reason.
            stream_copy_to_stream($source, $out);
        } finally {
            fclose($source);
            fclose($out);
        }

        try {
            Storage::disk($disk)->delete($relative);

            // The webroot copy is separate from the disk's own file — `AssetRepository::create()`
            // writes both when the public disk is not symlinked — so deleting one is not enough.
            $public = public_path('storage/' . ltrim($relative, '/'));

            if (is_file($public)) {
                File::delete($public);
            }

            $asset->delete();
        } catch (Throwable $e) {
            // The bundle is already safely in place, so the import can proceed. A copy left
            // behind in the webroot is worth saying out loud rather than swallowing.
            $run->addErrors(
                'The uploaded copy could not be removed from the public folder, so it may still be '
                . 'downloadable. Delete asset #' . $assetId . ' from the Assets screen. (' . $e->getMessage() . ')'
            )->save();
        }
    }

    /**
     * The uploaded bundle, out of whatever the upload field bound.
     *
     * **The field binds a URL, not an id**, which is the single most surprising thing on this
     * screen and was found by watching the request rather than by reading the schema: the value
     * posted is `http://site/storage/assets/original/2026/08/bundle.zip`. Three shapes are
     * accepted because the field produces different ones at different moments — a fresh drop, a
     * page rebound from saved state, and a form where the file was replaced — and a screen that
     * understood only one of them would work when tested and fail for the operator.
     *
     * Matched on the stored path rather than the URL, because the URL carries this install's
     * `APP_URL` and that changes between the moment of upload and any later read.
     *
     * @param  array<string,mixed>  $data
     *
     * @throws RuntimeException
     */
    private function locateUpload(array $data): Asset
    {
        $bundle = $data['bundle'] ?? null;

        if (is_array($bundle)) {
            $bundle = $bundle[0] ?? null;
        }

        if (is_array($bundle)) {
            $bundle = $bundle['id'] ?? $bundle['path'] ?? null;
        }

        if (is_numeric($bundle) && (int) $bundle > 0) {
            $asset = Asset::find((int) $bundle);

            if ($asset !== null) {
                return $asset;
            }
        }

        if (is_string($bundle) && trim($bundle) !== '') {
            // `latest('id')`: uploading the same filename twice leaves two rows whose paths
            // differ only by the `-1` suffix the asset pipeline adds, and on the exact-match case
            // the newest row is the one the operator just dropped.
            $asset = Asset::where('path', $this->storageRelative($bundle))->latest('id')->first();

            if ($asset !== null) {
                return $asset;
            }
        }

        throw new RuntimeException(
            'Upload a bundle first — drop the zip the other site produced onto the box above, '
            . 'wait for it to finish uploading, then press Read bundle.'
        );
    }

    /**
     * Delete bundles that were uploaded and never claimed.
     *
     * **This closes the one hole the design admits.** An upload reaches the public disk in a
     * request this package does not control, and {@see claim()} only moves it once the operator
     * presses Read bundle. If they never do — they change their mind, the manifest is refused,
     * the tab is closed — the bundle stays in the webroot, downloadable by anyone with the URL,
     * indefinitely. That is a site's whole content sitting on a guessable path.
     *
     * Swept on every load of this screen rather than on a schedule, because a plugin ships no
     * console command and no scheduled work: `PluginServiceProvider` registers an autoloader and
     * listeners, nothing else. The screen where bundles are uploaded is the one place guaranteed
     * to be visited by the person who uploads them.
     *
     * Failures are swallowed deliberately. This is housekeeping on somebody else's rows, and an
     * import must not fail because a file it does not need could not be tidied away.
     */
    private function sweepOrphanedUploads(): void
    {
        try {
            $orphans = Asset::query()
                ->where('usage', 'MIGRATION_BUNDLE')
                ->where('created_at', '<', now()->subMinutes(self::ORPHAN_MINUTES))
                ->limit(25)
                ->get();

            foreach ($orphans as $orphan) {
                $relative = (string) $orphan->getRawOriginal('path');
                $disk     = $orphan->disk ?: 'public';

                if ($relative !== '') {
                    Storage::disk($disk)->delete($relative);

                    $public = public_path('storage/' . ltrim($relative, '/'));

                    if (is_file($public)) {
                        File::delete($public);
                    }
                }

                $orphan->delete();
            }
        } catch (Throwable) {
            // Nothing to report to the operator: the bundle they are about to import is
            // unaffected, and a tidy-up that failed is not a reason to refuse the screen.
        }
    }

    /**
     * The disk-relative path inside an asset URL.
     *
     * `…/storage/assets/original/2026/08/bundle.zip` → `assets/original/2026/08/bundle.zip`,
     * which is what the `path` column holds. A query string is dropped: a cache-buster on the URL
     * is not part of the path and would match nothing.
     */
    private function storageRelative(string $value): string
    {
        $value = strtok(trim($value), '?') ?: '';

        $marker = '/storage/';
        $at     = strpos($value, $marker);

        if ($at !== false) {
            $value = substr($value, $at + strlen($marker));
        }

        return ltrim($value, '/');
    }

    /** @param array<string,mixed>|null $manifest */
    private function describe(?array $manifest): string
    {
        if ($manifest === null) {
            return 'Nothing uploaded yet.';
        }

        $contents = array_filter(array_map('intval', (array) ($manifest['contents'] ?? [])));

        $lines = [
            'From: ' . ($manifest['source']['url'] ?? 'an unnamed site')
                . ' (Ovynt ' . ($manifest['source']['ovynt'] ?? '?') . ')',
            'Written: ' . ($manifest['created_at'] ?? '?'),
            'Contains: ' . ($contents === []
                ? 'nothing'
                : implode(', ', array_map(
                    static fn ($count, $key) => number_format($count) . ' ' . $key,
                    $contents,
                    array_keys($contents)
                ))),
            'Locales: ' . implode(', ', (array) ($manifest['source']['locales'] ?? [])),
        ];

        if (($manifest['has_credentials'] ?? false) === true) {
            $lines[] = 'This bundle carries encrypted credentials. You will need the passphrase.';
        }

        return implode("\n", $lines);
    }

    private function inspection(?Run $run): string
    {
        $manifest = $run?->get('manifest');

        return is_array($manifest) ? $this->describe($manifest) : 'Nothing uploaded yet.';
    }

    private function progress(?Run $run): string
    {
        if ($run === null) {
            return 'No import has been run on this site yet.';
        }

        $tally = $run->tally();

        return match ($run->status()) {
            Run::STATUS_COMPLETED => sprintf(
                'Finished %s — %s created, %s updated, %s skipped, %s failed.',
                $run->get('finished_at', ''),
                number_format($tally['created']),
                number_format($tally['updated']),
                number_format($tally['skipped']),
                number_format($tally['failed'])
            ),
            Run::STATUS_PAUSED => sprintf(
                'Paused after %s records. Press Continue — an interrupted import leaves a partly '
                . 'populated site, and continuing is the fix, not starting over.',
                number_format(array_sum($tally))
            ),
            Run::STATUS_FAILED  => 'The last import failed. The messages below say why.',
            Run::STATUS_RUNNING => 'Running.',
            default             => 'Waiting to start.',
        };
    }

    private function errors(?Run $run): string
    {
        $errors = (array) $run?->get('errors', []);

        return $errors === [] ? '' : implode("\n", $errors);
    }
}
