# AGENTS

## Repo overview

Single-file PHP kanban board. All backend logic, frontend HTML/CSS/JS lives in `index.php`. No frameworks, no Composer, no build tools. SQLite database.

## Commands

- `just start` — dev server on port 8080 (foreground)
- `just start 3000` — dev server on custom port
- `just start 8080 background` — dev server in background
- `just stop` — stop background dev server
- `just test` — run test suite (spins up its own server on port 8089, uses `test.sqlite`, cleans up after)

Or directly: `php -S localhost:8080 index.php`

## Architecture

- **`index.php`** — the entire app. Routes, API handlers, DB schema/migrations, HTML template, CSS, and JS all in one file.
- **`test.php`** — API test suite. Spins up a dedicated PHP server on port 8089 with `test.sqlite`. Uses `curl` for HTTP requests. Never touches `kanban.sqlite`.
- **`.htaccess`** — Apache rewrite rules + sensitive file blocking.
- API routing: `?action=<name>` query parameter. All POST endpoints require `X-CSRF-Token` header.

## Constraints

- **Single-file architecture is intentional.** All code goes in `index.php`. Do not split into multiple files.
- **No external PHP dependencies.** No Composer, no autoloaders. Implement logic directly.
- Database migrations live in `migrateDatabase()` function (index.php:210). New schema changes go there.

## Testing

Tests run against a real server (port 8089) with a temporary SQLite DB. The test suite is self-contained — it starts the server, runs tests, and shuts it down. No external test framework.

To run: `just test` or `php test.php`

Tests cover: auth, team, projects, columns, cards, comments, guests, webhooks, watchers, notifications, SMTP, search, security headers, CSRF, rate limiting, file blocking.

## Style

- Match existing code patterns in `index.php`. No abstractions, no premature optimization.
- API responses use `jsonResponse()` helper.
- Auth checks: `requireAuth()`, `requireAdmin()`, `requireOwner($projectId)`, `requireAccess($projectId)`.
- Session-based auth with CSRF tokens. Guest access via token query parameter.
