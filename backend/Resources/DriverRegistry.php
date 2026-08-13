<?php

namespace Plugin\SiteMigration\Backend\Resources;

use RuntimeException;

/**
 * Which resources can travel, and in what order they land.
 *
 * A fixed map rather than discovery, for the same reason core's `ImporterRegistry` is one: the
 * set of movable resources is a product decision, and every entry here also names a permission,
 * so a key appearing by accident would create an ungated write path into a table.
 *
 * **The order is the import order and it is declared, not discovered.** Taxonomies before the
 * things categorised, assets before the records embedding them, products before orders. A driver
 * added to the wrong position produces records referencing rows that do not exist yet — which
 * looks like a broken relation on the destination and reads as data loss.
 */
class DriverRegistry
{
    /**
     * Resource key => driver class, **in import order**.
     *
     * Read this list as a dependency graph, because that is what it is:
     *
     * - **Taxonomies first.** A product attaches to a category by slug, and attaching to a row
     *   that does not exist yet silently drops the association rather than failing.
     * - **Assets before anything that embeds one.** The rewrite pass can only point a builder
     *   node at an image this run has already placed.
     * - **Products before pages**, because a page can feature a product; pages before posts, for
     *   the links between them.
     * - **Commerce config last**, because nothing else references it — a product names its tax
     *   *class* as a string, not a rate by id, which is precisely what makes the pair portable.
     *
     * Getting a position wrong does not throw. It produces records referencing rows that do not
     * exist yet, which reads on the destination as a broken relation and is indistinguishable
     * from data loss.
     */
    private const DRIVERS = [
        'categories'      => CategoryDriver::class,
        'tags'            => TagDriver::class,
        'assets'          => AssetDriver::class,
        'products'        => ProductDriver::class,
        'pages'           => PageDriver::class,
        'posts'           => PostDriver::class,
        'forms'           => FormDriver::class,
        'email_templates' => EmailTemplateDriver::class,
        'shipping'        => ShippingDriver::class,
        'tax'             => TaxDriver::class,
        'discounts'       => DiscountDriver::class,

        // Last, and not because it depends on anything: settings are what an operator notices
        // going wrong soonest, so they land after the content they describe rather than before it.
        'settings'        => SettingsDriver::class,

        // Packages rather than records: a row **and** a directory of files each. Late, because
        // nothing else references them and because restoring files is the slowest thing here.
        'themes'          => ThemeDriver::class,
        'plugins'         => PluginDriver::class,

        // Records about people. Not separated by position — by RECORD_GROUPS below.
        'users'           => UserDriver::class,
    ];

    /**
     * Resources that are records **about people** rather than content the operator authored.
     *
     * Kept apart from everything else because they fail the admission test the rest pass, and
     * because the difference has to survive the screen: the content list defaults to *all of it*,
     * and this list defaults to **none**. Folding the two together would mean an operator who
     * pressed Export without reading carefully had just produced a personal-data export.
     *
     * Orders, invoices, comments and leads belong here too and are **not implemented**, each for a
     * specific reason rather than for want of time:
     *
     * - **Invoices** carry the source's numbering sequence, and the destination computes its next
     *   number from its own rows. The collision is silent until an accountant finds two invoices
     *   sharing a number, and deciding between renumbering (which breaks the customer's copy) and
     *   preserving (which breaks the sequence) is a product decision, not an implementation one.
     * - **Orders** are meaningless without their items, addresses and the invoice attached to
     *   them, so they cannot land before that question is answered.
     * - **Comments and leads** point at what they are about — a post, a product, a form — through
     *   ids that must be remapped *at write time*, and a driver has no access to the run's id map.
     *   Shipping them would need that seam widened, which is worth doing deliberately rather than
     *   as a side effect.
     *
     * @var array<int,string>
     */
    public const RECORD_GROUPS = ['users'];

    /** Resources that are ordinary content, ticked by default. */
    public function contentKeys(): array
    {
        return array_values(array_diff($this->keys(), self::RECORD_GROUPS));
    }

    /**
     * Everything, minus what the operator excluded.
     *
     * **The selection model is exclusion, not inclusion**, which is the shape All-in-One WP
     * Migration uses and is the right default for a tool whose job is "move my site". An operator
     * asked to *choose* what travels has to know the answer in advance, and anything they forget is
     * missing on the destination with nothing to say so. Asked instead what to *leave out*, the
     * failure mode inverts: forget something and it travels anyway.
     *
     * @param  array<int,string>  $excluded
     * @return array<int,string>
     */
    public function everythingExcept(array $excluded): array
    {
        $excluded = array_values(array_filter($excluded, 'is_string'));

        return array_values(array_diff($this->keys(), $excluded));
    }

    /** Resources that are records about people, ticked by nobody unless they mean it. */
    public function recordKeys(): array
    {
        return array_values(array_intersect($this->keys(), self::RECORD_GROUPS));
    }

    /** @return array<int,string> */
    public function keys(): array
    {
        return array_keys(self::DRIVERS);
    }

    public function has(string $resource): bool
    {
        return isset(self::DRIVERS[$resource]);
    }

    /**
     * @throws RuntimeException when the key names no movable resource.
     */
    public function for(string $resource): ResourceDriver
    {
        $class = self::DRIVERS[$resource] ?? null;

        if ($class === null) {
            // The message lists the valid keys rather than saying "not found". This is reached
            // from a bundle written by another install, and the operator's next question is
            // always "then what *can* this version move".
            throw new RuntimeException(sprintf(
                '"%s" is not something this version of Site Migration can move. It handles: %s.',
                $resource,
                implode(', ', $this->keys())
            ));
        }

        return app($class);
    }

    /**
     * The given resources, reordered into the order they must be written.
     *
     * Callers pass whatever the operator ticked or whatever a bundle happens to contain, in
     * whatever order that arrived. Sorting here rather than trusting the caller is what stops a
     * selective import writing products before the categories they reference.
     *
     * @param  array<int,string>  $resources
     * @return array<int,string>
     */
    public function ordered(array $resources): array
    {
        return array_values(array_filter(
            $this->keys(),
            static fn (string $key) => in_array($key, $resources, true)
        ));
    }

    /**
     * Title/value options for one set of keys, for the selection fields.
     *
     * @param  array<int,string>  $keys
     * @return array<int,array{title:string,value:string}>
     */
    public function options(array $keys): array
    {
        return array_map(fn (string $key) => [
            'title' => $this->for($key)->label(),
            'value' => $key,
        ], array_values($keys));
    }

    /**
     * Every driver, for the screen that offers a choice of what to move.
     *
     * @return array<int,array{key:string,label:string,natural_key:string}>
     */
    public function all(): array
    {
        return array_map(function (string $key) {
            $driver = $this->for($key);

            return [
                'key'         => $key,
                'label'       => $driver->label(),
                'natural_key' => $driver->naturalKey(),
            ];
        }, $this->keys());
    }
}
