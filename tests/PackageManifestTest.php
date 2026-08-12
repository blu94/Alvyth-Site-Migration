<?php

namespace Plugin\SiteMigration\Tests;

use App\Services\Plugin\PluginManifest;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

require_once __DIR__ . '/autoload.php';

/**
 * The claims `plugin.json` makes that this package cannot survive being wrong about.
 *
 * Three of these are values Ovynt refuses at install, and they are the ones plugin authors get
 * wrong — but the reason to assert them here rather than trust the installer is that two of them
 * fail *silently at the wrong layer*: a wrong `routeBase` gives a list screen that loads and an
 * Add button that 404s, and a pipe-delimited `rules` string is valid Laravel and is therefore
 * ignored by both the form and the server.
 */
class PackageManifestTest extends TestCase
{
    /** @return array<string,mixed> */
    private function raw(): array
    {
        $path = dirname(__DIR__) . '/plugin.json';

        $this->assertFileExists($path);

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    private function manifest(): PluginManifest
    {
        return PluginManifest::fromArray($this->raw());
    }

    /** The version range is honoured, and states a real upper bound. */
    #[Test]
    public function the_package_declares_a_bounded_ovynt_range(): void
    {
        $manifest = $this->manifest();

        $this->assertTrue($manifest->satisfiedBy('1.2.0'));
        $this->assertTrue($manifest->satisfiedBy((string) config('ovynt.version')));

        // A 2.x core is a different contract, and installing into one unread is how a package
        // breaks quietly.
        $this->assertFalse($manifest->satisfiedBy('2.0.0'));
        $this->assertFalse($manifest->satisfiedBy('1.1.0'));
    }

    /**
     * The module declares `api` plural and `routeBase` singular.
     *
     * `api` must be present at all or the schema engine parses `module.json` as a *form field* and
     * dies with `Field requires 'key', 'type', and 'label'` — an error naming nothing the author
     * wrote. `routeBase` plural is the one that leaves the backend perfectly healthy while a
     * button 404s, which is exactly the failure a green PHP suite misses.
     */
    #[Test]
    public function the_module_declares_the_three_load_bearing_values(): void
    {
        $module = json_decode(
            (string) file_get_contents(dirname(__DIR__) . '/admin/modules/migration-runs/module.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertSame('migration-runs', $module['type'], 'The type must equal the directory name.');
        $this->assertSame('/admin/modules/migration-runs', $module['api'], 'api is plural.');
        $this->assertSame('module/migration-runs', $module['routeBase'], 'routeBase is singular.');
        $this->assertSame('site_migration', $module['resource']);
    }

    /**
     * Every `rules` in every schema is an array.
     *
     * A pipe string is valid Laravel, so nothing refuses it — the field simply looks validated and
     * accepts anything. Checked across every schema the package ships rather than the ones that
     * happen to have rules today.
     */
    #[Test]
    public function every_validation_rule_is_an_array(): void
    {
        $checked = 0;

        foreach (glob(dirname(__DIR__) . '/admin/modules/*/*.json') as $path) {
            $schema = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

            $checked += $this->assertRulesAreArrays($schema, basename($path));
        }

        $this->assertGreaterThan(0, $checked, 'No rules were found at all, so this asserted nothing.');
    }

    /** Every custom page named in `module.json` has a schema file behind it. */
    #[Test]
    public function every_declared_page_has_a_schema(): void
    {
        $directory = dirname(__DIR__) . '/admin/modules/migration-runs';

        $module = json_decode((string) file_get_contents($directory . '/module.json'), true, 512, JSON_THROW_ON_ERROR);

        $refs = 0;

        foreach ($module as $key => $value) {
            if (is_array($value) && isset($value['$ref'])) {
                $this->assertFileExists($directory . '/' . $value['$ref'], "module.json declares '{$key}' but its schema is missing.");
                $refs++;
            }
        }

        $this->assertGreaterThan(0, $refs);
    }

    /**
     * Every navigation child names a module the package declares.
     *
     * A child naming one it does not is refused at install — it would either 404 or deep-link into
     * another plugin's screens from a menu the operator reads as this one's.
     */
    #[Test]
    public function every_navigation_child_names_a_declared_module(): void
    {
        $raw      = $this->raw();
        $declared = (array) ($raw['admin']['modules'] ?? []);
        $children = (array) ($raw['admin']['navigation']['children'] ?? []);

        $this->assertNotEmpty($children);

        foreach ($children as $child) {
            $this->assertContains(
                $child['module'] ?? null,
                $declared,
                "Navigation child '" . ($child['title'] ?? '?') . "' names a module the package does not declare."
            );
        }
    }

    /**
     * The package ships no migrations, and therefore names no tables to drop.
     *
     * A deliberate design decision rather than an omission: a run is a directory under
     * `storage/app/site-migration`, not a row. Asserted so that adding a migration later is a
     * conscious act that also has to answer for `uninstall.drop_tables` — a package that creates
     * tables and does not declare them leaves them behind on a purge.
     */
    #[Test]
    public function the_package_owns_no_database_tables(): void
    {
        $this->assertDirectoryDoesNotExist(
            dirname(__DIR__) . '/backend/migrations',
            'The package has gained migrations. If that is intended, declare the tables in uninstall.drop_tables and delete this test.'
        );

        $this->assertArrayNotHasKey('uninstall', $this->raw());
    }

    /**
     * Count the `rules` keys in a schema, asserting each is an array.
     *
     * @param  array<mixed>  $node
     */
    private function assertRulesAreArrays(array $node, string $file): int
    {
        $found = 0;

        foreach ($node as $key => $value) {
            if ($key === 'rules') {
                $this->assertIsArray($value, "{$file}: 'rules' must be an array, not a pipe string.");
                $found++;

                continue;
            }

            if (is_array($value)) {
                $found += $this->assertRulesAreArrays($value, $file);
            }
        }

        return $found;
    }
}
