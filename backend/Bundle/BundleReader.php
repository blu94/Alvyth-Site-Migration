<?php

namespace Plugin\SiteMigration\Backend\Bundle;

use App\Services\Core\Archive\SafeZip;
use Generator;
use Illuminate\Support\Facades\File;
use RuntimeException;
use ZipArchive;

/**
 * Reads a bundle: its manifest without unpacking anything, then its records a line at a time.
 *
 * **Extraction goes through core's `SafeZip`, never `ZipArchive::extractTo()`.** It already
 * handles the two failures that matter here: entry-by-entry realpath containment against
 * zip-slip, and Windows `Compress-Archive` writing backslash separators, which `extractTo()`
 * turns into a single file literally named `data\products.ndjson`. That one installs *silently
 * wrong* — the import finds no products and reports an empty bundle.
 */
class BundleReader
{
    private ?Manifest $manifest = null;

    public function __construct(
        private readonly string $zipPath,
        private readonly string $extractedTo,
    ) {
    }

    /**
     * Read the manifest without unpacking the bundle.
     *
     * One named entry, so this costs a single small read regardless of whether the bundle is
     * 40 KB or 400 MB — which is what makes "tell me what is in this file" answerable *before*
     * the operator commits to anything. `getFromName` touches no filesystem path, so it has none
     * of the zip-slip surface that made `SafeZip` necessary in the first place.
     */
    public static function peek(string $zipPath): Manifest
    {
        if (! extension_loaded('zip')) {
            throw new RuntimeException('The PHP zip extension is required to read a bundle.');
        }

        $zip = new ZipArchive();

        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException(
                'That file could not be opened as a zip. Check the upload finished — a bundle '
                . 'cut short by an upload limit fails exactly here.'
            );
        }

        try {
            $raw = $zip->getFromName('manifest.json');
        } finally {
            $zip->close();
        }

        if ($raw === false) {
            throw new RuntimeException(
                'That zip has no manifest.json, so it is not a Site Migration bundle. A backup '
                . 'archive or a theme package will fail here.'
            );
        }

        $data = json_decode($raw, true);

        if (! is_array($data)) {
            throw new RuntimeException('The bundle manifest could not be read — the file may be damaged.');
        }

        return Manifest::fromArray($data);
    }

    /** Where the bundle was unpacked, for the drivers that read files beside the data. */
    public function extractedPath(): string
    {
        return $this->extractedTo;
    }

    /** Unpack, once. Cheap to call repeatedly: a step that resumes finds the files already there. */
    public function extract(): void
    {
        if (is_file($this->extractedTo . '/manifest.json')) {
            return;
        }

        File::ensureDirectoryExists($this->extractedTo);

        SafeZip::extractTo($this->zipPath, $this->extractedTo, 'migration bundle');
    }

    public function manifest(): Manifest
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }

        $this->extract();

        $data = json_decode((string) file_get_contents($this->extractedTo . '/manifest.json'), true);

        if (! is_array($data)) {
            throw new RuntimeException('The bundle manifest could not be read — the file may be damaged.');
        }

        return $this->manifest = Manifest::fromArray($data);
    }

    public function has(string $resource): bool
    {
        $this->extract();

        return is_file($this->dataPath($resource));
    }

    /** How many records a resource's file holds, counted without decoding any of them. */
    public function count(string $resource): int
    {
        $this->extract();

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
            while (($line = fgets($handle)) !== false) {
                if (trim($line) !== '') {
                    $lines++;
                }
            }
        } finally {
            fclose($handle);
        }

        return $lines;
    }

    /**
     * Records from a resource's file, starting at a line, one at a time.
     *
     * A generator rather than an array, and `fgets` rather than `file()`: memory holds one
     * record, never the catalogue. `$from` is what makes a run resumable — the cursor stores a
     * line number and the next step seeks to it.
     *
     * Skipping to `$from` still reads the lines before it, which sounds like the offset paging
     * this package refuses elsewhere. It is not the same thing: reading a line of a local file is
     * a memory copy, where an SQL `OFFSET` makes the *database* re-scan and discard rows it has
     * already sorted. There is no index to skip with in a flat file, and no query planner to
     * mislead.
     *
     * @return Generator<int,array<string,mixed>>
     */
    public function records(string $resource, int $from = 0): Generator
    {
        // Every reader that touches the extracted tree extracts first. `manifest()` happens to do
        // it on the ordinary path, so this was invisible until something called `records()` or
        // `count()` on its own — and then the answer was a silent "the bundle contains nothing"
        // rather than an error, which is the worst shape a bug can take here.
        $this->extract();

        $path = $this->dataPath($resource);

        if (! is_file($path)) {
            return;
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Could not read the {$resource} data from the bundle.");
        }

        try {
            $line = 0;

            while (($raw = fgets($handle)) !== false) {
                $line++;

                if ($line <= $from || trim($raw) === '') {
                    continue;
                }

                $record = json_decode($raw, true);

                if (! is_array($record)) {
                    // One damaged line is that record's problem. Throwing would lose the other
                    // 39,999, and the caller tallies this as a failure with its line number.
                    yield $line => ['_unreadable' => true];

                    continue;
                }

                yield $line => $record;
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Check a resource's data file against the checksum the source recorded.
     *
     * **Verified lazily — each file as its resource starts, not all of them up front.** Hashing
     * every file before the first record is imported doubles the read to protect against a rare
     * corruption; hashing one as it is consumed fails at the moment that resource starts, which
     * is both cheaper and a more useful place to fail.
     *
     * A bundle with no `checksums.json` passes. The file is a corruption check, not an
     * authenticity one — it is inside the same zip, so anyone able to alter the data could alter
     * it too — and treating its absence as tampering would refuse a bundle for the wrong reason.
     */
    public function verify(string $resource): void
    {
        $this->extract();

        $path = $this->dataPath($resource);

        if (! is_file($path)) {
            return;
        }

        $checksums = $this->checksums();
        $relative  = 'data/' . basename($path);
        $expected  = $checksums[$relative] ?? null;

        if ($expected === null) {
            return;
        }

        $actual = hash_file('sha256', $path);

        if (! hash_equals((string) $expected, (string) $actual)) {
            throw new RuntimeException(sprintf(
                'The %s data in this bundle does not match its checksum, so the file was damaged '
                . 'in transit. Export it again and re-upload — nothing has been written.',
                $resource
            ));
        }
    }

    /** @return array<string,string> */
    private function checksums(): array
    {
        $path = $this->extractedTo . '/checksums.json';

        if (! is_file($path)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? array_map('strval', $data) : [];
    }

    private function dataPath(string $resource): string
    {
        $safe = preg_replace('/[^a-z0-9_-]/', '', strtolower($resource)) ?: 'unknown';

        return $this->extractedTo . '/data/' . $safe . '.ndjson';
    }
}
