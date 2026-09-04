# Database Architecture Audit

**Project:** HeroSysgro ERP  
**Audit stage:** Stage 1  
**Generated:** 2026-07-10  
**Scope:** Schema artifacts only. **No live MySQL connection. No schema-sync, migration, repair, or posting scripts were run.**

## Evidence labels

Confirmed from code | Confirmed from schema artifact | Strong inference | Needs database confirmation | Needs business confirmation | Not found

## Artifact sources (read-only)

| Artifact | Path | Notes |
|----------|------|-------|
| Schema snapshot | `storage/schema_snapshots/schema_snapshot_local.json` | Exported ~2026-07-08, DB name `datanew` |
| Views SQL | `database/views/erp_schema_views.sql` | Canonical `v_*` definitions |
| Migrations | `migrations/*.sql` | Incremental DDL/seeds |
| Dumps | `bestsys.sql`, `localhost_database_structure.sql`, `live_database_structure.sql`, `backups/*.sql` | Historical; may lag |
| Sync docs | `docs/database/DATABASE_STRUCTURE_SYNC.md` | Operator guide — **not executed** |

---

## 1. Main schemas and table groups

Snapshot summary (Confirmed from schema artifact): ~450 tables, ~30 views, **0 triggers**, **0 procedures/functions** reported (routine introspection warning on MariaDB), **0 events** (scheduler disabled in snapshot meta).

| Prefix / group | Approx count | Role |
|----------------|-------------:|------|
| `re_*` | ~171 | Real estate ops + shared GL + legal + tasks |
| `co_*` | ~50 | Construction |
| `inv_*` | ~24 | Inventory |
| `ars_*` | ~16 | Short-term rental |
| `sm_*` | ~12 | Cleaning service-management finance |
| `gl_*` | 3 | Cleaning journals / balances |
| `erp_*` | ~4 | Shared multi-module expenses |
| `barber_*` | ~5 | Barber POS |
| `cleaning_bank_*` | several | Cleaning bank reco |
| HR / payroll / leave / overtime | ~40+ | HR |
| Unprefixed cleaning core | large | `make_order`, `client`, `invoices`, `receipts`, `workers`, etc. |
| Auth / RBAC | several | `user`, `roles`, `user_roles`, `user_companies`, `companies`, `role_*` |

---

## 2. Financial control tables

### Cleaning stack

| Table | Role | Evidence |
|-------|------|----------|
| `chart_of_accounts` | COA | Confirmed from schema artifact |
| `gl_journals` | Journal headers (`source`, `source_id`, optional `company_id`) | Confirmed from schema artifact / code |
| `gl_journal_lines` | Lines (no `company_id`; via parent) | Confirmed from schema artifact |
| `gl_account_balances` | Period balances | Confirmed from schema artifact |
| `invoices`, `invoice_items` / lines | AR | Confirmed from schema artifact |
| `receipts`, `receipt_allocations` | Cash application | Confirmed from schema artifact |
| `credit_notes*` | Credit notes | Confirmed from schema artifact |
| `expenses`, `sm_prepaid_*`, `sm_recurring_*` | Expenses / prepaid / recurring | Confirmed from schema artifact |

### Shared stack (RE / Construction / ARS / non-cleaning payroll)

| Table | Role | Evidence |
|-------|------|----------|
| `re_chart_of_accounts` | Per-company COA | Confirmed from schema artifact |
| `re_journal_headers` | Journals; `company_id NOT NULL` | Confirmed from schema artifact |
| `re_journal_lines` | Lines | Confirmed from schema artifact |
| `re_general_ledger` | Posted GL; bank reco flags | Confirmed from schema artifact / code |
| `re_journal_sequences` | Numbering | Confirmed from schema artifact |
| `re_fiscal_years` | Period lock | Confirmed from code |
| `re_accounting_audit_log` | Accounting audit | Confirmed from code |
| `re_invoices`, `re_payments`, `re_receipt_allocations`, etc. | RE subledger | Confirmed from schema artifact |
| `co_supplier_invoices`, `co_supplier_payments`, etc. | Construction AP/AR | Confirmed from schema artifact |
| `payroll_runs` (+ journal id columns) | Payroll link to either stack | Confirmed from code |

### Unique keys relevant to posting

| Key | Status | Evidence |
|-----|--------|----------|
| `(company_id, journal_number)` on `re_journal_headers` | Present | Confirmed from schema artifact |
| `(company_id, reference_type, reference_id)` | **Not enforced** — optional unique commented in `migrations/accounting_critical_controls.sql` | Confirmed from schema artifact |
| Cleaning `(source, source_id)` unique | **Not found** as DB constraint | Not found / Strong inference |

---

## 3. Primary / foreign-key relationships

- Snapshot reports ~84 foreign keys. Confirmed from schema artifact.
- Examples (Confirmed from schema artifact / migrations):
  - Construction headers → `companies`, journals
  - `re_obligations` → lease/tenant/journals; unique `uq_re_obligations_company_key`
  - Barber lines → sale header
  - Cleaning bank lines → bank accounts / COA
- Many line/child tables omit `company_id` and rely on parent FK. Confirmed from schema artifact.

**Needs database confirmation:** FK enforcement mode (ON DELETE behaviour) for all financial children on live.

---

## 4. Important indexes

High-churn / financial indexes observed in snapshot (Confirmed from schema artifact):

- `gl_journals` — company/date/source style indexes  
- `make_order` — client/date/status/ars_booking  
- `re_obligations`, `re_journal_*`  
- `audit_log`, `outbox_messages`  

**Strong inference:** High-volume tables benefit from these indexes; missing composite unique on journal references is a control gap, not a performance gap alone.

---

## 5. Views

| View family | Purpose | Evidence |
|-------------|---------|----------|
| `v_ar_*` / ageing / open invoices | Cleaning AR reporting | Confirmed from schema artifact (`database/views/erp_schema_views.sql`) |
| Attendance / workers / P&L / trial balance views | Operational & financial | Confirmed from schema artifact |

**Risk:** Classic vs `*_optimized` AR views may diverge in balance formulas. Confirmed from schema artifact / Strong inference.  
**Risk:** Mis-imported dumps can materialize `v_*` as tables — repair tooling documented in `DATABASE_STRUCTURE_SYNC.md` (not run). Confirmed from code/docs.

---

## 6. Triggers

**Not found** in snapshot (0 triggers) and no `CREATE TRIGGER` found in migrations search during audit. Confirmed from schema artifact / Not found.

---

## 7. Stored procedures / functions / events

| Object | Status | Evidence |
|--------|--------|----------|
| Procedures/functions in snapshot | 0 (introspection warning) | Confirmed from schema artifact |
| Cache helper procedures in migrations | Present in `migrations/optimize_database_performance*.sql` | Confirmed from schema artifact |
| Runtime CALL usage in app PHP | Little evidence | Strong inference / Needs database confirmation |
| Scheduled DB events | 0 in snapshot (scheduler disabled) | Confirmed from schema artifact |

---

## 8. Audit tables

| Table | Domain | Evidence |
|-------|--------|----------|
| `audit_log` | Generic app audit | Confirmed from schema artifact |
| `re_accounting_audit_log` | Shared engine | Confirmed from code |
| `re_cheque_lifecycle_audit` | PDC | Confirmed from code |
| `re_security_deposit_audit` | Deposits | Confirmed from code |
| `re_bank_reconciliation_audit` / `co_bank_reconciliation_audit` | Bank reco | Confirmed from code |
| `re_lease_payment_schedule_audit` / mode audits | Lease accounting modes | Confirmed from schema artifact |
| `order_audit` | Cleaning orders | Confirmed from schema artifact |

---

## 9. Configuration tables

| Table / area | Role | Evidence |
|--------------|------|----------|
| `companies`, `company_settings` | Multi-company | Confirmed from schema artifact |
| `re_vat_config` | RE VAT | Confirmed from code |
| `payroll_account_settings` | Payroll accounts | Confirmed from code |
| `re_bank_reco_settings` / CO bank rules | Bank reco | Confirmed from code |
| Branding / invoice templates | Settings UI | Confirmed from code |

Secrets live in PHP config files (DB credentials, SMTP, JWT) — **values not documented**; paths only: `includes/config.php`, `includes/email_config.php`, `api/mobile/config.php`, `includes/customer_api.php`. Confirmed from code.

---

## 10. Company-isolation fields

| Has `company_id` | Missing on child / legacy | Evidence |
|------------------|---------------------------|----------|
| `gl_journals`, `chart_of_accounts`, `invoices`, `receipts`, `expenses`, `make_order` | `gl_journal_lines`, `gl_account_balances`, `receipt_allocations`, `order_payment`, invoice line tables | Confirmed from schema artifact |
| `re_journal_headers/lines`, `re_invoices`, `re_payments`, most `co_*` headers, `erp_expense_headers`, most `inv_*`, `pos_sales`, `ars_bookings`, `payroll_runs`, `employees` | `barber_sale_lines`, `payroll_items`, some SM line tables | Confirmed from schema artifact |

**Older dump lag:** `live_database_structure.sql` may lack `company_id` on tables that newer backups/migrations include. Confirmed from schema artifact / Strong inference.  
**Needs database confirmation:** Live production column set vs snapshot.

---

## 11. Soft-delete behaviour

| Pattern | Where | Evidence |
|---------|-------|----------|
| `deleted_at` | `re_leases`, `re_legal_cases` | Confirmed from schema artifact / code |
| `is_active` | Master data (users, companies, categories, etc.) | Confirmed from schema artifact |
| `is_reversed` | Journals | Confirmed from code |
| Hard delete | Still used in some financial delete tools | Confirmed from code |

No universal soft-delete convention. Confirmed from code.

---

## 12. Status columns

Common patterns (Confirmed from schema artifact / code):

- ENUM / string lifecycles: `make_order.status`, lease statuses, invoice statuses, cheque statuses, adjustment statuses  
- Payment status: unpaid / partially_paid / paid / refunded  
- Journal: `is_posted`, `is_reversed`  
- Boolean `is_active` for masters  

---

## 13. High-volume tables (auto-increment proxy from snapshot)

Approximate ordering from snapshot AI values (Confirmed from schema artifact; live may differ):

1. `audit_log`  
2. `outbox_messages` / `order_audit` / `dashboard_cache`  
3. `gl_journal_lines`  
4. `client`  
5. `re_obligations` / `gl_journals` / `re_journal_lines`  
6. `make_order`  
7. Cheque tables  

**Needs database confirmation:** Live row counts and growth rates.

---

## 14. Tables referenced by multiple modules

| Table / family | Modules | Evidence |
|----------------|---------|----------|
| `re_chart_of_accounts`, `re_journal_*`, `re_general_ledger` | RE, Construction, ARS, HR (non-cleaning), ERP expenses | Confirmed from code |
| `inv_*`, `inv_request_*` | Inventory + Cleaning/RE/CO/ARS MR | Confirmed from code |
| `erp_expense_*` | Construction / ARS / RE expense UIs | Confirmed from code |
| `pos_sales` | Grocery / inventory POS | Confirmed from code |
| `employees` / payroll | HR + cleaning worker linkage | Confirmed from code |
| `user` / `user_companies` / RBAC | All staff modules | Confirmed from code |
| `make_order` | Cleaning + ARS booking link index | Confirmed from schema artifact |

---

## 15. Orphan-record risks

| Risk | Basis | Evidence |
|------|-------|----------|
| Journal without source doc | Soft source links; deletes may reverse or leave orphans | Strong inference |
| Source doc without journal | Legacy PDC clear without GL; contractor payment GL failure | Confirmed from code |
| Parallel cheque stores | `re_lease_cheques` vs `re_post_dated_cheques` | Confirmed from schema artifact |
| Cleaning payment duality | `order_payment` (no company_id) vs `receipts` | Confirmed from schema artifact |
| Orphan cleanup tooling | `re_orphan_accounting_cleanup_audit` exists | Confirmed from schema artifact |
| Line tables without company_id | Orphans if parent deleted without cascade | Strong inference |

---

## 16. Duplicate-record risks

| Risk | Basis | Evidence |
|------|-------|----------|
| Duplicate shared journals for same reference | No DB unique; race vs app check | Confirmed from schema artifact / code |
| Duplicate Cleaning invoice/receipt journals | `gl_create_journal` no source refuse; repair tools exist | Confirmed from code |
| Duplicate AR view formulas | classic vs optimized views | Confirmed from schema artifact |
| Credit notes with `reference_id=0` | Many journals share weak key | Confirmed from code |

---

## 17. Migration risks

| Risk | Detail | Evidence |
|------|--------|----------|
| Incremental SQL without single installer | `migrations/` + PHP apply tools | Confirmed from code |
| Dump lag | `live_database_structure.sql` older than multi-company reality | Confirmed from schema artifact / Strong inference |
| `v_*` table misclassification | Documented repair path | Confirmed from docs |
| Running sync/apply tools | Can alter live structure — **out of scope; do not run without approval** | Confirmed from docs |
| Commented unique constraints | `uq_company_reference` not applied | Confirmed from schema artifact |

### Migration tooling paths (describe only — not run)

| Path | Purpose |
|------|---------|
| `migrations/*.sql` | Incremental DDL |
| `database/export_schema_snapshot.php` | Export snapshot JSON |
| `database/run_database_structure_sync.php` | Align structure to snapshot |
| `database/fix_v_prefix_views.php` | Repair `v_*` |
| `tools/sm_apply_*_schema.php` | SM phase appliers |
| `tools/db_schema_sync.php` | Alternate sync |
| `includes/database_structure_sync.php` | Sync library |

---

## 18. Soft conclusions for Stage 1

1. Database is a **single MySQL schema** with multi-company rows, not separate DBs per company.  
2. Financial truth is split across **`gl_*` and `re_*`**.  
3. Isolation depends heavily on application filters; child tables often inherit company via parent.  
4. Triggers are absent; controls are application-level.  
5. Highest structural control gap: **missing unique constraint on shared journal source references**.

---

## 19. Items requiring human / database confirmation

1. Live table/column parity vs `schema_snapshot_local.json`.  
2. Whether performance SPs from migrations exist and are used on live.  
3. FK ON DELETE behaviours for financial children.  
4. Whether commented `uq_company_reference` should ever be enabled (business + DBA decision).  
5. Live row counts for capacity planning.

---

## 20. Explicit non-changes

No SQL executed against any database. No migrations applied. No sync/repair/cleanup/posting scripts run. Documentation only.
