# Memory

Session checkpoints for continuity across sessions.

## 2026-08-18

- Refactored index.php for code quality: extracted `setSetting`, `rotateRecoveryKey`, `formatEventText` helpers; consolidated `QUILL_TOOLBAR`; removed unnecessary comments; bulk-query `getSmtpSettings`
- Fixed Account modal tab buttons (missing `type="button"` inside `<form>`)
- Added time tracking start/end times: DB migration v4→v5, `update_time_entry` API, frontend with grouped time inputs + "Now" buttons, in-progress entries, toast notifications
- Fixed tag color bug: `selectTagColor` was hardcoding `$('#new-tag-color')` — changed to relative DOM lookup `$(el).parent().find('input[type="hidden"]')`
- Test count: 353 (all passing)

## 2026-08-20

- Added project reporting settings: `week_start_day` (0=Sun–6=Sat, default Mon) and `cycle_reset_day` (1–31, default 1) columns via DB migration v6. Settings UI in Project Settings → General tab (side-by-side grid).
- Added app-wide locale setting: stored in `settings` table, dropdown in App Settings → General (en, pt-BR, de, fr, es, it, ja). Used for date formatting via `Intl.DateTimeFormat`.
- Time Report period display: added localized period label (e.g., "Aug 17 – Aug 23, 2026"). Fixed pre-existing bug where `cycle_reset_day > 1` produced backward date range (`from > to`).
- Added `toLocalDateStr()` helper to avoid `toISOString()` UTC timezone shift in period calculations. Week period now correctly computes end-of-week (Sunday) instead of showing today.
- Localized date-grouped rows in Time Report.
- Test count: 381 (all passing)

## 2026-08-26

- Fixed "Comments not loading in card preview" bug: `loadComments` `.done()` callback had no error handling — if `marked.parse()` or `DOMPurify.sanitize()` threw, jQuery propagated the exception as uncaught error and `$('#card-comments').html()` never ran, leaving UI stuck on "Loading...". Added try/catch + .fail() handler + null-safety for comment content.
- Fixed `loadProjectWatchState` crash: `this.user.id` accessed without null-check, causing `Cannot read properties of null` when `this.user` is null.
- Renamed `test.php` → `tests.php`; updated all references (`.htaccess`, `Justfile`, `README.md`, `AGENTS.md`).
- Updated test output format: colored PASS/FAIL with `=== group-title ===` headers, summary with counts.
- Added tests: `list_comments` API (returns comments, empty array, 400/404 errors, response fields, delete comment), `loadComments` error handling checks (try/catch, .fail handler, null-safe content), `loadProjectWatchState` null-user guard check. Fixed stale version assertion (0.1.0 → 0.1.1).
- Test count: 405 (all passing)

## 2026-08-31

- Card stays open after creation: `createCard()` uses API response `id` to re-open card via `setTimeout(() => this.openCard(cardId), 100)`.
- Quill toolbar not focusable: `_initQuill` sets `tabindex="-1"` on `.ql-toolbar` and all `button`/`select` children.
- Footer layout fix: replaced `position: fixed` footer with flex-column body layout (`display: flex; flex-direction: column; min-height: 100vh`) and `margin-top: auto` on footer.
- Text replacements: `TEXT_REPLACEMENTS` map (`:check:`→✅, `:cross:`/`:x:`→❌, `->`/`:arrow-right:`→→, `<-`←←, `--`/`:emdash:`→—). `_applyReplacements()` for inputs, `_initQuillReplacements()` for Quill.
- Webhook UX overhaul (DB migration v7): `bot_token`, `chat_id`, `message_template` columns. Preset-specific form fields. Edit/Test buttons. `apiUpdateWebhook()`, `apiTestWebhook()`. Template variables: `{{event}}`, `{{project}}`, `{{actor}}`, `{{title}}`, `{{timestamp}}`.
- Card reference `#ID` autocomplete: read-only hint dropdown while text before cursor matches `#\d+`. Live linkification via `_linkifyCardRefs()` with debounced `text-change`. Regex `/#(\d+)(?![0-9])/g` skips refs still being typed.
- Card title validation: `createCard()` and `saveCardTitle()` show `this.toast('Title is required.')` + refocus input when empty.
- Card column move: `moveCardToColumn()` sends `position: 0` so moved cards land at top of new column.
- Mobile column sizing: changed from fixed `260px` to `85vw` in `@media (max-width: 768px)`.
- Mobile card drag: SortableJS `delay: 500`, `delayOnTouchOnly: true`, `touchStartThreshold: 5`, `chosenClass: 'sortable-chosen'`. Tilt + scale visual feedback. Desktop drag unaffected.
- Avatar menu shortcut indicators: replaced `<kbd>` with `.shortcut-hint` spans — subtle, small (11px), muted (`var(--text-light)` + `opacity:0.6`), right-aligned. Account=`A`, Team=`T`, Settings=`⌘,`, Help=`?`.
- Project list index: added visual index numbers (1-9) via `.project-index` badge — 22px rounded square, muted text on `var(--bg)` background, `flex: 0 0 22px` to prevent growth. Added `.project-card-info { flex: 1; min-width: 0; }` to contain the index to its badge width.
- SVG favicon: added inline data URI favicon matching uptime style — 32x32 rounded rect (`#334155`), white bold sans-serif "T." text. Inserted after `<meta charset="UTF-8">`.
- Test count: 483 (all passing)

## 2026-09-15

- Adopted the Nord color scheme (nordtheme.com) across the app, matching the `emmmail` Nord design-system standard in the Bare initiative.
- Replaced `:root` and `[data-theme="dark"]` tokens with Nord values: bg nord6/nord0, surface #fff/nord1, surface-hover nord5/nord2, border nord4/nord3, text nord0/nord6, primary nord10/nord8, danger nord11, success #5f8f68/nord14. Added `--nord0`–`--nord15`, `--on-primary`, `--warning`/`--warning-soft`, `--success-soft`, `--danger-soft`. Shadows tinted to Nord.
- Accent text contrast: primary buttons/outline-hover/success toast now use `--on-primary` (white in light, nord0 in dark).
- Recolored hardcoded Tailwind hexes to Nord vars: admin/member badges, guest banner (warning), success status badge, dropdown danger hover.
- Favicon switched to nord2 rect + nord6 text.
- Tag palette now Nord Aurora/Frost (`#bf616a #d08770 #ebcb8b #a3be8c #5e81ac #b48ead #88c0d0`); default tag color nord10 (`#5e81ac`), default column color nord3 (`#4c566a`).
- Bumped version 0.1.0 → 0.2.0 (`APP_VERSION`, `package.json`, tests).
- Verified computed CSS vars for light + dark via terminal-browser. Test count: 483 (all passing).

## 2026-09-15 (bug fix)

- Fixed card description links rendering as `undefined`: `marked.use({ renderer: { link(...) } })` used the v13 token-object destructuring `link({ href, title, text })`, but the app loads **marked@12.0.0** (positional args `link(href, title, text)`). So every link rendered as `<a href="undefined">undefined</a>`. Reproduced via node against the real CDN build; fixed signature to positional. `list(body)` was already correct for v12.
- Updated the stale test at tests.php:1365 that enforced the wrong destructured signature → now asserts `link(href, title, text)`.
- Test count: 483 (all passing).

## 2026-09-25

- Login heading now uses the header's four-square SVG instead of the lock emoji. Added `.login-heading` CSS (flex, centered, `gap:8px`, `svg` sized `1em`). No inline styles.
- Modal: header and footer stay fixed; only `.modal-body` scrolls. `.modal` is now `display:flex; flex-direction:column; overflow:hidden`; `.modal-body` is `flex:1; overflow-y:auto; min-height:0`; header/footer `flex-shrink:0`.
- Time Report TOTAL stat and Hours column (all groupings: card/user/date) show hours+minutes only (e.g. `98h 49m`) via new `formatMinutesHM()` helper — no day/month/year rollover. `formatMinutes()` unchanged elsewhere. Hours/Entries columns nowrap.
- Test count: 483 (all passing).
