# Deploy Checklist — levata-deploy.zip

Everything needed to go from "zip uploaded to cPanel" to "fully working app",
including every key/credential the app can use. Nothing below lives in the
zip except as an empty template — it's all entered by hand, once, on the
live server.

## 1. What's in the zip

Regenerate any time with the PowerShell snippet at the bottom of this file.
Current contents (20 files, no secrets):

```
.cpanel.yml, .gitattributes, .gitignore, .htaccess
api.php, chat.php, chatbot.php, clients.php, cp.php, jobs.php, minutes.php,
partners.php, sow.php, support.php, tasks.php
index.html, partner-portal.html   <- partner-portal.html is a SEPARATE standalone
                                      page (its own login), not part of the SPA
levatalogo.png, levata-logo-jpeg.jpg
db.example.php          <- template only, NOT real credentials
data/uploads/.gitkeep    <- keeps the upload folder path in the zip
CLAUDE.md, DEPLOY_CHECKLIST.md, DEPLOY_POSTGRES.md, HOW_IT_WORKS.md, README.md
```

Deliberately **excluded** (never zip these): `db.php`, `db.production.php`,
`migrate-users.php`, any `data/*.json`, `.git/`, `.claude/`.

## 2. File-level secret — db.php (the only credential file)

This is the **only** secret that lives in a file. Everything else (API keys,
sender emails) lives in Postgres and is entered through the Admin UI after
deploy — see Section 3.

1. On the server, copy `db.example.php` → `db.php` (cPanel File Manager, or
   `cp` over SSH). Never upload a `db.php` you edited locally over git/zip.
2. Edit the constants to match the Postgres database cPanel created for you:
   ```php
   define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
   define('DB_NAME', getenv('DB_NAME') ?: 'levatahq_levatadb');
   define('DB_USER', getenv('DB_USER') ?: 'levatahq_levata');
   define('DB_PASS', getenv('DB_PASS') ?: 'the-real-password');
   ```
3. Full walkthrough for creating that database: see `DEPLOY_POSTGRES.md`
   Phase 0–1.

## 3. Runtime keys — entered in Admin → Settings after first login

None of these need to be in the zip or in any file. They're stored in the
`admin_config` row in Postgres (`store_blobs` table) and entered once through
the app's own UI. This is the full list of every key the app can use —
tick off what you actually need:

### LLM providers (Sales Intelligence, SOW/CP generation, task extraction)
| Key | Used for | Required? |
|---|---|---|
| `groq_key` | Groq (llama-3.3-70b) — fast/cheap default | Pick **at least one** provider |
| `gemini_key` | Google Gemini — **required for SOW generation** (large token budget; Groq free tier truncates SOWs) | Recommended |
| `anthropic_key` | Anthropic Claude | Optional |
| `default_provider` | Which provider `groq`/`gemini`/`anthropic` is used when not specified | Set to match whichever key(s) you added |
| `groq_model` / `gemini_model` / `anthropic_model` | Override the default model per provider | Optional |

### ClickUp (meeting transcripts → SOW input / extracted tasks, plus task sync)
| Key | Used for |
|---|---|
| `clickup_token` | Personal API token, authorizes all ClickUp calls below |
| `clickup_workspace_id` | Listing/fetching recent meeting docs for "Start from meeting notes" and task extraction |
| `clickup_list_id` | The List that tasks saved in this app are created in via the ClickUp API |

### Email — Resend (both outbound senders share this one account-wide key)
| Key | Used for |
|---|---|
| `resend_key` | The Resend API key. Powers **both** senders below — do not skip. |
| `support_email` | Where Help & Support tickets are emailed **to** |
| `support_from` | The **From** address for support notification emails (must be a domain verified in Resend) |
| `outreach_from` | The **From** address for sales emails sent to leads (must be a domain verified in Resend; independent of `support_from` by design) |

### Zoho CRM (optional integration)
| Key | Used for |
|---|---|
| `zoho_client_id` / `zoho_client_secret` | OAuth app credentials from the Zoho API console |
| `zoho_datacenter` | Which Zoho region (`com`, `eu`, `in`, etc.) |
| `zoho_enabled_modules` | Which Zoho modules to sync |
| `zoho_auto_sync` | Whether sync runs automatically | 
| *(connected_at, tokens, sync_history)* | Written automatically once you connect — never set by hand |

**None of these are required to boot the app.** Login, leads, jobs, clients,
tasks all work with zero keys configured. You only need the LLM key(s) for
AI research/email/SOW generation, ClickUp token for meeting import/task sync, and
Resend key + sender addresses before sending any real email.

## 4. Server-side setup checklist (in order)

- [ ] **Postgres database created** in cPanel (name + user noted exactly as
      cPanel generated them — usually prefixed, e.g. `levatahq_levatadb`)
- [ ] **Zip uploaded** and extracted into the app root (e.g. `public_html/`
      or a subdomain's document root)
- [ ] **`db.php` created** on the server from `db.example.php`, filled with
      the real DB credentials (Section 2) — uploaded separately, never via
      the zip/git
- [ ] **`data/uploads/` is writable** (755) — needed for uploaded document
      files and client file attachments
- [ ] **First request loads successfully** — hitting the app URL should
      auto-run `dbBootstrap()` and create every table, then seed
      `admin@levatahq.com` / `password` if `users` is empty
- [ ] **Log in with the seeded admin**, change that password immediately
      (Admin → Users)
- [ ] **Admin → Settings**: enter whichever keys from Section 3 you need
      (at minimum: one LLM provider key + `default_provider` to use AI
      features at all)
- [ ] **Verify CORS**: `$allowedOrigins` in `api.php` must include the real
      domain this copy is served from (already set to `levataos.com` for
      the main deploy — check/update for any other domain)
- [ ] **Smoke test**: add a lead, create a client, create a job (confirms
      invoice auto-generation), create a task, submit a support ticket

## 5. Multi-client deployments

Each client gets their own **separate** zip deploy: own subdomain, own
Postgres database (own `db.php`), own set of keys in Section 3 (their own
Resend account + verified sending domain — you cannot send as a domain you
don't control). There's no shared tenancy in the code, so this checklist
runs once per client copy. See the "Multi-client / white-label" section in
`CLAUDE.md` for the full model.

## 6. Regenerating the zip

Run from the project root (PowerShell). Excludes secrets and dev junk,
preserves the `data/uploads/` folder path:

```powershell
$root = (Get-Location).Path
$zipPath = "$root\levata-deploy.zip"
if (Test-Path $zipPath) { Remove-Item -LiteralPath $zipPath -Force }

$excludeDirPrefixes = @('.git\', '.claude\', 'node_modules\', 'pptx-build\', 'docs\')
$excludeFiles = @('db.php','db.production.php','migrate-users.php','levata-deploy.zip','error_log','.DS_Store')

$all = Get-ChildItem -LiteralPath $root -Recurse -File
$items = foreach ($f in $all) {
    $rel = $f.FullName.Substring($root.Length + 1)
    $skip = $false
    foreach ($p in $excludeDirPrefixes) { if ($rel.StartsWith($p)) { $skip = $true; break } }
    if (-not $skip -and $excludeFiles -contains $rel) { $skip = $true }
    if (-not $skip -and $rel -like "data\*.json" -and -not $rel.StartsWith("data\uploads\")) { $skip = $true }
    if (-not $skip -and $rel.StartsWith("data\uploads\") -and $rel -ne "data\uploads\.gitkeep") { $skip = $true }
    if (-not $skip -and $rel -like "_*.png") { $skip = $true }
    if (-not $skip -and $rel -like "_*.js") { $skip = $true }
    if (-not $skip -and $rel -like "_*.html") { $skip = $true }
    if (-not $skip -and $rel.StartsWith(".data-backup-")) { $skip = $true }
    if (-not $skip) { [PSCustomObject]@{ Full = $f.FullName; Rel = $rel } }
}

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem
$fs = [System.IO.File]::Open($zipPath, [System.IO.FileMode]::Create)
$archive = New-Object System.IO.Compression.ZipArchive($fs, [System.IO.Compression.ZipArchiveMode]::Create)
foreach ($it in $items) {
    $entryName = $it.Rel.Replace([char]92, [char]47)
    [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($archive, $it.Full, $entryName, [System.IO.Compression.CompressionLevel]::Optimal) | Out-Null
}
$archive.Dispose()
$fs.Dispose()
Write-Host "Done: $zipPath"
```
