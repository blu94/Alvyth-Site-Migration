<?php

namespace Plugin\SiteMigration\Backend\Support;

/**
 * A byte count as a human reads it.
 *
 * **One implementation, because there were two and they had already drifted.** The export screen
 * and the exporter's size warning each carried their own copy of this loop, differing in what they
 * returned when they ran out of units — and both shared the same defect: they cast to `int` on
 * every division, so the fraction was gone before `round($bytes, 1)` could use it. The
 * 1,533,667-byte bundle recorded in `OUTSTANDING.md` §10 displayed as "1 MB".
 *
 * That is cosmetic until you notice where the number is read: the History screen offers it as the
 * figure an operator picks which run to delete on, and "1 MB" against "1 MB" is not a choice.
 */
final class Bytes
{
    /** Units, smallest first. `bytes` is spelled out because "1 B" reads as a typo. */
    private const UNITS = ['bytes', 'KB', 'MB', 'GB', 'TB'];

    /**
     * Format a byte count.
     *
     * The running value stays a float the whole way down, so the precision survives to the point
     * where it is wanted. Whole bytes are printed as integers — "512 bytes", never "512.0 bytes" —
     * and everything above them keeps one decimal place, which is the resolution somebody
     * comparing two bundles actually uses.
     */
    public static function human(int $bytes): string
    {
        if ($bytes < 0) {
            return '0 bytes';
        }

        $value = (float) $bytes;
        $unit  = 0;
        $last  = count(self::UNITS) - 1;

        while ($value >= 1024 && $unit < $last) {
            $value /= 1024;
            $unit++;
        }

        return $unit === 0
            ? $bytes . ' ' . self::UNITS[0]
            : round($value, 1) . ' ' . self::UNITS[$unit];
    }
}
