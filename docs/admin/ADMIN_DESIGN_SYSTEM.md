# Administration Design System

**Scope:** ERP Administration Control Center (`settings.php` + admin shell)  
**Status:** Module default (2026-07-16)  
**Constraint:** Presentation only — no accounting, posting, permission, or schema changes.

## Overview

Admin Control Center uses the same frontend architecture as Construction:

- Bootstrap 5.3.3 + Alpine.js + Lucide + Chart.js (CDN)
- Hand-authored CSS design tokens (`--admin-*`)
- Presentational PHP helpers (`admin_ui_*`)
- **Light white + gold theme** (eye-comfort default; not Construction dark)

## Files

| Path | Role |
|------|------|
| `assets/admin/admin-ui-v2.css` | Tokens + components |
| `assets/admin/admin-ui-v2.js` | Lucide, drawers, unsaved forms, toasts |
| `includes/admin/admin_layout_header.php` | Shell sidebar + top bar |
| `includes/admin/admin_layout_footer.php` | CDN scripts |
| `includes/admin/ui/admin_ui_helpers.php` | Presentational helpers |
| `includes/admin/admin_nav.php` | Sidebar IA definition |

## Design tokens (light gold defaults)

| Token | Default |
|-------|---------|
| `--admin-primary` | `#b8860b` |
| `--admin-primary-hover` | `#d4af37` |
| `--admin-page-bg` | `#f6f7f9` |
| `--admin-card-bg` | `#ffffff` |
| `--admin-sidebar-bg` | `#ffffff` |
| `--admin-border` | `#e5e7eb` |
| `--admin-text` | `#111827` |
| `--admin-text-muted` | `#6b7280` |

Optional dark tokens under `[data-theme="dark"]` are reserved for a future opt-in; **shipping UI is light-first**.

## PHP helpers

| Helper | Use |
|--------|-----|
| `admin_ui_page_header($title, $desc, $crumbs, $actions)` | Page chrome |
| `admin_ui_kpi($array)` | KPI card |
| `admin_ui_status_pill($status)` | Status badge |
| `admin_ui_empty($message)` | Empty state |
| `admin_ui_alert($html, $type)` | Alert (pass escaped text) |
| `admin_ui_scope_badge($scope)` | Global / Company / Module |

## CSS component classes

`.admin-page-header`, `.admin-kpi`, `.admin-pill`, `.admin-filter-bar`, `.admin-table-shell`, `.admin-settings-card`, `.admin-info-card`, `.admin-empty`, `.admin-drawer`, `.admin-activity-row`, `.admin-skeleton`, `.admin-unsaved-bar`

## Applying to a new Admin page

1. Set `$pageTitle`, `$adminTab` (or `$tab`), prepare `$adminNavGroups` via `admin_nav.php`.
2. `require` `admin_layout_header.php`.
3. Render content with helpers / `.admin-*` classes.
4. `require` `admin_layout_footer.php`.
5. Do not change POST handlers, CSRF, or role checks.
6. Mark major forms with `data-admin-unsaved` for the sticky unsaved bar.
7. Prefer Lucide icons (`data-lucide="…"`) over ad-hoc emoji.

## Do / Don’t

- **Do** keep light gold as default; dark tokens are opt-in via `data-theme="dark"` only.
- **Do** reuse `.admin-settings-card` / `.settings-card` for form sections.
- **Don’t** introduce Tailwind or a SPA.
- **Don’t** put secret values in Integrations UI.
