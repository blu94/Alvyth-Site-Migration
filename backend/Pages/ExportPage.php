<?php

namespace Plugin\SiteMigration\Backend\Pages;

use Plugin\SiteMigration\Backend\Bundle\CredentialVault;
use Plugin\SiteMigration\Backend\Resources\DriverRegistry;
use Plugin\SiteMigration\Backend\Runs\Run;
use Plugin\SiteMigration\Backend\Runs\RunStore;
use Plugin\SiteMigration\Backend\Services\Exporter;
use Plugin\SiteMigration\Backend\Support\Permissions;

/**
 * The export wizard: tick what travels, press once, press Continue until it finishes.
 *
 * **Why the module picker is an `autocomplete`, not a `permissions` matrix.** The specification
 * called for the `permissions` field type on the grounds that it "already renders a grouped
 * matrix of checkboxes, which is precisely the shape of tick the modules to include". It does
 * render that — but `PermissionsField.vue` **ignores `element.options` entirely** and calls
 * `fetchPermissionsConfig()` on mount, so it would render this site's real permission list and
 * hand back permission names. There is no way to feed it a list of resources. A multiple
 * `autocomplete` over a static option list is the closest thing in the closed field set that
 * actually yields the selection back.
 */
class ExportPage
{
    public function __construct(
        private readonly RunStore $runs,
        private readonly DriverRegistry $drivers,
        private readonly Exporter $exporter,
    ) {
    }

    /** @return array<string,mixed> */
    public function data(): array
    {
        $run = $this->resumable();

        return [
            // Content defaults to everything; record groups default to nothing. That asymmetry
            // is the whole point of splitting them.
            'modules'        => $this->drivers->contentKeys(),
            'module_options' => $this->drivers->options($this->drivers->contentKeys()),
            'records'        => [],
            'record_options' => $this->drivers->options($this->drivers->recordKeys()),
            'on_conflict'         => 'skip',
            'include_media'       => true,
            'include_credentials' => false,
            'passphrase'          => '',
            'passphrase_confirm'  => '',
            'run_id'        => $run?->id,
            'progress'      => $this->progress($run),
            'bundle_path'   => $run?->bundlePath() === null ? null : $this->relative($run),
            'can_continue'  => $run !== null && $run->status() === Run::STATUS_PAUSED,
            'errors_text'   => $this->errors($run),
        ];
    }

    /**
     * Start a run, or continue the one that is waiting.
     *
     * **One button does both**, because a schema `request`/`save` action fires once and does not
     * iterate — there is no client loop to distinguish "start" from "next". Which of the two this
     * is depends on whether the posted `run_id` names a paused run, and getting that wrong in the
     * other direction is the expensive one: treating a Continue as a fresh start would walk the
     * catalogue again from the top and write every record twice into the same staging file.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    public function save(array $data): array
    {
        Permissions::assertMayRun();

        $run = $this->runs->find($data['run_id'] ?? null);

        if ($run === null || $run->isFinished()) {
            $modules = array_values(array_filter(
                (array) ($data['modules'] ?? []),
                fn ($key) => is_string($key) && $this->drivers->has($key)
            ));

            if ($modules === []) {
                return ['message' => 'Tick at least one thing to include, then press Export.'];
            }

            $run = $this->runs->create(Run::DIRECTION_EXPORT, [
                'modules'     => $modules,
                'records'     => array_values(array_filter(
                    (array) ($data['records'] ?? []),
                    fn ($key) => is_string($key) && in_array($key, $this->drivers->recordKeys(), true)
                )),
                'on_conflict' => ($data['on_conflict'] ?? 'skip') === 'overwrite' ? 'overwrite' : 'skip',

                // Defaults **on**. A bundle whose images did not travel leaves the imported site
                // pointing at this one, which is a dependency the operator has to opt into
                // knowingly rather than discover when this hosting is cancelled.
                'include_media' => ! array_key_exists('include_media', $data)
                    || (bool) $data['include_media'],

                // Defaults **off**, and stays a deliberate act. The switch carries one specific
                // warning rather than a generic caution, because the risk is specific: copying
                // live gateway keys to a staging site means staging can charge real cards.
                'include_credentials' => (bool) ($data['include_credentials'] ?? false),
            ]);
        }

        $this->assertPassphrasePair($run, $data);

        // Held in memory for this press only. `withPassphrase()` deliberately does not touch the
        // run's state file, so nothing writes it to disk at any point.
        $run->withPassphrase((string) ($data['passphrase'] ?? ''));

        $this->exporter->step($run);

        // Re-read: the exporter wrote state through its own handle, and returning stale progress
        // is how a finished run renders a Continue button.
        $fresh = $this->runs->find($run->id);

        // **No `message` key, deliberately.** The custom-page host merges a response back into the
        // bound model only when it carries none — `if (res && !res.message) data = {...data, ...res}`
        // — so returning the sentence as a toast means the screen never rebinds, and the operator
        // watches "No export has been run yet" while the bundle is written behind them. The
        // sentence goes in `progress` instead, where it also survives a reload.
        return [
            'run_id'      => $fresh?->id,
            'progress'    => $this->progress($fresh),
            'bundle_path' => $fresh?->bundlePath() === null ? null : $this->relative($fresh),
            'errors_text' => $this->errors($fresh),
        ];
    }

    /**
     * Refuse a mistyped passphrase before anything is sealed under it.
     *
     * **Checked here rather than in the schema**, because no validation rule can express "required,
     * and equal to that other field, but only when this switch is on" — and the consequence of
     * getting it wrong is unusually bad. A passphrase is not stored anywhere, so a typo is not
     * recoverable: the bundle would be sealed under a string nobody knows, and the operator would
     * discover it on the destination, having already carried the file there.
     *
     * @param  array<string,mixed>  $data
     *
     * @throws \RuntimeException
     */
    private function assertPassphrasePair(Run $run, array $data): void
    {
        if (($run->selection()['include_credentials'] ?? false) !== true) {
            return;
        }

        $passphrase = (string) ($data['passphrase'] ?? '');
        $confirm    = (string) ($data['passphrase_confirm'] ?? '');

        if ($passphrase === '' && $confirm === '') {
            // Empty on a Continue press is not an error — the operator may simply have navigated
            // back. The exporter records that credentials were left out.
            return;
        }

        if (! hash_equals($passphrase, $confirm)) {
            throw new \RuntimeException(
                'The two passphrases do not match. Nothing has been exported. Since the passphrase '
                . 'is never stored, a typo here would seal your credentials under a string nobody '
                . 'knows — which is why it is asked for twice.'
            );
        }

        if (strlen($passphrase) < CredentialVault::MIN_PASSPHRASE) {
            throw new \RuntimeException(sprintf(
                'The passphrase must be at least %d characters. Once the bundle leaves this server '
                . 'it is the only thing protecting your gateway keys, and whoever holds the file '
                . 'can guess at it for as long as they like.',
                CredentialVault::MIN_PASSPHRASE
            ));
        }
    }

    /**
     * The most recent export.
     *
     * Whatever its state — a failed one is shown too, because its error list is the only place
     * that says why, and hiding it would leave the screen looking as though nothing had happened.
     */
    private function resumable(): ?Run
    {
        return $this->runs->latest(Run::DIRECTION_EXPORT);
    }

    private function progress(?Run $run): string
    {
        if ($run === null) {
            return 'No export has been run on this site yet.';
        }

        $tally = $run->tally();

        return match ($run->status()) {
            Run::STATUS_COMPLETED => sprintf(
                'Finished %s — %s records written.',
                $run->get('finished_at', ''),
                number_format($tally['created'])
            ),
            Run::STATUS_PAUSED => sprintf(
                'Paused with %s records written. Press Continue.',
                number_format($tally['created'])
            ),
            Run::STATUS_FAILED  => 'The last export failed. The messages below say why.',
            Run::STATUS_RUNNING => 'Running.',
            default             => 'Waiting to start.',
        };
    }

    /**
     * Where the bundle is, as a path the operator can act on.
     *
     * **Not a download link, and that is a platform limit rather than a choice.** A plugin
     * registers no routes, so it cannot stream a file; `savePageData` always wraps its return in
     * `response()->json()`. The one upload/download path core offers is an Asset, and on this
     * build that path is unusable in both directions: `config('filesystems.disks')` has no
     * `protected` entry, and there is no `assets.view` route, so `Asset::path()` on a non-public
     * asset throws `RouteNotFoundException`.
     *
     * The remaining option would be to write the bundle to the **public** disk, where it is
     * reachable by anyone who guesses the URL — for a file that can contain the whole site, that
     * is not a default this package will pick on the operator's behalf. So the bundle stays under
     * `storage/app`, off the web, and the screen says where it is.
     */
    private function relative(Run $run): string
    {
        return 'storage/app/site-migration/' . $run->id . '/bundle.zip';
    }

    private function errors(?Run $run): string
    {
        $errors = (array) $run?->get('errors', []);

        return $errors === [] ? '' : implode("\n", $errors);
    }
}
