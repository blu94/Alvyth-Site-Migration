<?php

namespace Plugin\SiteMigration\Backend\Services;

use Illuminate\Database\Eloquent\Model;
use Plugin\SiteMigration\Backend\Bundle\BundleContext;
use Plugin\SiteMigration\Backend\Bundle\BundleWriter;
use Plugin\SiteMigration\Backend\Bundle\Manifest;
use Plugin\SiteMigration\Backend\Resources\DriverRegistry;
use Plugin\SiteMigration\Backend\Runs\Run;
use Plugin\SiteMigration\Backend\Support\Canonical;
use Plugin\SiteMigration\Backend\Support\Permissions;
use Plugin\SiteMigration\Backend\Support\StepBudget;
use Throwable;

/**
 * Walks the selected resources into a bundle, a time-box at a time.
 *
 * Gate → plan → walk → seal. Each press of the button runs as much of the walk as the budget
 * allows and then returns its cursor; the last one seals.
 */
class Exporter
{
    public function __construct(
        private readonly DriverRegistry $drivers,
    ) {
    }

    /**
     * Do as much of this run as the budget allows.
     *
     * @return array<string,mixed> what the screen shows: done, processed, total, message
     */
    public function step(Run $run, int|float|null $budgetSeconds = null): array
    {
        Permissions::assertMayRun();

        $budget = new StepBudget($budgetSeconds);
        $writer = new BundleWriter($run->directory . '/staging');

        $resources = $this->resources($run);

        if ($resources === []) {
            return $this->fail($run, 'Nothing was selected, so there is nothing to export.');
        }

        $run->set('status', Run::STATUS_RUNNING)->save();

        try {
            return $this->walk($run, $writer, $resources, $budget);
        } catch (Throwable $e) {
            return $this->fail($run, $e->getMessage());
        }
    }

    /**
     * Which resources this run may actually read.
     *
     * Filtered rather than refused, and what was dropped is recorded on the run — an export that
     * silently omitted products would be discovered on the destination, which is the worst place
     * to find out.
     *
     * @return array<int,string>
     */
    private function resources(Run $run): array
    {
        $selected = array_values(array_filter(
            (array) ($run->selection()['modules'] ?? []),
            fn ($key) => is_string($key) && $this->drivers->has($key)
        ));

        // Gated on the *permission* resource, not the driver key. Two of them differ — posts are
        // gated by `blogs`, email templates by `settings` — and asking `can('posts.view')` would
        // check a permission that exists nowhere, silently dropping the resource from the bundle.
        $allowed = $refused = [];

        foreach ($selected as $key) {
            if (Permissions::allows($this->drivers->for($key)->permissionResource() . '.view')) {
                $allowed[] = $key;
            } else {
                $refused[] = $key;
            }
        }

        if ($refused !== []) {
            $run->addErrors(sprintf(
                'Left out of this bundle because you do not have permission to view them: %s.',
                implode(', ', $refused)
            ));
        }

        return $this->drivers->ordered($allowed);
    }

    /**
     * @param  array<int,string>  $resources
     * @return array<string,mixed>
     */
    private function walk(Run $run, BundleWriter $writer, array $resources, StepBudget $budget): array
    {
        $cursor    = $run->cursor();
        $totals    = $this->plan($run, $resources);
        $processed = 0;

        foreach ($resources as $resource) {
            // A resource finished by an earlier press is skipped without a query. `written()`
            // counts the staging file, so the answer survives the request that produced it.
            if (($cursor['done'][$resource] ?? false) === true) {
                continue;
            }

            $afterId = (int) ($cursor['after_id'] ?? 0);

            if (($cursor['resource'] ?? null) !== $resource) {
                $afterId = 0;
            }

            $result = $this->walkResource($run, $writer, $resource, $afterId, $budget);

            $processed += $result['processed'];

            if (! $result['done']) {
                $run->set('cursor', [
                    'resource' => $resource,
                    'after_id' => $result['after_id'],
                    'done'     => $cursor['done'] ?? [],
                ])->save();

                return $this->paused($run, $writer, $resources, $totals);
            }

            $cursor['done'][$resource] = true;
            $cursor['resource']        = null;
            $cursor['after_id']        = 0;

            $run->set('cursor', $cursor)->save();
        }

        return $this->seal($run, $writer, $resources, $totals);
    }

    /**
     * Walk one resource until it runs out of rows or out of time.
     *
     * `chunkById`, **never** `offset` paging: an offset re-scans every skipped row, so page 200
     * of a catalogue costs two hundred times page one. Keyset paging on the primary key is also
     * what makes `after_id` a resumable cursor — an offset would silently shift if a row were
     * inserted between two presses.
     *
     * @return array{done:bool, processed:int, after_id:int}
     */
    private function walkResource(
        Run $run,
        BundleWriter $writer,
        string $resource,
        int $afterId,
        StepBudget $budget,
    ): array {
        $driver = $this->drivers->for($resource);

        // Only the asset driver acts on this — it is what tells it where to copy bytes to, and
        // whether the operator asked for them at all.
        $driver->useBundle(new BundleContext(
            $run->directory . '/staging',
            (bool) ($run->selection()['include_media'] ?? false)
        ));

        $keyColumn = $this->qualifiedKey($driver->exportQuery()->getModel());
        $processed = 0;
        $failed    = 0;
        $errors    = [];

        // `do`, not `while`: at least one chunk is always fetched. A plain `while` tested the
        // budget *before* any work, so on a host slow enough that the step's own setup exhausted
        // it, the run returned "paused, 0 done" — and every Continue did the same thing forever.
        // A step that cannot make progress is worse than a slow one, because nothing on the
        // screen says it is stuck.
        do {
            $chunk = $driver->exportQuery()
                ->where($keyColumn, '>', $afterId)
                ->limit(200)
                ->get();

            if ($chunk->isEmpty()) {
                $this->tally($run, $processed, $failed, $errors);

                return ['done' => true, 'processed' => $processed, 'after_id' => $afterId];
            }

            foreach ($chunk as $model) {
                $afterId = (int) $model->getKey();

                try {
                    $record = $driver->toRecord($model);

                    // Reserved at stage 2 even though nothing reads it until stage 5. It is a
                    // field in the bundle *format*: retrofitting it once real bundles exist in
                    // the wild means a format bump and a compatibility branch.
                    $record['_hash'] = Canonical::hash($record, $driver->volatileFields());

                    $writer->append($resource, $record);

                    $processed++;
                } catch (Throwable $e) {
                    $failed++;

                    if (count($errors) < 20) {
                        $errors[] = sprintf('%s #%d: %s', $resource, $model->getKey(), $e->getMessage());
                    }
                }

                if (! $budget->hasTime()) {
                    break;
                }
            }
        } while ($budget->hasTime());

        $this->tally($run, $processed, $failed, $errors);

        return ['done' => false, 'processed' => $processed, 'after_id' => $afterId];
    }

    /** @param array<int,string> $errors */
    private function tally(Run $run, int $processed, int $failed, array $errors): void
    {
        $run->addTally(['created' => $processed, 'failed' => $failed])->addErrors($errors);
    }

    /**
     * How many records each resource holds, counted once and kept on the run.
     *
     * Counted at the start rather than per step: the figure is what the progress line divides by,
     * and recounting on every press would make the denominator move while the operator watched.
     *
     * @param  array<int,string>  $resources
     * @return array<string,int>
     */
    private function plan(Run $run, array $resources): array
    {
        $planned = (array) $run->get('planned', []);

        if ($planned !== []) {
            return array_map('intval', $planned);
        }

        foreach ($resources as $resource) {
            // `count()` on the Eloquent builder, not the base one: eager loads do not run for a
            // count, so this is a single `select count(*)` and the `with()` in `exportQuery()`
            // costs nothing here.
            $planned[$resource] = $this->drivers->for($resource)->exportQuery()->count();
        }

        $run->set('planned', $planned)->save();

        return array_map('intval', $planned);
    }

    /**
     * @param  array<int,string>  $resources
     * @param  array<string,int>  $totals
     * @return array<string,mixed>
     */
    private function paused(Run $run, BundleWriter $writer, array $resources, array $totals): array
    {
        $done  = $this->written($writer, $resources);
        $total = array_sum($totals);

        $run->set('status', Run::STATUS_PAUSED)
            ->set('updated_at', now()->toDateTimeString())
            ->save();

        return [
            'done'      => false,
            'run_id'    => $run->id,
            'processed' => $done,
            'total'     => $total,
            'message'   => sprintf(
                'Still working — %s of %s records written. Press Continue to carry on.',
                number_format($done),
                number_format($total)
            ),
        ];
    }

    /**
     * @param  array<int,string>  $resources
     * @param  array<string,int>  $totals
     * @return array<string,mixed>
     */
    private function seal(Run $run, BundleWriter $writer, array $resources, array $totals): array
    {
        $contents = $this->written($writer, $resources, true);

        $manifest = Manifest::build($contents, (bool) ($run->selection()['include_media'] ?? false));

        $writer->seal($manifest);

        $run->set('status', Run::STATUS_COMPLETED)
            ->set('manifest', $manifest->toArray())
            ->set('finished_at', now()->toDateTimeString())
            ->set('updated_at', now()->toDateTimeString())
            ->save();

        $written = array_sum($contents);

        return [
            'done'      => true,
            'run_id'    => $run->id,
            'processed' => $written,
            'total'     => array_sum($totals),
            'message'   => sprintf(
                'Export finished — %s records in the bundle. Download it below.',
                number_format($written)
            ),
        ];
    }

    /**
     * @param  array<int,string>  $resources
     * @return array<string,int>|int
     */
    private function written(BundleWriter $writer, array $resources, bool $perResource = false): array|int
    {
        $counts = [];

        foreach ($resources as $resource) {
            $counts[$resource] = $writer->written($resource);
        }

        return $perResource ? $counts : array_sum($counts);
    }

    /** @return array<string,mixed> */
    private function fail(Run $run, string $message): array
    {
        $run->set('status', Run::STATUS_FAILED)
            ->addErrors($message)
            ->set('updated_at', now()->toDateTimeString())
            ->save();

        return ['done' => true, 'run_id' => $run->id, 'failed' => true, 'message' => $message];
    }

    /**
     * The primary key, table-qualified.
     *
     * Qualified because a driver's `exportQuery()` may join — `where('id', '>', ?)` against a
     * joined query is ambiguous and MySQL refuses it, which would fail only for the drivers that
     * happen to join and only once their table had rows.
     */
    private function qualifiedKey(Model $model): string
    {
        return $model->getTable() . '.' . $model->getKeyName();
    }
}
