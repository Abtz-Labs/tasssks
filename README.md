# Tasssks

A single-file, self-hosted kanban board built with PHP and SQLite.

No frameworks. No Composer. No build tools. One file, one database, done.

## Concept

Tasssks was born from a desire for simplicity. Most project management tools are bloated, require complex infrastructure, or lock your data behind a subscription. Tasssks takes the opposite approach: drop one PHP file on any server with PHP 8.1+ and you have a fully functional kanban board with multi-user support, guest access, and notifications.

The entire application (backend logic, frontend markup, CSS, and JavaScript) lives in a single `index.php` file. The database is a SQLite file created automatically on first run. Uploads go into a single directory. That's the whole stack.

## Features

- Drag-and-drop cards and columns
- Multiple projects with customizable columns
- Team management with roles (admin and member)
- Guest access via shareable token links
- WYSIWYG editor with markdown shortcuts for card descriptions and comments
- Fuzzy search across cards (title and description)
- Keyboard shortcuts for common actions
- Comments
- File attachments
- Tags (global, color-coded)
- Watcher system (get notified about cards or entire projects)
- Webhook integrations (Slack, Telegram, generic JSON)
- Email notifications via SMTP (immediate or daily digest)
- Queue-based email delivery (non-blocking, cron-powered)

## Requirements

- PHP 8.1 or later
- SQLite3 extension (usually bundled with PHP)
- A web server (Apache, Nginx, or PHP's built-in server for development)

## Installation

1. Copy `index.php` to your web root (or any directory served by PHP).
2. Make sure the directory is writable (for the SQLite database and uploads).
3. Open it in a browser. The setup screen will ask you to create the first admin account.

That's it.

For development (requires [just](https://github.com/casey/just)):

```
just start
```

With a custom port:

```
just start 3000
```

Run in background:

```
just start background
```

Both options combined:

```
just start 3000 background
```

Stop the server:

```
just stop
```

Or directly:

```
php -S localhost:2020 index.php
```

## User Manual

### First Run

On first access, Tasssks shows a setup screen. Create your admin account (name, email, password). This account has full control over the application.

### Projects

Create projects from the sidebar. Each project has its own board with customizable columns. By default, new projects start with "To Do", "In Progress", and "Done".

Add or rename columns from the project settings (gear icon in the board header).

### Cards

Click "Add card" at the bottom of any column. Cards support:

- Title (required)
- Description (Markdown)
- Tags
- File attachments
- Comments

Drag cards between columns or reorder them within a column. Drag columns to reorder them.

### Team

Admins can invite team members from the Account menu (Team section). Members can create their own projects and collaborate on projects where they are added as owners.

### Guests

Share a project with external collaborators by creating a guest link (project settings). Guests can view the board, add cards, and post comments (but cannot modify structure or settings).

Guests with an email address can watch cards and projects to receive digest notifications.

### Watching

Click the eye icon on a project or card to watch it. Watchers receive notifications when activity occurs. You automatically watch cards you create or comment on.

### Notifications

**Webhooks** are configured per project (project settings). Supported types: Slack (Incoming Webhook), Telegram (Bot API), and generic JSON POST.

**Email notifications** require SMTP configuration (App Settings). Choose between immediate delivery or a daily digest summary.

Both email modes are processed by cron jobs (not inline) to avoid blocking the application. See the Help section in the Account menu for cron setup instructions.

### API Tokens

Generate API tokens from the Account menu (API Tokens tab). Tokens allow external tools and agents (like Claude Code, automation scripts, etc.) to access the API on your behalf without a browser session.

Usage:

```bash
curl -H "Authorization: Bearer <your-token>" \
  "https://your-instance/?action=list_projects"
```

Bearer token auth bypasses CSRF checks — no session cookie or `X-CSRF-Token` header needed. Tokens have full access as the user who created them.

Tokens can be revoked at any time from the Account menu. The raw token is shown only once at creation — store it securely.

### App Settings (Admin Only)

- SMTP configuration and test
- Cron token generation (for authenticating cron requests)
- Global tag management

### Keyboard Shortcuts

| Shortcut | Action |
|----------|--------|
| `⌘K` | Search cards |
| `⌘S` | Save (in any form) |
| `N` | Add card to first column |
| `W` | Watch/unwatch card or project |
| `,` | Project settings |
| `A` | Account |
| `T` | Team |
| `G` | App settings |
| `?` | Help |
| `1`–`9` | Open project by index (on project list) |
| `Esc` | Cancel / close modal / clear search |

Single-letter shortcuts work when no input is focused. On Windows/Linux, use `Ctrl` instead of `⌘`.

## Deployment

For production, use Apache or Nginx with PHP-FPM. The key security considerations:

- **Database location.** By default the SQLite file (`tasssks.sqlite`) is created in the same directory as `index.php`. Set the `TASSSKS_DB_FILE` environment variable to place it outside the web root (recommended). Examples:

  ```bash
  # PHP built-in server (dev)
  TASSSKS_DB_FILE=/path/to/data/tasssks.sqlite php -S localhost:2020 index.php

  # Apache (.htaccess or vhost)
  SetEnv TASSSKS_DB_FILE /var/data/tasssks.sqlite

  # Nginx + PHP-FPM (server or location block)
  fastcgi_param TASSSKS_DB_FILE /var/data/tasssks.sqlite;

  # Docker / systemd
  Environment=TASSSKS_DB_FILE=/var/data/tasssks.sqlite
  ```
- **Deny direct file access.** The application blocks requests to `.sqlite`, `.env`, and `.git` paths internally, but your web server should also enforce this as a second layer.
- **Do not deploy `test.php` to production.** It is a development-only file.

### Apache

A `.htaccess` file is included in the repository. It routes requests through `index.php`, blocks access to database and sensitive files, and denies access to `test.php`. No extra setup needed if your Apache has `mod_rewrite` and `AllowOverride All`.

### Nginx

```nginx
location ~ \.(sqlite|sqlite3|db|sql|env)$ {
    deny all;
}

location ~ /\.git {
    deny all;
}

location / {
    try_files $uri /index.php$is_args$args;
}
```

## Running Tests

The test suite spins up its own server on port 8089 (with a temporary database):

```
just test
```

Or directly:

```
php test.php
```

Tests use a temporary database and clean up after themselves.

## Contributing

Contributions are welcome. Please keep these principles in mind:

1. **Single-file architecture.** All application code stays in `index.php`. This is a deliberate constraint, not a limitation to work around.

2. **No external PHP dependencies.** No Composer, no autoloaders. If you need a library, either implement the necessary logic directly or reconsider the approach.

3. **Write tests.** New features should include corresponding tests in `test.php`. Use TDD when possible (write failing tests first, then implement).

4. **Keep it simple.** If 50 lines of straightforward code solve the problem, don't write 200 lines of abstracted code. Match the existing style.

5. **Surgical changes.** A pull request should do one thing. Don't bundle unrelated cleanups or formatting changes.

### How to Contribute

1. Fork the repository.
2. Create a feature branch.
3. Write tests for your change.
4. Implement the change (make tests pass).
5. Submit a pull request with a clear description of what and why.

### Reporting Issues

Open an issue with steps to reproduce. Include your PHP version, browser, and any relevant error messages.

## License

[O'SAASy](https://osaasy.dev/). See [LICENSE](LICENSE) for details.

In short: use it, modify it, self-host it, distribute it. Just don't offer it as a competing hosted service where the software itself is the primary value.

Designed, built, and backed by [Rogerio Taques](https://x.com/rogeriotaques), the guy behind [Abtz Labs](https://abtz.co).
