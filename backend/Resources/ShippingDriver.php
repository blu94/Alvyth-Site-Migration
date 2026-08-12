<?php

namespace Plugin\SiteMigration\Backend\Resources;

use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Shipping zones, carrying their methods with them.
 *
 * **One driver, not two.** A method has no meaning without its zone — "Standard, £4.95" is not a
 * shipping rule until you know it applies to the UK — and a method's natural key is only unique
 * *within* a zone, since two zones each having a "standard" method is the normal case. Splitting
 * them into two resources would mean inventing a composite key and declaring an ordering between
 * them, to buy the ability to migrate a method to a zone that is not there.
 *
 * Same shape as products carrying their variants, for the same reason.
 */
class ShippingDriver extends BaseDriver
{
    public function key(): string
    {
        return 'shipping';
    }

    public function label(): string
    {
        return 'Shipping zones and methods';
    }

    public function naturalKey(): string
    {
        return 'slug';
    }

    public function exportQuery(): Builder
    {
        return ShippingZone::query()->with('methods')->orderBy('id');
    }

    /**
     * @param  ShippingZone  $record
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

            // Which countries or regions the zone covers. Plain data on both installs — a country
            // code means the same thing everywhere, which is exactly why this resource is
            // portable at all.
            'regions' => $record->regions,
            'status'  => $record->status,
            'meta'    => $record->meta,

            'methods' => $record->methods->map(fn (ShippingMethod $method) => [
                '_source'     => $method->getKey(),
                'slug'        => $this->translations($method, 'slug'),
                'title'       => $this->translations($method, 'title'),
                'subtitle'    => $this->translations($method, 'subtitle'),
                'description' => $this->translations($method, 'description'),
                'type'        => $method->type,
                'rate'        => $method->rate,
                'free_over'   => $method->free_over,
                'bands'       => $method->bands,
                'status'      => $method->status,
                'meta'        => $method->meta,
            ])->values()->all(),
        ];
    }

    /** @param array<string,mixed> $record */
    public function locate(array $record): ?Model
    {
        return $this->locateBySlug(ShippingZone::class, $this->requireKey($record, 'slug', 'shipping zone'));
    }

    /**
     * @param  array<string,mixed>  $record
     * @param  ShippingZone|null  $existing
     */
    public function write(array $record, ?Model $existing): Model
    {
        $this->requireKey($record, 'slug', 'shipping zone');

        $zone = $existing ?? new ShippingZone();

        $zone->fill($this->withoutNullTranslations([
            'slug'        => $record['slug'] ?? null,
            'title'       => $record['title'] ?? null,
            'subtitle'    => $record['subtitle'] ?? null,
            'description' => $record['description'] ?? null,
            'regions'     => $record['regions'] ?? null,
            'status'      => $record['status'] ?? 'active',
            'meta'        => $record['meta'] ?? null,
        ]));

        $zone->deleted_at = null;
        $zone->save();

        $this->writeMethods($zone, $record);

        return $zone;
    }

    /**
     * Write the zone's methods, matched on slug within the zone.
     *
     * Not deleted-and-replaced: a method carries a rate an operator may have tuned for *this*
     * shop's costs, and a zone's identity is stable enough to update through. A method the bundle
     * does not mention is left alone rather than removed — this is a merge into a live shop, not a
     * restore over an empty one.
     *
     * @param  array<string,mixed>  $record
     */
    private function writeMethods(ShippingZone $zone, array $record): void
    {
        foreach ((array) ($record['methods'] ?? []) as $index => $method) {
            $slug = $this->firstTranslation($method['slug'] ?? null);

            $model = $slug === null
                ? null
                : $zone->methods()->get()->first(
                    fn (ShippingMethod $m) => $this->firstTranslation($m->slug) === $slug
                );

            $model ??= new ShippingMethod();

            $model->fill($this->withoutNullTranslations([
                'slug'        => $method['slug'] ?? null,
                'title'       => $method['title'] ?? null,
                'subtitle'    => $method['subtitle'] ?? null,
                'description' => $method['description'] ?? null,
                'type'        => $method['type'] ?? null,
                'rate'        => $method['rate'] ?? 0,
                'free_over'   => $method['free_over'] ?? null,
                'bands'       => $method['bands'] ?? null,
                'status'      => $method['status'] ?? 'active',
                'meta'        => $method['meta'] ?? null,
                'orders'      => $method['orders'] ?? $index,
            ]));

            $model->shipping_zone_id = $zone->getKey();
            $model->deleted_at       = null;
            $model->save();
        }
    }
}
