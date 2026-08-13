<?php

namespace Plugin\SiteMigration\Backend\Resources;

use App\Models\Address;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Orders, with their items and addresses. **Opt-in as a record group: these are records about
 * people** — an order is a person's purchase, an address is where they live.
 *
 * **Items and addresses travel nested under the order**, the way variants travel under a product:
 * they have no identity of their own, so walking them as top-level records would export lines that
 * nothing could ever place. Written after the order, when its id is known.
 *
 * **Products are resolved at write time, map first.** An order item names its product by SKU —
 * the natural key — but a SKU lookup alone is not rename-proof: a product this same run placed
 * alongside a clash as `HAT-1-2` is *not* the `HAT-1` the lookup would find. The id map answers
 * where the source's product actually landed; the SKU answers for products that were already
 * here. Either way the item keeps its own SKU and title snapshot, exactly as checkout recorded
 * them, so the order still reads correctly even when the product itself is long gone.
 *
 * **A collision takes the destination's next order number in sequence**, for the same reason an
 * invoice does: `ORD-000123-2` sits outside the numbering forever, and order numbers are how a
 * customer, an email template and a payment gateway all name the purchase. The number it arrived
 * under is kept in the order's meta.
 *
 * **Addresses attach to the order, never to anyone's address book.** On the source an order's
 * address may be a row the customer manages themselves; copying it into their address book here
 * would edit a person's saved addresses as a side effect of an import. Written as an
 * order-attached snapshot instead — which is exactly what checkout itself does for a guest, and
 * what the row means once the order exists: an immutable record of where this order went.
 */
class OrderDriver extends BaseDriver
{
    /** The one meta key that describes the migration rather than the order. */
    private const LOCAL_META = ['imported_number'];

    /** The address columns that travel — everything the row holds except its ids and morphs. */
    private const ADDRESS_FIELDS = [
        'type', 'first_name', 'last_name', 'email', 'contact',
        'address_line_1', 'address_line_2', 'landmark',
        'city', 'state', 'zip_code', 'country', 'is_billing',
    ];

    public function key(): string
    {
        return 'orders';
    }

    public function label(): string
    {
        return 'Orders';
    }

    public function naturalKey(): string
    {
        return 'order_number';
    }

    public function exportQuery(): Builder
    {
        return Order::query()
            ->with([
                'items',
                'customer:id,email',
                'billingAddress',
                'shippingAddress',
                'shippingMethod:id,slug',
            ])
            ->orderBy('id');
    }

    /**
     * @param  Order  $record
     * @return array<string,mixed>
     */
    public function toRecord(Model $record): array
    {
        // Sorted by id — insertion order on both sides. The relation itself carries no ordering,
        // and an unordered list would make the same order hash two ways.
        $items = $record->items->sortBy('id')->values();

        return [
            '_source'      => $record->getKey(),
            'order_number' => $record->order_number,

            'status'             => $record->status,
            'payment_status'     => $record->payment_status,
            'fulfillment_status' => $record->fulfillment_status,
            'currency'           => $record->currency,

            'subtotal'       => $record->subtotal,
            'discount_total' => $record->discount_total,
            'shipping_total' => $record->shipping_total,
            'tax_total'      => $record->tax_total,
            'grand_total'    => $record->grand_total,

            // The method as its natural key for re-linking, and the snapshot name for the truth.
            // The name is what the customer saw at checkout; the slug is only ever a convenience
            // for re-attaching the live method where one matches, and may resolve to nothing.
            'shipping_method'      => $this->firstTranslation($record->shippingMethod?->slug),
            'shipping_method_name' => $record->shipping_method_name,

            'customer' => $record->customer?->email,

            // When the order was placed on the source. Carried explicitly because `created_at`
            // is excluded from every bundle record by the canonical form — and an imported order
            // dated the day of the migration would file a year of sales under one afternoon.
            'placed_at' => $record->created_at?->toDateTimeString(),

            'notes' => $record->notes,
            'meta'  => $this->portableMeta($record->meta),

            'billing_address'  => $this->addressToArray($record->billingAddress),
            'shipping_address' => $this->addressToArray($record->shippingAddress),

            'items' => $items->map(fn (OrderItem $item) => [
                'sku'        => $item->sku,
                'title'      => $item->title,
                'qty'        => $item->qty,
                'unit_price' => $item->unit_price,
                'discount'   => $item->discount,
                'tax'        => $item->tax,
                'subtotal'   => $item->subtotal,
                'meta'       => $item->meta,
            ])->all(),

            // The items' product ids on the source, aligned with `items` by position. A separate
            // top-level list rather than a key inside each item, because the canonical form can
            // only exclude top-level keys — buried inside `items`, a source id would poison the
            // content hash and every order would compare as changed on every migration, silently.
            '_product_sources' => $items->map(fn (OrderItem $item) => $item->product_id)->all(),
        ];
    }

    /** @param array<string,mixed> $record */
    public function locate(array $record): ?Model
    {
        $number = $this->requireKey($record, 'order_number', 'order');

        return Order::withTrashed()->where('order_number', $number)->first();
    }

    /**
     * @param  array<string,mixed>  $record
     * @param  Order|null  $existing
     */
    public function write(array $record, ?Model $existing): Model
    {
        $number = $this->requireKey($record, 'order_number', 'order');
        $order  = $existing ?? new Order();

        $order->fill([
            'order_number'       => $number,
            'status'             => $record['status'] ?? Order::STATUS_PENDING,
            'payment_status'     => $record['payment_status'] ?? Order::PAYMENT_UNPAID,
            'fulfillment_status' => $record['fulfillment_status'] ?? Order::FULFILLMENT_UNFULFILLED,
            'currency'           => $record['currency'] ?? 'MYR',
            'subtotal'           => $record['subtotal'] ?? 0,
            'discount_total'     => $record['discount_total'] ?? 0,
            'shipping_total'     => $record['shipping_total'] ?? 0,
            'tax_total'          => $record['tax_total'] ?? 0,
            'grand_total'        => $record['grand_total'] ?? 0,
            'shipping_method_id' => $this->resolveShippingMethod($record),
            'shipping_method_name' => $record['shipping_method_name'] ?? null,
            'notes'              => $record['notes'] ?? null,
            'meta'               => $this->mergedMeta($record, $existing),
            'user_id'            => $this->resolveCustomer($record),
        ]);

        if (! empty($record['placed_at'])) {
            $order->created_at = $record['placed_at'];
        }

        $order->deleted_at = null;
        $order->save();

        $this->syncItems($order, $record);
        $this->syncAddresses($order, $record);

        return $order;
    }

    /**
     * A colliding order takes the destination's next number **in sequence** — `ORD-` plus
     * `max(id) + 1`, advancing past numbers already taken, exactly as core numbers a new order.
     * The number it arrived under goes into `meta.imported_number`; the customer's confirmation
     * email still shows it, and reconciling the two sites needs the pair.
     *
     * @param  array<string,mixed>  $record
     * @return array<string,mixed>
     */
    public function renameForCollision(array $record): array
    {
        $wanted = trim((string) ($record['order_number'] ?? ''));

        $record['order_number'] = $this->nextNumberInSequence(
            'ORD',
            (int) (Order::withTrashed()->orderByDesc('id')->value('id') ?? 0),
            static fn (string $candidate) => Order::withTrashed()
                ->where('order_number', $candidate)
                ->exists()
        );

        if ($wanted !== '') {
            $meta = (array) ($record['meta'] ?? []);
            $meta['imported_number'] = $wanted;
            $record['meta'] = $meta;
        }

        return $record;
    }

    /**
     * The next free number in a `PREFIX-000000` sequence — shared with the invoice driver's
     * numbering, bounded like {@see Collision::freeKey()}, with the same never-drop last resort.
     *
     * @param  callable(string): bool  $taken
     */
    protected function nextNumberInSequence(string $prefix, int $lastId, callable $taken): string
    {
        for ($offset = 0; $offset < 200; $offset++) {
            $candidate = $prefix . '-' . str_pad((string) ($lastId + 1 + $offset), 6, '0', STR_PAD_LEFT);

            if (! $taken($candidate)) {
                return $candidate;
            }
        }

        return Collision::freeKey($prefix . '-' . str_pad((string) ($lastId + 1), 6, '0', STR_PAD_LEFT), $taken);
    }

    /** `_product_sources` is the source database's ids for the items — plumbing, not content. */
    public function volatileFields(): array
    {
        return array_merge(parent::volatileFields(), ['_product_sources']);
    }

    /** Notes are free text and meta carries tracking and adjustments — both can embed URLs. */
    public function rewritableFields(): array
    {
        return ['notes', 'meta'];
    }

    /**
     * One address as the fields it holds, or null.
     *
     * Never the morph columns and never the id: where the row was attached on the source — the
     * customer's address book or the order itself — is that install's business, and the id is
     * that database's.
     *
     * @return array<string,mixed>|null
     */
    private function addressToArray(?Address $address): ?array
    {
        if ($address === null) {
            return null;
        }

        $out = [];

        foreach (self::ADDRESS_FIELDS as $field) {
            $out[$field] = $field === 'is_billing'
                ? (bool) $address->{$field}
                : $address->{$field};
        }

        return $out;
    }

    /** @param array<string,mixed> $record */
    private function resolveCustomer(array $record): ?int
    {
        $email = trim((string) ($record['customer'] ?? ''));

        if ($email === '') {
            return null;
        }

        $id = User::query()->where('email', $email)->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * The live shipping method this order used, where one matches by slug.
     *
     * Null is the common and correct outcome for a migrated order: the column is nullable with
     * `nullOnDelete` precisely because methods come and go, and `shipping_method_name` — the
     * snapshot — is what the order actually says the customer chose.
     *
     * @param  array<string,mixed>  $record
     */
    private function resolveShippingMethod(array $record): ?int
    {
        $slug = trim((string) ($record['shipping_method'] ?? ''));

        if ($slug === '') {
            return null;
        }

        $method = $this->locateBySlug(ShippingMethod::class, $slug);

        return $method === null ? null : (int) $method->getKey();
    }

    /**
     * The travelling copy of the order's meta, without this package's own marker.
     *
     * @param  array<string,mixed>|null  $meta
     * @return array<string,mixed>|null
     */
    private function portableMeta(?array $meta): ?array
    {
        if ($meta === null) {
            return null;
        }

        $portable = array_diff_key($meta, array_flip(self::LOCAL_META));

        return $portable === [] ? null : $portable;
    }

    /**
     * The meta to store: what travelled, keeping the local marker an overwrite would lose.
     *
     * @param  array<string,mixed>  $record
     * @param  Order|null  $existing
     * @return array<string,mixed>|null
     */
    private function mergedMeta(array $record, ?Model $existing): ?array
    {
        $meta = (array) ($record['meta'] ?? []);

        foreach (self::LOCAL_META as $key) {
            if (! array_key_exists($key, $meta) && isset($existing?->meta[$key])) {
                $meta[$key] = $existing->meta[$key];
            }
        }

        return $meta === [] ? null : $meta;
    }

    /**
     * Replace the order's lines with the bundle's, resolving each line's product as it lands.
     *
     * Delete-and-recreate for the same reason the invoice driver does it: lines have no identity
     * of their own, so there is nothing stable to diff on, and core's own `syncItems` answered
     * the question the same way. The product link resolves through the id map first — the only
     * rename-proof answer — and the SKU second; an item whose product resolves to nothing keeps
     * `product_id` null and its own snapshot columns, which is exactly how core treats an order
     * whose product was deleted.
     *
     * @param  array<string,mixed>  $record
     */
    private function syncItems(Order $order, array $record): void
    {
        $items   = array_values((array) ($record['items'] ?? []));
        $sources = array_values((array) ($record['_product_sources'] ?? []));

        $order->items()->delete();

        foreach ($items as $index => $item) {
            OrderItem::create([
                'order_id'   => $order->getKey(),
                'product_id' => $this->resolveProduct($sources[$index] ?? null, (string) ($item['sku'] ?? '')),
                'sku'        => $item['sku'] ?? null,
                'title'      => $item['title'] ?? null,
                'qty'        => (int) ($item['qty'] ?? 1),
                'unit_price' => $item['unit_price'] ?? 0,
                'discount'   => $item['discount'] ?? 0,
                'tax'        => $item['tax'] ?? 0,
                'subtotal'   => $item['subtotal'] ?? 0,
                'meta'       => $item['meta'] ?? null,
            ]);
        }
    }

    private function resolveProduct(mixed $source, string $sku): ?int
    {
        $mapped = $this->mapped('products', $source);

        if ($mapped !== null) {
            return $mapped;
        }

        $sku = trim($sku);

        if ($sku === '') {
            return null;
        }

        $id = Product::withTrashed()->where('sku', $sku)->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Write the order's addresses as order-attached snapshots and point the order at them.
     *
     * An address row already attached to *this order* is refreshed in place. One attached to
     * anything else — the customer's own address book, which is what checkout links for a
     * signed-in buyer — is **never edited**: the order is repointed at a fresh order-attached
     * snapshot instead, and the address-book row is left exactly as its owner keeps it. Editing
     * a person's saved addresses as a side effect of an import is the failure this guard exists
     * to prevent. Incoming absence leaves whatever is here alone — nothing is dropped, on either
     * side of a migration.
     *
     * @param  array<string,mixed>  $record
     */
    private function syncAddresses(Order $order, array $record): void
    {
        $changed = false;

        foreach (['billing_address' => 'billing_address_id', 'shipping_address' => 'shipping_address_id'] as $field => $column) {
            $incoming = $record[$field] ?? null;

            if (! is_array($incoming) || $incoming === []) {
                continue;
            }

            $address = $order->{$column} !== null ? Address::find($order->{$column}) : null;

            $ownedByThisOrder = $address !== null
                && $address->addressable_type === Order::class
                && (int) $address->addressable_id === (int) $order->getKey();

            if (! $ownedByThisOrder) {
                $address = new Address();
                $address->addressable_type = Order::class;
                $address->addressable_id   = $order->getKey();
            }

            foreach (self::ADDRESS_FIELDS as $key) {
                if (array_key_exists($key, $incoming)) {
                    $address->{$key} = $key === 'is_billing' ? (bool) $incoming[$key] : $incoming[$key];
                }
            }

            $address->save();

            if ($order->{$column} !== (int) $address->getKey()) {
                $order->{$column} = (int) $address->getKey();
                $changed = true;
            }
        }

        if ($changed) {
            $order->save();
        }
    }
}
