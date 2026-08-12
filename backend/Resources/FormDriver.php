<?php

namespace Plugin\SiteMigration\Backend\Resources;

use App\Models\Form;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Forms — the definitions, never the submissions.
 *
 * A form is configuration the operator authored: which fields it has, what it validates, where it
 * sends. Its **leads** are records about people who filled it in, which is a different admission
 * test entirely — they are opt-in and warned about, and they are not this driver's business.
 */
class FormDriver extends BaseDriver
{
    public function key(): string
    {
        return 'forms';
    }

    public function label(): string
    {
        return 'Forms';
    }

    public function naturalKey(): string
    {
        return 'slug';
    }

    public function exportQuery(): Builder
    {
        return Form::query()->orderBy('id');
    }

    /**
     * @param  Form  $record
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

            // The field definitions, validation and delivery configuration — the whole form,
            // carried as the blob core stores it as rather than picked apart into columns this
            // package would then have to keep in step with.
            'settings' => $record->settings,
            'status'   => $record->status,
        ];
    }

    /** @param array<string,mixed> $record */
    public function locate(array $record): ?Model
    {
        return $this->locateBySlug(Form::class, $this->requireKey($record, 'slug', 'form'));
    }

    /**
     * @param  array<string,mixed>  $record
     * @param  Form|null  $existing
     */
    public function write(array $record, ?Model $existing): Model
    {
        $this->requireKey($record, 'slug', 'form');

        $form = $existing ?? new Form();

        $form->fill($this->withoutNullTranslations([
            'slug'        => $record['slug'] ?? null,
            'title'       => $record['title'] ?? null,
            'subtitle'    => $record['subtitle'] ?? null,
            'description' => $record['description'] ?? null,
            'settings'    => $record['settings'] ?? null,
            'status'      => $record['status'] ?? 'active',
        ]));

        $form->deleted_at = null;
        $form->save();

        return $form;
    }

    /** A form's settings can name a thank-you page or an image by id. */
    public function rewritableFields(): array
    {
        return ['settings', 'description'];
    }
}
