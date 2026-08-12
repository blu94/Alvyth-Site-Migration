<?php

namespace Plugin\SiteMigration\Backend\Services;

use App\Models\Asset;
use Illuminate\Database\Eloquent\Model;
use Plugin\SiteMigration\Backend\Bundle\BundleReader;
use Plugin\SiteMigration\Backend\Bundle\Manifest;
use Plugin\SiteMigration\Backend\Resources\DriverRegistry;
use Plugin\SiteMigration\Backend\Runs\IdMap;
use Plugin\SiteMigration\Backend\Runs\Run;
use Throwable;

/**
 * Repairs the references a foreign key could not.
 *
 * **Why a second pass exists at all.** Relations that are proper foreign keys are resolved at
 * *write* time, because the import order guarantees the referenced row already exists. But a
 * page's builder nodes embed asset ids and page ids inside an opaque JSON blob, a richtext body
 * embeds absolute URLs, and a discount's rules name products by id. The schema does not know those
 * references are there, so nothing can resolve them on the way in — they can only be repaired once
 * every id is known, which is after the last resource has landed.
 *
 * **Media URLs are rewritten, not merely remapped.** A page-builder image node carries an absolute
 * URL to the *source* install. Left alone, every imported page hotlinks the site the operator is
 * leaving, and breaks the day that hosting is cancelled. The pass rewrites the host **and** the
 * asset id, because doing only one of the two produces a URL that is confidently wrong.
 *
 * **Idempotent, and keyed on the source host recorded in the manifest.** A resumed step re-runs
 * work it has already done, so a rewrite that could fire twice would turn a URL into nonsense on
 * the second pass. Every substitution here is from a source-shaped value to a local one, and a
 * local value matches nothing.
 */
class RewritePass
{
    /**
     * Keys whose integer value is an id pointing at another record.
     *
     * A fixed list rather than "any key ending in `_id`", because a blob also contains ids that
     * are *not* records this run placed — a Vuetify component id, an operator's own numbering —
     * and remapping one of those would corrupt a working block to fix nothing.
     *
     * @var array<string,string> key => resource it points at
     */
    private const ID_KEYS = [
        'asset_id'      => 'assets',
        'image_id'      => 'assets',
        'image'         => 'assets',
        'background_id' => 'assets',
        'thumbnail_id'  => 'assets',
        'page_id'       => 'pages',
        'product_id'    => 'products',
        'category_id'   => 'categories',
        'post_id'       => 'posts',
    ];

    public function __construct(private readonly DriverRegistry $drivers)
    {
    }

    /**
     * Rewrite everything this run placed.
     *
     * @return array<string,int> resource => records touched
     */
    public function run(Run $run, Manifest $manifest, ?BundleReader $reader = null): array
    {
        $idMap  = $run->idMap();
        $source = $manifest->sourceUrl();
        $local  = rtrim((string) config('app.url'), '/');

        // **Only when media travelled.** When it did not, the imported site deliberately points at
        // the source for its images and the screen said so — rewriting those URLs to a local asset
        // that was never created would replace a working (if borrowed) image with a broken one.
        // That is the labelled exception to the no-hotlink rule, and this is where it is honoured.
        $rewriteHost = $manifest->includesMedia() && $source !== '' && $source !== $local;

        $assetPaths = $rewriteHost ? $this->assetPaths($idMap, $reader) : [];
        $touched    = [];

        foreach ($idMap->resources() as $resource) {
            if (! $this->drivers->has($resource)) {
                continue;
            }

            $driver = $this->drivers->for($resource);
            $fields = $driver->rewritableFields();

            if ($fields === []) {
                continue;
            }

            $touched[$resource] = $this->rewriteResource(
                $driver->exportQuery()->getModel(),
                array_values($idMap->all($resource)),
                $fields,
                $idMap,
                $source,
                $local,
                $assetPaths,
                $rewriteHost
            );
        }

        return array_filter($touched);
    }

    /**
     * Walk one resource's placed records in chunks.
     *
     * `whereIn` over a chunk of target ids, never the whole set: the id map for a 20,000-product
     * catalogue is 20,000 ids, and a single `whereIn` of that size is a query no host will plan
     * well.
     *
     * @param  array<int,int>  $targetIds
     * @param  array<int,string>  $fields
     * @param  array<string,int>  $assetPaths
     */
    private function rewriteResource(
        Model $model,
        array $targetIds,
        array $fields,
        IdMap $idMap,
        string $source,
        string $local,
        array $assetPaths,
        bool $rewriteHost,
    ): int {
        $touched = 0;

        foreach (array_chunk($targetIds, 200) as $chunk) {
            $records = $model->newQuery()->whereIn($model->getKeyName(), $chunk)->get();

            foreach ($records as $record) {
                if ($this->rewriteRecord($record, $fields, $idMap, $source, $local, $assetPaths, $rewriteHost)) {
                    $touched++;
                }
            }
        }

        return $touched;
    }

    /**
     * @param  array<int,string>  $fields
     * @param  array<string,int>  $assetPaths
     */
    private function rewriteRecord(
        Model $record,
        array $fields,
        IdMap $idMap,
        string $source,
        string $local,
        array $assetPaths,
        bool $rewriteHost,
    ): bool {
        $changed = false;

        foreach ($fields as $field) {
            // `rows` and `seo` are relations rather than columns — a page's content lives in
            // `metas`, so rewriting the page row alone would leave every image node untouched.
            if (in_array($field, ['rows', 'seo'], true)) {
                $changed = $this->rewriteMetaTree($record, $idMap, $source, $local, $assetPaths, $rewriteHost) || $changed;

                continue;
            }

            if (! array_key_exists($field, $record->getAttributes())) {
                continue;
            }

            $before = $record->getAttribute($field);
            $after  = $this->rewriteValue($before, $idMap, $source, $local, $assetPaths, $rewriteHost);

            if ($after !== $before) {
                $record->setAttribute($field, $after);
                $changed = true;
            }
        }

        if ($changed) {
            try {
                // `saveQuietly`: a page save fires `HasRevisions`, and filing a revision for the
                // machine's own repair pass would bury the operator's snapshot of the version they
                // actually replaced under a second, near-identical one.
                $record->saveQuietly();
            } catch (Throwable) {
                $record->save();
            }
        }

        return $changed;
    }

    /**
     * Rewrite a page's builder tree, which lives in `metas` rather than on the page row.
     *
     * @param  array<string,int>  $assetPaths
     */
    private function rewriteMetaTree(
        Model $record,
        IdMap $idMap,
        string $source,
        string $local,
        array $assetPaths,
        bool $rewriteHost,
    ): bool {
        if (! method_exists($record, 'metas')) {
            return false;
        }

        $changed = false;

        foreach ($record->metas()->get() as $meta) {
            $touched = false;

            foreach (['data', 'title', 'subtitle', 'description'] as $field) {
                $before = $meta->getAttribute($field);
                $after  = $this->rewriteValue($before, $idMap, $source, $local, $assetPaths, $rewriteHost);

                if ($after !== $before) {
                    $meta->setAttribute($field, $after);
                    $touched = true;
                }
            }

            if ($touched) {
                $meta->save();
                $changed = true;
            }
        }

        return $changed;
    }

    /**
     * Rewrite one value, however deeply nested.
     *
     * @param  array<string,int>  $assetPaths
     */
    private function rewriteValue(
        mixed $value,
        IdMap $idMap,
        string $source,
        string $local,
        array $assetPaths,
        bool $rewriteHost,
        ?string $parentKey = null,
    ): mixed {
        if (is_array($value)) {
            $out = [];

            foreach ($value as $key => $item) {
                $out[$key] = $this->rewriteValue(
                    $item,
                    $idMap,
                    $source,
                    $local,
                    $assetPaths,
                    $rewriteHost,
                    is_string($key) ? $key : $parentKey
                );
            }

            return $out;
        }

        if (is_string($value)) {
            return $this->rewriteString($value, $source, $local, $assetPaths, $rewriteHost);
        }

        if (is_int($value) && $parentKey !== null && isset(self::ID_KEYS[$parentKey])) {
            $mapped = $idMap->resolve(self::ID_KEYS[$parentKey], [$value])[$value] ?? null;

            return $mapped ?? $value;
        }

        return $value;
    }

    /**
     * Rewrite a string: the asset path first, then whatever host is left.
     *
     * **Order matters.** Rewriting the host first would turn the source URL into a local one whose
     * *path* still names a file that does not exist here — a broken image on the operator's own
     * domain, which is harder to diagnose than an obvious hotlink because it looks like their
     * fault.
     *
     * **Idempotent**, because every substitution goes from a source-shaped value to a local one
     * and a local value matches nothing. A resumed step re-running this cannot double-rewrite.
     *
     * @param  array<string,string>  $assetPaths
     */
    private function rewriteString(
        string $value,
        string $source,
        string $local,
        array $assetPaths,
        bool $rewriteHost,
    ): string {
        if (! $rewriteHost || $source === '' || ! str_contains($value, $source)) {
            return $value;
        }

        // Point each source path at the file this run actually created for it, so the URL names
        // something that exists here rather than something that merely used to exist there.
        foreach ($assetPaths as $sourcePath => $localPath) {
            if (str_contains($value, $sourcePath)) {
                $value = str_replace($sourcePath, $localPath, $value);
            }
        }

        // Whatever still points at the source — a link to a page, an image this run did not
        // carry — at least stops naming a host the operator is walking away from.
        return str_replace($source, $local, $value);
    }

    /**
     * Source storage path => local storage path, for every asset this run placed.
     *
     * **Both halves are resolved once, up front.** The naive shape — look the asset up while
     * rewriting each string — is an N+1 against every image in every builder node on every page,
     * which is exactly the shape core already found and fixed once in the activity log.
     *
     * The source path is not in the id map, which holds only integers, so it is read back out of
     * the bundle's own `assets.ndjson` where the driver recorded it. Streamed line by line, so a
     * library of ten thousand images is ten thousand short reads and not ten thousand in memory.
     *
     * @return array<string,string>
     */
    private function assetPaths(IdMap $idMap, ?BundleReader $reader): array
    {
        if ($reader === null || ! $reader->has('assets')) {
            return [];
        }

        $placed = $idMap->all('assets');

        if ($placed === []) {
            return [];
        }

        $local = Asset::query()
            ->whereIn('id', array_values($placed))
            ->pluck('path', 'id')
            ->all();

        $paths = [];

        foreach ($reader->records('assets') as $record) {
            $sourceId   = (int) ($record['_source'] ?? 0);
            $sourcePath = trim((string) ($record['source_path'] ?? ''));

            if ($sourceId <= 0 || $sourcePath === '' || ! isset($placed[$sourceId])) {
                continue;
            }

            $localPath = (string) ($local[$placed[$sourceId]] ?? '');

            // A path that resolved to itself is not worth substituting, and skipping it keeps the
            // replace loop honest about being a no-op when source and destination agree.
            if ($localPath !== '' && $localPath !== $sourcePath) {
                $paths[$sourcePath] = $localPath;
            }
        }

        return $paths;
    }
}
