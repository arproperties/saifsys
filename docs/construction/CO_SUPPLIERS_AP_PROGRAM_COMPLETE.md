# Construction Suppliers / AP Uplift — Program Complete

| Field | Value |
|-------|-------|
| Program | Construction Suppliers/AP maturity (Phases 0–4) |
| Status | **COMPLETE — APPROVED FOR PRODUCTION** |
| Approval date | 2026-07-27 |
| Module | Construction |
| Ledger | Shared `re_*` via Construction wrappers only (DEC-002) |
| Benchmark | Real Estate Vendor/AP patterns (adapted, not copied) |

---

## Final state

Construction Suppliers/AP is production-ready at RE-comparable functional maturity for:

- Supplier master, invoices (lines/VAT/project), payments + allocations  
- Formal invoice lifecycle (Draft → Posted → Partially Paid → Paid → Voided) with Void / Void+Amend  
- Supplier advances (asset, default **1410**, configurable)  
- Advance VAT documents + draft-invoice links + VAT report netting  
- Advance refunds (blocked while Advance VAT still posted)  
- Dashboard KPIs, financial timeline, project spend, printable statement, ledger, aging, diagnostics  
- Dedicated AP audit (`co_supplier_ap_audit`)  

**Confirmed business rules:** [`docs/business-rules/CO_SUPPLIERS_AP.md`](../business-rules/CO_SUPPLIERS_AP.md)

**Explicitly out of scope (deferred):** credit/debit notes, PO/GRN/3-way match, maker-checker thresholds, contractor retention merge, ERP expense merge into AP, Cleaning `gl_*`.

**No further implementation** for this program unless driven by new business requirements or operational feedback.

**Related closed program:** Construction Quick Paid Expenses — [`CO_QUICK_PAID_EXPENSES_COMPLETE.md`](CO_QUICK_PAID_EXPENSES_COMPLETE.md).  

**Combined production deployment checklist:** [`CO_FINANCIAL_PRODUCTION_DEPLOYMENT_CHECKLIST.md`](CO_FINANCIAL_PRODUCTION_DEPLOYMENT_CHECKLIST.md) (Suppliers/AP + Quick Paid + RE regression).

---

## Migrations (apply in order)

| Order | File |
|-------|------|
| 1 | `migrations/construction_supplier_ap_phase0.sql` |
| 2 | `migrations/construction_supplier_ap_phase1_lifecycle.sql` |
| 3 | `migrations/construction_supplier_ap_phase2_advances.sql` |
| 4 | `migrations/construction_supplier_ap_phase3_advance_vat_refunds.sql` |

Phase 4 is code/docs only (no DDL).

For Combined Construction Financial go-live (including Quick Paid), also apply `migrations/construction_quick_paid_expenses.sql` and use [`CO_FINANCIAL_PRODUCTION_DEPLOYMENT_CHECKLIST.md`](CO_FINANCIAL_PRODUCTION_DEPLOYMENT_CHECKLIST.md).

---

## Primary code map

| Area | Path |
|------|------|
| AP / lifecycle / audit | `modules/construction/includes/construction_supplier_ap_helpers.php` |
| Advances | `modules/construction/includes/construction_supplier_advance_helpers.php` |
| Advance VAT | `modules/construction/includes/construction_supplier_advance_vat_helpers.php` |
| Refunds | `modules/construction/includes/construction_supplier_advance_refund_helpers.php` |
| Dashboard / timeline / statement | `modules/construction/includes/construction_supplier_reporting_helpers.php` |
| GL posting | `modules/construction/includes/construction_accounting_integration.php` (`co_post_supplier_*`) |
| Supplier dashboard | `modules/construction/supplier_view.php` |
| Setup advance COA | Construction Setup Accounts (`co_supplier_advance_account_code`) |

---

## Locked COA (Construction)

| Purpose | Code | Notes |
|---------|------|--------|
| Supplier Payable | **2110** | Do not use RE 2130 for AP |
| Input VAT | **2130** | Do not use RE 2320 |
| Supplier Advances (asset) | **1410** default | `settings.co_supplier_advance_account_code`; not **2410** |

---

## Production verification checklist

Prefer the combined Construction Financial checklist for go-live:

**[`CO_FINANCIAL_PRODUCTION_DEPLOYMENT_CHECKLIST.md`](CO_FINANCIAL_PRODUCTION_DEPLOYMENT_CHECKLIST.md)**  
(covers Suppliers/AP + Quick Paid + RE shared-helper regression)

Suppliers/AP-only extracts below remain valid for AP-focused verification.

### A. Pre-deploy

- [ ] Backup database  
- [ ] Confirm Construction company `business_type` uses shared `re_*` ledger (not Cleaning `gl_*`)  
- [ ] Confirm human approval to run the four migrations (ERP policy)  
- [ ] Deploy application code containing Phases 0–4  
- [ ] Apply migrations **0 → 1 → 2 → 3** in order; verify tables exist:
  - [ ] `co_supplier_ap_audit`
  - [ ] Invoice lifecycle columns (`status`, `voided_at`, `amended_from_invoice_id`, …)
  - [ ] `co_supplier_advance_balances`, `co_supplier_advance_applications`, `co_supplier_payments.advance_amount`
  - [ ] `co_supplier_advance_vat_documents`, `_attachments`, `_invoice_links`, `co_supplier_advance_refunds`
- [ ] Confirm `uploads/construction/supplier_advance_vat/.htaccess` denies PHP execution  
- [ ] In Construction Setup Accounts: Payable **2110**, Input VAT **2130**, Advances code present (default **1410**) and `co_supplier_advance_account_code` set  
- [ ] Smoke company context: fail-closed if no company selected (no silent `company_id = 1`)

### B. Functional smoke (one Construction company)

- [ ] **Supplier master** — create/view/edit; dashboard KPIs load  
- [ ] **Invoice draft** — create, edit, delete OK  
- [ ] **Invoice post** — GL Dr expense(+VAT) / Cr 2110; status Posted; edit blocked  
- [ ] **Payment** — allocate to invoice; GL Dr 2110 / Cr bank; status → Partial/Paid  
- [ ] **Overpay → advance** — remainder posts Dr Advances / Cr bank; dashboard Advance Balance increases  
- [ ] **Apply advance** — Dr 2110 / Cr Advances; invoice balance falls; timeline shows Advance Applied  
- [ ] **Advance VAT** — draft → post (Dr 2130 / Cr Advances); refund blocked while posted  
- [ ] **Link Advance VAT** on draft invoice → post invoice with **remaining** Input VAT only; AP credit = total − linked VAT  
- [ ] **Refund** — after reversing Advance VAT if needed; Dr bank / Cr Advances  
- [ ] **Void / Void+Amend** — unpaid posted invoice only; amend creates new draft  
- [ ] **Payment reverse** — blocked if advance applications / posted VAT / refunds remain  
- [ ] **VAT report** — includes Advance VAT; supplier invoice VAT net of links  
- [ ] **Aging** — posted only; Gross / Advances / Net sensible  
- [ ] **Statement / Ledger / Project spend** — generate + print/export  
- [ ] **Timeline** — shows Created / Posted / Payment / Advance / VAT / Applied / Refund / Void / Amend as applicable  
- [ ] **Diagnostics** — `reports/supplier_ap_diagnostics.php` reviewed for smoke company  
- [ ] **Company isolation** — second company cannot see first company’s supplier documents by ID  

### C. Accounting spot-checks

- [ ] Trial Balance still balances after smoke journals  
- [ ] No postings into Cleaning `gl_*` for Construction company  
- [ ] No use of RE `re_vendors` / `vendor_ap_helper` / `vendor_advance_helper`  
- [ ] Advance account is asset **1410** (or configured override), not client advance **2410**  

### D. Sign-off

| Role | Name | Date | Result |
|------|------|------|--------|
| Technical | | | PASS / FAIL |
| Accounting / ops | | | PASS / FAIL |
| Product owner | | | PASS / FAIL |

**Go-live decision:** ________________ (GO / NO-GO)

---

## Related documents

- Plan (historical): Cursor plan `co_suppliers_ap_audit` — all phases completed  
- Business rules: [`CO_SUPPLIERS_AP.md`](../business-rules/CO_SUPPLIERS_AP.md)  
- Accounting architecture §7.9: [`ACCOUNTING_ARCHITECTURE.md`](../cursor-audit/ACCOUNTING_ARCHITECTURE.md)  
- Earlier recommendation (superseded as roadmap): [`modules/construction/EXPENSES_SUPPLIERS_VAT_RECOMMENDATION.md`](../../modules/construction/EXPENSES_SUPPLIERS_VAT_RECOMMENDATION.md)  
- Combined deploy checklist: [`CO_FINANCIAL_PRODUCTION_DEPLOYMENT_CHECKLIST.md`](CO_FINANCIAL_PRODUCTION_DEPLOYMENT_CHECKLIST.md)  
- Quick Paid (closed): [`CO_QUICK_PAID_EXPENSES_COMPLETE.md`](CO_QUICK_PAID_EXPENSES_COMPLETE.md)  
