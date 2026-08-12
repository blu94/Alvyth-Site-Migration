<?php

namespace Plugin\SiteMigration\Backend\Support;

/**
 * The canonical form a record's content hash is taken over.
 *
 * **Why a hash at all.** The normal case is not one migration, it is many: staging is pushed to
 * production repeatedly and almost nothing changes between pushes. Without a hash every re-run
 * rewrites the entire catalogue to change nothing, and the operator pays full price for a no-op.
 * With one, a second migration between the same two sites is close to free — and the dry run
 * becomes affordable, because comparing one hash is what it costs instead of comparing every
 * field of every record.
 *
 * **Why the canonical form is specified here rather than left to `json_encode`.** The hash is
 * written by the *source* and recomputed by the *destination* over its own record, so both sides
 * must produce byte-identical input from two different databases. Get any part of that wrong and
 * every record compares as changed: the plugin still works, it just never skips anything, and
 * **nothing reports why**. That is the failure this class exists to make impossible, which is
 * also why it is pinned by a test that hashes a fixture rather than by care.
 *
 * Five rules, each answering a way the two sides would otherwise differ:
 *
 * 1. **Ids and timestamps are excluded.** They differ by definition — that is the whole premise
 *    of matching on a natural key — so including them would make every record differ.
 * 2. **Keys are sorted, at every depth.** PHP preserves insertion order and Eloquent's attribute
 *    order follows the column order of a `SELECT *`, which two installs can differ on after one
 *    of them adds a column.
 * 3. **Translatable arrays sort by locale**, for the same reason: Spatie stores them as JSON
 *    objects and the key order is whatever was written.
 * 4. **Floats are formatted to a fixed scale.** `1.5` and `1.50` are the same price and different
 *    strings, and a `decimal:2` cast returns a string on one side and a float on the other.
 * 5. **Booleans and nulls are normalised.** MySQL hands back `"1"` where a fresh model has `true`.
 */
class Canonical
{
    /**
     * Excluded from every resource's hash.
     *
     * `_hash` itself is on the list for the obvious reason — a hash cannot cover itself — and
     * `_source` because it describes the bundle, not the record.
     */
    public const ALWAYS_EXCLUDED = [
        'id',
        '_hash',
        '_source',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    /** Decimal places every float is rendered at. Money is the tightest constraint here. */
    private const SCALE = 6;

    /**
     * The hash of a record's content.
     *
     * @param  array<string,mixed>  $record
     * @param  array<int,string>  $exclude  the driver's own volatile fields
     */
    public static function hash(array $record, array $exclude = []): string
    {
        return hash('sha256', self::encode($record, $exclude));
    }

    /**
     * The exact bytes the hash is taken over.
     *
     * Exposed separately from {@see hash()} because when two sides disagree, the useful question
     * is *which field* differs, and a test that can print this answers it in one line where
     * comparing two hashes cannot.
     *
     * @param  array<string,mixed>  $record
     * @param  array<int,string>  $exclude
     */
    public static function encode(array $record, array $exclude = []): string
    {
        $drop = array_flip(array_merge(self::ALWAYS_EXCLUDED, $exclude));

        return (string) json_encode(
            self::normalise(array_diff_key($record, $drop)),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }

    /**
     * Recursively put a value into its one canonical shape.
     *
     * Note the list/map distinction: a **list** keeps its order, because the order of a page's
     * builder nodes is content and sorting it would be a lie. A **map** is sorted, because its
     * order is an artefact of however it was serialised.
     */
    private static function normalise(mixed $value): mixed
    {
        if (is_array($value)) {
            if (self::isList($value)) {
                return array_map([self::class, 'normalise'], $value);
            }

            ksort($value, SORT_STRING);

            return array_map([self::class, 'normalise'], $value);
        }

        if (is_bool($value)) {
            return $value;
        }

        if ($value === null || $value === '') {
            // An absent value and an empty string are the same absence as far as content goes,
            // and the two databases will not agree on which they hold: a column with no default
            // reads back `null` on one install and `""` on another that saved a cleared form.
            return null;
        }

        if (is_float($value) || is_int($value)) {
            return self::number($value);
        }

        if (is_string($value) && self::isPlainDecimal($value)) {
            return self::number((float) $value);
        }

        return $value;
    }

    /**
     * Whether a string is a plain decimal, and therefore safe to read as a number.
     *
     * **Deliberately narrower than `is_numeric`,** because two shapes it accepts are traps:
     *
     * - **Exponent notation.** A SKU of `1e5` is numeric, and reading it as a number would hash
     *   that product as though its SKU were `100000` — so two genuinely different products could
     *   collide, and the second would be reported as an unchanged duplicate of the first.
     * - **Leading zeros.** SKUs like `0012` are ordinary, and `0012` → `12` would collide with the
     *   product whose SKU actually is `12`.
     *
     * What is left is the case this exists for: a `decimal:2` cast returning `"19.90"` where the
     * other side holds `19.9`. Those are the same price written two ways, and a canonical form
     * that could not see that would make every priced record compare as changed.
     */
    private static function isPlainDecimal(string $value): bool
    {
        return preg_match('/^-?(0|[1-9]\d*)(\.\d+)?$/', $value) === 1;
    }

    /**
     * A number as a fixed-scale string, with trailing zeros removed.
     *
     * Both halves are needed. `number_format` alone makes `1.5` and `1.50` agree; stripping the
     * zeros afterwards makes `2` and `2.000000` agree too, which matters because an integer
     * column and a `decimal:2` cast holding the same value arrive here as different PHP types.
     */
    private static function number(int|float $value): string
    {
        $formatted = number_format((float) $value, self::SCALE, '.', '');

        if (! str_contains($formatted, '.')) {
            return $formatted;
        }

        $trimmed = rtrim(rtrim($formatted, '0'), '.');

        return $trimmed === '' || $trimmed === '-' ? '0' : $trimmed;
    }

    /** @param array<mixed> $value */
    private static function isList(array $value): bool
    {
        return $value === [] || array_keys($value) === range(0, count($value) - 1);
    }
}
