<?php

namespace Plugin\SiteMigration\Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Plugin\SiteMigration\Backend\Runs\Run;
use Plugin\SiteMigration\Backend\Runs\RunStore;

require_once __DIR__ . '/autoload.php';

/**
 * Where runs live, and who can reach them.
 *
 * Two properties that had no test and cost real things when they were false: the suite deleted the
 * dev install's bundles because the run directory was not scoped to a database, and any operator
 * could publish a download link for any other operator's bundle because ownership was recorded and
 * never read.
 */
class RunStorageTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * The run directory names the database it belongs to.
     *
     * `DB_DATABASE=alvyth_test` used to change the database and not the directory, so following the
     * documented way to run this suite emptied `storage/app/site-migration` — every bundle and every
     * history entry the dev install had. Core scopes its own manifests the same way and for the same
     * reason; this asserts the package does too.
     */
    #[Test]
    public function the_run_root_is_scoped_to_this_database(): void
    {
        $database = (string) config('database.connections.' . config('database.default') . '.database');
        $root     = str_replace('\\', '/', app(RunStore::class)->root());

        $this->assertStringEndsWith('/' . $database, $root);

        $this->assertNotSame(
            str_replace('\\', '/', storage_path('app/site-migration')),
            $root,
            'The run root is the unscoped directory, so this suite can reach another database\'s runs.'
        );
    }

    /**
     * A run belongs to the operator who started it.
     *
     * A bundle is an entire site. Resuming somebody else's run, publishing a download link for it or
     * deleting it are not things a second operator holding the same permission should be able to do
     * by pasting an id.
     */
    #[Test]
    public function one_operator_cannot_reach_another_operators_run(): void
    {
        $mine = $this->actingAsOperator(['site_migration.create']);

        $run = app(RunStore::class)->create(Run::DIRECTION_EXPORT, ['modules' => ['tags']]);

        $this->assertNotNull(app(RunStore::class)->find($run->id), 'An operator lost their own run.');

        // A different operator, holding exactly the same permission.
        $this->actingAsOperator(['site_migration.create']);
        RunStore::forgetLive();

        $this->assertNull(
            app(RunStore::class)->find($run->id),
            'A second operator reached a run they did not start.'
        );

        $this->assertSame(
            [],
            array_filter(
                app(RunStore::class)->all(),
                static fn (Run $found) => $found->id === $run->id
            ),
            'Another operator\'s run appeared in the history listing.'
        );

        // A super admin can still reach it, which is what makes a stuck run recoverable.
        $this->actingAsSuperAdmin();
        RunStore::forgetLive();

        $this->assertNotNull(
            app(RunStore::class)->find($run->id),
            'A super admin could not reach a run, so a stuck one could never be cleared.'
        );

        $this->assertSame($mine->getKey(), app(RunStore::class)->find($run->id)?->get('user_id'));
    }

    /**
     * Two handles to one run are the same object.
     *
     * The state file is written when the surrounding transaction commits, so between a write and
     * that commit the copy on disk is deliberately behind. A second handle that re-read the file
     * would disagree with the first — which is how a finished export rendered as still running.
     */
    #[Test]
    public function two_handles_to_one_run_do_not_diverge(): void
    {
        $this->actingAsSuperAdmin();

        $run = app(RunStore::class)->create(Run::DIRECTION_IMPORT, ['modules' => ['tags']]);

        $run->set('status', Run::STATUS_COMPLETED)->save();

        $this->assertSame(
            Run::STATUS_COMPLETED,
            app(RunStore::class)->find($run->id)?->status(),
            'A second handle to the same run reported stale state.'
        );
    }
}
