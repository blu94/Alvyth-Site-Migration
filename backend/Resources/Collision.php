<?php

namespace Plugin\SiteMigration\Backend\Resources;

/**
 * What happens when an incoming record's natural key is already taken here.
 *
 * **No record is ever dropped, and that is the rule the rest of this class serves.** The earlier
 * design offered "leave mine alone", which quietly meant *discard theirs* — a migration that
 * reported success while losing data the operator had asked it to carry. Losing an incoming record
 * is never the right answer to a name collision.
 *
 * So there are two outcomes, and both keep everything:
 *
 * - {@see OVERWRITE} — the incoming record replaces the one here. Destructive to *this* site, which
 *   is why it sits behind a confirmation dialog the operator has to accept.
 * - {@see KEEP_BOTH} — the incoming record is written alongside, under a fresh key that is not
 *   taken. An invoice takes this site's next number; a product gets a free SKU; a slug gets a
 *   suffix. The old→new pair goes in the id map, so anything that referenced the record still
 *   finds it.
 *
 * **`KEEP_BOTH` deliberately creates duplicates.** That is the honest reading of "do not overwrite
 * my data, and do not throw theirs away": two records that claimed one name become two records with
 * two names. The operator can merge them afterwards knowing nothing was lost, which is not a
 * position they can get back to once a record has been silently skipped.
 */
final class Collision
{
    public const OVERWRITE = 'overwrite';
    public const KEEP_BOTH = 'keep_both';

    /**
     * How many suffixes to try before giving up.
     *
     * A bound rather than a `while (true)`: a driver whose `exists()` is wrong would otherwise spin
     * the request to its timeout with no clue why. Two hundred collisions on one key means
     * something is wrong that another attempt will not fix.
     */
    private const MAX_ATTEMPTS = 200;

    /**
     * A key like the one given that nothing here is using.
     *
     * `hat` → `hat-2`, `hat-3`, … and `INV-0007` → `INV-0007-2`. Deliberately a readable suffix
     * rather than a random one: an operator looking at two similar rows needs to see at a glance
     * which arrived in the migration, and `hat-2` says that where `hat-f3a91c` says only that
     * something went wrong.
     *
     * @param  callable(string): bool  $taken  whether a candidate is already in use
     */
    public static function freeKey(string $wanted, callable $taken): string
    {
        $wanted = trim($wanted);

        if ($wanted === '' || ! $taken($wanted)) {
            return $wanted;
        }

        for ($n = 2; $n <= self::MAX_ATTEMPTS; $n++) {
            $candidate = $wanted . '-' . $n;

            if (! $taken($candidate)) {
                return $candidate;
            }
        }

        // Past the bound, fall back to something that cannot collide rather than throwing. Losing
        // the record is the one outcome this class exists to prevent, and an ugly key is recoverable
        // where a dropped row is not.
        return $wanted . '-' . bin2hex(random_bytes(4));
    }

    /**
     * The same, for a translated key.
     *
     * A slug is a locale map, and the suffix has to land on every locale or the record is
     * `hat-2` in English and still colliding `hut` in German.
     *
     * @param  array<string,string>|string|null  $wanted
     * @param  callable(string): bool  $taken
     * @return array<string,string>|string|null
     */
    public static function freeTranslatedKey(array|string|null $wanted, callable $taken): array|string|null
    {
        if (! is_array($wanted)) {
            return is_string($wanted) ? self::freeKey($wanted, $taken) : $wanted;
        }

        if ($wanted === []) {
            return $wanted;
        }

        // One suffix decided from the first locale and applied to all of them, rather than each
        // locale resolved independently — otherwise the same record ends up as `hat-2` and `hut-5`,
        // which reads as two unrelated things.
        $first = (string) reset($wanted);
        $free  = self::freeKey($first, $taken);

        if ($free === $first) {
            return $wanted;
        }

        $suffix = substr($free, strlen($first));

        return array_map(static fn ($value) => $value . $suffix, $wanted);
    }
}
