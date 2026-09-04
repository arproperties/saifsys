# ERP Module Map

**Project:** HeroSysgro ERP  
**Audit stage:** Stage 1  
**Generated:** 2026-07-10  
**Scope:** Read-only repository inspection. No live database connection. No production logic changed.

## Evidence labels

| Label | Meaning |
|-------|---------|
| Confirmed from code | Observed in PHP/JS/config |
| Confirmed from schema artifact | Observed in migrations, dumps, or schema snapshots |
| Strong inference | Reasonable conclusion from multiple code paths |
| Needs database confirmation | Requires live DB inspection |
| Needs business confirmation | Requires product/ops decision |
| Not found | Searched; no evidence located |

---

## 1. Top-level structure

| Path | Role | Evidence |
|------|------|----------|
| `accounts/` | Cleaning / SM finance UI (invoices, receipts, GL, VAT, bank reco, prepaid) | Confirmed from code |
| `operation/` | Cleaning operations (work orders, clients, schedule) | Confirmed from code |
| `modules/realestate/` | Real estate leases, billing, accounting, maintenance | Confirmed from code |
| `modules/construction/` | Construction projects, AP/AR, bank reco | Confirmed from code |
| `modules/ars/` | Short-term rental (ARS) | Confirmed from code |
| `modules/inventory/` | Shared inventory / stock / POS support | Confirmed from code |
| `modules/grocery/` | Supermarket POS UI over inventory | Confirmed from code |
| `modules/barber/` | Barber POS | Confirmed from code |
| `modules/legal/` | Legal cases (RE-linked) | Confirmed from code |
| `modules/tasks/` | Thin wrapper over RE tasks | Confirmed from code |
| `hr/` | HR, attendance, payroll | Confirmed from code |
| `includes/` | Shared auth, GL, permissions, services | Confirmed from code |
| `api/mobile/`, `api/customer/v1/`, `api/pos/` | External / mobile APIs | Confirmed from code |
| `tenant_portal/` | Tenant-facing RE portal | Confirmed from code |
| `stay/` | ARS guest web portal | Confirmed from code |
| `migrations/` | Incremental SQL (~261 files) | Confirmed from schema artifact |
| `database/` | Schema snapshot / sync tooling (not run in this audit) | Confirmed from code |
| `cron/`, `tools/` | Scheduled and maintenance scripts | Confirmed from code |
| `customer_app/` | Flutter customer app | Confirmed from code |
| `vendor/` | Composer dependencies (mPDF, Dompdf, PHPMailer, etc.) | Confirmed from code |

**Routing:** File-based PHP pages + Apache `.htaccess` rewrite. No framework front controller for staff UI. Confirmed from code: `.htaccess`, `includes/url_helper.php`, `includes/module_access.php` → `get_module_route()`.

**Registered modules** (`includes/module_access.php`): `cleaning`, `realestate`, `construction`, `hr`, `finance`, `inventory`, `core`, `ars`, `grocery`, `barber`, `legal`.  
**Not registered as MODULE_*:** `modules/tasks` (Confirmed from code).

---

## 2. Dual ledger architecture (global)

| Stack | COA | Journals | Primary helper | Consumers |
|-------|-----|----------|----------------|-----------|
| Cleaning / SM standalone | `chart_of_accounts` | `gl_journals`, `gl_journal_lines`, `gl_account_balances` | `includes/gl_posting.php` | Cleaning accounts, SM prepaid/recurring, cleaning payroll |
| Shared RE engine | `re_chart_of_accounts` | `re_journal_headers`, `re_journal_lines`, `re_general_ledger` | `modules/realestate/accounting/accounting_engine.php` | Real Estate, Construction, ARS, non-cleaning payroll, ERP expenses |

Isolation between shared-engine companies is by `company_id` + `companies.business_type`, **not** separate databases. Confirmed from code.  
**Do not treat `company_id` alone as fully safe** — see `ACCOUNTING_ARCHITECTURE.md` company_id verification matrix.

---

## 3. Module catalogue

### 3.1 Cleaning (`MODULE_CLEANING`)

| Field | Detail | Evidence |
|-------|--------|----------|
| Directories | `/`, `operation/`, `accounts/` | Confirmed from code |
| Main entry pages | `index.php`, `operation.php`, `account.php`, `operation/*`, `accounts/invoices.php` | Confirmed from code |
| Main tables | `make_order`, `client`, `invoices`, `receipts`, `receipt_allocations`, `order_payment`, `expenses`, `workers`, `sm_*`, `cleaning_bank_*`, `gl_*`, `chart_of_accounts` | Confirmed from schema artifact |
| Important views | `v_ar_*` AR ageing/open invoice views in `database/views/erp_schema_views.sql` | Confirmed from schema artifact |
| Accounting | Standalone `gl_*` via `gl_posting.php`; context via `includes/cleaning_accounting_context.php` | Confirmed from code |
| Permissions | Module `cleaning`; depts `cleaning_operations`, `cleaning_accounts` | Confirmed from code |
| Shared services | `ServiceAccountingService`, `sm_*` services, work-order services, inventory MR helpers | Confirmed from code |
| Crons | `tools/sm_run_prepaid_cron.php`; platform `cron/*` | Confirmed from code |
| Cross-module | → Inventory MR; → mobile booking APIs | Confirmed from code |
| High-risk shared code | Dual payment paths (`order_payment` vs `receipts`); WO finalize → invoice → GL | Confirmed from code / Strong inference |

### 3.2 Real Estate (`MODULE_REALESTATE`)

| Field | Detail | Evidence |
|-------|--------|----------|
| Directories | `modules/realestate/`, `modules/realestate/accounting/` | Confirmed from code |
| Main entry pages | `modules/realestate/index.php`, lease/payment/billing pages, `accounting/*` | Confirmed from code |
| Main tables | `re_*` family (~171 tables in snapshot) | Confirmed from schema artifact |
| Accounting | Shared engine `accounting_engine.php` + `accounting_integration.php` | Confirmed from code |
| Permissions | Module `realestate`; multiple RE departments | Confirmed from code |
| Crons | Reminder, collections, lease expiry, cheque, AMC, penalties, task reminders, digests | Confirmed from code |
| Cross-module | → Legal; → Inventory; ← tenant_portal; Construction/ARS share GL engine | Confirmed from code |
| High-risk | Dual cheque stores; invoice-mode vs legacy payment modes; shared GL with CO/ARS | Confirmed from code |

### 3.3 Construction (`MODULE_CONSTRUCTION`)

| Field | Detail | Evidence |
|-------|--------|----------|
| Directories | `modules/construction/` | Confirmed from code |
| Main entry pages | `modules/construction/index.php`, supplier/client invoice & payment pages, bank reco | Confirmed from code |
| Main tables | `co_*` (~50) | Confirmed from schema artifact |
| Accounting | Same shared `re_*` engine; wrapper `construction_accounting_integration.php` | Confirmed from code |
| Permissions | Module `construction` | Confirmed from code |
| Crons | Module-specific cron files: Not found | Not found |
| Cross-module | → RE accounting engine; → Inventory; ERP expenses | Confirmed from code |
| High-risk | Shared journals/COA with RE — wrong session company posts to wrong books | Confirmed from code / Strong inference |

### 3.4 HR (`MODULE_HR`)

| Field | Detail | Evidence |
|-------|--------|----------|
| Directories | `hr/` | Confirmed from code |
| Main entry pages | `hr/dashboard.php`, payroll run pages | Confirmed from code |
| Main tables | `employees`, `attendance`, `payroll_*`, leave/overtime tables | Confirmed from schema artifact |
| Accounting | Dual-stack router in `hr/includes/hr_payroll_accounting.php`: cleaning → `gl_*`; else → `re_*` | Confirmed from code |
| Permissions | Module `hr` | Confirmed from code |
| Crons | `cron/document_reminders.php` | Confirmed from code |
| High-risk | Wrong `business_type` routes payroll to wrong GL family | Confirmed from code |

### 3.5 Finance (`MODULE_FINANCE`)

| Field | Detail | Evidence |
|-------|--------|----------|
| Directories | Primarily `accounts/` for Cleaning books | Confirmed from code |
| Entry | `/accounts/invoices` via `get_module_route()` | Confirmed from code |
| Note | Module access is shared across companies, but `/accounts` UI is Cleaning-standalone GL | Confirmed from code (`cleaning_accounting_context.php`) |
| RE/CO/ARS finance UIs | Live under each module’s `accounting/` or financial pages, not under `/accounts` | Confirmed from code |

### 3.6 Inventory (`MODULE_INVENTORY`)

| Field | Detail | Evidence |
|-------|--------|----------|
| Directories | `modules/inventory/`, `includes/inventory/` | Confirmed from code |
| Tables | `inv_*` | Confirmed from schema artifact |
| Accounting | Stock ledger (`inv_posting.php`) — no direct `gl_*` / `re_journal_*` posting found | Confirmed from code |
| Cross-module | Material requests from Cleaning, RE, Construction, ARS | Confirmed from code |

### 3.7 ARS (`MODULE_ARS`)

| Field | Detail | Evidence |
|-------|--------|----------|
| Directories | `modules/ars/`, portal `stay/` | Confirmed from code |
| Tables | `ars_*` | Confirmed from schema artifact |
| Accounting | `modules/ars/includes/ars_accounting.php` → shared RE engine | Confirmed from code |
| Company type | `business_type='short_term_rental'` | Confirmed from code |

### 3.8 Grocery / Barber / Legal / Tasks

| Module | Entry | Tables | GL | Notes | Evidence |
|--------|-------|--------|----|-------|----------|
| Grocery | `modules/grocery` | `pos_sales`, `inv_*` | No dedicated grocery GL found | POS over inventory | Confirmed from code |
| Barber | `modules/barber` | `barber_*` | Not found | Operational sales | Confirmed from code |
| Legal | `modules/legal/legal_dashboard.php` | `re_legal_*` | No dedicated GL; costs in `re_legal_case_costs` | Gated to RE companies | Confirmed from code |
| Tasks | `modules/tasks/tasks.php` → RE tasks | `re_tasks*` | N/A | Not in MODULE_* registry | Confirmed from code |

### 3.9 Portals and APIs

| Surface | Auth | Data | Evidence |
|---------|------|------|----------|
| `tenant_portal/` | Separate tenant session | Reads/writes RE + service requests | Confirmed from code |
| `stay/` | Guest portal auth | ARS bookings | Confirmed from code |
| `api/mobile/` | JWT / OTP / Firebase | Cleaning bookings, LTR listings | Confirmed from code |
| `api/customer/v1/` | JWT front controller | Stay/guest/tenant endpoints | Confirmed from code |
| `api/pos/` | POS API | POS sales | Confirmed from code |

---

## 4. Authentication and permissions (module-relevant)

| Mechanism | Path | Evidence |
|-----------|------|----------|
| Staff session | `includes/auth.php`, `login.php` | Confirmed from code |
| Company session | `includes/company_helper.php` → `current_company_id()` | Confirmed from code |
| Module gate | `require_module_access()` in `module_access.php` | Confirmed from code |
| Permissions | `includes/permissions.php` + `includes/permissions/*` | Confirmed from code |
| Department RBAC | `includes/rbac_department.php` | Confirmed from code |
| Worker route lock | `lib/Guard.php` | Confirmed from code |

---

## 5. Scheduled processes (module-linked)

| Area | Scripts | Evidence |
|------|---------|----------|
| Platform | `cron/cleanup_cache.php`, `document_reminders.php`, `process_scheduled_reports.php`, `send_outbox.php` | Confirmed from code |
| RE | Multiple `*_cron.php` under `modules/realestate/` | Confirmed from code |
| SM prepaid | `tools/sm_run_prepaid_cron.php` | Confirmed from code |
| Live crontab schedule | Not in repository | Needs business confirmation |

---

## 6. High-risk shared code (cross-module)

| File | Why high-risk | Evidence |
|------|---------------|----------|
| `modules/realestate/accounting/accounting_engine.php` | Shared by RE, Construction, ARS, HR (non-cleaning), ERP expenses | Confirmed from code |
| `includes/gl_posting.php` | Cleaning GL + cleaning payroll; no source-level duplicate refuse on create | Confirmed from code |
| `includes/company_helper.php` / `module_access.php` | Session company/module gating | Confirmed from code |
| `includes/inventory/inv_posting.php` | Cross-module stock | Confirmed from code |
| `includes/erp_expense_posting.php` | Multi-module expenses into shared RE GL | Confirmed from code |

---

## 7. Items requiring human confirmation

1. Actual production crontab entries for RE/SM/platform crons.  
2. Whether `modules/tasks` should be registered as a first-class MODULE_*.  
3. Whether Grocery/Barber sales are intentionally outside GL posting.  
4. Live DB vs snapshot drift (snapshot dated 2026-07-08 in `storage/schema_snapshots/`).  

---

## 8. Explicit non-changes

This document is documentation only. No PHP, SQL, config, or database objects were modified.
