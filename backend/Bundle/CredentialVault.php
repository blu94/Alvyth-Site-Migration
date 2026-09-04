<?php

namespace Plugin\SiteMigration\Backend\Bundle;

use App\Repositories\Setting\Ai\AiSettingInterface;
use App\Repositories\Setting\Mail\MailInterface;
use App\Repositories\Setting\Payment\PaymentInterface;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

/**
 * The only way a secret ever leaves an install: sealed under a passphrase the operator chose.
 *
 * **The problem a plain "include credentials" checkbox does not solve.** The three secrets are not
 * stored the same way. Payment credentials are **plaintext** JSON in `metas.data` — `PaymentRepository`
 * contains no `Crypt` at all — while the SMTP password and the AI key are `Crypt::encryptString`
 * and therefore bound to the *source's* `APP_KEY`. So a checkbox that simply copied stored values
 * would produce the worst possible split: the gateway keys transfer and work, while the mail
 * password arrives as undecryptable ciphertext that `MailRepository` degrades to `null` — a
 * configured-looking mail screen that silently cannot send. **The dangerous half succeeds and the
 * useful half breaks.**
 *
 * So each secret is read through its own repository accessor, which decrypts under the source's
 * key; the plaintext exists in memory only; and the whole block is re-sealed under a key derived
 * from the passphrase.
 *
 * **Primitives are chosen for the hosting, not for this machine.** The KDF is `hash_pbkdf2`
 * (SHA-256) and the cipher is `openssl_encrypt` with **AES-256-GCM** — both core PHP, and
 * `openssl_*` is already load-bearing in this codebase. Sodium would be the better toolkit and is
 * deliberately not used: it is *present* on this development container (checked, contrary to the
 * note in `LicenceService`) and routinely absent on the shared hosting this product targets, and a
 * bundle that could be written on one install and not opened on another is worse than a slightly
 * older primitive. The one place sodium is touched is wiping a key from memory, and that is
 * guarded — see {@see wipe()}.
 *
 * Authenticated encryption specifically, so a tampered bundle or a wrong passphrase fails loudly
 * rather than yielding plausible garbage the destination would then store.
 *
 * **The deliberate slowness of the KDF is the defence.** Whoever holds the file can attack it
 * offline at their leisure, so the iteration count is the only lever that matters — and for the
 * same reason no attempt cap is worth building.
 *
 * **The passphrase travels out of band.** Not in the same email as the bundle. That is the entire
 * security model and nobody infers it, so the screen says it.
 */
class CredentialVault
{
    public const FILENAME = 'credentials.enc';

    private const KDF        = 'sha256';
    private const ITERATIONS = 600000;
    private const CIPHER     = 'aes-256-gcm';

    /**
     * The ciphers a bundle may ask for.
     *
     * One entry, and that is the point: an allowlist of the AEAD mode this package writes. A
     * manifest naming anything else is refused rather than honoured.
     *
     * @var array<int,string>
     */
    private const READABLE_CIPHERS = ['aes-256-gcm'];

    /**
     * The range of iteration counts a bundle may ask for.
     *
     * `max()` alone clamped the floor and left the ceiling open, so a manifest could ask for two
     * billion rounds and hold the request until it timed out - a denial of service written in
     * JSON. The ceiling is generous enough that a future bundle hardening its KDF still opens
     * here, and small enough that the worst case is seconds rather than hours.
     */
    private const MIN_ITERATIONS = 100000;

    private const MAX_ITERATIONS = 2000000;

    private const KEY_BYTES  = 32;
    private const SALT_BYTES = 16;
    private const IV_BYTES   = 12;
    private const TAG_BYTES  = 16;

    /** Minimum passphrase length. Short enough not to be theatre, long enough to matter offline. */
    public const MIN_PASSPHRASE = 12;

    /**
     * Read every carryable secret through its owning repository.
     *
     * @return array<string,array<string,mixed>>
     */
    public function collect(): array
    {
        $groups = [];

        foreach ($this->readers() as $group => $reader) {
            try {
                $values = $reader();

                if (is_array($values) && $values !== []) {
                    $groups[$group] = $values;
                }
            } catch (Throwable) {
                // A group that has never been configured is not an error. Skipping it silently is
                // right: the manifest records which groups the block actually holds.
            }
        }

        return $groups;
    }

    /**
     * Seal the block into the bundle.
     *
     * @param  array<string,array<string,mixed>>  $groups
     * @return array<string,mixed> the manifest description — salt, iv, iterations, groups
     */
    public function seal(string $directory, array $groups, string $passphrase): array
    {
        $this->assertUsable($passphrase);

        $salt = random_bytes(self::SALT_BYTES);
        $iv   = random_bytes(self::IV_BYTES);
        $key  = $this->derive($passphrase, $salt);

        $plaintext = json_encode($groups, JSON_UNESCAPED_SLASHES);

        $sealed = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_BYTES);

        $this->wipe($key);

        if ($sealed === false) {
            throw new RuntimeException('The credentials could not be encrypted.');
        }

        File::ensureDirectoryExists($directory);

        // The tag rides with the ciphertext rather than in the manifest: it authenticates *these*
        // bytes, so separating the two invites a bundle whose tag and body came from different
        // exports.
        file_put_contents($directory . '/' . self::FILENAME, $iv . $tag . $sealed);

        return [
            'kdf'        => 'pbkdf2-' . self::KDF,
            'iterations' => self::ITERATIONS,
            'salt'       => base64_encode($salt),
            'cipher'     => self::CIPHER,
            'groups'     => array_keys($groups),
        ];
    }

    /**
     * Open the block, or fail closed.
     *
     * @param  array<string,mixed>  $description  the manifest's `credentials` object
     * @return array<string,array<string,mixed>>
     *
     * @throws RuntimeException on a wrong passphrase or a tampered block
     */
    public function open(string $path, array $description, string $passphrase): array
    {
        if (! is_file($path)) {
            throw new RuntimeException('This bundle says it carries credentials, but the encrypted block is missing.');
        }

        $blob = (string) file_get_contents($path);

        if (strlen($blob) <= self::IV_BYTES + self::TAG_BYTES) {
            throw new RuntimeException('The encrypted credentials block is damaged.');
        }

        $salt = base64_decode((string) ($description['salt'] ?? ''), true);

        if ($salt === false || $salt === '') {
            throw new RuntimeException('The bundle does not say how its credentials were encrypted.');
        }

        $iv         = substr($blob, 0, self::IV_BYTES);
        $tag        = substr($blob, self::IV_BYTES, self::TAG_BYTES);
        $ciphertext = substr($blob, self::IV_BYTES + self::TAG_BYTES);

        // **The cipher is policy, not data.** It arrived from `manifest.json` - a plaintext file
        // inside the same zip as the block it describes - and `openssl_decrypt()` ignores the
        // authentication tag entirely for any cipher that is not an AEAD mode. So naming
        // `aes-256-cbc` in a manifest turned the guarantee this class is built on ("a tampered
        // bundle or a wrong passphrase fails loudly") into an unauthenticated decrypt, one edited
        // JSON value away. The salt still comes from the manifest, because it genuinely varies per
        // bundle; the algorithm does not vary and is no longer negotiable.
        $cipher = (string) ($description['cipher'] ?? self::CIPHER);

        if (! in_array($cipher, self::READABLE_CIPHERS, true)) {
            throw new RuntimeException(sprintf(
                'This bundle says its credentials were encrypted with %s, which this version will '
                . 'not open. Only %s is accepted, because it is the only mode that authenticates '
                . 'what it decrypts.',
                $cipher === '' ? 'nothing' : $cipher,
                self::CIPHER
            ));
        }

        $key = $this->derive(
            $passphrase,
            $salt,
            (int) ($description['iterations'] ?? self::ITERATIONS)
        );

        $plaintext = openssl_decrypt(
            $ciphertext,
            $cipher,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        $this->wipe($key);

        // GCM authentication failing is indistinguishable from a wrong passphrase, and that is the
        // point: an attacker learns nothing about which of the two happened, and the operator is
        // told the only thing that would help them.
        if ($plaintext === false) {
            throw new RuntimeException(
                'That passphrase does not open this bundle\'s credentials — or the block was '
                . 'altered in transit. Nothing has been changed. Check the passphrase the other '
                . 'site gave you, which should have reached you separately from the file itself.'
            );
        }

        $groups = json_decode($plaintext, true);

        return is_array($groups) ? $groups : [];
    }

    /**
     * Write the opened secrets through the *destination's* own repositories.
     *
     * Which re-encrypts mail and AI under **this** install's `APP_KEY` and stores payment as
     * plaintext, exactly as core stores them. Never written to `metas` directly — apart from the
     * cache contract, doing so would store the source's ciphertext, which this install cannot read.
     *
     * @param  array<string,array<string,mixed>>  $groups
     * @return array<int,string> the groups actually applied
     */
    public function apply(array $groups): array
    {
        $applied = [];

        foreach ($this->writers() as $group => $writer) {
            if (! isset($groups[$group]) || ! is_array($groups[$group]) || $groups[$group] === []) {
                continue;
            }

            try {
                $writer($groups[$group]);
                $applied[] = $group;
            } catch (Throwable) {
                // One group failing must not cost the others. The run records which were applied,
                // and a group absent from that list is the report that it was not.
            }
        }

        return $applied;
    }

    /**
     * Wipe a derived key from memory where the platform can.
     *
     * **Guarded, not assumed.** `sodium_memzero` is the only way to actually clear a PHP string in
     * place, and sodium is present on this development container — but this package ships to
     * shared hosting, where it frequently is not, and an unguarded call would fatal at the exact
     * moment an operator is exporting their gateway keys. Where it is missing the key simply falls
     * out of scope, which is what every other PHP secret does.
     *
     * Not a strong guarantee either way; worth doing where it is free.
     */
    private function wipe(string &$key): void
    {
        if (function_exists('sodium_memzero')) {
            sodium_memzero($key);
        }
    }

    /**
     * Derive the key.
     *
     * `true` for raw output — the key is bytes, and a hex string would give 256 bits of entropy in
     * a 64-byte key, which AES would then silently truncate.
     */
    private function derive(string $passphrase, string $salt, ?int $iterations = null): string
    {
        return hash_pbkdf2(
            self::KDF,
            $passphrase,
            $salt,
            // Clamped at both ends. See MAX_ITERATIONS for why the ceiling is not optional.
            min(self::MAX_ITERATIONS, max(self::MIN_ITERATIONS, $iterations ?? self::ITERATIONS)),
            self::KEY_BYTES,
            true
        );
    }

    /** @throws RuntimeException */
    private function assertUsable(string $passphrase): void
    {
        if (strlen($passphrase) < self::MIN_PASSPHRASE) {
            throw new RuntimeException(sprintf(
                'The passphrase must be at least %d characters. It is the only thing protecting '
                . 'your gateway keys once the file leaves this server.',
                self::MIN_PASSPHRASE
            ));
        }
    }

    /**
     * Each secret group, read through the accessor that decrypts it.
     *
     * @return array<string,callable(): array<string,mixed>>
     */
    private function readers(): array
    {
        return [
            // **Not `getSettings()` for payment either, since core encrypted it (audit S3).**
            // That accessor now masks gateway secrets the same way mail always masked its
            // password, so collecting it would seal `••••••••xx` strings under the operator's
            // passphrase — and the destination's `updateSettings()` treats a mask as "keep
            // what is stored", so the import would silently write nothing. The runtime
            // accessor returns the decrypted values; the `method_exists` guard keeps this
            // package installable on cores older than that change, where `getSettings()`
            // still returns plaintext.
            'payment' => static function () {
                $repo = app(PaymentInterface::class);

                return method_exists($repo, 'getRuntimeSettings')
                    ? $repo->getRuntimeSettings()
                    : $repo->getSettings();
            },

            // **Not `getSettings()` for mail.** That accessor deliberately returns a *mask* rather
            // than the password — the form treats blank as "keep the existing one" — so collecting
            // it would seal the string `••••••••` under the operator's passphrase and hand the
            // destination a mail configuration that cannot send. The raw row is read and decrypted
            // instead, which is the one place in this package that is allowed to.
            // **The mail password, through the repository rather than around it.** This used to
            // read the `metas` row directly and decrypt the password itself, because `getSettings()`
            // returns a mask and sealing `••••••••` would hand the destination a mail configuration
            // that cannot send. Core's settings base now offers `getRuntimeSettings()` — the same
            // seam the payment reader above uses — so the bypass is gone and this package no longer
            // depends on how core stores a secret, only on it being willing to reveal one.
            //
            // The guard keeps the package installable on the cores its manifest still allows
            // (`>=1.4.0`), where that method does not exist and the raw read is the only way.
            'mail' => function () {
                $repo = app(MailInterface::class);

                return method_exists($repo, 'getRuntimeSettings')
                    ? $repo->getRuntimeSettings()
                    : $this->rawMail();
            },

            // **`AiSettingInterface`, not `AiRepository` — and this never worked.**
            // `App\Repositories\Ai\AiRepository` is the *per-user assistant* repository: its API is
            // `getForUser()` / `updateForUser()`, and it has no `getSettings()` at all. Calling one
            // raised an `Error`, which {@see collect()} and {@see apply()} both catch and treat as
            // "this group was never configured" — so the AI key was silently absent from every
            // bundle ever written, and silently never applied by any import. The manifest's `groups`
            // list made that look deliberate.
            //
            // The store's own settings live behind `AiSettingInterface` (meta type `AI_SETTING`).
            // `getRuntimeSettings()` is preferred over the interface's `getCredentials()` because the
            // latter returns only provider, model and key — writing that back through
            // `updateSettings()` would drop `enabled` and anything else the row holds.
            'ai' => static function () {
                $repo = app(AiSettingInterface::class);

                return method_exists($repo, 'getRuntimeSettings')
                    ? $repo->getRuntimeSettings()
                    : $repo->getSettings();
            },
        ];
    }

    /**
     * @return array<string,callable(array<string,mixed>): mixed>
     */
    private function writers(): array
    {
        return [
            'payment' => static fn (array $v) => app(PaymentInterface::class)->updateSettings($v),
            'mail'    => static fn (array $v) => app(MailInterface::class)->updateSettings($v),
            'ai'      => static fn (array $v) => app(AiSettingInterface::class)->updateSettings($v),
        ];
    }

    /**
     * Mail settings with the real password.
     *
     * Reached through the repository's own masking accessor for everything else, then the password
     * decrypted from the row — because `MailRepository::updateSettings()` on the destination
     * expects a plaintext password and encrypts it under that install's key.
     *
     * @return array<string,mixed>
     */
    private function rawMail(): array
    {
        $settings = app(MailInterface::class)->getSettings();

        if (! is_array($settings) || $settings === []) {
            return [];
        }

        $meta = \App\Models\Meta::query()->where('type', 'MAIL_SETTING')->first();
        $raw  = (array) ($meta?->data ?? []);

        $password = $raw['password'] ?? null;

        if (is_string($password) && $password !== '') {
            try {
                $settings['password'] = \Illuminate\Support\Facades\Crypt::decryptString($password);
            } catch (Throwable) {
                // Ciphertext this install cannot read — an APP_KEY that changed under it. Better
                // to carry the rest of the mail configuration than to fail the whole export for a
                // value that is already unusable here.
                unset($settings['password']);
            }
        } else {
            unset($settings['password']);
        }

        return $settings;
    }
}
