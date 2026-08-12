<?php

namespace Plugin\SiteMigration\Backend\Resources;

use App\Models\EmailTemplate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * The transactional emails: order confirmations, the welcome mail, the password reset.
 *
 * **Identity is `data->key`, and this is the one resource whose key is not a column.** Every
 * template is a row in `metas` with `type = 'EMAIL_TEMPLATE'`, so the six of them are
 * indistinguishable by type; what separates "order confirmation" from "password reset" is a key
 * *inside* the JSON blob. Matching on anything else — the title, the row id — would either merge
 * all six into one or import six duplicates on every run.
 *
 * The wording travels; **who it is sent to does not necessarily belong on another shop**, but
 * recipients live in the same blob and separating them would mean this package deciding which
 * half of a template is configuration. The bundle carries the template as core stores it.
 */
class EmailTemplateDriver extends BaseDriver
{
    public function key(): string
    {
        return 'email_templates';
    }

    public function label(): string
    {
        return 'Email templates';
    }

    /**
     * Gated by `settings`, which is where core puts them.
     *
     * Note that resource has no `.create` verb — only view and update — which is correct for
     * something that is configuration rather than content: the six templates always exist, and
     * writing one is always an update. {@see Permissions::assertMayWrite()} narrows to the verbs a
     * resource actually declares for exactly this case.
     */
    public function permissionResource(): string
    {
        return 'settings';
    }

    public function naturalKey(): string
    {
        return 'key';
    }

    /**
     * The global type scope on the model does the filtering, so this needs no `where`.
     */
    public function exportQuery(): Builder
    {
        return EmailTemplate::query()->orderBy('id');
    }

    /**
     * @param  EmailTemplate  $record
     * @return array<string,mixed>
     */
    public function toRecord(Model $record): array
    {
        $data = (array) $record->data;

        return [
            '_source'     => $record->getKey(),
            'key'         => $data['key'] ?? null,
            'title'       => $this->translations($record, 'title'),
            'subtitle'    => $this->translations($record, 'subtitle'),
            'description' => $this->translations($record, 'description'),
            'status'      => $record->status,
            'data'        => $data,
        ];
    }

    /** @param array<string,mixed> $record */
    public function locate(array $record): ?Model
    {
        $key = $this->keyOf($record);

        // `where('data->key', …)` is the same lookup `EmailTemplate::findByEvent()` uses, taken
        // from core rather than restated, so the two cannot drift about what identifies a template.
        return EmailTemplate::query()->where('data->key', $key)->first();
    }

    /**
     * @param  array<string,mixed>  $record
     * @param  EmailTemplate|null  $existing
     */
    public function write(array $record, ?Model $existing): Model
    {
        $key = $this->keyOf($record);

        $template = $existing ?? new EmailTemplate();

        $data = (array) ($record['data'] ?? []);

        // Forced rather than trusted: a bundle whose blob lost its key would otherwise write a
        // template that no lookup can ever find again, and nothing would report it.
        $data['key'] = $key;

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
     * @param  array<string,mixed>  $record
     *
     * @throws RuntimeException
     */
    private function keyOf(array $record): string
    {
        $key = $this->firstTranslation($record['key'] ?? ($record['data']['key'] ?? null));

        if ($key === null) {
            throw new RuntimeException(
                'This email template has no key, so there is no way to tell which message it is. '
                . 'It cannot be placed on this site.'
            );
        }

        return $key;
    }

    /** A template body carries images and links to pages on the source install. */
    public function rewritableFields(): array
    {
        return ['data', 'description'];
    }
}
