# HR Design System

**Scope:** Human Resources module (`hr/*.php` + HR shell)  
**Status:** Module default (2026-07-16)  
**Constraint:** Presentation only — no payroll posting, WPS, leave/loan policy, permission, or schema changes.

## Overview

HR uses the same frontend architecture as Administration Control Center:

- Bootstrap 5.3.3 + Lucide (+ Alpine where useful)
- Hand-authored CSS design tokens (`--hr-*`)
- Presentational PHP helpers (`hr_ui_*`)
- **Light white + gold theme** (matches Admin; not the legacy dark brand sidebar)

## Files

| Path | Role |
|------|------|
| `assets/hr/hr-ui-v2.css` | Tokens + components |
| `assets/hr/hr-ui-v2.js` | Lucide, mobile sidebar, nav search, unsaved forms |
| `hr/includes/hr_layout_header.php` | Shell sidebar + top bar |
| `hr/includes/hr_layout_footer.php` | CDN scripts |
| `hr/includes/ui/hr_ui_helpers.php` | Presentational helpers |
| `hr/includes/hr_nav.php` | Sidebar IA (manager + worker) |

## Design tokens (light gold defaults)

| Token | Default |
|-------|---------|
| `--hr-primary` | `#b8860b` |
| `--hr-primary-hover` | `#d4af37` |
| `--hr-page-bg` | `#f6f7f9` |
| `--hr-card-bg` | `#ffffff` |
| `--hr-sidebar-bg` | `#ffffff` |
| `--hr-border` | `#e5e7eb` |
| `--hr-text` | `#111827` |
| `--hr-text-muted` | `#6b7280` |

## PHP helpers

| Helper | Use |
|--------|-----|
| `hr_ui_page_header($title, $desc, $crumbs, $actions)` | Page chrome |
| `hr_ui_kpi($array)` | KPI card |
| `hr_ui_status_pill($status)` | Status badge |
| `hr_ui_empty($message)` | Empty state |
| `hr_ui_alert($html, $type)` | Alert |

## CSS component classes

`.hr-page-header`, `.hr-kpi`, `.hr-pill`, `.hr-filter-bar`, `.hr-table-shell`, `.hr-settings-card`, `.hr-info-card`, `.hr-empty`, `.hr-unsaved-bar`

Legacy Bootstrap `.card` inside `body.hr-ui-v2` is restyled for spacing/padding compatibility.

## Applying to an HR page

1. Set `$pageTitle` (optional; layout infers from filename).
2. Optional: `$hrScopeLabel` for company scope pill in the top bar.
3. `require` `hr/includes/hr_layout_header.php`.
4. Render content with helpers / `.hr-*` classes.
5. `require` `hr/includes/hr_layout_footer.php`.
6. Do not change POST handlers, CSRF, or role checks.
7. Prefer Lucide icons (`data-lucide="…"`) for new chrome; Bootstrap Icons may remain in legacy forms.

## Do / Don’t

- **Do** keep light gold as default module chrome.
- **Do** show company scope on list/financial screens when the page supports it.
- **Don’t** introduce Tailwind or a SPA.
- **Don’t** invent leave/loan/payroll business rules while restyling.
