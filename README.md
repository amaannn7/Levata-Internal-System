# Levata Internal System

Levata's internal platform — **Sales Intelligence**, a **Document Studio** (Cost Proposals + Statements of Work), and a company-wide **Job Registry**. Monolithic PHP + vanilla JavaScript with JSON file storage.

## Modules

- **Sales Intelligence** — leads, AI research, cold email / call-pitch generation, pipeline.
- **Documents** — AI-generated **Cost Proposals** (CP-xxxx) and **Statements of Work** (SOW-xxxx), with PDF/DOCX export and a re-upload round-trip for hand-finished files. Documents are shared across the team.
- **Job Registry** — shared finance view tracking each client job: approved cost proposal → linked SOW → invoices (advance + final, or monthly for retainers), with status and pipeline/paid/outstanding roll-ups.

## Requirements

- **PHP 7.4+** with the **curl** extension enabled (needed for the AI provider calls).
- A writable `data/` directory.

## Setup (Namecheap / cPanel shared hosting)

1. Upload to the web root: `index.html`, `api.php`, `sow.php`, `cp.php`, `jobs.php`, `levatalogo.png`, `levata-logo-jpeg.jpg`, `.htaccess`.
2. Create a writable `data/` directory and a `data/uploads/` subdirectory (permissions `755`).
3. In cPanel → **Select PHP Version**, set 8.0/8.1 and ensure `curl` is enabled.
4. Enable HTTPS (cPanel → SSL/TLS → AutoSSL). The app assumes `https://`.
5. Visit the site. On first load it seeds a default admin and creates `data/admin.json`.

## First login

On a fresh install the seeded admin is:

- **Email:** `admin@levatahq.com`
- **Password:** `password`

**Change this password immediately after first login** — authentication is enforced (token-based via the `X-User-Token` header).

Then go to **Settings** and add your AI keys (Groq / Gemini / Anthropic) and Fireflies key. These are stored in `data/admin.json` on the server (never in the repo).

## AI providers

Configured in **Settings**:

- **Groq** — fast (Llama 3.3). Free tier may truncate large documents.
- **Gemini** — recommended for SOW/Cost Proposal generation (large output budget).
- **Anthropic** — Claude.

## Local development

```bash
php -S localhost:8000
```

## File structure

```
/
├── index.html              # Single-page app (HTML + CSS + JS)
├── api.php                 # Backend API (routes; require_once's the modules)
├── sow.php                 # SOW generation + shared document store + Fireflies + doc numbering
├── cp.php                  # Cost Proposal generation
├── jobs.php                # Job Registry (shared store)
├── levatalogo.png          # Horizontal logo (sidebar, mobile, PDFs)
├── levata-logo-jpeg.jpg    # High-res square logo (login, DOCX)
├── .htaccess               # Blocks web access to data/ ; security headers
└── data/                   # JSON storage (writable; gitignored — never committed)
    ├── admin.json          # API keys & config
    ├── users.json          # Accounts, tokens, password hashes
    ├── user_*.json         # Per-user leads
    ├── documents.json      # Shared Cost Proposals + SOWs
    ├── jobs.json           # Shared Job Registry
    └── uploads/            # Uploaded revised document files
```

## Security notes

- `data/` is blocked from direct web access by `.htaccess` (so API keys/passwords can't be downloaded). Verify after deploy that `https://<domain>/data/admin.json` returns **403**.
- Passwords are bcrypt-hashed; auth is token-based.
- API keys live only in `data/admin.json` on the server; nothing sensitive is in the repo.

See `CLAUDE.md` for full architecture, conventions, and module internals.
