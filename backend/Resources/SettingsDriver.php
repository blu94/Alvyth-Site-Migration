<?php

namespace Plugin\SiteMigration\Backend\Resources;

use App\Models\Meta;
use App\Repositories\Setting\Application\ApplicationInterface;
use App\Repositories\Setting\Einvoice\EinvoiceSettingInterface;
use App\Repositories\Setting\EmailBranding\EmailBrandingInterface;
use App\Repositories\Setting\Localization\LocalizationInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * The settings groups an operator authored, and only those.
 *
 * **The exclusions are the design, not the leftovers.** Three groups are refused in code rather
 * than merely left off the default selection, because the difference matters: a group that is
 * simply unticked can be ticked, and a group refused here cannot travel however hard anyone tries.
 *
 * - **`PAYMENT_SETTING` and `MAIL_SETTING` never travel at all.** Not filtered field by field —
 *   excluded wholesale, because a gateway configuration is mostly credentials and a field-level
 *   allow-list is one forgotten key away from disclosure. The only route out for a secret is the
 *   encrypted block behind a passphrase.
 * - **`AI_ASSISTANT_SETTING` likewise**, for its API key.
 * - **`EINVOICE_SETTING` travels with its credential fields removed.** Unlike the other two, most
 *   of it is a *seller identity* — TIN, BRN, SST, MSIC, address — which is genuinely the same
 *   configuration on a rebuilt site and tedious to retype.
 *
 * **Written through the owning repository, never straight to `metas`.** Eight settings keys are
 * `Cache::rememberForever` and therefore never expire on their own, so a write that bypassed the
 * repository would leave the destination serving the old value **forever**, with nothing to
 * explain it. `updateSettings()` calls `Cache::forget` as part of its contract; that is the whole
 * reason this driver goes the long way round.
 */
class SettingsDriver extends BaseDriver
{
    /**
     * Meta type => the interface that owns reading and writing it.
     *
     * A group absent from this map cannot travel, which is the point: adding one is a deliberate
     * act that has to name a repository, and naming a repository is what guarantees the cache is
     * dropped on write.
     */
    private const GROUPS = [
        'APPLICATION_SETTING'  => ApplicationInterface::class,
        'LOCALIZATION_SETTING' => LocalizationInterface::class,
        'EMAIL_BRANDING'       => EmailBrandingInterface::class,
        'EINVOICE_SETTING'     => EinvoiceSettingInterface::class,
    ];

    /**
     * Groups that are never carried, under any selection.
     *
     * Listed explicitly rather than left out of {@see GROUPS}, so that the refusal is documented
     * where somebody adding a group will read it — and so the test that greps a bundle for secrets
     * has something to assert against.
     */
    public const NEVER = [
        'PAYMENT_SETTING',
        'MAIL_SETTING',
        'AI_ASSISTANT_SETTING',
    ];

    /**
     * Fields stripped from e-invoice settings before they leave.
     *
     * The seller identity travels; the keys that let a machine *submit as that seller* do not.
     */
    private const EINVOICE_CREDENTIALS = [
        'client_id',
        'client_secret',
        'api_key',
        'secret',
        'password',
        'token',
        'access_token',
        'refresh_token',
        'private_key',
        'certificate',
    ];

    public function key(): string
    {
        return 'settings';
    }

    public function label(): string
    {
        return 'Settings';
    }

    public function naturalKey(): string
    {
        return 'group';
    }

    public function permissionResource(): string
    {
        return 'settings';
    }

    /**
     * One row per carryable group.
     *
     * A `metas` query rather than a list of repository calls, so a group that has never been saved
     * on this install simply has no row and is silently absent — which is correct, and is what an
     * "export everything" run should do rather than writing four empty objects.
     */
    public function exportQuery(): Builder
    {
        return Meta::query()
            ->whereIn('type', array_keys(self::GROUPS))
            ->whereNotIn('type', self::NEVER)
            ->orderBy('id');
    }

    /**
     * @param  Meta  $record
     * @return array<string,mixed>
     */
    public function toRecord(Model $record): array
    {
        $group = (string) $record->type;

        // Read through the repository rather than off the row, so a group whose accessor decrypts
        // or defaults gives the same shape the admin screen shows.
        $values = app(self::GROUPS[$group])->getSettings();

        return [
            '_source' => $record->getKey(),
            'group'   => $group,
            'values'  => $this->strip($group, is_array($values) ? $values : []),
        ];
    }

    /** @param array<string,mixed> $record */
    public function locate(array $record): ?Model
    {
        $group = $this->group($record);

        return Meta::query()->where('type', $group)->first();
    }

    /**
     * @param  array<string,mixed>  $record
     * @param  Meta|null  $existing
     */
    public function write(array $record, ?Model $existing): Model
    {
        $group  = $this->group($record);
        $values = $this->strip($group, (array) ($record['values'] ?? []));

        if ($values === []) {
            throw new SkipRecord(sprintf('The %s settings in this bundle are empty.', strtolower($group)));
        }

        // The long way round, deliberately: `updateSettings()` writes the row **and** forgets the
        // `rememberForever` cache key. Writing `$meta->data` here instead would leave this site
        // serving the previous values until something else happened to clear the cache, which on a
        // file cache store is "never".
        app(self::GROUPS[$group])->updateSettings($values);

        $written = Meta::query()->where('type', $group)->first();

        if ($written === null) {
            throw new RuntimeException(sprintf('The %s settings could not be saved.', strtolower($group)));
        }

        return $written;
    }

    /**
     * Remove anything that must not leave, whatever the caller asked for.
     *
     * Applied on **both** the way out and the way in. On export it is what keeps the secret out of
     * the file; on import it stops a hand-edited bundle writing one *into* this install through a
     * path that looks like ordinary configuration.
     *
     * @param  array<string,mixed>  $values
     * @return array<string,mixed>
     */
    private function strip(string $group, array $values): array
    {
        if (in_array($group, self::NEVER, true)) {
            return [];
        }

        if ($group !== 'EINVOICE_SETTING') {
            return $values;
        }

        // Matched on the key *containing* a credential word rather than equalling one, because
        // these blobs grow prefixed variants — `myinvois_client_secret`, `sandbox_api_key` — and
        // an exact-match list would leak the next one somebody adds.
        return array_filter(
            $values,
            static function ($key) {
                $lower = strtolower((string) $key);

                foreach (self::EINVOICE_CREDENTIALS as $secret) {
                    if (str_contains($lower, $secret)) {
                        return false;
                    }
                }

                return true;
            },
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * @param  array<string,mixed>  $record
     *
     * @throws RuntimeException
     */
    private function group(array $record): string
    {
        $group = strtoupper(trim((string) ($record['group'] ?? '')));

        if ($group === '' || ! isset(self::GROUPS[$group])) {
            throw new SkipRecord(sprintf(
                '"%s" is not a settings group this version can carry.',
                $record['group'] ?? '(unnamed)'
            ));
        }

        return $group;
    }

    /**
     * A settings group is a singleton.
     *
     * Two `APPLICATION_SETTING` rows is not a thing that can exist, so a collision here means the
     * operator is replacing configuration rather than acquiring a second copy of it. Renaming the
     * group to dodge the clash would write a settings row no repository ever reads.
     */
    public function mergesOnCollision(): bool
    {
        return true;
    }

    /**
     * Application settings carry a logo and a favicon by asset id.
     *
     * **`data`, not `values`.** The rewrite pass walks the *model's* attributes, and the model here
     * is a `Meta` whose blob lives in `data` — `values` is this driver's own name for it in the
     * bundle and exists nowhere on the row. Naming the bundle field would have made the pass a
     * silent no-op for every settings group.
     */
    public function rewritableFields(): array
    {
        return ['data'];
    }
}
