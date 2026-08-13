<?php

namespace Plugin\SiteMigration\Backend\Resources;

use App\Models\Comment;
use App\Models\Page;
use App\Models\Post;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Comments, threaded. **Opt-in as a record group: a comment is a person speaking.**
 *
 * **A comment has no natural key, so identity is what it is: the same words, at the same moment,
 * about the same thing.** Body plus timestamp plus target is as close to "that comment" as the
 * schema allows, and it is strong enough that a re-import finds its own records rather than
 * doubling every thread.
 *
 * **The target resolves through the id map first, its natural key second.** `commentable` is a
 * morph — a post, a product, a page — carried both as the source's id (for the rename-proof map
 * lookup) and as the target's own natural key (for records this run did not place). A comment
 * whose target resolves to nothing is **skipped and says so**: a comment about a post that is not
 * here is not placeable, and inventing a home for it would file someone's words under the wrong
 * article.
 *
 * **Replies need the map for their parent, and the map alone is not enough.** A parent comment
 * has no natural key either, so a reply can only find it by where the source's id landed — which
 * is what forced {@see ResourceDriver::useIdMap()} to exist. Parents always precede replies in the
 * file (a reply's id is greater than its parent's, and the export walks in id order), but the
 * base class's map cache is loaded once per step, so parents written *after* that load would be
 * missing from it. This driver therefore keeps its own note of everything it places, and reads
 * the map only for what an earlier step placed.
 *
 * **A collision merges.** Matching body, moment and target is the same utterance, not two things
 * sharing a name — and there is no rename that means anything for a sentence.
 */
class CommentDriver extends BaseDriver
{
    /**
     * Morph class => the resource whose driver can find it by natural key.
     *
     * A fixed map, like the rewrite pass's `ID_KEYS`: an unknown commentable class is carried
     * with no target key and resolves only through the id map, rather than guessed at.
     *
     * @var array<class-string,string>
     */
    private const TARGET_RESOURCES = [
        Post::class    => 'posts',
        Product::class => 'products',
        Page::class    => 'pages',
    ];

    /**
     * Where this driver's own writes landed, source id => local id.
     *
     * The self-reference cache the class docblock explains: the base map cache cannot see rows
     * appended after its first load, and a thread's parent is exactly such a row.
     *
     * @var array<int,int>
     */
    private array $placed = [];

    public function __construct(private readonly DriverRegistry $drivers)
    {
    }

    public function key(): string
    {
        return 'comments';
    }

    public function label(): string
    {
        return 'Comments';
    }

    public function naturalKey(): string
    {
        return 'body + commented_at';
    }

    public function exportQuery(): Builder
    {
        return Comment::query()
            ->with(['commentable', 'author:id,email'])
            ->orderBy('id');
    }

    /**
     * @param  Comment  $record
     * @return array<string,mixed>
     */
    public function toRecord(Model $record): array
    {
        return [
            '_source' => $record->getKey(),

            'body'   => $record->body,
            'data'   => $record->data,
            'status' => $record->status,

            'author' => $record->author?->email,

            // When it was said is part of what was said — and it is also half the identity.
            'commented_at' => $record->created_at?->toDateTimeString(),

            // The target twice over: its natural key for records already here, its source id
            // (volatile — it belongs to the database that wrote it) for the rename-proof map.
            'commentable'         => $this->targetToArray($record),
            '_commentable_source' => $record->commentable_id,

            // Threading. Only ever resolvable through the map — a parent comment has no natural
            // key — which is the reason this driver exists after the seam widening, not before.
            '_parent_source' => $record->parent_id,
        ];
    }

    /** @param array<string,mixed> $record */
    public function locate(array $record): ?Model
    {
        [$type, $id] = $this->resolveTarget($record);

        $body = (string) ($record['body'] ?? '');
        $time = trim((string) ($record['commented_at'] ?? ''));

        $query = Comment::withTrashed()
            ->where('commentable_type', $type)
            ->where('commentable_id', $id)
            ->where('body', $body);

        if ($time !== '') {
            $query->where('created_at', $time);
        }

        return $query->first();
    }

    /**
     * @param  array<string,mixed>  $record
     * @param  Comment|null  $existing
     */
    public function write(array $record, ?Model $existing): Model
    {
        [$type, $id] = $this->resolveTarget($record);

        $comment = $existing ?? new Comment();

        $comment->body             = (string) ($record['body'] ?? '');
        $comment->data             = $record['data'] ?? null;
        $comment->commentable_type = $type;
        $comment->commentable_id   = $id;
        $comment->user_id          = $this->resolveAuthor($record);
        $comment->parent_id        = $this->resolveParent($record);

        if ($existing === null) {
            // Moderation is a decision about *this* site. A comment marked spam here stays spam,
            // whatever the bundle believed — the same posture UserDriver takes on a suspended
            // account. `status` is volatile for the matching reason.
            $comment->status = (string) ($record['status'] ?? 'active');
        }

        if (! empty($record['commented_at'])) {
            $comment->created_at = $record['commented_at'];
        }

        $comment->deleted_at = null;
        $comment->save();

        $source = (int) ($record['_source'] ?? 0);

        if ($source > 0) {
            $this->placed[$source] = (int) $comment->getKey();
        }

        return $comment;
    }

    /** The same words at the same moment about the same thing are one comment, not two. */
    public function mergesOnCollision(): bool
    {
        return true;
    }

    /** There is no rename that means anything for a sentence — collisions merge instead. */
    public function renameForCollision(array $record): array
    {
        return $record;
    }

    /**
     * The source ids are plumbing, and `status` is moderation — a decision this site made about
     * a comment, which must not make the record read as changed and must not be undone by a
     * bundle that predates it.
     */
    public function volatileFields(): array
    {
        return array_merge(parent::volatileFields(), [
            '_commentable_source', '_parent_source', 'status',
        ]);
    }

    /** A comment body is storefront-rendered rich text and can embed source-host URLs. */
    public function rewritableFields(): array
    {
        return ['body', 'data'];
    }

    /**
     * The target as its resource and natural key, or null when it has none to give.
     *
     * @return array{resource:string,key:string}|null
     */
    private function targetToArray(Comment $record): ?array
    {
        $target = $record->commentable;

        if ($target === null) {
            return null;
        }

        $resource = self::TARGET_RESOURCES[$target::class] ?? null;

        if ($resource === null) {
            return null;
        }

        $driver = $this->drivers->for($resource);
        $key    = $this->firstTranslation($target->getAttribute($driver->naturalKey()));

        if ($key === null) {
            return null;
        }

        return ['resource' => $resource, 'key' => $key];
    }

    /**
     * Where the comment's target lives on this install.
     *
     * Map first — rename-proof — then the target's own natural key through its own driver, so
     * this class never restates what makes a post *that* post. A comment that never had a target
     * resolves to none, legitimately: the morph columns are nullable. One whose target existed
     * on the source but resolves to nothing here is skipped, with the reason.
     *
     * @param  array<string,mixed>  $record
     * @return array{0:?string,1:?int}
     *
     * @throws SkipRecord
     */
    private function resolveTarget(array $record): array
    {
        $described = $record['commentable'] ?? null;
        $source    = (int) ($record['_commentable_source'] ?? 0);

        if ($source <= 0 && ! is_array($described)) {
            return [null, null];
        }

        $resource = is_array($described) ? (string) ($described['resource'] ?? '') : '';

        if ($resource !== '' && $this->drivers->has($resource)) {
            $mapped = $this->mapped($resource, $source);

            if ($mapped !== null) {
                return [$this->classFor($resource), $mapped];
            }

            $driver = $this->drivers->for($resource);
            $key    = (string) ($described['key'] ?? '');

            if ($key !== '') {
                $target = $driver->locate([$driver->naturalKey() => $key]);

                if ($target !== null) {
                    return [$target::class, (int) $target->getKey()];
                }
            }
        }

        throw new SkipRecord(sprintf(
            'The %s this comment was about is not on this site, so the comment has nowhere to '
            . 'live. Import that resource first, or bring it across by hand.',
            is_array($described) ? rtrim((string) ($described['resource'] ?? 'record'), 's') : 'record'
        ));
    }

    /** The morph class a resource key maps back to. */
    private function classFor(string $resource): ?string
    {
        $class = array_search($resource, self::TARGET_RESOURCES, true);

        return $class === false ? null : $class;
    }

    /** @param array<string,mixed> $record */
    private function resolveAuthor(array $record): ?int
    {
        $email = trim((string) ($record['author'] ?? ''));

        if ($email === '') {
            return null;
        }

        $id = User::query()->where('email', $email)->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * The local id of a reply's parent: this step's own writes first, the map for earlier steps,
     * and null past both. Null rather than skipped — a reply whose parent could not be placed
     * still carries somebody's words, and landing it top-level loses the threading, not the
     * speech.
     *
     * @param  array<string,mixed>  $record
     */
    private function resolveParent(array $record): ?int
    {
        $source = (int) ($record['_parent_source'] ?? 0);

        if ($source <= 0) {
            return null;
        }

        return $this->placed[$source] ?? $this->mapped('comments', $source);
    }
}
