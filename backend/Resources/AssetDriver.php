<?php

namespace Plugin\SiteMigration\Backend\Resources;

use App\Models\Asset;
use App\Repositories\Asset\AssetInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Media: the rows, and — when the operator asked for it — the bytes.
 *
 * **Identity is the content hash, not the path.** Two installs holding the same photograph store it
 * under different paths, because the path carries the year and month it was uploaded; matching on
 * path would import every image again on every migration. Hashing the bytes is the only thing that
 * says "this is the same picture".
 *
 * **Hashing the whole library to find one match would be absurd**, so candidates are narrowed
 * first: same byte length and same format. That is an ordinary indexed-ish comparison over a small
 * set, and only the survivors — usually zero or one — are actually read and hashed.
 *
 * **Bytes are re-uploaded through core's own asset pipeline** rather than written to disk here, so
 * the destination produces its path, its disk placement and its webroot copy exactly the way a
 * genuine upload does. Forking that would mean this package owning a copy of rules core changes.
 *
 * Lands before the records that embed images, so the rewrite pass has somewhere to point.
 */
class AssetDriver extends BaseDriver
{
    public function key(): string
    {
        return 'assets';
    }

    public function label(): string
    {
        return 'Media';
    }

    public function naturalKey(): string
    {
        return 'content_hash';
    }

    /**
     * Public-disk assets only.
     *
     * Anything on another disk is either a protected upload belonging to a feature that manages its
     * own lifecycle, or — on this build, where no `protected` disk is even configured — a row whose
     * file cannot be read at all. Carrying those would export a pointer to nothing.
     */
    public function exportQuery(): Builder
    {
        return Asset::query()
            ->where(function ($q) {
                $q->where('disk', 'public')->orWhereNull('disk');
            })
            ->orderBy('id');
    }

    /**
     * @param  Asset  $record
     * @return array<string,mixed>
     */
    public function toRecord(Model $record): array
    {
        $relative = (string) $record->getRawOriginal('path');

        $data = [
            '_source'      => $record->getKey(),
            'content_hash' => $this->hashOf($record),
            'filename'     => basename($relative),
            'title'        => $this->translations($record, 'title'),
            'alt'          => $this->translations($record, 'alt'),
            'usage'        => $record->usage,
            'device'       => $record->device,
            'format'       => $record->format,
            'size'         => $record->size,
            'featured'     => (bool) $record->featured,

            // The path this file had on the source. Carried so the rewrite pass can recognise a
            // URL embedded in a page and swap it for the local one — it is evidence, not identity.
            'source_path'  => $relative,
        ];

        // Recorded per asset rather than inferred from the run's `include_media`, because the two
        // answer different questions: the flag says whether the operator asked for bytes, this
        // says whether *this* file actually had any to give. A source row whose file was deleted
        // long ago is common in a real library, and without this the destination cannot tell that
        // from a bundle that was built wrong.
        $data['has_bytes'] = $this->bundle?->includeMedia
            ? $this->copyIntoBundle($record, $relative)
            : false;

        return $data;
    }

    /** @param array<string,mixed> $record */
    public function locate(array $record): ?Model
    {
        $hash = (string) ($record['content_hash'] ?? '');

        if ($hash === '') {
            // **Thrown, not `null`.** Returning null would mean "this is new", and the dry run
            // would promise to create twenty-seven images it is then going to skip — a preview
            // that disagrees with the run it is previewing is worse than no preview. Without bytes
            // there is nothing to hash, and matching on filename instead would merge two unrelated
            // `banner.jpg` files into one.
            throw new SkipRecord(sprintf(
                'The file for "%s" was already missing on the site this bundle came from, so it '
                . 'cannot be identified or copied.',
                $record['filename'] ?? 'an image'
            ));
        }

        $candidates = Asset::query()
            ->where('format', $record['format'] ?? null)
            ->where('size', $record['size'] ?? -1)
            ->limit(50)
            ->get();

        foreach ($candidates as $candidate) {
            if (hash_equals($hash, (string) $this->hashOf($candidate))) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $record
     * @param  Asset|null  $existing
     */
    public function write(array $record, ?Model $existing): Model
    {
        if ($existing !== null) {
            // The bytes are already here — that is what matching on content hash established — so
            // only the describing columns are refreshed. Re-uploading an identical file would
            // create a second copy of every image on every migration, which is the exact failure
            // hashing exists to prevent.
            $existing->fill($this->withoutNullTranslations([
                'title' => $record['title'] ?? null,
                'alt'   => $record['alt'] ?? null,
                'usage' => $record['usage'] ?? $existing->usage,
            ]));

            $existing->save();

            return $existing;
        }

        return $this->upload($record);
    }

    /**
     * Bring a new file in through core's upload path.
     *
     * @param  array<string,mixed>  $record
     *
     * @throws RuntimeException when the bundle carries no bytes for it
     */
    private function upload(array $record): Asset
    {
        $source = (int) ($record['_source'] ?? 0);
        $file   = $this->bytesFor($source, (string) ($record['filename'] ?? ''));

        if ($file === null) {
            // Two quite different situations reach here, and telling them apart is the difference
            // between a report an operator trusts and one they learn to ignore.
            if (($record['has_bytes'] ?? true) === false) {
                // The source's own row pointed at a file that had already been deleted there.
                // Nothing is wrong on either side and nothing can be done about it.
                throw new SkipRecord(sprintf(
                    'The file for "%s" was already missing on the site this bundle came from, so '
                    . 'there is nothing to copy across.',
                    $record['filename'] ?? 'an image'
                ));
            }

            throw new RuntimeException(sprintf(
                'The image "%s" is not in this bundle, so it cannot be created here. Export again '
                . 'with media included, or upload it by hand.',
                $record['filename'] ?? 'unknown'
            ));
        }

        $upload = new UploadedFile(
            $file,
            (string) ($record['filename'] ?? basename($file)),
            null,
            null,
            // Marked as test-mode: these bytes did not arrive over HTTP, and without this
            // `UploadedFile` refuses to move a file it did not receive as an upload.
            true
        );

        $response = app(AssetInterface::class)->create(
            $upload,
            $record['usage'] ?? 'MIGRATION_IMPORT',
            $record['device'] ?? 'DESKTOP',
            null,
            false,
            false
        );

        // `AssetRepository::create()` answers with a JsonResponse, because its first caller was a
        // controller. The id is what matters here; re-implementing the method to avoid unwrapping
        // it would fork the pipeline this driver deliberately reuses.
        $payload = json_decode((string) $response->getContent(), true);
        $asset   = Asset::find($payload['id'] ?? 0);

        if ($asset === null) {
            throw new RuntimeException('The image could not be stored on this site.');
        }

        $asset->fill($this->withoutNullTranslations([
            'title' => $record['title'] ?? null,
            'alt'   => $record['alt'] ?? null,
        ]));

        $asset->save();

        return $asset;
    }

    /** The extracted bytes for one source asset, or null. */
    private function bytesFor(int $sourceId, string $filename): ?string
    {
        if ($this->bundle === null || $sourceId <= 0) {
            return null;
        }

        $directory = $this->bundle->mediaDirectory($sourceId);
        $direct    = $directory . '/' . basename($filename);

        if (is_file($direct)) {
            return $direct;
        }

        // The filename recorded on the source and the one in the archive can differ if the archive
        // normalised it, so fall back to whatever single file is in that directory.
        $found = glob($directory . '/*') ?: [];

        return is_file($found[0] ?? '') ? $found[0] : null;
    }

    /**
     * Copy one asset's bytes into the staging tree, stream to stream.
     *
     * @return bool whether there were any bytes to copy
     */
    private function copyIntoBundle(Asset $record, string $relative): bool
    {
        if ($this->bundle === null || $relative === '') {
            return false;
        }

        $disk = $record->disk ?: 'public';

        if (! Storage::disk($disk)->exists($relative)) {
            // A row whose file has gone is not worth failing an export over. It still travels as a
            // row, and the destination is told plainly that there were never any bytes.
            return false;
        }

        $directory = $this->bundle->mediaDirectory((int) $record->getKey());

        File::ensureDirectoryExists($directory);

        $in  = Storage::disk($disk)->readStream($relative);
        $out = fopen($directory . '/' . basename($relative), 'wb');

        if ($in === false || $out === false) {
            return false;
        }

        try {
            // Stream to stream. `file_get_contents` on a 40 MB video is 40 MB of PHP memory for no
            // reason, and a media-included export of a real site is exactly where that lands.
            stream_copy_to_stream($in, $out);
        } finally {
            fclose($in);
            fclose($out);
        }

        return true;
    }

    /**
     * The sha256 of an asset's bytes, computed once per run.
     *
     * `hash_file` streams, so a large file costs one read and no memory. The static cache matters
     * because `locate()` is called for every incoming asset and would otherwise re-read the same
     * local candidates repeatedly.
     *
     * @var array<int,string|null>
     */
    private static array $hashes = [];

    private function hashOf(Asset $asset): ?string
    {
        $id = (int) $asset->getKey();

        if (array_key_exists($id, self::$hashes)) {
            return self::$hashes[$id];
        }

        $relative = (string) $asset->getRawOriginal('path');
        $disk     = $asset->disk ?: 'public';

        try {
            if ($relative === '' || ! Storage::disk($disk)->exists($relative)) {
                return self::$hashes[$id] = null;
            }

            $absolute = Storage::disk($disk)->path($relative);

            return self::$hashes[$id] = is_file($absolute) ? hash_file('sha256', $absolute) : null;
        } catch (\Throwable) {
            return self::$hashes[$id] = null;
        }
    }

    /**
     * `size` and `format` describe the bytes, which the content hash already identifies, and
     * `featured` is placement on *this* site rather than content that travelled. `source_path`,
     * `filename` and `has_bytes` describe where the file came from, not what it is.
     *
     * **`usage` is here for a subtler reason, found by a symmetry check rather than by thinking.**
     * Two rows can share one file — the same photograph attached once as a `PRODUCT_IMAGE` and
     * once with no usage at all — and content-hash dedupe correctly resolves both to a single
     * local asset. That local row then differs from the incoming record in `usage` alone, so the
     * hash reported it as changed on every migration forever. And the rewrite would have achieved
     * nothing: {@see write()} deliberately keeps the existing usage rather than clearing it, so
     * the comparison was detecting a difference the write path will never act on.
     */
    public function volatileFields(): array
    {
        return array_merge(parent::volatileFields(), [
            'size', 'format', 'featured', 'source_path', 'filename', 'has_bytes', 'usage',
        ]);
    }
}
