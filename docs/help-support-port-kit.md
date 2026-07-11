# Help & Support — Port Kit (add the feature to another deployment)

Use this to add the **Help & Support** feature (feedback + support tickets, with
optional client↔hub sync) to a deployment that does not have it yet — e.g. Macktiles.

The feature lives in **three files**: `support.php` (new), `api.php` (endpoints), and
`index.html` (UI). Do **not** touch `db.php` (it holds that server's own DB credentials),
and **no SQL / migration is needed** — the ticket store auto-creates on first use.

> Reference source: this repo (levataos / the hub). All line numbers below refer to
> the files in this repo at the time of writing; use them as a guide, and match on the
> surrounding code rather than the exact numbers if the target file differs.

---

## STEP 0 — Diff first (do not blind-overwrite)

Because the target's `api.php` / `index.html` may be customised, **compare before changing**:

1. Back up the target's current `api.php`, `support.php` (if any), and `index.html`.
2. Diff the target's `api.php` and `index.html` against this repo's versions.
   - If they are essentially identical (same app/version) → you may copy whole files
     (Step A below).
   - If they differ (branding, older, local tweaks) → merge only the marked blocks
     (Step B below). This is the safe default when unsure.

---

## STEP A — If the codebases are the same

Deploy this repo's three files to the target server, after backing up theirs:

- `support.php`  (new file — copy whole)
- `api.php`      (copy whole)
- `index.html`   (copy whole)

Leave `db.php` untouched. Done — go to "Configure" below.

---

## STEP B — If the codebase has diverged (merge just the feature)

### B1. Add `support.php`
Copy this repo's `support.php` into the target as a new file (whole file). It is
self-contained: the ticket store, categories, email, and the sync helpers.

It depends on these functions that already exist in the app: `getAdmin()`,
`dbGetBlob()` / `dbSaveBlob()`, `dbSyncReportingTable()`. (Any Postgres-based build
of this app has them.)

### B2. Wire it into `api.php`

**(i) Include the module** — near the other `require_once` lines at the top
(this repo: line ~55):
```php
require_once __DIR__ . '/support.php';
```

**(ii) Add the endpoints** — inside the main `switch ($action)`, alongside the other
`case` blocks. Copy this repo's ticket cases (this repo: lines ~2578–2781):
`tickets`, `save-ticket`, `ingest-ticket`, `ingest-reply`, `ticket-reply`,
`update-ticket-status`, `delete-ticket`.

**(iii) Add the settings handling** — inside the `admin-settings` POST case, add the
lines that persist the support + sync settings (search this repo's `api.php` for
`support_email` to find the block):
```php
if (isset($input['support_email'])) $admin['support_email'] = trim($input['support_email']);
if (isset($input['support_from'])) $admin['support_from'] = trim($input['support_from']);
if (isset($input['ticket_hub_url'])) $admin['ticket_hub_url'] = trim($input['ticket_hub_url']);
if (isset($input['ticket_hub_secret']) && strpos($input['ticket_hub_secret'], '****') === false) $admin['ticket_hub_secret'] = trim($input['ticket_hub_secret']);
if (isset($input['ticket_client_name'])) $admin['ticket_client_name'] = trim($input['ticket_client_name']);
if (isset($input['ticket_ingest_secret']) && strpos($input['ticket_ingest_secret'], '****') === false) $admin['ticket_ingest_secret'] = trim($input['ticket_ingest_secret']);
```
(Also add `resend_key` to the masked-keys loop if the target doesn't already send email.)

### B3. Wire it into `index.html`

Copy these four pieces from this repo's `index.html` into the target's (search for the
quoted markers):

1. **Nav item** — the `HELP & SUPPORT` section: the
   `<div class="nav-item" data-page="support">…</div>` block.
2. **Page section** — `<section class="page-section" id="page-support">…</section>`
   (stat cards, client filter, ticket list).
3. **Ticket modal** — `<div class="modal-overlay" id="ticket-modal">…</div>`.
4. **JS controller** — the `Help & Support` JS block (starts at
   `let _supTickets=[]` … through `window.supDeleteTicket`), plus:
   - add `if(p==='support'&&typeof window.loadSupport==='function')window.loadSupport();`
     inside `showPage()`.
   - the `Ticket Sync` settings card + its `ticket-sync-form` submit handler, and the
     support/sync fields inside `loadAdminSettings()` and the `admin-settings-form` submit.

---

## Configure (both this hub and the client spoke)

The same code is a **spoke** or a **hub** purely by its settings (Admin → Settings →
**Ticket Sync**). Pick one shared secret and use it on both.

**On the CLIENT (spoke, e.g. Macktiles):**
- Hub URL: `https://levataos.com/api.php?action=ingest-ticket`
- Hub Secret: `<shared-secret>`
- This Client's Name: `Macktiles`
- (leave Ingest Secret blank)

**On the HUB (levataos.com):**
- Ingest Secret: `<same shared-secret>`

Setting the Ingest Secret puts the hub into **hub mode**: Help & Support becomes a
**client-ticket inbox** — the "+ New Ticket" button and the my/all scope switch are
hidden, and the list shows only tickets forwarded from client (spoke) systems. The
hub team manages and replies to client tickets; they do not file their own here.
(Spoke deployments, with no Ingest Secret, are unaffected — their users create and
view tickets normally.)

Both secrets must match — the same value authenticates ticket-in (spoke→hub) and
reply-back (hub→spoke).

For email notifications, also set (per deployment): Resend API Key, Support Email,
Support From.

---

## Verify (smoke test)

1. On the client, log in as a normal user → **Help & Support → New Ticket** → submit.
2. On the hub, open **Help & Support** → the ticket appears, tagged with the client name.
3. On the hub, reply in the ticket thread.
4. On the client, reopen the ticket → the reply appears in the thread.

If step 2 fails: check the shared secret matches and the client can reach the hub URL.
If step 4 fails: check the hub can reach the client's `?action=ingest-reply` URL.

---

## Safety checklist

- [ ] Backed up the target's `api.php`, `support.php`, `index.html` before changes.
- [ ] Did **not** modify or overwrite `db.php`.
- [ ] No SQL / migration run (not needed — store auto-creates).
- [ ] Confirmed the target is a Postgres build (has `dbGetBlob`/`dbSaveBlob`).
- [ ] Shared secret identical on hub and spoke.
- [ ] Smoke test passed both directions.
