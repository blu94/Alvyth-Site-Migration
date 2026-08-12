# Site Migration

Site Migration moves the work you have already done from one Ovynt site to another. You choose
what travels, the plugin writes it into a single file, and you import that file on the other site.

Typical jobs:

- Push a staging site's catalogue to the live site.
- Set up a client's shop from a template site you keep for the purpose.
- Move a shop onto new hosting without retyping anything.

It is **not** a backup. A backup puts a site back the way it was; this takes selected things from
one site and adds them to another site that already has content of its own.

---

## Before you start

You need the plugin installed and enabled on **both** sites. A bundle written by one site can only
be read by another running this same plugin.

---

## Exporting

Open **Site Migration → Export**.

1. Under **Include**, choose what you want to take. Anything you leave out is simply not in the
   file.
2. **If a record is already there** is a note for the other site's operator. The site importing
   the bundle makes its own decision, so this is a suggestion rather than a setting.
3. Press **Export / Continue**.

### It may take more than one press

A big catalogue does not finish in one go. Each press works for about twenty seconds, writes down
where it got to, and stops — so nothing is ever lost half-way. The **State** line tells you where
you are:

> Paused with 1,200 records written. Press Continue.

Keep pressing **Export / Continue** until it says *Finished*.

### Finding your file

When the run finishes, **Bundle written to** shows you where the file is, for example:

```
storage/app/site-migration/20260812-141233-a7f3/bundle.zip
```

Fetch it with SFTP, or your host's file manager. **There is no download button on this screen**,
and that is on purpose: the only folder this platform can serve files from is the public one, and a
bundle left there could be downloaded by anyone who guessed its address. Your bundle can contain
your entire catalogue, so it stays somewhere private.

---

## Importing

Open **Site Migration → Import** on the *other* site.

### 1. Upload the file

Drop the zip onto the **Bundle file** box and wait for it to finish uploading.

Then press **Read bundle** straight away. Between the upload finishing and that press, the file sits
in a public folder — pressing the button moves it somewhere private and deletes the public copy. If
you change your mind and never press it, the file is cleaned up automatically after half an hour.

**What is in it** then tells you where the bundle came from and what it holds:

```
From: https://staging.example (Ovynt 1.3.0)
Written: 2026-08-12T04:11:09Z
Contains: 412 products
Locales: en, ms
```

If the bundle came from a *newer* version of Ovynt than this site runs, it is refused here, before
anything is written, and the message names both versions.

### 2. Choose what to bring in

**Only these** lets you take part of a bundle. Leave it empty to import everything. Anything you
leave out is never even read, so narrowing an import makes it faster as well as smaller.

**If a record is already here** is the important one:

- **Leave mine alone** — anything this site already has is untouched. New records are still added.
- **Overwrite mine** — this site's version is replaced by the bundle's.

Overwriting also needs permission to *update* each kind of record, not just permission to run a
migration.

### 3. Preview

Press **Preview changes**. Nothing is written. You get a line like:

> Nothing has been written yet. Products: 12 new, 312 will be overwritten, 88 unchanged

This is the step worth not skipping. *"312 products will be overwritten"* is exactly the sentence
you want to read **before** it happens rather than after.

If the bundle carries languages this site is not set up for, the preview says so. Nothing breaks —
the translations are stored, they just will not appear anywhere until you add the language under
Settings.

### 4. Import

Press **Import / Continue**. As with exporting, a large bundle takes several presses. The **State**
line keeps count:

> Finished — 12 created, 312 updated, 88 skipped, 0 failed.

**If it stops part-way, press Import / Continue again — do not start over.** An interrupted import
leaves the site partly filled in, and continuing picks up exactly where it stopped. Running it again
from the beginning is also safe, just slower: records are matched on their own identity, so nothing
is ever added twice.

---

## How records are matched

A record finds itself on the other site by something meaningful, never by its database id.

| What travels | Matched on |
|---|---|
| Products, and their variants | The SKU |

This is why importing the same bundle twice does not give you two of everything, and why you can
correct something and re-import without tidying up first.

**A product with no SKU cannot be placed.** There is nothing to match it on, so it is counted in the
preview as *cannot be placed* and skipped. If you see that number, give those products a SKU on the
site you exported from and export again.

---

## Doing it repeatedly

Pushing staging to live is not usually a one-off. Every record in a bundle carries a fingerprint of
its own contents, so the second migration between the same two sites only touches what actually
changed — everything else is reported as *unchanged* and skipped without being rewritten.

---

## What never travels

No bundle ever contains:

- Payment gateway keys, your mail password, or your AI key.
- Login sessions, access tokens, or two-factor settings.
- The activity log — it is a record of what happened on the *other* site, and filing it here would
  spoil this site's own audit trail.
- Installed plugins and themes. Those are packages with their own installers and licences.

---

## Who can do this

Running a migration needs the **Site Migration** permission — but that on its own is never enough.
Every kind of record is checked against your own permission for it:

- To **export** products, you need permission to view products.
- To **import** them, you need permission to create products.
- To **overwrite** them, you also need permission to update products.

If you export something you are not allowed to see, it is left out of the bundle and the **Messages**
box says which. If you import something you are not allowed to write, the run stops and names it,
rather than quietly skipping it and leaving you with a half-migrated site.

---

## History

**Site Migration → History** lists what this site has exported and imported, newest first, with what
each run did and where its bundle is.

Deleting a bundle from the server does not undo an import. The records it wrote stay exactly where
they are.

---

## When something goes wrong

| What you see | What it means |
|---|---|
| *That zip has no manifest.json* | The file is not a migration bundle — probably a backup or a theme package. |
| *This bundle came from Ovynt X and this site runs Y* | The source site is newer. Update this site first. |
| *The … data in this bundle does not match its checksum* | The file was damaged in transit. Export and re-upload; nothing was written. |
| *This product has no SKU* | Nothing to match it on. Give it one on the source site. |
| *You do not have permission to update products* | Overwriting needs that permission as well as the migration one. |
| The run says **Paused** | Normal on a large site. Press the button again. |
