# Site Migration

Site Migration moves the work you have already done from one Ovynt site to another. Everything
travels unless you leave it out, the plugin writes it into a single file, and you import that file
on the other site.

Typical jobs:

- Push a staging site's catalogue to the live site.
- Set up a client's shop from a template site you keep for the purpose.
- Move a shop onto new hosting without retyping anything.

It is **not** a backup. A backup puts a site back the way it was; this takes things from one site
and adds them to another site that already has content of its own.

---

## Before you start

You need the plugin installed and enabled on **both** sites. A bundle written by one site can only
be read by another running this same plugin.

---

## Exporting

Open **Site Migration → Export**.

1. **Leave out** names anything you do *not* want in the bundle. Leave it empty and the whole site
   travels — content, media, settings, themes, plugins, accounts, orders, invoices, comments and
   form leads. The picker asks what to leave out rather than what to take, so forgetting something
   means it travels anyway, which is the safer mistake.
2. **Include the image files** decides whether the actual pictures travel. Leave it on and the
   other site owns its images. Turn it off and the bundle is a fraction of the size, but the new
   site loads its pictures from this one until you upload them there — useful when the bundle would
   otherwise be too big to upload.
3. **Credentials** is off. See below.
4. Press **Export / Continue**.

### It may take more than one press

A big catalogue does not finish in one go. Each press works for about twenty seconds, writes down
where it got to, and stops — so nothing is ever lost half-way. The **State** line tells you where
you are:

> Paused with 1,200 records written. Press Export again to carry on.

Keep pressing **Export / Continue** until it says *Finished*.

### Downloading your file

When the run finishes, press **Download the bundle**. You get a private link that works for a few
minutes and then stops working — nothing about the bundle is ever readable from the web without it.
If you come back to the screen later the link is simply issued again, so a stale page is never a
problem; press Download once more.

The file itself stays in private storage on the server, and the copy made for the download is
removed by your next visit to any Site Migration screen, and in any case within the hour.

### Records about people

Accounts, orders, invoices, comments and form leads are not content you wrote — they are people's
details, purchases and words — and a bundle containing any of them is a personal-data export the
moment it leaves your building. The export screen says the same. Leave them out unless you are
genuinely moving a whole site rather than seeding a new one.

If they do travel:

- **Accounts arrive unable to sign in.** Each password is replaced with one nobody holds, so each
  person resets it themselves in the ordinary way. Nothing else about signing in travels at all: no
  sessions, no two-factor settings, and no roles — somebody on the other site grants those
  deliberately.
- **Orders and invoices keep their numbers where the number is free.** If the number is already
  used on the other site, the incoming record takes *that site's next number in sequence* —
  `INV-000042`, never `INV-0007-2` — because numbering is a sequence, not a label. The customer's
  own copy still shows the old number, so the number it arrived under is kept on the imported
  record where you can see it and match the two.
- **Orders bring their items and addresses with them**, and each line is re-linked to its product
  by SKU. The addresses attach to the order itself, never to anyone's address book.
- **Comments keep their threading and land on what they were about** — a comment about a post that
  is not on the other site is skipped and reported, never filed under the wrong thing.
- **Form leads land on their form.** A lead whose form did not travel is skipped and reported.

### Taking your credentials with you

**Credentials** — your payment gateway keys, mail password and AI key — is off, and stays off
unless you deliberately turn it on. Nothing secret is ever in a bundle otherwise.

Turn it on and you must choose a **passphrase**, typed twice. The credentials are encrypted with it
before they go anywhere near the file.

Three things worth knowing:

- **The passphrase is never stored.** Not on this server, not in the bundle, not in any log. If you
  lose it, the credentials in that bundle cannot be recovered by anyone, including you. That is why
  it is asked for twice.
- **Send it separately from the file.** Not in the same email. A locked box and its key in one
  envelope is not locked. This is the entire protection, and nobody works it out on their own.
- **Copying live gateway keys to a staging site means staging can charge real cards.** If you are
  setting up a test site, leave this off and enter test keys there by hand.

---

## Importing

Open **Site Migration → Import** on the *other* site.

### 1. Upload the file

Drop the zip onto the **Bundle file** box and wait for it to finish uploading.

Then press **Read bundle**. The upload goes straight to private storage — it is never in a
web-readable folder at any point — and pressing the button moves it into this run's own directory.
If you change your mind and never press it, the file is cleaned up automatically after half an
hour.

**What is in it** then tells you where the bundle came from and what it holds:

```
From: https://staging.example (Ovynt 1.3.0)
Written: 2026-08-12T04:11:09Z
Contains: 412 products, 19 pages, 2 orders, 1 invoices, 13 comments, 21 leads
Locales: en, ms
```

If the bundle came from a *newer* version of Ovynt than this site runs, it is refused here, before
anything is written, and the message names both versions.

### 2. Choose what to bring in

**Leave out** lets you take part of a bundle. Leave it empty to import everything it carries.
Anything you name is never even read, so narrowing an import makes it faster as well as smaller.

**Passphrase** is only needed if the bundle carries credentials, which **What is in it** will have
told you. Credentials are applied last, after everything else has succeeded, so a wrong passphrase
costs nothing that already imported: they are skipped, you are told, and this site's existing keys
are left exactly as they were.

**Overwrite my records when they clash** is the important one, and it is off by default:

- **Off** — nothing on this site is touched, and nothing in the bundle is dropped either. A record
  whose identity is already used here — the same SKU, the same slug, the same invoice number — is
  added *alongside* under a free name: `HAT-1` and `HAT-1-2`, or the next free invoice number. You
  can merge the two afterwards knowing you still have both.
- **On** — this site's version is replaced by the bundle's, behind a confirmation you have to
  accept. That is not reversible, except for pages, which keep a snapshot.

Overwriting also needs permission to *update* each kind of record, not just permission to run a
migration.

### 3. Preview

Press **Preview changes**. Nothing is written. You get a line like:

> Nothing has been written yet. Products: 12 new, 3 clash and will be added alongside under a free
> name, 88 unchanged

This is the step worth not skipping. A count of what will be replaced or added alongside is exactly
the sentence you want to read **before** it happens rather than after.

If the bundle carries languages this site is not set up for, the preview says so. Nothing breaks —
the translations are stored, they just will not appear anywhere until you add the language under
Settings.

### 4. Import

Press **Import / Continue**. As with exporting, a large bundle takes several presses. The **State**
line keeps count:

> Finished — 12 created, 3 updated, 88 skipped, 0 failed.

**If it stops part-way, press Import / Continue again — do not start over.** An interrupted import
leaves the site partly filled in, and continuing picks up exactly where it stopped. Running it again
from the beginning is also safe, just slower: records are matched on their own identity, so nothing
is ever added twice.

---

## How records are matched

A record finds itself on the other site by something meaningful, never by its database id.

| What travels | Matched on |
|---|---|
| Categories and tags | Their web address (slug) |
| Pages and posts | Their web address (slug) |
| Products, and their variants | The SKU |
| Images and files | The contents of the file itself |
| Discounts | The code customers type |
| Shipping and tax zones | Their slug |
| Email templates | Which message they are |
| Invoice templates | Their title |
| Themes and plugins | Their slug |
| Customer accounts | The email address |
| Orders | The order number |
| Invoices | The invoice number |
| Comments | The same words, at the same moment, about the same thing |
| Form leads | The same form, at the same moment, with the same answers |

This is why importing the same bundle twice does not give you two of everything, and why you can
correct something and re-import without tidying up first.

Images are matched on their **contents**, not their filename or where they were stored, so the same
photograph is never imported twice even though the two sites file it under different paths.

**A product with no SKU cannot be placed.** There is nothing to match it on, so it is counted in the
preview as *cannot be placed* and skipped. If you see that number, give those products a SKU on the
site you exported from and export again.

Three things arrive deliberately inert:

- **An imported theme never activates over one already in use.** It arrives installed; switching to
  it is one click you make.
- **An imported invoice template never becomes your main one** if you have already chosen a main
  template here. If you have not chosen one, the bundle's choice is adopted, since there is nothing
  to overrule. Your invoices carry the design they were issued under wherever that design travelled
  with them; where it did not, they fall back to whichever template this site treats as main.
- **An imported plugin always arrives disabled**, and a paid plugin's licence is bound to a domain,
  so it needs re-licensing here.

---

## Doing it repeatedly

Pushing staging to live is not usually a one-off. Every record in a bundle carries a fingerprint of
its own contents, so the second migration between the same two sites only touches what actually
changed — everything else is reported as *unchanged* and skipped without being rewritten. Importing
a site's own bundle back into it writes nothing at all.

---

## What never travels

No bundle ever contains:

- Payment gateway keys, your mail password, or your AI key — **unless** you deliberately switch
  credentials on, and then only encrypted under your passphrase, never as readable text.
- Login sessions, access tokens, two-factor settings, or anyone's password.
- Roles. An imported account can sign in to nothing until somebody here grants it something.
- The activity log — it is a record of what happened on the *other* site, and filing it here would
  spoil this site's own audit trail.
- A plugin's licence key. Licences are bound to a domain, and a copied key would only look licensed.

---

## Who can do this

Running a migration needs the **Site Migration** permission — but that on its own is never enough.
Every kind of record is checked against your own permission for it:

- To **export** products, you need permission to view products.
- To **import** them, you need permission to create products.
- To **overwrite** them, you also need permission to update products.

Comments and form leads follow the permissions that guard them elsewhere in the admin: comments are
checked against the comments permission, and leads against the forms permission — the same grants
you need to read or moderate them on this site.

If you export something you are not allowed to see, it is left out of the bundle and the **Messages**
box says which. If you import something you are not allowed to write, the run stops and names it,
rather than quietly skipping it and leaving you with a half-migrated site.

---

## History

**Site Migration → History** lists what this site has exported and imported, newest first, with what
each run did and how much disk its bundle holds, and lets you remove a run you have already carried
across.

Deleting a run does not undo an import. The records it wrote stay exactly where they are.

---

## When something goes wrong

| What you see | What it means |
|---|---|
| *That zip has no manifest.json* | The file is not a migration bundle — probably a backup or a theme package. |
| *This bundle came from Ovynt X and this site runs Y* | The source site is newer. Update this site first. |
| *The … data in this bundle does not match its checksum* | The file was damaged in transit. Export and re-upload; nothing was written. |
| *This product has no SKU* | Nothing to match it on. Give it one on the source site. |
| *You do not have permission to update products* | Overwriting needs that permission as well as the migration one. |
| *That passphrase does not open this bundle's credentials* | Wrong passphrase, or the file was altered. Everything else imported; your existing keys are untouched. |
| *The file for "…" was already missing on the site this bundle came from* | An image record whose file had been deleted long ago. Counted as skipped, not failed — nothing is wrong here. |
| *The post this comment was about is not on this site* | The comment's subject did not travel or no longer exists anywhere. The comment is skipped and counted, never filed under the wrong thing. |
| *The form this lead was submitted to is not on this site* | Import the forms first, or leave leads out. |
| *This bundle is 240 MB and this server only accepts uploads up to 64 MB* | Export again with the image files turned off. |
| The run says **Paused** | Normal on a large site. Press the button again. |
