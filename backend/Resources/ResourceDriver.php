<?php

namespace Plugin\SiteMigration\Backend\Resources;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * What one resource means as a bundle: how it is walked, what a record looks like as a line of
 * NDJSON, and how a line finds its record again on a different install.
 *
 * `Exporter` and `Importer` drive the file, the cursor and the tally and know nothing about
 * products, pages or categories. Everything resource-specific lives behind this interface.
 *
 * **Deliberately not `App\Services\Import\ResourceImporter`.** `ImporterRegistry::DRIVERS` is a
 * `private const` and cannot be extended from a package, so these are the plugin's own classes
 * either way — but the deeper reason is that the two tools have different contracts. The
 * spreadsheet importer flattens a record to fit a grid, and item 11 records that "variants,
 * images and SEO are not importable" as a result. A site migration must carry exactly those.
 *
 * What the two **must** agree on is identity: {@see naturalKey()} returns the same column core's
 * driver declares in `upsertKey()`, read from it where one exists rather than restated. An
 * operator who imports a spreadsheet and then a bundle must not get two of everything.
 */
interface ResourceDriver
{
    /**
     * The resource key — `products`, `pages`, `categories`.
     *
     * The same string names the NDJSON file in the bundle, the id-map file, and the **permission
     * resource** that gates it. One string doing all three jobs is deliberate: a migration is a
     * way of writing a resource, so the thing being moved and the permission guarding it must
     * not be able to disagree.
     */
    public function key(): string;

    /** Operator-facing name, for the selection list and the dry-run report. */
    public function label(): string;

    /**
     * The column a record is matched on when it lands.
     *
     * **Never the primary key.** Auto-increment ids are meaningful only within one database; a
     * bundle's `products.id = 12` is some unrelated product on the destination, and honouring it
     * would overwrite a stranger.
     */
    public function naturalKey(): string;

    /**
     * The records to export, as a query so the walk can `chunkById` rather than hydrate a
     * catalogue.
     *
     * **Must eager-load whatever `toRecord()` reads.** A products export that lazy-loads
     * categories per row turns 4,000 products into 4,001 queries — the shape core already found
     * and fixed once in the activity log.
     */
    public function exportQuery(): Builder;

    /**
     * One record as the array that becomes a line of NDJSON.
     *
     * Relations are resolved to **natural keys as they are written**, never to ids. A product's
     * categories leave as `["hats", "scarves"]`, not `[4, 9]`, because 4 and 9 mean nothing on
     * the destination and resolving them at write time is what makes the bundle portable rather
     * than merely transferable.
     *
     * @return array<string,mixed>
     */
    public function toRecord(Model $record): array;

    /**
     * Find the record this line refers to on *this* install, or null if it is new.
     *
     * @param  array<string,mixed>  $record
     */
    public function locate(array $record): ?Model;

    /**
     * Write one line.
     *
     * Returns the model written, so the caller can record its id in the run's map. Throwing is
     * how a row is reported as failed — the caller catches, tallies and moves on, because one
     * bad record in forty thousand must not end the run.
     *
     * @param  array<string,mixed>  $record
     * @param  Model|null  $existing  what `locate()` found, so it is not looked up twice
     */
    public function write(array $record, ?Model $existing): Model;

    /**
     * Fields excluded from the content hash, beyond the ones every resource excludes.
     *
     * @return array<int,string>
     */
    public function volatileFields(): array;
}
