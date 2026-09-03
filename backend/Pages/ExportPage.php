<?php

namespace Plugin\SiteMigration\Backend\Pages;

use Plugin\SiteMigration\Backend\Bundle\CredentialVault;
use Plugin\SiteMigration\Backend\Bundle\Download;
use Plugin\SiteMigration\Backend\Resources\DriverRegistry;
use Plugin\SiteMigration\Backend\Runs\Run;
use Plugin\SiteMigration\Backend\Runs\RunStore;
use Plugin\SiteMigration\Backend\Services\Exporter;
use Plugin\SiteMigration\Backend\Support\Bytes;
use Plugin\SiteMigration\Backend\Support\Permissions;
use RuntimeException;

/**
 * The export wizard: leave out anything you do not want, press once, download when it finishes.
 *
 * **The selection is an exclusion, and that inversion is the design.** An operator asked to *choose*
 * what travels has to know the answer in advance, and anything they forget is missing on the
 * destination with nothing to say so. Asked what to *leave out*, the failure mode inverts: forget
 * something and it travels anyway. It also means a resource added in a later version is carried by
 * every existing habit rather than quietly omitted from it.
 *
 * **Why the picker is an `autocomplete`, not a `permissions` matrix.** The specification called for
 * the `permissions` field type on the grounds that it renders a grouped matrix of checkboxes. It
 * does — but `PermissionsField.vue` ignores `element.options` entirely and calls
 * `fetchPermissionsConfig()` on mount, so it would render this site's real permission list and hand
 * back permission names. There is no way to feed it a list of resources.
 */
class ExportPage
{
    public function __construct(
        private readonly RunStore $runs,
        private readonly DriverRegistry $drivers,
        private readonly Exporter $exporter,
        private readonly Download $download,
    ) {
    }

    /** @return array<string,mixed> */
    public function data(): array
    {
        // Any download link older than the window goes now, whoever opened the screen.
        $this->download->sweep();

        $run  = $this->runs->latest(Run::DIRECTION_EXPORT);
        $last = $this->runs->lastSelection() ?? [];

        return [
            'exclude'       => $this->remembered($last),
            'include_media' => (bool) ($last['include_media'] ?? true),

            // **Never remembered as on**, for the same reason credentials are not: it is a decision
            // about running somebody else's code, not a convenience.
            'include_code'  => false,

            // **Never remembered as on.** Every other choice is a convenience; this one is a
            // security decision, and a form that came up pre-ticked would let an operator export
            // their gateway keys by pressing a button they pressed last week for other reasons.
            'include_credentials' => false,
            'passphrase'          => '',
            'passphrase_confirm'  => '',

            'run_id'       => $run?->id,
            'progress'     => $this->progress($run),
            'errors_text'  => $this->errors($run),
            'can_download' => $run !== null && $run->bundlePath() !== null,
            'download_url' => $run === null ? null : ($this->download->url($run) ?? 'Not published. Press Download.'),
        ];
    }

    /**
     * Start a run, or continue the one that is waiting.
     *
     * **One button does both**, because a schema action fires once and does not iterate — there is
     * no client loop to distinguish "start" from "next". Which of the two this is depends on
     * whether the posted `run_id` names an unfinished run, and getting that wrong in the other
     * direction is the expensive one: treating a Continue as a fresh start would walk the site
     * again from the top.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    public function save(array $data): array
    {
        Permissions::assertMayRun();

        // Validated before anything is created. Doing this afterwards left a `pending` run behind
        // every time somebody mistyped the confirmation — precisely the case the double entry
        // exists to catch, so the orphan was guaranteed to happen to the people the check is for.
        $this->assertPassphrasePair($data);

        $run = $this->runs->find($data['run_id'] ?? null);

        if ($run === null || $run->isFinished()) {
            $excluded = array_values(array_filter(
                (array) ($data['exclude'] ?? []),
                fn ($key) => is_string($key) && $this->drivers->has($key)
            ));

            // Code packages are asked for, never assumed. See DriverRegistry::CODE_GROUPS.
            $includeCode = (bool) ($data['include_code'] ?? false);

            $included = $this->drivers->everythingExcept($excluded, $includeCode);

            if ($included === []) {
                return ['message' => 'You have excluded everything, so there would be nothing in the bundle.'];
            }

            $run = $this->runs->create(Run::DIRECTION_EXPORT, [
                'modules' => $included,
                'exclude' => $excluded,

                // Defaults on. A bundle whose images did not travel leaves the imported site
                // pointing at this one, which is a dependency the operator has to opt into
                // knowingly rather than discover when this hosting is cancelled.
                'include_media' => ! array_key_exists('include_media', $data)
                    || (bool) $data['include_media'],

                'include_credentials' => (bool) ($data['include_credentials'] ?? false),
                'include_code'        => $includeCode,
            ]);

            // Remembered here rather than on completion, so a run the operator abandons still
            // leaves them the selection they were building.
            $this->runs->rememberSelection($run->selection());
        }

        // Held in memory for this press only — `withPassphrase()` never touches the state file.
        $run->withPassphrase((string) ($data['passphrase'] ?? ''));

        $this->exporter->step($run);

        return $this->respond($this->runs->find($run->id));
    }

    /**
     * Publish the finished bundle so the browser can fetch it.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    public function publish(array $data): array
    {
        Permissions::assertMayRun();

        $run = $this->runs->find($data['run_id'] ?? null) ?? $this->runs->latest(Run::DIRECTION_EXPORT);

        if ($run === null) {
            throw new RuntimeException('There is no finished export to download yet.');
        }

        $url = $this->download->publish($run);

        return $this->respond($this->runs->find($run->id)) + ['download_url' => $url];
    }

    /**
     * Everything the screen rebinds after a press.
     *
     * **No `message` key, deliberately.** The custom-page host merges a response back into the bound
     * model only when it carries none, so returning the sentence as a toast means the screen never
     * rebinds and the operator watches a stale progress line while the bundle is written behind
     * them. The sentence goes in `progress`, where it also survives a reload.
     *
     * @return array<string,mixed>
     */
    private function respond(?Run $run): array
    {
        return [
            'run_id'       => $run?->id,
            'progress'     => $this->progress($run),
            'errors_text'  => $this->errors($run),
            'can_download' => $run !== null && $run->bundlePath() !== null,
            'download_url' => $run === null ? null : ($this->download->url($run) ?? 'Not published. Press Download.'),
        ];
    }

    /**
     * A remembered exclusion list, narrowed to resources that still exist.
     *
     * A list saved before an upgrade can name a driver this version no longer has. Replaying it
     * unchecked would carry a dead key into a run — harmless for an exclusion, but it would also
     * show the operator a chip for something that is not there.
     *
     * @param  array<string,mixed>  $last
     * @return array<int,string>
     */
    private function remembered(array $last): array
    {
        return array_values(array_filter(
            (array) ($last['exclude'] ?? []),
            fn ($key) => is_string($key) && $this->drivers->has($key)
        ));
    }

    /**
     * Refuse a mistyped passphrase before anything is sealed under it.
     *
     * **Checked here rather than in the schema**, because no validation rule can express "required,
     * and equal to that other field, but only when this switch is on" — and the consequence of
     * getting it wrong is unusually bad. A passphrase is not stored anywhere, so a typo is not
     * recoverable: the bundle would be sealed under a string nobody knows, and the operator would
     * find out on the destination, having already carried the file there.
     *
     * @param  array<string,mixed>  $data
     *
     * @throws RuntimeException
     */
    private function assertPassphrasePair(array $data): void
    {
        if ((bool) ($data['include_credentials'] ?? false) !== true) {
            return;
        }

        $passphrase = (string) ($data['passphrase'] ?? '');
        $confirm    = (string) ($data['passphrase_confirm'] ?? '');

        if ($passphrase === '' && $confirm === '') {
            // Empty on a Continue press is not an error — the operator may have navigated back.
            // The exporter records that credentials were left out.
            return;
        }

        if (! hash_equals($passphrase, $confirm)) {
            throw new RuntimeException(
                'The two passphrases do not match. Nothing has been exported. Since the passphrase '
                . 'is never stored, a typo here would seal your credentials under a string nobody '
                . 'knows — which is why it is asked for twice.'
            );
        }

        if (strlen($passphrase) < CredentialVault::MIN_PASSPHRASE) {
            throw new RuntimeException(sprintf(
                'The passphrase must be at least %d characters. Once the bundle leaves this server '
                . 'it is the only thing protecting your gateway keys, and whoever holds the file '
                . 'can guess at it for as long as they like.',
                CredentialVault::MIN_PASSPHRASE
            ));
        }
    }

    private function progress(?Run $run): string
    {
        if ($run === null) {
            return 'No export has been run on this site yet.';
        }

        $tally = $run->tally();

        return match ($run->status()) {
            Run::STATUS_COMPLETED => sprintf(
                'Finished %s — %s records, %s. Press Download to save it to your computer.',
                $run->get('finished_at', ''),
                number_format($tally['created']),
                $this->size($run)
            ),
            Run::STATUS_PAUSED => sprintf(
                'Paused with %s records written. Press Export again to carry on.',
                number_format($tally['created'])
            ),
            Run::STATUS_FAILED  => 'The last export failed. The messages below say why.',
            Run::STATUS_RUNNING => 'Running.',
            default             => 'Waiting to start.',
        };
    }

    private function size(Run $run): string
    {
        $bytes = (int) $run->get('bundle_bytes', 0);

        return $bytes <= 0 ? 'size unknown' : Bytes::human($bytes);
    }

    private function errors(?Run $run): string
    {
        $errors = (array) $run?->get('errors', []);

        return $errors === [] ? '' : implode("\n", $errors);
    }
}
