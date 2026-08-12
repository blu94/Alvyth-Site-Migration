<?php

namespace Plugin\SiteMigration\Backend\Pages;

use Plugin\SiteMigration\Backend\Runs\Run;
use Plugin\SiteMigration\Backend\Runs\RunStore;

/**
 * What this install has exported and imported.
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
            'storage_at' => 'storage/app/site-migration',
        ];
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
            '%s · %s · %s · run by %s',
            $run->get('created_at', $run->id),
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

        $where = $run->bundlePath() === null
            ? 'Bundle no longer on this server.'
            : 'Bundle: storage/app/site-migration/' . $run->id . '/bundle.zip';

        return $line . "\n" . $counts . "\n" . $where;
    }
}
