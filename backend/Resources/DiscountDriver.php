<?php

namespace Plugin\SiteMigration\Backend\Resources;

use App\Models\Discount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Discounts.
 *
 * **Identity is the code**, not the slug, because the code is the thing a customer types and the
 * thing the shop matches on — two discounts may share a title, never a code.
 *
 * **Usage does not travel.** `discount_usages` records who redeemed what on *that* shop, and
 * copying it would either grant strangers' redemptions here or exhaust a per-customer limit for
 * people who have never visited. The rule is the same one that keeps the activity log out: a
 * record of what happened on another install is not a fact about this one.
 */
class DiscountDriver extends BaseDriver
{
    public function key(): string
    {
        return 'discounts';
    }

    public function label(): string
    {
        return 'Discounts';
    }

    public function naturalKey(): string
    {
        return 'code';
    }

    public function exportQuery(): Builder
    {
        return Discount::query()->orderBy('id');
    }

    /**
     * @param  Discount  $record
     * @return array<string,mixed>
     */
    public function toRecord(Model $record): array
    {
        return [
            '_source'     => $record->getKey(),
            'code'        => $record->code,
            'slug'        => $this->translations($record, 'slug'),
            'title'       => $this->translations($record, 'title'),
            'subtitle'    => $this->translations($record, 'subtitle'),
            'description' => $this->translations($record, 'description'),
            'type'        => $record->type,
            'value'       => $record->value,
            'status'      => $record->status,
            'meta'        => $record->meta,
            'rules'       => $record->rules,
            'starts_at'   => $record->starts_at?->toDateTimeString(),
            'ends_at'     => $record->ends_at?->toDateTimeString(),
        ];
    }

    /** @param array<string,mixed> $record */
    public function locate(array $record): ?Model
    {
        $code = $this->requireKey($record, 'code', 'discount');

        return Discount::withTrashed()->where('code', $code)->first();
    }

    /**
     * @param  array<string,mixed>  $record
     * @param  Discount|null  $existing
     */
    public function write(array $record, ?Model $existing): Model
    {
        $code = $this->requireKey($record, 'code', 'discount');

        $discount = $existing ?? new Discount();

        $discount->fill($this->withoutNullTranslations([
            'code'        => $code,
            'slug'        => $record['slug'] ?? null,
            'title'       => $record['title'] ?? null,
            'subtitle'    => $record['subtitle'] ?? null,
            'description' => $record['description'] ?? null,
            'type'        => $record['type'] ?? null,
            'value'       => $record['value'] ?? 0,
            'status'      => $record['status'] ?? 'active',
            'meta'        => $record['meta'] ?? null,
            'rules'       => $record['rules'] ?? null,
            'starts_at'   => $record['starts_at'] ?: null,
            'ends_at'     => $record['ends_at'] ?: null,
        ]));

        $discount->deleted_at = null;
        $discount->save();

        return $discount;
    }

    /**
     * A colliding discount takes a free code.
     *
     * The code is what a customer types, so two discounts cannot share one — but the incoming
     * discount is still somebody's configured offer, and the operator can retire whichever of the
     * two they meant to keep once both are visible.
     *
     * @param  array<string,mixed>  $record
     * @return array<string,mixed>
     */
    public function renameForCollision(array $record): array
    {
        $record['code'] = Collision::freeKey(
            (string) ($record['code'] ?? ''),
            static fn (string $candidate) => Discount::withTrashed()->where('code', $candidate)->exists()
        );

        return $record;
    }

    /**
     * A discount's rules can name products or categories by id.
     *
     * Those are ids on the *source*, so they need the rewrite pass exactly as a builder node does —
     * a "10% off these six products" rule that landed unrewritten would discount six arbitrary
     * items.
     */
    public function rewritableFields(): array
    {
        return ['rules', 'meta'];
    }
}
