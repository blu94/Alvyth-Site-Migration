<?php

namespace Plugin\SiteMigration\Tests;

use App\Models\Meta;
use App\Models\Page;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Plugin\SiteMigration\Backend\Bundle\BundleReader;
use Plugin\SiteMigration\Backend\Bundle\Manifest;
use Plugin\SiteMigration\Backend\Runs\Run;
use Plugin\SiteMigration\Backend\Runs\RunStore;
use Plugin\SiteMigration\Backend\Services\RewritePass;
use ZipArchive;

require_once __DIR__ . '/autoload.php';

/**
 * Gate 7 — no imported record points at the site the operator is leaving.
 *
 * **The failure this prevents is delayed and total.** A page-builder image node carries an absolute
 * URL to the source install. Left alone, every imported page hotlinks the old site and looks
 * perfectly fine — until that hosting is cancelled, at which point every image on the new site
 * breaks at once, months later, for a reason nobody connects to a migration.
 *
 * The pass cannot be exercised by importing a bundle into the site that produced it, because source
 * and destination hosts are then equal and it correctly does nothing. So these tests fabricate a
 * bundle that claims a *different* origin, which is what a real migration looks like.
 */
class RewritePassTest extends TestCase
{
    use DatabaseTransactions;

    private const SOURCE = 'https://old-shop.example';

    #[Test]
    public function a_source_host_is_rewritten_out_of_a_builder_node(): void
    {
        $this->actingAsSuperAdmin();

        [$run, $page] = $this->pageCarryingSourceUrls();

        app(RewritePass::class)->run($run, $this->manifest(includeMedia: true), $this->reader($run));

        $body = $this->blobOf($page);

        $this->assertStringNotContainsString(
            self::SOURCE,
            $body,
            'An imported page still points at the site it came from. Every image on it breaks the '
            . 'day that hosting is cancelled.'
        );

        $this->assertStringContainsString(
            rtrim((string) config('app.url'), '/'),
            $body,
            'The URL was removed rather than repointed at this site.'
        );
    }

    /**
     * An image URL is repointed at the file this run actually placed.
     *
     * **The half of the pass that had no fixture.** `assets.ndjson` in these tests was the single
     * line `{}`, so `assetPaths()` always returned an empty map and the path substitution never
     * ran — which is how `pluck('path')` reading through the `Asset::path` *accessor* survived a
     * green suite. It returned a fully qualified URL where a storage-relative path was wanted, and
     * the replacement then wrote that URL inside a string that already carried the source host:
     * `https://old-shop.example/storage/https://this-site/storage/assets/...`.
     *
     * So the assertion is not "the source host is gone" — that passed throughout — but that the
     * destination host appears exactly once, which is the thing the bug made false.
     */
    #[Test]
    public function an_image_url_is_repointed_at_the_file_this_run_placed(): void
    {
        $this->actingAsSuperAdmin();

        [$run, $page, $asset] = $this->pageCarryingAPlacedImage();

        app(RewritePass::class)->run($run, $this->manifest(includeMedia: true), $this->reader($run));

        $body  = $this->blobOf($page);
        $local = rtrim((string) config('app.url'), '/');

        $this->assertSame(
            1,
            substr_count($body, $local),
            'The rewritten URL names this site more than once, so a URL was substituted into a '
            . 'string that already carried one. Check that the asset path is read with '
            . 'getRawOriginal() rather than through the path accessor.'
        );

        $this->assertStringNotContainsString(
            self::SOURCE,
            $body,
            'The imported page still points at the site it came from.'
        );

        $this->assertStringContainsString(
            (string) $asset->getRawOriginal('path'),
            $body,
            'The URL was repointed at this site but not at the file this run actually created.'
        );

        $this->assertStringNotContainsString(
            'storage/https',
            $body,
            'A URL was substituted where a storage path belonged.'
        );
    }

    /**
     * Rewriting twice changes nothing the second time.
     *
     * A resumed run re-runs work it has already done, so a substitution that could fire twice would
     * turn a URL into nonsense on the second pass. Every replacement here goes source-shaped to
     * local, and a local value matches nothing — but that is a property worth asserting rather than
     * asserting about.
     */
    #[Test]
    public function rewriting_is_idempotent(): void
    {
        $this->actingAsSuperAdmin();

        [$run, $page] = $this->pageCarryingSourceUrls();

        $pass     = app(RewritePass::class);
        $manifest = $this->manifest(includeMedia: true);

        $pass->run($run, $manifest, $this->reader($run));
        $once = $this->blobOf($page);

        $pass->run($run, $manifest, $this->reader($run));
        $twice = $this->blobOf($page);

        $this->assertSame($once, $twice, 'A second pass changed the content, so a resumed run would corrupt it.');
    }

    /**
     * **When media did not travel, the URLs are deliberately left alone.**
     *
     * This is the labelled exception rather than a contradiction. The no-hotlink rule exists so an
     * operator never *unknowingly* depends on a site they are leaving — but when they chose not to
     * carry the image files, the imported site has no local copy to point at, and rewriting the URL
     * would replace a working borrowed image with a broken one. A blanked image is not the safer
     * failure; it is the same dependency with the evidence removed. The screen says so instead.
     */
    #[Test]
    public function urls_are_left_alone_when_media_did_not_travel(): void
    {
        $this->actingAsSuperAdmin();

        [$run, $page] = $this->pageCarryingSourceUrls();

        app(RewritePass::class)->run($run, $this->manifest(includeMedia: false), $this->reader($run));

        $body = $this->blobOf($page);

        $this->assertStringContainsString(
            self::SOURCE,
            $body,
            'The source URL was rewritten on a media-excluded run, which points the page at a local '
            . 'file that was never created.'
        );
    }

    /** An id buried in a JSON blob is remapped to where this run actually put that record. */
    #[Test]
    public function an_embedded_id_is_remapped(): void
    {
        $this->actingAsSuperAdmin();

        [$run, $page] = $this->pageCarryingSourceUrls();

        // The bundle said asset 4242; this run placed it as 99.
        $run->idMap()->remember('assets', 4242, 99);

        app(RewritePass::class)->run($run, $this->manifest(includeMedia: true), $this->reader($run));

        $data = Meta::query()->where('metaable_id', $page->getKey())->first()->data;

        $this->assertSame(99, $data['asset_id'] ?? null, 'A source id survived into an imported page.');
    }

    // ---------------------------------------------------------------- helpers

    /**
     * A page's builder blobs as one searchable string.
     *
     * `JSON_UNESCAPED_SLASHES` matters more than it looks: without it `json_encode` writes
     * `http:\/\/localhost`, so asserting on a plain URL fails against output that is in fact
     * perfectly correct — which is a test that lies in the more expensive direction.
     */
    private function blobOf(Page $page): string
    {
        return (string) json_encode(
            Meta::query()->where('metaable_id', $page->getKey())->get()->pluck('data'),
            JSON_UNESCAPED_SLASHES
        );
    }

    /**
     * A page whose builder node carries a source URL and a source id, plus the run that "placed" it.
     *
     * @return array{0: Run, 1: Page}
     */
    private function pageCarryingSourceUrls(): array
    {
        $slug = 'rewrite-' . bin2hex(random_bytes(3));

        $page = Page::create([
            'title'  => ['en' => 'Rewrite target'],
            'slug'   => ['en' => $slug],
            'type'   => 'PAGE',
            'status' => 'draft',
        ]);

        $meta = new Meta([
            'type' => 'SECTION',
            'data' => [
                'asset_id' => 4242,
                'src'      => self::SOURCE . '/storage/assets/original/2026/07/hero.jpg',
                'body'     => '<img src="' . self::SOURCE . '/storage/assets/original/2026/07/hero.jpg">',
            ],
        ]);

        $meta->metaable_id   = $page->getKey();
        $meta->metaable_type = $page->getMorphClass();
        $meta->save();

        $run = app(RunStore::class)->create(Run::DIRECTION_IMPORT, ['modules' => ['pages']]);

        // The pass only visits records this run recorded placing.
        $run->idMap()->remember('pages', 1, (int) $page->getKey());

        $this->writeBundle($run);

        return [$run, $page];
    }

    /**
     * A page whose builder node points at an image that this run placed under a different path.
     *
     * The source path deliberately differs from the local one — a real migration lands a file under
     * the destination's own year and month — because a substitution that resolved to itself would
     * pass whether or not the bug was present.
     *
     * @return array{0: Run, 1: Page, 2: \App\Models\Asset}
     */
    private function pageCarryingAPlacedImage(): array
    {
        $sourcePath = 'assets/original/2026/07/hero-' . bin2hex(random_bytes(3)) . '.jpg';

        $asset = \App\Models\Asset::create([
            'title'  => 'Hero',
            'usage'  => 'MIGRATION_IMPORT',
            'path'   => 'assets/original/2026/09/hero-' . bin2hex(random_bytes(3)) . '.jpg',
            'format' => 'jpg',
            'size'   => 1234,
            'disk'   => 'public',
        ]);

        $slug = 'rewrite-image-' . bin2hex(random_bytes(3));

        $page = Page::create([
            'title'  => ['en' => 'Rewrite image'],
            'slug'   => ['en' => $slug],
            'type'   => 'PAGE',
            'status' => 'draft',
        ]);

        $meta = new Meta([
            'type' => 'SECTION',
            'data' => ['src' => self::SOURCE . '/storage/' . $sourcePath],
        ]);

        $meta->metaable_id   = $page->getKey();
        $meta->metaable_type = $page->getMorphClass();
        $meta->save();

        $run = app(RunStore::class)->create(Run::DIRECTION_IMPORT, ['modules' => ['pages', 'assets']]);

        $run->idMap()->remember('pages', 1, (int) $page->getKey());
        $run->idMap()->remember('assets', 7, (int) $asset->getKey());

        $this->writeBundle($run, json_encode([
            '_source'     => 7,
            'source_path' => $sourcePath,
        ]) . "\n");

        return [$run, $page, $asset];
    }

    private function manifest(bool $includeMedia): Manifest
    {
        return Manifest::fromArray([
            'format'        => 1,
            'source'        => ['url' => self::SOURCE, 'alvyth' => (string) config('alvyth.version')],
            'contents'      => ['pages' => 1],
            'include_media' => $includeMedia,
        ]);
    }

    /** A minimal bundle on disk, so the pass can read the asset records it needs. */
    private function writeBundle(Run $run, string $assets = "{}\n"): void
    {
        $zip = new ZipArchive();
        $zip->open($run->directory . '/bundle.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('manifest.json', json_encode($this->manifest(true)->toArray()));
        $zip->addFromString('data/pages.ndjson', "{}\n");
        $zip->addFromString('data/assets.ndjson', $assets);
        $zip->close();
    }

    private function reader(Run $run): BundleReader
    {
        return new BundleReader($run->bundlePath(), $run->directory . '/extracted');
    }
}
