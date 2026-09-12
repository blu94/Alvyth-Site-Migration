<?php

namespace Plugin\SiteMigration\Backend\Runs;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;

/**
 * Every run this install has performed, as directories under `storage/app/site-migration`.
 *
 * ```
 * storage/app/site-migration/alvyth/
 *   20260812-141233-a7f3/
 *     state.json          what the run is, where it got to, what it tallied
 *     bundle.zip          the bundle written (export) or uploaded (import)
 *     idmap/products.ndjson
 * ```
 *
 * **Off the public disk, deliberately.** A bundle can contain everything the operator authored
 * and, if they opted into record groups, other people's names and addresses. `storage/app` is
 * not web-reachable; `storage/app/public` is. Nothing here is ever written to the latter.
 *
 * **Scoped by database, exactly as core scopes its own manifests.** `Theme::activeConfigPath()`
 * and `PluginRegistry::manifestPath()` both end in `active-{database}.json` for a reason this
 * package needed too: one `storage/app` can be shared by more than one database, and a run is
 * about the database it walked, not about the directory it happens to sit in. Unscoped, the test
 * database's runs and the live site's runs were the same set of directories — so the suite's own
 * teardown deleted an operator's bundles, and two sites under one install would have shown each
 * other's history.
 *
 * **Ids are timestamps, not a sequence.** There is no database to hold a counter, and scanning
 * the directory for the highest number and adding one is a race with itself the moment two
 * requests overlap. `Ymd-His` plus four hex characters is unique in practice, sorts
 * lexicographically into chronological order — which is what the history listing wants — and
 * reads as a date to anybody looking at the directory over SFTP.
 */
class RunStore
{
    /**
     * One `Run` object per id, for the life of the request.
     *
     * **Not a cache — an identity map, and it is load-bearing.** A run's state is written to disk
     * only when the surrounding transaction commits ({@see Run::save()}), so between a write and
     * the commit the file on disk is deliberately behind. Two handles to one run would then
     * disagree, and the one that re-read from disk would win — which is how a finished export
     * rendered as still running. Handing back the same object makes that impossible.
     *
     * Static because nothing binds this class as a singleton: `app(RunStore::class)` builds a new
     * instance every time it is called, and several callers do.
     *
     * @var array<string,Run>
     */
    private static array $live = [];

    /** Drop the identity map. For tests, which run many runs through one process. */
    public static function forgetLive(): void
    {
        self::$live = [];
    }

    /**
     * Where this database's runs live.
     *
     * The segment is derived exactly as `PluginRegistry::manifestPath()` derives its own, down to
     * the character class: it is only ever a database identifier, but it becomes a directory name,
     * so it is constrained rather than trusted.
     */
    public function root(): string
    {
        $connection = (string) config('database.default');
        $database   = (string) config("database.connections.{$connection}.database");

        $safe = preg_replace('/[^A-Za-z0-9_.-]/', '_', $database) ?: 'default';

        return storage_path('app/site-migration/' . $safe);
    }

    /**
     * Start a run.
     *
     * @param  array<string,mixed>  $selection
     */
    public function create(string $direction, array $selection = []): Run
    {
        $id = date('Ymd-His') . '-' . bin2hex(random_bytes(2));

        return self::$live[$id] = Run::make($id, $this->root() . '/' . $id, [
            'id'         => $id,
            'direction'  => $direction,
            'status'     => Run::STATUS_PENDING,
            'selection'  => $selection,
            'manifest'   => null,
            'cursor'     => [],
            'tally'      => ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0],
            'errors'     => [],
            'credentials' => null,
            'user_id'    => Auth::id(),
            'user_name'  => Auth::user()?->name ?? Auth::user()?->username,
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);
    }

    /**
     * One run by id, if it belongs to whoever is asking.
     *
     * The id reaches this from a form field, so it is constrained to the shape `create()` issues
     * rather than trusted. Without that a value of `../../../themes` would resolve to a
     * directory this class would then happily delete.
     *
     * **Ownership is checked here rather than on the screens**, because every screen reaches a run
     * through this method and a check on four call sites is a check somebody will forget on the
     * fifth. A run is not a shared object: its bundle is an entire site, and publishing a download
     * link for one, resuming it, or deleting it are all things only the operator who started it
     * should be doing. A super admin sees everything, which is what makes a stuck run recoverable.
     */
    public function find(?string $id): ?Run
    {
        if (! is_string($id) || preg_match('/^\d{8}-\d{6}-[0-9a-f]{4}$/', $id) !== 1) {
            return null;
        }

        $run = self::$live[$id] ?? Run::load($this->root() . '/' . $id);

        if ($run === null) {
            return null;
        }

        // Checked on the way out, so the identity map cannot be used to step around it.
        if (! $this->belongsToCaller($run)) {
            return null;
        }

        return self::$live[$id] = $run;
    }

    /**
     * Whether the current operator may see this run.
     *
     * A run written before ownership was recorded, or by a process with no authenticated user
     * (the console), carries no `user_id`. Those are visible to everybody rather than to nobody:
     * hiding a run that predates the rule would strand its bundle on the disk with no screen able
     * to remove it, which is a worse outcome than the one the rule prevents.
     */
    private function belongsToCaller(Run $run): bool
    {
        $owner  = $run->get('user_id');
        $caller = Auth::id();

        if ($owner === null || $caller === null) {
            return true;
        }

        return (int) $owner === (int) $caller
            || (bool) Auth::user()?->hasRole('super_admin');
    }

    /**
     * Runs, newest first.
     *
     * @return array<int,Run>
     */
    public function all(int $limit = 50, ?string $direction = null): array
    {
        $root = $this->root();

        if (! is_dir($root)) {
            return [];
        }

        $directories = glob($root . '/*', GLOB_ONLYDIR) ?: [];

        // The id sorts chronologically, so reversing the directory listing is the sort. No
        // `filemtime` call per entry, which would be one stat per run to answer a question the
        // name already answers.
        rsort($directories, SORT_STRING);

        $runs = [];

        foreach ($directories as $directory) {
            if (count($runs) >= $limit) {
                break;
            }

            $id  = basename($directory);
            $run = self::$live[$id] ?? Run::load($directory);

            if ($run === null) {
                continue;
            }

            if ($direction !== null && $run->direction() !== $direction) {
                continue;
            }

            // Same rule as `find()`: the History screen lists what the caller may act on, and
            // `latest()` reads through here, so a Continue button never resumes somebody else's run.
            if (! $this->belongsToCaller($run)) {
                continue;
            }

            $runs[] = self::$live[$id] = $run;
        }

        return $runs;
    }

    /** The most recent run in a direction, which is the one a Continue button resumes. */
    public function latest(string $direction): ?Run
    {
        return $this->all(1, $direction)[0] ?? null;
    }

    /**
     * The selection an operator last exported with, so the next visit does not start from scratch.
     *
     * **One remembered selection, not named presets.** Named presets are a management surface of
     * their own — create, rename, delete, which is the default — and the problem an operator
     * actually has is "give me what I did last time". Pushing staging to production is the same
     * export every week, and re-ticking it by hand is where a mis-selection creeps in: the kind
     * that is invisible until the destination turns out to be missing something.
     *
     * Kept beside the runs rather than in a run, because it outlives any one of them — including
     * the ones an operator deletes to reclaim disk.
     *
     * @return array<string,mixed>|null
     */
    public function lastSelection(): ?array
    {
        $path = $this->root() . '/last-export.json';

        if (! is_file($path)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : null;
    }

    /**
     * Remember a selection for next time.
     *
     * **The passphrase is not part of a selection and never reaches here** — it lives on the run
     * in memory for one press. What is remembered is which resources were ticked and whether media
     * and credentials were wanted, which is the shape of the choice rather than its secret.
     *
     * @param  array<string,mixed>  $selection
     */
    public function rememberSelection(array $selection): void
    {
        File::ensureDirectoryExists($this->root());

        file_put_contents(
            $this->root() . '/last-export.json',
            json_encode(array_intersect_key($selection, array_flip([
                // The **exclusion** is what is remembered, not the resulting inclusion. A version
                // that added a driver would otherwise replay last week's inclusion list and
                // silently leave the new resource out of every future export.
                // `include_code` is deliberately absent: a remembered selection must not be able
                // to carry somebody else's theme and plugin files into next week's export.
                'exclude', 'include_media', 'on_conflict',
            ])), JSON_PRETTY_PRINT)
        );
    }

    /**
     * Remove a run and everything it wrote — state, id map and bundle.
     *
     * The records an import created are untouched. Deleting the history of a run is not undoing
     * it, and a screen that quietly did both would be the most expensive misunderstanding this
     * package could offer.
     */
    public function delete(string $id): bool
    {
        $run = $this->find($id);

        if ($run === null) {
            return false;
        }

        unset(self::$live[$run->id]);

        return File::deleteDirectory($run->directory);
    }
}
