# Outstanding issues

Everything known to be wrong, missing or undecided in this package, with the resolution taken for
each. Written after the build rather than during it, so the reasons are the ones that actually
applied rather than the ones anticipated.

Items marked **FIX** are addressed in this change. Items marked **DEFER** are left undone
deliberately, and say what would have to be true to do them. Items marked **DECIDE** need an answer
from the product owner before any code is worth writing.

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

## 7. Orders, invoices, comments and leads do not travel — **DEFER**

Already documented in `DriverRegistry::RECORD_GROUPS` and the README. Restated here because it is
the largest known gap and should be findable from one place.

- **Invoices** carry the source's numbering sequence, and the destination computes its next number
  from its own rows. The collision is silent until an accountant finds two invoices sharing a
  number. Choosing between renumbering (which breaks the copy the customer already holds) and
  preserving (which breaks the destination's sequence) is a product decision.
- **Orders** are meaningless without their items, addresses and invoice, so they cannot land before
  that question is answered.
- **Comments and leads** point at what they are about through ids that must be remapped **at write
  time**, and a driver has no access to the run's id map. Widening that seam is a deliberate
  architectural change, not a side effect of adding a driver.

**What would have to be true:** an answer on invoice numbering, and a decision to pass the run's
`IdMap` into `ResourceDriver::write()`.

---

## 8. Publish-state filter and incremental export — **DEFER**

Spec §8.6 and §8.7. Both narrow what an export walks.

§8.7 in particular is not free and the specification says so: `products` declares one index beyond
its key and `pages` declares none, so filtering on `updated_at` is a **full table scan on the two
largest tables**. Either the package ships an index — a schema change to core tables made by a
plugin, which this package has deliberately avoided entirely — or it offers a filter that quietly
scans.

**What would have to be true:** a decision that a plugin may add an index to a core table. Note the
content hash (§8.1, shipped) already delivers most of the benefit without touching the schema,
which is why it was built first and this was not.

---

## 9. Does a bundle carry the theme? — **DECIDE**

Spec D-T1. A site whose look is defined by a theme is not reproduced by its data alone. Themes
already move as ZIPs with their own installer, so the likely answer is "no, but name the theme and
version in the manifest and warn when the destination differs".

It matters more than it first appears: theme settings hold the **menus**, and menu data is content
the operator authored — so under the current build a migrated site arrives with its navigation
missing and nothing says why.

**What would have to be true:** a decision on whether the manifest records the theme, and whether a
mismatch is a warning or a refusal.

---

## 10. The bundle cannot be downloaded from the admin — **DECIDE**

The only web-reachable directory on this platform is the public folder, and the two mechanisms core
offers for anything else do not exist on this build: there is no `protected` disk configured, and
no `assets.view` route, so `Asset::path()` on a non-public asset throws.

Current behaviour: the bundle stays under `storage/app`, off the web, and the screen says where it
is. An operator fetches it over SFTP.

The alternative is to write it to the public disk under an unguessable name with a prominent
"delete from server" action. That is more convenient and strictly less safe — the file is readable
by anyone who learns the URL, for as long as it is there, and a bundle can contain the whole site.

**This is not a technical question and should not be answered by the person writing the code.**

---

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
