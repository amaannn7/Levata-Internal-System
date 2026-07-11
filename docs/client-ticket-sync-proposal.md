# Feedback & Support — Syncing Client Tickets With Our System

**Subject:** how a client's feedback/support tickets (e.g. Macktiles) can appear in **our**
system, and how we can **reply back into the client's system**.

**Requirement:**
1. When a user in a client's deployment submits a ticket, we want to **see it in our own system**.
2. When we respond, our reply should appear in the **client user's own app** (not just by email).

---

## The core challenge

Each client runs their **own copy** of the system. A ticket they raise is saved only in
**their** install. For us to see it, the ticket must **travel** from their system to ours;
for the user to see our answer, our reply must **travel back**. There are two realistic ways
to achieve this.

---

## Approach 1 — Third-party helpdesk (Zoho Desk / Freshdesk)

Tickets are pushed into an external helpdesk product. We manage them inside that product's
dashboard; replies are sent to the user by email.

```
Client system → helpdesk cloud (Zoho/Freshdesk) → we work in the helpdesk → email reply to user
```

**How it works**
1. The client's system sends each ticket to the helpdesk's API.
2. The ticket is stored on the helpdesk's servers.
3. We log into the helpdesk's website to view and manage all clients' tickets.
4. We reply inside the helpdesk, which **emails** the reply to the user.

**What it does NOT do**
- Tickets appear in the **helpdesk's dashboard**, not in our own system.
- Our reply reaches the user by **email only** — it does **not** appear in the client's app.
- Ticket data lives on the **vendor's servers**, not ours.

**Restrictions**
- Free tiers are limited: Zoho Desk free = 3 agents; Freshdesk free is a 6-month program
  with 2 agents (then paid). API rate limits apply.

**Summary:** easy (little to build), but it moves ticket management out of our system into a
third-party tool, and replies are email-only. It does **not** meet requirement 1 or 2 as stated.

---

## Approach 2 — System-to-system sync  (meets both requirements)

The client's system and our system talk **directly to each other**. No third party. Tickets
arrive in our app; our replies are sent back into the client's app.

```
Client system → OUR system (tickets appear in our Help & Support)
OUR system → client system (our reply appears in the user's app)
```

**How it works**
1. **Ticket comes to us:** when a client user submits a ticket, the client's system saves it
   locally and **sends a secure copy to our system**. Our system stores it in our Help &
   Support list, tagged with the client's name (e.g. "Macktiles").
2. **We reply:** we open the ticket in our system and respond. Our system **sends the reply
   back to the client's system**, where it is added to the user's ticket thread.
3. **The user sees it in their app** (and also receives the existing email notification).

**Security**
- Both connections are protected by a **shared secret** so only our authorised client systems
  can exchange tickets — not the public.

**What stays the same**
- The user's experience is unchanged — they use the same in-app form.
- Tickets are still saved on the client's side too, so nothing is lost if a connection is
  briefly unavailable.
- **All data stays on our and the client's own servers** — no third party.

**Restrictions**
- This is the larger build: two secure intake points (one on each system), a link between each
  client ticket and our copy of it, and a per-client setup (the client's address + secret).
- Both systems must be reachable online for live exchange; otherwise messages are retried.
- It is **one helpdesk we build**, not an off-the-shelf product — but it is free and fully ours.

**Summary:** meets both requirements — tickets in **our** system, replies back into the
**client's** app, data on our own servers. Requires development on both sides.

---

## Side-by-side comparison

| Factor | 1: Third-party helpdesk | 2: System-to-system |
|---|---|---|
| See tickets in **our** system | No (their dashboard) | **Yes** |
| Reply appears in **client's app** | No (email only) | **Yes** |
| Data stays on our servers | No (vendor cloud) | **Yes** |
| Cost | Free tier, with limits | Free |
| We build it | Mostly no | Yes (both sides) |
| Per-client setup | Account + API key | Address + shared secret |
| Time to working | Days | Weeks |

---

## Recommendation

Our stated requirements are: **(1) see client tickets in our own system, and (2) reply back
into the client's app.** Only **Approach 2 (system-to-system)** delivers both. The third-party
helpdesks, while quicker to set up, keep tickets in their own dashboard and can only reply to
the user by email — so they do not meet the requirement.

**Recommended path:** build the system-to-system sync. It keeps everything in-house, on our own
servers, at no licensing cost, and gives us a single Help & Support view across all clients with
the ability to respond directly into each client's system. Onboarding each new client is then a
simple one-time configuration (their address + a secret), with no new development per client.

---

## What it does not do (current scope)

- It synchronises **tickets and replies** between our system and each client's system.
- It is **not** a third-party product and does not depend on any external service.
- Live exchange requires both systems to be online; otherwise messages are retried until
  delivered.
