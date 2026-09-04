<?php

namespace Plugin\SiteMigration\Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Plugin\SiteMigration\Backend\Bundle\CredentialVault;
use Plugin\SiteMigration\Backend\Runs\RunStore;
use RuntimeException;

require_once __DIR__ . '/autoload.php';

/**
 * Gate 5 — the right passphrase reproduces every secret; a wrong one skips them, reports it, and
 * leaves the destination's own credentials untouched rather than nulled.
 *
 * The failure this guards is specific and quiet: authenticated encryption is the difference
 * between a wrong passphrase *failing* and a wrong passphrase yielding plausible garbage that the
 * destination then writes into its payment settings.
 */
class CredentialVaultTest extends TestCase
{
    use DatabaseTransactions;

    private const PASSPHRASE = 'correct horse battery staple';

    #[Test]
    public function the_right_passphrase_reproduces_every_secret(): void
    {
        $vault  = new CredentialVault();
        $groups = [
            'payment' => ['stripe' => ['secret_key' => 'sk_live_EXAMPLE']],
            'mail'    => ['host' => 'smtp.example.test', 'password' => 'hunter2-hunter2'],
        ];

        $directory = $this->tempDir();

        $description = $vault->seal($directory, $groups, self::PASSPHRASE);

        $this->assertSame('aes-256-gcm', $description['cipher']);
        $this->assertSame(['payment', 'mail'], $description['groups']);

        $opened = $vault->open(
            $directory . '/' . CredentialVault::FILENAME,
            $description,
            self::PASSPHRASE
        );

        $this->assertSame($groups, $opened, 'The secrets did not survive the round trip byte for byte.');
    }

    /**
     * A wrong passphrase fails GCM authentication, and the message says nothing was changed.
     *
     * Failing is the *point*: without an authenticated cipher a wrong key would decrypt to
     * something, and that something would be written into the destination's payment settings.
     */
    #[Test]
    public function a_wrong_passphrase_fails_closed(): void
    {
        $vault     = new CredentialVault();
        $directory = $this->tempDir();

        $description = $vault->seal($directory, ['payment' => ['k' => 'v']], self::PASSPHRASE);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/nothing has been changed/i');

        $vault->open($directory . '/' . CredentialVault::FILENAME, $description, 'the wrong passphrase entirely');
    }

    /** A tampered block is refused for the same reason, and is indistinguishable from a bad key. */
    #[Test]
    public function a_tampered_block_is_refused(): void
    {
        $vault     = new CredentialVault();
        $directory = $this->tempDir();

        $description = $vault->seal($directory, ['payment' => ['k' => 'v']], self::PASSPHRASE);

        $path = $directory . '/' . CredentialVault::FILENAME;
        $blob = (string) file_get_contents($path);

        // Flip one bit deep inside the ciphertext, past the iv and tag.
        $blob[40] = $blob[40] === 'A' ? 'B' : 'A';

        file_put_contents($path, $blob);

        $this->expectException(RuntimeException::class);

        $vault->open($path, $description, self::PASSPHRASE);
    }

    /**
     * The sealed file contains none of the plaintext, which is the only claim that matters about it.
     */
    #[Test]
    public function the_sealed_file_holds_no_plaintext(): void
    {
        $vault     = new CredentialVault();
        $directory = $this->tempDir();

        $vault->seal($directory, ['payment' => ['secret_key' => 'sk_live_UNMISTAKABLE']], self::PASSPHRASE);

        $blob = (string) file_get_contents($directory . '/' . CredentialVault::FILENAME);

        $this->assertStringNotContainsString('sk_live_UNMISTAKABLE', $blob);
        $this->assertStringNotContainsString('secret_key', $blob);
        $this->assertStringNotContainsString(self::PASSPHRASE, $blob);
    }

    /**
     * The passphrase is not stored in the manifest description either.
     *
     * That object travels *in the bundle*, so a passphrase in it would defeat the entire scheme —
     * the lock and its key in one envelope.
     */
    #[Test]
    public function the_description_never_carries_the_passphrase(): void
    {
        $description = (new CredentialVault())->seal($this->tempDir(), ['payment' => ['k' => 'v']], self::PASSPHRASE);

        $this->assertStringNotContainsString(
            self::PASSPHRASE,
            json_encode($description),
            'The manifest description carries the passphrase, which would defeat the encryption entirely.'
        );

        $this->assertArrayHasKey('salt', $description);
        $this->assertArrayHasKey('iterations', $description);
    }

    /**
     * A short passphrase is refused before anything is sealed under it.
     *
     * Whoever holds the file can attack it offline for as long as they like, so length is the only
     * lever that matters and an attempt cap would be theatre.
     */
    #[Test]
    public function a_short_passphrase_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/at least/i');

        (new CredentialVault())->seal($this->tempDir(), ['payment' => ['k' => 'v']], 'short');
    }

    /** The iteration count is not quietly weakened below what a bundle's description claims. */
    #[Test]
    public function the_kdf_is_expensive_on_purpose(): void
    {
        $description = (new CredentialVault())->seal($this->tempDir(), ['payment' => ['k' => 'v']], self::PASSPHRASE);

        $this->assertGreaterThanOrEqual(
            600000,
            (int) $description['iterations'],
            'The KDF cost is the only defence against an offline attack on a stolen bundle.'
        );
    }

    private function tempDir(): string
    {
        $path = app(RunStore::class)->root() . '/_scratch/' . bin2hex(random_bytes(4));

        File::ensureDirectoryExists($path);

        return $path;
    }

    /**
     * A configured secret group is never silently absent from the block.
     *
     * **This is the test that was missing, and its absence hid a feature that never worked.**
     * `collect()` treats a group it cannot read as "never configured" and skips it, which is right
     * for a shop with no gateway and fatal as a safety net: it swallowed a genuine `Error`. The AI
     * reader called `getSettings()` on `App\Repositories\Ai\AiRepository` — the *per-user assistant*
     * repository, which has no such method — so the AI key was absent from every bundle ever
     * written, and no test noticed, because absence is indistinguishable from "not configured"
     * unless you configure it first.
     *
     * So this configures AI, then asserts it arrives with a value a destination could use.
     */
    #[Test]
    public function a_configured_group_is_never_silently_absent(): void
    {
        $this->actingAsSuperAdmin();

        app(\App\Repositories\Setting\Ai\AiSettingInterface::class)->updateSettings([
            'enabled'  => true,
            'provider' => 'anthropic',
            'model'    => 'claude-sonnet-4-5',
            'api_key'  => 'sk-ant-not-a-real-key',
        ]);

        $groups = app(CredentialVault::class)->collect();

        $this->assertArrayHasKey(
            'ai',
            $groups,
            'The AI group is missing from collect() on a site that has an AI key configured. '
            . 'collect() swallows the reason, so check the reader resolves a repository that '
            . 'actually has getSettings()/getRuntimeSettings().'
        );

        $this->assertSame(
            'sk-ant-not-a-real-key',
            $groups['ai']['api_key'] ?? null,
            'The AI key was collected masked or absent, so a bundle would carry something the '
            . 'destination cannot use.'
        );
    }
}
