# Connecting Reps' Personal Email (Gmail) — What It Takes, the Restrictions, and Easier Alternatives

**Question being answered:** if we build the "connect your own Gmail" feature (a Google app, OAuth, send-as-rep, read replies), is that a good path to put in front of clients? What are the restrictions? And are there easier ways to connect personal email so reps still get the "it's my own inbox" experience without the heavy build?

**Short answer up front:** the full Gmail-app path *works* and is the most powerful option, but it carries a Google review/cost burden that fits a single large SaaS (like Outreach) far better than our "a copy per client" model. There are lighter ways to get most of the value. This doc lays out all three honestly so the team can choose with eyes open.

See also: [inbox-options.md](inbox-options.md) (system-mailbox vs rep-mailbox inbox) — this doc is the deeper dive on the *Gmail-app* side of that decision.

---

## What the Gmail-app path actually is

Each rep clicks **"Connect Gmail"** once and authorizes our app (OAuth). From then on the system can:

- **Send as the rep** — mail genuinely leaves their Gmail account, lands in their Sent folder, threads naturally.
- **Read their inbox** — so we detect replies, mark a lead "Replied", and auto-pause any sequence.

To do this in production, we register a **Google Cloud app** that requests Gmail's `gmail.send` and `gmail.readonly` permissions ("scopes"). Those are **restricted scopes** because they touch real email, and that is what triggers Google's review.

---

## The restrictions (this is the part to read carefully)

This is the section that matters for selling. Every figure below is current as of June 2026, with sources listed at the end of the document.

### 1. Google verification is required for production — with real costs

Because we ask for **restricted** Gmail scopes (`gmail.send` + `gmail.readonly`), before anyone outside a small test group can use the feature Google requires two things:

**(a) OAuth consent / brand review** — we must publish and submit:
- a publicly accessible app home page describing the app,
- a privacy policy that explicitly states how the app accesses, uses, stores, and shares Google user data,
- a demonstration video showing the OAuth flow and how each scope is used,
- domain verification + logo.
- **Cost: free. Time: days to a few weeks.** Reviewed by Google staff.

**(b) Restricted-scope security assessment (CASA)** — an independent, Google-approved third-party security lab audits how we store and protect the email data. The numbers:

| Item | Figure |
|---|---|
| Who charges | The third-party assessor (a private security lab), **not Google** — Google charges **nothing** itself |
| Tier required for Gmail | **CASA Tier 2** (triggered by restricted/sensitive scopes) |
| Typical cost | **~$500 to $4,500 USD per assessment**; Tier 2 commonly **$3,000+**, edge cases up to **$5,000+** if a full penetration test is needed |
| Frequency | **Every 12 months** — it is an **annual recurring cost**, not one-time |
| Self-scan option | **No longer allowed** for Tier 2 — you must pay for an official third-party assessment |
| Time | Weeks to schedule + complete |

So the realistic budget is **a few thousand USD every year, per verified app**, indefinitely, for as long as we use Gmail restricted scopes. Plus our own engineering time to pass it (privacy controls, data handling evidence, remediation).

### 2. Without verification, we are capped at ~100 test users

We can run fully **unverified** by manually whitelisting up to ~100 test users. Perfect for an internal pilot. Everyone else sees a Google "this app isn't verified" warning, and restricted scopes are blocked. So: fine to prove it works; not fine to sell at scale.

### 3. It collides with our "separate copy per client" model

Our system ships as an isolated copy per client. Google verification is **per app**, not per domain:

- **One central Google app** (every client's reps consent to *Levata's* app) → we verify once, but each client's reps see "**Levata** wants to read and send your email" — some clients will object to handing their inboxes to a vendor app.
- **One Google app per client** → cleaner trust story, but we pay verification + CASA **per client**, multiplying a recurring cost across few clients. At ~$3,000/yr/app, **10 clients = ~$30,000/yr in CASA fees alone**, before any of our own labour.

Either way the thing that makes our model simple (a self-contained copy) breaks, because a Gmail integration needs a Google-approved app identity that lives *above* the individual deployment.

### 4. Gmail sending caps (and a Workspace gotcha)

Even once connected, Gmail limits sends **per account per day**:

| Account type | Daily send cap |
|---|---|
| Free `@gmail.com` | **~500 messages/day** |
| Google Workspace (paid) | **~2,000 messages/day** |

**The gotcha:** a Workspace account only gets the 2,000/day cap **after** the domain has paid at least **$100 cumulative** in Workspace fees **and** at least **60 days** have passed since hitting that threshold. Until both are true, a brand-new Workspace account is throttled to the **same 500/day** as a free account. So a client who just signed up for Workspace will *not* get the higher limit immediately.

These are per-sender caps. Fine for genuine 1:1 selling; not a bulk blaster. There are also **API quota units** (e.g. each send costs 100 units; ~60 sends/min per user max), but the daily 500/2,000 cap is almost always the limit you hit first.

### 5. Other practical restrictions

- Gmail only sends from addresses the account actually owns (its own address or verified aliases) — not arbitrary "from" addresses.
- It is a **per-rep** connection: each rep must authorize, tokens expire and need refresh, and a rep disconnecting/changing password breaks their link.
- Reading inboxes at volume strains our current JSON-file + shared-hosting stack; doing it properly wants a database + background worker (same conclusion as [inbox-options.md](inbox-options.md)).

---

## Is it "good" to put in front of clients?

**As the default product: no.** The recurring CASA cost, the weeks-long Google review, and the per-app verification all fight a "sell many isolated copies" business. Outreach/HubSpot pay CASA happily because they are **one app serving millions** — the cost is a rounding error spread across a huge paying base. We would pay a similar fixed cost across a handful of clients, for a feature most clients do not strictly need.

**As an optional, advanced, client-owns-the-keys add-on: yes, it can be good** — for a specific technical client who insists their reps' mail send from their own Gmail and who will do (or pay us to do) the Google setup. Ship it opt-in, not as the standard path.

---

---

## Total cost picture (for the "we're selling this" decision)

What the Gmail-app path actually costs us, recurring, separate from build time:

| Cost item | Amount | Frequency | Notes |
|---|---|---|---|
| Google verification (consent review) | **Free** | Once per app (+ re-review on changes) | Our time only |
| CASA Tier 2 assessment | **~$500–$4,500** (often $3,000+) | **Annual, per app** | Paid to a third-party lab, not Google |
| Our labour to pass CASA | Engineering days | Annual | Evidence, remediation, re-submission |
| Gmail API usage | **Free** | — | Within daily caps |
| Workspace seats (for 2,000/day cap) | Client's cost, **$100 cumulative + 60 days** to unlock | — | Client pays; not us |

**The model multiplier is the killer.** Because we sell isolated copies, the honest question is "one central Google app, or one per client?":

- **One central app:** ~$3,000/yr total CASA, but every client's reps consent to *Levata's* app reading their mail — a trust objection for security-conscious clients.
- **One app per client:** clean trust, but **~$3,000/yr × number of clients**, recurring forever.

Compare this to **Resend**, where verification is a **free, one-time, self-service DNS task per domain** with **zero annual review** — which is why it fits a "sell many copies" business and Gmail does not.

---

## Easier ways to connect personal email

The goal behind "connect my Gmail" is usually one of: *(a)* mail should look like it came from a person, *(b)* it should land in the rep's Sent folder, *(c)* replies should be captured. Here are lighter ways to hit those without the full Gmail-app review.

### Option A — Resend from our own domain (recommended default)

Send everything through Resend from a domain **we** verify (`levataos.com`, or per-client `acme.levataos.com`). No Google, no OAuth, no CASA. Add the engagement layer on top:

- **Open + click + delivery/bounce tracking** — small build, no mailbox access needed.
- **Reply capture via inbound parsing** — route replies for a subdomain back through Resend, file them against the lead (this is [inbox-options.md](inbox-options.md) Option A).

| | |
|---|---|
| Setup hurdle | Verify a domain in DNS — **free, once, self-service** |
| Per-client | Trivial — each copy gets its own from-address |
| Reply capture | Yes (inbound parsing) |
| Rep's own Sent folder | No (system-addressed) |
| Google review | **None** |

**Trade-off:** mail is company-addressed, not literally the rep's Gmail. For a CRM whose point is recording and evaluation, that is arguably the *right* design.

### Option B — Gmail "Send mail as" alias / SMTP (lighter than the API)

A rep can add a **Send-as alias** in their own Gmail settings, or we send via **Gmail SMTP with an App Password** the rep generates. This sends from their address without us building a full OAuth app with restricted scopes.

| | |
|---|---|
| Setup hurdle | Rep generates an App Password / adds an alias — minutes, no Google app review |
| Per-client | Works, but each rep does manual setup |
| Reply capture | No (SMTP is send-only; replies still need inbound parsing or IMAP) |
| Rep's own Sent folder | Partial — depends on setup |
| Google review | **None** (no restricted-scope OAuth app) |

**Trade-off:** App Passwords require 2FA and are fiddly for non-technical reps; Google is also gradually tightening these. Send-only, so replies still need a separate path. Good as a low-tech "send from my address" without the API burden.

### Option C — Full Gmail API (this document's main subject)

The powerful one, with all the restrictions above. Best reserved for an opt-in advanced add-on where the **client owns their own Google app** (they paste their Client ID/Secret into settings, exactly like our Zoho card already does) — so *their* app is verified, *their* consent screen, *their* CASA. We ship the capability; they shoulder the Google relationship.

---

## Side by side

| Factor | A: Resend (our domain) | B: Alias / SMTP | C: Gmail API |
|---|---|---|---|
| Mail from rep's address | No (company) | Yes | Yes |
| Lands in rep's Sent folder | No | Partial | Yes |
| Captures replies | Yes (inbound parse) | No (needs extra) | Yes (native) |
| Google app review / CASA | None | None | **Yes (cost + weeks)** |
| Per-rep setup | None | Manual (App Password) | OAuth click |
| Fits "copy per client" | Yes | Mostly | Poorly |
| Effort for us | Low | Low–medium | High |
| Recurring cost | Resend per-email | None | **CASA ~thousands/yr** |

---

## Recommendation

1. **Default the product to Option A** (Resend from our domain + open/click/reply tracking). It fits how we sell, needs no Google review, costs nothing in verification, and delivers most of the "live email" feel.
2. **Offer Option C (full Gmail API) only as an opt-in advanced add-on**, structured so the **client owns their own Google app** — so the verification/CASA burden sits with them, not multiplied across our deployments. Price the setup as onboarding.
3. **Treat Option B (alias/SMTP)** as a niche middle path for a rep who just wants mail from their own address with minimal build, accepting that replies still need Option A's inbound path.

**Bottom line for the client conversation:** we *can* do "connect your own Gmail" (Option C), and it is genuinely the most powerful — but it brings a **recurring CASA security-assessment cost of roughly $500–$4,500 per app per year** (Tier 2, annual, paid to a third-party lab) plus a weeks-long Google review, and that cost **multiplies per client** under our copy-per-client model. It only makes sense for a client who specifically needs it and will own the Google side. For everyone else, sending from a branded company domain with full open/click/reply tracking gets ~90% of the value with none of the Google burden, and it is the same free, one-time setup for every client we sell to.

---

## Sources (figures current as of June 2026)

- Google CASA — tiers, costs, annual reassessment, no self-scan for Tier 2: [deepstrike.io/blog/google-casa-security-assessment-2025](https://deepstrike.io/blog/google-casa-security-assessment-2025)
- Google official — restricted scope verification requirements (privacy policy, demo video, annual reassessment, Google charges no fee): [developers.google.com/identity/protocols/oauth2/production-readiness/restricted-scope-verification](https://developers.google.com/identity/protocols/oauth2/production-readiness/restricted-scope-verification)
- Google official — Security Assessment / CASA help: [support.google.com/cloud/answer/13465431](https://support.google.com/cloud/answer/13465431)
- CASA Tier 2 cost range and "no self-scan" detail: [community.latenode.com — Gmail API CASA Tier 2 for indie developers](https://community.latenode.com/t/is-casa-tier-2-assessment-necessary-for-all-gmail-api-apps-options-for-indie-developers/22861)
- Gmail sending limits (500 free / 2,000 Workspace) + Workspace $100/60-day unlock caveat: [developers.google.com/workspace/gmail/api/reference/quota](https://developers.google.com/workspace/gmail/api/reference/quota) and [Unipile — Gmail API Limits 2026](https://www.unipile.com/gmail-api-limits/)
