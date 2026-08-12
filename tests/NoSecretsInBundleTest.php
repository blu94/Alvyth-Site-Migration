<?php

namespace Plugin\SiteMigration\Tests;

use App\Models\Product;
use App\Repositories\Setting\Mail\MailInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Plugin\SiteMigration\Backend\Resources\DriverRegistry;
use Plugin\SiteMigration\Backend\Runs\Run;
use Plugin\SiteMigration\Backend\Runs\RunStore;
use Plugin\SiteMigration\Backend\Services\Exporter;
use ZipArchive;

require_once __DIR__ . '/autoload.php';

/**
 * Gate 4 — no secret appears anywhere in a bundle.
 *
 * **This is configuration, not inspection**, and the distinction is the entire point. Reading the
 * export code and concluding "it does not touch mail settings" is true today and says nothing
 * about the day somebody adds a settings driver. This greps the produced bytes, so it fails
 * loudly the first time a secret rides along — which is the only moment anybody could still
 * cheaply fix it.
 *
 * It is deliberately written to survive the drivers that do not exist yet: it exports **every**
 * resource the registry offers rather than naming products, so a driver added in a later stage is
 * covered by this test the moment it is registered, without anyone remembering to come back here.
 */
class NoSecretsInBundleTest extends TestCase
{
    use DatabaseTransactions;

    /** Sentinels distinctive enough that a substring match cannot be a coincidence. */
    private const SMTP_PASSWORD = 'zzz-smtp-secret-b4a91c7e-zzz';

    #[Test]
    public function a_bundle_contains_no_configured_secret(): void
    {
        $this->actingAsSuperAdmin();

        // A real secret, written the way core writes it — through the repository, so it is
        // encrypted under this install's APP_KEY exactly as production would store it.
        app(MailInterface::class)->updateSettings([
            'host'     => 'smtp.example.test',
            'username' => 'postmaster@example.test',
            'password' => self::SMTP_PASSWORD,
        ]);

        Product::factory()->create([
            'sku'            => 'SEC-' . bin2hex(random_bytes(3)),
            'title'          => ['en' => 'Ordinary product'],
            'productable_id' => null,
        ]);

        $bundle = $this->exportEverything();

        foreach ($this->members($bundle) as $name => $contents) {
            $this->assertStringNotContainsString(
                self::SMTP_PASSWORD,
                $contents,
                "The SMTP password appears in bundle member '{$name}'. A bundle is carried between "
                . 'organisations; a secret in one is a secret disclosed.'
            );
        }
    }

    /**
     * **With credentials switched on, the same grep must still find nothing.**
     *
     * This is the half of the gate that is easy to lose. The values may exist only inside
     * `credentials.enc`, sealed under the operator's passphrase — never in `data/settings.json`,
     * never in the manifest, and never as plaintext anywhere in the archive. A bundle that carried
     * both the encrypted block *and* a readable copy would be strictly worse than one that carried
     * neither, because the operator would believe the encryption was protecting them.
     */
    #[Test]
    public function opting_into_credentials_still_puts_no_plaintext_in_the_bundle(): void
    {
        $this->actingAsSuperAdmin();

        app(MailInterface::class)->updateSettings([
            'host'     => 'smtp.example.test',
            'username' => 'postmaster@example.test',
            'password' => self::SMTP_PASSWORD,
        ]);

        Product::factory()->create([
            'sku'            => 'SEC-' . bin2hex(random_bytes(3)),
            'productable_id' => null,
        ]);

        $passphrase = 'a passphrase long enough to pass';

        $bundle = $this->exportEverything(credentials: $passphrase);

        $members = $this->members($bundle);

        $this->assertArrayHasKey(
            'credentials.enc',
            $members,
            'Credentials were requested but no encrypted block was written, so this proved nothing.'
        );

        foreach ($members as $name => $contents) {
            $this->assertStringNotContainsString(self::SMTP_PASSWORD, $contents, "Plaintext secret in '{$name}'.");
            $this->assertStringNotContainsString($passphrase, $contents, "The passphrase itself is in '{$name}'.");
        }
    }

    /**
     * The manifest names no secret either.
     *
     * Called out separately because the manifest is the one member read *before* the operator
     * commits to anything, and it is the member most likely to grow a field describing
     * configuration.
     */
    #[Test]
    public function the_manifest_carries_no_credential_material(): void
    {
        $this->actingAsSuperAdmin();

        Product::factory()->create([
            'sku'            => 'SEC-' . bin2hex(random_bytes(3)),
            'productable_id' => null,
        ]);

        $manifest = $this->members($this->exportEverything())['manifest.json'] ?? '';

        foreach (['password', 'secret', 'api_key', 'private_key', 'passphrase'] as $word) {
            $this->assertStringNotContainsString(
                $word,
                strtolower($manifest),
                "The manifest mentions '{$word}'. Even a key name is a hint a bundle should not carry."
            );
        }
    }

    /**
     * Nothing under `storage/app/private/keys` is reachable from a bundle.
     *
     * The application's signing key lives there. A release script nearly shipped a developer's
     * copy to every install once; a migration bundle is the same mistake with a different courier.
     */
    #[Test]
    public function no_bundle_member_comes_from_the_private_key_directory(): void
    {
        $this->actingAsSuperAdmin();

        Product::factory()->create([
            'sku'            => 'SEC-' . bin2hex(random_bytes(3)),
            'productable_id' => null,
        ]);

        foreach (array_keys($this->members($this->exportEverything())) as $name) {
            $this->assertStringNotContainsString('keys/', $name);
            $this->assertStringNotContainsString('.pem', $name);
        }
    }

    // ---------------------------------------------------------------- helpers

    /** Export every resource the registry knows about, so future drivers are covered too. */
    private function exportEverything(?string $credentials = null): string
    {
        $registry = app(DriverRegistry::class);

        $run = app(RunStore::class)->create(Run::DIRECTION_EXPORT, [
            'modules'             => $registry->contentKeys(),
            'records'             => $registry->recordKeys(),
            'include_credentials' => $credentials !== null,
        ]);

        // The passphrase is transient by design, so it has to be handed to the run on every press
        // exactly as the screen does.
        $this->pressUntilDone(function () use ($run, $credentials) {
            $fresh = app(RunStore::class)->find($run->id);

            if ($credentials !== null) {
                $fresh->withPassphrase($credentials);
            }

            return app(Exporter::class)->step($fresh);
        });

        $bundle = app(RunStore::class)->find($run->id)->bundlePath();

        $this->assertNotNull($bundle, 'The export did not seal a bundle, so this test proved nothing.');

        return $bundle;
    }

    /**
     * Every member of the bundle as raw bytes.
     *
     * Read exhaustively rather than by name: a test that checked only the members it expected
     * would pass over exactly the surprise member this is meant to catch.
     *
     * @return array<string,string>
     */
    private function members(string $bundle): array
    {
        $zip = new ZipArchive();
        $zip->open($bundle);

        $members = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);

            $members[$name] = (string) $zip->getFromIndex($i);
        }

        $zip->close();

        $this->assertNotEmpty($members, 'The bundle was empty.');

        return $members;
    }
}
