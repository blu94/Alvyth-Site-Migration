<?php

namespace Plugin\SiteMigration\Backend\Resources;

use App\Models\Category;
use App\Models\Product;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Products, and the variants that live in the same table behind them.
 *
 * **Identity is the SKU**, which is what `ProductImporter::upsertKey()` already declares for the
 * spreadsheet pipeline. The two tools must agree on what makes a product *that* product, or an
 * operator who imports a spreadsheet and then a bundle gets two of everything.
 *
 * **`products.sku` is nullable, and that is the hard case this driver has to answer.** The column
 * is unique when present but a catalogue that never set SKUs has nothing to match on. Two options
 * were open: refuse such rows, or invent a key. This driver **refuses them and says so** —
 * inventing one is how the same product lands twice, and a row named in the dry run is a problem
 * the operator can fix in their own catalogue in a minute. A generated key would be wrong
 * silently and forever.
 *
 * **Variants are exported with their parent, not as rows of their own.** They live in the same
 * table behind `nullableMorphs('productable')`, so walking the table naively would export a
 * variant as a top-level product with no parent and — because variants routinely carry no SKU —
 * no way to place it. Carried as a nested list under the parent, they are written after it, when
 * the parent's id is known.
 */
class ProductDriver implements ResourceDriver
{
    public function key(): string
    {
        return 'products';
    }

    public function label(): string
    {
        return 'Products';
    }

    public function naturalKey(): string
    {
        return 'sku';
    }

    /**
     * Top-level products only, with everything `toRecord()` reads already loaded.
     *
     * `whereNull('productable_id')` is what keeps variants out of the walk. The eager loads are
     * not an optimisation to add later: without them this is 4,001 queries for 4,000 products.
     */
    public function exportQuery(): Builder
    {
        return Product::query()
            ->whereNull('productable_id')
            ->with(['categories:id,slug', 'tags:id,slug', 'variants'])
            ->orderBy('id');
    }

    /**
     * @param  Product  $record
     * @return array<string,mixed>
     */
    public function toRecord(Model $record): array
    {
        return [
            '_source'  => $record->getKey(),
            'sku'      => $record->sku,
            'type'     => $record->type,
            'title'    => $this->translations($record, 'title'),
            'subtitle' => $this->translations($record, 'subtitle'),
            'slug'     => $this->translations($record, 'slug'),

            'description' => $this->translations($record, 'description'),

            'price'             => $record->price,
            'stock'             => $record->stock,
            'weight'            => $record->weight,
            'requires_shipping' => (bool) $record->requires_shipping,
            'tax_class'         => $record->tax_class,
            'status'            => $record->status,
            'data'              => $record->data,

            // Resolved to natural keys as they are written, never to ids. `4` is some unrelated
            // category on the destination; `hats` is the same idea everywhere.
            'categories' => $record->categories->pluck('slug')->map($this->firstTranslation(...))->filter()->values()->all(),
            'tags'       => $record->tags->pluck('slug')->map($this->firstTranslation(...))->filter()->values()->all(),

            'variants' => $record->variants->map(fn (Product $variant) => [
                '_source'           => $variant->getKey(),
                'sku'               => $variant->sku,
                'title'             => $this->translations($variant, 'title'),
                'price'             => $variant->price,
                'stock'             => $variant->stock,
                'weight'            => $variant->weight,
                'requires_shipping' => (bool) $variant->requires_shipping,
                'status'            => $variant->status,
                'data'              => $variant->data,
            ])->values()->all(),
        ];
    }

    /** @param array<string,mixed> $record */
    public function locate(array $record): ?Model
    {
        $sku = $this->sku($record);

        // `withTrashed`, because a product the operator soft-deleted here and is now importing
        // again is one they are asking for back. Colliding with an invisible row instead would
        // fail on the unique index with a message naming a record they cannot see.
        return Product::withTrashed()->where('sku', $sku)->first();
    }

    /**
     * @param  array<string,mixed>  $record
     * @param  Product|null  $existing
     */
    public function write(array $record, ?Model $existing): Model
    {
        $product = $existing ?? new Product();

        $product->fill($this->attributes($record));
        $product->deleted_at = null;
        $product->save();

        $this->syncTaxonomy($product, $record);
        $this->syncVariants($product, $record);

        return $product;
    }

    /**
     * `orders` is a running count of how many times the product has sold *here*, and `stock` is
     * what is physically on this install's shelves. Neither is content the operator authored, so
     * a change in either must not make a product read as modified and trigger a needless rewrite.
     *
     * @return array<int,string>
     */
    public function volatileFields(): array
    {
        return ['orders', 'stock'];
    }

    /**
     * The SKU, refused rather than invented when absent.
     *
     * @param  array<string,mixed>  $record
     *
     * @throws RuntimeException
     */
    private function sku(array $record): string
    {
        $sku = trim((string) ($record['sku'] ?? ''));

        if ($sku === '') {
            throw new RuntimeException(
                'This product has no SKU, so there is no way to tell whether it already exists '
                . 'here. Give it one on the site you exported from and export again.'
            );
        }

        return $sku;
    }

    /**
     * The columns written straight onto the model.
     *
     * `id` is never among them — that is the whole premise of matching on a natural key — and
     * neither are the relations, which are written afterwards once the product has an id.
     *
     * @param  array<string,mixed>  $record
     * @return array<string,mixed>
     */
    private function attributes(array $record): array
    {
        $attributes = [
            'sku'               => $this->sku($record),
            'type'              => $record['type'] ?? 'simple',
            'title'             => $record['title'] ?? null,
            'subtitle'          => $record['subtitle'] ?? null,
            'slug'              => $record['slug'] ?? null,
            'description'       => $record['description'] ?? null,
            'price'             => $record['price'] ?? 0,
            'stock'             => $record['stock'] ?? null,
            'weight'            => $record['weight'] ?? null,
            'requires_shipping' => (bool) ($record['requires_shipping'] ?? true),
            'tax_class'         => $record['tax_class'] ?? null,
            'status'            => $record['status'] ?? 'draft',
            'data'              => $record['data'] ?? null,
        ];

        // A translatable column given `null` would have Spatie store a null translation for the
        // current locale rather than leave the column alone, so absent stays absent.
        return array_filter(
            $attributes,
            static fn ($value, $key) => ! (in_array($key, ['title', 'subtitle', 'slug', 'description'], true) && $value === null),
            ARRAY_FILTER_USE_BOTH
        );
    }

    /**
     * Attach categories and tags by slug, creating neither.
     *
     * **Deliberately does not create a missing category.** Categories are their own resource with
     * their own permission, and inventing one here would write a taxonomy row through a code path
     * gated on `products.create`. The import order puts categories before products precisely so
     * that the ones the operator selected already exist; a product referencing one they did not
     * select simply lands without it, and the dry run says so.
     *
     * @param  array<string,mixed>  $record
     */
    private function syncTaxonomy(Product $product, array $record): void
    {
        foreach ([['categories', Category::class], ['tags', Tag::class]] as [$relation, $model]) {
            $slugs = array_filter(array_map('strval', (array) ($record[$relation] ?? [])));

            if ($slugs === []) {
                continue;
            }

            $ids = $model::query()
                ->whereIn('slug', $slugs)
                ->pluck('id')
                ->all();

            // A slug stored as a translatable JSON column will not match a plain `whereIn`
            // on every install, so anything the column lookup missed is resolved the slower way
            // rather than silently dropped.
            if (count($ids) < count($slugs)) {
                $ids = $model::query()
                    ->get(['id', 'slug'])
                    ->filter(fn ($row) => in_array($this->firstTranslation($row->slug), $slugs, true))
                    ->pluck('id')
                    ->all();
            }

            $product->{$relation}()->sync($ids);
        }
    }

    /**
     * Write the variants carried with this product.
     *
     * Matched on SKU within the parent where they have one, and on position where they do not —
     * position being the only thing left that distinguishes two variants of one product. That is
     * a weaker key than the parent's and it is used only inside a single parent, so the blast
     * radius of getting it wrong is one product's variants rather than the catalogue.
     *
     * @param  array<string,mixed>  $record
     */
    private function syncVariants(Product $product, array $record): void
    {
        $variants = (array) ($record['variants'] ?? []);

        if ($variants === []) {
            return;
        }

        $existing = $product->variants()->get();

        foreach ($variants as $position => $variant) {
            $sku = trim((string) ($variant['sku'] ?? ''));

            $model = $sku !== ''
                ? $existing->firstWhere('sku', $sku)
                : $existing->get($position);

            $model ??= new Product();

            $model->fill(array_filter([
                'sku'               => $sku !== '' ? $sku : null,
                'title'             => $variant['title'] ?? null,
                'price'             => $variant['price'] ?? 0,
                'stock'             => $variant['stock'] ?? null,
                'weight'            => $variant['weight'] ?? null,
                'requires_shipping' => (bool) ($variant['requires_shipping'] ?? true),
                'status'            => $variant['status'] ?? 'draft',
                'data'              => $variant['data'] ?? null,
                'type'              => 'variant',
            ], static fn ($value) => $value !== null));

            $model->productable_id   = $product->getKey();
            $model->productable_type = Product::class;
            $model->save();
        }
    }

    /**
     * Every translation of a column, so a bundle carries all locales rather than the one the
     * operator happened to be viewing in.
     *
     * @return array<string,string>|null
     */
    private function translations(Product $record, string $attribute): ?array
    {
        $values = $record->getTranslations($attribute);

        return $values === [] ? null : $values;
    }

    /**
     * One usable string out of a value that may be a plain string or a translation map.
     *
     * A slug is matched across installs that need not share a current locale, so taking
     * `app()->getLocale()` would silently fail to match a bundle exported under a different one.
     */
    private function firstTranslation(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = reset($value);
        }

        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }
}
