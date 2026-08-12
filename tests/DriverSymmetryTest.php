<?php

namespace Plugin\SiteMigration\Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Plugin\SiteMigration\Backend\Resources\DriverRegistry;
use Plugin\SiteMigration\Backend\Support\Canonical;

require_once __DIR__ . '/autoload.php';

/**
 * **Every driver must describe a record the same way twice.**
 *
 * `CanonicalFormTest` pins the hashing rules against synthetic input. This pins something the
 * rules cannot see: that a *driver* produces identical output from a record it eager-loaded on
 * export and the same record loaded bare on import.
 *
 * That distinction is not academic. `PageDriver::metaToArray()` guarded its recursion with
 * `relationLoaded('children') ? … : []` to avoid an N+1, which meant the export wrote the whole
 * builder tree and the import compared against an empty one — so **every page on the site read as
 * changed and was rewritten on every migration**, silently, forever. The canonical form was
 * blameless and every existing test passed. This is the test that sees it.
 *
 * It walks whatever the registry holds, so a driver added later is covered without anyone
 * remembering to come back here.
 */
class DriverSymmetryTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function every_driver_describes_a_record_identically_twice(): void
    {
        $this->actingAsSuperAdmin();

        $registry = app(DriverRegistry::class);
        $checked  = 0;

        foreach ($registry->keys() as $key) {
            $driver = $registry->for($key);

            // As the export sees it: through `exportQuery()`, with its eager loads.
            $eager = $driver->exportQuery()->first();

            if ($eager === null) {
                continue;
            }

            $exported = $driver->toRecord($eager);

            // As the import sees it: whatever `locate()` hands back, which is a bare model.
            $located = $driver->locate($exported);

            $this->assertNotNull(
                $located,
                "[{$key}] a record this driver just exported could not be found again by its own "
                . 'natural key, so every migration would duplicate it.'
            );

            $reloaded = $driver->toRecord($located);

            $this->assertSame(
                Canonical::encode($exported, $driver->volatileFields()),
                Canonical::encode($reloaded, $driver->volatileFields()),
                "[{$key}] the driver describes one record two different ways depending on how it "
                . 'was loaded. Every record of this kind would compare as changed and be rewritten '
                . 'on every migration, with nothing reporting why.'
            );

            $checked++;
        }

        $this->assertGreaterThan(0, $checked, 'No driver had a record to check, so this asserted nothing.');
    }

    /**
     * Every driver's permission resource is one core actually declares.
     *
     * A driver whose key does not match a core permission name — `posts` is gated by `blogs`,
     * `email_templates` by `settings` — would ask `can('posts.view')`, which is false for
     * everybody, and the resource would be **silently dropped from every export**.
     */
    #[Test]
    public function every_driver_is_gated_by_a_permission_that_exists(): void
    {
        $registry = app(DriverRegistry::class);
        $declared = (array) config('settings.permissions');

        foreach ($registry->keys() as $key) {
            $resource = $registry->for($key)->permissionResource();

            $this->assertArrayHasKey(
                $resource,
                $declared,
                "[{$key}] is gated on '{$resource}', which core does not declare — so every check "
                . 'against it is false and the resource would never travel.'
            );
        }
    }

    /** The import order is a dependency graph, and taxonomies have to come before what uses them. */
    #[Test]
    public function taxonomies_and_assets_land_before_the_records_that_reference_them(): void
    {
        $order = array_flip(app(DriverRegistry::class)->keys());

        foreach (['categories', 'tags', 'assets'] as $early) {
            foreach (['products', 'pages', 'posts'] as $late) {
                if (! isset($order[$early], $order[$late])) {
                    continue;
                }

                $this->assertLessThan(
                    $order[$late],
                    $order[$early],
                    "{$early} must be written before {$late}, or {$late} references rows that do not exist yet."
                );
            }
        }
    }

    /** Record groups are a strict subset of the registry, and content is everything else. */
    #[Test]
    public function record_groups_are_separated_from_content(): void
    {
        $registry = app(DriverRegistry::class);

        $this->assertSame(
            [],
            array_diff($registry->recordKeys(), $registry->keys()),
            'A record group names a driver the registry does not have.'
        );

        $this->assertSame(
            [],
            array_intersect($registry->contentKeys(), $registry->recordKeys()),
            'A resource is in both lists, so it would be ticked by default despite being about people.'
        );

        $this->assertContains(
            'users',
            $registry->recordKeys(),
            'Accounts must never be part of the content selection, which defaults to everything.'
        );
    }
}
