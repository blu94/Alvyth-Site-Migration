<?php

namespace Plugin\SiteMigration\Tests;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Plugin\SiteMigration\Backend\Runs\Run;
use Plugin\SiteMigration\Backend\Runs\RunStore;

require_once __DIR__ . '/autoload.php';

/**
 * A cursor is a promise that the records before it are in the database.
 *
 * **`DatabaseTransactions` is deliberately absent from this class.** These tests are *about* the
 * transaction boundary — they open and close one themselves — and a wrapping transaction that never
 * commits would make every assertion here vacuous. Nothing in them writes a database row: a run is
 * a directory, so the whole fixture is files, and `clearRuns()` removes it either way.
 *
 * What is being held: `savePageData()` wraps every custom-page call in `DB::beginTransaction()`, so
 * one press of Import is a single transaction, and the state file is not in it. A deadlock, a
 * lock-wait timeout or an execution-time overrun discarded the writes while the file kept the
 * advanced cursor — and Continue then skipped exactly the records that were lost, reporting them as
 * written.
 */
class RunDurabilityTest extends TestCase
{
    #[Test]
    public function state_reaches_the_disk_only_when_the_transaction_commits(): void
    {
        $this->actingAsSuperAdmin();

        DB::beginTransaction();

        $run   = app(RunStore::class)->create(Run::DIRECTION_IMPORT, ['modules' => ['tags']]);
        $state = $run->directory . '/state.json';

        $this->assertFileDoesNotExist(
            $state,
            'A run recorded its progress before the writes it describes were committed.'
        );

        // The in-memory run is still authoritative for the request, which is what lets the screen
        // render the step it just finished without reading the file.
        $this->assertSame(Run::STATUS_PENDING, $run->status());

        DB::commit();

        $this->assertFileExists($state, 'A committed run never recorded where it got to.');
    }

    #[Test]
    public function a_rolled_back_step_leaves_the_cursor_where_it_was(): void
    {
        $this->actingAsSuperAdmin();

        // A run that genuinely finished a step: created and committed.
        DB::beginTransaction();
        $run = app(RunStore::class)->create(Run::DIRECTION_IMPORT, ['modules' => ['tags']]);
        $run->set('cursor', ['resource' => 'products', 'line' => 100, 'done' => []])->save();
        DB::commit();

        $this->assertSame(100, Run::load($run->directory)?->cursor()['line']);

        // A second step that writes records, advances the cursor, and is then rolled back under it.
        DB::beginTransaction();
        $run->set('cursor', ['resource' => 'products', 'line' => 200, 'done' => ['products' => true]])
            ->addTally(['created' => 100])
            ->save();
        DB::rollBack();

        $onDisk = Run::load($run->directory);

        $this->assertSame(
            100,
            $onDisk?->cursor()['line'],
            'The cursor advanced past records whose writes were rolled back, so Continue would skip '
            . 'them and the tally would report them as written.'
        );

        $this->assertSame(
            [],
            $onDisk?->cursor()['done'],
            'A resource was marked finished by a step that did not commit.'
        );

        $this->assertSame(0, $onDisk?->tally()['created'], 'A rolled-back step still counted its writes.');
    }

    /** Outside a transaction — the console, and the export side — the write is immediate. */
    #[Test]
    public function state_is_written_immediately_when_there_is_no_transaction(): void
    {
        $this->actingAsSuperAdmin();

        $this->assertSame(0, DB::transactionLevel(), 'This test needs to run outside a transaction.');

        $run = app(RunStore::class)->create(Run::DIRECTION_EXPORT, ['modules' => ['tags']]);

        $this->assertFileExists($run->directory . '/state.json');
    }
}
