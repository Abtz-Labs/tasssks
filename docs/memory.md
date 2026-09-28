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

## 2026-09-25 (refetch on focus)

- Mimicked vue-query `refetchOnWindowFocus`: `window` `focus` + `document` `visibilitychange` listeners call new `refetchActiveView()`, which reloads the active view (`refreshBoard()` on board, `loadProjects()` on projects).
- Guards prevent clobbering live UI: skips when tab hidden, any request in flight (`_activeRequests` counter in `api()`), modal open, card open, or an input/textarea/select/contenteditable is focused. No interval polling — option A only.
- Test count: 488 (all passing).

## 2026-09-25 (card insert position)

- New cards can be inserted at the top of a column: optional `at_top` boolean in `apiCreateCard()`. Truthy shifts existing column positions +1 and inserts at 0; falsy keeps `MAX(position)+1`.
- Create-card modal (`showAddCard()`) has a Position select (Bottom default / Top); `createCard()` sends `at_top`. Guests get the same choice in the first column. `N` shortcut defaults to Bottom.
- Tests: new `Card Insert Position` section (order + returned position). Test count: 499 (all passing).

## 2026-09-25 (inline card title edit)

- Card title editing is now inline: double-click the title or click the edit pencil icon. The `#modal-title` span is replaced with an input, saved on blur/Enter, cancelled on Escape. Matches column name editing pattern.
- Removed `editCardTitle()` + `saveCardTitle()` (separate modal) → replaced with `editCardTitleInline(cardId)`.
- CSS: `.card-title-text` (cursor:default), `.inline-edit-input` (flex:1, 18px bold). h2 flexes during editing. Test count: 502 (all passing).

## 2026-09-25 (inline description edit)

- Card description editing is now inline: clicking the pencil replaces the `.markdown-body` div with a Quill editor + Save/Cancel buttons, no separate modal. `editCardDescriptionInline`, `saveCardDescriptionInline`, `cancelEditDescriptionInline`. Original HTML stored in `_editDescOriginalHtml` for cancel restore. Test count: 505 (all passing).

## 2026-09-25 (modal body restructure)

- Modal body restructured: `.modal-body` now `overflow: visible` (no clipping), inner `.modal-body-scroll` handles scrolling (`overflow-y: auto`). Quill link tooltip and card-ref dropdown render fully visible. `openModal()` targets `.modal-body-scroll`. Mobile padding moved to `.modal-body-scroll`. Test count: 505 (all passing).

## 2026-09-25 (tag filter)

- Tag filter for the board: filter funnel button between search and Watch button. Multi-select dropdown with project tags (checkbox + color dot + name). "Apply" button applies filter, "Clear" resets.
- Client-side filtering in `renderBoard()`: cards filtered by `_activeFilters` Set (OR logic — card shown if any selected tag matches). Search re-applies after filter changes.
- Filter count badge on button when active. Dropdown closes on click-outside. `_activeFilters` cleared on project switch. Guest toolbar also gets the filter button + badge. Test count: 519 (all passing).

## 2026-09-25 (project sort & search)

- DB migration v8: added `position INTEGER DEFAULT 0` to `projects` table. Backfilled from `created_at ASC` (oldest=0). `list_projects` now orders by `position ASC, created_at DESC`.
- `apiReorderProjects()` endpoint: accepts `order` array of project IDs, updates `position` column. Requires auth.
- `initProjectSortable()`: SortableJS on `#projects-list` with `data-id` on `.project-card`. `onChoose`/`onUnchoose` toggle `sortable-drag-active` body class. `onEnd` sends `reorder_projects` API call and updates index badges.
- Project search: input in `.projects-header` (between h1 and "+ New Project" button). `searchProjects(query)` filters `.project-card` by name (case-insensitive show/hide). Test count: 528 (all passing).

## 2026-09-26 (move all cards / column menu)

- `apiMoveAllCards()` + `move_all_cards` route: bulk-moves every card from `column_id` to `target_column_id`, landing at the top. Returns `{ok, moved}`. `requireOwner`, same as `apiDeleteColumn`.
- Positions: target cards shift down by the source count (`position = position + n`), then source cards get `0..n-1`. Source positions can have gaps because `apiMoveCard` renumbers only the target column, so the source is renumbered explicitly (`ORDER BY position, id`) rather than shifted as a block. No transaction — matches the existing untransacted style of `apiMoveCard`.
- Column header: the `×` is replaced by a meatball menu (`.column-menu.dropdown`) with `Move all cards to...` and `Delete column`. First column has no menu (it cannot be deleted). Menu visible to any non-guest; endpoint is owner-only, so members see it and get 403 — pre-existing inconsistency, deliberately preserved.
- CSS trap: the `max-width: 768px` rule `.dropdown-toggle svg:last-child { display: none }` hides the account menu chevron. A lone meatball icon is also `:last-child`, so the toggle uses its own `.column-menu-toggle` class. Verified live at a 414px viewport: account chevron hidden, meatball visible.
- Trap: the document click-outside handler only exempts `.dropdown`, so a wrapper of only `.column-menu` was closed by the same click that opened it. The wrapper carries `dropdown` as well. Regression test added.
- Column drag uses `handle: '.column-header'`, so SortableJS got `filter: '.column-menu'` to stop the meatball starting a drag.
- `toggleColumnMenu(btn)` closes all menus before opening one, so two column menus cannot be open at once. Test count: 574 (all passing).

## 2026-09-26 (column counts on drag + move-all default)

- Fixed a **pre-existing** bug: dragging a card between columns never refreshed the `.column-count` badges. The card drag `onEnd` moved the DOM and sent `move_card` but never called `updateColumnCounts()`. Was broken at HEAD, unrelated to the column menu work.
- `initSortable()` has **two** card-move handlers: a guest path (first column only, gated on `guest_can_sort_cards`) and the signed-in path (all columns). Both needed the fix. `createCard`/`deleteCard` were already fine because they call `refreshBoard()`.
- Lesson: a lazy `/s` regex over a 9600-line single-file app matches code far past the intended block. `.*?` found the `updateColumnCounts` *definition* and the test passed for the wrong reason. Use bounded `substr()` slices, and assert handler-count == refreshing-handler-count so a future third handler cannot skip the fix.
- `showMoveAllCards` now pre-selects the **next** column along the board instead of the first, wrapping to `this.columns[0]` when the source is the last column. The first column has no menu, so the source is never index 0 and the wrap target is always valid. Test count: 583 (all passing).

## 2026-09-26 (stale card cache after drag)

- Fixed a **pre-existing** bug behind "Move 0 cards": `showMoveAllCards` counts from the `this.cards` cache, but a manual drag only updated the DOM and the server. The cache kept the old `column_id`, so the modal reported 0 for a column holding 2 cards.
- The same staleness caused a worse symptom: applying/clearing the tag filter calls `renderBoard()`, which redrew the dragged cards **back into the column they had left**, contradicting the server. Root fix: both drag handlers now do `const cached = this.cards.find(c => c.id == cardId); if (cached) cached.column_id = newColumnId;`. Fixes the count bug and the revert bug together.
- The modal count must keep reading the **cache, not the DOM**. With a tag filter active the DOM omits non-matching cards, so a DOM count would understate how many cards the server will really move. Verified: filter hides both cards, modal still correctly says "Move 2 cards".
- Test slice bounds are measured, not guessed: last call in a drag handler is at +752 bytes from its `onEnd`, next handler starts 2193 later. A 700-byte slice broke the moment the guest handler gained a line; 1000 has margin on both sides. Test count: 584 (all passing).

## 2026-09-26 (version bump)

- Bumped 0.2.3 → 0.3.0. Only three places hold a literal version: `index.php` `APP_VERSION`, `package.json`, and the `tests.php` version assertion. Footer, Help modal, App Settings, and the `version_compare` update check all read `APP_VERSION`, so one edit covers them all. `Justfile` and `bare.config.json` carry no version. Test count: 584 (all passing).

## 2026-09-28 (stale card modal + scroll + lightbox ESC)

- Fixed stale card modal after tag toggle / attachment upload / delete: mutations did `refreshBoard(); setTimeout(openCard, 200)`, but `refreshBoard` chains 3 AJAX round trips (`list_columns` → `list_cards` → `list_tags`+`unread_counts`). `openCard` read `this.cards` before it updated. Root fix: `refreshBoard(callback)` / `loadBoard(projectId, callback)`; every mutation now calls `refreshBoard(() => this.openCard(cardId))`. Applied to toggleTag, createTag, createCard, desc save, moveCardToColumn, deleteComment, uploadFiles, deleteAttachment, deleteTimeEntry, watch/unwatch (user + guest).
- Fixed long modal content not scrollable: `.modal` uses only `max-height` (indefinite), so `.modal-body-scroll { height:100% }` resolved to `auto`. Changed `.modal-body` to a nested flex column and `.modal-body-scroll` to `flex:1; min-height:0`. Verified with terminal-browser: old CSS `scrollable:false`, new CSS `scrollable:true` (1548 > 577).
- Fixed ESC closing the card instead of the attachment lightbox: the global keydown handler checked `#modal-overlay` without first checking `.lightbox`. Added `$('.lightbox').remove()` guard first.
- QoL: every modal now opens scrolled to the top. `openModal()` chains `.scrollTop(0)` after `.html(body)` — previously a long card opened after a scrolled one inherited the old scroll.
- Bumped 0.3.0 → 0.3.1 (`APP_VERSION`, `package.json`, `tests.php` assertion).
- **Serious**: running `just test` clobbered the working `index.php`. The test calls `check_update` (fetches `GITHUB_RAW_URL` = GitHub `main`, still v0.3.0) then `apply_update`; `apiApplyUpdate` only rejected `=== APP_VERSION`, so local 0.3.1 got **downgraded** to remote 0.3.0 and the pre-update file was saved to `index.php.bak`. Fixed: `apply_update` now requires `version_compare($newVersion, APP_VERSION, '>')` (no downgrades), and the "without prior check" test runs before `check_update` caches content. Restored from `index.php.bak`. Test count: 590 (all passing).
