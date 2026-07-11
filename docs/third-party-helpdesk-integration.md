# Third-Party Helpdesk Integration — How It Works & Restrictions

**Scope:** routing our system's feedback/support tickets into an existing third-party
helpdesk via its API. Covers **how the integration is wired** and the **restrictions**
(free-tier limits, rate limits, auth, data location) for each option.

> All figures below were verified June 2026. Free tiers change — confirm on the
> provider's pricing page before committing.

---

## How any of these integrate (the common pattern)

Our system already creates a ticket and can make an outbound HTTPS call (the same
mechanism used for our existing email and AI provider calls). Integration means adding
**one outbound API call** at the moment a ticket is saved:

```
User submits ticket in our app
   → saved locally (our tickets.json)
   → HTTPS POST to the helpdesk's "create ticket" API  (with our credentials)
   → ticket appears in the helpdesk; we log in there to manage and reply
```

So per provider, the work is: (1) get credentials, (2) add one POST call to our
`save-ticket` flow, (3) map our fields (subject, message, type, priority, requester
email) to the provider's fields. No database, no new infrastructure on our side.

The differences between providers are **authentication style**, **free-tier limits**,
**rate limits**, and **where the data lives** — covered below.

---

## Option 1 — Zoho Desk  (recommended for us)

**Why it fits us:** we already built **Zoho OAuth** for the CRM integration, so the
hardest part (the auth handshake + token refresh) is a known quantity in our codebase.

### How to integrate
1. Create a Zoho Desk account; create a Desk **department**.
2. Register an OAuth client in the Zoho API console (same as our CRM setup) with the
   Desk scope (`Desk.tickets.CREATE`).
3. Reuse our existing OAuth flow to get an **access token** (1-hour) + **refresh token**.
4. On `save-ticket`, POST to the create-ticket endpoint:
   `POST https://desk.zoho.com/api/v1/tickets`
   with `Authorization: Zoho-oauthtoken <access_token>`, body mapping our fields
   (`subject`, `description`, `email`, `priority`, department id).
5. Refresh the access token via `https://accounts.zoho.com/oauth/v2/token` when it expires.

### Restrictions
- **Free plan: max 3 agents.** No live chat, no social, no automation rules, no SLA management.
- **API access is "limited" on Free** — core ticket create/read works; advanced
  endpoints are gated to paid tiers.
- **Auth is OAuth 2.0**, access tokens expire after **1 hour**; you must store and
  rotate a **refresh token**. (More moving parts than an API key — but we've done it.)
- **No stated cap on number of tickets**, but API-created tickets have **per-edition
  daily limits**; the Free tier's exact number is not publicly documented (paid tiers
  run 3,500–25,000/day, so Free is below that).
- **Data lives on Zoho's servers**, not ours.

---

## Option 2 — Freshdesk

A polished, popular helpdesk with a simple **API-key** auth (easier than OAuth).

### How to integrate
1. Create a Freshdesk account (you get a `yourcompany.freshdesk.com` domain).
2. Copy your **API key** from agent settings.
3. On `save-ticket`, POST to:
   `POST https://yourcompany.freshdesk.com/api/v2/tickets`
   with HTTP Basic auth (`API_KEY:X`), JSON body (`subject`, `description`, `email`,
   `priority`, `status`).
4. Done — auth is just the API key, no token refresh.

### Restrictions
- **Free tier is now time-limited:** the current "Free Program" runs at no cost for
  **6 months** with **2 agent seats** — it is **not a permanent free plan** like it
  used to be. After that it converts to paid. (Confirm current terms — this changed recently.)
- **API rate limit on Free: 100 requests/minute**, enforced **per account** (not per
  key, not per IP). Fine for ticket volume, but a hard ceiling.
- **API-key auth** (simple), but the key has full account access — keep it server-side.
- **Data lives on Freshdesk's servers.**

---

## Option 3 — HubSpot Service Hub

Generous free CRM + ticketing, token-based API.

### How to integrate
1. Create a HubSpot account; enable the free Service Hub.
2. Create a **private app** to get an access token with the `tickets` scope.
3. On `save-ticket`, POST to the CRM objects API:
   `POST https://api.hubapi.com/crm/v3/objects/tickets`
   with `Authorization: Bearer <token>`, body mapping our fields to ticket properties.

### Restrictions
- **Free tier** includes ticketing, but advanced support features (automation,
  SLAs, routing) are paid.
- **Token-based** (private app token) — simpler than OAuth, no per-user refresh.
- **Rate limits** apply per account/app (burst + daily caps).
- **Data lives on HubSpot's servers**; tickets sit inside the broader HubSpot CRM,
  which can be more than we need for simple support.

---

## Option 4 — Self-hosted open source (osTicket / FreeScout)

Not a third-party *cloud* API — open-source helpdesks **we host ourselves**. Included
because it removes the "data on someone else's servers" and "free-tier limits" problems.

### How to integrate
1. Install the helpdesk on our hosting. **FreeScout is PHP** and runs on the same
   cPanel/Namecheap hosting as our app.
2. Enable its API (API key) or its inbound email.
3. On `save-ticket`, POST the ticket to the helpdesk's API, or email it in.

### Restrictions
- **Free software, but we provide the hosting** and maintenance (updates, backups).
- **No vendor agent/seat limits, no API rate caps** beyond our own server.
- **More setup and upkeep** than a hosted service.
- **Data stays on our servers.**

---

## Comparison of restrictions

| Provider | Auth | Free-tier limit | Rate limit | Data location | Reuses our code |
|---|---|---|---|---|---|
| **Zoho Desk** | OAuth 2.0 (1h token + refresh) | 3 agents; limited API | Per-edition daily cap (Free undocumented) | Zoho cloud | **Yes (Zoho OAuth)** |
| Freshdesk | API key (Basic) | **6 months only**, 2 agents | 100 req/min per account | Freshdesk cloud | No |
| HubSpot | Bearer token | Free ticketing; advanced paid | Per-account caps | HubSpot cloud | No |
| osTicket / FreeScout | API key | None (self-host) | Our server only | **Our servers** | Partial |

---

## Key restrictions to flag to management

1. **Agent seats.** Hosted free tiers cap how many of our team can log in (Zoho 3,
   Freshdesk 2). If more staff need access, it becomes paid.
2. **Freshdesk's free is temporary** (6 months) — not a long-term free option anymore.
3. **Rate limits** are per account, not per request key — high ticket bursts can be
   throttled (Freshdesk 100/min).
4. **Data residency.** With any hosted helpdesk (Zoho/Freshdesk/HubSpot), **ticket data
   lives on the vendor's servers**, not ours. Only self-hosting (FreeScout) keeps it in-house.
5. **Auth upkeep.** OAuth providers (Zoho, HubSpot) need token refresh handling;
   API-key providers (Freshdesk, FreeScout) are simpler but the key is a full-access secret.

---

## Recommendation

- **Easiest fit for us:** **Zoho Desk** — we already have the Zoho OAuth plumbing, and
  its free tier (3 agents) covers a small team. Accept that data lives on Zoho.
- **Simplest auth if starting fresh:** **Freshdesk** — but note the **6-month** free
  limit, so plan for it becoming paid.
- **If data must stay on our servers:** **FreeScout** (self-hosted on our cPanel) — free
  software, no seat/rate caps, at the cost of us hosting and maintaining it.
