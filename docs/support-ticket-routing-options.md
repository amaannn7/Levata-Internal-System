# Feedback & Support — Getting Client Tickets Into Our System

**Goal:** when a user inside a client's deployment (e.g. **Macktiles**) submits a feedback or support ticket, we (Levata) want that ticket to arrive **in our own central system**, so we can see and manage every client's tickets in one place.

**Where we are today:** each client runs their own copy of the system. A ticket they raise is saved in *their* install only. The system can already email a ticket out (via Resend). The missing half is **bringing that ticket back into our central system** as a real, manageable record.

---

## The core problem in one line

Each deployment is an island. A Macktiles ticket saves to Macktiles' own storage. To "see it in our system," the ticket has to **travel** from their install to ours. The options below are simply different ways for it to travel.

---

## Option 1 — Email to our inbox

Set the client's **Support Email** to our address. Their tickets simply email straight to us.

| | |
|---|---|
| **Effort** | None. A settings change, works today. |
| **Lives in our app?** | No — it lands in Gmail, not inside the system. |
| **Filter / status / record** | No. Just an email in an inbox. |
| **Reply to client** | By email. |
| **Best for** | A quick stopgap while we decide. |

**Trade-off:** fastest possible, but it is not really "in the system" — no central list, no statuses, no per-client view.

---

## Option 2 — Ingest webhook  (recommended)

After a client's system saves a ticket locally, it **sends that ticket to our system automatically** (a secure HTTPS call with a shared secret). Our system stores it as a real record, tagged with the client's name.

```
Client install  ──(ticket + secret)──►  Our system  ──►  Help & Support list (tagged "Macktiles")
```

| | |
|---|---|
| **Effort** | Small. Reuses the email-sending mechanism we already built. |
| **Lives in our app?** | Yes — a real ticket in our Help & Support page. |
| **Filter / status / record** | Yes. See all clients' tickets in one list, filter by client. |
| **Needs a database?** | No. Uses the existing file storage. |
| **Per-client setup** | Trivial — each client gets our address + a secret in their settings. |
| **Reply to client** | By email for now (replying *into* their app is a later upgrade). |

**Trade-off:** one-way. We *see* every ticket centrally, but answering back into the client's own app is a separate, later step. For "I want to see Macktiles' tickets in our system," this is the sweet spot.

---

## Option 3 — Shared central backend

Both our system and every client system talk to **one central tickets service and database that we host**. Everyone reads and writes the same shared store.

| | |
|---|---|
| **Effort** | Large. New hosted service + a real database (move off file storage). |
| **Lives in our app?** | Yes, natively. |
| **Filter / status / record** | Yes, fully. |
| **Needs a database?** | Yes. |
| **Per-client setup** | Each client points at the central service. |
| **Reply to client** | Yes — we can reply and the client's user sees it **in their app**. |

**Trade-off:** the most powerful and the proper long-term product, including **two-way replies**. But it requires new infrastructure and is months of work, not days.

---

## Side by side

| Factor | 1: Email | 2: Ingest webhook | 3: Central backend |
|---|---|---|---|
| Build effort | None | Small | Large |
| Shows as a record in our app | No | **Yes** | Yes |
| Filter by client | No | **Yes** | Yes |
| Needs a database | No | No | Yes |
| Reply back into client's app | No (email) | No (email) | **Yes** |
| Scales to many clients | Weak | Good | Best |
| Time to working | Today | Days | Months |

---

## Recommendation

**Start with Option 2 (ingest webhook).** It delivers exactly what we want — every client's tickets arriving as real, filterable records inside our own Help & Support page — with a small build, no database, and trivial per-client setup. We reply by email for now.

**Move to Option 3 (central backend) later**, when we have enough clients and want a true multi-tenant support desk with **two-way replies** (answering a client's user directly inside their app). That is also the point at which we would move storage to a proper database.

**Option 1 (email)** is the "use it today while we decide" stopgap.

---

## The one limitation to keep in mind (Options 2 and 3)

Seeing tickets is the easy direction. **Replying back down into the client's app** — so their user sees our answer in-app — is the genuinely hard half. With the webhook (Option 2), we reply by email in v1; in-app reply-down is the later upgrade that Option 3 unlocks.
