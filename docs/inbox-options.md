# In-System Inbox — Approach Comparison

**Goal (from Shameer):** every sales rep gets an **Inbox** inside the system. They see all emails going out *and replies coming in*, per lead, without leaving the app. Everything is recorded so management can evaluate whether the right things are happening at the right time.

**Where we are today:** the system can *send* an email (via Resend) and *log the outbound copy* against the lead. The missing half is **receiving the reply back into the system** and showing both sides as a thread. This doc compares the two real ways to close that gap.

---

## The one question that decides everything

**Must replies live in the rep's own personal Gmail/Outlook, or is a system mailbox acceptable?**

- If a **system mailbox is fine** → Option A. Faster, simpler, fits our stack.
- If emails **must be the rep's own mailbox** (their Sent folder, their threads) → Option B. More powerful, much more work.

Everything below flows from this.

---

## Option A — Inbound webhook (system-addressed email)

Emails send from and reply to a **system domain** (e.g. `reply.levataos.com`). When a lead replies, our mail provider (Resend / Postmark) receives it and POSTs it to our app, which files it against the lead.

**How a reply gets in:** lead replies to `lead-<id>@reply.levataos.com` → provider receives it → POSTs to our new webhook → we match the address to the lead → store as inbound.

| | |
|---|---|
| **Effort** | Moderate. Builds directly on the Resend send path we already have. |
| **Fits our stack?** | Yes. Push-based (no polling), works on shared cPanel hosting. |
| **Per-client deploys** | Easy. Same setup repeats per client, one verified subdomain each. |
| **Setup hurdle** | Verify a subdomain + MX records. No Google/Microsoft review. |
| **Deliverability** | Good (DKIM via provider), but it is a system sender, not the rep. |
| **Microsoft users** | No difference. Works for any recipient. |
| **Big trade-off** | Email no longer "goes through their Gmail." It is centralised under the company domain. |

**Why the trade-off is arguably correct for a CRM:** centralised, fully recorded, identical for every rep and every client deployment, and nothing depends on a rep's personal account staying connected. This is what Shameer wants for *evaluation*. The only thing lost is the email living in the rep's personal mailbox.

---

## Option B — Gmail / Microsoft API (rep's real mailbox)

Each rep connects their own Google or Microsoft account once (OAuth). The system then sends and reads through *their real inbox*. Replies thread naturally in both their mailbox and the app.

**How a reply gets in:** we read the rep's actual inbox via the Gmail API / Microsoft Graph (poll or push), find messages from the lead, match, and store.

| | |
|---|---|
| **Effort** | High. OAuth per provider, per-user tokens, inbox sync, matching. |
| **Fits our stack?** | Poorly without upgrades. Wants a database + a cron/worker; JSON files + shared hosting strain under inbox sync. |
| **Per-client deploys** | Complicated. Each client needs its own Google/Microsoft app or a shared app reps connect through. |
| **Setup hurdle** | **Google verification / security review for read scopes — can take weeks**, needs privacy policy + domain verification. Microsoft is a *separate* build. |
| **Deliverability** | Best. It genuinely is the rep's own email. |
| **Microsoft users** | Needs a second, separate integration (Microsoft Graph). |
| **Big win** | Emails are truly the rep's own: their Sent folder, natural threading, highest trust. |

**Good news:** we already shipped this exact OAuth pattern for the **Zoho CRM** integration (connect, code-to-token exchange, token storage, refresh). Gmail is the same shape, so ~60% of the *plumbing* is a known quantity. The hard parts are Google's verification, per-user tokens, inbox sync, and doing it again for Microsoft.

---

## Side by side

| Factor | A: Inbound webhook | B: Gmail/Microsoft API |
|---|---|---|
| Time to a working inbox | Weeks | Months |
| Email identity | System domain | Rep's own mailbox |
| Replies thread in rep's Gmail | No | Yes |
| Google/Microsoft review needed | No | Yes (weeks) |
| Fits current JSON + cPanel stack | Yes | No (needs DB + worker) |
| Works the same across client deploys | Yes | Harder per client |
| Covers Outlook users with no extra build | Yes | No (separate Graph build) |
| Reuses existing code | Resend send path | Zoho OAuth pattern |
| Ongoing fragility | Low | Higher (token expiry, account disconnects, sync) |

---

## Recommendation

**Build Option A first.** It delivers the in-system inbox Shameer wants in the shortest time, on the stack we already have, and it is identical across every client deployment. The cost is accepting that email becomes **company-addressed rather than the rep's personal Gmail** — which, for a CRM whose whole point is recording and evaluation, is the right design. The team should consciously sign off on that.

**Keep Option B as a later upgrade** for clients who specifically demand emails live in reps' real mailboxes. By then we should also move storage to a real database and add a background worker, which the inbox needs to be robust at volume regardless of approach.

## The real foundation issue (true for both)

The hard constraint is not the email mechanics, it is **storage and hosting**. An inbox means many growing email threads, read across all of a rep's leads, frequently. JSON files with no locking, on shared cPanel hosting, with no background worker, will strain. Doing this "properly" eventually means **a database (SQLite/MySQL) and a cron/worker**. Option A lets us defer that; Option B effectively requires it up front.

---

## Suggested first slice (Option A)

1. Pick the inbound provider (Resend inbound or Postmark) and verify `reply.levataos.com` (MX records).
2. Set each outbound email's `Reply-To` to a per-lead address (`lead-<id>@reply.levataos.com`). One-line change in the existing send path.
3. Add an inbound webhook endpoint that verifies the request, parses the reply, maps it to the lead, and stores it.
4. Add a `direction` (in/out) field to email history; store inbound messages there.
5. Build the Inbox page: threads per lead, unread state, reply in place (reuse the editable-email send we already built).
6. Add a "new reply" notification so reps see incoming mail.
