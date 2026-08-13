<?php

namespace Plugin\SiteMigration\Backend\Resources;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceTemplate;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Invoices, with their line items. **Opt-in as a record group: these are records about people.**
 *
 * **Identity is the invoice number** — the one thing an accountant, a customer and this package
 * can all agree names *that* invoice. The rest of the row is a snapshot: totals, dates, lines.
 *
 * **A collision takes the destination's next number in sequence, never a suffix.** This is the
 * decision that unblocked the whole record group. `INV-0007-2` is not an invoice number — it sits
 * outside the sequence forever, sorts wrong, and reads as an error in an audit. So a clash is
 * renumbered the way core numbers a new invoice: the next free `INV-XXXXXX` derived from
 * `max(id)`, exactly as `InvoiceRepository::nextInvoiceNumber()` derives it. The customer's copy
 * still shows the old number — that is stated on the import screen rather than hidden, and the
 * number it arrived under is kept in the invoice's own meta so the two can be matched later.
 *
 * **The order reference resolves through the id map first, the order number second.** The map is
 * rename-proof: an order that took this site's next number on the same run is found by where it
 * *landed*, not by the number it no longer holds. The number lookup is the fallback for an order
 * that was already here before this run.
 *
 * **`meta.template_id` is resolved, not carried.** The raw id names a row in the *source's* metas
 * table, which is some unrelated record here — but which design an invoice was rendered with is
 * real information, so it travels as the template's **title** beside the source id, and is
 * resolved the same way everything else is: id map first (rename-proof), natural key second. An
 * invoice whose template did not travel keeps whatever this site already had for it, and a new
 * one simply has no `template_id` — where `InvoicePdfService` falls back to this site's default,
 * which is the correct answer when the design it wanted is genuinely not here.
 */
class InvoiceDriver extends BaseDriver
{
    /**
     * Meta keys that describe this install rather than the invoice.
     *
     * `template_id` points into the local metas table; `imported_number` is this package's own
     * record of the number an invoice arrived under before it was renumbered. Both are stripped
     * from the travelling record — and therefore from the content hash on both sides, or every
     * renumbered invoice would compare as changed on every migration forever.
     */
    private const LOCAL_META = ['template_id', 'imported_number'];

    public function key(): string
    {
        return 'invoices';
    }

    public function label(): string
    {
        return 'Invoices';
    }

    public function naturalKey(): string
    {
        return 'invoice_number';
    }

    public function exportQuery(): Builder
    {
        return Invoice::query()
            ->with(['items', 'order:id,order_number', 'customer:id,email'])
            ->orderBy('id');
    }

    /**
     * @param  Invoice  $record
     * @return array<string,mixed>
     */
    public function toRecord(Model $record): array
    {
        return [
            '_source'        => $record->getKey(),
            'invoice_number' => $record->invoice_number,

            // The order as both halves of the resolution: its source id for the rename-proof map
            // lookup, its number for the fallback. Only the number is content — the id is
            // volatile, because it belongs to whichever database wrote this record.
            '_order_source' => $record->order_id,
            'order'         => $record->order?->order_number,

            'customer' => $record->customer?->email,

            'status'   => $record->status,
            'currency' => $record->currency,

            'issued_at'   => $record->issued_at?->toDateTimeString(),
            'due_at'      => $record->due_at?->toDateTimeString(),
            // When the invoice was raised on the source, carried explicitly because `created_at`
            // is excluded from every bundle record by the canonical form — and for a record
            // group, *when it happened* is content, not housekeeping.
            'recorded_at' => $record->created_at?->toDateTimeString(),

            'subtotal'       => $record->subtotal,
            'discount_total' => $record->discount_total,
            'tax_total'      => $record->tax_total,
            'grand_total'    => $record->grand_total,

            'notes' => $record->notes,
            'meta'  => $this->portableMeta($record->meta),

            // Which design this invoice was rendered with, as both halves of the resolution: the
            // source's meta id for the rename-proof map lookup, the template's title for one that
            // was already here. Both are volatile — the design is a fact about the invoice, but
            // *which row holds it* is a fact about a database.
            '_template_source' => data_get($record->meta, 'template_id'),
            'template'         => $this->templateTitle($record),

            // Sorted by id, which is insertion order on both sides — the relation itself carries
            // no ordering, and an unordered list would make the same invoice hash two ways.
            'items' => $record->items->sortBy('id')->values()->map(fn (InvoiceItem $item) => [
                'sku'        => $item->sku,
                'title'      => $item->title,
                'qty'        => $item->qty,
                'unit_price' => $item->unit_price,
                'discount'   => $item->discount,
                'tax'        => $item->tax,
                'subtotal'   => $item->subtotal,
                'meta'       => $item->meta,
            ])->all(),
        ];
    }

    /** @param array<string,mixed> $record */
    public function locate(array $record): ?Model
    {
        $number = $this->requireKey($record, 'invoice_number', 'invoice');

        // `withTrashed` for the same reason products do it: an invoice the operator soft-deleted
        // here and is importing again is one they are asking for back, and colliding with an
        // invisible row would fail on the unique index naming a record they cannot see.
        return Invoice::withTrashed()->where('invoice_number', $number)->first();
    }

    /**
     * @param  array<string,mixed>  $record
     * @param  Invoice|null  $existing
     */
    public function write(array $record, ?Model $existing): Model
    {
        $number  = $this->requireKey($record, 'invoice_number', 'invoice');
        $invoice = $existing ?? new Invoice();

        $invoice->fill([
            'invoice_number' => $number,
            'order_id'       => $this->resolveOrder($record),
            'status'         => $record['status'] ?? Invoice::STATUS_DRAFT,
            'currency'       => $record['currency'] ?? 'MYR',
            'issued_at'      => $record['issued_at'] ?? null,
            'due_at'         => $record['due_at'] ?? null,
            'subtotal'       => $record['subtotal'] ?? 0,
            'discount_total' => $record['discount_total'] ?? 0,
            'tax_total'      => $record['tax_total'] ?? 0,
            'grand_total'    => $record['grand_total'] ?? 0,
            'notes'          => $record['notes'] ?? null,
            'meta'           => $this->mergedMeta($record, $existing),
            'user_id'        => $this->resolveCustomer($record),
        ]);

        // When it was raised is content here, not housekeeping — an imported invoice dated today
        // would file a year of paperwork under the day of the migration.
        if (! empty($record['recorded_at'])) {
            $invoice->created_at = $record['recorded_at'];
        }

        $invoice->deleted_at = null;
        $invoice->save();

        $this->syncItems($invoice, (array) ($record['items'] ?? []));

        return $invoice;
    }

    /**
     * A colliding invoice takes the destination's next number **in sequence**.
     *
     * Derived exactly the way core derives a new invoice's number — `INV-` plus `max(id) + 1`,
     * advancing past numbers already taken — so the renumbered invoice is indistinguishable from
     * one this site raised itself. A `-2` suffix would be visible forever: invoice numbering is a
     * sequence, not a label, and a number outside the sequence reads as an error in an audit.
     *
     * The number it arrived under goes into `meta.imported_number`, because the customer's copy
     * still shows it and somebody reconciling the two sites needs the pair.
     *
     * @param  array<string,mixed>  $record
     * @return array<string,mixed>
     */
    public function renameForCollision(array $record): array
    {
        $wanted = trim((string) ($record['invoice_number'] ?? ''));

        $record['invoice_number'] = $this->nextNumberInSequence(
            'INV',
            (int) (Invoice::withTrashed()->orderByDesc('id')->value('id') ?? 0),
            static fn (string $candidate) => Invoice::withTrashed()
                ->where('invoice_number', $candidate)
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
     * The next free number in a `PREFIX-000000` sequence.
     *
     * Bounded like {@see Collision::freeKey()}, and with the same last resort: past the bound,
     * an ugly-but-free key beats the one outcome this package exists to prevent, which is losing
     * the record.
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

    /**
     * The source database's ids for the order and the template are plumbing, not content.
     *
     * **`template` is volatile too, and that is the subtle one.** The design an invoice was
     * rendered with is real information and worth carrying, but a destination that does not have
     * that template — or that renders through its own default — would otherwise differ from the
     * bundle in this one field and rewrite every invoice on every migration to change nothing.
     * The same shape `AssetDriver` documents for `usage`: a difference the write path will never
     * act on must not be part of the comparison.
     */
    public function volatileFields(): array
    {
        return array_merge(parent::volatileFields(), ['_order_source', '_template_source', 'template']);
    }

    /** Notes are free text and meta carries tax lines — both can embed source-host URLs. */
    public function rewritableFields(): array
    {
        return ['notes', 'meta'];
    }

    /**
     * The order this invoice bills, as a local id.
     *
     * Map first — rename-proof, finds an order that took this site's next number on the same run —
     * then the order number, for an order that was already here. Null when neither answers:
     * `order_id` is nullable by design, and an invoice is still an invoice without its order.
     *
     * @param  array<string,mixed>  $record
     */
    private function resolveOrder(array $record): ?int
    {
        $mapped = $this->mapped('orders', $record['_order_source'] ?? 0);

        if ($mapped !== null) {
            return $mapped;
        }

        $number = trim((string) ($record['order'] ?? ''));

        if ($number === '') {
            return null;
        }

        $id = Order::withTrashed()->where('order_number', $number)->value('id');

        return $id === null ? null : (int) $id;
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
     * The travelling copy of an invoice's meta, without the keys that describe this install.
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
     * The meta to store: what travelled, plus the local keys the bundle deliberately does not
     * carry — the renumbered invoice's original number, and a template id resolved to *this*
     * install's rows.
     *
     * @param  array<string,mixed>  $record
     * @param  Invoice|null  $existing
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

        $template = $this->resolveTemplate($record);

        if ($template !== null) {
            $meta['template_id'] = $template;
        }

        return $meta === [] ? null : $meta;
    }

    /**
     * The local id of the design this invoice was rendered with, or null to leave it alone.
     *
     * Map first — so an imported template that took a free title still receives its own invoices —
     * then the title, for a design that was already here. Null when neither answers, which leaves
     * an overwritten invoice pointing at whatever it pointed at before and a new one pointing at
     * nothing: `InvoicePdfService` then falls back to this site's default, which is the honest
     * answer when the design is genuinely absent.
     *
     * @param  array<string,mixed>  $record
     */
    private function resolveTemplate(array $record): ?int
    {
        $mapped = $this->mapped('invoice_templates', $record['_template_source'] ?? 0);

        if ($mapped !== null) {
            return $mapped;
        }

        $title = $this->firstTranslation($record['template'] ?? null);

        if ($title === null) {
            return null;
        }

        $template = $this->locateByTranslatable(InvoiceTemplate::class, 'title', $title);

        return $template === null ? null : (int) $template->getKey();
    }

    /**
     * Template id => its title, loaded once for the whole walk.
     *
     * @var array<int,array<string,string>|string|null>|null
     */
    private ?array $templateTitles = null;

    /**
     * The title of the template an invoice names, read without assuming the row still exists.
     *
     * **Not `Invoice::template()`, and the difference is a rule rather than a preference.** That
     * method is a plain `where('id', …)->first()` — one query per invoice, so a shop with four
     * thousand invoices would pay four thousand extra queries to write four thousand lines. It
     * cannot be eager-loaded either, because it is a method rather than a relation, and
     * `exportQuery()` has nowhere to declare it.
     *
     * A site has a handful of invoice templates, never thousands, so the whole set is read once
     * and answered from memory — the same shape `AssetDriver` uses for content hashes and
     * `RewritePass` for asset paths. An invoice naming a template since deleted answers null,
     * which is ordinary: an invoice outlives its design.
     *
     * @return array<string,string>|string|null
     */
    private function templateTitle(Invoice $record): array|string|null
    {
        $id = (int) data_get($record->meta, 'template_id');

        if ($id <= 0) {
            return null;
        }

        if ($this->templateTitles === null) {
            $this->templateTitles = InvoiceTemplate::query()
                ->get(['id', 'title'])
                ->mapWithKeys(fn (InvoiceTemplate $t) => [
                    (int) $t->getKey() => $this->translations($t, 'title'),
                ])
                ->all();
        }

        return $this->templateTitles[$id] ?? null;
    }

    /**
     * Replace the invoice's lines with the bundle's, matching core's own `syncItems` semantics.
     *
     * Delete-and-recreate rather than a diff: lines have no identity of their own — two identical
     * `Blue hat × 2` lines are legitimately two lines — so there is nothing stable to match a
     * diff on, and core's `InvoiceRepository::syncItems()` already answered this question the
     * same way.
     *
     * @param  array<int,array<string,mixed>>  $items
     */
    private function syncItems(Invoice $invoice, array $items): void
    {
        $invoice->items()->delete();

        foreach ($items as $item) {
            InvoiceItem::create([
                'invoice_id' => $invoice->getKey(),
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
}
