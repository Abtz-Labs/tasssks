# Auto-Update Feature

## Status: done

## Goal
Add automatic update checking and one-click update for Tasssks. Since the app is a single `index.php` file, updating = fetching the latest from GitHub and replacing the local file.

## Design

### Backend

#### New API endpoints (both admin-only)

1. **`check_update`** (GET)
   - Reads `last_update_check_at` from `settings` table
   - If checked <24h ago, returns cached result from settings (`latest_version`, `update_available`, `latest_content`)
   - If >24h (or first time): fetches `https://raw.githubusercontent.com/rogeriotaques/tasssks/main/index.php`
   - Parses `define('APP_VERSION', '...')` with regex
   - Uses `version_compare()` against local `APP_VERSION`
   - Stores results in settings: `latest_version`, `update_available`, `last_update_check_at`, `latest_content` (the full fetched file)
   - Returns `{ update_available, latest_version, current_version, last_checked }` (does NOT return content)

2. **`apply_update`** (POST, CSRF required)
   - Reads cached `latest_content` and `latest_version` from settings
   - If no cached content, returns error (must check first)
   - Validates cached content contains a valid `APP_VERSION` (sanity check — must not be empty)
   - Backs up current file: `index.php` → `index.php.bak`
   - Writes new content to `index.php` via `file_put_contents()`
   - On write failure: restores from `.bak`, returns error
   - On success: updates settings (`update_available = 0`, `latest_version`, clears `latest_content`)
   - Returns `{ ok: true, previous_version, new_version }`

#### Modified endpoint

3. **`auth_status`** — add `version` and `update_available` to response
   - `version`: `APP_VERSION` constant
   - `update_available`: read from settings table (default `0`)
   - Before returning, compare local `APP_VERSION` against cached `latest_version` — if they match, clear `update_available` flag (handles manual updates via git pull etc.)
   - This lets the frontend show notifications without extra API calls

### Frontend

#### Non-invasive admin notification (toast)

- On admin page load, if `update_available` is true, show a subtle toast banner at the bottom-right
- Toast content: "A new version of Tasssks is available (vX.Y.Z)" with an "Update" link that opens App Settings → Updates tab
- Auto-dismisses after 10 seconds, or user can close it manually
- Uses existing toast/notification pattern (if any) or a simple fixed-position div

#### Settings modal — new "Updates" tab

- Add a third tab "Updates" alongside General and SMTP in `showAppSettings()`
- Tab content:
  - Current version display
  - Latest version display (or "Up to date" if none available)
  - Last checked timestamp
  - "Check now" button (calls `check_update`, refreshes tab)
  - "Apply update" button (only shown when `update_available` is true)
  - After applying: show success message, prompt page reload

#### User menu badge

- When `update_available` is true, show a small indicator dot next to the "Settings" menu item in the avatar dropdown
- Read from `this.updateAvailable` (set from `auth_status` response)

### Rate limiting

- `check_update` respects the 24h cooldown via `last_update_check_at` in settings
- `apply_update` has no rate limit (admin action, requires confirmation)

### Safety

- Backup: `copy('index.php', 'index.php.bak')` before writing
- Write failure handling: if `file_put_contents()` fails, restore from `.bak`
- Sanity check: fetched content must contain `define('APP_VERSION',` and must not be empty
- Cached content is stored in settings (not re-fetched on apply) — avoids double request and remote changes between check and apply
- After update, the user must reload the page — no hot-reload
- Stale flag handling: `auth_status` clears `update_available` if local version matches cached latest

### File blocking (.bak)

Add `.bak` to all three blocking layers:

- **`.htaccess`**: add `bak` to the `FilesMatch` regex
- **PHP built-in server router** (`index.php` line 15): add `bak` to the regex
- **General security block** (`index.php` line 296): add `\.bak` to the regex
- **Tests** (`test.php` line 815): add `index.php.bak` to the sensitive files list

## Files to modify

- **`index.php`**: add `check_update`, `apply_update` functions; add to match router; modify `apiAuthStatus()`; add "Updates" tab in JS `showAppSettings()`; update user menu badge rendering; add toast notification JS; block `.bak` in router + security section
- **`.htaccess`**: add `.bak` to `FilesMatch`
- **`test.php`**: add `index.php.bak` to sensitive files test; add update-related tests
- **`README.md`**: document auto-update feature in App Settings section; add `.bak` to Nginx config example

## Tests to add

- `check_update returns current_version matching APP_VERSION`
- `check_update requires admin`
- `check_update caches latest_content in settings`
- `apply_update requires admin`
- `apply_update creates backup file`
- `apply_update fails without prior check`
- `auth_status returns version field`
- `auth_status returns update_available field`
- `index.php.bak blocked by security`

## Implementation order

1. Block `.bak` in `.htaccess`, PHP built-in server router, general security block, and tests
2. Add `version` and `update_available` to `auth_status` response (with stale flag handling)
3. Add `check_update` endpoint with caching
4. Add `apply_update` endpoint with backup + error handling
5. Add "Updates" tab in Settings modal JS
6. Add user menu badge when update available
7. Add non-invasive toast notification on admin page load
8. Add auto-check on admin board load (fire-and-forget `check_update` if >24h since last check)
9. Update README docs
10. Add regression tests
