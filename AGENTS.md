# AGENTS

## Repo overview

Single-file PHP kanban board. All backend logic, frontend HTML/CSS/JS lives in `index.php`. No frameworks, no Composer, no build tools. SQLite database.

## Commands

- `just start` — dev server on port 2020 (foreground)
- `just start 3000` — dev server on custom port
- `just start background` — dev server in background (default port)
- `just start 3000 background` — dev server in background on custom port
- `just stop` — stop background dev server
- `just test` — run test suite (spins up its own server on port 8089, uses `test.sqlite`, cleans up after)

Or directly: `php -S localhost:8080 index.php`

## Architecture

- **`index.php`** — the entire app. Routes, API handlers, DB schema/migrations, HTML template, CSS, and JS all in one file.
- **`tests.php`** — API test suite. Spins up a dedicated PHP server on port 8089 with `test.sqlite`. Uses `curl` for HTTP requests. Never touches `kanban.sqlite`.
- **`.htaccess`** — Apache rewrite rules + sensitive file blocking.
- API routing: `?action=<name>` query parameter. POST endpoints require `X-CSRF-Token` header (session auth) OR `Authorization: Bearer <token>` header (API token auth — bypasses CSRF).
- DB migrations use `PRAGMA user_version` (version-based, sequential). New migrations go at the end of `migrateDatabase()` with `if ($version < N)` guard.

## API Authentication

Two auth methods:
- **Session** — cookie-based, requires `X-CSRF-Token` header on POST requests.
- **Bearer token** — `Authorization: Bearer <token>` header. Bypasses CSRF. Tokens are created per-user from Account → API Tokens. Token hash stored in `api_tokens` table.

## Constraints

- **Single-file architecture is intentional.** All code goes in `index.php`. Do not split into multiple files.
- **No external PHP dependencies.** No Composer, no autoloaders. Implement logic directly.
- Database migrations use `PRAGMA user_version` in `migrateDatabase()` (currently v5). Add new migrations as `if ($version < N)` blocks and bump the final `PRAGMA user_version = N` statement. Migrations must be idempotent (check column/table existence before ALTER).

## Testing

Tests run against a real server (port 8089) with a temporary SQLite DB. The test suite is self-contained — it starts the server, runs tests, and shuts it down. No external test framework.

To run: `just test` or `php tests.php`

Tests cover: auth, team, projects, columns, cards, comments, guests, webhooks, watchers, notifications, SMTP, search, security headers, CSRF, rate limiting, file blocking, tags, time tracking, code quality checks.

## Key Helpers

- `setSetting(key, value)` — single upsert point for the settings table
- `rotateRecoveryKey(userId)` — generates and stores a new recovery key
- `formatEventText(eventType, payload, projectName, actorName, includeContent)` — shared by webhooks and notifications
- `calcMinutesFromTimes(start, end)` — HH:MM subtraction for time tracking

## Style

- Match existing code patterns in `index.php`. No abstractions, no premature optimization.
- API responses use `jsonResponse()` helper.
- Auth checks: `requireAuth()`, `requireAdmin()`, `requireOwner($projectId)`, `requireAccess($projectId)`.
- Session-based auth with CSRF tokens. Guest access via token query parameter.
- When adding user-facing features, update README and in-app help in the same pass.
