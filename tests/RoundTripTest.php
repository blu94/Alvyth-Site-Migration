<?php

namespace Plugin\SiteMigration\Tests;

use App\Models\Product;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Plugin\SiteMigration\Backend\Resources\Collision;
use Plugin\SiteMigration\Backend\Runs\Run;
use Plugin\SiteMigration\Backend\Runs\RunStore;
use Plugin\SiteMigration\Backend\Services\Exporter;
use Plugin\SiteMigration\Backend\Services\Importer;
use ZipArchive;

require_once __DIR__ . '/autoload.php';

/**
 * The acceptance gates that describe what this package promises: a round trip that reproduces the
 * catalogue, an idempotent second run that writes nothing, and an interrupted run that finishes
 * to the same place as an uninterrupted one.
 *
 * `DatabaseTransactions`, **never** `RefreshDatabase` — the latter runs `migrate:fresh` and has
 * destroyed a real database in this project before.
 */
class RoundTripTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsSuperAdmin();
    }

    /**
     * Gate 1 — export a catalogue, delete half of it, import the bundle, and the catalogue is back.
     */
    #[Test]
    public function a_bundle_restores_what_was_deleted(): void
    {
        $products = $this->seedProducts(6);
        $bundle   = $this->export();

        // Remove three of them, and change a fourth so the import has an update to make as well
        // as three creates.
        foreach ($products->take(3) as $product) {
            $product->forceDelete();
        }

        $edited        = $products->get(3);
        $exportedTitle = $edited->getTranslation('title', 'en');

        $edited->update(['title' => ['en' => 'Changed locally']]);

        $this->assertSame(3, Product::whereIn('sku', $products->pluck('sku'))->count());

        $run = $this->importRun($bundle, overwrite: true);

        $report = app(Importer::class)->dryRun($run)['report']['products'];

        $this->assertSame(3, $report['new'], 'The three deleted products should read as new.');
        $this->assertSame(1, $report['update'], 'The locally edited product should read as an update.');
        $this->assertSame(2, $report['unchanged'], 'The untouched products should read as unchanged.');

        $this->pressUntilDone(fn () => app(Importer::class)->step($this->reload($run)));

        $this->assertSame(6, Product::whereIn('sku', $products->pluck('sku'))->count());
        $this->assertSame(
            $exportedTitle,
            Product::where('sku', $edited->sku)->first()->getTranslation('title', 'en'),
            'The overwritten product did not get its exported title back.'
        );
    }

    /**
     * Gate 2 — importing the same bundle twice creates nothing the second time, **and writes
     * nothing at all**.
     *
     * The second half is the one worth asserting properly: "it looked instant" is not the same
     * claim as "it did no work". Counting the products is what proves identity by natural key;
     * asserting that `updated` is zero while `skipped` is everything is what proves the content
     * hash actually fired.
     */
    #[Test]
    public function a_second_import_writes_nothing(): void
    {
        $products = $this->seedProducts(5);
        $bundle   = $this->export();

        $first = $this->importRun($bundle, overwrite: true);
        $this->pressUntilDone(fn () => app(Importer::class)->step($this->reload($first)));

        $countAfterFirst = Product::whereIn('sku', $products->pluck('sku'))->count();

        $second = $this->importRun($bundle, overwrite: true);
        $this->pressUntilDone(fn () => app(Importer::class)->step($this->reload($second)));

        $tally = $this->reload($second)->tally();

        $this->assertSame($countAfterFirst, Product::whereIn('sku', $products->pluck('sku'))->count());
        $this->assertSame(0, $tally['created'], 'The second run created records, so identity is not holding.');
        $this->assertSame(0, $tally['updated'], 'The second run rewrote records the content hash should have skipped.');
        $this->assertSame(5, $tally['skipped']);
    }

    /**
     * Gate 3 — a run interrupted repeatedly reaches the same place as one that was not.
     *
     * Forced with a budget too small to finish, so every press does one record and stops. That is
     * the failure the Continue button exists for, reproduced deterministically rather than by
     * killing a process and hoping.
     */
    #[Test]
    public function an_interrupted_run_finishes_where_an_uninterrupted_one_does(): void
    {
        $products = $this->seedProducts(5);

        $run = app(RunStore::class)->create(Run::DIRECTION_EXPORT, ['modules' => ['products']]);

        $result = $this->pressUntilDone(
            fn () => app(Exporter::class)->step($this->reload($run), 0.001)
        );

        $this->assertGreaterThan(
            1,
            $result['presses'],
            'The budget did not force a pause, so this proved nothing about resuming.'
        );

        $records = $this->records($this->reload($run)->bundlePath());

        $this->assertCount(5, $records, 'The resumed export wrote a different number of records.');
        $this->assertSame(
            $products->pluck('sku')->sort()->values()->all(),
            collect($records)->pluck('sku')->sort()->values()->all(),
            'The resumed export duplicated or dropped a record.'
        );
    }

    /** A resumed *import* is idempotent too — no record is written twice across presses. */
    #[Test]
    public function an_interrupted_import_does_not_double_write(): void
    {
        $products = $this->seedProducts(5);
        $bundle   = $this->export();

        Product::whereIn('sku', $products->pluck('sku'))->forceDelete();

        $run = $this->importRun($bundle, overwrite: true);

        $this->pressUntilDone(fn () => app(Importer::class)->step($this->reload($run), 0.001));

        $this->assertSame(
            5,
            Product::whereIn('sku', $products->pluck('sku'))->count(),
            'A resumed import created a different number of products than the bundle held.'
        );
    }

    /** The bundle is a real zip with the members the format promises. */
    #[Test]
    public function the_bundle_carries_a_manifest_and_checksums(): void
    {
        $this->seedProducts(2);

        $zip = new ZipArchive();
        $zip->open($this->export());

        $names = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }

        $manifest = json_decode($zip->getFromName('manifest.json'), true);

        $zip->close();

        $this->assertContains('manifest.json', $names);
        $this->assertContains('data/products.ndjson', $names);
        $this->assertContains('checksums.json', $names);

        $this->assertSame(1, $manifest['format']);
        $this->assertSame((string) config('alvyth.version'), $manifest['source']['alvyth']);
        $this->assertSame(2, $manifest['contents']['products']);
    }

    /** Every exported record carries the hash field, reserved from the first release of the format. */
    #[Test]
    public function every_record_carries_a_content_hash(): void
    {
        $this->seedProducts(3);

        foreach ($this->records($this->export()) as $record) {
            $this->assertArrayHasKey('_hash', $record);
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $record['_hash']);
        }
    }

    /**
     * A product with no SKU cannot be placed, and is counted rather than guessed at.
     *
     * `products.sku` is nullable, so this is not a hypothetical. Inventing a key is how the same
     * product lands twice; refusing it names a problem the operator can fix in a minute.
     */
    #[Test]
    public function a_product_with_no_sku_is_reported_rather_than_guessed(): void
    {
        $this->seedProducts(2);

        Product::factory()->create(['sku' => null, 'title' => ['en' => 'No SKU'], 'productable_id' => null]);

        $run = $this->importRun($this->export(), overwrite: true);

        $report = app(Importer::class)->dryRun($run)['report']['products'];

        $this->assertSame(1, $report['unplaceable'], 'The SKU-less product should have been reported as unplaceable.');
    }

    // ---------------------------------------------------------------- helpers

    private function seedProducts(int $count)
    {
        return collect(range(1, $count))->map(fn (int $n) => Product::factory()->create([
            'sku'            => 'RT-' . $n . '-' . bin2hex(random_bytes(3)),
            'title'          => ['en' => 'Original ' . $n],
            'productable_id' => null,
            'status'         => 'active',
        ]));
    }

    /** Export everything and return the path to the sealed bundle. */
    private function export(): string
    {
        $run = app(RunStore::class)->create(Run::DIRECTION_EXPORT, ['modules' => ['products']]);

        $this->pressUntilDone(fn () => app(Exporter::class)->step($this->reload($run)));

        $bundle = $this->reload($run)->bundlePath();

        $this->assertNotNull($bundle, 'The export did not seal a bundle.');

        return $bundle;
    }

    private function importRun(string $bundle, bool $overwrite): Run
    {
        $run = app(RunStore::class)->create(Run::DIRECTION_IMPORT, [
            'modules'     => ['products'],
            'on_conflict' => $overwrite ? Collision::OVERWRITE : Collision::KEEP_BOTH,

            // **Both halves, or it is not an overwrite.** The acknowledgement is what the
            // confirmation dialog mirrors onto the form, and `Run::overwrites()` requires it — so a
            // test that set only the mode would quietly exercise the keep-both path and pass for
            // the wrong reason. It did exactly that once, which is why this is spelled out.
            'overwrite_acknowledged' => $overwrite,
        ]);

        copy($bundle, $run->directory . '/bundle.zip');

        return $run;
    }

    /**
     * Re-read a run from disk.
     *
     * Every step writes state through its own handle, so a `Run` held across presses goes stale —
     * and a stale cursor is how a test would silently re-walk from the top and still pass.
     */
    private function reload(Run $run): Run
    {
        return app(RunStore::class)->find($run->id);
    }

    /** @return array<int,array<string,mixed>> */
    private function records(string $bundle): array
    {
        $zip = new ZipArchive();
        $zip->open($bundle);
        $raw = $zip->getFromName('data/products.ndjson');
        $zip->close();

        return array_map(
            static fn (string $line) => json_decode($line, true),
            array_filter(explode("\n", trim((string) $raw)))
        );
    }
}
