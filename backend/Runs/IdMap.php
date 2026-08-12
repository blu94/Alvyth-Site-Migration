<?php

namespace Plugin\SiteMigration\Backend\Runs;

use Illuminate\Support\Facades\File;

/**
 * Where each source record's id landed on this install.
 *
 * A page's builder nodes embed asset ids and page ids. A product references categories. Those
 * ids belong to the database the bundle came *from*, and they are wrong the moment they land —
 * `products.id = 12` in a bundle is some unrelated product here.
 *
 * Two mechanisms repair that, and the split matters. Relations that are proper foreign keys are
 * resolved at *write* time, because the import order guarantees the referenced row already
 * exists. References buried inside JSON blobs and richtext bodies cannot be — the blob is opaque
 * to the schema — so they are rewritten in a second pass, after every id is known. This is what
 * the second pass reads.
 *
 * **One append-only file per resource.** Appending costs nothing and rewrites nothing, which is
 * the failure mode a single JSON blob would have: re-serialised on *every* step, a 20,000-product
 * map is ~300 KB rewritten forty times over a long run, and every step would decode the whole
 * thing into memory to read three keys. Splitting per resource is what bounds a read — the
 * rewrite pass wants "every product this run placed", never the whole map at once.
 */
class IdMap
{
    public function __construct(private readonly string $directory)
    {
    }

    /**
     * Record where a record landed.
     *
     * Appended without checking for a previous entry for the same source id. A step re-run after
     * an interruption will write its pairs a second time, and that is deliberate: a read folds
     * duplicates by taking the last line for a source id, so the newer answer wins and the write
     * path stays a single `fwrite` with no seek. Checking first would turn every upsert into a
     * file scan to prevent something that costs a few bytes.
     */
    public function remember(string $resource, int $sourceId, int $targetId): void
    {
        File::ensureDirectoryExists($this->directory);

        $handle = fopen($this->path($resource), 'ab');

        if ($handle === false) {
            return;
        }

        try {
            fwrite($handle, json_encode(['s' => $sourceId, 't' => $targetId]) . "\n");
        } finally {
            fclose($handle);
        }
    }

    /**
     * Every mapping recorded for one resource, source id => target id.
     *
     * Read line by line rather than with `json_decode(file_get_contents(...))`, so memory scales
     * with one line and not with the catalogue. The resulting array is bounded by the number of
     * records of a *single* resource, which is the smallest unit the rewrite pass can work in.
     *
     * @return array<int,int>
     */
    public function all(string $resource): array
    {
        $path = $this->path($resource);

        if (! is_file($path)) {
            return [];
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return [];
        }

        $map = [];

        try {
            while (($line = fgets($handle)) !== false) {
                $pair = json_decode($line, true);

                if (is_array($pair) && isset($pair['s'], $pair['t'])) {
                    // Last write wins, which is how a re-run step corrects itself.
                    $map[(int) $pair['s']] = (int) $pair['t'];
                }
            }
        } finally {
            fclose($handle);
        }

        return $map;
    }

    /**
     * Resolve a specific set of source ids.
     *
     * @param  array<int,int|string>  $sourceIds
     * @return array<int,int>
     */
    public function resolve(string $resource, array $sourceIds): array
    {
        $wanted = array_flip(array_map('intval', $sourceIds));

        return array_intersect_key($this->all($resource), $wanted);
    }

    /** Whether anything has been recorded for a resource — cheaper than reading it. */
    public function has(string $resource): bool
    {
        return is_file($this->path($resource));
    }

    /** @return array<int,string> the resources this run has placed records for */
    public function resources(): array
    {
        if (! is_dir($this->directory)) {
            return [];
        }

        return array_values(array_map(
            static fn (string $file) => basename($file, '.ndjson'),
            glob($this->directory . '/*.ndjson') ?: []
        ));
    }

    /**
     * A resource key is a driver key — `products`, `pages` — never operator input, but the value
     * ends up in a path, so it is constrained here rather than trusted. A driver added later with
     * a careless key must not be able to write outside this run's directory.
     */
    private function path(string $resource): string
    {
        $safe = preg_replace('/[^a-z0-9_-]/', '', strtolower($resource)) ?: 'unknown';

        return $this->directory . '/' . $safe . '.ndjson';
    }
}
