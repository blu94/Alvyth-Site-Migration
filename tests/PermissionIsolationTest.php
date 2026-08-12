<?php

namespace Plugin\SiteMigration\Tests;

use App\Models\Product;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Plugin\SiteMigration\Backend\Runs\Run;
use Plugin\SiteMigration\Backend\Runs\RunStore;
use Plugin\SiteMigration\Backend\Services\Exporter;
use Plugin\SiteMigration\Backend\Services\Importer;
use Plugin\SiteMigration\Backend\Support\Permissions;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

require_once __DIR__ . '/autoload.php';

/**
 * Gate 6 — holding `site_migration.*` is not, on its own, permission to rewrite the catalogue.
 *
 * This is the most important thing in the package to get right. An import writes products, pages
 * and users; if it checked only `site_migration.create` then granting somebody the right to run a
 * migration would grant them the right to rewrite everything, without ever holding
 * `products.update`. That is a privilege-escalation path wearing a convenience feature's name,
 * and the only thing standing between it and a shipped release is this file.
 */
class PermissionIsolationTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * An operator who may migrate but not update products cannot overwrite one, and the refusal
     * names the resource rather than being generic.
     */
    #[Test]
    public function migrating_is_not_permission_to_overwrite_products(): void
    {
        $bundle = $this->bundleFromSuperAdmin();

        $this->actingAsOperator([
            'site_migration.view',
            'site_migration.create',
            'products.view',
            'products.create',
            // Deliberately no products.update.
        ]);

        $run = $this->importRun($bundle, overwrite: true);

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessageMatches('/products/');

        Permissions::assertMayWrite(['products'], overwriting: true);
    }

    /** The same operator may still create, because that is a grant they hold. */
    #[Test]
    public function creating_is_allowed_when_the_grant_is_held(): void
    {
        $this->actingAsOperator([
            'site_migration.create',
            'products.view',
            'products.create',
        ]);

        Permissions::assertMayWrite(['products'], overwriting: false);

        $this->assertTrue(true, 'Creating with products.create held must not throw.');
    }

    /**
     * Somebody who may not run a migration at all is refused before any resource is considered.
     */
    #[Test]
    public function running_a_migration_needs_the_plugin_permission(): void
    {
        $this->actingAsOperator(['products.view', 'products.create', 'products.update']);

        $this->expectException(AccessDeniedHttpException::class);

        Permissions::assertMayRun();
    }

    /**
     * An export leaves out what the operator may not read, and **says so on the run** rather than
     * silently producing a short bundle.
     *
     * Filtered rather than refused, unlike the import: an export the operator narrowed is still a
     * valid bundle, whereas an import that skipped a resource leaves a destination that is partly
     * migrated and looks finished. What must never happen is the omission being invisible — that
     * would be discovered on the destination, which is the worst possible place.
     */
    #[Test]
    public function an_export_records_what_it_left_out(): void
    {
        $this->actingAsOperator([
            'site_migration.create',
            // No products.view.
        ]);

        $run = app(RunStore::class)->create(Run::DIRECTION_EXPORT, ['modules' => ['products']]);

        $result = app(Exporter::class)->step($run);

        $errors = (array) app(RunStore::class)->find($run->id)->get('errors', []);

        $this->assertTrue($result['failed'] ?? false, 'An export with nothing readable should fail rather than seal an empty bundle.');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('products', implode(' ', $errors));
    }

    /**
     * The plugin's resource is its own, never a core one.
     *
     * Read from `config/settings.php` **on disk**, not through `config()`. An enabled plugin's own
     * resources are merged into the runtime config, so `config('settings.permissions')` contains
     * `site_migration` precisely *because* this package is installed — asserting against it would
     * fail for the one reason that is not a problem, and would keep failing however the package
     * named itself.
     */
    #[Test]
    public function the_permission_resource_is_not_a_core_name(): void
    {
        $this->assertSame('site_migration', Permissions::RESOURCE);

        $core = require config_path('settings.php');

        $this->assertArrayNotHasKey(
            Permissions::RESOURCE,
            (array) ($core['permissions'] ?? []),
            'The package claims a permission resource core already owns, which widens a core grant.'
        );
    }

    // ---------------------------------------------------------------- helpers

    private function bundleFromSuperAdmin(): string
    {
        $this->actingAsSuperAdmin();

        Product::factory()->create([
            'sku'            => 'PERM-' . bin2hex(random_bytes(3)),
            'title'          => ['en' => 'Gated'],
            'productable_id' => null,
        ]);

        $run = app(RunStore::class)->create(Run::DIRECTION_EXPORT, ['modules' => ['products']]);

        $this->pressUntilDone(fn () => app(Exporter::class)->step(app(RunStore::class)->find($run->id)));

        return app(RunStore::class)->find($run->id)->bundlePath();
    }

    private function importRun(string $bundle, bool $overwrite): Run
    {
        $run = app(RunStore::class)->create(Run::DIRECTION_IMPORT, [
            'modules'     => ['products'],
            'on_conflict' => $overwrite ? 'overwrite' : 'skip',
        ]);

        copy($bundle, $run->directory . '/bundle.zip');

        return $run;
    }
}
