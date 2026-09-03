<?php

namespace Plugin\SiteMigration\Backend\Resources;

use App\Models\Plugin;
use App\Services\Plugin\PluginInstaller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;

/**
 * Installed plugins — the row and the package directory.
 *
 * **Two things about this are not obvious and both are stated on screen rather than buried here.**
 *
 * **A paid plugin's licence is bound to a domain.** The files arrive and the row arrives, but the
 * key was issued for the site being left, so the destination will refuse it and the plugin lands
 * `blocked` until somebody pastes a key for the new domain. That is not a defect in this package or
 * in the licensing — it is what domain binding *means* — and the alternative, carrying the key and
 * hoping, would produce a plugin that looks licensed and is not.
 *
 * **An imported plugin never arrives enabled.** Enabling runs its migrations and registers its
 * listeners, which is third-party code executing with full application privileges as a side effect
 * of a data migration. The operator enables it themselves, having seen what arrived.
 *
 * **This package excludes itself.** A migration bundle that reinstalls the migrator over the
 * running one, mid-run, is a way to lose the run.
 */
class PluginDriver extends BaseDriver
{
    public function key(): string
    {
        return 'plugins';
    }

    public function label(): string
    {
        return 'Plugins';
    }

    public function permissionResource(): string
    {
        return 'plugins';
    }

    public function naturalKey(): string
    {
        return 'slug';
    }

    /**
     * Every installed plugin but this one.
     *
     * Excluding itself is not modesty. The import writes the package directory and the registry
     * row; doing that to the plugin currently executing the import would swap its own code out
     * from under it, part-way through a run whose state it is still holding.
     */
    public function exportQuery(): Builder
    {
        return Plugin::query()->where('slug', '!=', 'site-migration')->orderBy('id');
    }

    /**
     * @param  Plugin  $record
     * @return array<string,mixed>
     */
    public function toRecord(Model $record): array
    {
        $slug = (string) $record->slug;

        return [
            '_source' => $record->getKey(),
            'slug'    => $slug,
            'name'    => $record->name,
            'version' => $record->version,
            'author'  => $record->author,
            'reach'   => $record->reach,

            // Which kind of licence it needs — carried so the destination can say "this one needs
            // a key" rather than leaving the operator to discover it. **The key itself is not
            // carried**: it is issued for the source's domain and would not validate here, so
            // sending it would be disclosure with no upside.
            'licence_type' => $record->licence_type,

            'has_files' => $this->copyIntoBundle($slug),
        ];
    }

    /** @param array<string,mixed> $record */
    public function locate(array $record): ?Model
    {
        $slug = $this->packageSlug($record, 'plugin');

        return Plugin::query()->where('slug', $slug)->first();
    }

    /**
     * @param  array<string,mixed>  $record
     * @param  Plugin|null  $existing
     */
    public function write(array $record, ?Model $existing): Model
    {
        $slug = $this->packageSlug($record, 'plugin');

        if ($slug === 'site-migration') {
            throw new SkipRecord('Site Migration does not import itself over the copy that is running.');
        }

        // **Never downgrade.** An operator who upgraded a plugin here keeps their version — the
        // same rule core applies when a theme bundles plugins, and for the same reason: a bundle
        // is a snapshot of somewhere else, not an authority on what this site should be running.
        if ($existing !== null
            && $existing->version
            && version_compare((string) ($record['version'] ?? '0'), (string) $existing->version, '<=')) {
            throw new SkipRecord(sprintf(
                'This site already has %s %s, which is the same or newer than the %s in the bundle.',
                $existing->name ?: $slug,
                $existing->version,
                $record['version'] ?? '?'
            ));
        }

        $source = $this->containedPath($this->bundle?->path . '/plugins', $slug, 'plugin');

        if ($this->bundle === null || ! is_dir($source)) {
            // A row without its files is a registry entry for code that is not there — the exact
            // objection that kept plugins out of a bundle in the first place. Refused rather than
            // written, and named, so the operator installs it themselves rather than finding a
            // dead entry on the Plugins screen.
            throw new SkipRecord(sprintf(
                'The bundle carries a record for the "%s" plugin but none of its files, so there '
                . 'is nothing to install. Install it on this site the ordinary way.',
                $slug
            ));
        }

        // **Installed by core, not copied by this package.** `installFromDirectory()` is the seam
        // core factored precisely so a second caller could reuse it, and it does four things a
        // directory copy does not: it validates the manifest, applies the install-time signature
        // policy, refuses a package that collides with a core module type or another plugin's, and
        // keeps a backup it can restore if any of that fails part-way. Writing the tree by hand
        // meant a bundle could place arbitrary PHP under `storage/app/plugins` with none of those
        // checks — and `upsertRow()` inside it is also what guarantees the row arrives `disabled`
        // and that a reinstall does not re-enable something this operator switched off.
        $result = app(PluginInstaller::class)->installFromDirectory($source);

        foreach ((array) ($result['warnings'] ?? []) as $warning) {
            $this->note((string) $warning);
        }

        $plugin = $result['plugin'];

        $this->note(sprintf(
            'The "%s" plugin arrived disabled, as every installed plugin does. Enabling it runs its '
            . 'migrations and its code with full application privileges, so that is a decision for '
            . 'you rather than for a bundle.%s',
            $slug,
            ($plugin->licence_type ?? 'free') === 'paid'
                ? ' It is a paid plugin, and its licence was issued for the site it came from, so it '
                  . 'needs re-licensing on this domain.'
                : ''
        ));

        return $plugin;
    }

    /**
     * Merging a plugin replaces installed code.
     *
     * The strongest case for asking first: what is overwritten is a package the operator installed
     * and licensed on this domain, and its files execute with full application privileges once
     * enabled. Consent for that is the overwrite dialog, not a default.
     */
    public function mergeReplacesLocalWork(): bool
    {
        return true;
    }

    /**
     * A plugin's slug is its identity, its directory name **and** its PHP namespace.
     *
     * Renaming it to dodge a collision would produce a registry row whose namespace resolves to
     * nothing. Two installs with the same slug have the same plugin, so this merges.
     */
    public function mergesOnCollision(): bool
    {
        return true;
    }

    /** @param array<string,mixed> $record */
    public function renameForCollision(array $record): array
    {
        return $record;
    }

    /** @return bool whether there were files to copy */
    private function copyIntoBundle(string $slug): bool
    {
        if ($this->bundle === null || $slug === '') {
            return false;
        }

        $source = storage_path('app/plugins/' . $slug);

        if (! is_dir($source)) {
            return false;
        }

        $target = $this->bundle->path . '/plugins/' . $slug;

        File::ensureDirectoryExists(dirname($target));

        return File::copyDirectory($source, $target);
    }

    /** Status and licence state belong to this install, not to the bundle. */
    public function volatileFields(): array
    {
        return array_merge(parent::volatileFields(), ['status', 'has_files']);
    }
}
