<?php

namespace Plugin\SiteMigration\Backend\Resources;

use App\Models\Category;
use App\Models\Post;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Posts — the blog.
 *
 * `Blog` is a subclass of `Post` with a type scope rather than a table of its own, so one driver
 * carries both and a second would export every article twice.
 *
 * Lands after categories and tags, because it attaches to them by slug and attaching to a taxonomy
 * row that does not exist yet silently drops the association.
 */
class PostDriver extends BaseDriver
{
    public function key(): string
    {
        return 'posts';
    }

    public function label(): string
    {
        return 'Posts';
    }

    /** Core calls this resource `blogs`; there is no `posts` permission to check. */
    public function permissionResource(): string
    {
        return 'blogs';
    }

    public function naturalKey(): string
    {
        return 'slug';
    }

    public function exportQuery(): Builder
    {
        return Post::query()
            ->with(['categories:id,slug', 'tags:id,slug'])
            ->orderBy('id');
    }

    /**
     * @param  Post  $record
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

            'categories' => $record->categories->pluck('slug')->map($this->firstTranslation(...))->filter()->values()->all(),
            'tags'       => $record->tags->pluck('slug')->map($this->firstTranslation(...))->filter()->values()->all(),
        ];
    }

    /** @param array<string,mixed> $record */
    public function locate(array $record): ?Model
    {
        return $this->locateBySlug(Post::class, $this->requireKey($record, 'slug', 'post'));
    }

    /**
     * @param  array<string,mixed>  $record
     * @param  Post|null  $existing
     */
    public function write(array $record, ?Model $existing): Model
    {
        $this->requireKey($record, 'slug', 'post');

        $post = $existing ?? new Post();

        $post->fill($this->withoutNullTranslations([
            'slug'        => $record['slug'] ?? null,
            'title'       => $record['title'] ?? null,
            'subtitle'    => $record['subtitle'] ?? null,
            'description' => $record['description'] ?? null,
            'type'        => $record['type'] ?? 'BLOG',
            'status'      => $record['status'] ?? 'draft',
            'data'        => $record['data'] ?? null,
        ]));

        $post->deleted_at = null;
        $post->save();

        $this->attachTaxonomy($post, $record);

        return $post;
    }

    /**
     * Attach categories and tags by slug, creating neither.
     *
     * A taxonomy row the operator did not select is not invented here: categories and tags are
     * their own resources with their own permissions, and creating one through a path gated on
     * `posts.create` would be a quiet widening of that grant.
     *
     * @param  array<string,mixed>  $record
     */
    private function attachTaxonomy(Post $post, array $record): void
    {
        foreach ([['categories', Category::class], ['tags', Tag::class]] as [$relation, $model]) {
            $slugs = array_values(array_filter(array_map('strval', (array) ($record[$relation] ?? []))));

            if ($slugs === []) {
                continue;
            }

            $ids = $model::query()
                ->get(['id', 'slug'])
                ->filter(fn ($row) => in_array($this->firstTranslation($row->slug), $slugs, true))
                ->pluck('id')
                ->all();

            $post->{$relation}()->sync($ids);
        }
    }

    /** A post body is richtext and routinely carries `<img src>` pointing at the source install. */
    public function rewritableFields(): array
    {
        return ['description', 'data'];
    }
}
