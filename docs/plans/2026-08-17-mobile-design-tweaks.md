---
status: done
---

# Mobile Design Tweaks

## Context

The app currently has zero mobile-specific CSS for the header, footer, modals, cards, and navbar. Only one `@media (max-width: 768px)` rule exists (line 3292) that adjusts board padding and column width. On mobile: the footer squeezes side-by-side, modals touch screen edges, the board doesn't reach the footer, cards don't adapt, and the navbar overflows with the app name, search box, watch/settings buttons, and avatar dropdown all competing for space.

All changes must be scoped to `@media (max-width: 768px)` so desktop is untouched.

## Files

- `index.php` — all CSS, HTML templates, and JS (single-file app)

## Plan

### 1. Footer: stack vertically and center

**What:** On mobile, the two `<p>` elements in `.app-footer` should stack vertically and center, instead of sitting side-by-side.

**Where:** Add to the existing `@media (max-width: 768px)` block at line 3292.

```css
.app-footer {
    flex-direction: column;
    gap: 4px;
}
```

**Verify:** `just test` passes. Visual check: footer lines stack and are centered.

---

### 2. Modals: fit mobile screens

**What:** On mobile, modals are broken in multiple ways:
- `padding-top: 80px` on the overlay wastes vertical space
- `.modal-body` and `.modal-header` have 24px padding each side = 48px wasted on 375px screens
- `.settings-tabs` has negative margins (`-12px -24px`) that extend beyond the modal
- Tab buttons with `padding: 10px 16px` can overflow when 3+ tabs exist
- `max-height: 80vh` clips content on short screens

**Where:** Add to the `@media (max-width: 768px)` block.

```css
/* Modal overlay: reduce top padding */
.modal-overlay { padding-top: 24px; }

/* Modal: reduce horizontal padding, allow full height */
.modal {
    margin: 0 8px;
    max-height: calc(100vh - 48px);
    width: calc(100% - 16px);
}
.modal-header { padding: 16px; }
.modal-body { padding: 16px; }
.modal-footer { padding: 12px 16px; }

/* Tabs: prevent overflow, allow scroll */
.settings-tabs {
    margin: -16px -16px 16px;
    padding: 0 16px;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}
.settings-tab { padding: 10px 12px; font-size: 12px; white-space: nowrap; }
```

**Verify:** `just test` passes. Visual check: modals have breathing room, tabs scroll if needed, content not clipped.

---

### 3. Board: bottom padding for fixed footer

**What:** The board needs bottom padding so content isn't hidden behind the fixed footer.

**Where:** Add to the `@media (max-width: 768px)` block.

```css
.board {
    padding-bottom: 48px;
}
```

**Verify:** `just test` passes. Visual check: last column content is visible above footer.

---

### 4. Cards: fit mobile screens

**What:** On mobile, cards should use the full available width within their column. Column width is already 260px at 768px. Cards should wrap long titles and keep metadata compact.

**Where:** Add to the `@media (max-width: 768px)` block.

```css
.card-title {
    font-size: 13px;
}
.card-meta {
    flex-wrap: wrap;
    gap: 4px;
}
```

**Verify:** `just test` passes.

---

### 5. Navbar: hide app name, show project name only

**What:** On mobile, hide `#header-brand-name` (the "Tasssks" text). The breadcrumb (`#breadcrumb`) already shows the project name — it should be truncated with ellipsis if too long, limited to 40% width.

**Where:** Add to the `@media (max-width: 768px)` block.

```css
#header-brand-name {
    display: none;
}
.breadcrumb {
    max-width: 40%;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
```

**Verify:** Visual check: "Tasssks" text gone, project name visible with ellipsis if long.

---

### 6. Navbar: watch button collapses to icon + counter

**What:** On mobile, the watch/unwatch button should show only the eye icon and counter number, hiding the "Watch" / "Watching" text. The button HTML already contains an SVG icon followed by text — we hide the text node.

**Approach:** The watch button text is injected via JS `.html()` which includes both the SVG and text. We can't easily split the text from JS without changing the JS. Instead, use CSS to shrink the button and hide overflow, keeping only the icon visible. Or better: change the JS to add a `data-watch-label` attribute and hide it on mobile.

**Simplest CSS-only approach:** Use `.btn-sm` on the watch button. On mobile, hide the text portion. Since the SVG + text are in the same innerHTML, we use a wrapper span in the JS.

**Where (JS):** In `loadProjectWatchState()` (line 4943) and `updateGuestWatchBtn()` (line 4976), wrap the text in a `<span class="watch-label">`.

**Where (CSS):** Add to `@media (max-width: 768px)`.

```css
.watch-label { display: none; }
```

**Verify:** `just test` passes. Visual check: watch button shows eye icon + count only.

---

### 7. Navbar: project settings collapses to icon only

**What:** On mobile, the "Project Settings" button should show only the gear icon, hiding "Project Settings" text.

**Approach:** In `renderBoard()` (line 3843), wrap "Project Settings" text in a `<span class="settings-label">`.

**Where (CSS):** Add to `@media (max-width: 768px)`.

```css
.settings-label { display: none; }
```

**Verify:** `just test` passes. Visual check: gear icon only, no text.

---

### 8. Navbar: avatar dropdown collapses to icon only

**What:** On mobile, the user dropdown toggle should show only the avatar SVG icon, hiding the user name and chevron.

**Where (CSS):** Add to `@media (max-width: 768px)`.

```css
.dropdown-toggle svg:last-child { display: none; }
.dropdown-toggle { font-size: 0; gap: 0; padding: 0 8px; }
.dropdown-toggle svg { font-size: initial; }
```

**Verify:** Visual check: avatar icon only in navbar.

---

### 9. Navbar: search bar collapses to icon, expands on focus

**What:** On mobile, the search input should be collapsed to just the magnifying glass icon. On focus/click, it expands to fill available width.

**Where (CSS):** Add to `@media (max-width: 768px)`.

```css
.search-wrapper input {
    width: 34px;
    padding: 0 0 0 34px;
    border-color: transparent;
    background: transparent;
}
.search-wrapper input:focus {
    width: 100%;
    position: absolute;
    right: 0;
    border-color: var(--primary);
    background: var(--surface);
    padding: 0 72px 0 34px;
}
```

**Verify:** Visual check: magnifying glass only, expands on tap.

---

## Verification

1. `just test` — all 215 tests pass
2. Visual: open browser dev tools, toggle mobile viewport (375px width)
3. Check each item: footer stacks, modals have margin, board reaches footer, cards fit, navbar collapsed, search expands on focus
