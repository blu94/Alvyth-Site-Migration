<?php

namespace Plugin\SiteMigration\Backend\Runs;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;

/**
 * Every run this install has performed, as directories under `storage/app/site-migration`.
 *
 * ```
 * storage/app/site-migration/
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
 * **Ids are timestamps, not a sequence.** There is no database to hold a counter, and scanning
 * the directory for the highest number and adding one is a race with itself the moment two
 * requests overlap. `Ymd-His` plus four hex characters is unique in practice, sorts
 * lexicographically into chronological order — which is what the history listing wants — and
 * reads as a date to anybody looking at the directory over SFTP.
 */
class RunStore
{
    public function root(): string
    {
        return storage_path('app/site-migration');
    }

    /**
     * Start a run.
     *
     * @param  array<string,mixed>  $selection
     */
    public function create(string $direction, array $selection = []): Run
    {
        $id = date('Ymd-His') . '-' . bin2hex(random_bytes(2));

        return Run::make($id, $this->root() . '/' . $id, [
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
     * One run by id.
     *
     * The id reaches this from a form field, so it is constrained to the shape `create()` issues
     * rather than trusted. Without that a value of `../../../themes` would resolve to a
     * directory this class would then happily delete.
     */
    public function find(?string $id): ?Run
    {
        if (! is_string($id) || preg_match('/^\d{8}-\d{6}-[0-9a-f]{4}$/', $id) !== 1) {
            return null;
        }

        return Run::load($this->root() . '/' . $id);
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

            $run = Run::load($directory);

            if ($run === null) {
                continue;
            }

            if ($direction !== null && $run->direction() !== $direction) {
                continue;
            }

            $runs[] = $run;
        }

        return $runs;
    }

    /** The most recent run in a direction, which is the one a Continue button resumes. */
    public function latest(string $direction): ?Run
    {
        return $this->all(1, $direction)[0] ?? null;
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

        return File::deleteDirectory($run->directory);
    }
}
