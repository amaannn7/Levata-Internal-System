# FreeScout — Install as a Standalone Helpdesk (Track A)

**Purpose:** stand up **FreeScout**, a free, open-source helpdesk, on our existing cPanel
hosting as a **separate** ticket dashboard. This is independent of the in-app Help & Support
feature (Track B) — the two run in parallel and are not wired together.

**Why FreeScout:** it is pure **PHP + MySQL**, so it installs on our current Namecheap/cPanel
hosting with no VPS, no Ruby, no Elasticsearch. It is genuinely free (open source), has no
agent-seat cap, and keeps all data on our own server.

---

## What you get

- A helpdesk dashboard at e.g. `https://support.levataos.com`.
- Tickets arrive by **email** (a connected mailbox) or via its API.
- Statuses, assignment, replies, canned responses, a shared inbox.
- Replies are sent to the customer **by email** from within FreeScout.

**Limitation (by design):** this is a separate dashboard. Tickets are managed *in FreeScout*,
not inside our Levata app, and replies reach users by email. (If you want tickets inside our
own app with replies flowing into the client's app, that is the in-app sync — Track B — not this.)

---

## Requirements (all met by our hosting)

- PHP 7.4+ (switchable in cPanel under **Select PHP Version**).
- MySQL / MariaDB database.
- A subdomain, e.g. `support.levataos.com`.
- Cron (for background jobs) — available in cPanel.

---

## Option 1 — One-click via Softaculous (easiest, ~5 min)

1. Log into **cPanel**.
2. Find **Softaculous Apps Installer** (under "Software").
3. Search **FreeScout**. If listed, click **Install**.
4. Set:
   - **Domain:** choose/create `support.levataos.com`.
   - **Directory:** leave blank (installs at the subdomain root).
   - **Admin account:** your name, email, password.
5. Click **Install**. Softaculous creates the database and configures everything.
6. Log in at `https://support.levataos.com`.

> If FreeScout is not in your Softaculous list, use Option 2.

---

## Option 2 — Manual install (if no Softaculous entry)

1. **Create a subdomain** in cPanel → **Domains / Subdomains**: `support.levataos.com`
   (note its document root, e.g. `/home/USER/support`).
2. **Create a MySQL database + user** in cPanel → **MySQL Databases**; grant the user
   ALL privileges on the database. Note the db name, user, password.
3. **Set PHP to 7.4+** in cPanel → **Select PHP Version**, and enable the required extensions
   (pdo_mysql, mbstring, curl, gd, zip, intl — FreeScout's installer lists any missing ones).
4. **Download FreeScout**: from <https://freescout.net/download/> get the latest build (a Zip).
5. **Upload + extract** into the subdomain's document root via cPanel → **File Manager**.
6. Visit `https://support.levataos.com` — the **web installer** runs; enter the database
   details from step 2 and create the admin account.
7. **Add the cron job** (cPanel → **Cron Jobs**), running every minute:
   ```
   * * * * * php /home/USER/support/artisan schedule:run >> /dev/null 2>&1
   ```
   (Use the real path to your subdomain's `artisan` file.)

---

## Connect a mailbox (so tickets arrive by email)

1. In cPanel → **Email Accounts**, create/pick a mailbox, e.g. `support@levataos.com`.
2. In FreeScout → **Manage → Mailboxes → New Mailbox**.
3. Enter the mailbox address, and its **IMAP** (incoming) + **SMTP** (outgoing) settings —
   cPanel shows these under the mailbox's **Connect Devices** page (host = `mail.levataos.com`,
   standard ports, the mailbox password).
4. Send a test email to `support@levataos.com`; it should appear as a ticket in FreeScout.

Now any email to that address becomes a ticket, and replying in FreeScout emails the sender back.

---

## Onboarding a client (e.g. Macktiles) to this helpdesk

Because this is email-based, a client simply emails (or is told to email) `support@levataos.com`.
Optionally give each client its own mailbox (e.g. `macktiles@levataos.com`) and a separate
FreeScout mailbox, so their tickets are grouped.

---

## Maintenance notes

- **Updates:** FreeScout updates from within its own admin UI (or re-upload a new build).
  Because we self-host, updates are our responsibility.
- **Backups:** include the subdomain's files and its MySQL database in the cPanel backup routine.
- **Cost:** the software is free; the only cost is the hosting we already pay for.

---

## Where this fits

- **Track A (this doc):** FreeScout = a ready-made, standalone, email-based helpdesk on our
  hosting. Fast to stand up, data stays ours, but managed outside our app.
- **Track B (in-app sync):** the Help & Support feature inside our Levata app, with client
  tickets forwarded into it and (later) replies flowing back into the client's app. Built in
  our codebase; see the ticket-sync work.

The two are independent and can run side by side.
