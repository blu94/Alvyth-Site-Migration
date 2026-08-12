<?php

namespace Plugin\SiteMigration\Backend\Resources;

use App\Models\Category;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Categories — and, by the same rows, collections.
 *
 * **"Collections" is not a resource of its own.** `ThemeController` resolves `collections/{slug}`
 * through the *category* repository, so a collection is a category reached by a different URL
 * prefix. Giving it its own driver would import every category twice, once under each name.
 *
 * Lands before products, because a product references categories by slug and a category that does
 * not exist yet cannot be attached — which is why the registry's order is declared rather than
 * discovered.
 */
class CategoryDriver extends BaseDriver
{
    public function key(): string
    {
        return 'categories';
    }

    public function label(): string
    {
        return 'Categories';
    }

    public function naturalKey(): string
    {
        return 'slug';
    }

    public function exportQuery(): Builder
    {
        return Category::query()->orderBy('id');
    }

    /**
     * @param  Category  $record
     * @return array<string,mixed>
     */
    public function toRecord(Model $record): array
    {
        return [
            '_source'     => $record->getKey(),
            'slug'        => $this->translations($record, 'slug'),
            'title'       => $this->translations($record, 'title'),
            'subtitle'    => $this->translations($record, 'subtitle'),
            'description' => $this->translations($record, 'description'),
            'type'        => $record->type,
            'status'      => $record->status,
            'data'        => $record->data,
        ];
    }

    /** @param array<string,mixed> $record */
    public function locate(array $record): ?Model
    {
        return $this->locateBySlug(Category::class, $this->requireKey($record, 'slug', 'category'));
    }

    /**
     * @param  array<string,mixed>  $record
     * @param  Category|null  $existing
     */
    public function write(array $record, ?Model $existing): Model
    {
        $this->requireKey($record, 'slug', 'category');

        $category = $existing ?? new Category();

        $category->fill($this->withoutNullTranslations([
            'slug'        => $record['slug'] ?? null,
            'title'       => $record['title'] ?? null,
            'subtitle'    => $record['subtitle'] ?? null,
            'description' => $record['description'] ?? null,
            'type'        => $record['type'] ?? null,
            'status'      => $record['status'] ?? 'active',
            'data'        => $record['data'] ?? null,
        ]));

        $category->deleted_at = null;
        $category->save();

        return $category;
    }

    /** A category's `data` blob can carry an image chosen in the admin. */
    public function rewritableFields(): array
    {
        return ['data', 'description'];
    }
}
