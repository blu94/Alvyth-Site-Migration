<?php

namespace Plugin\SiteMigration\Backend\Bundle;

use Illuminate\Support\Facades\File;
use RuntimeException;
use ZipArchive;

/**
 * Builds a bundle on disk, one record at a time.
 *
 * ```
 * manifest.json                what this bundle is
 * data/products.ndjson         one record per line
 * checksums.json               sha256 per data file
 * ```
 *
 * **NDJSON for records, not one JSON array.** A 20,000-product array must be fully parsed before
 * the first record is reachable, so memory would scale with the catalogue on a host that gives
 * PHP 128 MB. Line-delimited records stream, and — the half that matters more here — a resumable
 * import can seek to a line number, which is exactly what the run's cursor holds.
 *
 * **Nothing is assembled in memory.** Records are appended to a staging file as they are walked,
 * and the zip is sealed at the end with `addFile`, which reads from disk rather than taking a
 * string. A run that stops half-way leaves its staging directory intact, which is what lets the
 * next step continue appending rather than start the walk again.
 */
class BundleWriter
{
    public function __construct(private readonly string $directory)
    {
        File::ensureDirectoryExists($this->directory . '/data');
    }

    /**
     * Append one record to a resource's file.
     *
     * Opened and closed per call rather than held open across the walk. That looks wasteful and
     * is not: a step is time-boxed and ends by returning to the caller, so a handle held open
     * across steps would have to survive a request boundary, which it cannot. The cost is one
     * `fopen` per record against a page cache that already has the file.
     *
     * @param  array<string,mixed>  $record
     */
    public function append(string $resource, array $record): void
    {
        $handle = fopen($this->dataPath($resource), 'ab');

        if ($handle === false) {
            throw new RuntimeException("Could not write the {$resource} data file into the bundle.");
        }

        try {
            $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if ($line === false) {
                // One unencodable record must not take the run with it. Invalid UTF-8 in a
                // description is the usual cause and it is the record's problem, not the run's.
                throw new RuntimeException(sprintf(
                    'A %s record could not be written as JSON: %s.',
                    $resource,
                    json_last_error_msg()
                ));
            }

            fwrite($handle, $line . "\n");
        } finally {
            fclose($handle);
        }
    }

    /** How many records a resource's file already holds — where a resumed walk picks up. */
    public function written(string $resource): int
    {
        $path = $this->dataPath($resource);

        if (! is_file($path)) {
            return 0;
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return 0;
        }

        $lines = 0;

        try {
            while (fgets($handle) !== false) {
                $lines++;
            }
        } finally {
            fclose($handle);
        }

        return $lines;
    }

    /** Start a resource's file again, for a walk that is being restarted rather than resumed. */
    public function reset(string $resource): void
    {
        File::delete($this->dataPath($resource));
    }

    /**
     * Seal the staging directory into a zip.
     *
     * The manifest and checksums are written last, because both describe what the data files
     * ended up containing and neither is knowable until the walk has finished.
     *
     * @return string the path to the finished bundle
     */
    public function seal(Manifest $manifest): string
    {
        $this->write('manifest.json', json_encode($manifest->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->write('checksums.json', json_encode($this->checksums(), JSON_PRETTY_PRINT));

        $target = dirname($this->directory) . '/bundle.zip';

        // Rebuilt rather than appended to. A resumed run seals once at the end, but a run the
        // operator restarted would otherwise add a second copy of every entry to the previous
        // archive — `ZipArchive::OVERWRITE` is what makes sealing idempotent.
        $zip = new ZipArchive();

        if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the bundle file.');
        }

        try {
            foreach ($this->files() as $relative => $absolute) {
                // `addFile` reads from disk when the archive is written, so a 200 MB data file
                // never becomes 200 MB of PHP memory.
                if (! $zip->addFile($absolute, $relative)) {
                    throw new RuntimeException("Could not add {$relative} to the bundle.");
                }
            }
        } finally {
            $zip->close();
        }

        return $target;
    }

    /**
     * sha256 per data file.
     *
     * `hash_file` streams, so this costs one read of each file and no memory. The import
     * verifies them **lazily** — each file as its resource starts — rather than all of them up
     * front, which would double the read before the first record was written to protect against
     * a rare corruption.
     *
     * @return array<string,string>
     */
    private function checksums(): array
    {
        $checksums = [];

        foreach ($this->files() as $relative => $absolute) {
            if (str_starts_with($relative, 'data/')) {
                $checksums[$relative] = hash_file('sha256', $absolute);
            }
        }

        return $checksums;
    }

    /**
     * Every file staged so far, relative path => absolute path.
     *
     * @return array<string,string>
     */
    private function files(): array
    {
        $files = [];

        foreach (File::allFiles($this->directory) as $file) {
            $relative = str_replace('\\', '/', $file->getRelativePathname());

            $files[$relative] = $file->getPathname();
        }

        ksort($files);

        return $files;
    }

    private function write(string $relative, string $contents): void
    {
        file_put_contents($this->directory . '/' . $relative, $contents);
    }

    /**
     * A resource key is a driver key, never operator input — but it lands in a path, so it is
     * constrained rather than trusted. A driver added later with a careless key must not be able
     * to write outside the staging directory.
     */
    private function dataPath(string $resource): string
    {
        $safe = preg_replace('/[^a-z0-9_-]/', '', strtolower($resource)) ?: 'unknown';

        return $this->directory . '/data/' . $safe . '.ndjson';
    }
}
