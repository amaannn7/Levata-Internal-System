# AGENTS.md - Macktiles Sales Intelligence

## Project Overview

Macktiles Sales Intelligence is a multi-user B2B sales intelligence and cold outreach platform for Macktiles Australia, a tile company entering the Australian market. It's a monolithic PHP + vanilla JavaScript application with JSON file-based storage.

## Tech Stack

- **Frontend**: Vanilla JavaScript SPA, CSS3 with design tokens
- **Backend**: PHP 7.4+ REST API
- **Storage**: JSON files in `data/` directory
- **LLM Providers**: Groq (llama-3.3-70b), Google Gemini, Anthropic Codex

## Project Structure

```
/
├── index.html          # Single-page app (HTML + CSS + JS)
├── api.php             # Backend API (all endpoints)
├── logo.png            # Brand assets
├── logo-inverse.png
├── README.md
└── data/               # JSON storage (must be writable)
    ├── admin.json      # API keys, config, requisitions
    ├── users.json      # User accounts & auth tokens
    └── user_*.json     # Per-user leads data
```

## Key Commands

```bash
# Start local PHP server
php -S localhost:8000

# Ensure data directory is writable
chmod 755 data/
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

**Data I/O**:
```php
$users = getUsers();           // Read users.json
saveUsers($users);             // Write users.json
$data = getUserData($userId);  // Read user_*.json
saveUserData($userId, $data);  // Write user_*.json
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

- API keys stored in `data/admin.json` (plaintext - use env vars in production)
- Passwords hashed with bcrypt (`password_hash()`)
- Token-based auth via `X-User-Token` header
- Keys masked in API responses (last 4 chars only)

## Limitations

- JSON file storage (no database)
- No file locking (potential race conditions)
- All leads loaded in memory
- Synchronous LLM calls (5-30 second waits)
- No automated tests
