# Handover — Site Migration plugin

Everything built so far, why it is the way it is, and what to do next. Read this whole file before
touching anything.

---

## 0. STOP — read these first, in this order

**This is not optional and skipping it is a critical error in this project.** Several decisions in
this package look wrong until you have read the rules that forced them.

### Project rules and skills — always

| Read | Why it matters here |
|---|---|
| [`.agent/SKILL.md`](../../.agent/SKILL.md) | The dispatcher. States that a plugin is not a module and `create-module.md` does **not** apply. |
| [`.agent/skills/core-identity.md`](../../.agent/skills/core-identity.md) | Global constraints. Vue 3 + Vite, no Nuxt, no Livewire, PowerShell only, verify before claiming. |
| [`.agent/rules/ovynt.md`](../../.agent/rules/ovynt.md) | Stack and hard prohibitions. |
| [`.agent/rules/code-standards.md`](../../.agent/rules/code-standards.md) | Generate full code, never truncate. Repository pattern. Docs ship with the feature. |
| [`.agent/rules/no-garbage-files.md`](../../.agent/rules/no-garbage-files.md) | Do not create files nobody asked for. Applies to helper scripts especially. |
| [`.agent/rules/verification-checkpoint.md`](../../.agent/rules/verification-checkpoint.md) | **Never say "fixed" without executing something.** This package has caught four bugs that way. |
| [`.agent/rules/lessons-learned.md`](../../.agent/rules/lessons-learned.md) | Silent-failure catalogue. Rule 11 (which failures are loud) and rule 12 (schema-driven) both apply. |
| [`.agent/rules/docker-rules.md`](../../.agent/rules/docker-rules.md) | `storage/` is bind-mounted — do **not** `docker cp` into it. |
| [`.agent/rules/schema-sop.md`](../../.agent/rules/schema-sop.md) | Schema JSON conventions. |
| [`.agent/skills/admin-skill.md`](../../.agent/skills/admin-skill.md) | Display and layout capabilities table. `ui.block` defaults true; full-width buttons must be `size: large`. |
| [`.agent/skills/api-skill.md`](../../.agent/skills/api-skill.md) | Backend conventions. |
| [`.agent/skills/laravel-testing.md`](../../.agent/skills/laravel-testing.md) | **`DatabaseTransactions`, never `RefreshDatabase`** — the latter has wiped a real database in this project. |

### Plugin-specific — mandatory before any change here

| Read | Why |
|---|---|
| [`.agent/workflows/create-plugin.md`](../../.agent/workflows/create-plugin.md) | The workflow this package was built from. |
| [`PLUGIN-DEVELOPMENT.md`](../../PLUGIN-DEVELOPMENT.md) | The author-facing reference. All 963 lines. §1 (what a plugin cannot do) and §6 (module schemas) decide most of this design. |
| [`PLUGIN-SYSTEM-SPEC.md`](../../PLUGIN-SYSTEM-SPEC.md) | Why the plugin system works the way it does. |
| [`SITE-MIGRATION-PLUGIN-SPEC.md`](../../SITE-MIGRATION-PLUGIN-SPEC.md) | The original specification. **Several parts of it are wrong** — see §4 below before following it. |
| [`OUTSTANDING.md`](OUTSTANDING.md) | Every known gap and decision, with resolutions. |
| [`README.md`](README.md) | What the package does and does not do. |

### Verify, do not trust

The specification was written before the code existed and **four of its load-bearing claims are
false on this build**. Every claim in §4 below was checked by executing something in the container,
not by reading. Do the same for anything you add.

---

## 1. What this package is

Moves a site's data from one Ovynt install to another. Export a bundle, download it, upload it on
the second install, import it. Staging → production; agency template → client site; a shop rebuilt
on new hosting.

- **Repo:** `git@github.com:blu94/Ovynt-Site-Migration.git`
- **Branch:** `master` — ⚠️ **the remote is empty; nothing has ever been pushed.** Six commits are
  local only. See §7.
- **Slug:** `site-migration` → `Plugin\SiteMigration\`
- **Requires:** Ovynt `>=1.3.0 <2.0.0` (floor measured, not guessed — see §4.4)
- **Module type:** `migration-runs`, three custom pages: `export`, `import`, `history`

---

## 2. Current state

**67 tests, 1493 assertions, all passing.** Every screen driven end to end in a real browser.

### Commits (oldest first)

| Commit | What landed |
|---|---|
| `caa96b4` | Skeleton, file-backed runs, products export/import, dry run, id map, time-boxed steps, content hash |
| `3b863a8` | Ten more drivers, the rewrite pass, media bytes, auto-revision on pages |
| `5743beb` | Settings groups, credentials behind a passphrase, customer accounts |
| `e24c8d8` | Rewrite-pass tests against a foreign source host (gate 7) |
| `0b0cfc8` | Version floor, run deletion, staging prune, remembered selection, empty-dropdown fix |
| `54c84d1` | Exclusion model, never-drop collisions, overwrite dialog, themes + plugins, real download |

### What works

**Export** — everything travels unless excluded. Walks each resource with `chunkById` keyset
paging, writes NDJSON into a staging tree, seals a zip with a manifest and per-file checksums, then
prunes the staging tree. Time-boxed at ~20 s per press with a Continue button.

**Download** — bundle is generated under `storage/app`, off the web. Pressing Download copies it to
the public folder under a 64-hex random name; the browser fetches it; the copy is purged by the next
screen load, the operator's press, or an hourly sweep. Verified: `HTTP 200, 1,533,667 bytes,
application/zip`.

**Import** — upload → Read bundle (manifest only, no extraction) → Preview (writes nothing) →
Import. Resumable. Credentials applied last.

**15 resources**, in this dependency order (`DriverRegistry::DRIVERS`):

```
categories → tags → assets → products → pages → posts → forms
→ email_templates → shipping → tax → discounts → settings
→ themes → plugins → users
```

Themes and plugins carry **files as well as rows**.

### The guarantees, and where they are proven

| Guarantee | Test |
|---|---|
| A bundle restores what was deleted | `RoundTripTest` |
| A second import writes **nothing** | `RoundTripTest` |
| An interrupted run finishes where an uninterrupted one does | `RoundTripTest` |
| **No record is ever dropped** | `NothingIsDroppedTest` |
| Overwriting requires the confirmation, not just the choice | `NothingIsDroppedTest` |
| Every driver describes a record identically eager-loaded or bare | `DriverSymmetryTest` |
| No secret in a bundle — credentials **on** and off | `NoSecretsInBundleTest` |
| Right passphrase restores secrets; wrong one fails closed | `CredentialVaultTest` |
| Payment/mail/AI settings never travel | `SettingsExclusionTest` |
| No password or second factor leaves the site | `UserImportTest` |
| No imported record points at the source host | `RewritePassTest` |
| Migrating is not permission to overwrite | `PermissionIsolationTest` |
| A newer/corrupt bundle is refused before anything is written | `BundleFormatTest` |
| `api` plural, `routeBase` singular, `rules` arrays, no tables | `PackageManifestTest` |

---

## 3. Architecture, and the decisions behind it

### No database tables

The package ships **no migrations**. A run is a directory:

```
storage/app/site-migration/20260812-141233-a7f3/
  state.json            what it is, where it got to, its tally
  bundle.zip            the sealed bundle
  staging/              working space; pruned once sealed
  extracted/            an uploaded bundle, unpacked
  idmap/products.ndjson append-only source id → target id
storage/app/site-migration/last-export.json   the remembered exclusion
```

The user asked for this explicitly. Tables would have bought a DataTables history screen
(`baseIndexQuery()` must return a query builder) and an indexed `whereIn` for the rewrite pass —
neither load-bearing. **Do not add a migration without asking**; if you do, declare the tables in
`uninstall.drop_tables` and delete the test that asserts there are none.

### Identity is a natural key, never a database id

`sku`, `slug`, `code`, `email`, `data->key`, content hash. An id means nothing outside the database
that issued it. This is what makes a second import a no-op instead of a duplicate.

### Nothing is ever dropped — `Collision`

Two outcomes, both keeping everything:

- **`OVERWRITE`** — replaces the local record. Behind a confirmation dialog **and** a mirrored
  acknowledgement flag; `Run::overwrites()` requires both.
- **`KEEP_BOTH`** (default) — the incoming record is written alongside under a free key
  (`HAT-1` → `HAT-1-2`), and the old→new pair goes in the id map so relations still resolve.

Drivers whose identity cannot be renamed return `mergesOnCollision() === true`: **users** (an email
*is* the person), settings and email templates (singletons), themes and plugins (slug is also a
directory name and a namespace), assets (a hash collision is the same file).

### The content hash — `Canonical`

Every record carries `_hash`. The destination recomputes one over its own record; identical means
skip. This is what makes repeat migrations near-free.

**Getting the canonical form wrong is silent** — every record compares as changed, the plugin still
works, it just never skips, and nothing reports why. Rules: ids and timestamps excluded, keys sorted
at every depth, lists keep order but maps do not, floats to fixed scale, plain decimals normalised
but **exponent and leading-zero strings left alone** (a SKU of `1e5` must not become `100000`).

### The rewrite pass — `RewritePass`

Runs **once, at the end**, when every id is known. Repairs what a foreign key could not: asset and
page ids inside builder JSON, and absolute URLs pointing at the source host. Idempotent by
construction — every substitution goes source-shaped → local, and a local value matches nothing.

**Deliberately does nothing when media did not travel.** The imported site then points at the source
for its images and the screen says so; rewriting would swap a working borrowed image for a broken
local one.

### Credentials — `CredentialVault`

PBKDF2-SHA256 at 600k iterations → AES-256-GCM → `credentials.enc`. Authenticated specifically so a
wrong passphrase *fails* rather than yielding plausible garbage the destination would store. Applied
**last** on import, so a passphrase problem costs nothing that already succeeded.

The passphrase lives on `Run` in memory for one press (`withPassphrase()`), **never** in
`state.json`, the log or the activity trail.

Not sodium — it is present on this dev container (contrary to the note in `LicenceService`) and
routinely absent on shared hosting. The one sodium call, wiping a key, is guarded.

---

## 4. Verified platform facts that contradict the documentation

**Each of these was checked by running something.** Re-check before trusting them; do not take them
from this file alone.

### 4.1 There is no `protected` disk and no `assets.view` route

`config('filesystems.disks')` is `local, public, s3, builder, themes, backups`. `route:list` has no
`assets.view`. So `Asset::path()` on a non-public asset throws `RouteNotFoundException`, and
`protectedDisk: true` on an upload field would 500.

**Consequence:** the spec's §5.2 bundle transport is unbuildable. The public folder is the only
directory nginx serves, which is why the download transits it.

### 4.2 `PermissionsField` ignores `element.options`

It calls `fetchPermissionsConfig()` on mount and renders the site's real permission list, returning
permission names. It **cannot** be used as a generic checkbox matrix, which is what the spec's §5.1
assumed. Use a `multiple` `autocomplete`.

### 4.3 A custom page has no sidebar, and its schema root must be a section

`pages/module/[type]/page/[slug].vue` renders only `<Builder :elements>`. A `sidebar` block renders
nothing. A root of `{elements: [...], sidebar: {...}}` renders a **completely blank page with no
console error**. The root must be a section object or an array of them.

Two more from the same file:

- It merges a response into the bound model **only when the response has no `message` key**. Return
  a success sentence as `message` and the screen never rebinds. Put sentences in bound fields.
- A button's action key is `element.key || element.action`, so setting `"key"` on an
  `action: "save"` button **silently stops it firing**. Distinct verbs are distinct `endpoint`s.

### 4.4 The version floor

At the commit that bumped core to 1.2.0 (`031b8c36`), `SitemapCache`, `Revision` and
`EmailBrandingRepository` did not exist. A 1.2.0 install would fatal at `SitemapCache` on the *last*
step of every import. Floor is `>=1.3.0`, asserted in `PackageManifestTest`.

### 4.5 Field options cannot come from page data

`BuilderField::resolvedOptions()` reads `element.options` only — `master` names a key **inside that
object**, not on the bound model. `{"master": "x", "x": []}` resolves to the empty array forever.
Use `{"url": "...", "master": "..."}`; the endpoint is
`GET /admin/modules/migration-runs/options` → `MigrationRunRepository::getOptions()`.

### 4.6 `ui.confirm` exists on switches

`BuilderField` opens the global confirm dialog when a switch with `ui.confirm` is turned on, and
commits the value only on accept. **`mirror` goes inside `ui.confirm`**, not beside it — nested
wrong it silently never sets the flag.

---

## 5. What to do next, in order

### 5.1 Push the repo — blocked, needs the user

Six commits are local. The remote is empty.

```bash
cd plugins/site-migration
git push -u origin master     # or rename to main first, if that is the convention
```

The push was refused by a permission classifier in the previous session. Ask the user to run it or
to approve the action.

### 5.2 Orders and invoices — unblocked, not built

The blocker is gone: nothing is dropped, so an imported invoice takes this site's next free number
via `Collision::freeKey()` rather than colliding.

What is needed:

- **`InvoiceDriver`** — `App\Models\Invoice` + `InvoiceItem`. Natural key is the invoice number.
  `renameForCollision()` must take the destination's next number in sequence, not a `-2` suffix —
  invoice numbering is a sequence, not a label. Say on screen that the customer's copy shows the old
  number.
- **`OrderDriver`** — `App\Models\Order` + `OrderItem` + `Address`. Items and addresses travel
  nested under the order, the way variants travel under a product. Orders reference products, which
  must be resolved by natural key at write time.
- Both are `RECORD_GROUPS` (about people), so they inherit the PII warnings.

### 5.3 Comments and leads — needs a seam widened first

Both point at what they are about through **ids that must be remapped at write time**, and
`ResourceDriver::write()` has no access to the run's `IdMap`.

**Do this first:** pass the `IdMap` into `write()`, either as a fourth argument or via a
`useIdMap(IdMap $map)` setter on `BaseDriver` mirroring `useBundle()`. Then:

- **`CommentDriver`** — `commentable_id`/`commentable_type` remapped through the map.
- **`LeadDriver`** — `form_id` remapped. Leads are form submissions: personal data, opt-in.

### 5.4 Smaller things

- **Artwork.** `plugin.json` declares none, so Ovynt draws the Tabler icon. Dropping
  `banner.png` (≈1200×300) and `thumbnail.png` (≈256×256) at the package root is picked up with no
  manifest change. Raster only — **SVG is refused** (stored XSS against the admin session).
- **Signing.** `php artisan ovynt:plugin-sign <dir> --key=~/keys/vendor-private.pem`, then zip.
  Never edit a file afterwards. `plugin.sig` is gitignored deliberately.
- **`docs/migration-runs.md`** is the in-app operator guide and must be updated in the same change
  as any screen change — that is the project rule, not a follow-up.

### 5.5 Decided against — do not build without a new decision

**Publish-state filter and incremental "changed since" export.** Both exclude records from the
bundle, which contradicts "migrate everything, drop nothing". The content hash already delivers the
speed they were wanted for. Building them would also mean a plugin adding an index to a core table.

---

## 6. How to work on this

### Install and test

```bash
# From the Ovynt root. `plugins/` is NOT mounted into the container, so copy first.
rm -rf app/storage/app/plugin-src-tmp/site-migration
cp -r plugins/site-migration app/storage/app/plugin-src-tmp/site-migration
rm -rf app/storage/app/plugin-src-tmp/site-migration/.git

MSYS_NO_PATHCONV=1 docker exec ovynt_app \
  php artisan plugin:import /var/www/storage/app/plugin-src-tmp/site-migration --enable

# Tests run against the installed copy, in the container.
MSYS_NO_PATHCONV=1 docker exec -e DB_DATABASE=ovynt_test ovynt_app \
  php artisan plugin:import /var/www/storage/app/plugin-src-tmp/site-migration --enable
MSYS_NO_PATHCONV=1 docker exec ovynt_app \
  php vendor/bin/phpunit storage/app/plugins/site-migration/tests --no-coverage
```

`MSYS_NO_PATHCONV=1` is required in Git Bash or the shell rewrites `/var/www/...` into a host path.

### Browser check — mandatory, not optional

**Every UI defect this package has shipped passed a fully green PHP suite.** Four of them:

1. A blank page from the wrong schema root shape.
2. A button that never fired, because it had a `key`.
3. Progress never updating, because the response carried `message`.
4. Every resource dropdown empty, because options cannot come from page data.

Log in as **`tester@ovynt.com` / `password`** — never `admin@ovynt.com` (project rule).
URLs: `http://localhost:8090/admin/module/migration-runs/page/{export|import|history}`.

Two harness notes that cost time last session:

- Playwright's `page.fill()` sets the DOM but a Vuetify **select/autocomplete menu only opens** on
  `mousedown` + `click` dispatched **in-page**, not via `locator.click()`.
- Running the PHPUnit suite clears the plugins table and logs the browser out. Log back in after.

### After a test run

The suite empties the `plugins` table in `ovynt_test`. If `plugin:import --enable` then fails with a
`role_has_permissions` foreign-key error, it is a stale Spatie permission cache, not a defect:

```bash
docker exec -e DB_DATABASE=ovynt_test ovynt_app php artisan permission:cache-reset
```

### Housekeeping

Clean up after yourself — `app/storage/app/plugin-src-tmp/`, any probe scripts, and
`app/storage/app/site-migration/` test runs. Run `git status` in the **Ovynt root** as well as the
plugin repo; the root has unrelated pre-existing changes that are not yours to commit.

---

## 7. Known limitations to state, not hide

- **The download transits the public folder.** Unavoidable: no routes, `savePageData` always returns
  JSON, and the public folder is the only thing nginx serves. Purged after.
- **The upload is briefly public** for the same reason, until Read bundle is pressed. Unclaimed
  uploads are swept after 30 minutes.
- **A paid plugin's licence is domain-bound**, so an imported plugin needs re-licensing.
- **Uninstalling does not remove `storage/app/site-migration/`.** The plugin system offers no
  uninstall hook for files, and deleting an operator's only bundle would be worse.
- **Themes never activate and plugins never enable on import.** Deliberate.

---

## 8. The one habit that matters

The specification for this package is careful, detailed, and **wrong in four places that would each
have shipped a broken feature**. Every one was caught by executing something — `route:list`,
`config('filesystems.disks')`, a git archaeology check on the version floor, and clicking a button
in a browser.

Read the docs. Then check them.
