<?php

namespace Plugin\SiteMigration\Backend\Resources;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Plugin\SiteMigration\Backend\Bundle\BundleContext;
use Plugin\SiteMigration\Backend\Runs\IdMap;

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
     * The **core permission resource** that gates this driver.
     *
     * Usually identical to {@see key()}, and deliberately allowed to differ, because core's
     * permission names and this package's resource keys were chosen for different jobs and two of
     * them disagree: posts are gated by `blogs`, and email templates by `settings`. Deriving the
     * permission from the key instead would ask `can('posts.view')` — a permission that exists
     * nowhere — and the resource would be **silently dropped from every export** with nothing to
     * explain it.
     */
    public function permissionResource(): string;

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
     * **Drivers read and write models directly, never core's repositories, and that is deliberate.**
     * A migration walks a table with keyset paging and writes with an explicit column map; a CRUD
     * repository is shaped for one record at a time from a form, and going through one would cost a
     * query per row and drop the columns the form does not show.
     *
     * The consequence is worth stating where somebody will meet it: **anything moved *into* a
     * repository write path does not reach a migration import.** Audit rows, cache invalidation, a
     * slug rule, a derived column — if it lives in `CategoryRepository::create()` rather than in the
     * model, an imported category will not get it. Whoever moves such behaviour has to check these
     * drivers too. The settings driver is the one deliberate exception, and its docblock says why:
     * eight settings keys are `rememberForever`, so writing those through anything but the owning
     * repository leaves the destination serving stale values indefinitely.
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
     * Rewrite an incoming record so its natural key no longer collides, or return it unchanged.
     *
     * Called only when the operator chose **not** to overwrite. The contract is that the returned
     * record is safe to `write()` as a *new* row: nothing is dropped, and the collision is resolved
     * by renaming rather than by discarding either side.
     *
     * A driver returning the record untouched is saying its identity cannot meaningfully be
     * renamed — `UserDriver` does exactly that, because an email address *is* the person and
     * `jane+2@example.com` would be a second account nobody can sign into. Such a driver must then
     * merge instead, which it signals with {@see mergesOnCollision()}.
     *
     * @param  array<string,mixed>  $record
     * @return array<string,mixed>
     */
    public function renameForCollision(array $record): array;

    /**
     * Whether a collision on this resource means "the same thing", not "two things sharing a name".
     *
     * True for accounts: one email is one person, so an incoming record updates the existing row
     * even when the operator asked not to overwrite, because the alternative is an unusable
     * duplicate. False for everything else, where two records claiming one slug are genuinely two
     * records.
     */
    public function mergesOnCollision(): bool;

    /**
     * Whether merging this resource replaces work the operator did **on this site**.
     *
     * **A second question, because `mergesOnCollision()` was answering two.** That flag says a
     * collision means *the same thing* — one email address is one person, one content hash is one
     * picture — and for those, merging loses nothing: there was only ever one record to have.
     *
     * For four resources it means something else. Settings, email templates, themes and plugins
     * also merge, because they are singletons or their slug is their directory name and there is
     * no second copy to keep — but the thing being replaced is the operator's own configuration,
     * their edited transactional emails, their theme's colours and menus. Merging those without
     * asking made the import screen's promise — *"nothing already here is replaced unless you turn
     * overwriting on"* — false for eight of twenty-one resources, and the preview said the
     * incoming record would be "added alongside under a free name" when it was going to overwrite.
     *
     * So a driver answering `true` here is saying: I merge, and merging is destructive to this
     * site, so do not do it unless the operator has accepted the overwrite confirmation.
     */
    public function mergeReplacesLocalWork(): bool;

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
     * Anything the driver did partially, and wants the operator told about.
     *
     * **A third outcome, because two were not enough.** A driver could report "placed" by returning
     * a model or "not placed" by throwing, and nothing in between — so a theme whose row and
     * settings were imported while its *files* were deliberately left alone (because they are the
     * ones the live shop is rendering) had no way to say so. Silence there is the worst option: the
     * operator would find out by noticing the shop looked unchanged.
     *
     * Drained by the importer after each record and folded into the run's messages. Returns the
     * notes and clears them, so one record's note cannot be reported against the next.
     *
     * @return array<int,string>
     */
    public function takeNotes(): array;

    /**
     * Fields excluded from the content hash, beyond the ones every resource excludes.
     *
     * @return array<int,string>
     */
    public function volatileFields(): array;

    /**
     * Tell the driver where the bundle's own files are, before it is used.
     *
     * Only the asset driver acts on this — its record is a pointer to bytes that live beside the
     * data files rather than inside them. Every other driver ignores it.
     */
    public function useBundle(BundleContext $context): void;

    /**
     * Hand the driver the run's id map, before any record is written.
     *
     * The map is where each source record's id landed on this install, and it is what lets a
     * record reference another one **rename-proof**: a natural-key lookup finds the record that
     * *holds* the key now, which after a KEEP_BOTH collision is the destination's own version,
     * not the one this run just placed alongside it. An order whose product was renamed
     * `HAT-1` → `HAT-1-2`, an invoice whose order took this site's next number, a reply whose
     * parent comment has no natural key at all — each resolves through the map first and falls
     * back to the natural key only for records this run did not place.
     *
     * Mirrors {@see useBundle()}: set by the importer before use, ignored by every driver whose
     * records reference nothing.
     */
    public function useIdMap(IdMap $map): void;

    /**
     * Fields whose contents may embed references to other records.
     *
     * Read by the rewrite pass, which repairs ids and media URLs that a foreign key could not — a
     * page's builder nodes carry asset ids inside an opaque JSON blob, and a richtext body carries
     * absolute URLs to the source install. Returning `[]` asserts a record contains neither.
     *
     * @return array<int,string>
     */
    public function rewritableFields(): array;
}
