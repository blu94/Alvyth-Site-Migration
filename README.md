# Site Migration

Move a site's data from one Ovynt install to another. Pick what travels, export a single bundle,
carry it to a second install running this same plugin, and import it there.

Staging → production. An agency template → a client's site. A shop rebuilt on new hosting.

---

## What it does

**Export.** Everything travels by default — content, media, settings, themes, plugins and accounts.
Name anything you want **left out**, press Export, then Download to save the bundle to your own
computer. A large site takes more than one press; each works for about twenty seconds, writes down
where it got to, and stops.

**Import.** Upload a bundle, read what is in it, preview exactly what would change, then write.
Records find themselves on the destination by their **natural key** — a product by its SKU, never
by database id — so importing the same bundle twice updates rather than duplicating.

**No record in a bundle is ever discarded.** If one clashes with a record already here you get one
of two outcomes, and both keep everything: it replaces the local version (behind a confirmation
dialog you have to accept), or it is written alongside under a free name — `HAT-1` and `HAT-1-2`.
There is no third option where something quietly disappears.

**History.** What this install has exported and imported, with tallies and outcomes.

---

## What it deliberately does not do

- **It is not a backup tool.** `spatie/laravel-backup` already runs nightly and the updater takes
  a pre-flight dump. A backup restores over an *identical* schema on the *same* install; this
  merges selected data into a *different* install that already has its own. Different problems.
- **It is not a WordPress importer.** It reads Ovynt bundles only.
- **It is not a spreadsheet importer.** Core already ships that at `/products/import`. That is an
  *authoring* tool and deliberately flattens — variants, images and SEO are not importable there.
  A migration must carry exactly those, so this is a different contract, not a fork.
- **It is not multi-site.** Ovynt hosts one site per install. "Which website" means which install
  you carry the bundle to.
- **It carries no secrets by default.** Gateway keys, the SMTP password and the AI key never enter
  a bundle unless you switch them on, and then only sealed under a passphrase you choose. There is
  a test that greps a produced bundle for them and fails if any appears — and it runs with
  credentials switched **on** as well as off, because a bundle carrying both an encrypted block and
  a readable copy would be worse than one carrying neither.

---

## What travels

**Everything, unless you exclude it.** The picker asks what to leave *out*, not what to take —
forget something and it travels anyway, which is the safer failure. A resource added in a later
version is carried by every existing habit rather than quietly omitted from it.

| Resource | Matched on |
|---|---|
| Categories (and collections, which are the same rows) | `slug` |
| Tags | `slug` |
| Media — rows, and the files themselves | content hash |
| Products, including their variants | `sku` |
| Pages, with their whole builder tree and SEO block | `slug` |
| Posts | `slug` |
| Forms — the definitions, never the submissions | `slug` |
| Email templates | `data->key` |
| Invoice templates — the designed layout invoices are rendered with | `title` |
| Shipping zones, carrying their methods | `slug` |
| Tax zones, carrying their rates | `slug` |
| Discounts | `code` |
| Settings — application, localization, email branding, e-invoice profile | group |
| Themes — the row **and** the files, so the menus come too | `slug` |
| Plugins — the row and the package directory | `slug` |
| Customer and staff accounts | `email` |
| Orders, carrying their items and addresses | `order_number` |
| Invoices, carrying their lines | `invoice_number` |
| Comments, threaded | the same words at the same moment about the same thing |
| Form leads — the submissions themselves | the same form, moment and answers |

**Nothing is matched by database id**, ever. An id means nothing outside the database that issued
it, so honouring one would overwrite a stranger.

Two consequences worth knowing:

- **A product with no SKU cannot travel.** `products.sku` is nullable, so a catalogue that never
  set one has nothing to match on. Those rows are reported in the preview and skipped, rather than
  given an invented key — inventing one is how the same product lands twice.
- **Media is matched on content hash, not path.** Two installs store the same photograph under
  different paths, so matching on path would re-import every image on every migration. Candidates
  are narrowed by size and format first, so nothing hashes a whole library to find one match.
- **An account merges, it never duplicates.** Every other resource resolves a clash by keeping both
  under two names. An email address will not take that — it *is* the person, and `jane+2@…` would
  be a second account nobody can sign into. Comments and leads merge for the same reason: the same
  words at the same moment about the same thing are one record, and there is no rename that means
  anything for a sentence.
- **A colliding invoice or order takes this site's next number in sequence**, never a suffix.
  `INV-0007-2` is not an invoice number — it sits outside the sequence forever and reads as an
  error in an audit — so a clash is renumbered exactly the way this site numbers a new invoice.
  The customer's copy still shows the old number, which is kept on the imported record so the two
  can be matched. The screen says so.
- **An order's lines follow their products through a rename.** Each line resolves through the
  run's id map first — so a product this same run placed alongside a clash as `HAT-1-2` is linked
  as itself, not as whichever local record still holds `HAT-1` — and by SKU for products that were
  already here.

Three things arrive deliberately inert:

- **An imported theme never activates over one already in use.** Exactly one theme is live at a
  time, and a data migration must not change how your shop looks as a side effect. It arrives
  installed; switching to it is one click you make.
- **An imported invoice template never takes the "main" designation** from one this site already
  chose — the same reasoning, applied to paperwork. A site with no main template of its own does
  inherit the source's choice, since there is nothing to overrule. An imported invoice still
  points at the design it was actually issued under, where that design travelled with it.
- **An imported plugin always arrives disabled**, because enabling runs a third party's migrations
  with full application privileges. **A paid plugin's licence is bound to a domain**, so the files
  arrive and the key does not — it needs re-licensing here. Carrying the key would give you
  something that looks licensed and is not.

### What is deliberately not here

**The activity log, sessions, tokens, revisions, jobs.** Install-local by meaning. A record of what
happened on another site is not a fact about this one.

**A comment or lead whose subject is absent is skipped and says so.** A comment about a post that
is not on the destination, or a lead for a form that never travelled, has nowhere honest to live —
filing it under the wrong thing would be worse than reporting it. The preview counts these as
*cannot be placed*.

---

## How the bundle gets in and out

**Neither direction touches the public folder.** The file is private from the first byte to the
last, in both directions — which is why this package requires Ovynt **1.4.0** and refuses anything
older.

### Downloading

The bundle is generated under `storage/app/site-migration/`, off the web. Pressing **Download**
copies it to the `protected` disk — also off the web — and hands your browser a **signed link that
expires in minutes**. The copy is removed by your own press, by the sweep on the next screen load,
and in any case within the hour.

### Uploading

The upload goes straight to the same private disk. Pressing **Read bundle** moves it into the run's
own directory and deletes the upload's copy; an upload nobody claims is swept after 30 minutes so
an abandoned bundle does not sit on the disk forever.

### Why the version floor is 1.4.0

Both of those need something that did not work before it. A plugin registers no routes, so it
cannot stream a file, and `savePageData` always wraps its return in `response()->json()`, so no
package endpoint can return bytes — which leaves core's own `Asset` as the only way in or out.
On 1.3.0 that path was advertised and unfinished: `AssetRepository` selected a `protected` disk
core did not configure, and `Asset::path()` signed a route named `assets.view` that was not
registered, so an upload 500'd and a private link threw. Ovynt 1.4.0 finished both ends.

Earlier versions of this package worked around it by copying the bundle through the public folder
under a 64-character random name and sweeping it within the hour. That was the honest answer at the
time; it is not needed now, and it is gone.

---

## Permissions

The plugin declares `site_migration` with `view` and `create`.

**That gate is necessary and nowhere near sufficient**, and it is the most important thing in the
package. An import writes products. If it checked only `site_migration.create`, then letting
somebody run a migration would let them rewrite the entire catalogue without holding
`products.update` — a privilege-escalation path wearing a convenience feature's name.

| Action | Requires |
|---|---|
| Export resource *X* | `site_migration.create` **and** `X.view` |
| Import resource *X*, creating | `site_migration.create` **and** `X.create` |
| Import resource *X*, overwriting | additionally `X.update` |

An export **filters** to what you may read and says what it left out. An import **refuses** and
names the resource — a partly-migrated destination that looks finished is worse than a clear stop.

---

## How a large site is handled

A plugin cannot ship an admin Vue component, so there is no browser loop issuing one request per
hundred rows the way core's spreadsheet importer does. Instead each press is **time-boxed by the
server**: it works for about twenty seconds, commits, records its cursor and returns. The screen
then says *"Still working — 1,200 of 4,300 records done"* and you press again.

Most sites finish in one press. The failure mode is a visible Continue button rather than a request
that dies at thirty seconds with a half-written database — the trade that matters on shared hosting.

**Resumability is bought by idempotence, not atomicity.** Each press is its own transaction, so an
interrupted import leaves a partly populated site. The fix is to press Continue, not to start over:
every write is an upsert on the natural key, so re-running any step is harmless.

---

## Repeat migrations are close to free

Every exported record carries a hash of its own content. The preview compares hashes rather than
fields, and the import **skips identical records outright**. Pushing staging to production
repeatedly — where almost nothing changes between pushes — costs almost nothing after the first
run.

The canonical form both sides hash over is pinned by `tests/CanonicalFormTest.php` rather than by
care, because getting it wrong is *silent*: every record would compare as changed, the plugin would
still work, it would just never skip anything, and nothing would report why.

---

## No database tables

This package ships **no migrations and owns no tables**. A run is a directory:

```
storage/app/site-migration/20260812-141233-a7f3/
  state.json           what the run is, where it got to, what it tallied
  bundle.zip           the bundle written or uploaded
  idmap/products.ndjson  where each source id landed here
```

Nothing to create on enable, nothing to drop on uninstall, and no schema of ours in a database
shared with core. The cost is that History is a read-only page rather than a sortable, server-paged
table — `baseIndexQuery()` must return a query builder, and a directory cannot answer that.

Uninstalling does **not** delete `storage/app/site-migration/`. Remove it by hand if you want the
bundles gone.

---

## Picking this up

[`HANDOVER.md`](HANDOVER.md) — start here. What is built, why it is built that way, the platform
facts that contradict the documentation, and what to do next.

[`OUTSTANDING.md`](OUTSTANDING.md) — every known gap and decision, with its resolution.

---

## Releasing

Run these in order. **The order is the point** — a signature covers exact bytes, so anything that
edits a file after step 5 invalidates it silently, and the package installs with the same "not
signed" warning it would have had unsigned.

| # | Step | How it is checked |
|---|---|---|
| 1 | Working tree clean, everything pushed | `git status --porcelain` empty, `git rev-list --count origin/master..master` = `0` |
| 2 | Suite green against the installed copy | `docker exec ovynt_app php vendor/bin/phpunit storage/app/plugins/site-migration/tests --no-coverage` |
| 3 | Both wizards driven in a browser | Export, Import and History at `/admin/module/migration-runs/page/{export\|import\|history}` — a green suite has never once caught this package's UI defects |
| 4 | Version and floor bumped deliberately | `plugin.json` → `version`, and `requires.ovynt` if a new core seam is now called; `PackageManifestTest` asserts the floor |
| 5 | **Sign**, then **zip**, then stop editing | `php artisan ovynt:plugin-sign /path/to/site-migration --key=~/keys/vendor-private.pem` |

`plugin.sig` is `.gitignore`d deliberately: it covers exact bytes and goes stale on the next edit,
so it is a release artifact rather than a repository state.

**Keep the private key offline** — in a secrets manager or on a hardware token, never in this
repository. A signing key in version control lets anyone mint packages in the author's name, which
is worse than shipping unsigned.

### Artwork — decided: the icon ships

**`tabler-transfer` is the package's mark, not a placeholder awaiting one.** It reads correctly at
every size Ovynt draws it, it costs nothing to maintain, and it cannot go stale. The manifest
declares no image paths, which is the state that matters: it once named `assets/banner.png` and
`assets/thumbnail.png` against a directory that never existed, and a manifest asserting a file that
is not there sends the next reader looking for something nobody ever made.

Fabricating a banner to fill the gap was considered and rejected. Listing artwork ships to every
install and is covered by the release signature, so inventing a design nobody asked for is a
heavier commitment than the icon, not a lighter one.

**Reversing it costs nothing and needs no code change.** Drop `banner.png` (≈1200×300) and
`thumbnail.png` (≈256×256) at the package root; Ovynt probes for them by name and uses them from
the next install onwards. **Raster only** — an SVG is refused, because it renders inside an
authenticated admin session and is a stored-XSS surface.

---

## Development

```bash
# Install from source (a directory works; no zipping needed)
docker compose exec -T app php artisan plugin:import \
  /var/www/storage/app/plugin-src-tmp/site-migration --enable

# Tests — run inside the container against the installed copy
docker exec ovynt_app php vendor/bin/phpunit storage/app/plugins/site-migration/tests --no-coverage
```

The tests use `DatabaseTransactions`, never `RefreshDatabase`.

### What the suite covers

| File | What it proves |
|---|---|
| `RoundTripTest` | A bundle restores what was deleted · a second import writes nothing · an interrupted run finishes where an uninterrupted one does |
| `CanonicalFormTest` | Two spellings of one record hash alike, and a real change does not |
| `DriverSymmetryTest` | **Every** driver describes a record identically whether eager-loaded or bare · every driver is gated by a permission that exists · the import order is a valid dependency graph |
| `PermissionIsolationTest` | Migrating is not permission to overwrite products |
| `NoSecretsInBundleTest` | No configured secret appears in a bundle — with credentials off *and* on |
| `CredentialVaultTest` | The right passphrase reproduces every secret; a wrong one and a tampered block both fail closed |
| `SettingsExclusionTest` | Payment, mail and AI settings never travel; the e-invoice profile does, without its credential keys |
| `UserImportTest` | No password or second factor leaves the site; imported accounts cannot sign in and carry no role |
| `RewritePassTest` | Gate 7 — a source host is rewritten out of a builder node, the pass is idempotent, and URLs are left alone when media did not travel |
| `BundleFormatTest` | A newer bundle is refused, a corrupt one is caught, a damaged line does not lose the rest |
| `PackageManifestTest` | `api` plural, `routeBase` singular, every `rules` an array, no tables |
| `RecordGroupsTest` | A colliding invoice takes the next number in sequence and keeps the number it arrived under · an order travels with its items and addresses and re-links its products · an order line follows its product through a rename via the id map · a comment thread keeps its threading and its target · an unplaceable comment or lead is skipped with the reason · an invoice keeps the template it was rendered with · an imported template never steals the main designation · rich records of every new kind describe themselves identically eager-loaded and bare |

`DriverSymmetryTest` earns its place: `PageDriver` once returned an empty builder tree for a
lazily-loaded page, so **every page compared as changed and was rewritten on every migration**,
silently. The canonical form was blameless and every other test passed.

---

## Licence

MIT. See [LICENSE](LICENSE).
