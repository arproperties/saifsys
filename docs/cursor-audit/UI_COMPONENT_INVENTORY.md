# UI Component Inventory

**Stage:** 2  
**Date:** 2026-07-10  
**Rule:** Inventory only — do not create shared partials yet.

| Component | Locations (representative) | Classification | Notes |
|-----------|----------------------------|----------------|-------|
| Module layout header/footer | `modules/*/includes/*_layout_*.php`, `hr/includes/hr_layout_*` | Duplicate | Same idea, N copies |
| Brand CSS vars | `includes/branding.php` → layouts | Canonical | Staff brand source |
| Vertical theme CSS | ARS/Legal/Grocery/Barber/portals | Acceptable variant | Needs brand approval matrix |
| Sticky sidebar | RE, Construction, HR, Legal, Grocery… | Canonical pattern / Duplicate impl | — |
| Mobile drawer | Construction, ARS | Canonical candidate | Port to RE |
| Topbar-only shell | Tasks | Acceptable variant | Lightweight module |
| Portal top nav | Tenant, Stay | Acceptable variant | Customer UX |
| Accounts inline shell | `account.php` | Inconsistent | Extract layout later |
| Operation partials | `cleaning_ui_sidebar.php` | Inconsistent | Mixed adoption |
| `card-round` | RE, Construction | Acceptable variant | Not global |
| Filter card + GET form | Most list pages | Canonical pattern | Standardize fields |
| Data table + `table-responsive` | Widespread | Canonical | Add pagination standard |
| Bootstrap modal | Widespread | Canonical | A11y rules apply |
| Status `badge bg-*` | Widespread | Canonical / Inconsistent maps | Per-domain maps OK |
| `alert alert-*` | Widespread | Canonical | — |
| `page-header-label` | Many modules | Canonical candidate | Prefer over raw h1 mix |
| Select2 | `lease_add.php`, complex forms | Acceptable variant | Load once via layout |
| Pagination | Accounts, ops, HR; missing on many RE lists | Inconsistent | — |
| Empty row text | Tables | Duplicate ad hoc | Empty-state partial later |
| Spinner | Accounts AJAX | Needs manual review | Promote shared |
| KPI cards | Dashboards, receipt allocation | Duplicate | Summary component later |
| Company switcher | Grocery/Inventory headers, select-module | Duplicate | Shared control later |
| Switch Module link | Most layouts | Canonical pattern | — |
| Dark mode toggle | RE, HR layouts | Acceptable variant | Optional |
| Cheque dual-mode panels | `billing_cheque_view.php` | Needs manual review | Legacy = Historical |
| Accounting mode banner | `lease_add.php` | Canonical for RE | Keep IM primary |
| PDF templates | mPDF/Dompdf generators | Inconsistent | Shared header later |
| Root `header.php`/`footer.php` | `includes/` | Deprecated candidate | Empty; unused |

---

## Future shared partials (do not create now)

1. `staff_layout_header.php` / footer with module slots  
2. `page_header.php` (title, breadcrumb, actions)  
3. `filter_bar.php`  
4. `empty_state.php` / `loading_block.php`  
5. `status_badge.php`  
6. `company_context_bar.php`  
7. `journal_lines_table.php`  
8. `mobile_nav_drawer.php`  

---

## Explicit non-changes

No partials created or files refactored.
