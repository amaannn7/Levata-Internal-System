# Conexus Cold Email Generator

Multi-user cold email platform with AI-powered research and email generation.

## Setup

1. Upload all files to your web server
2. Ensure PHP 7.4+ with curl extension
3. Make `data/` directory writable: `chmod 755 data/`
4. Access the site and login

## Default Login

- **Email:** admin@conexus.com  
- **Password:** password

## Features

- AI-powered lead research with detailed intelligence
- Proven cold email frameworks (AIDA structure)
- Follow-up sequence with thread continuity
- Client requisitions/qualification system
- Multi-user with admin controls
- Mobile responsive design
- Zoho CRM-compatible CSV export

## API Providers

Configure in Admin Panel:
- **Groq** (Recommended) - Fast, reliable
- **Gemini** - Free tier available
- **Anthropic** - Claude Sonnet

## File Structure

```
/
├── api.php          # Backend API
├── index.html       # Frontend app
├── logo.png         # Logo
├── .htaccess        # Security rules
└── data/            # JSON storage
    ├── admin.json   # API keys & requisitions config
    ├── users.json   # User accounts
    └── user_*.json  # Per-user lead data
```
