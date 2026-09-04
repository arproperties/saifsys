# Construction Financial — Production Deployment Checklist

| Field | Value |
|-------|-------|
| Scope | Construction **Suppliers/AP** (Phases 0–4) + **Quick Paid Expenses** |
| Status | **COMPLETE — APPROVED FOR PRODUCTION** (2026-07-27) |
| Module | Construction |
| Ledger | Shared `re_*` only (not Cleaning `gl_*`) |

Use this checklist on each target environment before go-live. Mark PASS/FAIL. Do not invent new features under these closed programs.

**Program docs**
- Suppliers/AP: [`CO_SUPPLIERS_AP_PROGRAM_COMPLETE.md`](CO_SUPPLIERS_AP_PROGRAM_COMPLETE.md)
- Quick Paid: [`CO_QUICK_PAID_EXPENSES_COMPLETE.md`](CO_QUICK_PAID_EXPENSES_COMPLETE.md)
- Contractor ↔ Supplier link: [`CO_CONTRACTOR_SUPPLIER_LINK_COMPLETE.md`](CO_CONTRACTOR_SUPPLIER_LINK_COMPLETE.md)
- BRs: [`CO_SUPPLIERS_AP.md`](../business-rules/CO_SUPPLIERS_AP.md), [`CO_QUICK_PAID_EXPENSES.md`](../business-rules/CO_QUICK_PAID_EXPENSES.md), [`CO_CONTRACTOR_SUPPLIER_LINK.md`](../business-rules/CO_CONTRACTOR_SUPPLIER_LINK.md)

---

## A. Pre-deploy

- [ ] Backup database  
- [ ] Confirm Construction company uses shared `re_*` ledger  
- [ ] Human approval to run migrations (ERP policy)  
- [ ] Deploy application code (Suppliers/AP Phases 0–4 + Quick Paid)  
- [ ] Apply migrations **in order**:
  1. `migrations/construction_supplier_ap_phase0.sql`
  2. `migrations/construction_supplier_ap_phase1_lifecycle.sql`
  3. `migrations/construction_supplier_ap_phase2_advances.sql`
  4. `migrations/construction_supplier_ap_phase3_advance_vat_refunds.sql`
  5. `migrations/construction_quick_paid_expenses.sql` (`legacy_archive` on existing Construction ERP expenses)
  6. `migrations/construction_contractor_supplier_link.sql` (`co_contractors.supplier_id` + retirement audit log)
  7. `migrations/construction_contractor_payment_retirement_mode.sql` (`retirement_mode` on audit log)
- [ ] Verify key objects: `co_supplier_ap_audit`, advance tables, advance VAT/refund tables, `erp_expense_headers.legacy_archive`  
- [ ] `uploads/construction/supplier_advance_vat/.htaccess` denies PHP execution  
- [ ] Construction COA: Payable **2110**, Input VAT **2130**, Advances **1410** (or configured), bank/cash settlement accounts  
- [ ] Setting `co_supplier_advance_account_code` present  
- [ ] Company context fail-closed on financial pages (no silent `company_id = 1`)

---

## B. Suppliers / AP functional smoke

- [ ] Supplier master + dashboard KPIs (Outstanding AP, Advances, Net Payable, VAT Pending, Open / Partially Paid)  
- [ ] Invoice draft → post (edit blocked when posted)  
- [ ] Payment allocate; overpay → advance  
- [ ] Apply advance; Advance VAT; link on draft invoice; remaining Input VAT on post  
- [ ] Refund blocked while Advance VAT posted; refund OK after VAT reverse  
- [ ] Void / Void+Amend; guarded payment reverse  
- [ ] VAT report, aging (Gross/Advances/Net), statement, ledger, project spend, timeline, diagnostics  
- [ ] Company isolation (cross-company ID access fails)

---

## C. Quick Paid Expenses functional smoke

- [ ] Create Quick Paid (Cash / Bank / Credit Card) with VAT → Dr Expense / Dr **2130** / Cr settlement  
- [ ] Accounts Payable settlement **rejected**  
- [ ] Optional project → appears in Project Cost reporting; blank project = overhead (not in project cost)  
- [ ] Optional supplier informational only — Outstanding AP / Advances unchanged; no Supplier Invoice created  
- [ ] Supplier profile shows informational Quick Paid section  
- [ ] Legacy Historical rows (`legacy_archive=1`) view-only; post/cancel/repost blocked; journals not rewritten  
- [ ] List filters: Project, Supplier, Expense Account, Payment Method, Date, Status  

---

## D. Shared-helper regression (required)

`includes/erp_expense_posting.php` is shared with Real Estate (and ARS).

- [ ] **Real Estate Quick Paid regression smoke** on an RE company after deploy:
  - [ ] Create/post RE Quick Paid (cash/bank/credit) succeeds  
  - [ ] RE still rejects `accounts_payable`  
  - [ ] RE Input VAT still resolves via RE preference (**2320** / **1260**, not forced to Construction 2130)  
  - [ ] RE list/edit still labeled Quick Paid Expenses  

---

## E. Contractor ↔ Supplier Business Partner smoke

- [ ] Main Contractor linked to Supplier (or Create Supplier from Contractor)  
- [ ] Supplier profile shows Linked Contractor, projects, contract value, Contractor Profile link  
- [ ] Contractor dashboard KPIs: Contract Value, Invoiced, Paid, Outstanding AP, Remaining, Billing %, Payment %  
- [ ] Project Payment CTA opens Supplier Payment Workspace with supplier + project defaults  
- [ ] `contractor_payment_add.php` POST returns 410 / redirects (no new rows)  
- [ ] Cost reports do not include legacy contractor payments  
- [ ] Admin tool lists historical payments; retire via Verified Match **or** Business Approved Legacy Retirement (staging first; bulk requires typed `RETIRE ALL LEGACY`)  
- [ ] After retirement: audit log rows present, contractor payment count = 0 (or reduced), TB still balances  

---

## F. Accounting spot-checks

- [ ] Trial Balance balances after smoke journals  
- [ ] No Construction postings into Cleaning `gl_*`  
- [ ] No Construction use of RE `vendor_ap_helper` / `vendor_advance_helper`  
- [ ] Quick Paid never posts to Supplier AP document tables (`co_supplier_*`)  
- [ ] Advances COA is asset **1410** (or override), not client advance **2410**  

---

## G. Sign-off

| Role | Name | Date | Result |
|------|------|------|--------|
| Technical | | | PASS / FAIL |
| Accounting / ops | | | PASS / FAIL |
| Product owner | | | PASS / FAIL |

**Go-live decision:** ________________ (GO / NO-GO)

---

## Closed programs — no further work

| Program | Status |
|---------|--------|
| Construction Suppliers/AP uplift | COMPLETE — APPROVED |
| Construction Quick Paid Expenses | COMPLETE — APPROVED |
| Contractor ↔ Supplier Business Partner link | IMPLEMENTED — use checklist §E before production retirement of historical payments |

No additional features unless new business requirements or operational feedback arise.
