# Accessibility and Responsive Standards

**Stage:** 2 — Standards + current-state notes. Not implemented.

## Current-state snapshot

| Area | Observation | Evidence |
|------|-------------|----------|
| Framework | Bootstrap 5 components help baseline a11y | Confirmed from code |
| Labels | Often present; Select2/filter UIs sometimes weak | Strong inference |
| Status | Color badges common; text usually present | Confirmed from code |
| Mobile nav | Uneven (RE weak; Construction/ARS better) | Confirmed from code |
| Touch | Tenant portal `--tp-touch: 44px` | Confirmed from code |
| RTL | Arabic libs exist (ar-php); staff RTL readiness uneven | Strong inference / Needs business confirmation |
| Modals | Bootstrap modals used widely | Confirmed from code |
| Tables | `table-responsive` common; not always card-alternative on mobile | Confirmed from code |

---

## Required standards (future)

### Input labels
- Every control has a visible `<label for>` or `aria-label`.
- Placeholder is never the only label.

### Keyboard & focus
- All actions reachable by Tab; visible `:focus-visible`.
- Modals trap focus; restore focus on close; Esc closes.
- No `outline: none` without replacement.

### Tab order
- Matches visual order; skip link to main content on staff shell.

### Color contrast & status
- Text contrast ≥ 4.5:1 (3:1 large text).
- Status always includes text/icon, not color alone.

### Screen readers
- Page has one `h1` (or equivalent `page-header-label` mapped to heading role).
- Tables: `<th scope>`; complex tables need captions.
- Live regions for async errors (`role="alert"`).

### Accessible modals & tables
- `aria-labelledby` on modal title.
- Sortable columns announce state.
- Horizontal scroll tables have accessible name.

### Validation messages
- Tied to field via `aria-describedby`; not color-only.

### Touch & mobile
- Targets ≥ 44×44px for primary actions.
- Filters stack; primary CTA not trapped in hover-only UI.
- Navigation: off-canvas drawer on small screens for all staff modules.

### Responsive filters & nav
- Filter card collapses or stacks under `md`.
- Avoid side-by-side dense filters on phone.

### RTL readiness
- Where Arabic UI is required: logical properties (`margin-inline`), Bootstrap RTL build or mirrored CSS — **Needs business confirmation** which modules require RTL.

### Print readability
- Hide chrome; show company + title; avoid cut-off tables.

---

## Explicit non-changes

No accessibility fixes implemented in Stage 2.
