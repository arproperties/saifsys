# Module Dependency Map

**Project:** HeroSysgro ERP  
**Audit stage:** Stage 1  
**Generated:** 2026-07-10  
**Scope:** Read-only. Dependencies inferred from `require`/`include` and call sites. No production changes.

## Evidence labels

Confirmed from code | Confirmed from schema artifact | Strong inference | Needs database confirmation | Needs business confirmation | Not found

---

## 1. Dependency overview

```text
Staff UI / Portals / APIs
        │
        ▼
includes/auth.php + company_helper + module_access + permissions
        │
        ├── Cleaning ops ──► accounts/gl_posting ──► gl_*
        ├── Real Estate ──► accounting_engine ──► re_*
        ├── Construction ──► accounting_engine ──► re_*  (same tables, co company_id)
        ├── ARS ──► ars_accounting ──► accounting_engine ──► re_*
        ├── HR payroll ──► gl_* OR re_* (by business_type)
        ├── Inventory ◄── material requests (Cleaning/RE/CO/ARS)
        ├── Legal ◄── RE leases/cheques
        ├── Grocery/Barber ──► POS / inv_* (no shared GL engine found)
        └── erp_expense_* ──► accounting_engine ──► re_*
```

---

## 2. Edge list (who depends on whom)

| From | To | Dependency type | Evidence |
|------|----|-----------------|----------|
| Cleaning ops (`operation/`) | Finance (`accounts/`) | WO finalize → invoice → `gl_post_invoice` | Confirmed from code |
| Cleaning | Inventory | Material request wrappers | Confirmed from code |
| Cleaning accounts | `includes/gl_posting.php` | Journal create/post/reverse | Confirmed from code |
| Cleaning accounts | `sm_*` services | Prepaid, recurring, expense posting | Confirmed from code |
| Real Estate | `accounting_engine.php` | All RE journals | Confirmed from code |
| Real Estate | `accounting_integration.php` | Domain post_* wrappers | Confirmed from code |
| Construction | `accounting_engine.php` | Via `construction_accounting_integration.php` | Confirmed from code |
| Construction | Inventory | Material requests | Confirmed from code |
| Construction | Shared COA UI patterns | `coa_ui` / RE chart pages patterns | Confirmed from code / Strong inference |
| ARS | `ars_accounting.php` → RE engine | Booking revenue/VAT journals | Confirmed from code |
| ARS | RE `chart_of_accounts.php` | Some ARS COA UI requires RE file | Confirmed from code |
| ARS | Inventory | Material requests | Confirmed from code |
| HR | `gl_posting.php` | When company `business_type=cleaning` | Confirmed from code |
| HR | `accounting_engine.php` | Non-cleaning companies | Confirmed from code |
| Legal | `re_legal_*` + RE cheques/leases | Case/cheque escalation data | Confirmed from code |
| Tasks UI | `modules/realestate/tasks.php` | Direct require wrapper | Confirmed from code |
| Tenant portal | RE payment/cash/email helpers | Portal payments & requests | Confirmed from code |
| Stay / customer API | ARS includes | Bookings & guest flows | Confirmed from code |
| Mobile cleaning API | `make_order` / workers | Bookings | Confirmed from code |
| Mobile LTR API | RE unit public listing | Long-term rentals | Confirmed from code |
| Grocery | Inventory POS (`pos_sales`, `inv_*`) | Thin UI | Confirmed from code |
| ERP expenses | RE accounting engine | Multi-module AP expenses | Confirmed from code |
| Finance MODULE access | All companies | Access flag shared; books UI still Cleaning-only | Confirmed from code |

---

## 3. Shared services catalogue

| Service / helper | Path | Used by | Risk | Evidence |
|------------------|------|---------|------|----------|
| Accounting engine | `modules/realestate/accounting/accounting_engine.php` | RE, CO, ARS, HR (non-cleaning), ERP expenses | Highest — shared ledger write path | Confirmed from code |
| Cleaning GL | `includes/gl_posting.php` | Cleaning accounts, cleaning payroll, SM | High — no create-time source dup refuse | Confirmed from code |
| Company helper | `includes/company_helper.php` | Nearly all staff pages | High — session company fallback patterns | Confirmed from code |
| Module access | `includes/module_access.php` | Module entry gates | High | Confirmed from code |
| Permissions | `includes/permissions.php` + packs | Granular ACL | Medium | Confirmed from code |
| Auth / CSRF | `includes/auth.php`, `includes/csrf.php` | Staff UI | High | Confirmed from code |
| Inventory posting | `includes/inventory/inv_posting.php` | Inventory + POS consumers | High for stock | Confirmed from code |
| ERP expense posting | `includes/erp_expense_posting.php` | Construction/ARS/RE expense UIs | High (shared GL) | Confirmed from code |
| Audit service | `includes/AuditService.php` / audit helpers | Cross-module audit_log | Medium | Confirmed from code |
| Email / outbox | `includes/email_*`, `cron/send_outbox.php` | Reminders, digests | Medium | Confirmed from code |
| Branding / URL | `includes/branding.php`, `url_helper.php` | Layouts | Low | Confirmed from code |

---

## 4. Shared UI components

| Pattern | Location | Consumers | Evidence |
|---------|----------|-----------|----------|
| Per-module layout header/footer | `modules/*/includes/*_layout_header.php` | RE, CO, ARS, grocery, barber, legal, inventory, tasks, HR | Confirmed from code |
| Root `includes/header.php` / `footer.php` | Empty files | Not the active chrome | Confirmed from code |
| Bootstrap 5.3.3 CDN + Icons | Module layouts | Most staff UIs | Confirmed from code |
| Branding CSS variables | `includes/branding.php` | Layouts | Confirmed from code |
| Cleaning accounts UI | `accounts/*` | Cleaning finance only | Confirmed from code |

---

## 5. Accounting dependency matrix

| Module | Posts to Cleaning `gl_*`? | Posts to Shared `re_*`? | Notes | Evidence |
|--------|---------------------------|-------------------------|-------|----------|
| Cleaning | Yes | No (by design) | `cleaning_accounting_context.php` | Confirmed from code |
| Real Estate | No | Yes | Primary owner of engine | Confirmed from code |
| Construction | No | Yes | Same tables, `company_id` | Confirmed from code |
| ARS | No | Yes | Via `ars_accounting.php` | Confirmed from code |
| HR | Conditional | Conditional | Exclusive routing by `business_type` — not dual-write | Confirmed from code |
| Inventory | Not found | Not found | Stock ledger only | Confirmed from code |
| Grocery | Not found | Not found | POS/stock | Confirmed from code |
| Barber | Not found | Not found | Operational sales | Confirmed from code |
| Legal | Not found | Not found as dedicated | Costs tables only | Confirmed from code |
| ERP expenses | No | Yes | Shared expense stack | Confirmed from code |

---

## 6. Permission dependencies

| Gate | Function / file | Applies to | Evidence |
|------|-----------------|------------|----------|
| Login | `require_login()` | Staff pages | Confirmed from code |
| Role | `require_role([...])` | Many accounts pages | Confirmed from code |
| Module | `require_module_access($conn, MODULE_*)` | Module roots | Confirmed from code |
| Permission | `require_permission()` / `has_permission()` | Granular features | Confirmed from code |
| Department | `rbac_department.php` | Dept-scoped menus/routes | Confirmed from code |
| Worker allowlist | `lib/Guard.php` | Field workers | Confirmed from code |
| Portal auth | `tenant_portal/includes/tenant_auth.php`, `stay/includes/portal_auth.php` | External portals | Confirmed from code |

**Strong inference:** Enforcement is per-page include discipline (no single front controller). New endpoints can omit gates if authors forget includes.

---

## 7. External integrations

| Integration | Path / area | Evidence |
|-------------|-------------|----------|
| Stripe (ARS) | ARS payments / customer API webhook paths | Confirmed from code |
| Firebase / push | Mobile auth & customer push endpoints | Confirmed from code |
| SMS | `api/mobile/sms_service.php` and related | Confirmed from code |
| Email SMTP | `includes/email_config.php` (secret type: SMTP credentials — values not documented) | Confirmed from code |
| WhatsApp stub | `integrations/` | Confirmed from code |
| mPDF / Dompdf | Composer vendor; RE contracts, invoices, slips | Confirmed from code |

---

## 8. Scheduled process dependencies

| Process | Depends on | Evidence |
|---------|------------|----------|
| RE reminder/collections/cheque crons | RE tables + email/outbox | Confirmed from code |
| SM prepaid cron | `sm_prepaid_*` + `gl_create_journal` | Confirmed from code |
| Document reminders | HR documents | Confirmed from code |
| Scheduled reports | `includes/scheduled_reports_service.php` | Confirmed from code |
| Live schedule | Server crontab | Needs business confirmation |

---

## 9. High-risk shared-code change impact

Changing any of these requires **cross-module impact analysis** before edit:

1. `accounting_engine.php` — RE + Construction + ARS + HR + ERP expenses  
2. `gl_posting.php` — Cleaning AR/AP + cleaning payroll + SM  
3. `company_helper.php` — every company-scoped page  
4. `module_access.php` — navigation and access gates  
5. `inv_posting.php` — stock for all MR/POS consumers  
6. `erp_expense_posting.php` — multi-module expenses into shared GL  

---

## 10. Contradictions / unknowns

| Item | Status |
|------|--------|
| Finance module access vs Cleaning-only `/accounts` books | Confirmed from code — access shared, books not |
| Tasks module exists but not in MODULE_* list | Confirmed from code |
| Parallel cheque tables in RE | Confirmed from schema artifact — business ownership Needs business confirmation |
| Whether live DB matches schema snapshot | Needs database confirmation |
| Grocery/Barber intentional GL omission | Needs business confirmation |

---

## 11. Explicit non-changes

Documentation only. No existing files or database objects modified.
