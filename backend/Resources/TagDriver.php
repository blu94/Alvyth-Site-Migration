<?php

namespace Plugin\SiteMigration\Backend\Resources;

use App\Models\Tag;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Tags.
 *
 * The simplest resource in the package, and useful as the shape every other driver is a
 * complication of: a natural key, a handful of translatable columns, no relations resolved at
 * write time and nothing embedded to rewrite afterwards.
 */
class TagDriver extends BaseDriver
{
    public function key(): string
    {
        return 'tags';
    }

    public function label(): string
    {
        return 'Tags';
    }

    public function naturalKey(): string
    {
        return 'slug';
    }

    public function exportQuery(): Builder
    {
        return Tag::query()->orderBy('id');
    }

    /**
     * @param  Tag  $record
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
        return $this->locateBySlug(Tag::class, $this->requireKey($record, 'slug', 'tag'));
    }

    /**
     * @param  array<string,mixed>  $record
     * @param  Tag|null  $existing
     */
    public function write(array $record, ?Model $existing): Model
    {
        $this->requireKey($record, 'slug', 'tag');

        $tag = $existing ?? new Tag();

        $tag->fill($this->withoutNullTranslations([
            'slug'        => $record['slug'] ?? null,
            'title'       => $record['title'] ?? null,
            'subtitle'    => $record['subtitle'] ?? null,
            'description' => $record['description'] ?? null,
            'type'        => $record['type'] ?? null,
            'status'      => $record['status'] ?? 'active',
            'data'        => $record['data'] ?? null,
        ]));

        $tag->deleted_at = null;
        $tag->save();

        return $tag;
    }
}
