# Feedback & Support — Bringing Client Tickets Into Our System

**Prepared for:** management review
**Subject:** how a client's feedback/support tickets (e.g. Macktiles) reach **our** central system so we can see and manage them.

---

## 1. The goal

Each client (Macktiles, and future clients) runs their **own copy** of our system. When a user inside a client's system submits a **feedback or support ticket**, we want that ticket to arrive in **our central Levata system**, so our team can:

- see every client's tickets in **one place**,
- track status (open / in progress / resolved),
- respond to the client.

**Where we are today:** the feature is built. A client's system already saves tickets locally and can send an email out. The remaining piece is **routing those tickets back to us** in a way we can manage.

---

## 2. The core challenge

Each deployment is independent — a Macktiles ticket is saved in Macktiles' own system. To "see it in our system," the ticket has to **travel** from their install to ours. There are three realistic ways to do that.

---

## 3. The three approaches

### Approach A — System-to-system (we build it)

The client's system sends each ticket **directly to our system** over a secure connection (a "webhook"), protected by a shared secret. Our system stores it as a real record, tagged with the client's name.

```
Client system  ──(ticket + secret)──►  Our system  ──►  Our Help & Support list (tagged "Macktiles")
```

- **Cost:** free. Both sides are our own code.
- **Data:** stays entirely on our servers (private).
- **Effort:** small — reuses mechanisms already in the system.
- **Reply to client:** by email for now; replying *into* their app is a later upgrade.
- **Best for:** keeping everything in-house and under our control.

### Approach B — Plug into a free third-party helpdesk

The client's system sends each ticket to a **free hosted helpdesk service** (e.g. **Freshdesk** free plan, or **Zoho Desk** free tier) via that service's API. We log into that service to manage and reply to tickets.

```
Client system  ──(ticket via API)──►  Freshdesk / Zoho Desk  ──►  we manage + reply there
```

- **Cost:** free tier (with limits on agents / monthly volume).
- **Data:** lives on the third party's servers, not ours.
- **Effort:** small — one API call.
- **Reply to client:** built in (the service emails the user back automatically).
- **Note:** we already built **Zoho** authentication for the CRM, so **Zoho Desk** reuses that pattern.
- **Best for:** getting a full, polished helpdesk (statuses, assignment, email replies) without building one.

### Approach C — Central shared backend (long-term)

Both our system and every client system talk to **one central service + database that we host**. Everyone reads and writes the same shared store.

- **Cost:** higher — new hosted service and a real database.
- **Data:** ours, centralised.
- **Effort:** large (months); requires moving off our current file-based storage.
- **Reply to client:** full two-way — we reply and the client's user sees it **in their own app**.
- **Best for:** the eventual product-grade, multi-client support desk.

---

## 4. Side-by-side comparison

| Factor | A: System-to-system | B: Free helpdesk API | C: Central backend |
|---|---|---|---|
| Cost | Free | Free tier (limits) | Higher (infra) |
| Data stays on our servers | Yes | No (third party) | Yes |
| Shows as a record we manage | Yes (in our app) | Yes (in their tool) | Yes (in our app) |
| Filter / view by client | Yes | Yes | Yes |
| Reply back to the client's user | Email only | Email (built in) | In-app (two-way) |
| Build effort | Small | Small | Large |
| Time to working | Days | Days | Months |
| Scales to many clients | Good | Good (within free limits) | Best |
| Reuses what we already built | Yes (our send code) | Yes (Zoho auth, if Zoho Desk) | No |

---

## 5. Recommendation

**Start with Approach A (system-to-system).** It delivers exactly what we need — every client's tickets arriving as real, manageable records inside our own Help & Support page — with a small build, no new cost, no database, and full control of our data. We reply by email for now.

**Consider Approach B (Zoho Desk free tier)** if management prefers a ready-made, polished helpdesk with built-in email replies and we are comfortable with ticket data living on Zoho's servers. The advantage: we already built Zoho authentication, so the integration is familiar.

**Move to Approach C (central backend) later**, once we have several clients and want a true multi-client support desk with two-way, in-app replies. This is also when we would upgrade to a proper database.

---

## 6. How the integration works (Approach A — recommended)

The integration is set up **once per client**. Steps:

**On our (Levata) system — the receiver:**

1. Add a secure intake point that accepts incoming tickets.
2. It checks a **shared secret** so only our clients can send to it (not the public).
3. It saves each incoming ticket into our Help & Support list, **tagged with the client's name** (e.g. "Macktiles").
4. Our Help & Support page gains a **client filter** so we can view one client at a time or all together.

**On the client's system — the sender:**

1. In the client's admin settings, we enter **our intake address** and the **shared secret** (one-time setup).
2. From then on, whenever their user submits a ticket, their system saves it locally **and** sends a copy to us automatically.
3. If our system is ever briefly unreachable, the client's ticket is still saved on their side — it is never lost.

**Onboarding a new client** is then just: give them our intake address + a unique secret, enter it in their settings. No new development per client.

---

## 7. What this does and does not do (current scope)

**Does:**
- Bring every client's feedback and support tickets into our central system.
- Tag and filter them by client.
- Let us track status and respond by email.

**Does not (yet):**
- Let our reply appear **inside the client's app** (their user would see our reply by email). True two-way, in-app replies are the later upgrade (Approach C).

---

## 8. Summary

We already have the feedback & support feature built. To centralise client tickets into our system, the recommended next step is a **small, free, system-to-system integration** that routes each client's tickets to us as real, manageable records — set up once per client, with all data staying on our servers. A ready-made helpdesk (Zoho Desk) is a viable alternative if a polished, hosted tool is preferred. A full central backend is the long-term direction once client numbers grow.
