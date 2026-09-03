<?php

namespace Plugin\SiteMigration\Backend\Services;

use App\Services\Seo\SitemapCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use Plugin\SiteMigration\Backend\Bundle\BundleContext;
use Plugin\SiteMigration\Backend\Bundle\BundleReader;
use Plugin\SiteMigration\Backend\Bundle\CredentialVault;
use Plugin\SiteMigration\Backend\Resources\DriverRegistry;
use Plugin\SiteMigration\Backend\Resources\ResourceDriver;
use Plugin\SiteMigration\Backend\Resources\SkipRecord;
use Plugin\SiteMigration\Backend\Runs\IdMap;
use Plugin\SiteMigration\Backend\Runs\Run;
use Plugin\SiteMigration\Backend\Support\Canonical;
use Plugin\SiteMigration\Backend\Support\Permissions;
use Plugin\SiteMigration\Backend\Support\StepBudget;
use Throwable;

/**
 * Reads a bundle into this install.
 *
 * Manifest → gate → dry run → apply, resource by resource in the registry's declared order.
 *
 * **Resumability is bought by idempotence, not by atomicity.** `savePageData` wraps each call in
 * a transaction, so a multi-step import is emphatically *not* all-or-nothing: press one commits
 * before press two begins. What makes that safe is upserting on the natural key — re-running any
 * step is harmless, because a record that was already written is found and updated rather than
 * added again. An interrupted import leaves a partially populated site, and the fix is to press
 * Continue, not to start over. The screen says so.
 */
class Importer
{
    public function __construct(
        private readonly DriverRegistry $drivers,
        private readonly RewritePass $rewrite,
    ) {
    }

    /**
     * Hand a driver the bundle it is reading from, and the run's id map.
     *
     * Only the asset driver acts on the bundle context, and only the drivers whose records
     * reference other records act on the map; the rest ignore both. Called per resource rather
     * than once, because the registry resolves a fresh driver each time and a context set on a
     * discarded instance is a context nobody sees.
     */
    private function driverFor(Run $run, string $resource, BundleReader $reader): ResourceDriver
    {
        $driver = $this->drivers->for($resource);

        $driver->useBundle(new BundleContext(
            $reader->extractedPath(),
            $reader->manifest()->includesMedia(),
            $reader->manifest()->sourceUrl()
        ));

        $driver->useIdMap($run->idMap());

        return $driver;
    }

    /**
     * What this bundle would do, without writing anything.
     *
     * **The step that earns the whole feature.** An operator seeing "312 products will be
     * overwritten" before pressing anything is the difference between a migration and an
     * accident. It is affordable because it compares one hash per record rather than every field.
     *
     * @return array<string,mixed>
     */
    public function dryRun(Run $run): array
    {
        Permissions::assertMayRun();

        $reader = $this->reader($run);

        $reader->manifest()->assertReadable();

        $resources = $this->resources($run, $reader);
        $report    = [];

        foreach ($resources as $resource) {
            $driver = $this->driverFor($run, $resource, $reader);

            $reader->verify($resource);

            $new = $update = $unchanged = $unplaceable = 0;

            foreach ($reader->records($resource) as $record) {
                if (($record['_unreadable'] ?? false) === true) {
                    $unplaceable++;

                    continue;
                }

                try {
                    $existing = $driver->locate($record);
                } catch (Throwable) {
                    // A record with no natural key cannot be placed, and saying so here — with a
                    // count — is the entire reason this refuses to invent one.
                    $unplaceable++;

                    continue;
                }

                if ($existing === null) {
                    $new++;

                    continue;
                }

                $this->unchanged($driver->toRecord($existing), $record, $driver->volatileFields())
                    ? $unchanged++
                    : $update++;
            }

            $report[$resource] = [
                'label'       => $driver->label(),
                'new'         => $new,
                'update'      => $update,
                'unchanged'   => $unchanged,
                'unplaceable' => $unplaceable,

                // Carried so the preview can say what will actually happen to *this* resource.
                // A single sentence for all of them was how the preview came to describe a merge
                // as "added alongside under a free name".
                'merges'      => $driver->mergesOnCollision(),
                'guarded'     => $driver->mergeReplacesLocalWork(),
            ];
        }

        $run->set('dry_run', $report)
            ->set('manifest', $reader->manifest()->toArray())
            ->save();

        return [
            'report'   => $report,
            'locales'  => $reader->manifest()->unmappedLocales(),
            'message'  => $this->describe($report, $run->overwrites()),
        ];
    }

    /**
     * Write as much of the bundle as the budget allows.
     *
     * @return array<string,mixed>
     */
    public function step(Run $run, int|float|null $budgetSeconds = null): array
    {
        Permissions::assertMayRun();

        $budget = new StepBudget($budgetSeconds);
        $reader = $this->reader($run);

        try {
            $reader->manifest()->assertReadable();

            $resources = $this->resources($run, $reader);

            // A refusal, not a filter. An import that silently skipped a resource produces a
            // destination that is *partly* migrated and looks finished.
            //
            // Mapped to permission resources first, for the same reason the export gates on them:
            // `posts` and `email_templates` are guarded by `blogs` and `settings`.
            Permissions::assertMayWrite(
                array_values(array_unique(array_map(
                    fn (string $key) => $this->drivers->for($key)->permissionResource(),
                    $resources
                ))),
                $run->overwrites()
            );

            if ($resources === []) {
                return $this->fail($run, 'This bundle carries nothing this site can import.');
            }

            $run->set('status', Run::STATUS_RUNNING)->save();

            return $this->apply($run, $reader, $resources, $budget);
        } catch (Throwable $e) {
            return $this->fail($run, $e->getMessage());
        }
    }

    /**
     * @param  array<int,string>  $resources
     * @return array<string,mixed>
     */
    private function apply(Run $run, BundleReader $reader, array $resources, StepBudget $budget): array
    {
        $cursor = $run->cursor();
        $idMap  = $run->idMap();
        $total  = $this->total($run, $reader, $resources);

        foreach ($resources as $resource) {
            if (($cursor['done'][$resource] ?? false) === true) {
                continue;
            }

            $driver = $this->driverFor($run, $resource, $reader);
            $from   = ($cursor['resource'] ?? null) === $resource ? (int) ($cursor['line'] ?? 0) : 0;

            if ($from === 0) {
                $reader->verify($resource);
            }

            $line      = $from;
            $exhausted = true;

            foreach ($reader->records($resource, $from) as $number => $record) {
                $line = $number;

                $this->writeOne($run, $driver, $idMap, $resource, $record, $number);

                if (! $budget->hasTime()) {
                    // `records()` is a generator, so breaking here stops reading the file rather
                    // than merely stopping the loop — the remaining lines are never decoded.
                    $exhausted = false;

                    break;
                }
            }

            if (! $exhausted) {
                $run->set('cursor', [
                    'resource' => $resource,
                    'line'     => $line,
                    'done'     => $cursor['done'] ?? [],
                ])->save();

                return $this->paused($run, $total);
            }

            $cursor['done'][$resource] = true;
            $cursor['resource']        = null;
            $cursor['line']            = 0;

            $run->set('cursor', $cursor)->save();
        }

        return $this->finish($run, $total, $reader);
    }

    /**
     * Write one record, tallying whatever happens.
     *
     * Every outcome is caught. One bad record in forty thousand must not end the run — the whole
     * point of the tally and the bounded error list is that the operator gets the other 39,999
     * and a sentence naming the one that failed.
     *
     * @param  array<string,mixed>  $record
     */
    private function writeOne(
        Run $run,
        ResourceDriver $driver,
        IdMap $idMap,
        string $resource,
        array $record,
        int $line,
    ): void {
        if (($record['_unreadable'] ?? false) === true) {
            $run->addTally(['failed' => 1])->addErrors("{$resource} line {$line}: the record could not be read.");

            return;
        }

        try {
            $existing = $driver->locate($record);

            // **A collision never drops the incoming record.** The earlier behaviour — leave mine
            // alone, discard theirs — reported success while losing data the operator had asked
            // this tool to carry, which is the one outcome a migration must not produce.
            //
            // So when the operator has not chosen to overwrite, the incoming record is written
            // *alongside* under a key nothing is using: an invoice takes this site's next number,
            // a product a free SKU, a slug a suffix. Two records that claimed one name become two
            // records with two names, which the operator can merge afterwards knowing nothing was
            // lost — a position they cannot get back to once a row has been silently skipped.
            if ($existing !== null && ! $run->overwrites()) {
                // The content hash short-circuits an identical record **before** anything else,
                // merge semantics included. This check once sat below the merge branch, so every
                // record of a merging resource — each asset, account, comment and lead — was
                // rewritten on every repeat import to change nothing, while the dry run promised
                // "unchanged". Found by importing a site into itself in a browser: the preview
                // said nothing would change and the tally then reported 519 updates.
                if ($this->unchanged($driver->toRecord($existing), $record, $driver->volatileFields())) {
                    $run->addTally(['skipped' => 1]);
                    $this->remember($idMap, $resource, $record, $existing);

                    return;
                }

                if ($driver->mergesOnCollision()) {
                    // **A merge that replaces the operator's own work needs the overwrite
                    // acknowledgement, exactly like every other replacement.** Settings, email
                    // templates, themes and plugins have no free name to be written under, so the
                    // merge branch used to take them regardless of the switch — which made the
                    // screen's promise ("nothing already here is replaced unless you turn
                    // overwriting on") false for them, and made the preview's "added alongside
                    // under a free name" a description of something else entirely.
                    //
                    // Left alone rather than written, and said out loud. This is the one place the
                    // package declines to place an incoming record, and it is not a silent skip:
                    // the reason names the resource and the switch that would let it through.
                    if ($driver->mergeReplacesLocalWork()) {
                        throw new SkipRecord(sprintf(
                            'This site already has %s, and bringing the bundle\'s version in would '
                            . 'replace what is here rather than sit beside it. Nothing was changed. '
                            . 'Turn on "Overwrite my records when they clash" and import again to '
                            . 'replace it.',
                            strtolower($driver->label())
                        ));
                    }

                    // Where a collision means "the same thing": one email is one person, and
                    // `jane+2@example.com` would be a second account nobody can sign into.
                    $written = $driver->write($record, $existing);

                    $run->addTally(['updated' => 1]);
                    $this->remember($idMap, $resource, $record, $written);

                    return;
                }

                $renamed = $driver->renameForCollision($record);
                $written = $driver->write($renamed, null);

                $run->addTally(['renamed' => 1]);

                // Mapped from the record's **original** id, so anything that referenced it on the
                // source still resolves to where it actually landed here.
                $this->remember($idMap, $resource, $record, $written);

                return;
            }

            // The largest performance win available, and the reason the hash is in the format
            // from stage 2. Staging is pushed to production repeatedly and almost nothing changes
            // between pushes; without this every re-run rewrites the whole catalogue to change
            // nothing.
            if ($existing !== null
                && $this->unchanged($driver->toRecord($existing), $record, $driver->volatileFields())) {
                $run->addTally(['skipped' => 1]);
                $this->remember($idMap, $resource, $record, $existing);

                return;
            }

            $written = $driver->write($record, $existing);

            $run->addTally([($existing === null ? 'created' : 'updated') => 1]);

            $this->remember($idMap, $resource, $record, $written);
        } catch (SkipRecord $e) {
            // Benign: nothing here is wrong, there is simply nothing to place. Counted as skipped
            // so the final tally still reads as the clean migration it was, and recorded once so
            // the operator can see *which* records were left out if they care.
            $run->addTally(['skipped' => 1])
                ->addErrors(sprintf('%s line %d: %s', $resource, $line, $e->getMessage()));
        } catch (Throwable $e) {
            $run->addTally(['failed' => 1])
                ->addErrors(sprintf('%s line %d: %s', $resource, $line, $e->getMessage()));
        } finally {
            // Drained however the record ended, and in a `finally` so a driver that noted something
            // and *then* threw still gets its note reported. A note is not a failure — it is a
            // record that was placed, with a part of it deliberately left undone.
            foreach ($driver->takeNotes() as $note) {
                $run->addErrors(sprintf('%s line %d: %s', $resource, $line, $note));
            }
        }
    }

    /** @param array<string,mixed> $record */
    private function remember($idMap, string $resource, array $record, Model $model): void
    {
        $source = (int) ($record['_source'] ?? 0);

        if ($source > 0) {
            $idMap->remember($resource, $source, (int) $model->getKey());
        }
    }

    /**
     * Whether an existing record already says what the bundle says.
     *
     * The bundle's `_hash` was computed by the *source* over its own record; this recomputes one
     * over the destination's. Both sides run the same canonical form, which is what makes the
     * comparison meaningful — and what makes getting that form wrong a silent no-op rather than a
     * visible bug.
     *
     * A bundle with no `_hash` compares as changed, which is correct: an older bundle predates
     * the field, and treating "I do not know" as "unchanged" would skip a record that needs
     * writing.
     *
     * @param  array<string,mixed>  $mine
     * @param  array<string,mixed>  $theirs
     * @param  array<int,string>  $volatile
     */
    private function unchanged(array $mine, array $theirs, array $volatile): bool
    {
        $incoming = (string) ($theirs['_hash'] ?? '');

        if ($incoming === '') {
            return false;
        }

        return hash_equals(Canonical::hash($mine, $volatile), $incoming);
    }

    /**
     * Which resources to write: what the bundle carries, narrowed to what the operator ticked.
     *
     * A resource the operator did not tick has its NDJSON file **never opened**, so narrowing an
     * import is strictly cheaper rather than merely equivalent. An empty selection means "all of
     * it", which is what pressing Import without touching the list should do.
     *
     * @return array<int,string>
     */
    private function resources(Run $run, BundleReader $reader): array
    {
        $available = array_values(array_filter(
            $reader->manifest()->resources(),
            fn (string $key) => $this->drivers->has($key) && $reader->has($key)
        ));

        $wanted = array_values(array_filter(array_merge(
            (array) ($run->selection()['modules'] ?? []),
            (array) ($run->selection()['records'] ?? [])
        )));

        if ($wanted !== []) {
            $available = array_values(array_intersect($available, $wanted));
        }

        return $this->drivers->ordered($available);
    }

    /**
     * @param  array<int,string>  $resources
     */
    private function total(Run $run, BundleReader $reader, array $resources): int
    {
        $planned = (array) $run->get('planned', []);

        if ($planned === []) {
            foreach ($resources as $resource) {
                $planned[$resource] = $reader->count($resource);
            }

            $run->set('planned', $planned)->save();
        }

        return (int) array_sum(array_map('intval', $planned));
    }

    /** @return array<string,mixed> */
    private function paused(Run $run, int $total): array
    {
        $done = array_sum(array_intersect_key(
            $run->tally(),
            array_flip(['created', 'updated', 'skipped', 'failed'])
        ));

        $run->set('status', Run::STATUS_PAUSED)->set('updated_at', now()->toDateTimeString())->save();

        return [
            'done'      => false,
            'run_id'    => $run->id,
            'processed' => $done,
            'total'     => $total,
            'message'   => sprintf(
                'Still working — %s of %s records done. Press Continue to carry on.',
                number_format($done),
                number_format($total)
            ),
        ];
    }

    /** @return array<string,mixed> */
    private function finish(Run $run, int $total, BundleReader $reader): array
    {
        // **The second pass, and it runs exactly once — here, at the end.** Every id this bundle
        // placed is now known, which is the precondition it could not have earlier: a page's
        // builder node pointing at asset 12 can only be repaired once this install knows what 12
        // became. Running it per step would rewrite records against a half-built map.
        try {
            $rewritten = $this->rewrite->run($run, $reader->manifest(), $reader);

            if ($rewritten !== []) {
                $run->set('rewritten', $rewritten);
            }
        } catch (Throwable $e) {
            // The records are already written and correct in every respect except their embedded
            // references. Failing the whole run here would report a successful import as a failure;
            // saying what went wrong lets the operator re-run, which is safe because the pass is
            // idempotent.
            $run->addErrors('The records were imported, but repairing embedded links and images '
                . 'failed: ' . $e->getMessage() . ' Re-running the import will retry it safely.');
        }

        // **Write through the owning repository, never straight to `metas`** is the rule that
        // keeps the eight `rememberForever` settings keys from going stale. Content has no such
        // repository seam, and `SitemapCache` versions its keys rather than flushing — which
        // matters because `.env.install` ships `CACHE_STORE=file` and `FileStore` is not
        // taggable, so `Cache::tags()->flush()` would throw on exactly the hosting this targets.
        app(SitemapCache::class)->bust();

        $this->applyCredentials($run, $reader);

        // **The unpacked copy goes here, and only here.** `extracted/` is working space that
        // exists so a paused import can resume without unzipping again; once the run is finished
        // it is a second, uncompressed copy of every record and every media file in the bundle —
        // including the encrypted credential block — kept for no reader. The export side has
        // pruned its equivalent since `OUTSTANDING.md` §3; this is the half that was missed.
        //
        // After the credentials, because that is the last thing to read from it. A paused run
        // keeps its tree, which is what makes Continue cheap.
        File::deleteDirectory($run->directory . '/extracted');

        $tally = $run->tally();

        $run->set('status', Run::STATUS_COMPLETED)
            ->set('finished_at', now()->toDateTimeString())
            ->set('updated_at', now()->toDateTimeString())
            ->save();

        return [
            'done'      => true,
            'run_id'    => $run->id,
            'processed' => array_sum($tally),
            'total'     => $total,
            'message'   => sprintf(
                'Import finished — %s created, %s updated, %s unchanged or skipped, %s failed.',
                number_format($tally['created']),
                number_format($tally['updated']),
                number_format($tally['skipped']),
                number_format($tally['failed'])
            ),
        ];
    }

    /**
     * Open and write the encrypted credentials, if the bundle has any and a passphrase was given.
     *
     * **Applied last, deliberately.** A passphrase problem then costs nothing that already
     * succeeded — the content import is finished and committed before this is attempted. A wrong
     * passphrase fails GCM authentication, credentials are **skipped**, the screen says so plainly,
     * and the rest of the run still reports success. Never a half-written or nulled secret.
     */
    private function applyCredentials(Run $run, BundleReader $reader): void
    {
        $manifest = $reader->manifest();

        if (! $manifest->hasCredentials()) {
            return;
        }

        $passphrase = (string) $run->passphrase();

        if ($passphrase === '') {
            $run->set('credentials', ['applied' => [], 'skipped_reason' => 'no passphrase given'])
                ->addErrors(
                    'This bundle carries encrypted credentials, which were left alone because no '
                    . 'passphrase was entered. Everything else imported normally. Enter the '
                    . 'passphrase and press Import again to apply just those.'
                );

            return;
        }

        try {
            $vault = app(CredentialVault::class);

            $groups = $vault->open(
                $reader->extractedPath() . '/' . CredentialVault::FILENAME,
                $manifest->credentials(),
                $passphrase
            );

            $applied = $vault->apply($groups);

            // The **outcome only** — which groups were written. Never a value, and never the
            // passphrase, which is not stored anywhere at any point.
            $run->set('credentials', ['applied' => $applied]);

            if ($applied !== []) {
                $run->addErrors(sprintf(
                    'Credentials applied: %s. Check them on the settings screens before trusting '
                    . 'them — copying live gateway keys to a second site means that site can charge '
                    . 'real cards.',
                    implode(', ', $applied)
                ));
            }
        } catch (Throwable $e) {
            // Fails closed: this install's existing credentials are untouched rather than nulled.
            $run->set('credentials', ['applied' => [], 'skipped_reason' => 'passphrase or block rejected'])
                ->addErrors($e->getMessage());
        }
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

    /** @param array<string,array<string,int|string>> $report */
    private function describe(array $report, bool $overwriting): string
    {
        $parts = [];

        foreach ($report as $row) {
            // The clash wording is the whole point of the preview, so it says what will actually
            // happen rather than naming a mode. "Kept alongside" is the sentence an operator needs
            // to understand that nothing of theirs is going and nothing of the bundle's is either.
            $parts[] = sprintf(
                '%s: %d new, %d %s, %d unchanged%s',
                $row['label'],
                $row['new'],
                $row['update'],
                $this->clashWording($row, $overwriting),
                $row['unchanged'],
                $row['unplaceable'] ? sprintf(', %d cannot be placed', $row['unplaceable']) : ''
            );
        }

        if ($parts === []) {
            return 'This bundle carries nothing this site can import.';
        }

        $guarded = array_values(array_filter(
            $report,
            static fn (array $row) => ($row['guarded'] ?? false) === true && (int) $row['update'] > 0
        ));

        return 'Nothing has been written yet. ' . implode(' · ', $parts)
            . ($overwriting
                ? "

Overwriting is ON. Records here will be replaced and that cannot be undone, except for pages."
                : ($guarded === []
                    ? "

Nothing here will be replaced and nothing in the bundle will be dropped."
                    : "

Nothing here will be replaced. " . $this->guardedSentence($guarded)));
    }

    /**
     * What will happen to one resource's clashes, in the operator's terms.
     *
     * @param  array<string,mixed>  $row
     */
    private function clashWording(array $row, bool $overwriting): string
    {
        if ($overwriting) {
            return 'will REPLACE what is here';
        }

        // A merge that replaces the operator's own work is refused without the switch, so the
        // preview must say "left alone" and not "added alongside": there is no free name for a
        // settings group or a theme slug, and pretending otherwise is what made the old wording
        // describe the opposite of what happened.
        if (($row['guarded'] ?? false) === true) {
            return 'clash and will be LEFT ALONE until you turn overwriting on';
        }

        // An identity merge: one email is one person, one content hash is one picture. There is no
        // second record to keep, and updating in place loses nothing.
        if (($row['merges'] ?? false) === true) {
            return 'are the same records and will be updated in place';
        }

        return 'clash and will be added alongside under a free name';
    }

    /**
     * The sentence naming the resources a non-overwriting import will decline to touch.
     *
     * @param  array<int,array<string,mixed>>  $guarded
     */
    private function guardedSentence(array $guarded): string
    {
        $labels = array_map(static fn (array $row) => strtolower((string) $row['label']), $guarded);

        return sprintf(
            'Because of that, the %s in this bundle will be left out rather than replacing what is '
            . 'here — they have no free name to sit alongside under. Turn on overwriting and import '
            . 'again to bring them in.',
            implode(' and ', array_filter([
                implode(', ', array_slice($labels, 0, -1)),
                end($labels) ?: '',
            ]))
        );
    }

    private function reader(Run $run): BundleReader
    {
        $bundle = $run->bundlePath();

        if ($bundle === null) {
            throw new \RuntimeException('This run has no bundle — upload one and start again.');
        }

        return new BundleReader($bundle, $run->directory . '/extracted');
    }
}
