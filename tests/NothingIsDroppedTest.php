<?php

namespace Plugin\SiteMigration\Tests;

use App\Models\EmailTemplate;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Plugin\SiteMigration\Backend\Resources\Collision;
use Plugin\SiteMigration\Backend\Resources\DriverRegistry;
use Plugin\SiteMigration\Backend\Runs\Run;
use Plugin\SiteMigration\Backend\Runs\RunStore;
use Plugin\SiteMigration\Backend\Services\Exporter;
use Plugin\SiteMigration\Backend\Services\Importer;

require_once __DIR__ . '/autoload.php';

/**
 * **No record in a bundle is ever discarded.** This is the strongest promise the package makes and
 * the one that replaced an earlier, weaker design.
 *
 * The first version offered "leave mine alone", which quietly meant *throw theirs away*: a
 * migration that reported success while losing data it had been asked to carry. A name collision
 * now has exactly two outcomes and both keep everything — replace what is here, or write the
 * incoming record alongside under a free name.
 */
class NothingIsDroppedTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsSuperAdmin();
    }

    /**
     * A colliding product is kept alongside under a free SKU, and neither version is lost.
     */
    #[Test]
    public function a_clashing_record_is_kept_alongside_rather_than_dropped(): void
    {
        $sku = 'DROP-' . bin2hex(random_bytes(3));

        Product::factory()->create([
            'sku'            => $sku,
            'title'          => ['en' => 'From the bundle'],
            'productable_id' => null,
        ]);

        $bundle = $this->export();

        // The site's own version now differs from the one in the bundle.
        Product::where('sku', $sku)->first()->update(['title' => ['en' => 'Mine, edited here']]);

        $run = $this->importRun($bundle, overwrite: false);

        $this->pressUntilDone(fn () => app(Importer::class)->step($this->reload($run)));

        $tally = $this->reload($run)->tally();

        $this->assertSame(1, $tally['renamed'], 'The clashing record should have been renamed, not skipped.');
        $this->assertSame(0, $tally['failed']);

        $this->assertSame(
            'Mine, edited here',
            Product::where('sku', $sku)->first()->getTranslation('title', 'en'),
            "This site's own version was modified despite overwriting being off."
        );

        $this->assertSame(
            'From the bundle',
            Product::where('sku', $sku . '-2')->first()?->getTranslation('title', 'en'),
            'The incoming record was dropped instead of being kept under a free name.'
        );
    }

    /**
     * A renamed parent takes its variants with it.
     *
     * A variant's SKU is unique across the whole table, not within its parent — so a renamed parent
     * whose variants kept their original SKUs landed and then hit the unique index on every one of
     * them, arriving without the sizes and colours that make it a product. Found by importing one.
     */
    #[Test]
    public function a_renamed_product_takes_its_variants_with_it(): void
    {
        $sku = 'VAR-' . bin2hex(random_bytes(3));

        $parent = Product::factory()->create([
            'sku'            => $sku,
            'title'          => ['en' => 'Parent'],
            'productable_id' => null,
        ]);

        foreach (['S', 'M'] as $size) {
            Product::factory()->create([
                'sku'              => $sku . '-' . $size,
                'title'            => ['en' => $size],
                'type'             => 'variant',
                'productable_id'   => $parent->getKey(),
                'productable_type' => Product::class,
            ]);
        }

        $bundle = $this->export();

        Product::where('sku', $sku)->first()->update(['title' => ['en' => 'Edited here']]);

        $run = $this->importRun($bundle, overwrite: false);
        $this->pressUntilDone(fn () => app(Importer::class)->step($this->reload($run)));

        $this->assertSame(0, $this->reload($run)->tally()['failed'], 'A variant collision failed the record.');

        $renamed = Product::where('sku', $sku . '-2')->first();

        $this->assertNotNull($renamed, 'The renamed parent was not written.');
        $this->assertSame(2, $renamed->variants()->count(), 'The renamed product arrived without its variants.');
    }

    /**
     * **Overwriting requires the acknowledgement, not just the choice.**
     *
     * The switch is the choice; the mirrored flag is proof the confirmation dialog was accepted.
     * Reading only the switch would let a hand-built POST overwrite a site without ever meeting the
     * warning, which is the one thing the dialog exists to prevent.
     */
    #[Test]
    public function overwriting_without_the_acknowledgement_does_not_overwrite(): void
    {
        $sku = 'ACK-' . bin2hex(random_bytes(3));

        Product::factory()->create([
            'sku'            => $sku,
            'title'          => ['en' => 'From the bundle'],
            'productable_id' => null,
        ]);

        $bundle = $this->export();

        Product::where('sku', $sku)->first()->update(['title' => ['en' => 'Mine, edited here']]);

        // The mode says overwrite; the acknowledgement is absent.
        $run = app(RunStore::class)->create(Run::DIRECTION_IMPORT, [
            'modules'                => ['products'],
            'on_conflict'            => Collision::OVERWRITE,
            'overwrite_acknowledged' => false,
        ]);

        copy($bundle, $run->directory . '/bundle.zip');

        $this->assertFalse($run->overwrites(), 'An unacknowledged overwrite must not count as one.');

        $this->pressUntilDone(fn () => app(Importer::class)->step($this->reload($run)));

        $this->assertSame(
            'Mine, edited here',
            Product::where('sku', $sku)->first()->getTranslation('title', 'en'),
            'A record was overwritten without the confirmation having been accepted.'
        );
    }

    /**
     * An account is merged, never duplicated.
     *
     * Every other resource resolves a clash by keeping both under two names. An email address will
     * not take that: it is not a label on a record, it *is* the person, and `jane+2@example.com`
     * would be a second account nobody can sign into.
     */
    #[Test]
    public function an_account_merges_rather_than_duplicating(): void
    {
        $email = 'merge-' . bin2hex(random_bytes(3)) . '@example.test';

        User::factory()->create(['email' => $email, 'name' => 'From the bundle']);

        $bundle = $this->export(['users']);

        User::where('email', $email)->first()->update(['name' => 'Renamed here']);

        $run = $this->importRun($bundle, overwrite: false, modules: ['users']);
        $this->pressUntilDone(fn () => app(Importer::class)->step($this->reload($run)));

        $this->assertSame(
            1,
            User::where('email', $email)->count(),
            'A second account was created for one email address.'
        );

        $this->assertSame(0, $this->reload($run)->tally()['renamed'], 'An account was renamed to dodge a collision.');
    }

    /**
     * Excluding nothing means every resource that is data.
     *
     * **The one exception is code, and it inverts the default deliberately.** Exclusion is the right
     * model for content: forget to tick something and it travels anyway, which is the safe direction
     * to fail. For a theme's or a plugin's *files* the safe direction is the opposite one — forget,
     * and nobody else's code runs on the destination — so those two are asked for rather than
     * remembered against.
     */
    #[Test]
    public function excluding_nothing_exports_every_data_resource(): void
    {
        $registry = app(DriverRegistry::class);

        $expected = array_values(array_diff($registry->keys(), DriverRegistry::CODE_GROUPS));

        $this->assertSame($expected, $registry->everythingExcept([]));

        $this->assertNotContains(
            'products',
            $registry->everythingExcept(['products']),
            'An excluded resource still travelled.'
        );
    }

    /** Code travels only when it is asked for, and exclusion still applies on top of that. */
    #[Test]
    public function theme_and_plugin_files_are_opt_in(): void
    {
        $registry = app(DriverRegistry::class);

        foreach (DriverRegistry::CODE_GROUPS as $key) {
            $this->assertNotContains(
                $key,
                $registry->everythingExcept([]),
                "{$key} travelled without being asked for."
            );

            $this->assertContains(
                $key,
                $registry->everythingExcept([], includeCode: true),
                "{$key} did not travel even when it was asked for."
            );
        }

        $this->assertSame($registry->keys(), $registry->everythingExcept([], includeCode: true));

        $this->assertNotContains(
            'themes',
            $registry->everythingExcept(['themes'], includeCode: true),
            'An excluded resource travelled because code was opted into.'
        );
    }

    /**
     * The one stated exception to "nothing is dropped", and it is the operator's own work.
     *
     * Four resources merge because there is no free name to write a second copy under — settings,
     * email templates, a theme, a plugin. Merging them replaces something somebody authored *here*,
     * so it now needs the overwrite acknowledgement like every other replacement. Without it the
     * incoming record is left out and said so, which is a smaller loss than silently overwriting a
     * shop's transactional emails while the screen promises nothing will be replaced.
     */
    #[Test]
    public function a_merge_that_replaces_local_work_waits_for_consent(): void
    {
        $this->actingAsSuperAdmin();

        $registry = app(DriverRegistry::class);

        $guarded = array_values(array_filter(
            $registry->keys(),
            fn (string $key) => $registry->for($key)->mergeReplacesLocalWork()
        ));

        $this->assertSame(
            ['email_templates', 'settings', 'themes', 'plugins'],
            $guarded,
            'The set of resources whose merge replaces the operator\'s own work has changed. If that '
            . 'is deliberate, the import screen\'s wording has to change with it.'
        );

        foreach ($guarded as $key) {
            $this->assertTrue(
                $registry->for($key)->mergesOnCollision(),
                "{$key} claims its merge is destructive but does not merge, which cannot both be true."
            );
        }

        // The identity merges are the other half of the rule: one email is one person, one content
        // hash is one file, so those update in place without asking and lose nothing.
        foreach (['users', 'assets'] as $key) {
            $this->assertTrue($registry->for($key)->mergesOnCollision());
            $this->assertFalse(
                $registry->for($key)->mergeReplacesLocalWork(),
                "{$key} is an identity merge and must not require consent to place a record."
            );
        }
    }

    /**
     * And the rule holds end to end, not just in the flags.
     *
     * An email template edited here is what the shop's customers actually receive. Before this, a
     * bundle replaced it on the merge branch with the overwrite switch off and the confirmation
     * dialog never shown — while the screen promised nothing would be replaced.
     */
    #[Test]
    public function an_edited_email_template_is_not_replaced_without_consent(): void
    {
        $this->actingAsSuperAdmin();

        $template = EmailTemplate::query()->first();

        if ($template === null) {
            $this->markTestSkipped('This install has no email templates to protect.');
        }

        $bundle = $this->export(['email_templates']);

        $mine = 'Edited on this site ' . bin2hex(random_bytes(3));
        $template->update(['title' => ['en' => $mine]]);

        $run = $this->importRun($bundle, overwrite: false, modules: ['email_templates']);
        $this->pressUntilDone(fn () => app(Importer::class)->step($this->reload($run)));

        // `title` is a plain array cast, not a Spatie translation — an email template is a `Meta`
        // row, which is the distinction `BaseDriver::translations()` exists to handle.
        $after = EmailTemplate::query()->find($template->getKey())?->title;

        $this->assertSame(
            $mine,
            is_array($after) ? ($after['en'] ?? null) : $after,
            'A bundle replaced an email template this site had edited, without the overwrite switch.'
        );

        $errors = implode(' ', (array) $this->reload($run)->get('errors', []));

        $this->assertStringContainsString(
            'Overwrite my records when they clash',
            $errors,
            'The template was left alone but the run did not say so, which is a silent drop.'
        );
    }

    // ---------------------------------------------------------------- helpers

    /** @param array<int,string> $modules */
    private function export(array $modules = ['products']): string
    {
        $run = app(RunStore::class)->create(Run::DIRECTION_EXPORT, [
            'modules'       => $modules,
            'include_media' => false,
        ]);

        $this->pressUntilDone(fn () => app(Exporter::class)->step($this->reload($run)));

        $bundle = $this->reload($run)->bundlePath();

        $this->assertNotNull($bundle, 'The export did not seal a bundle.');

        return $bundle;
    }

    /** @param array<int,string> $modules */
    private function importRun(string $bundle, bool $overwrite, array $modules = ['products']): Run
    {
        $run = app(RunStore::class)->create(Run::DIRECTION_IMPORT, [
            'modules'                => $modules,
            'on_conflict'            => $overwrite ? Collision::OVERWRITE : Collision::KEEP_BOTH,
            'overwrite_acknowledged' => $overwrite,
        ]);

        copy($bundle, $run->directory . '/bundle.zip');

        return $run;
    }

    private function reload(Run $run): Run
    {
        return app(RunStore::class)->find($run->id);
    }
}
