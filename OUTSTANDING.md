# Outstanding issues

Everything known to be wrong, missing or undecided in this package, with the resolution taken for
each. Written after the build rather than during it, so the reasons are the ones that actually
applied rather than the ones anticipated.

**FIX** / **FIXED** / **RESOLVED** — done. **WILL NOT BUILD** — decided against, with the reason.
**UNBLOCKED, NOT YET BUILT** — the obstacle is gone and the work remains. **NOTED** — left as is,
deliberately.

---

## 1. The manifest declares artwork that does not exist — **FIX**

`plugin.json` names `assets/banner.png` and `assets/thumbnail.png`. There is no `assets/`
directory. Ovynt ignores a declared path it cannot resolve and falls back to the Tabler icon, so
nothing breaks — but the manifest asserts something untrue, and the next person to read it will go
looking for files that were never there.

**Resolution.** Remove both declarations. The Tabler icon is a real, deliberate choice rather than
a placeholder. Ovynt also probes for `banner.{ext}` / `thumbnail.{ext}` at the package root without
any manifest entry, so dropping real artwork in later needs no code change at all — which makes the
declaration pure liability.

Fabricating placeholder images instead was considered and rejected: artwork ships to every install
and is covered by the signature, and inventing a design nobody asked for is worse than the icon.

---

## 2. The version floor is lower than what the code actually needs — **FIX**

`requires.ovynt` says `>=1.2.0 <2.0.0`, copied from the specification. **Checked against the core
repository rather than assumed**, at the commit that bumped the version to 1.2.0 (`031b8c36`,
2026-07-15):

| Seam this package calls | Present at 1.2.0? |
|---|---|
| `App\Services\Seo\SitemapCache` | **absent** |
| `App\Models\Revision` | **absent** |
| `App\Repositories\Setting\EmailBranding\EmailBrandingRepository` | **absent** |

So the constraint was not merely optimistic, it was wrong. On a genuine 1.2.0 install every import
would fatal at `SitemapCache` on the last step — after writing every record — and every page
overwrite would fatal at `Revision`. This is the failure mode the sibling redirect-manager package
already hit and documented: a constraint that is too loose installs happily onto a build missing
the seam, and then fails somewhere expensive.

**Resolution.** Raise the floor to `>=1.3.0 <2.0.0` and assert it in `PackageManifestTest`. A
package should refuse a core it has not been run against, rather than discover the gap on somebody
else's shop.

---

## 3. Runs accumulate forever, and nothing in the UI removes one — **FIX**

Every export leaves a run directory containing `state.json`, a staging tree and a `bundle.zip`. A
media-included bundle of a real site is tens of megabytes. Nothing deletes any of it: `RunStore`
has a `delete()` method that no screen calls, and uninstalling the plugin does not touch
`storage/app/site-migration` either.

An operator pushing staging to production weekly fills their disk and has no way to notice, because
the History screen shows the runs without offering to remove them.

**Resolution.** Two parts, because they solve different halves:

- **A delete action on the History screen**, which removes one run's directory entirely — state,
  id map and bundle. Deleting a run is explicitly *not* undoing it, and the screen says so: the
  records an import wrote stay exactly where they are.
- **Automatic pruning of the staging tree once a bundle is sealed.** The staging directory is
  working space that exists only so a paused walk can resume; once `bundle.zip` is written it is a
  second, uncompressed copy of everything. Removing it roughly halves what a finished export costs
  on disk, and costs nothing else.

Deliberately *not* an automatic retention policy that deletes whole runs by age. A bundle is
sometimes the only copy of a site an operator has while they are mid-migration, and a tool that
quietly deleted one after thirty days would eventually delete exactly the wrong one.

**Reversed once, by clicking it.** The delete action was first gated on `site_migration.delete`,
reasoning that running a migration and tidying up after one are different acts. Pressing the button
as `tester@ovynt.com` — role `admin` — returned a 403, because core's seeder grants `admin` every
permission *except* `.delete`. So the role that actually performs migrations could never reclaim a
byte of the disk its own bundles were filling, which is the entire problem the action exists to
solve. A correct-sounding gate that makes the feature unreachable for its only user is worse than
no feature.

It now gates on `.create`, and the manifest declares only `view` and `create`. Removing a run
destroys no site data — a bundle is regenerable by exporting again and a history entry is a log
line — so it does not warrant a grant core deliberately withholds. Declaring a `.delete` permission
that nothing checks would have been worse still: it misleads whoever grants it.

The specification's §6 says `["view", "create", "delete"]`; this is a deliberate departure from it.

---

## 4. A rejected passphrase leaves an orphan run behind — **FIX**

`ExportPage::save()` creates the run, *then* validates the passphrase pair. So mistyping the
confirmation — the case the double entry exists to catch — throws after a directory has already
been created, and leaves a `pending` run in the history that never did anything.

**Resolution.** Validate before creating. The check needs the selection to know whether credentials
were requested, so it reads that from the posted form rather than from the run, which is where it
should have come from in the first place.

---

## 5. There is no way to repeat an export selection — **FIX**

Spec §8.5. An operator pushing staging to production does the same export every time, and re-ticking
the selection by hand is where a mis-selection happens — the kind that is invisible until the
destination is missing something.

**Resolution.** Save the last selection used and offer it back as the default on the next visit.
Deliberately *one* remembered selection rather than named presets: named presets are a small
management surface of their own (create, rename, delete, which is default), and the actual problem
is "I want what I did last time", which one slot solves completely.

Costs nothing at runtime — it is a stored selection replayed into the form.

---

## 6. Signing is not done, and cannot be done in the repository — **FIX (documentation only)**

`plugin.sig` is absent, so every install prints `This package is not signed`. It is also
`.gitignore`d, correctly: a signature covers exact bytes and goes stale on the next edit.

**Resolution.** Nothing to change in the package. Signing is a release step and belongs in the
release instructions, which the README now states explicitly, including the ordering trap — sign
the directory, *then* zip, and never edit a file afterwards. Generating a vendor key here and
committing it would be worse than leaving the package unsigned, since a signing key in a public
repository lets anyone mint packages in the author's name.

---

## 7. Orders, invoices, comments and leads do not travel — **RESOLVED: all four built**

**The seam was widened first, then the drivers.** `ResourceDriver::useIdMap()` mirrors
`useBundle()`: the importer hands every driver the run's id map before use, and `BaseDriver`
caches one resource's map per instance so resolving a reference is an array lookup, not a file
scan per record. The map turned out to matter beyond comments and leads — it is the only
**rename-proof** way to resolve any reference: an order item whose product this same run placed
alongside a clash as `HAT-1-2` must link to *that* row, not to whichever local record still holds
`HAT-1`, and a natural-key lookup alone answers the wrong question. Pinned by
`RecordGroupsTest::an_order_item_follows_its_product_through_a_rename`.

The four drivers, and the decision each one embodies:

- **`InvoiceDriver` / `OrderDriver`** — identity is the number; a collision takes the
  destination's **next number in sequence** (`INV-` / `ORD-` plus `max(id) + 1`, advancing past
  taken numbers, exactly as core numbers a new record), never a `-2` suffix that would sit
  outside the numbering forever. The number the record arrived under is kept in its own meta
  (`imported_number`) — stripped from the travelling copy and from the content hash on both
  sides, or every renumbered record would compare as changed forever. The customer's copy shows
  the old number; the import screen says so. Items travel nested (delete-and-recreate, core's own
  `syncItems` semantics — lines have no identity to diff on); addresses attach to the order,
  never to anyone's address book; `meta.template_id` does not travel (it names a row in the
  source's metas table) and `InvoicePdfService` falls back to this site's default template.
- **`CommentDriver`** — no natural key, so identity is the same words at the same moment about
  the same thing, and a collision **merges**. The morph target travels twice over: the source id
  for the map, the target's own natural key for records already here. A reply's parent has no
  natural key at all, so threading resolves only through the map — plus the driver's own note of
  what it placed this step, because the per-instance cache cannot see rows appended after its
  first load. A comment whose target resolves to nothing is skipped with the reason.
- **`LeadDriver`** — gated by `forms` (core's `FormController` guards its lead endpoints with the
  forms resource; a `leads` permission exists nowhere, and deriving it from the key would silently
  never travel — same trap as posts/`blogs`). `form_id` is NOT NULL with a cascade, so a lead
  whose form is not here is skipped with the reason, never attached to somebody else's form. The
  `data` blob is never rewritten by the rewrite pass: it is a verbatim record of what a person
  typed, and repairing a URL in it would falsify a submission.

All four are `RECORD_GROUPS` and the wording on both wizards states the PII consequence. Import
order extends the dependency graph: users → orders → invoices → comments → leads.

**Timestamps travel as content.** `created_at` is excluded from every bundle record by the
canonical form, but for records about people *when it happened* is the content — an imported
order dated the day of the migration files a year of sales under one afternoon. Each driver
carries it explicitly (`placed_at`, `recorded_at`, `commented_at`, `submitted_at`) and writes it
back to `created_at`.

## 7a. Merged resources rewrote identical records on every repeat import — **FIXED**

Found the way this package finds everything: by using the screens. Importing a site's own bundle
back into it, the preview said everything was unchanged and the tally then reported **519
updates** — every asset, account, settings row, theme and plugin rewritten to change nothing.
`Importer::writeOne()` checked `mergesOnCollision()` *before* the content hash, so merging
resources never reached the unchanged short-circuit that every other resource enjoyed, on
exactly the path (`KEEP_BOTH`, the default) an operator actually uses. The check now runs first;
a repeat import of an unchanged site writes nothing at all — verified in the browser: 0 created,
0 updated, 834 skipped, 0 failed.

---

## 8. Publish-state filter and incremental export — **WILL NOT BUILD**

Decided rather than deferred. Both work by *excluding records from the bundle*, which is the
opposite of what this tool is now for: everything travels, nothing is dropped. The content hash
already delivers the speed they were wanted for — a repeat migration writes nothing for unchanged
records — while every record still travels and is still verified. No index on a core table either.

## 9. Does a bundle carry the theme? — **RESOLVED: yes, and plugins too**

Answered: migrate everything. Themes and plugins now travel as rows **and** files, so the objection
that a row without its files gives the destination a registry entry for absent code no longer
applies. This also fixes the symptom that made the question urgent — theme settings hold the menus,
so a site migrated without its theme arrived with its navigation missing.

Two behaviours are deliberate and stated on screen:

- **An imported theme never activates over one in use.** Exactly one theme is live at a time, and a
  data migration must not change how somebody's shop looks as a side effect.
- **An imported plugin always arrives disabled**, because enabling runs a third party's migrations
  with full application privileges. A paid plugin's licence is domain-bound, so it needs
  re-licensing here — the files arrive, the key does not, and carrying the key would produce
  something that looks licensed and is not.

## 10. Downloading the bundle — **RESOLVED twice: through the webroot, then properly**

**First answer, and it was the honest one available.** The bundle is generated under
`storage/app`, off the web; pressing **Download** copied it to the *public* disk under a
64-character random name, the browser fetched it, and the copy was removed — by the operator's own
press, by the sweep on the next screen load, and in any case within the hour. It transited; it was
not stored. Verified end to end at `HTTP 200, 1,533,667 bytes, application/zip`.

The reasoning for accepting that was sound and is worth keeping: a plugin registers no routes, so
it cannot stream a file; `savePageData` always wraps its return in `response()->json()`, so no
package endpoint can return bytes; and the engine's `download` action lives in `useModuleWrapper`,
which custom pages do not use. The public folder was the only directory nginx served.

**What the reasoning missed is that core's own private-asset path was supposed to cover this** —
and was simply unfinished. `AssetRepository::create()` selected a `protected` disk core did not
configure, and `Asset::path()` signed an `assets.view` route that was not registered, so the
documented mechanism 500'd at one end and threw at the other. Filed as core defect 11 and **fixed
in core 1.4.0**: the disk exists, the route exists, and the expiry is configurable
(`ovynt.assets.private_link_minutes`) instead of the five-second literal that would never have
worked for a link a human clicks.

**Second answer, now shipped.** `Download::publish()` copies to the `protected` disk and returns
`$asset->path` — a signed link, re-minted on every screen load because it expires in minutes while
a page can sit open for hours. The import field declares `protectedDisk: true`, so the upload never
reaches the webroot either. Gone with the transit: the 64-hex name (the signature is the credential
now), `mirrorToWebroot()` for hosts where `public/storage` is a real directory rather than a
symlink, and the public half of every cleanup path.

**The floor moved to `>=1.4.0` for this and only this.** `PackageManifestTest` asserts both ends of
the path exist, because a class check cannot see a missing disk or an unregistered route — which is
exactly how the gap survived as long as it did.

## 11. Three defects found while fixing the above — **FIXED**

None of these were on the list when it was written. All three were found by *using* the screens
rather than reading them, which is the pattern this package keeps re-learning.

### 11a. Every resource dropdown was empty

`{"options": {"master": "module_options", "module_options": []}}` was the wrong shape. Verified in
`BuilderField::resolvedOptions()`: `master` names a key **inside the element's own `options`
object**, not a key of the bound model, so it resolved to the literal empty array — and with no
`url` present, `fetchOptions()` returned early and never populated anything either.

The pickers still *looked* right, because the pre-selected chips come from the field's **value**,
which page data does supply. The list behind them was empty on all three screens. That is why a
screen-by-screen check missed it: nothing is visibly wrong until somebody opens the dropdown.

Fixed by pointing all three at `GET /admin/modules/migration-runs/options` — the one options seam
the engine already routes to the repository — and implementing `getOptions()` to serve `content`,
`records` and `runs` from the registry. That also keeps a single source of truth: a driver added
later appears in every dropdown with no schema edit.

### 11b. `$this->data() + [...]` silently discarded every override

PHP's array union keeps the **left** operand's value for a duplicate key. `data()` already declares
`delete_result`, so `$this->data() + ['delete_result' => …]` returned the empty one and the outcome
sentence never reached the screen — a delete that had worked looked as though it had done nothing.
Overrides now go on the left. Audited the rest of the package: `ImportPage::respond()` already had
it the right way round, and the two other unions have no colliding keys.

### 11c. The delete gate locked out the only role that needed it

Covered in §3 above. Worth repeating here because it is the same lesson: the gate was correct in
principle, returned a 403 the first time it was pressed, and would have shipped that way.

---

## 11a. Invoice templates never travelled — **FIXED**

Found by diffing every model in `app/Models` against the driver registry rather than by reading
the to-do list, which had nothing to say about it. The original specification listed invoice
templates in §3.1 — *"Presentation | email templates, email branding, invoice templates"* — beside
two things that were built; this one was quietly never done, and no document recorded the gap.

It mattered more once invoices travelled. A shop that designed its own invoice and then migrated
would arrive with core's seeded default and every invoice it had ever issued silently re-rendered
under somebody else's design.

**`InvoiceTemplateDriver`**, and three decisions inside it:

- **Identity is the title**, because there is nothing else. An invoice template is a `Meta` row
  under the STI type `INVOICE_TEMPLATE` with no slug column and no key inside its blob — unlike an
  email template, whose `data->key` says which message it is. The title is what the operator typed
  and what the picker shows them.
- **Gated by `invoices`**, which is the resource core itself chose. `TemplateController` records
  why: there is no `invoice_templates` key in `config/settings.php`, so naming one resolves to
  `super_admin` alone. Deriving the permission from the driver key would have asked
  `can('invoice_templates.view')` — false for everybody — and the resource would have been
  silently dropped from every export. `DriverSymmetryTest` now covers it.
- **`data->is_default` never travels inside the blob.** Which template is "main" is a decision
  about *this* install, exactly like theme activation, so the flag is lifted out of `data`,
  carried as a plain field, marked volatile, and honoured on import **only when this site has no
  main template of its own**. Left inside the hashed blob it would also have made every template
  whose designation differs read as changed on every migration forever — the silent shape
  `AssetDriver` documents for `usage`.

**And the invoice now keeps the design it was rendered with.** `meta.template_id` was previously
stripped and left to fall back to the destination's default, which was the only honest answer while
templates could not travel. It now resolves like every other reference — id map first
(rename-proof), title second — and falls back to the default only when the design genuinely is not
here. `template` and `_template_source` are both volatile, or an invoice rendered through a
fallback would rewrite itself on every migration.

One performance note worth keeping: `Invoice::template()` is a method, not a relation, so it cannot
be eager-loaded and calling it per record is an N+1 across the whole export. The titles are read
once per walk and answered from memory, the same shape `AssetDriver` uses for content hashes.

## 12. Imported order numbers can sit ahead of the local sequence — **NOTED**

Core numbers a new order `ORD-` + `max(id) + 1` (`GeneratesSequentialNumber`), and an import
preserves the source's numbers wherever they are free — so a destination whose own ids are low can
end up holding imported numbers *above* its next derived one. The first checkout after such an
import may derive a number an imported order already holds.

**Why this is left alone.** Core's trait already retries with an advancing offset, five attempts
deep, and a failed insert still consumes an auto-increment id — so every collision moves `max(id)`
upward and the window closes by itself. The pathological case is a large *contiguous* block of
imported numbers sitting just above the local counter, where a checkout could exhaust its five
retries; on the real shapes of this problem (fresh-site migration, where ids and numbers land
together; or an established shop, whose counter is already high) the gap is small or nonexistent.
The alternatives were worse: a plugin issuing `ALTER TABLE … AUTO_INCREMENT` against a core table
is DDL from a package, and renumbering imported orders to fit the local sequence would break the
number on every customer's confirmation email for a problem that mostly cannot occur.

## 13. Uninstalling leaves the run directory behind — **NOTED**

The package owns no database tables, so `uninstall.drop_tables` is absent and there is nothing for
Ovynt to clean up. `storage/app/site-migration` survives an uninstall.

**Resolution.** Left as is, and documented. The plugin system offers no uninstall hook for files, so
the alternatives are to leave them or to have some other code path delete them — and a plugin that
deleted an operator's only copy of a bundle on uninstall would be doing something much worse than
leaving a directory behind. The README says where it is and that removing it is a manual step.

---

## 14. The audit of 2026-09-01, and the seventeen things it found — **FIXED**

A full read of the package against the plugin contract and against ordinary web practice. The
contract half came back clean: manifest, module routing keys, closed field and `ui.type` sets,
navigation ownership, permission resource, no migrations, no routes, no provider, `SafeZip` for
extraction, and a version floor that matches the core it needs. The other half did not, and the
seventeen findings share one cause worth stating once:

**A bundle's *shape* was validated and its *content* was trusted.** Format version, checksums and
zip-slip were all checked on the way in; the filenames, package slugs and cipher name *inside* were
not, and each of those reaches something that acts on it — a filesystem path, an upload, a decrypt.

What changed, grouped by what one change bought:

- **Code is no longer deployed by a data import.** Plugin files now install through
  `PluginInstaller::installFromDirectory()` — core's own seam, which validates the manifest, applies
  the signature policy and refuses collisions — instead of a directory copy. Importing a theme or a
  plugin requires `super_admin`, mirroring the gate `PluginController::store()` states outright;
  `plugins.create` alone was a way around it, and `SyncPermissions` grants that to every `admin`.
  The files of the **active** theme are never replaced (its settings and menus still travel, and the
  run says so). Both are now opt-in rather than part of "everything travels": exclusion is the right
  default for content, and the opposite is the right default for executable code.
- **Slugs, filenames and cipher names are input.** Package slugs are validated against core's own
  pattern and both ends of the copy are `realpath`-contained. Media is allowlisted by extension and
  checked that the bytes agree with it — core's `AssetRepository::create()` has no allowlist at all,
  which is filed as **core defect 13**; the guard here should be removed once that lands. The
  credential vault's cipher is allowlisted to the AEAD mode it writes and its iteration count is
  clamped at both ends, because `openssl_decrypt()` silently ignores the authentication tag for a
  non-AEAD cipher.
- **The overwrite gate now covers what it claimed to.** `mergesOnCollision()` was answering two
  questions. It still means "a collision is the same thing" — one email is one person, one content
  hash is one picture — and a second method, `mergeReplacesLocalWork()`, says whether merging
  replaces something the operator authored *here*. Settings, email templates, themes and plugins
  answer yes, and without the overwrite acknowledgement their incoming record is left out and named
  rather than written over the top. The preview reports each resource in its own terms, and both
  screens say what actually happens.
- **Progress is recorded only for work that committed.** `savePageData()` wraps a press in one
  transaction and the state file was never in it, so a deadlock or an execution-time overrun
  discarded the writes while the cursor advanced past them — and Continue skipped exactly what was
  lost. `Run::save()` now defers to `DB::afterCommit()`, `RunStore` keeps an identity map so nothing
  in the request has to read the lagging file, and both filesystem writes are checked.
- **The run directory is scoped by database**, exactly as core scopes `active-{database}.json`.
  This is the one that had already cost something: `DB_DATABASE=ovynt_test` changed the database and
  not the directory, so running this suite the documented way deleted the dev install's bundles and
  history — the loss §3 refuses to risk. It also removes the multi-site collision before it exists.
- **Ownership is read, not merely recorded.** `RunStore::find()` and `all()` filter by `user_id`
  unless the caller is a super admin, and the upload lookup is constrained to this operator's own
  `MIGRATION_BUNDLE` rows — it previously matched *any* asset by path and then deleted it.
- **Smaller, and each its own defect.** The rewrite pass read `Asset::path` through the accessor,
  substituting a URL where a storage path belonged and breaking every rewritten image; a page
  overwrite orphaned its whole builder subtree because `metas()->delete()` only removes the top
  level; an imported account's `status` was written despite being documented as local; media
  matching gave up after fifty unordered candidates; `baseIndexQuery()` returned `null` into a live
  route; `getOptions()` ignored its `$columns`; two copies of a byte formatter both lost their
  precision to an `int` cast.

**What the fixes cost in tests.** Nine new tests, and two existing ones changed to encode the new
contract rather than the old one. Three of the nine exist because the audit found the bug *and* the
fixture that would have caught it: `RewritePassTest` wrote `{}` as its assets file, so the
substitution path it was named for never ran.

---

## 15. The three things the audit left open — **TWO FIXED, ONE IS A RELEASE STEP**

Item 14 closed all seventeen findings and left three follow-ups. Two are now done.

**Core defect 13 is fixed, and this package's workaround is gone.** Core's asset pipeline had no
extension allowlist at all, so the guard this package added in `AssetDriver::upload()` was standing
in for one. `App\Services\Asset\UploadPolicy` now holds the policy — an allowlist read from
`config('ovynt.assets.allowed_extensions')` plus a `finfo` check that the bytes agree with the name
— enforced by `StoreAssetRequest` on the way in over HTTP *and* by `AssetRepository::create()` for
every caller, including the ones that never saw a request.

`AssetDriver` no longer carries a list. It catches the `ValidationException` core throws and turns
it into a `SkipRecord`, so a bundle naming a refused type is still reported as *skipped with a
reason* rather than *failed* — the tally distinction is this package's business, the policy is not.
Two lists that can disagree was the thing worth avoiding, and the copy is always the one that
drifts.

One decision inside the core fix matters here: **`zip` had to be on the allowlist.** Theme packages,
plugin packages and this package's own bundles are all uploaded through that pipeline and handed on
by `asset_id`. An allowlist covering only media would have read as correct and broken every theme
deploy and every bundle upload on the install.

**The upgrade step for pre-scoping runs is written down.** §14 moved the run directory to
`storage/app/site-migration/{database}`, which leaves anything under the old unscoped path invisible
to every screen. There was nothing to move on the development install — the suite had already
emptied it, which is what the scoping fixes — but an install that did have runs keeps them and the
disk they occupy. The README now carries the two-line `mv`, and says why an automatic migration is
not the answer: it would have to guess which database an unscoped run belonged to, which is exactly
the question the scoping exists to stop anyone asking.

**Signing is still open, and cannot be closed here.** §6 already covers why: a signature covers
exact bytes, so it goes stale on the next edit and cannot live in the repository. It needs the
vendor key at release time. Generating one here to make the item go green would put a signing key in
a repository, which lets anyone mint packages in the author's name — worse than shipping unsigned,
and the reason this stays a release step rather than a task.
