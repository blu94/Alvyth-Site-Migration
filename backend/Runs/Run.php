<?php

namespace Plugin\SiteMigration\Backend\Runs;

use Illuminate\Support\Facades\File;

/**
 * One export or one import, from the button press to the last record written.
 *
 * **Backed by a file, not a database table**, and that is a decision worth stating because the
 * obvious reading of "a run needs state" is a migration.
 *
 * A run needs state for one reason: a plugin ships no admin Vue component, so there is no
 * client-side chunking loop and nothing in the browser holds the cursor between steps. (Core's
 * spreadsheet importer *deleted* its own `imports` table precisely because the browser did hold
 * that state there, and a server row could only drift from the client that owned the truth.
 * That reasoning does not transfer — but it argues for state existing, not for a table.)
 *
 * A cache entry is genuinely wrong: `.env.install` ships `CACHE_STORE=file`, entries can evict
 * between two steps of a resumable run, and losing the map mid-import orphans every relation
 * written after that point. A file cannot evict.
 *
 * A table would buy exactly two things — a DataTables-backed history screen, since
 * `baseIndexQuery()` must return a query builder and a directory cannot answer that, and an
 * indexed `whereIn` for the rewrite pass. Neither is load-bearing for export → bundle → import,
 * so the package ships no migrations at all: nothing to create on enable, nothing to drop on
 * uninstall, and no schema of ours in a database shared with core.
 *
 * **Named `Run`, not `Migration`.** Laravel already owns that word for schema versioning, and
 * this package would otherwise carry both senses at once. The word appears in the slug, the
 * display name, the permission resource and the module type, and never in a class name — so the
 * artifacts are `Run`, `IdMap`, `BundleWriter`, `BundleReader`, named for the thing and the
 * action.
 */
class Run
{
    public const DIRECTION_EXPORT = 'export';
    public const DIRECTION_IMPORT = 'import';

    public const DIRECTIONS = [self::DIRECTION_EXPORT, self::DIRECTION_IMPORT];

    public const STATUS_PENDING   = 'pending';
    public const STATUS_RUNNING   = 'running';
    public const STATUS_PAUSED    = 'paused';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED    = 'failed';

    /**
     * How many errors a run keeps.
     *
     * An import of a bad bundle can fail on every one of forty thousand rows. Forty thousand
     * messages is a file nobody opens and a screen nobody reads; the first fifty say the same
     * thing.
     */
    public const MAX_ERRORS = 50;

    /** @param array<string,mixed> $state */
    private function __construct(
        public readonly string $id,
        public readonly string $directory,
        private array $state,
    ) {
    }

    /**
     * Read a run from disk, or null.
     *
     * A corrupt or truncated `state.json` reads as absent rather than throwing. The file is
     * written by us and should never be either, but a half-written file is exactly what a host
     * that ran out of disk mid-step leaves behind, and a 500 on the history of a failed run is
     * the least useful moment to raise one.
     */
    public static function load(string $directory): ?self
    {
        $path = $directory . '/state.json';

        if (! is_file($path)) {
            return null;
        }

        $state = json_decode((string) file_get_contents($path), true);

        if (! is_array($state)) {
            return null;
        }

        return new self(basename($directory), $directory, $state);
    }

    /** @param array<string,mixed> $state */
    public static function make(string $id, string $directory, array $state): self
    {
        File::ensureDirectoryExists($directory);

        $run = new self($id, $directory, $state);
        $run->save();

        return $run;
    }

    /**
     * Persist.
     *
     * Written to a sibling and renamed, so a step interrupted mid-write leaves the previous
     * state intact rather than a truncated file. `rename` is atomic within a filesystem, and a
     * run whose whole purpose is to survive interruption cannot have a window where its own
     * record of where it got to is unreadable.
     */
    public function save(): void
    {
        File::ensureDirectoryExists($this->directory);

        $path = $this->directory . '/state.json';
        $temp = $path . '.writing';

        file_put_contents($temp, json_encode($this->state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        rename($temp, $path);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->state[$key] ?? $default;
    }

    public function set(string $key, mixed $value): static
    {
        $this->state[$key] = $value;

        return $this;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->state + ['id' => $this->id];
    }

    public function direction(): string
    {
        return (string) $this->get('direction', self::DIRECTION_EXPORT);
    }

    public function status(): string
    {
        return (string) $this->get('status', self::STATUS_PENDING);
    }

    public function isFinished(): bool
    {
        return in_array($this->status(), [self::STATUS_COMPLETED, self::STATUS_FAILED], true);
    }

    /** @return array<string,mixed> */
    public function selection(): array
    {
        return array_merge([
            'modules'             => [],
            'settings'            => [],
            'records'             => [],
            'include_media'       => true,
            'include_credentials' => false,
            'on_conflict'         => 'skip',
        ], (array) $this->get('selection', []));
    }

    /** Whether the operator asked for an overwrite when a record already exists. */
    public function overwrites(): bool
    {
        return ($this->selection()['on_conflict'] ?? 'skip') === 'overwrite';
    }

    /**
     * A tally with every key present, so a caller never has to null-coalesce four times.
     *
     * @return array<string,int>
     */
    public function tally(): array
    {
        return array_merge(
            ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0],
            (array) $this->get('tally', [])
        );
    }

    /**
     * Add to the tally.
     *
     * Merged rather than replaced, because a step reports what *it* did and a resumed run's
     * total is the sum across steps. Replacing would silently reset the count on every
     * Continue, and the number would look plausible the whole way.
     *
     * @param  array<string,int>  $delta
     */
    public function addTally(array $delta): static
    {
        $tally = $this->tally();

        foreach ($delta as $key => $count) {
            $tally[$key] = ($tally[$key] ?? 0) + (int) $count;
        }

        return $this->set('tally', $tally);
    }

    /**
     * Record a problem, newest first, bounded.
     *
     * @param  array<int,string>|string  $messages
     */
    public function addErrors(array|string $messages): static
    {
        $messages = array_values(array_filter(array_map(
            static fn ($message) => trim((string) $message),
            (array) $messages
        )));

        if ($messages === []) {
            return $this;
        }

        return $this->set('errors', array_slice(
            array_merge($messages, (array) $this->get('errors', [])),
            0,
            self::MAX_ERRORS
        ));
    }

    /** Where the run got to: `{ resource, step, after_id (export) | line (import) }`. */
    public function cursor(): array
    {
        return (array) $this->get('cursor', []);
    }

    public function idMap(): IdMap
    {
        return new IdMap($this->directory . '/idmap');
    }

    /** The bundle this run wrote or was given, if it is still on disk. */
    public function bundlePath(): ?string
    {
        $path = $this->directory . '/bundle.zip';

        return is_file($path) ? $path : null;
    }
}
