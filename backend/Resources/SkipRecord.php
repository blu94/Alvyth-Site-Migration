<?php

namespace Plugin\SiteMigration\Backend\Resources;

use RuntimeException;

/**
 * A record that cannot be placed here for a reason that is nobody's fault.
 *
 * **The distinction between this and an ordinary failure is what the tally means.** A failure is
 * something the operator should look at: a malformed record, a column that would not accept a
 * value, a constraint that refused. This is the other thing — the source site had an asset row
 * whose file had already been deleted, so there are no bytes to bring across and never were.
 *
 * Reported as `skipped` rather than `failed`, because twenty-seven "failures" at the end of a
 * migration that in fact worked perfectly reads as a broken tool, and an operator who cannot tell
 * the two apart learns to ignore both.
 */
class SkipRecord extends RuntimeException
{
}
