<?php

namespace Plugin\SiteMigration\Backend\Resources;

use App\Models\TaxRate;
use App\Models\TaxZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Tax zones, carrying their rates with them.
 *
 * Same shape and same reasoning as shipping: a rate is meaningless without the zone it applies to,
 * and its slug is unique only within that zone.
 *
 * **Worth a moment's thought before ticking this.** A tax rate is a legal fact about where the
 * *shop* trades, not about its catalogue. Copying a staging site's rates onto a shop in another
 * country produces a configuration that is confidently wrong — which is why this is offered as its
 * own resource rather than folded into a "store config" bundle nobody inspects.
 */
class TaxDriver extends BaseDriver
{
    public function key(): string
    {
        return 'tax';
    }

    public function label(): string
    {
        return 'Tax zones and rates';
    }

    public function naturalKey(): string
    {
        return 'slug';
    }

    public function exportQuery(): Builder
    {
        return TaxZone::query()->with('rates')->orderBy('id');
    }

    /**
     * @param  TaxZone  $record
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
            'regions'     => $record->regions,
            'status'      => $record->status,
            'meta'        => $record->meta,

            'rates' => $record->rates->map(fn (TaxRate $rate) => [
                '_source'     => $rate->getKey(),
                'slug'        => $this->translations($rate, 'slug'),
                'title'       => $this->translations($rate, 'title'),
                'subtitle'    => $this->translations($rate, 'subtitle'),
                'description' => $this->translations($rate, 'description'),
                'rate'        => $rate->rate,

                // The class a product points at with its own `tax_class` column — a plain string
                // on both sides, so products and rates find each other without an id between them.
                'tax_class' => $rate->tax_class,
                'compound'  => (bool) $rate->compound,
                'status'    => $rate->status,
                'meta'      => $rate->meta,
            ])->values()->all(),
        ];
    }

    /** @param array<string,mixed> $record */
    public function locate(array $record): ?Model
    {
        return $this->locateBySlug(TaxZone::class, $this->requireKey($record, 'slug', 'tax zone'));
    }

    /**
     * @param  array<string,mixed>  $record
     * @param  TaxZone|null  $existing
     */
    public function write(array $record, ?Model $existing): Model
    {
        $this->requireKey($record, 'slug', 'tax zone');

        $zone = $existing ?? new TaxZone();

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

        $this->writeRates($zone, $record);

        return $zone;
    }

    /** @param array<string,mixed> $record */
    private function writeRates(TaxZone $zone, array $record): void
    {
        foreach ((array) ($record['rates'] ?? []) as $index => $rate) {
            $slug = $this->firstTranslation($rate['slug'] ?? null);

            $model = $slug === null
                ? null
                : $zone->rates()->get()->first(
                    fn (TaxRate $r) => $this->firstTranslation($r->slug) === $slug
                );

            $model ??= new TaxRate();

            $model->fill($this->withoutNullTranslations([
                'slug'        => $rate['slug'] ?? null,
                'title'       => $rate['title'] ?? null,
                'subtitle'    => $rate['subtitle'] ?? null,
                'description' => $rate['description'] ?? null,
                'rate'        => $rate['rate'] ?? 0,
                'tax_class'   => $rate['tax_class'] ?? null,
                'compound'    => (bool) ($rate['compound'] ?? false),
                'status'      => $rate['status'] ?? 'active',
                'meta'        => $rate['meta'] ?? null,
                'orders'      => $rate['orders'] ?? $index,
            ]));

            $model->tax_zone_id = $zone->getKey();
            $model->deleted_at  = null;
            $model->save();
        }
    }
}
