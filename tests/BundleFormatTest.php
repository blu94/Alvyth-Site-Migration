<?php

namespace Plugin\SiteMigration\Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Plugin\SiteMigration\Backend\Bundle\BundleReader;
use Plugin\SiteMigration\Backend\Bundle\Manifest;
use Plugin\SiteMigration\Backend\Runs\RunStore;
use RuntimeException;
use ZipArchive;

require_once __DIR__ . '/autoload.php';

/**
 * What a bundle must be for this install to touch it.
 *
 * Every refusal here happens **before a single record is written**, which is the whole value of
 * the manifest. The alternative failure — a column that does not exist, surfacing half-way
 * through — leaves the destination part-migrated with no clean way back.
 */
class BundleFormatTest extends TestCase
{
    use DatabaseTransactions;

    /** A bundle from a newer Alvyth is refused, with both versions named. */
    #[Test]
    public function a_bundle_from_a_newer_alvyth_is_refused(): void
    {
        $manifest = Manifest::fromArray([
            'format' => 1,
            'source' => ['alvyth' => '99.0.0', 'url' => 'https://newer.example'],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/99\.0\.0/');

        $manifest->assertReadable();
    }

    /**
     * A bundle from an older Alvyth is accepted.
     *
     * The asymmetry is not caution, it is what the two cases mean: fields absent from an older
     * bundle take their column defaults, which is well defined. Fields present in a newer one may
     * name columns that do not exist here.
     */
    #[Test]
    public function a_bundle_from_an_older_alvyth_is_accepted(): void
    {
        $manifest = Manifest::fromArray([
            'format' => 1,
            'source' => ['alvyth' => '1.0.0', 'url' => 'https://older.example'],
        ]);

        $manifest->assertReadable();

        $this->assertSame('1.0.0', $manifest->sourceVersion());
    }

    /** A bundle in a format this build does not understand is refused. */
    #[Test]
    public function an_unreadable_format_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/format/');

        Manifest::fromArray(['format' => Manifest::FORMAT + 1])->assertReadable();
    }

    /** A zip with no manifest is refused as not being a bundle at all. */
    #[Test]
    public function a_zip_that_is_not_a_bundle_is_refused(): void
    {
        $path = $this->tempZip(['readme.txt' => 'this is a theme, not a bundle']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/manifest/');

        BundleReader::peek($path);
    }

    /**
     * The manifest is readable without unpacking anything.
     *
     * One named entry, so answering "what is in this file" costs the same whether the bundle is
     * 40 KB or 400 MB — which is what makes it a separate press the operator can afford before
     * committing.
     */
    #[Test]
    public function the_manifest_reads_without_extracting(): void
    {
        $path = $this->tempZip([
            'manifest.json'       => json_encode(Manifest::build(['products' => 7])->toArray()),
            'data/products.ndjson' => "{}\n",
        ]);

        $manifest = BundleReader::peek($path);

        $this->assertSame(7, $manifest->contents()['products']);
        $this->assertSame(['products'], $manifest->resources());
    }

    /**
     * A damaged data file is caught by its checksum, and the message says nothing was written.
     */
    #[Test]
    public function a_corrupted_data_file_is_caught_by_its_checksum(): void
    {
        $good = "{\"sku\":\"A\"}\n";

        $path = $this->tempZip([
            'manifest.json'        => json_encode(Manifest::build(['products' => 1])->toArray()),
            'data/products.ndjson' => 'THIS IS NOT WHAT WAS HASHED',
            'checksums.json'       => json_encode(['data/products.ndjson' => hash('sha256', $good)]),
        ]);

        $reader = new BundleReader($path, $this->tempDir());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/nothing has been written/i');

        $reader->verify('products');
    }

    /**
     * A bundle with no checksums file still imports.
     *
     * It is a corruption check, not an authenticity one — it lives in the same zip, so anyone able
     * to alter the data could alter it too. Treating its absence as tampering would refuse a
     * bundle for a reason the file cannot actually support.
     */
    #[Test]
    public function a_bundle_without_checksums_is_still_readable(): void
    {
        $path = $this->tempZip([
            'manifest.json'        => json_encode(Manifest::build(['products' => 1])->toArray()),
            'data/products.ndjson' => "{\"sku\":\"A\"}\n",
        ]);

        $reader = new BundleReader($path, $this->tempDir());

        $reader->verify('products');

        $this->assertSame(1, $reader->count('products'));
    }

    /** Records stream from a line, which is what makes a resumed import possible. */
    #[Test]
    public function records_can_be_read_from_a_line(): void
    {
        $lines = implode("\n", [
            '{"sku":"A"}',
            '{"sku":"B"}',
            '{"sku":"C"}',
        ]) . "\n";

        $reader = new BundleReader(
            $this->tempZip([
                'manifest.json'        => json_encode(Manifest::build(['products' => 3])->toArray()),
                'data/products.ndjson' => $lines,
            ]),
            $this->tempDir()
        );

        $seen = [];

        foreach ($reader->records('products', from: 1) as $line => $record) {
            $seen[$line] = $record['sku'];
        }

        $this->assertSame([2 => 'B', 3 => 'C'], $seen, 'Resuming from line 1 should skip only the first record.');
    }

    /** A damaged line is reported, and the records after it still import. */
    #[Test]
    public function one_damaged_line_does_not_lose_the_rest(): void
    {
        $reader = new BundleReader(
            $this->tempZip([
                'manifest.json'        => json_encode(Manifest::build(['products' => 3])->toArray()),
                'data/products.ndjson' => "{\"sku\":\"A\"}\nNOT JSON\n{\"sku\":\"C\"}\n",
            ]),
            $this->tempDir()
        );

        $records = iterator_to_array($reader->records('products'));

        $this->assertSame('A', $records[1]['sku']);
        $this->assertTrue($records[2]['_unreadable']);
        $this->assertSame('C', $records[3]['sku']);
    }

    // ---------------------------------------------------------------- helpers

    /** @param array<string,string> $entries */
    private function tempZip(array $entries): string
    {
        $path = $this->tempDir() . '/bundle.zip';

        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        $zip->close();

        return $path;
    }

    private function tempDir(): string
    {
        $path = app(RunStore::class)->root() . '/_scratch/' . bin2hex(random_bytes(4));

        mkdir($path, 0755, true);

        return $path;
    }
}
