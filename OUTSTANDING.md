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

## 10. Downloading the bundle — **RESOLVED: a real download**

The bundle is generated under `storage/app`, off the web. Pressing **Download** copies it to the
public disk under a 64-character random name, the browser fetches it, and the copy is removed — by
the operator's own press, by the sweep on the next screen load, and in any case within the hour.

It **transits**; it is not stored. That transit is unavoidable: the only directory nginx serves is
the public one, a plugin registers no routes, and `savePageData` always wraps its return in
`response()->json()`, so no package endpoint can return bytes. The engine's `download` action does
fetch a URL as an authenticated blob, but it lives in `useModuleWrapper`, which custom pages do not
use, and it would still need an endpoint that returns a blob.

Verified end to end: `HTTP 200, 1,533,667 bytes, application/zip`.

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

## 12. Uninstalling leaves the run directory behind — **NOTED**

The package owns no database tables, so `uninstall.drop_tables` is absent and there is nothing for
Ovynt to clean up. `storage/app/site-migration` survives an uninstall.

**Resolution.** Left as is, and documented. The plugin system offers no uninstall hook for files, so
the alternatives are to leave them or to have some other code path delete them — and a plugin that
deleted an operator's only copy of a bundle on uninstall would be doing something much worse than
leaving a directory behind. The README says where it is and that removing it is a manual step.
