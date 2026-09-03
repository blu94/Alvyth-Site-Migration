<?php

namespace Plugin\SiteMigration\Tests;

use App\Models\Theme;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Plugin\SiteMigration\Backend\Bundle\BundleContext;
use Plugin\SiteMigration\Backend\Bundle\CredentialVault;
use Plugin\SiteMigration\Backend\Resources\AssetDriver;
use Plugin\SiteMigration\Backend\Resources\PluginDriver;
use Plugin\SiteMigration\Backend\Resources\SkipRecord;
use Plugin\SiteMigration\Backend\Resources\ThemeDriver;
use Plugin\SiteMigration\Backend\Runs\RunStore;
use Plugin\SiteMigration\Backend\Support\Permissions;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

require_once __DIR__ . '/autoload.php';

/**
 * A bundle is a file another machine wrote, and every field in it is input.
 *
 * **The package validated a bundle's *shape* and then trusted its *content*.** The manifest format,
 * the checksums and zip-slip were all checked; the filenames, the package slugs and the cipher name
 * inside were not, and each of those reaches something that acts on it — a filesystem path, an
 * upload, a decrypt. These tests hold that line, because it is the one a green suite did not.
 */
class BundleIsUntrustedTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * A slug is a directory name, so it has to look like one.
     *
     * `../plugins/{something}` read from the bundle's own tree and wrote over an installed package,
     * around the version guard and the disabled-on-arrival rule both.
     */
    #[Test]
    public function a_traversing_package_slug_is_refused(): void
    {
        $this->actingAsSuperAdmin();

        foreach (['../plugins/redirect-manager', 'Ella', 'has space', '../../etc', ''] as $slug) {
            foreach ([ThemeDriver::class, PluginDriver::class] as $class) {
                try {
                    app($class)->locate(['slug' => $slug]);

                    $this->fail(sprintf(
                        '%s accepted "%s" as a slug. It becomes a directory name.',
                        class_basename($class),
                        $slug
                    ));
                } catch (SkipRecord|RuntimeException) {
                    $this->addToAssertionCount(1);
                }
            }
        }
    }

    /**
     * The files of the theme the shop is rendering are never replaced.
     *
     * The settings and menus still land — that is why themes travel at all — so the assertion is
     * about the note, not about the row.
     */
    #[Test]
    public function the_active_theme_keeps_its_files(): void
    {
        $this->actingAsSuperAdmin();

        // Created rather than found. A test that skips itself on an install with no active theme
        // skips exactly where the protection is easiest to lose — and `DatabaseTransactions` rolls
        // this row back, so it cannot leave a second active theme behind.
        $active = Theme::query()->where('status', 'active')->first() ?? Theme::create([
            'slug'   => 'audit-active-' . bin2hex(random_bytes(3)),
            'title'  => 'Active during this test',
            'status' => 'active',
        ]);

        // A bundle that genuinely carries files for this theme, so the guard is reached rather than
        // short-circuited by there being nothing to copy.
        $run    = app(RunStore::class)->create('import');
        $staged = $run->directory . '/themes/' . $active->slug;

        File::ensureDirectoryExists($staged);
        File::put($staged . '/layout.blade.php', '<?php /* the bundle author writes this */ ?>');

        $target = storage_path('app/themes/' . $active->slug);
        $before = is_dir($target) ? File::allFiles($target) : [];

        $driver = app(ThemeDriver::class);
        $driver->useBundle(new BundleContext($run->directory, true));

        $driver->write([
            'slug'     => $active->slug,
            'title'    => $active->title,
            'settings' => $active->settings,
            'sections' => $active->sections,
        ], $active);

        $notes = $driver->takeNotes();

        $this->assertNotEmpty($notes, 'Replacing the live theme said nothing about its files.');
        $this->assertStringContainsString('files were left alone', implode(' ', $notes));

        $this->assertFileDoesNotExist(
            $target . '/layout.blade.php',
            'A bundle overwrote a file in the theme the shop is currently rendering.'
        );

        $this->assertCount(
            count($before),
            is_dir($target) ? File::allFiles($target) : [],
            'The live theme directory changed during an import.'
        );
    }

    /** Installing code is a super-admin act, whoever holds `themes.create`. */
    #[Test]
    public function importing_code_requires_a_super_admin(): void
    {
        $this->actingAsOperator([
            'site_migration.create',
            'themes.view', 'themes.create', 'themes.update',
            'plugins.view', 'plugins.create', 'plugins.update',
        ]);

        foreach (Permissions::CODE_RESOURCES as $resource) {
            try {
                Permissions::assertMayWrite([$resource], false);

                $this->fail("An ordinary operator was allowed to import {$resource}.");
            } catch (AccessDeniedHttpException $e) {
                $this->assertStringContainsString('super administrator', $e->getMessage());
            }
        }

        $this->actingAsSuperAdmin();

        Permissions::assertMayWrite(Permissions::CODE_RESOURCES, false);
        $this->addToAssertionCount(1);
    }

    /**
     * A bundle cannot name a file type this site does not accept.
     *
     * Core's own upload pipeline has no allowlist — filed as core defect 13 — so this driver is
     * where the line is drawn until it does.
     */
    #[Test]
    public function a_bundle_cannot_name_an_arbitrary_file_type(): void
    {
        $this->actingAsSuperAdmin();

        $run    = app(RunStore::class)->create('import');
        $driver = app(AssetDriver::class);

        $driver->useBundle(new BundleContext($run->directory, true));

        // **Real bytes for every case.** Without them `bytesFor()` refuses first, for a reason that
        // has nothing to do with the file's type — and the test would pass whether or not the
        // allowlist existed.
        $source = 1;

        foreach (['shell.php', 'page.html', 'logo.svg', 'run.phtml', 'noextension'] as $filename) {
            $source++;

            $directory = $run->directory . '/media/' . $source;

            File::ensureDirectoryExists($directory);
            File::put($directory . '/' . $filename, '<?php echo "arbitrary"; ?>');

            try {
                $driver->write([
                    '_source'      => $source,
                    'content_hash' => str_repeat('a', 64),
                    'filename'     => $filename,
                    'has_bytes'    => true,
                ], null);

                $this->fail("A bundle was allowed to write \"{$filename}\" into the media library.");
            } catch (SkipRecord $e) {
                // The wording is core's now, not this package's — `UploadPolicy` owns the policy and
                // this driver only translates the refusal into a `SkipRecord`. Matched loosely
                // enough to survive core rephrasing its own message, tightly enough to prove the
                // refusal came from the type check rather than from a missing bundle or absent bytes.
                $this->assertMatchesRegularExpression(
                    '/not a (file )?type this site accepts|contents are/i',
                    $e->getMessage(),
                    "\"{$filename}\" was refused, but not by the type check."
                );
            }
        }

        // And the honest case still lands, so the allowlist is a filter rather than a wall.
        $directory = $run->directory . '/media/99';

        File::ensureDirectoryExists($directory);
        File::put($directory . '/photo.png', base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        ));

        $written = $driver->write([
            '_source'      => 99,
            'content_hash' => str_repeat('b', 64),
            'filename'     => 'photo.png',
            'has_bytes'    => true,
        ], null);

        $this->assertSame('png', $written->format, 'An ordinary image was refused by the allowlist.');
    }

    /**
     * The credential block's algorithm is not negotiable.
     *
     * `openssl_decrypt()` ignores the authentication tag for any cipher that is not an AEAD mode, so
     * honouring a manifest's `cipher` turned "a tampered bundle fails loudly" into an
     * unauthenticated decrypt, one edited JSON value away.
     */
    #[Test]
    public function a_manifest_cannot_choose_the_cipher(): void
    {
        $this->actingAsSuperAdmin();

        $run   = app(RunStore::class)->create('export');
        $vault = app(CredentialVault::class);

        $description = $vault->seal($run->directory, ['payment' => ['key' => 'value']], 'a-long-passphrase');

        $path = $run->directory . '/' . CredentialVault::FILENAME;

        // The honest one still opens.
        $this->assertSame(
            ['payment' => ['key' => 'value']],
            $vault->open($path, $description, 'a-long-passphrase')
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/will not open|only .* is accepted/i');

        $vault->open($path, ['cipher' => 'aes-256-cbc'] + $description, 'a-long-passphrase');
    }

    /** A manifest cannot ask for an arbitrarily expensive key derivation either. */
    #[Test]
    public function a_manifest_cannot_choose_an_unbounded_kdf_cost(): void
    {
        $this->actingAsSuperAdmin();

        $run   = app(RunStore::class)->create('export');
        $vault = app(CredentialVault::class);

        $description = $vault->seal($run->directory, ['payment' => ['key' => 'value']], 'a-long-passphrase');

        $started = microtime(true);

        try {
            $vault->open(
                $run->directory . '/' . CredentialVault::FILENAME,
                ['iterations' => 2000000000] + $description,
                'a-long-passphrase'
            );
        } catch (RuntimeException) {
            // A clamped count derives a different key, so this fails to authenticate. That is the
            // correct outcome; what is being measured is that it failed *quickly*.
        }

        $this->assertLessThan(
            30,
            microtime(true) - $started,
            'A manifest asking for two billion PBKDF2 rounds was honoured, which holds the request '
            . 'until it times out.'
        );
    }
}
