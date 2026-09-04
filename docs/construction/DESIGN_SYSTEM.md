# Construction Design System

**Scope:** Construction module (`modules/construction/`)  
**Status:** Module-wide default (2026-07-12)  
**Constraint:** Presentation only — no accounting, posting, or permission-logic changes (except Owner-only Theme Settings page).

## Overview

The Commercial Leasing UI v2 pilot is promoted to the **Construction Design System**:

- Canonical CSS tokens: `--construction-*`
- Compatibility aliases: `--co-*` (existing leasing markup)
- Company overrides: table `erp_module_themes` (`company_id` + `module_key` + `theme_json`)
- First `module_key`: `construction` — other ERP modules can reuse the same table later

## Files

| Path | Role |
|------|------|
| `assets/construction-ui-v2.css` | Tokens + components |
| `assets/construction-ui-v2.js` | Lucide, Chart theme helpers |
| `includes/ui/co_ui_helpers.php` | Presentational PHP helpers |
| `includes/construction_theme_helpers.php` | Construction wrappers |
| `includes/erp_module_theme.php` | Shared theme get/save/validate/CSS |
| `theme_settings.php` | Owner-only appearance settings |
| `migrations/erp_module_themes.sql` | Schema |
| `docs/construction/UI_V2_DESIGN_LAYER.md` | Legacy pilot notes (superseded by this doc for module-wide use) |

## Opt-in / default

Layout defaults `$coUiV2 = true` when unset. Pages may set `$coUiV2 = false` before including the header to force the legacy light shell.

## Design tokens (defaults)

| Token | Default |
|-------|---------|
| `--construction-primary` | `#d4af37` |
| `--construction-primary-hover` | `#e8c547` |
| `--construction-page-bg` | `#0b1220` |
| `--construction-card-bg` | `#151e2e` |
| `--construction-sidebar-bg` | `#0f172a` |
| `--construction-success` | `#10b981` |
| `--construction-warning` | `#fbbf24` |
| `--construction-danger` | `#f87171` |
| `--construction-info` | `#38bdf8` |

Company overrides are injected once as:

```html
<style id="co-theme-vars">body.co-ui-v2 { … }</style>
```

## Theme load / save / reset

1. `co_theme_get($conn, $companyId)` merges defaults + DB JSON  
2. `co_theme_save(...)` validates hex + contrast; fail-closed without `company_id`  
3. `co_theme_reset(...)` deletes the company row  
4. Missing table → defaults only (run migration)

## Theme templates

`theme_settings.php` exposes curated palettes from `co_theme_templates()`:

| Id | Name | Intent |
|----|------|--------|
| `midnight_gold` | Midnight Gold | Default dark (navy + gold) |
| `light_gold` | Light Gold | White/grey + gold light theme |
| `sand_bronze` | Sand & Bronze | Warm light + bronze |
| `ocean_slate` | Ocean Slate | Cool dark + teal |
| `graphite_amber` | Graphite Amber | Charcoal + amber |

**Preview** fills all color/shape fields and the live preview. **Apply & Save** POSTs `action=apply_template` + `template_id` (CSRF + Owner). Light themes adjust elevated surfaces/shadows via luminance in `erp_theme_css_variables()`.

**Permissions:** Theme Settings requires `require_role(['Owner'])` + Construction module access. Nav link is Owner-only.

**Not the same as** global ERP `settings.brand_*` (system-wide chrome).

## PHP helpers

| Helper | Use |
|--------|-----|
| `co_ui_page_header($title, $desc, $crumbs, $actions)` | Page chrome |
| `co_ui_kpi($array)` | KPI card |
| `co_ui_status_pill($status)` | Status badge |
| `co_ui_kpi_ring($pct)` | Occupancy ring |
| `co_ui_empty($message)` | Empty state |
| `co_ui_alert($html, $type)` | Alert (pass escaped text) |

## CSS component classes

`.co-page-header`, `.co-kpi`, `.co-pill`, `.co-filter-bar`, `.co-table-shell`, `.co-stepper`, `.co-tabs`, `.co-qa`, `.co-empty`, `.co-timeline`

## Applying to a new page

1. Do **not** set `$coUiV2 = false` (design system is default).  
2. Include `construction_layout_header.php` as usual.  
3. Prefer `co_ui_page_header()` and `.co-kpi` / `.co-table-shell` for chrome.  
4. Keep field `name`s, POST actions, CSRF, and calculations unchanged.

## Future modules

Insert rows with another `module_key` (e.g. `realestate`) and provide `erp_theme_defaults('realestate')`. Do not fork the table.

## Migration

```bash
# After explicit human approval:
mysql … < migrations/erp_module_themes.sql
```
