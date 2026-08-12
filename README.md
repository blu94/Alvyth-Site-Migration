# Site Migration

Move a site's data from one Ovynt install to another. Pick what travels, export a single bundle,
carry it to a second install running this same plugin, and import it there.

Staging → production. An agency template → a client's site. A shop rebuilt on new hosting.

---

## What it does

**Export.** Choose what to include, press once, and the plugin walks your data into a zip under
`storage/app/site-migration/`. A large site takes more than one press — each one works for about
twenty seconds, writes down where it got to, and stops.

**Import.** Upload a bundle, read what is in it, preview exactly what would change, then write.
Records find themselves on the destination by their **natural key** — a product by its SKU, never
by database id — so importing the same bundle twice updates rather than duplicating.

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
- **It carries no secrets.** Gateway keys, the SMTP password and the AI key never enter a bundle.
  There is a test that greps a produced bundle for them and fails if any appears.

---

## What travels today

| Resource | Matched on |
|---|---|
| Products, including their variants | `sku` |

That is the whole list in this release, and the narrowness is deliberate. The risk in a tool like
this is not breadth — it is identity, the id map and the rewrite pass. Adding twenty resource
drivers before those hold means finding a defect twenty drivers deep. The remaining resources land
in the order pages, categories, tags, assets, settings and the rest, behind the same seam.

**A product with no SKU cannot travel.** `products.sku` is nullable, so a catalogue that never set
one has nothing to match on. Those rows are **reported in the preview and refused**, rather than
given an invented key — inventing one is how the same product lands twice.

---

## Two things to know before you use it

### There is no download button

The bundle is written under `storage/app/site-migration/<run>/bundle.zip`, which is not reachable
from the web. Fetch it over SFTP or your host's file manager.

This is a platform limit rather than an omission. A plugin registers no routes, so it cannot stream
a file, and the one upload/download path core offers — an `Asset` — is unusable in both directions
on Ovynt 1.3.0: there is no `protected` disk configured, and no `assets.view` route, so
`Asset::path()` on a non-public asset throws `RouteNotFoundException`. The only remaining place a
file can be served from is the **public** folder, where a bundle would be readable by anyone who
guessed the URL. For a file that can contain your whole site, that is not a default this package
picks on your behalf.

### Uploading is briefly public

An import has to come *in* through that same public path, because it is the only upload core
offers. The moment you press **Read bundle** the file is moved into private storage and both the
public copy and its asset row are deleted. Uploads that are never claimed are swept after 30
minutes.

So: press Read bundle straight away rather than leaving the page open.

---

## Permissions

The plugin declares `site_migration` with `view`, `create` and `delete`.

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
| `PermissionIsolationTest` | Migrating is not permission to overwrite products |
| `NoSecretsInBundleTest` | No configured secret appears in a produced bundle |
| `BundleFormatTest` | A newer bundle is refused, a corrupt one is caught, a damaged line does not lose the rest |
| `PackageManifestTest` | `api` plural, `routeBase` singular, every `rules` an array, no tables |

---

## Licence

MIT. See [LICENSE](LICENSE).
