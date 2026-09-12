<?php

namespace Plugin\SiteMigration\Tests;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Plugin\SiteMigration\Backend\Runs\RunStore;
use Tests\TestCase as HostTestCase;

require_once __DIR__ . '/autoload.php';

/**
 * Base for this package's tests.
 *
 * **There is no schema step here, unlike most plugin suites.** This package ships no migrations
 * and owns no tables — a run is a directory under `storage/app/site-migration`, not a row — so
 * the usual "create my tables if they are missing" dance in `refreshApplication()` has nothing to
 * do. What it does need is its own scratch directory, torn down after each test, because a test
 * that leaves runs behind changes what `RunStore::latest()` returns for the next one.
 */
abstract class TestCase extends HostTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->clearRuns();
    }

    protected function tearDown(): void
    {
        $this->clearRuns();

        parent::tearDown();
    }

    /**
     * Sign in as somebody who may do everything.
     *
     * `Auth::login` rather than a Sanctum token: these tests call the repository and the services
     * directly rather than over HTTP, so there is no request for a bearer token to travel on.
     * The permission checks read `Auth::user()`, which this satisfies exactly as a real request
     * would.
     */
    protected function actingAsSuperAdmin(): User
    {
        $user = User::whereHas('roles', fn ($q) => $q->where('name', 'super_admin'))->first()
            ?? User::first();

        $this->assertNotNull($user, 'The test database has no users — seed it before running this suite.');

        Auth::login($user);

        return $user;
    }

    /**
     * Sign in as somebody holding the migration permission and nothing else.
     *
     * Built with Spatie's `syncPermissions` on a role-less user so the grant set is exactly what
     * the test names. Giving the user a role instead would inherit the seeder's blanket grants and
     * the isolation test would pass for the wrong reason.
     *
     * @param  array<int,string>  $permissions
     */
    protected function actingAsOperator(array $permissions): User
    {
        $user = User::factory()->create();

        $user->syncRoles([]);
        $user->syncPermissions($permissions);

        Auth::login($user);

        return $user->fresh();
    }

    /**
     * This database's runs, emptied.
     *
     * **Scoped, and that is the whole point.** `RunStore::root()` now ends in the database name,
     * so running the suite against `alvyth_test` empties `storage/app/site-migration/alvyth_test`
     * and cannot reach `.../alvyth`. It used to reach it: the root was unscoped, `DB_DATABASE`
     * changed the database and not the directory, and so following the documented way to run this
     * suite deleted the dev install's bundles and history — the exact loss `OUTSTANDING.md` §3
     * refuses to risk when it declines an automatic retention policy.
     *
     * Guarded rather than trusted, because the cost of this being wrong is somebody's only copy of
     * a site.
     */
    protected function clearRuns(): void
    {
        RunStore::forgetLive();

        $root     = app(RunStore::class)->root();
        $database = (string) config('database.connections.' . config('database.default') . '.database');

        if (! str_ends_with(str_replace('\\', '/', $root), '/' . $database)) {
            $this->fail("Refusing to empty {$root}: it is not scoped to the {$database} database.");
        }

        if (is_dir($root)) {
            File::deleteDirectory($root);
        }
    }

    /**
     * Press a button until the run says it is finished.
     *
     * Guarded rather than `while (true)`: a step that cannot make progress would otherwise hang
     * the suite instead of failing it, and "the test run never came back" is the least
     * diagnosable outcome available.
     *
     * @param  callable(): array<string,mixed>  $press
     * @return array<string,mixed>
     */
    protected function pressUntilDone(callable $press, int $limit = 500): array
    {
        $result  = ['done' => false];
        $presses = 0;

        while (($result['done'] ?? false) === false) {
            $this->assertLessThan($limit, $presses, 'The run never finished — a step is making no progress.');

            $result = $press();
            $presses++;
        }

        $result['presses'] = $presses;

        return $result;
    }
}
