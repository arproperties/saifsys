# Phase 3B Wave 0 — Component Catalog

**Foundation:** `modules/ars/includes/ars_ui.php`  
**Showcase:** `modules/ars/tools/ars_ui_showcase.php` (not in nav)  
**Rule:** Presentational only — no DB, no financial math, no posting, no Stay dependency.

| Component | Helper | Variants / notes |
|-----------|--------|------------------|
| App wrapper | `ars_ui_app_open` / `ars_ui_app_close` | `#ars-app` root |
| Assets | `ars_ui_assets` | CSS + Alpine + Lucide + ars-ui.js |
| Page header | `ars_ui_page_header` | Title, subtitle, actions |
| Breadcrumbs | `ars_ui_breadcrumbs` | List of label/href |
| Action toolbar | `ars_ui_action_toolbar` | Role=toolbar |
| Primary/secondary/danger/ghost button | `ars_ui_button` | sizes sm/md/lg; locked; disabled |
| Icon button | `ars_ui_icon_button` | aria-label required |
| Status badge | `ars_ui_status_badge` | booking / housekeeping / maintenance / financial |
| Generic badge | `ars_ui_badge` | tone + optional icon |
| Financial lock indicator | `ars_ui_lock_indicator` | role=status |
| KPI card | `ars_ui_kpi_card` | tabular value; alert border |
| Alert card | `ars_ui_alert_card` | info/warning/danger/success |
| Empty state | `ars_ui_empty_state` | optional action HTML |
| Error banner | `ars_ui_error_banner` | aria-live assertive |
| Skeleton | `ars_ui_skeleton` | pulse; reduced-motion respected via CSS |
| Form field | `ars_ui_form_field` | label, required, error, help |
| Form error | `ars_ui_form_error` | role=alert |
| Filter bar | `ars_ui_filter_bar` | role=search |
| Table shell | `ars_ui_table_shell` | caption, thead, tbody |
| Drawer shell | `ars_ui_drawer_shell` | Alpine open; Escape; focus trap attr |
| Modal shell | `ars_ui_modal_shell` | same |
| Confirmation dialog | `ars_ui_confirm_dialog` | alertdialog; danger option |
| Toast region | `ars_ui_toast_region` | aria-live polite; `ArsUI.toast` |
| Timeline item | `ars_ui_timeline_item` | chronological |
| Permission-disabled | `ars_ui_permission_disabled` | title=reason |
| Network error | `ars_ui_network_error` | optional retry button id |
| Icon | `ars_ui_icon` | Lucide `data-lucide` |

Directory stub: `modules/ars/views/components/README.md`.
