<?php

namespace Plugin\SiteMigration\Backend\Resources;

use Illuminate\Database\Eloquent\Model;
use Plugin\SiteMigration\Backend\Bundle\BundleContext;
use Plugin\SiteMigration\Backend\Runs\IdMap;
use RuntimeException;

/**
 * What every driver would otherwise repeat.
 *
 * Three jobs, and each exists because getting it wrong in one driver out of twelve is the shape of
 * bug that survives review: reading *every* translation rather than the current locale, matching a
 * translatable slug across installs that need not share a locale, and never writing `null` into a
 * translatable column.
 */
abstract class BaseDriver implements ResourceDriver
{
    protected ?BundleContext $bundle = null;

    protected ?IdMap $idMap = null;

    /**
     * One resource's mappings, loaded on first use.
     *
     * `IdMap::all()` reads its file line by line, so calling it per record would re-scan the map
     * once for every row this driver writes — an import of ten thousand orders re-reading the
     * products map ten thousand times. Loaded once per driver instance instead, which is safe
     * because the import order guarantees a referenced resource is **finished** before anything
     * that references it starts: nothing is appended to a map after the first read of it.
     *
     * The one exception — a resource referencing *itself*, as a comment thread does — cannot rely
     * on this cache, because parents written after the first load would be missing from it. A
     * driver with that shape keeps its own record of what it placed this step; see `CommentDriver`.
     *
     * @var array<string,array<int,int>>
     */
    private array $idMapCache = [];

    /**
     * By default the resource key is also the permission resource.
     *
     * Overridden only where core named the same thing differently — see `PostDriver` (`blogs`)
     * and `EmailTemplateDriver` (`settings`).
     */
    public function permissionResource(): string
    {
        return $this->key();
    }

    /** A no-op for every driver whose records are fully described by their own columns. */
    public function useBundle(BundleContext $context): void
    {
        $this->bundle = $context;
    }

    /** A no-op for every driver whose records reference nothing. */
    public function useIdMap(IdMap $map): void
    {
        $this->idMap = $map;
        $this->idMapCache = [];
    }

    /**
     * Where a source record's id landed on this install, or null.
     *
     * The **rename-proof** half of reference resolution. A natural-key lookup answers "who holds
     * this key now", which after a KEEP_BOTH collision is the destination's own record rather
     * than the one this run placed alongside it under a free key. The map answers "where did
     * *that* record land", which is the question an order item or an invoice's order reference
     * is actually asking. Callers try this first and fall back to the natural key for records
     * this run did not place.
     */
    protected function mapped(string $resource, mixed $sourceId): ?int
    {
        $sourceId = (int) $sourceId;

        if ($this->idMap === null || $sourceId <= 0) {
            return null;
        }

        $this->idMapCache[$resource] ??= $this->idMap->all($resource);

        return $this->idMapCache[$resource][$sourceId] ?? null;
    }

    /**
     * Columns Spatie stores as a translation map, which must round-trip whole.
     *
     * @return array<int,string>
     */
    protected function translatableFields(): array
    {
        return ['title', 'subtitle', 'slug', 'description'];
    }

    /**
     * Give a colliding record a free slug, which is what most drivers key on.
     *
     * Overridden by the drivers whose identity is not a slug — `sku` for products, `code` for
     * discounts, the number for invoices — and by `UserDriver`, which cannot rename at all.
     *
     * @param  array<string,mixed>  $record
     * @return array<string,mixed>
     */
    public function renameForCollision(array $record): array
    {
        $key = $this->naturalKey();

        if (! array_key_exists($key, $record)) {
            return $record;
        }

        $record[$key] = Collision::freeTranslatedKey(
            $record[$key],
            fn (string $candidate) => $this->locateBySlug($this->modelClass(), $candidate) !== null
        );

        return $record;
    }

    /** Most resources are genuinely two records when two claim one name. */
    public function mergesOnCollision(): bool
    {
        return false;
    }

    /**
     * The model this driver writes, taken from its own export query.
     *
     * Derived rather than declared so a driver cannot get the two out of step — the query is
     * already the authority on what this resource is.
     */
    protected function modelClass(): string
    {
        return $this->exportQuery()->getModel()::class;
    }

    public function volatileFields(): array
    {
        // `orders` is a display position that operators reshuffle locally, and `user_id` is
        // whoever authored the record *here* — neither is content that arrived in the bundle, so
        // a change in either must not make a record read as modified.
        return ['orders', 'user_id'];
    }

    /**
     * Every translation of a column, so a bundle carries all locales rather than the one the
     * operator happened to be viewing in.
     *
     * @return array<string,string>|null
     */
    protected function translations(Model $record, string $attribute): ?array
    {
        if (method_exists($record, 'getTranslations')) {
            $values = $record->getTranslations($attribute);

            return $values === [] ? null : $values;
        }

        $value = $record->getAttribute($attribute);

        if ($value === null) {
            return null;
        }

        // **A model can hold a locale map without using `HasTranslations`.** `Meta` — which is
        // what an email template is — casts `title`, `subtitle` and `description` to `array` and
        // stores `{"en": "…"}` in them, but has no Spatie trait, so it lands here. Casting that to
        // a string produced `Array to string conversion` on every template exported and wrote the
        // literal text "Array" into the bundle. Already-mapped values pass through untouched.
        if (is_array($value)) {
            return $value === [] ? null : $value;
        }

        return ['*' => (string) $value];
    }

    /**
     * One usable string out of a value that may be plain or a translation map.
     *
     * **Not `app()->getLocale()`.** A slug is matched across two installs that need not share a
     * current locale, so reading the active one would silently fail to match a bundle exported
     * under a different one — and the symptom is every record reported as new.
     */
    protected function firstTranslation(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = reset($value);
        }

        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }

    /**
     * Locate a record by a translatable column, tolerating both storage shapes.
     *
     * A translatable column holds JSON, so an equality match on the raw column only works when the
     * value was written as a plain string. Both are tried: the cheap indexed comparison first, then
     * a JSON containment scan across the locales the bundle could have used.
     */
    protected function locateBySlug(string $modelClass, ?string $slug, bool $withTrashed = true): ?Model
    {
        return $this->locateByTranslatable($modelClass, 'slug', $slug, $withTrashed);
    }

    /**
     * The same lookup against any translatable column.
     *
     * Extracted from {@see locateBySlug()} because `slug` is not universal: an invoice template is
     * a `Meta` row with no slug at all, and its only human-meaningful identifier is its **title**.
     * Generalising the one method is what stops that driver copying the locale-scan logic — which
     * is subtle enough (`orWhere('col->locale', …)` inside a closure, per lesson #6 on `orWhere`
     * binding) that a second copy would be a second thing to get wrong.
     */
    protected function locateByTranslatable(
        string $modelClass,
        string $column,
        ?string $value,
        bool $withTrashed = true,
    ): ?Model {
        if ($value === null || $value === '') {
            return null;
        }

        /** @var \Illuminate\Database\Eloquent\Builder $query */
        $query = $modelClass::query();

        if ($withTrashed && in_array(
            \Illuminate\Database\Eloquent\SoftDeletes::class,
            class_uses_recursive($modelClass),
            true
        )) {
            // A record the operator soft-deleted here and is now importing again is one they are
            // asking for back. Colliding with an invisible row instead fails on a unique index
            // naming something they cannot see.
            $query->withTrashed();
        }

        $direct = (clone $query)->where($column, $value)->first();

        if ($direct !== null) {
            return $direct;
        }

        return (clone $query)
            ->where(function ($sub) use ($column, $value) {
                foreach ($this->candidateLocales() as $locale) {
                    $sub->orWhere($column . '->' . $locale, $value);
                }
            })
            ->first();
    }

    /**
     * Locales a slug might be stored under.
     *
     * This install's configured set, plus `en` as the near-universal fallback and `*` for the
     * placeholder {@see translations()} uses on a non-translatable model.
     *
     * @return array<int,string>
     */
    protected function candidateLocales(): array
    {
        $locales = array_keys((array) config('app.available_locales', []));

        $locales[] = (string) config('app.locale');
        $locales[] = 'en';
        $locales[] = '*';

        return array_values(array_unique(array_filter($locales)));
    }

    /**
     * Drop translatable keys whose value is null before filling.
     *
     * Spatie stores a null translation for the *current* locale rather than leaving the column
     * alone, so an absent field would quietly overwrite a good value with nothing.
     *
     * @param  array<string,mixed>  $attributes
     * @return array<string,mixed>
     */
    protected function withoutNullTranslations(array $attributes): array
    {
        $translatable = $this->translatableFields();

        return array_filter(
            $attributes,
            static fn ($value, $key) => ! (in_array($key, $translatable, true) && $value === null),
            ARRAY_FILTER_USE_BOTH
        );
    }

    /**
     * The natural key value carried by a record, refused rather than invented when absent.
     *
     * @param  array<string,mixed>  $record
     *
     * @throws RuntimeException
     */
    protected function requireKey(array $record, string $field, string $noun): string
    {
        $value = $this->firstTranslation($record[$field] ?? null);

        if ($value === null) {
            throw new RuntimeException(sprintf(
                'This %s has no %s, so there is no way to tell whether it already exists here. '
                . 'Give it one on the site you exported from and export again.',
                $noun,
                $field
            ));
        }

        return $value;
    }

    /**
     * Fields whose contents may embed references to other records.
     *
     * Read by the rewrite pass. A driver returning `[]` is saying its records contain no ids or
     * URLs belonging to the source install — true for a tag, false for a page.
     *
     * @return array<int,string>
     */
    public function rewritableFields(): array
    {
        return [];
    }
}
