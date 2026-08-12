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
     * Stage 2 ships products alone, deliberately. The whole risk in this package is in identity,
     * the id map and the rewrite pass; adding twenty drivers before those hold means finding a
     * defect twenty drivers deep.
     */
    private const DRIVERS = [
        'products' => ProductDriver::class,
    ];

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
