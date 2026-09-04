# Phase 2A — Bank Reconciliation & Reporting Blueprint

**Status:** Design only  
**Principle:** ARS must appear in the **same** RE shared financial environment (DEC-015) once posting company is Confirmed (BC-01). Do not fork a third GL.

---

## 1. Bank reconciliation integration

### Current RE entry points (Confirmed paths)

- UI: `modules/realestate/accounting/bank_reconciliation.php` (+ related screens)
- Core: `modules/realestate/includes/re_bank_reco_*.php`
- Ajax: `modules/realestate/accounting/ajax/re_bank_reco_*.php`

### How ARS becomes visible

Bank reco matches **bank statement lines** to **GL cash/bank movements** (journals), not to ARS screens directly.

| Channel | Today | Phase 2B target |
|---------|-------|-----------------|
| Cash/bank staff payment | JV DR 1110/1210 | Same; reference includes booking number in description/reference |
| Stripe | Often no GL | Clearing then bank per BC-12/13 |
| Deposit | JV to 1110/1210 | Same |

**Adapter requirement:** journal `description` / line `reference` must include `booking_number` and stable `document_number` for searchability.

**Filtering bank reco:** by company (BC-01 entity). Optional future: tag `source_module=ars` in journal meta if engine supports additive reference fields without signature change — prefer description/reference until Confirmed.

**Does not require** modifying reco engine core for Phase 2B MVP if ARS posts standard cash/bank journals into the shared company.

---

## 2. Official financial reports (shared RE stack)

| Report | ARS appearance | Filter dimensions |
|--------|----------------|-------------------|
| Trial Balance | Via posted journals in posting company | company, date, account |
| P&L | Revenue 4100 (+ fees/damage income if Confirmed) | company, period; optional booking via drill |
| Balance Sheet | AR 1310, VAT 2310, Deposit 2200, Deferred 2400 if used | company, as-of |
| VAT reports | Output VAT lines from HH invoices | company, period |
| GL | `general_ledger.php` / journal view | account, journal, date |

**Phase 2B:** Prefer **report filters / saved views** labeled Holiday Homes rather than forking report engines. Drill-down: journal → Activity Center / booking.

---

## 3. ARS operational–financial reports (module level)

| Report | Source | Notes |
|--------|--------|-------|
| Guest statement | Docs + payments + allocations | New in 2B or extend guest_view |
| Booking statement | Booking + child docs | Activity Center + document list |
| Outstanding receivables | Sum `ars_financial_documents.balance_due` where posted | By guest/booking |
| Deposit liability | 2200 GL + `ars_security_deposits` open | Reconcile subledger to GL |
| Holiday Homes revenue | 4100 movements with ARS references | Period |
| Refund report | `ars_refunds` + deposit refunds | |
| Stripe clearing report | Clearing account movements + Stripe payment rows | Needs BC-12 |

---

## 4. Standard filter dimensions

Every ARS financial list/report should support:

| Dimension | Implementation |
|-----------|----------------|
| `source_module` | Constant `ars` on docs / description tag |
| `booking_id` / `booking_number` | FK / indexed |
| `guest_id` | On document header |
| `property` / `unit` | Via booking → `re_units` / buildings |
| `company_id` | Mandatory scope |
| `payment_channel` | cash / bank / card / stripe |
| `document_type` | Enum on financial documents |

**Security:** All queries fail closed on `company_id`; no cross-company SUM by id alone.

---

## 5. Mobile / customer API reporting

- Guest booking detail continues to expose `payments` + `security_deposit` shapes.  
- Future additive keys (e.g. `financial_documents[]`) only after product approval.  
- Do not expose internal lock fields unless product Confirmed.

---

## 6. Verification checklist (Phase 2B)

- [ ] Sample ARS payment appears in bank reco candidate set for posting company  
- [ ] TB/P&L/BS move when confirm+pay  
- [ ] Deposit liability matches open deposits  
- [ ] VAT report includes ARS output VAT  
- [ ] No Cleaning `gl_*` contamination  
- [ ] Engine file unchanged  

---

## 7. Open items

- BC-01 posting company vs RE bank account company alignment  
- BC-12/13 Stripe clearing accounts  
- Whether journal headers gain a formal `source_module` column (engine change → human approval)
