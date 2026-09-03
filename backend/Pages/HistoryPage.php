<?php

namespace Plugin\SiteMigration\Backend\Pages;

use Plugin\SiteMigration\Backend\Runs\Run;
use Plugin\SiteMigration\Backend\Runs\RunStore;
use Plugin\SiteMigration\Backend\Support\Permissions;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * What this install has exported and imported, and the one place a run can be removed.
 *
 * **A custom page rather than a module list**, because the package ships no database tables and
 * `baseIndexQuery()` must return a query builder for DataTables to paginate — a directory of JSON
 * files cannot answer that. What is lost is server-side paging, sorting and filtering; what is
 * kept is the thing the history was for, which is being able to answer "what did we push to
 * production, and when, and did it finish".
 *
 * Bounded to the most recent runs for the same reason the errors list is bounded: this reads a
 * file per run, and a screen that stats four hundred directories to render a table nobody scrolls
 * is a slow page with no reader.
 */
class HistoryPage
{
    private const SHOWN = 25;

    public function __construct(private readonly RunStore $runs)
    {
    }

    /** @return array<string,mixed> */
    public function data(): array
    {
        $runs = $this->runs->all(self::SHOWN);

        return [
            'runs_text'  => $runs === [] ? '' : implode("\n\n", array_map($this->describe(...), $runs)),
            'runs_empty' => $runs === [],
            'runs_count' => count($runs),
            // Read from the store rather than restated, because the path is now scoped to the
            // database and a hard-coded label would send an operator looking in the parent
            // directory — which is exactly where their runs are not.
            'storage_at' => $this->storageLabel(),
            'disk_usage'     => $this->diskUsage($runs),
            'delete_id'      => '',
            'delete_options' => $this->deleteOptions($runs),
            'delete_result'  => '',
        ];
    }

    /**
     * The run directory, written the way an operator would type it into SFTP.
     *
     * Relative to the application root, because an absolute container path (`/var/www/storage/...`)
     * is not where anybody looking at their hosting will find it.
     */
    private function storageLabel(): string
    {
        $root = str_replace('\\', '/', $this->runs->root());
        $base = str_replace('\\', '/', base_path()) . '/';

        return str_starts_with($root, $base) ? substr($root, strlen($base)) : $root;
    }

    /**
     * Remove one run: its state, its id map and its bundle.
     *
     * **Deleting a run is not undoing it**, and the difference is the most expensive thing on this
     * screen to misunderstand. The records an import wrote stay exactly where they are; what goes
     * is the record *of* the run and the bundle file. The confirmation says so in those words.
     *
     * **Gated on `.create`, not `.delete`, and that is a reversal.** The first version demanded
     * `site_migration.delete` on the reasoning that running a migration and tidying up after one
     * are different acts. Clicking the button proved that wrong: core's seeder grants the `admin`
     * role every permission *except* `.delete`, so the role that actually performs migrations
     * could never reclaim a byte of the disk its own bundles were filling — which is the entire
     * problem this action was added to solve.
     *
     * The blast radius justifies the looser gate. Removing a run destroys no site data: a bundle
     * is regenerable by exporting again, and a history entry is a log line. That does not warrant
     * a grant core deliberately withholds.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    public function delete(array $data): array
    {
        if (! Permissions::mayRun()) {
            throw new AccessDeniedHttpException(
                'You do not have permission to manage migration runs.'
            );
        }

        $id = trim((string) ($data['delete_id'] ?? ''));

        // **No `message` key on any path.** The custom-page host merges a response into the bound
        // model only when it carries none, so returning the outcome as a toast leaves the list and
        // the disk figure showing the run that was just deleted — which reads as a delete that did
        // not work. The sentence goes in a bound field instead, and the refreshed list arrives with
        // it. (Walked into twice; hence the emphasis.)
        // **Overrides on the left of `+`.** PHP's array union keeps the *left* operand's value for
        // a duplicate key, so `$this->data() + ['delete_result' => …]` silently discarded every
        // override — `data()` already declares both keys, so the outcome sentence never reached
        // the screen and the delete looked as though it had done nothing. Found by clicking it.
        if ($id === '') {
            return [
                'delete_result' => 'Paste the id of the run to remove — it is the first thing on each line above.',
            ] + $this->data();
        }

        $removed = $this->runs->delete($id);

        return [
            'delete_id'     => '',
            'delete_result' => $removed
                ? "Run {$id} removed, along with its bundle. Anything it imported is untouched."
                : "No run called \"{$id}\" — check the id against the list above.",
        ] + $this->data();
    }

    /**
     * The runs, as options for the remove control.
     *
     * **A select rather than a typed id, and the first version was the typed one.** Asking an
     * operator to copy an id out of a read-only textarea and paste it into a box beside it is a
     * poor interaction on its own terms — it is transcription, and transcription of a
     * `20260812-141233-a7f3` is exactly the kind people get wrong. Picking from a list cannot be
     * mistyped, cannot name a run that is not there, and needs no instructions.
     *
     * Each option carries enough to recognise the run without opening anything: when it ran, which
     * direction it went, and how big its bundle is — which is the number an operator reclaiming
     * disk is actually choosing on.
     *
     * @param  array<int,Run>|null  $runs  the already-loaded list, or null to read it
     * @return array<int,array{title:string,value:string}>
     */
    public function deleteOptions(?array $runs = null): array
    {
        $runs ??= $this->runs->all(self::SHOWN);

        return array_map(function (Run $run) {
            $bundle = $run->bundlePath();

            return [
                'title' => sprintf(
                    '%s · %s · %s',
                    $run->get('created_at', $run->id),
                    ucfirst($run->direction()),
                    $bundle === null ? 'no bundle' : $this->humanBytes((int) filesize($bundle))
                ),
                'value' => $run->id,
            ];
        }, array_values($runs));
    }

    /**
     * One run as a paragraph.
     *
     * Prose rather than a table: a `display` field renders one value, and the closed field set
     * has no widget that draws an arbitrary grid. Given that constraint, sentences an operator
     * can read beat a pipe-delimited string pretending to be columns.
     */
    private function describe(Run $run): string
    {
        $tally = $run->tally();

        $status = match ($run->status()) {
            Run::STATUS_COMPLETED => 'finished',
            Run::STATUS_PAUSED    => 'paused, waiting for Continue',
            Run::STATUS_FAILED    => 'failed',
            Run::STATUS_RUNNING   => 'running',
            default               => 'not started',
        };

        $line = sprintf(
            '%s · %s · %s · %s · run by %s',
            $run->id,
            $run->get('created_at', ''),
            ucfirst($run->direction()),
            $status,
            $run->get('user_name') ?: 'someone since deleted'
        );

        $counts = sprintf(
            '%s created, %s updated, %s skipped, %s failed',
            number_format($tally['created']),
            number_format($tally['updated']),
            number_format($tally['skipped']),
            number_format($tally['failed'])
        );

        $bundle = $run->bundlePath();

        $where = $bundle === null
            ? 'Bundle no longer on this server.'
            : sprintf(
                'Bundle: storage/app/site-migration/%s/bundle.zip (%s)',
                $run->id,
                $this->humanBytes((int) filesize($bundle))
            );

        return $line . "\n" . $counts . "\n" . $where;
    }

    /**
     * What the runs on this screen are costing in disk.
     *
     * Shown because nothing else on the site would ever mention it: the package owns no tables, so
     * a growing collection of bundles is invisible until a host complains. Summed over the runs
     * listed rather than the whole directory — one `filesize` per shown run, no recursive walk.
     *
     * @param  array<int,Run>  $runs
     */
    private function diskUsage(array $runs): string
    {
        $bytes = 0;

        foreach ($runs as $run) {
            $bundle = $run->bundlePath();

            if ($bundle !== null) {
                $bytes += (int) filesize($bundle);
            }
        }

        return $bytes === 0
            ? 'No bundles are stored on this server.'
            : sprintf(
                'The %d runs below are holding %s of bundles. Remove any you have already carried across.',
                count($runs),
                $this->humanBytes($bytes)
            );
    }

    private function humanBytes(int $bytes): string
    {
        foreach (['bytes', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024 || $unit === 'GB') {
                return ($unit === 'bytes' ? $bytes : round($bytes, 1)) . ' ' . $unit;
            }

            $bytes = (int) round($bytes / 1024);
        }

        return $bytes . ' bytes';
    }
}
