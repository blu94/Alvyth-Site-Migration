<?php

namespace Plugin\SiteMigration\Backend\Support;

/**
 * How long one press of the button is allowed to work for.
 *
 * **A step is time-boxed by the server, not by the client, and this is the design's biggest
 * compromise.** Core's spreadsheet importer chunks by having the browser issue one short request
 * per 100 rows until the server says `eof`. That loop lives in a `.vue` file, and a plugin cannot
 * ship one — a schema `request` action fires once and emits `success`; it does not iterate.
 *
 * So each call works until this budget expires, then commits, writes its cursor and returns
 * `{ done: false, processed, total }`. The screen shows **"Continue — 1,200 of 4,300 done"**.
 * Most sites finish in one press; a large one takes a few. The failure mode is a visible Continue
 * button rather than a request that dies at 30 s with a half-written database, which is the trade
 * that matters on the shared hosting this targets.
 *
 * **The budget is wall-clock, not a row count**, because rows differ by orders of magnitude in
 * cost — a page with 200 builder nodes is not a tag. A fixed 100-row window would be instant for
 * one and would time out for the other.
 */
class StepBudget
{
    /**
     * Seconds of work per press.
     *
     * Twenty, against a `max_execution_time` that is commonly 30 on shared hosting. The margin is
     * not padding: the budget governs the *walk*, and the step still has to seal, save state and
     * render a response afterwards. Spending 29 of 30 seconds walking is how a run dies in the
     * one place it cannot record where it got to.
     */
    public const DEFAULT_SECONDS = 20;

    private readonly float $startedAt;

    private readonly float $seconds;

    /**
     * @param  int|float|null  $seconds  overridden only by tests, which need a budget that
     *                                   expires mid-walk to prove a run resumes. Fractions are
     *                                   accepted for exactly that: waiting 20 real seconds per
     *                                   assertion would make the resumability test the slowest
     *                                   thing in the suite, so nobody would run it.
     */
    public function __construct(int|float|null $seconds = null)
    {
        $this->startedAt = microtime(true);
        $this->seconds   = (float) ($seconds ?? self::resolve());
    }

    /** Whether there is still time to process another record. */
    public function hasTime(): bool
    {
        return (microtime(true) - $this->startedAt) < $this->seconds;
    }

    public function elapsed(): float
    {
        return round(microtime(true) - $this->startedAt, 2);
    }

    /**
     * The budget for this host.
     *
     * Read from `max_execution_time` where the host declares one, so a generous host gets longer
     * steps and fewer presses rather than the conservative default. Two thirds of the limit, for
     * the same reason the default leaves a margin — sealing and responding happen after the walk.
     *
     * A limit of `0` means unlimited, which is normal on CLI and means nothing useful here: the
     * step is still bounded by how long an operator will watch a spinner, so the default stands.
     */
    private static function resolve(): int
    {
        $limit = (int) ini_get('max_execution_time');

        if ($limit <= 0) {
            return self::DEFAULT_SECONDS;
        }

        return max(5, min(60, (int) floor($limit * 2 / 3)));
    }
}
