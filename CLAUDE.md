# CLAUDE.md - Levata Sales Intelligence

## Project Overview

The **Levata Internal System** is a monolithic PHP + vanilla JavaScript app with JSON file-based storage, organised into these top-level areas in the sidebar nav:

1. **Sales Intelligence** (the original platform) — leads, AI research, email/call generation, pipeline. Still the main day-to-day tool.
   **The deal pipeline (REBUILT July 2026 — 7 stages, replaced the old 9):** `lead` → `qualified` → `demo` → `cost_proposal` → `sow` → `won`, with `lost` as an exit from anywhere. Defined once in `getMacktilesStages()` (api.php, each stage carrying a `label`/`legacy`/`order`) and mirrored in `MACKTILES_STAGES` + `STAGE_ORDER` in index.html — change both together. The retired stages (`new_lead`/`research`/`email_sent`/`call_attempted`/`engaged`/`consultation_booked`/`nurture_parked`) still map forward via `legacyStatusToStage()` / `LEGACY_STAGE_MAP`, so old records keep working; research, email and calls are now **activity within `lead`**, not stages, so those actions no longer auto-advance the stage (they still log activity, and the "next action" hints key off `enrichment`/`emails_sent` instead).
   - **Every stage carries a deal amount.** `deal_amount` + `deal_currency` on the lead, changed via `set-deal-amount` or the `deal_amount` field on `update-lead`; every change is appended to `deal_amount_history` with the stage it happened in. `setDealAmount()` is the only writer. Stats/funnel return per-stage `stage_value` plus `open_value`/`won_value`.
   - **`peak_stage`** records the furthest stage a deal ever reached, which is what the Admin Dashboard funnel counts (true drop-off per step), separate from where deals sit *now* (`current`).
   - **Currency is per record, never converted.** `supportedCurrencies()` in
     api.php (LKR, USD, EUR, GBP, AUD, AED, INR, SGD, CAD) is mirrored by
     `CURRENCIES` in index.html — change both together. Each deal carries
     `deal_currency`, each job `currency`, and invoices inherit their job's; a
     job created by `win-deal` inherits the deal's. `normalizeCurrency()`
     validates every write and falls back to `defaultCurrency()` (the
     `default_currency` key in `admin_config`, set in **Admin → Settings →
     Finance**), so an unknown code can never be stored. The frontend mirrors it
     in `DEFAULT_CURRENCY`, seeded from the `me` endpoint at login.
     **Money in different currencies is NEVER added together or converted** — a
     stale exchange rate would silently corrupt finance figures. Totals are
     grouped by code via `sumByCurrency()` / `addToCurrencyBucket()` and returned
     as `{CODE: amount}` maps (`*_by_currency` on the jobs and client rollups;
     the `studio-overview` headline money fields are maps outright). The frontend
     renders them with `moneyByCurrency()` (stacks each currency, largest first)
     and `jrSetMoney()`. The flat `pipeline_value` / `total_paid` /
     `total_outstanding` totals are kept only for older callers — do not display
     them where records may differ in currency.
   - **Won → Job Registry (the handover seam).** Setting a deal to Won does NOT go through `update-lead` — that returns `409 needs_win_confirm`. The frontend opens a confirm dialog (`#win-deal-modal`, `openWinDealModal()`) fed by `deal-win-preview`, then calls **`win-deal`**, which: ensures a client record exists (creating one from the lead if needed), creates `JOB-xxxx` with its invoice schedule (advance/final or monthly), links any CP/SOW, and stamps `client_id`/`job_id`/`job_no` back onto the lead. Winning twice is refused.
   - **The lead/deal modal header** (`renderLeadModal()` in index.html) is a
     purpose-built `.deal-head` panel, not a row of form controls: the deal value
     is the headline figure (a borderless `.deal-amount-input` styled as 30px
     text that only looks like a field on hover/focus, with the currency select
     beside it), primary actions (Mark Won / Link Document / Lost) sit top-right,
     and the stage is a **`.stage-rail`** of clickable steps showing the whole
     pipeline at once with earlier stages ticked — built by `stageRailHtml()`,
     driven by `setDealStage()`. `Lost` only renders in the rail when the deal is
     actually lost. Linked CP/SOW/job/client render as `.deal-link-chip` pills.
     The old `#lead-status` `<select>`, `renderLeadWorkflow()`,
     `getLeadPrimaryAction()` and the `.lead-workflow*` CSS were **deleted** —
     `updateLeadStatus()` survives only as a thin alias to `setDealStage()`.
     The amount field displays a grouped number ("1,850,000"); `dealMoney()`
     server-side strips separators, and the field re-formats from the saved value.
   - **Documents → pipeline.** `link-deal-document` attaches a saved CP or SOW to a deal (`cost_proposal_id` / `sow_id`) and advances the deal to the matching stage if it is behind. Surfaced as "Link Document" on the lead modal's Profile tab; the linked CP/SOW/job/client appear as buttons at the top of the lead modal.
   - **Channel Partners (ADDED July 2026).** A deal either came to us directly, or
     was referred by a channel partner in exchange for a commission. New module
     `partners.php` (shared `partners` store_blobs row, same pattern as
     `clients.php` — no reporting-table projection, since the list is short).
     Nav → **Channel Partners** page (`#page-partners`, `loadPartnersPage()`) is a
     simple directory: name, contact, default commission (`default_rate_type`
     percentage/fixed + `default_rate_value`), reused via `save-partner` /
     `delete-partner`. A deal carries `partner_id` + its own
     `partner_rate_type`/`partner_rate_value` — a **snapshot** taken from the
     partner's default at the moment they're attached (`set-deal-partner`), so a
     later change to the partner's default rate does NOT retroactively change
     what's owed on deals already in flight; each deal can also override the rate
     at attach time. **The payout is never stored** — `partnerPayout($lead)`
     (api.php) / `calcPartnerPayout()` (index.html, kept numerically identical)
     always compute it live from the deal's *current* `deal_amount`, so editing
     the deal value keeps the payout correct with nothing to fall out of sync.
     Deleting a partner does not touch deals that reference them — they keep
     their name/rate snapshot and render as "(deleted partner)" via
     `partnerRefFor()` / the frontend's `partnerBadge()`.
     Surfaced on the deal modal (`.deal-partner-block` next to the deal-value
     block — same header level, so "what it's worth" and "who sent it" read as
     peers) and as a gold `.partner-badge` pill on the Pipeline table row
     (`partnerBadge()`), empty for direct deals. **The Pipeline filter** —
     `#source-type-filter` (All Deals / Direct / Partner) — is a `source_type`
     query param honoured by both `leads` and `stats` (so the stage strip and
     the table stay in sync); Direct = `partner_id` empty, Partner = set. The
     `leads` list response also carries the full `partners` array so the
     frontend never needs a second round trip to render names/badges.
   - **Removed:** the admin-only "Team Activity" nav item and its dashboard page-title variant (the per-rep card on the Admin Dashboard and Session Activity remain).

### What this system actually is (decided July 2026 — read before adding features)

Levata is a **small internal team where everyone is an admin**. This is not a
sales/CRM product and not a prospecting tool: it is where the team **runs work it
has already won**. Deals arrive warm (referral, inbound, existing relationship),
someone talks to them, and the real system is what happens after: cost proposal →
SOW → job → invoices → tasks. Design every new feature for that, not for outbound.

- **A CLIENT exists only once a deal is WON.** Before that it is a *deal* (still
  stored as a lead record, surfaced as "Deals" in the nav). `win-deal` is the ONLY
  path that creates a client, and it creates the client and the job together. The
  old "Convert to Client" button was **removed** precisely because it let you make
  clients you never won — do not reintroduce a mid-pipeline client-creation path.
- **Outbound is hidden, not deleted.** `OUTBOUND_ENABLED = false` (a single const
  near the top of the main script block in index.html) hides the AI research /
  cold email / call script tabs, the daily call+email+research target cards, and
  the Response Rate metric. The CSS class `.outbound-only` hides any element while
  the flag is off (`body.outbound-on` gates it). **All the backend endpoints
  (`enrich-lead`, `generate-email`, `generate-call-pitch`, `send-email`, import,
  Zoho export) still exist and still work** — flipping the flag to `true` brings
  the whole UI back. Do not delete those endpoints without asking.
- **No rep/manager split.** Everyone is an admin and sees every nav item;
  `applyRoleNavigation()` no longer hides anything by role. The duplicate
  rep-nav/manager-nav pairs and the two dashboard variants were collapsed into
  one. Only genuinely dangerous surfaces (Users, API keys) stay super-admin
  gated. `defaultLandingPage()` is always `dashboard`.
- **Internal-only, no client portal** — clients never log in (see the Clients
  section above). Everything here is for the Levata team.
- **Deleted in the July 2026 UI audit** (dead since outbound was hidden — all
  were unreachable, no nav item and no `showPage()` caller): the **Lead
  Workbench**, **Today's Commitments**, **Email Creator** and **Cold Call Script**
  pages, plus their JS (`loadWorkbench`, `loadCommitments`, the `batch*`
  helpers, `populateLeadSelects`, `generateCreatorEmail`, `generateCallPitch`,
  the daily-targets/commitments CRUD) and CSS (`.workbench-*`, `.daily-target*`,
  `.commitment-*`, `.cc-streak`). The proactive-dashboard chain
  (`loadProactiveDashboard`, `renderAIBriefing`, `renderFocusQueue`,
  `renderAttentionNeeded`, `skipFocusItem`, `startResearch`) went with the Today
  page — it was **throwing on every call** because its elements no longer
  existed. Their **backend endpoints survive** per the hidden-not-deleted policy
  and now have no caller: `dashboard-briefing`, `lead-batches`, `daily-progress`,
  `daily-targets`.
- **Stat cards are one system.** `.stat-card`, `.jr-stat` and `.cc-metric-card`
  had three different value sizes (26/28/21px); they are now uniform — label
  above value, 21px value, `.cur-extra` for stacked currencies. Anything holding
  money must use this, because per-currency totals render on multiple lines.
- Password reset was **restored, not deleted**: `requestPasswordReset()` existed
  with no UI (its markup had been lost), so a "Forgot password?" link and the
  `#reset-password-form` block were added back to the login screen.
- **The lead list is called "Pipeline"** — nav item, page title and
  `updatePageLanguage()`. It is NOT called "Deals"; that rename was made once and
  reverted on request. Do not rename it again.
- **The "Today" page was deleted** (`#page-dashboard` and its nav item). It was
  the outbound daily-rhythm view: call/email/research targets, focus queue,
  temperature groups, recent-leads table. **Studio Overview is now the landing
  page** (`defaultLandingPage()` returns `command-center`). Deleted with it:
  `renderDashboardLeads()`, `loadTodayProgress()`, `loadTodayQueues()`, and the
  Today-painting tail of `loadStats()` (which now only calls
  `renderPipelineStageStrip()`). `loadTodayOperations()` and
  `updateDashboardHeader()` survive as **no-ops** because they are still called
  from several refresh paths.
- **The dashboard is "Studio Overview"** (still `#page-command-center` /
  `showPage('command-center')` internally, and still loaded by
  `loadCommandCenter()`; nav label is just "Dashboard"). It replaced the outbound
  Command Center and is driven by the **`studio-overview`** action, which
  aggregates the shared jobs / invoices / tasks / documents stores plus the
  current user's open deals into one payload: headline tiles (outstanding,
  overdue, paid this month, active jobs, open deal value, open tasks) and six
  panels (money owed, jobs in delivery, tasks due this week, documents awaiting
  approval, deals closest to signature, pipeline funnel). Only CPs and SOWs count
  as "awaiting approval" — NDAs are filed, not decided on, and would otherwise
  flood that panel. `renderProgressItem()` and `renderManagementMetrics()` were
  **deleted** with the old dashboard (that removed the last per-rep Team Activity
  table and the compact User Sessions card; the full view still lives under Users).
  - **Layout rules learned the hard way:** these panels are half-width, and the
    shared `.table` class has `min-width:600px`, which forces a horizontal
    scrollbar inside them — so the panels use their own `.so-row` compact row
    layout instead, never `.table`. Metric tiles hold currency, not two-digit
    counts, so `.cc-metric-value` is 21px and left-aligned with the label above
    it (a big centred number wrapped and looked broken), and tiles use
    `dealMoneyShort()` (`LKR 2.4M`) with the exact figure in the `title`
    attribute. `.cc-metrics-row` is `auto-fit`, not a fixed column count.
2. **Documents** — **Cost Proposals** (full AI generator, see `cp.php`) and **Statements of Work** (Document Studio / SOW generator, see `sow.php`), plus a shared **Saved Documents** library (grouped by client). Each doc can be exported to PDF or DOCX, or a hand-edited revised file (e.g. a Figma-designed PDF) can be re-uploaded to supersede the generated draft.
3. **Clients** (BUILT — Flozy-style hub, Phase 1) — a shared, company-wide client registry that everything else hangs off. Lives in `clients.php` (module, `require_once`d by api.php after tasks.php) + shared store (the `clients` row in `store_blobs`, shape `['clients'=>[...], 'seq'=>n]`, client# `CLI-0001`) + a `clients` reporting projection table. Jobs, tasks and documents each carry a `client_id`, stamped at save time by `resolveClientId($name)` (case-insensitive name match against the registry — legacy records with only a typed name still aggregate via the name fallback in `clientOwnsRecord()`). Actions: `clients` (GET list + rollups + summary; auto-backfills the registry from existing job/doc/task client names when the store is empty), `client-workspace` (GET one client + their jobs/invoices/documents/tasks), `save-client` (create/update; 409 on duplicate name unless `if_exists:'use'`; renames propagate to all linked records via `propagateClientRename()`), `delete-client` (registry record only — linked records keep their name), `backfill-clients` (manual "Sync from existing data"). Frontend: nav `data-page="clients"`, `#page-clients` (list view `#cl-list-view` + per-client workspace `#cl-detail-view` with Overview/Jobs/Documents/Tasks/Invoices tabs), `#client-modal`, `window.loadClients`, and a shared `#client-datalist` feeding the job/task client inputs. Leads have a "Convert to Client" button (`convertLeadToClient()`, lead modal Profile tab).
   **Phase 2 (BUILT — the connected workflow chain):** (a) *Document approval* — docs carry `status` (`draft`/`approved`, plus `approved_at`/`approved_by`), set via the `set-document-status` action; Saved Documents shows an Approve button on CPs (`docToggleApproval()` in index.html) and approving offers to register the job via the existing `docCreateJob()` prefill (which now also auto-links the SOW whose `linked_cost_proposal` matches the CP). (b) *Tasks ↔ jobs* — tasks carry optional `job_id`/`job_no` (resolved server-side via `jobRefById()` in jobs.php); the task modal has a Job dropdown (`#task-edit-job`, `_populateTaskJobSelect()`) that auto-fills the client; `save-job` accepts `starter_tasks: true` on create to spawn a linked starter task set (4 tasks for one-off, 2 for retainer; checkbox `#job-starter-tasks`, create-only). (c) *Client files* — arbitrary shared files on a client record (`files` array on the client, stored in `data/uploads/`), actions `upload-client-file` / `download-client-file` (`?token=` query fallback used for the download link) / `delete-client-file`, surfaced as the Files tab in the client workspace (`clTabFiles()`, hidden input `#client-file-input`); files are unlinked from disk on client delete. **Internal-only by decision:** there will be NO client-facing portal/login — clients never access this system; it is purely an internal team tool (decided July 2026). Possible future additions (not built, internal-facing only): Stripe payment links on invoices, per-client team notes/activity feed.

4. **Job Registry** (BUILT) — a shared, company-wide database tracking each client *job* through its lifecycle: approved cost proposal (CP-xxxx) → linked SOW (SOW-xxxx) → invoices (advance + final, or one per month for retainers), with open/in-progress/awaiting-payment/completed/cancelled status and finance roll-ups (pipeline value, paid, outstanding). A client can have many jobs. Lives in `jobs.php` (module, `require_once`d by api.php) + shared store (the `jobs` row in the `store_blobs` Postgres table — NOT per-user, it's a team-wide finance view; see "PostgreSQL storage" below). Actions: `jobs` (GET list+summary), `save-job` (create/update; create auto-generates the invoice schedule), `delete-job`, `save-invoice` (add/update; toggle paid), `delete-invoice`. Job# `JOB-0001`, invoice# `INV-0001` (sequential, stored in the blob's `seq`). Frontend: `#page-job-registry`, `window.loadJobRegistry`, `#job-modal`/`#invoice-modal`, jobs grouped by client. Jobs are created manually for now; when the Cost Proposal system exists, wire approval → auto-create job.

Documents (Cost Proposals + SOWs) persist in a **shared, company-wide store** (the `documents` row in `store_blobs`, shape `['documents' => [...]]`), accessed via `getDocsStore()` / `saveDocsStore()` / `getAllDocuments()` in `sow.php` — the whole team sees every document, the same way the jobs store is shared. Each doc carries a human-readable, per-type sequential `doc_no` (`SOW-0001`, `CP-0001`, `INV-0001`) generated by `nextDocumentNumber()` — global across the company so numbers never collide between users — plus an optional `linked_cost_proposal` field so a SOW can reference the approved CP it was built from, and `owner`/`owner_id`. The doc endpoints (`list-documents`, `all-documents` (alias), `get-document`, `save-document`, `delete-document`, `upload-document-file`, `download-document-file`) all read/write the shared store. Nav pages: `cost-proposals`, `sow`, `job-registry`; JS hooks `window.loadJobRegistry` (stub) and `window.sowOnEnter`.

This is **Levata's own internal system**. It was forked from a client build ("Macktiles Sales Intelligence", an Australian tile company) and **rebranded + generalised to Levata** (a design and engineering studio building websites, software systems, and brand identities). All user-facing branding and the AI logic (research/email/call prompts, ICP/scoring, requisitions) are now Levata/generic, driven by settings rather than tile-specific hardcoding. NOTE: some **internal code identifiers still contain "macktiles"** (e.g. `mapZohoToMacktiles`, `getMacktilesStages`, `macktilesCallOutcomeConfig`) — these are not user-facing and were left as-is to avoid breaking call sites; do not assume the app is still tile-branded.

## Tech Stack

- **Frontend**: Vanilla JavaScript SPA, CSS3 with design tokens
- **Backend**: PHP 7.4+ REST API
- **Storage**: PostgreSQL (see "PostgreSQL storage" section below — this replaced the original JSON file storage)
- **LLM Providers**: Groq (llama-3.3-70b), Google Gemini, Anthropic Claude

## Project Structure

```
/
├── index.html              # Single-page app (HTML + CSS + JS)
├── api.php                 # Backend API (all endpoints; require_once's the modules below)
├── sow.php                 # SOW generation + shared doc store + ClickUp + doc numbering
├── cp.php                  # Cost Proposal generation
├── jobs.php                # Job Registry (shared company-wide store)
├── clients.php             # Clients registry (shared hub linking jobs/docs/tasks)
├── partners.php            # Channel partner directory + live commission payout
├── levatalogo.png          # Horizontal logo (icon + wordmark) — sidebar, mobile, PDFs
├── levata-logo-jpeg.jpg    # High-res square logo — login screen
├── .htaccess               # Security rules (blocks web access to data/)
├── README.md
├── CLAUDE.md
├── db.php                  # PostgreSQL connection + schema bootstrap (gitignored — real credentials)
├── db.example.php          # Committed template for db.php
└── data/                   # Only uploaded document files now; NOT the data store (all gitignored)
    └── uploads/            # Uploaded revised document files (PDF/DOCX)
```

## PostgreSQL storage (replaced JSON files)

Storage was migrated from JSON files in `data/` to PostgreSQL — see `DEPLOY_POSTGRES.md`
for the full setup/deployment walkthrough. `api.php` does an unconditional
`require_once __DIR__ . '/db.php'`; there is no JSON-file fallback mode anymore,
locally or in production.

**Design**: hybrid columns + JSONB, not full normalization.
- `users` — a real table (id, name, email, password, is_admin, is_super_admin,
  timestamps), plus a `data` JSONB column for everything else (device tokens,
  password-reset fields). `getUsers()`/`saveUsers()` still return/accept the
  same plain-array-of-users shape the rest of the app always used — nothing
  that touches `$user[...]` fields had to change.
- `store_blobs` — one JSONB row per shared store, keyed by name
  (`admin_config`, `clients`, `documents`, `jobs`, `tasks`, `tickets`,
  `user_data:<user_id>`). This is the **source of truth**, a drop-in
  replacement for what used to be one JSON file per store — including each
  store's embedded `seq` counter (e.g. `jobs` blob's `seq.job`/`seq.invoice`),
  so `nextJobNo()`/`nextTaskId()`/`nextTicketNo()` needed zero changes.
- `clients` / `documents` / `jobs` / `tasks` / `tickets` tables — reporting-only
  projections (real columns: status, client, type, dates, …) refreshed from
  the blob on every save via `dbSyncReportingTable()`. The app never reads
  from these; they exist for future SQL filtering/reporting without touching
  application logic.
- `activity_pings` — a genuine relational table (never was file-based), backs
  the Users → Session Activity view (`activity-ping` / `user-sessions` actions).

**First run** auto-bootstraps: `dbBootstrap()` creates every table via
`CREATE TABLE IF NOT EXISTS` on first connection (no manual SQL), and if
`users` is empty, seeds `admin@levatahq.com` / `password`.

**Local dev and production use the identical code path** — both are Postgres,
just pointed at different databases via `db.php`'s constants (or `DB_HOST`/
`DB_NAME`/`DB_USER`/`DB_PASS` env vars). There is no local JSON mode.

The get/save function pairs (`getUserData`/`saveUserData`, `getAdmin`/
`saveAdmin`, `getDocsStore`/`saveDocsStore`, `getJobsStore`/`saveJobsStore`,
`getTasksStore`/`saveTasksStore`, `getTicketsStore`/`saveTicketsStore`) kept
their exact original signatures and return shapes — only their internals
changed from file I/O to `dbGetBlob()`/`dbSaveBlob()`. This means the ~6600
lines of endpoint logic in `api.php` that call these functions needed no
changes at all.

## Key Commands

```bash
# Start local PHP server (requires db.php configured — see DEPLOY_POSTGRES.md)
php -S localhost:8000
```

## Architecture Patterns

### Backend (api.php)

**Routing**: Switch-based on `$_GET['action']`
```php
switch ($_GET['action'] ?? '') {
    case 'login': ...
    case 'leads': ...
}
```

**Auth**: Token-based via `X-User-Token` header
```php
requireAuth();      // Validates user token
requireAdmin();     // Validates admin access
```

**Data I/O** (Postgres-backed, see "PostgreSQL storage" above):
```php
$users = getUsers();           // Read `users` table
saveUsers($users);             // Write `users` table
$data = getUserData($userId);  // Read `user_data:<id>` blob (store_blobs)
saveUserData($userId, $data);  // Write `user_data:<id>` blob (store_blobs)
```

**Response Pattern**:
```php
respond(['success' => true, 'data' => $result], 200);
```

### Frontend (index.html)

**API Calls**:
```javascript
const result = await api('endpoint', 'METHOD', { payload });
```

**Page Navigation**:
```javascript
showPage('leads');  // Shows/hides page sections
```

**Modals**:
```javascript
openModal('modal-id');
closeModal('modal-id');
```

**Global State**: `user`, `leads`, `userSettings`, `reqConfig`, `currentLead`

## API Endpoints

| Action | Method | Auth | Description |
|--------|--------|------|-------------|
| `login` | POST | - | User authentication |
| `me` | GET | User | Current user info |
| `leads` | GET | User | List leads (filterable) |
| `lead` | GET/POST/PUT/DELETE | User | CRUD single lead |
| `import` | POST | User | CSV import |
| `enrich-lead` | POST | User | AI research |
| `generate-email` | POST | User | Create cold email |
| `generate-call-pitch` | POST | User | Create call script |
| `save-email` | POST | User | Record sent email |
| `save-call-outcome` | POST | User | Log call results |
| `export` | POST | User | Zoho CRM export |
| `stats` | GET | User | Lead statistics |
| `admin-settings` | GET/POST | Admin | API keys & config |
| `users` | GET | Admin | List all users |
| `create-user` | POST | Admin | Add user |
| `update-user` | POST | Admin | Edit user |
| `delete-user` | POST | Admin | Remove user |

## Data Models

### Lead Object
```javascript
{
  id: "lead_xxxxxxxx",
  first_name, last_name, email, phone, company, title,
  industry, country, website, linkedin, company_size, notes,
  enrichment: "JSON string",     // AI research data
  requisitions: {},              // Custom qualification fields
  status: "new|researched|email_sent|call_due|outcome_logged|qualified|disqualified",
  emails_sent: 0,
  last_email_type: "initial|followup1|followup2|breakup",
  email_history: [{ type, content, sent_at }],
  call_outcome, call_notes, call_anchor, next_action, followup_date,
  last_action, last_action_at, created_at, updated_at
}
```

### User Object
```javascript
{
  id: "user_xxxxx",
  name, email,
  password: "$2y$10$...",  // bcrypt
  token: "hex(64)",
  is_admin: boolean,
  created_at
}
```

## LLM Integration

**Abstraction**:
```php
function callLLM($provider, $apiKey, $prompt)
```

**Providers**: `callGroq()`, `callGemini()`, `callAnthropic()`

**Research Output Schema**:
```javascript
{
  research_score: { score, quality, factors },
  sources: [{ title, url, description }],
  company_profile: { description, key_products_services, market_position, growth_stage },
  industry_intelligence: { top_challenges, trends, competitive_pressures },
  prospect_analysis: { pain_points, responsibilities, success_metrics, buying_power },
  sales_strategy: { opening_hooks, value_angles, discovery_questions, objections, avoid }
}
```

## Conventions

### Naming
- **IDs**: `lead_` + hex(8), `user_` + hex(8)
- **Tokens**: hex(32) for auth
- **Statuses**: snake_case (`email_sent`, `call_due`)
- **Functions**: camelCase

### CSS Variables (Design Tokens)
```css
--navy: #1a1a1a;
--gold: #D4725C;
--success: #10b981;
--danger: #ef4444;
--radius-md: 10px;
```

### Adding New Features

1. **New field**: Update in 3 places - HTML form, JS handlers, PHP schema
2. **New endpoint**: Add case in `api.php` switch statement
3. **New page**: Add nav item, HTML section, `showPage()` logic
4. **New LLM feature**: Follow `generateEmailContent()` pattern

## Security Notes

- API keys stored in Postgres (`admin_config` blob in `store_blobs`, plaintext — use env vars in production)
- Passwords hashed with bcrypt (`password_hash()`)
- Token-based auth via `X-User-Token` header
- Keys masked in API responses (last 4 chars only)

## Limitations

- Most stores are still whole-blob read/write (a `store_blobs` row per store), not fully normalized — good enough at current scale but not built for heavy concurrent writers
- All leads loaded in memory (per-user blob, not paginated at the DB level)
- Synchronous LLM calls (5-30 second waits)
- No automated tests

---

## Document Studio (SOW generator) — ADDED for Levata

This working copy (`macktiles-with-sow`) adds a **Statement of Work generator**, ported from Levata's separate Next.js system into this PHP app. The original/live app does not have this yet; deploy by copying `sow.php` + the `api.php`/`index.html` changes once tested.

### Files & wiring
- **`sow.php`** — included near the top of `api.php` (`require_once __DIR__ . '/sow.php'`). Contains:
  - `sowSystemPrompt()` + `sowTemplate()` — the house voice and 12-section SOW template, generalised for ANY project type (website / software / app / branding), not tile-specific.
  - `sowFillTemplate()`, `buildSowUserPrompt()` — placeholder fill + prompt assembly.
  - `callLLMForSow($provider, $apiKey, $system, $user)` — a dedicated LLM call with a large token budget (8k OpenAI-shape / 32k Gemini) and proper system+user separation. The shared `callLLM` caps at 2048, which truncates a SOW, so SOW work must use this.
  - `generateSow()`, `refineSow()`.
  - ClickUp: `clickupRequest()`, `clickupListMeetingDocs()`, `clickupFetchDocText()`, `clickupCreateTask()`.
  - `extractSowInput()` — transcript → SOW form fields (JSON), with JSON recovery from noisy output.
- **api.php actions:** `generate-sow`, `refine-sow`, `clickup-meetings`, `clickup-transcript`, `extract-sow`. The `admin-settings` action also accepts/stores `clickup_token`, `clickup_workspace_id`, `clickup_list_id`.
- **index.html:**
  - Nav item `data-page="sow"` ("Documents"); page section `id="page-sow"`.
  - SOW form (uses the app's own `form-*`/`card`/`btn` classes), a "Start from meeting notes" panel (ClickUp meeting doc picker + paste-transcript → extract), a "Fill sample data" button, and generate/refine/copy.
  - Admin → Settings has a **ClickUp API Token / Workspace ID / List ID** card.
  - **PDF export:** `marked` + `html2pdf.js` loaded via CDN (next to the existing `xlsx` script). A hidden `#sow-pdf-page` container styled with `.sow-pdf` CSS renders the SOW to match the reference (gradient masthead, purple section headings, lilac table headers, zebra rows). "Download PDF" button.

### SOW conventions
- Output: British/international English, no emoji, **no em dashes**, clean GitHub-flavoured Markdown.
- Section 3 scope clauses are bold-lead-in paragraphs (`**3.1 Title.**`); Section 9 timeline is two columns (Phase = weeks, Activity); sign-off table has bold labels on both sides.
- Use **Gemini** for SOW generation — the document is large; Groq's free-tier TPM truncates it.

### Document Studio status
- Done & lint-clean: SOW + Cost Proposal generate/refine, ClickUp meeting doc pull, transcript extraction, sample data, PDF export (pixel-styled), DOCX export (logo embedded as data URI + branded cover), shared client-grouped Saved Documents library, upload-revised round-trip.
- Known limit: DOCX/PDF generated covers approximate but cannot exactly reproduce a bespoke Figma/Canva cover (CSS gradients + vector artwork don't transfer to Word). For pixel-perfect client docs, design the cover externally and re-upload via "Upload revised".

## Levata rebrand (de-Macktiles) — DONE

The whole app was rebranded from Macktiles (tiles) to Levata, brand + logic:
- **Brand / logos:** two logo assets are shipped. `levatalogo.png` is the horizontal icon+wordmark (3:1) used on the sidebar, mobile header, and both PDF document mastheads/covers. `levata-logo-jpeg.jpg` is a high-res 1080x1080 square used on the login screen and embedded (as a base64 data URI) into the DOCX export. The old `logo.png` / `logo-inverse.png` / `levatalogo3.png` assets were removed. Title/login/sidebar/onboarding text say "Levata". CSS tokens: `--navy` is deep slate `#1c1530`, `--gold` is Levata violet `#7c3aed` (was Macktiles terracotta).
- **Logic (generalised off tiles, mostly settings-driven):** `buildResearchPrompt($lead, $settings)` uses the user's `sender_company` / `company_description` / `value_proposition`; email + call-pitch prompts de-tiled; ICP defaults emptied/generalised (`ideal_segments`, `target_geographies`, `proof_points`); requisition fields generalised (`current_solution` was `current_tile_sourcing`; `project_context` now Website/Software/App/Branding); default `sender_company` = Levata; fresh-install admin seed = `admin@levatahq.com`.
- **Left as-is (internal, not user-facing):** Zoho-mapping function names, `getMacktilesStages`, `macktilesCallOutcomeConfig`, and an inert Melbourne scoring fallback.
- **Auth:** real token-based authentication is ENABLED. `getCurrentUser()` resolves the `X-User-Token` header against the `users` Postgres table; `requireAuth`/`requireAdmin`/`requireSuperAdmin` enforce 401/403. (It was previously disabled — auto-logged-in as super-admin — and has been restored.)
- **Deployment:** target is **Namecheap shared hosting (cPanel)**, served at **levataos.com**. CORS `$allowedOrigins` in `api.php` is already set to `levataos.com`. Upload PHP files + `index.html` + the two logos + `.htaccess` + a real `db.php` (see `DEPLOY_POSTGRES.md`); create a writable `data/uploads/` (755) for uploaded document files. No Docker/Render/npm — those configs were removed.

## Outbound email — two SEPARATE senders (do not merge them)

The app sends two kinds of email, both via the **Resend API** (`https://api.resend.com/emails`) over cURL. They share only the single account-wide `resend_key` (stored in the `admin_config` blob). **Their sending code and From identities are deliberately kept separate** — do not refactor them into one shared helper (this was tried and reverted on request).

1. **Help & Support** — `notifySupportEmail()` in `sow.php`'s sibling `support.php`. Self-contained cURL block. From = `Levata Support <support_from>`; Reply-To = the ticket submitter. Config: `support_email` (recipient) + `support_from` (sender) in Admin → Settings.
2. **Sales outreach to leads** — the `send-email` action in `api.php`. Its OWN self-contained cURL block (no call into support.php). Triggered by the "Send Email" button on a generated lead email (`sendLeadEmail()` in `index.html`). From = `<rep sender_name> <outreach_from>`; Reply-To = the logged-in rep's account email, so prospect replies land in the rep's real inbox, not a shared mailbox. On success it records to the lead's `email_history` (with `channel:'system'` + Resend `message_id`) and bumps the stage exactly like `save-email`. A small unsubscribe/sender footer is appended (CAN-SPAM). Config: `outreach_from` in Admin → Settings (no fallback to `support_from` — fully independent).

Limitation (acceptable for internal use): mail is sent *through* Resend, not the rep's own mailbox, so it does not appear in their Gmail "Sent" folder and replies arrive as fresh (unthreaded) inbound mail. Going through the rep's real mailbox would require Gmail/Microsoft OAuth (a much larger build) — deferred.

### Multi-client / white-label — model is SEPARATE COPY PER CLIENT (no tenancy code needed)

Each client gets their OWN isolated deployment of this same code: own URL/subdomain, own Postgres database (own `db.php`), own users, own logos. There is NO multi-tenancy in the code — every copy thinks it's the only one. The per-copy `admin_config` blob already IS that client's private config, so there is nothing to "build" for tenancy.

**Email therefore needs no per-tenant work.** Each client copy is configured (in its own Admin → Settings) with: that client's `resend_key` (their own Resend account), `outreach_from` = `sales@theirdomain.com` (a domain THEY verified in Resend via DNS — required; you can't send as a domain you don't control, Gmail/Outlook check SPF/DKIM/DMARC), plus their `support_from`/`support_email`. The existing `send-email` action works unchanged for every client because the From address is read from that copy's config, not hardcoded.

**The real cost of this model is operational, not code:** N separate cPanel deployments, each with its own domain/SSL/backups, and updates must be redeployed to every client copy (no deploy-once). Fine for a handful of clients; script the deploy/update fan-out once it grows. Upside: bulletproof data isolation — clients' data never coexists.
