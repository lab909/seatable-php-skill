# seatable-php — Claude Code Skill

A [Claude Code](https://claude.ai/code) skill that enables AI-assisted PHP backend development using [SeaTable](https://seatable.com) as a database, via the official [`seatable/seatable-api-php`](https://packagist.org/packages/seatable/seatable-api-php) SDK.

When this skill is installed, Claude Code will know how to scaffold, connect, query, and maintain PHP applications that use SeaTable as their data store — including self-hosted instances.

---

## What this skill teaches Claude Code

- Installing and configuring the SeaTable PHP SDK via Composer
- SeaTable's three-token authentication hierarchy (Account → API → Base Token)
- Self-hosted server configuration (`setHost()`)
- Full CRUD operations on rows using the correct SDK classes and namespaces
- SQL queries against SeaTable bases with proper `convert_keys` handling
- File and image uploads (the full two-step signed URL flow)
- Error handling and Base-Token refresh on expiry
- Recommended project structure using the Repository pattern

---

## Skill structure

```
seatable-php/
├── SKILL.md                        # Core instructions loaded into Claude's context
└── references/
    ├── api-endpoints.md            # Full SDK class/method reference + file upload example
    └── sql-limitations.md          # SeaTable SQL dialect notes and supported functions
```

---

## Requirements

- [Claude Code](https://claude.ai/code)
- PHP 7.4+
- Composer
- A SeaTable instance (cloud or self-hosted) with an API Token for your base

---

## Installation

Download the latest `seatable-php.skill` from the [Releases](../../releases) page, then install it into Claude Code:

```bash
cp seatable-php.skill ~/.claude/skills/
```

Or clone this repo and symlink the skill folder directly:

```bash
git clone https://github.com/your-username/seatable-php-skill.git
ln -s $(pwd)/seatable-php-skill/seatable-php ~/.claude/skills/seatable-php
```

After installing, restart Claude Code (or start a new session) for the skill to become available.

---

## Usage

Once installed, Claude Code will automatically use this skill whenever you mention SeaTable in a PHP context. You can also trigger it explicitly:

```
Build a PHP REST API using SeaTable as the database. Use the seatable-php skill.
```

```
Add a TaskRepository class that reads and writes to a SeaTable base.
```

```
Set up the SeaTable PHP SDK for my self-hosted server at https://seatable.mycompany.com
```

### Example kick-off prompt

Here's a prompt that exercises the full skill — auth, CRUD, SQL, error handling, and project structure:

```
Build a simple PHP REST API (no framework, plain PHP) called "task-manager"
that uses SeaTable as its database. Use the seatable-php skill.

The API should have these endpoints:
- GET /tasks (with optional ?status= filter)
- GET /tasks/{id}
- POST /tasks
- PUT /tasks/{id}
- DELETE /tasks/{id}

SeaTable table name: Tasks
Columns: Title (text), Description (long text), Status (single select: todo/in_progress/done),
Due Date (date), Priority (single select: low/medium/high)

Use a TaskRepository class, load credentials from a .env file,
and handle API errors gracefully (401 token refresh, 404 not found).

After scaffolding the project:
1. Tell me exactly which .env variables I need to fill in, what each one means,
   and where to find the values in the SeaTable UI (step by step).
2. Create a setup.php script that validates all required env variables are present
   and attempts a real connection to SeaTable, printing a clear success or error
   message for each check so I can confirm everything is working before running the API.
```

---

## Authentication overview

SeaTable uses a three-token hierarchy. For most backend apps you only need an **API Token**, which you create in the SeaTable UI under: Base → ··· menu → API Token → Add Token.

| Token | Expires | Used for |
|---|---|---|
| Account-Token | Never | Account-level operations |
| API-Token | Never | Per-base credential — the one you configure |
| Base-Token | 3 days | All CRUD operations (exchanged at runtime) |

Always store credentials in environment variables:

```
SEATABLE_SERVER_URL=https://your-seatable-server.com
SEATABLE_API_TOKEN=your_api_token_here
```

---

## Self-hosted servers

If you run a self-hosted SeaTable instance, always set the host URL explicitly — the SDK defaults to `https://cloud.seatable.io` otherwise:

```php
$config = SeaTable\Client\Configuration::getDefaultConfiguration();
$config->setAccessToken($_ENV['SEATABLE_API_TOKEN']);
$config->setHost($_ENV['SEATABLE_SERVER_URL']); // ← required for self-hosted
```

---

## SeaTable resources

- [SeaTable Developer Docs](https://developer.seatable.com)
- [PHP SDK on Packagist](https://packagist.org/packages/seatable/seatable-api-php)
- [PHP SDK on GitHub](https://github.com/seatable/seatable-api-php)
- [SeaTable API Reference](https://api.seatable.com)

---

## Contributing

Issues and PRs welcome — especially corrections to SDK method signatures or new usage patterns as the SDK evolves.

---

## License

MIT
