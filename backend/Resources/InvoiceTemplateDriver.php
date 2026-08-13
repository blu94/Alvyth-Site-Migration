<?php

namespace Plugin\SiteMigration\Backend\Resources;

use App\Models\InvoiceTemplate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The designed layout an invoice is rendered with — paper size, colours, logo, the lot.
 *
 * **This is presentation the operator authored**, which is why it travels: a shop that designed
 * its invoice and then migrated would otherwise arrive with core's seeded default and no way to
 * tell what happened, and every invoice it issued afterwards would look like somebody else's.
 * The original specification (§3.1) listed invoice templates beside email templates and email
 * branding from the start; this driver is that promise, kept late.
 *
 * **Identity is the title, because there is nothing else.** An invoice template is a `Meta` row
 * under the STI type `INVOICE_TEMPLATE` — no slug column, no key inside the blob (unlike an email
 * template, whose `data->key` says which message it is). The title is what the operator typed and
 * what the picker shows them, so it is the only identifier that means the same thing on two
 * installs.
 *
 * **Which template is "main" is a decision about *this* site, and an import must not make it.**
 * Exactly the posture {@see ThemeDriver} takes on activation: a data migration must not change how
 * somebody's paperwork looks as a side effect. So `data->is_default` never travels inside the
 * blob — it is lifted out, carried as a plain field, marked volatile so it cannot make a template
 * read as changed, and honoured on the way in **only when this site has no main template of its
 * own yet**. A fresh destination therefore inherits the source's choice; an established one keeps
 * its own.
 *
 * **Written on the model rather than through `InvoiceTemplateRepository`.** The rule that sends
 * {@see SettingsDriver} the long way round is about the eight `rememberForever` settings keys —
 * a write that bypassed the repository would serve a stale value forever. No such cache exists
 * here: `getDefaultTemplate()` is a plain query every time. What the repository *would* add is
 * `clearOtherDefaults()`, which is precisely the behaviour this driver must not perform blindly,
 * so going through it would mean an import silently demoting the template this site chose.
 */
class InvoiceTemplateDriver extends BaseDriver
{
    public function key(): string
    {
        return 'invoice_templates';
    }

    public function label(): string
    {
        return 'Invoice templates';
    }

    /**
     * Gated by `invoices`, which is the resource core itself chose for these screens.
     *
     * `TemplateController::middleware()` records why in a comment worth repeating: there is no
     * `invoice_templates` key in `config/settings.php`, so naming one would resolve to
     * `super_admin` alone and lock out every admin. Deriving the permission from this driver's key
     * would ask `can('invoice_templates.view')` — false for everybody — and the resource would be
     * **silently dropped from every export**, which is the trap `DriverSymmetryTest` exists to
     * catch.
     */
    public function permissionResource(): string
    {
        return 'invoices';
    }

    public function naturalKey(): string
    {
        return 'title';
    }

    /** The model's global scope filters to `INVOICE_TEMPLATE`, so this needs no `where`. */
    public function exportQuery(): Builder
    {
        return InvoiceTemplate::query()->orderBy('id');
    }

    /**
     * @param  InvoiceTemplate  $record
     * @return array<string,mixed>
     */
    public function toRecord(Model $record): array
    {
        $data = (array) $record->data;

        return [
            '_source'     => $record->getKey(),
            'title'       => $this->translations($record, 'title'),
            'subtitle'    => $this->translations($record, 'subtitle'),
            'description' => $this->translations($record, 'description'),
            'status'      => $record->status,

            // The whole design — paper size, colours, the logo's asset id — minus the one key that
            // is a statement about this install rather than about the design.
            'data' => $this->withoutDefaultFlag($data),

            // Carried as a field of its own so the destination can see what the source chose,
            // while the hash ignores it. Left inside `data` it would have made every template
            // whose designation differs read as changed on every migration, forever — the same
            // silent shape `AssetDriver::volatileFields()` documents for `usage`.
            'is_default' => (bool) ($data['is_default'] ?? false),
        ];
    }

    /** @param array<string,mixed> $record */
    public function locate(array $record): ?Model
    {
        return $this->locateByTranslatable(
            InvoiceTemplate::class,
            'title',
            $this->requireKey($record, 'title', 'invoice template')
        );
    }

    /**
     * @param  array<string,mixed>  $record
     * @param  InvoiceTemplate|null  $existing
     */
    public function write(array $record, ?Model $existing): Model
    {
        $this->requireKey($record, 'title', 'invoice template');

        $template = $existing ?? new InvoiceTemplate();

        $data = $this->withoutDefaultFlag((array) ($record['data'] ?? []));

        // The designation is kept if this template already held it, and adopted from the bundle
        // only when nothing here claims it. Never taken from a template that has one.
        $data['is_default'] = $this->shouldBeDefault($record, $existing);

        $template->fill($this->withoutNullTranslations([
            'title'       => $record['title'] ?? null,
            'subtitle'    => $record['subtitle'] ?? null,
            'description' => $record['description'] ?? null,
            'status'      => $record['status'] ?? 'active',
            'data'        => $data,
        ]));

        $template->save();

        return $template;
    }

    /**
     * Two templates sharing a title are two designs, so the incoming one takes a free title.
     *
     * **Overridden because the inherited version keys on `slug`**, and a `metas` row has no such
     * column — the default would have searched a column that does not exist. A template is also
     * genuinely not a singleton, unlike the settings group or the email template beside it in the
     * bundle: a shop may keep several designs and use whichever it likes.
     *
     * @param  array<string,mixed>  $record
     * @return array<string,mixed>
     */
    public function renameForCollision(array $record): array
    {
        $record['title'] = Collision::freeTranslatedKey(
            $record['title'] ?? null,
            fn (string $candidate) => $this->locateByTranslatable(
                InvoiceTemplate::class,
                'title',
                $candidate
            ) !== null
        );

        return $record;
    }

    /** A second design with the same name is a second design, not the same one. */
    public function mergesOnCollision(): bool
    {
        return false;
    }

    /**
     * `is_default` is a statement about this install, not about the design — see the class
     * docblock. `orders` is already volatile through the parent for the same reason: it is where
     * the operator put this template in *their* list.
     */
    public function volatileFields(): array
    {
        return array_merge(parent::volatileFields(), ['is_default']);
    }

    /** The design blob carries the logo's asset id and absolute URLs into the source install. */
    public function rewritableFields(): array
    {
        return ['data'];
    }

    /**
     * Whether the written template should hold this site's "main" designation.
     *
     * Three cases, and the middle one is the point: an established site keeps whatever it already
     * chose, and only a site with no main template at all inherits the source's.
     *
     * @param  array<string,mixed>  $record
     * @param  InvoiceTemplate|null  $existing
     */
    private function shouldBeDefault(array $record, ?Model $existing): bool
    {
        if ($existing !== null && ! empty($existing->data['is_default'])) {
            return true;
        }

        if (! (bool) ($record['is_default'] ?? false)) {
            return false;
        }

        // Adopted only when nothing here claims it. `InvoiceTemplateRepository::getDefaultTemplate()`
        // falls back to the first active template by sort order when no row is designated, so a
        // site that declines the designation still renders invoices — it simply keeps deciding for
        // itself.
        return ! InvoiceTemplate::query()
            ->when(
                $existing !== null,
                fn ($q) => $q->where('id', '!=', $existing->getKey())
            )
            ->where('data->is_default', true)
            ->exists();
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function withoutDefaultFlag(array $data): array
    {
        unset($data['is_default']);

        return $data;
    }
}
