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

- Card stays open after creation: `createCard()` now uses API response `id` to re-open card via `setTimeout(() => this.openCard(cardId), 100)` after board refresh.
- Quill toolbar not focusable: `_initQuill` sets `tabindex="-1"` on `.ql-toolbar` container and all `button`/`select` children. Uses `parentNode.querySelector('.ql-toolbar')` (sibling, not ancestor `closest()`).
- Footer layout fix: replaced `position: fixed` footer with flex-column body layout (`display: flex; flex-direction: column; min-height: 100vh`) and `margin-top: auto` on footer. Content no longer overlaps on long pages.
- Text replacements: added `TEXT_REPLACEMENTS` map (`:check:`→✅, `:cross:`/`:x:`→❌, `->`/`:arrow-right:`→→, `<-`→←, `--`/`:emdash:`→—). `_applyReplacements()` for input fields, `_initQuillReplacements()` for Quill editors (uses `quill.getText()` for full-text check, not delta fragment).
- Webhook UX overhaul (DB migration v7): added `bot_token`, `chat_id`, `message_template` columns to `project_webhooks`. Preset-specific form fields (Generic: URL; Slack: URL + template; Telegram: bot token + chat ID + template). Setup instructions with external links. Edit and Test buttons on webhook rows. `apiUpdateWebhook()` and `apiTestWebhook()` endpoints. `formatWebhookPayload()` supports `{{event}}`, `{{project}}`, `{{actor}}`, `{{title}}`, `{{timestamp}}` template variables.
- Test count: 474 (all passing)

## 2026-08-31 (same day, continued)

- Card reference `#ID` autocomplete in Quill: `_initCardRefAutocomplete(quill)` shows a read-only hint dropdown (`.card-ref-dropdown` appended to `.modal-body`, which got `position: relative`) of matching cards while the text before the cursor matches `#\d+`. Rebuilt with no selection after repeated cursor-drift bugs.
- Live linkification: `_linkifyCardRefs(quill)` applies Quill `link` format to `#\d+` via `formatText(..., 'silent')`, capturing + restoring selection so the caret doesn't move. Runs debounced (~50ms) on `text-change` for manually typed refs. View-mode `linkCardRefs(html, projectId)` links `#N` in `openCard`.
- Cursor bug root cause: `formatText` (even with `'silent'`) disturbed the editor selection during `_selectMatch`/linkify. Decided to drop dropdown selection entirely (read-only hint) rather than keep fighting the cursor placement; manual `#ID` typing + live linkify remains the supported flow.
- Linkify-while-typing bug: `#1` linked instantly, so continuing gave `[#1](...)0`. Fix: regex `/#(\d+)(?![0-9])/g` + skip linkifying any ref whose caret sits immediately after its last digit (user still typing). Also always re-apply link format (dropped `!existing.link` guard) so a lengthened `#10` gets fully linked instead of leaving `[#1]0`. Test count: 481 (all passing).
- Card title validation UX: `createCard()` and `saveCardTitle()` silently `return`ed when title was empty (no feedback). Added `this.toast('Title is required.')` + refocus the title input. Backend `apiCreateCard` already rejects empty title with a 400 ('Missing fields'). Added 2 code-quality tests. Test count: 483 (all passing).
- Card column move UX: `moveCardToColumn()` appended the card to the end of the new column (`position = colCards.length`). Changed to `position: 0` so a card moved via the card-detail column dropdown lands at the top of the new stack. Backend `apiMoveCard` already shifts the other cards correctly. Test count: 483 (all passing).
