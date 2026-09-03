<?php

namespace Plugin\SiteMigration\Backend\Resources;

use App\Models\Meta;
use App\Models\Page;
use App\Models\Revision;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Pages, with their whole builder tree and their SEO block.
 *
 * **The hardest resource in the package**, for a reason that is not obvious from the columns: a
 * page is barely in the `pages` table at all. Its content is a tree of `metas` rows —
 * ROW → COLUMN → SECTION — and those rows carry, inside opaque JSON `data` blobs, asset ids and
 * page ids belonging to the source install. Those ids are wrong the moment they land, and no
 * foreign key can repair them because the schema does not know they are there. That is what the
 * rewrite pass exists for; this driver's job is to carry the tree faithfully and record where
 * everything went.
 *
 * **The tree is written whole rather than diffed.** An import that tried to match individual
 * builder nodes would need an identity for a node, and a node has none — no slug, no key, nothing
 * but its position among its siblings. Replacing the tree is the honest operation, and it is safe
 * because {@see write()} snapshots the previous version first.
 */
class PageDriver extends BaseDriver
{
    public function key(): string
    {
        return 'pages';
    }

    public function label(): string
    {
        return 'Pages';
    }

    public function naturalKey(): string
    {
        return 'slug';
    }

    /**
     * Three levels of builder tree eager-loaded, plus SEO.
     *
     * Without this a hundred pages is several hundred queries, and the depth is fixed by the
     * builder's own shape rather than chosen: ROW holds COLUMN holds SECTION.
     */
    public function exportQuery(): Builder
    {
        return Page::query()
            ->with(['rows.children.children', 'seo'])
            ->orderBy('id');
    }

    /**
     * @param  Page  $record
     * @return array<string,mixed>
     */
    public function toRecord(Model $record): array
    {
        return [
            '_source'     => $record->getKey(),
            'slug'        => $this->translations($record, 'slug'),
            'title'       => $this->translations($record, 'title'),
            'description' => $this->translations($record, 'description'),
            'url'         => $this->translations($record, 'url'),
            'data'        => $record->data,
            'type'        => $record->type,
            'status'      => $record->status,

            // `fixed` and `deletable` mark the system pages the storefront routes on — HOME, CART
            // and their neighbours. Carried so the destination keeps treating them as system pages
            // rather than as ordinary content somebody could delete out from under the shop.
            'fixed'     => (bool) $record->fixed,
            'deletable' => (bool) $record->deletable,

            'seo'  => $record->seo === null ? null : $this->metaToArray($record->seo),
            'rows' => $record->rows->map($this->metaToArray(...))->values()->all(),
        ];
    }

    /** @param array<string,mixed> $record */
    public function locate(array $record): ?Model
    {
        return $this->locateBySlug(Page::class, $this->requireKey($record, 'slug', 'page'));
    }

    /**
     * @param  array<string,mixed>  $record
     * @param  Page|null  $existing
     */
    public function write(array $record, ?Model $existing): Model
    {
        $this->requireKey($record, 'slug', 'page');

        // Snapshot before overwriting, never before creating: a revision for a record that had no
        // previous version files an empty snapshot, and records this run *created* are removable
        // from the id map if a rollback is ever built.
        if ($existing !== null) {
            $this->snapshot($existing);
        }

        $page = $existing ?? new Page();

        $page->fill($this->withoutNullTranslations([
            'slug'        => $record['slug'] ?? null,
            'title'       => $record['title'] ?? null,
            'description' => $record['description'] ?? null,
            'url'         => $record['url'] ?? null,
            'data'        => $record['data'] ?? null,
            'type'        => $record['type'] ?? null,
            'status'      => $record['status'] ?? 'draft',
            'fixed'       => (bool) ($record['fixed'] ?? false),
            'deletable'   => (bool) ($record['deletable'] ?? true),
        ]));

        $page->deleted_at = null;
        $page->save();

        $this->writeTree($page, $record);

        return $page;
    }

    /**
     * Replace the builder tree and the SEO block.
     *
     * The old tree is deleted first. Merging would be the alternative and it has no defensible
     * rule: a node has no identity, so "the same node" can only mean "the one in the same
     * position", and a page whose sections were reordered would have its content shuffled between
     * blocks rather than replaced.
     *
     * @param  array<string,mixed>  $record
     */
    private function writeTree(Page $page, array $record): void
    {
        // **Depth-first, because there is no cascade.** `$page->metas()` is only the rows whose
        // `metaable_type` is the page - the ROW level - and every COLUMN and SECTION beneath them
        // hangs off a `Meta` parent. The migration declares `nullableMorphs` with no foreign key and
        // the model has no `deleting` hook, so deleting the top level orphaned the whole subtree:
        // unreachable rows, left behind on every page overwrite, on a package whose main use is a
        // weekly staging push that rewrites every page.
        //
        // Core solves the same problem the same way in `MetaRepository::deleteRecursive()`, which is
        // `private`, so the recursion is restated here rather than reached for.
        foreach ($page->metas()->get() as $meta) {
            $this->deleteMetaTree($meta);
        }

        foreach ((array) ($record['rows'] ?? []) as $index => $row) {
            $this->writeMeta($page, $row, $index);
        }

        if (is_array($record['seo'] ?? null)) {
            $this->writeMeta($page, $record['seo'], 0);
        }
    }

    /**
     * Delete a meta node and everything hanging off it.
     *
     * Depth-first: a child's `metaable_id` points at its parent's key, so removing the parent first
     * is what strands the children.
     */
    private function deleteMetaTree(Meta $meta): void
    {
        foreach ($meta->children()->get() as $child) {
            $this->deleteMetaTree($child);
        }

        $meta->delete();
    }

    /**
     * One meta node and everything under it.
     *
     * Recursive because the tree is, and depth-first because a child's `metaable_id` is its
     * parent's primary key — which does not exist until the parent is saved.
     *
     * @param  array<string,mixed>  $node
     */
    private function writeMeta(Model $parent, array $node, int $order): void
    {
        $meta = new Meta([
            'type'        => $node['type'] ?? 'SECTION',
            'title'       => $node['title'] ?? null,
            'subtitle'    => $node['subtitle'] ?? null,
            'description' => $node['description'] ?? null,
            'data'        => $node['data'] ?? null,
            'status'      => $node['status'] ?? 'active',
            'orders'      => $node['orders'] ?? $order,
        ]);

        $meta->metaable_id   = $parent->getKey();
        $meta->metaable_type = $parent->getMorphClass();
        $meta->save();

        foreach ((array) ($node['children'] ?? []) as $index => $child) {
            $this->writeMeta($meta, $child, $index);
        }
    }

    /**
     * A meta node as plain data, children included.
     *
     * @return array<string,mixed>
     */
    private function metaToArray(Meta $meta): array
    {
        return [
            'type'        => $meta->type,
            'title'       => $meta->title,
            'subtitle'    => $meta->subtitle,
            'description' => $meta->description,
            'data'        => $meta->data,
            'status'      => $meta->status,
            'orders'      => $meta->orders,

            // **`$meta->children`, not `relationLoaded('children') ? … : []`.** That guard was an
            // attempt to avoid an N+1 and it silently broke the content hash instead: on export
            // the tree is eager-loaded and every child is written, but on import `locate()` hands
            // back a bare page, so the same method returned an empty tree — and *every page on
            // the site* compared as changed and was rewritten on every migration, with nothing to
            // explain it. That is precisely the failure mode the canonical form exists to avoid.
            //
            // Eloquent lazy-loads and caches here, so the eager-loaded export path still costs no
            // extra queries; only the one-record comparison on import pays, which is correct.
            'children' => $meta->children->map($this->metaToArray(...))->values()->all(),
        ];
    }

    /**
     * File the version about to be replaced, so an overwrite is undoable.
     *
     * **Written directly rather than through `HasRevisions`**, and that is a limit rather than a
     * preference: the trait is applied to `App\Models\Page`, which is not this package's class to
     * edit. So the package owns the correctness of the shape the trait would have produced —
     * including the trim, which the trait would otherwise have done.
     *
     * Pages only, deliberately. `Page` is the one model core applies the trait to, so it is the
     * one model with a screen that *shows* revisions. Snapshotting an overwritten product would
     * file a row nothing surfaces: recoverable by hand from the database, invisible in the admin,
     * and therefore not an undo an operator can actually use.
     */
    private function snapshot(Page $page): void
    {
        try {
            $page->loadMissing(['rows.children.children', 'seo']);

            $user = auth()->user();

            // `payload`, `user_name` — the column names the trait writes, verified against
            // `HasRevisions::recordRevision()` and the create migration rather than guessed. This
            // is the cost of a plugin not being able to add a trait to a core model: the package
            // owns the correctness of a shape it does not define.
            //
            // The payload is the trait's `attributesToArray()` **plus the builder tree**, because
            // a page's content is almost entirely `metas` rows and a snapshot of the columns alone
            // would restore an empty page.
            Revision::create([
                'revisionable_id'   => $page->getKey(),
                'revisionable_type' => $page->getMorphClass(),
                'user_id'           => $user?->getKey(),
                'user_name'         => $user?->name,
                'payload'           => $page->attributesToArray() + [
                    'rows' => $page->rows->map($this->metaToArray(...))->values()->all(),
                    'seo'  => $page->seo === null ? null : $this->metaToArray($page->seo),
                ],
            ]);

            $this->trimRevisions($page);
        } catch (\Throwable) {
            // A failed snapshot must not cost the import. The operator asked to migrate a page;
            // losing the undo is worse than nothing but far better than losing the page.
        }
    }

    /**
     * Keep the newest `Revision::KEEP_PER_RECORD`, dropping the oldest.
     *
     * Ordered by **id**, not `created_at`, matching the trait and the reason the create migration
     * gives for it: timestamps have one-second resolution and saves do land in the same second,
     * so ties come back in arbitrary order and the trim would delete an arbitrary row rather than
     * the oldest one.
     */
    private function trimRevisions(Page $page): void
    {
        $keep = Revision::KEEP_PER_RECORD;

        $ids = Revision::query()
            ->where('revisionable_id', $page->getKey())
            ->where('revisionable_type', $page->getMorphClass())
            ->orderByDesc('id')
            ->pluck('id')
            ->slice($keep);

        if ($ids->isNotEmpty()) {
            Revision::query()->whereIn('id', $ids)->delete();
        }
    }

    /**
     * Everything a page can hide a source id or a source URL inside.
     *
     * `rows` is the important one — the builder tree — and `description` matters because a
     * richtext body carries `<img src="https://source/storage/…">` that would otherwise hotlink
     * the site the operator is leaving.
     */
    public function rewritableFields(): array
    {
        return ['rows', 'seo', 'data', 'description'];
    }

    public function volatileFields(): array
    {
        return array_merge(parent::volatileFields(), ['fixed', 'deletable']);
    }
}
