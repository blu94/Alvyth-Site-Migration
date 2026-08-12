<?php

namespace Plugin\SiteMigration\Backend\Bundle;

use App\Repositories\Setting\Localization\LocalizationInterface;
use RuntimeException;

/**
 * What a bundle is, written by the export and checked by the import before anything is written.
 *
 * The install writing a bundle and the install reading it are different machines that may be
 * months apart in version. **Refusing an unreadable bundle at upload — before a single record
 * lands — is the whole value of this file.** The alternative failure is a column that does not
 * exist, surfacing half-way through a write, with the destination already part-migrated.
 *
 * ```jsonc
 * {
 *   "format": 1,
 *   "created_at": "2026-08-12T04:11:09Z",
 *   "source": { "url": "https://shop.example", "ovynt": "1.3.0", "locales": ["en", "ms"] },
 *   "contents": { "products": 412 },
 *   "include_media": false,
 *   "has_credentials": false
 * }
 * ```
 */
class Manifest
{
    /**
     * The bundle format this package writes and the oldest it reads.
     *
     * Bumped only for a change that an older reader would misread — a new *field* does not need
     * one, because an absent field takes its default. The content hash is the reason the field
     * exists in the record shape from stage 2 despite nothing reading it until stage 5:
     * retrofitting it once real bundles are in the wild would mean a format bump and a
     * compatibility branch.
     */
    public const FORMAT = 1;

    public const MIN_READABLE_FORMAT = 1;

    /** @param array<string,mixed> $data */
    public function __construct(private readonly array $data)
    {
    }

    /**
     * Describe this install and what is about to leave it.
     *
     * @param  array<string,int>  $contents  resource key => record count
     */
    public static function build(array $contents, bool $includeMedia = false, bool $hasCredentials = false): self
    {
        return new self([
            'format'     => self::FORMAT,
            'created_at' => now()->toIso8601String(),
            'source'     => [
                'url'     => rtrim((string) config('app.url'), '/'),
                'ovynt'   => (string) config('ovynt.version'),
                'locales' => self::locales(),
            ],
            'contents'        => $contents,
            'include_media'   => $includeMedia,
            'has_credentials' => $hasCredentials,
        ]);
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    public function format(): int
    {
        return (int) ($this->data['format'] ?? 0);
    }

    public function sourceUrl(): string
    {
        return rtrim((string) ($this->data['source']['url'] ?? ''), '/');
    }

    public function sourceVersion(): string
    {
        return (string) ($this->data['source']['ovynt'] ?? '');
    }

    /** @return array<int,string> */
    public function sourceLocales(): array
    {
        return array_values(array_filter(array_map('strval', (array) ($this->data['source']['locales'] ?? []))));
    }

    /** @return array<string,int> */
    public function contents(): array
    {
        return array_map('intval', (array) ($this->data['contents'] ?? []));
    }

    /** @return array<int,string> the resources this bundle actually carries */
    public function resources(): array
    {
        return array_keys(array_filter($this->contents(), static fn (int $count) => $count > 0));
    }

    public function includesMedia(): bool
    {
        return (bool) ($this->data['include_media'] ?? false);
    }

    public function hasCredentials(): bool
    {
        return (bool) ($this->data['has_credentials'] ?? false);
    }

    /**
     * Refuse a bundle this install cannot read, naming both versions.
     *
     * **A newer bundle is refused; an older one is accepted.** The asymmetry is not caution, it
     * is what the two cases actually mean. Fields absent from an older bundle take their column
     * defaults, which is a well-defined outcome. Fields *present* in a newer one may name columns
     * that do not exist here, and there is no way to know which until the write fails.
     *
     * @throws RuntimeException
     */
    public function assertReadable(): void
    {
        $format = $this->format();

        if ($format === 0) {
            throw new RuntimeException(
                'That file does not look like a Site Migration bundle — it has no manifest. '
                . 'Check you uploaded the zip the other site produced, rather than a backup or a theme.'
            );
        }

        if ($format < self::MIN_READABLE_FORMAT) {
            throw new RuntimeException(sprintf(
                'This bundle is in format %d, which this version can no longer read (it reads %d and newer).',
                $format,
                self::MIN_READABLE_FORMAT
            ));
        }

        if ($format > self::FORMAT) {
            throw new RuntimeException(sprintf(
                'This bundle is in format %d and this install understands up to format %d. '
                . 'Update Site Migration on this site, then import it again.',
                $format,
                self::FORMAT
            ));
        }

        $here   = (string) config('ovynt.version');
        $source = $this->sourceVersion();

        if ($source !== '' && version_compare($source, $here, '>')) {
            throw new RuntimeException(sprintf(
                'This bundle came from Ovynt %s and this site runs %s. Importing it could reference '
                . 'fields this version does not have. Update this site to %s or newer first.',
                $source,
                $here,
                $source
            ));
        }
    }

    /**
     * Locales in the bundle that this install has no home for.
     *
     * **A warning on the dry run, never a refusal.** Translatable columns are Spatie arrays, so
     * importing `{"en": …, "ms": …}` into an install configured for `en` alone stores a
     * translation nothing renders — recoverable, and mostly harmless. It is still worth saying,
     * because an operator who reads "this bundle has Malay content and this site has no Malay
     * locale" fixes it in a minute and would otherwise not notice for months.
     *
     * @return array<int,string>
     */
    public function unmappedLocales(): array
    {
        $here = self::locales();

        return $here === []
            ? []
            : array_values(array_diff($this->sourceLocales(), $here));
    }

    /**
     * This install's content locales.
     *
     * Read through `LocalizationInterface` rather than the `metas` row behind it, because that
     * setting is one of the eight `Cache::rememberForever` keys and the repository owns the
     * cache contract. Falls back to `config('app.available_locales')` exactly as
     * `LocaleController` does — the same two-step, so the bundle records what the admin shows.
     *
     * @return array<int,string>
     */
    private static function locales(): array
    {
        try {
            $settings = app(LocalizationInterface::class)->getSettings();
            $locales  = collect($settings['locales'] ?? [])
                ->pluck('code')
                ->filter()
                ->map('strval')
                ->values()
                ->all();

            if ($locales !== []) {
                return $locales;
            }
        } catch (\Throwable) {
            // An install whose localisation settings have never been saved is not a reason to
            // fail an export. The config fallback below is what the admin screen shows anyway.
        }

        return array_map('strval', array_keys((array) config('app.available_locales', [])))
            ?: [(string) config('app.locale')];
    }
}
