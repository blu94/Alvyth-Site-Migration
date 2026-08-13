<?php

namespace Plugin\SiteMigration\Backend\Resources;

use App\Models\Form;
use App\Models\Lead;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Plugin\SiteMigration\Backend\Support\Canonical;

/**
 * Form leads — the submissions, where {@see FormDriver} carries only the definitions.
 * **Opt-in as a record group: a lead is a person who filled in a form**, name, email, message
 * and all, usually with an expectation about who would read it.
 *
 * **Gated by `forms`, not by a `leads` permission that exists nowhere.** Core's `FormController`
 * guards its lead endpoints with the `forms` resource — the same view/delete an operator needs
 * to read or remove a lead on the source — so this driver declares the same, exactly as posts
 * declare `blogs`. Deriving it from the key instead would ask `can('leads.view')`, false for
 * everybody, and the resource would silently never travel.
 *
 * **A lead has no natural key, so identity is the submission itself**: the same form, at the same
 * moment, carrying the same data. Strong enough that re-importing a bundle finds its own records
 * instead of doubling an inbox.
 *
 * **The form resolves through the id map first, its slug second** — `form_id` is the reference
 * that forced the seam widening for this driver — and `leads.form_id` is NOT NULL with a cascade,
 * so a lead whose form is not here is **skipped and says so** rather than attached to somebody
 * else's form.
 *
 * **`data` is never rewritten.** It is a verbatim record of what a person typed; a URL in it that
 * points at the source site is part of their message, and "repairing" it would falsify a
 * submission.
 */
class LeadDriver extends BaseDriver
{
    public function key(): string
    {
        return 'leads';
    }

    public function label(): string
    {
        return 'Form leads';
    }

    public function permissionResource(): string
    {
        return 'forms';
    }

    public function naturalKey(): string
    {
        return 'form + submitted_at + data';
    }

    public function exportQuery(): Builder
    {
        return Lead::query()->with('form:id,slug')->orderBy('id');
    }

    /**
     * @param  Lead  $record
     * @return array<string,mixed>
     */
    public function toRecord(Model $record): array
    {
        return [
            '_source' => $record->getKey(),

            // The form twice over: its slug for forms already here, its source id (volatile —
            // it belongs to the database that wrote it) for the rename-proof map lookup.
            'form'         => $this->firstTranslation($record->form?->slug),
            '_form_source' => $record->form_id,

            'data' => $record->data,

            // Part of the record of the submission, and it travels — nothing is dropped, and
            // core's own GDPR export carries both of these fields too.
            'ip_address' => $record->ip_address,
            'user_agent' => $record->user_agent,

            // When it was submitted: half the identity, and content besides — an inbox of leads
            // all dated the day of the migration would be useless to whoever works through it.
            'submitted_at' => $record->created_at?->toDateTimeString(),
        ];
    }

    /** @param array<string,mixed> $record */
    public function locate(array $record): ?Model
    {
        $formId = $this->resolveForm($record);
        $time   = trim((string) ($record['submitted_at'] ?? ''));

        $candidates = Lead::query()
            ->where('form_id', $formId)
            ->when($time !== '', fn ($q) => $q->where('created_at', $time))
            ->limit(50)
            ->get();

        // Same form, same moment — now the same words. Compared canonically in PHP rather than
        // as JSON in SQL, because two databases will not agree on key order inside a json column.
        $incoming = Canonical::encode(['data' => $record['data'] ?? null]);

        foreach ($candidates as $candidate) {
            if (hash_equals($incoming, Canonical::encode(['data' => $candidate->data]))) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $record
     * @param  Lead|null  $existing
     */
    public function write(array $record, ?Model $existing): Model
    {
        $lead = $existing ?? new Lead();

        $lead->form_id    = $this->resolveForm($record);
        $lead->data       = $record['data'] ?? [];
        $lead->ip_address = $record['ip_address'] ?? null;
        $lead->user_agent = $record['user_agent'] ?? null;

        if (! empty($record['submitted_at'])) {
            $lead->created_at = $record['submitted_at'];
        }

        $lead->save();

        return $lead;
    }

    /** The same submission is one fact about one person, not two things sharing a name. */
    public function mergesOnCollision(): bool
    {
        return true;
    }

    /** There is nothing to rename on a submission — collisions merge instead. */
    public function renameForCollision(array $record): array
    {
        return $record;
    }

    /** The source database's form id is plumbing, not content. */
    public function volatileFields(): array
    {
        return array_merge(parent::volatileFields(), ['_form_source']);
    }

    /**
     * The local id of the form this lead was submitted to.
     *
     * Map first — rename-proof, finds a form this run placed under a suffixed slug — then the
     * slug, for forms that were already here. `form_id` is NOT NULL with a cascade delete, so
     * there is no "landed without its form" outcome: unresolvable means skipped, with the reason.
     *
     * @param  array<string,mixed>  $record
     *
     * @throws SkipRecord
     */
    private function resolveForm(array $record): int
    {
        $mapped = $this->mapped('forms', $record['_form_source'] ?? 0);

        if ($mapped !== null) {
            return $mapped;
        }

        $slug = trim((string) ($record['form'] ?? ''));

        if ($slug !== '') {
            $form = $this->locateBySlug(Form::class, $slug);

            if ($form !== null) {
                return (int) $form->getKey();
            }
        }

        throw new SkipRecord(
            'The form this lead was submitted to is not on this site, so the lead has nowhere to '
            . 'live. Import the forms first, or leave leads out.'
        );
    }
}
