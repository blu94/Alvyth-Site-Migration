<?php

namespace Plugin\SiteMigration\Backend\Resources;

use App\Models\Plugin;
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
        $slug = $this->requireKey($record, 'slug', 'plugin');

        return Plugin::query()->where('slug', $slug)->first();
    }

    /**
     * @param  array<string,mixed>  $record
     * @param  Plugin|null  $existing
     */
    public function write(array $record, ?Model $existing): Model
    {
        $slug = $this->requireKey($record, 'slug', 'plugin');

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

        $this->restoreFiles($slug);

        $plugin = $existing ?? new Plugin();

        $plugin->fill([
            'slug'         => $slug,
            'name'         => $record['name'] ?? $slug,
            'version'      => $record['version'] ?? null,
            'author'       => $record['author'] ?? null,
            'reach'        => $record['reach'] ?? null,
            'licence_type' => $record['licence_type'] ?? 'free',
        ]);

        // **Arrives disabled, always.** Enabling runs a third party's migrations and registers its
        // listeners with full application privileges. That is a decision for whoever runs this
        // site, made deliberately, not a side effect of importing data. A plugin already enabled
        // here keeps its state — a bundle must not switch off something this site is using.
        $plugin->status = $existing?->status ?? 'disabled';

        $plugin->save();

        return $plugin;
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

    private function restoreFiles(string $slug): void
    {
        if ($this->bundle === null) {
            return;
        }

        $source = $this->bundle->path . '/plugins/' . $slug;

        if (! is_dir($source)) {
            return;
        }

        $target = storage_path('app/plugins/' . $slug);

        File::ensureDirectoryExists(dirname($target));
        File::copyDirectory($source, $target);
    }

    /** Status and licence state belong to this install, not to the bundle. */
    public function volatileFields(): array
    {
        return array_merge(parent::volatileFields(), ['status', 'has_files']);
    }
}
