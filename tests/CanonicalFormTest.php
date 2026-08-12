<?php

namespace Plugin\SiteMigration\Tests;

use PHPUnit\Framework\Attributes\Test;
use Plugin\SiteMigration\Backend\Support\Canonical;

require_once __DIR__ . '/autoload.php';

/**
 * Pins the canonical form the content hash is taken over.
 *
 * **This is the test the specification asks for by name**, and the reason it exists is that
 * getting the canonical form wrong is *silent*. The hash is written by the source and recomputed
 * by the destination over its own record; if the two sides disagree about key order, float
 * formatting or how a null is spelled, then every record compares as changed. The plugin still
 * works — it just never skips anything, an operator's repeat migration costs full price forever,
 * and **nothing reports why**. There is no error to notice. Only a test catches this.
 */
class CanonicalFormTest extends TestCase
{
    /**
     * The same record described two different ways must hash the same.
     *
     * Every difference below is one a real pair of installs produces: MySQL returns `"1"` where a
     * fresh model holds `true`, a `decimal:2` cast gives `"19.90"` where a seeder set `19.9`,
     * attribute order follows whatever `SELECT *` returned, and a cleared field is `null` on one
     * install and `""` on the other.
     */
    #[Test]
    public function two_spellings_of_one_record_hash_alike(): void
    {
        $fromSource = [
            'sku'               => 'HAT-1',
            'title'             => ['en' => 'Hat', 'ms' => 'Topi'],
            'price'             => 19.9,
            'stock'             => 5,
            'requires_shipping' => true,
            'subtitle'          => null,
        ];

        $fromDestination = [
            // Different key order.
            'requires_shipping' => true,
            'price'             => '19.90',
            // Different locale order inside the translation map.
            'title'             => ['ms' => 'Topi', 'en' => 'Hat'],
            'sku'               => 'HAT-1',
            // Empty string where the other side had null.
            'subtitle'          => '',
            'stock'             => 5,
        ];

        $this->assertSame(
            Canonical::hash($fromSource),
            Canonical::hash($fromDestination),
            "Two spellings of the same product hashed differently, so every record would compare "
            . "as changed and nothing would ever be skipped.\nsource:      "
            . Canonical::encode($fromSource) . "\ndestination: " . Canonical::encode($fromDestination)
        );
    }

    /** A genuine content change must move the hash, or an edit would never be carried over. */
    #[Test]
    public function a_real_change_moves_the_hash(): void
    {
        $before = ['sku' => 'HAT-1', 'title' => ['en' => 'Hat']];
        $after  = ['sku' => 'HAT-1', 'title' => ['en' => 'Cap']];

        $this->assertNotSame(Canonical::hash($before), Canonical::hash($after));
    }

    /**
     * Ids and timestamps are excluded, because they differ by definition.
     *
     * Matching on a natural key is the whole premise — a bundle's `id = 12` is some unrelated
     * record on the destination — so including either would make every record differ and the skip
     * would never fire.
     */
    #[Test]
    public function identity_and_timestamps_are_ignored(): void
    {
        $bare = ['sku' => 'HAT-1'];

        $decorated = [
            'sku'        => 'HAT-1',
            'id'         => 4001,
            '_source'    => 12,
            '_hash'      => 'whatever',
            'created_at' => '2020-01-01 00:00:00',
            'updated_at' => '2026-08-12 09:00:00',
        ];

        $this->assertSame(Canonical::hash($bare), Canonical::hash($decorated));
    }

    /**
     * A driver's volatile fields drop out too.
     *
     * `stock` and `orders` describe this install's shelves and this install's sales, not content
     * the operator authored. Without this, selling one item would make a product read as modified
     * and every future migration would rewrite it.
     */
    #[Test]
    public function volatile_fields_named_by_a_driver_are_ignored(): void
    {
        $quiet = ['sku' => 'HAT-1', 'stock' => 5, 'orders' => 0];
        $busy  = ['sku' => 'HAT-1', 'stock' => 0, 'orders' => 91];

        $this->assertNotSame(Canonical::hash($quiet), Canonical::hash($busy));

        $this->assertSame(
            Canonical::hash($quiet, ['stock', 'orders']),
            Canonical::hash($busy, ['stock', 'orders'])
        );
    }

    /**
     * A list keeps its order; a map does not.
     *
     * The order of a page's builder nodes is content — sorting it would be a lie — while the key
     * order of an attribute map is an artefact of however it was serialised.
     */
    #[Test]
    public function list_order_is_content_but_map_order_is_not(): void
    {
        $this->assertNotSame(
            Canonical::hash(['rows' => ['a', 'b']]),
            Canonical::hash(['rows' => ['b', 'a']]),
            'Reordering a list changed nothing, so a rearranged page would not be carried over.'
        );

        $this->assertSame(
            Canonical::hash(['meta' => ['x' => 1, 'y' => 2]]),
            Canonical::hash(['meta' => ['y' => 2, 'x' => 1]])
        );
    }

    /**
     * A numeric-looking string is left alone.
     *
     * Coercing was tried and is unsafe: a SKU of `1e5` is numeric, and reading it as a number
     * would hash that product as though its SKU were `100000`, so two genuinely different
     * products could collide and the second would be reported as an unchanged duplicate.
     */
    #[Test]
    public function a_numeric_looking_string_is_not_coerced(): void
    {
        $this->assertNotSame(
            Canonical::hash(['sku' => '1e5']),
            Canonical::hash(['sku' => '100000'])
        );
    }
}
