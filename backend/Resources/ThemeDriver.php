<?php

namespace Plugin\SiteMigration\Backend\Resources;

use App\Models\Theme;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;

/**
 * Themes — the row **and** the files.
 *
 * **This reverses an earlier decision, and the reversal is the point.** The specification refused
 * themes on the grounds that "a plugin is a package with its own installer; copying the row without
 * the files gives the destination a registry entry for code that is not there". That objection is
 * entirely about copying *only* the row. Copy the directory too and it evaporates.
 *
 * It also mattered more than it looked: **theme settings hold the menus**. A site migrated without
 * its theme arrived with its navigation missing and nothing on any screen to explain why, which is
 * the shape of failure this package exists to avoid.
 *
 * `settings` and `sections` travel as core stores them, so the destination gets the operator's
 * colours, fonts, header choices and menu structure rather than the theme's defaults.
 */
class ThemeDriver extends BaseDriver
{
    public function key(): string
    {
        return 'themes';
    }

    public function label(): string
    {
        return 'Themes';
    }

    public function permissionResource(): string
    {
        return 'themes';
    }

    public function naturalKey(): string
    {
        return 'slug';
    }

    public function exportQuery(): Builder
    {
        return Theme::query()->orderBy('id');
    }

    /**
     * @param  Theme  $record
     * @return array<string,mixed>
     */
    public function toRecord(Model $record): array
    {
        $slug = (string) $record->slug;

        return [
            '_source'  => $record->getKey(),
            'slug'     => $slug,
            'title'    => $record->title,

            // The operator's own configuration, which is the half a fresh install of the same
            // theme would not give them — and the half that holds the menus.
            'settings' => $record->settings,
            'sections' => $record->sections,

            // Whether this was the live theme on the source. Applied on import only when the
            // destination has no active theme of its own, so a migration never silently changes
            // how somebody's shop looks.
            'status'   => $record->status,

            'has_files' => $this->copyIntoBundle($slug),
        ];
    }

    /** @param array<string,mixed> $record */
    public function locate(array $record): ?Model
    {
        $slug = $this->requireKey($record, 'slug', 'theme');

        return Theme::query()->where('slug', $slug)->first();
    }

    /**
     * @param  array<string,mixed>  $record
     * @param  Theme|null  $existing
     */
    public function write(array $record, ?Model $existing): Model
    {
        $slug = $this->requireKey($record, 'slug', 'theme');

        $this->restoreFiles($slug);

        $theme = $existing ?? new Theme();

        $theme->fill([
            'slug'     => $slug,
            'title'    => $record['title'] ?? $slug,
            'settings' => $record['settings'] ?? null,
            'sections' => $record['sections'] ?? null,
        ]);

        // **Never activated over a theme already in use.** Exactly one theme is active at a time,
        // so importing an active one would change the look of the destination shop as a side effect
        // of a data migration. It arrives installed and inactive; switching to it is one click the
        // operator makes deliberately.
        $theme->status = $existing?->status
            ?? (Theme::query()->where('status', 'active')->exists() ? 'inactive' : ($record['status'] ?? 'inactive'));

        $theme->save();

        return $theme;
    }

    /**
     * A theme is identified by its slug, and its slug is also its directory name.
     *
     * Renaming to dodge a collision would put the row and the files out of step — the registry
     * would name `ella-2` and the files would still be under `ella`. Two installs with a theme of
     * the same slug have the same theme, so this merges.
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

    /**
     * Copy the theme's directory into the bundle.
     *
     * @return bool whether there were files to copy
     */
    private function copyIntoBundle(string $slug): bool
    {
        if ($this->bundle === null || $slug === '') {
            return false;
        }

        $source = storage_path('app/themes/' . $slug);

        if (! is_dir($source)) {
            return false;
        }

        $target = $this->bundle->path . '/themes/' . $slug;

        File::ensureDirectoryExists(dirname($target));

        return File::copyDirectory($source, $target);
    }

    /**
     * Put the theme's files where the storefront looks for them.
     *
     * Written straight into `storage/app/themes/{slug}` rather than through the theme installer,
     * because the installer takes a zip and this is already an unpacked tree — re-zipping it to
     * hand it back would be work for its own sake. Extraction safety is not a concern here either:
     * the tree came out of the bundle through `SafeZip`, which has already refused anything with a
     * traversing path.
     */
    private function restoreFiles(string $slug): void
    {
        if ($this->bundle === null) {
            return;
        }

        $source = $this->bundle->path . '/themes/' . $slug;

        if (! is_dir($source)) {
            return;
        }

        $target = storage_path('app/themes/' . $slug);

        File::ensureDirectoryExists(dirname($target));
        File::copyDirectory($source, $target);
    }

    /** `status` is decided by the destination, never carried. */
    public function volatileFields(): array
    {
        return array_merge(parent::volatileFields(), ['status', 'has_files']);
    }
}
