# How Levata's Client → Job → Document flow works

A plain-English walkthrough of what happens from the moment a lead becomes a
client, through proposals, jobs, and invoicing. For deployment/keys, see
`DEPLOY_CHECKLIST.md` instead — this file is about the *workflow*, not the
server setup.

## 1. A lead comes in

Normal Sales Intelligence pipeline: imported or added manually, researched
with AI, emailed and called through the pipeline stages. At this point
they're just a prospect — nothing client-related exists yet.

## 2. They become a client

On the lead's Profile tab, click **"Convert to Client."** This creates a
client record (`CLI-0001`, `CLI-0002`, …) pulling in their company, contact
name, email, phone, and website from the lead, then takes you straight into
their new **client workspace**. If a client with that name already exists,
it opens the existing one instead of creating a duplicate.

## 3. Everything for that client lives in one workspace

**Clients** page → click the client → tabs: **Overview**, **Jobs**,
**Documents**, **Tasks**, **Invoices**, **Files**. This is the hub — one
page shows the client's whole relationship with you: what's been proposed,
what's been built, what's outstanding.

## 4. How a CP or SOW actually gets linked to a client (the important part)

This used to work purely by matching a typed client *name* — fragile,
because a typo or an inconsistent spelling ("Buckingham" vs "Buckingham
Place") meant the link silently failed to form. **This has been fixed.**

Every place you'd type a client name — the Cost Proposal form, the SOW
form, the Job form, the Task form — now has a **client picker** instead of
a plain text box:

- Start typing and it searches your existing clients live.
- Click a match (or arrow keys + Enter) to select it. This sets a real,
  unambiguous ID behind the scenes — not just text that has to match later.
- If nothing matches, a **"+ Create client '...'"** option appears. Picking
  it creates the client on the spot and links it immediately — no dead end
  where you have to stop, go create the client elsewhere, and come back.

Because the link is now an actual ID and not a name match, the client's
**Documents**, **Jobs**, and **Tasks** tabs reliably show everything that
belongs to them, even if someone later renames the client or the typed text
varies slightly.

(Records saved before this change still rely on the old name-matching
logic, which still works as a fallback — nothing old broke. Going forward,
everything you save through these forms uses the picker.)

## 5. The proposal → job chain

- Generate a Cost Proposal for the client (via the picker, linked
  correctly from the start).
- Once they agree, click **Approve** on it (in Saved Documents or the
  client workspace). It gets an approved badge and timestamp.
- Approving immediately offers: *"Register a job from this approved
  proposal?"* — one click opens the job form pre-filled with the client,
  value, and the CP/SOW document numbers linked.
- Creating the job auto-generates its invoice schedule (advance + final
  for a one-off project, or one invoice per month for a retainer), and can
  spawn starter tasks (kickoff, invoicing, delivery) if you tick that box.

## 6. Retainer vs. one-off pricing

Cost Proposals and SOWs both support two pricing shapes now:

- **One-off**: a single total investment figure, with the usual
  50% advance / 50% balance structure.
- **Retainer**: a one-time setup fee (optional) plus a recurring monthly
  amount, a billing basis (e.g. "per licence, monthly"), a minimum term,
  and any third-party pass-through costs (like a subscription you're not
  marking up) — billed separately and clearly called out as such in the
  document.

Meeting-note extraction (pulling a ClickUp meeting doc into a form)
understands both shapes too — it detects whether the discussion was a
one-off project or a recurring arrangement, and never invents a number
that wasn't actually stated.

## 7. Opening documents from the client's side

Clicking a document from the client's **Documents** tab takes you to the
**Saved Documents** list (with that document highlighted), rather than
dropping you straight into the SOW/CP editor. Saved Documents is the
library view for browsing and managing everything; the editor is for
actively building or refining one document.

## 8. After you generate a CP or SOW

Hitting Generate/Save on the CP or SOW form saves the document (find it in
Saved Documents) and returns you to a **blank form**, ready for the next
one — it doesn't leave you sitting on the just-generated preview.

## 9. Money flow

Mark invoices paid from the client workspace, the Job Registry, or the
Calendar — it's all the same underlying data, so pipeline value, paid, and
outstanding totals stay consistent everywhere you look.

## What's deliberately not built

No client-facing portal or login. Clients never see or access this system
directly — it's a purely internal team tool, by design.
