# Contractor ↔ Supplier — Production Verification Report

| Field | Value |
|-------|-------|
| Status | **NOT PRODUCTION COMPLETE** |
| Environment | Local XAMPP / company_id **3** |
| Date | 2026-07-27 |
| Ledger | Shared `re_*` |
| Canvas | `canvases/contractor-supplier-production-verification.canvas.tsx` |

## Verdict

Read-path architecture (Business Partner link, dashboard KPIs, project redirects, reports excluding legacy contractor payments, Supplier/AP regression) **passes**.

**Retirement cannot be certified:** all **11** historical `co_contractor_payments` have **no** matching Supplier invoice or payment by amount. Mass reverse/delete was **not** executed. Do not mark Production Complete until matching (or explicit business sign-off to retire unmatched journals totaling **AED 11,808,500**) is resolved.

---

## Smoke matrix summary

| Result | Count |
|--------|------:|
| PASS | 27 |
| FAIL | 2 |
| BLOCKED | 4 |

### FAIL items
1. Bab Alyaqoot contractor still unlinked to a Supplier.
2. 0/11 Contractor Payments match any Supplier/AP document.

### BLOCKED items
Reverse journals, delete rows, audit log population, post-retirement TB — all blocked by match failure.

---

## 1. Business Partner Link

| Check | Result | Evidence |
|-------|--------|----------|
| `supplier_id` column + unique + retirement log | PASS | Migration applied |
| Contractor → Supplier | PASS | Contractor #1 → Supplier #38 |
| Supplier → Contractor | PASS | Reverse helper returns contractor #1 |
| Cross-company link | PASS | Rejected: “Supplier not found in this company.” |
| Duplicate supplier link | PASS | Rejected: already linked to another contractor |
| All contractors linked | FAIL | Contractor #2 Bab Alyaqoot unlinked |

---

## 2. Contractor Dashboard KPIs (Aladeeb #1)

Independent SQL vs `co_contractor_commercial_progress()`:

| KPI | Helper | SQL | Result |
|-----|--------|-----|--------|
| Contract Value | 26,219,725.00 | SUM(contract_value)=26,219,725.00 | PASS |
| Total Invoiced | 0.00 | SUM(subtotal) supplier 38 = 0 | PASS |
| Total Paid | 0.00 | SUM(payments) supplier 38 = 0 | PASS |
| Outstanding AP | 0.00 | `co_supplier_outstanding_ap` = 0 | PASS |
| Remaining Contract Value | 26,219,725.00 | contract − invoiced | PASS |
| Billing Progress % | 0.0 | 0 | PASS |
| Payment Progress % | 0.0 | 0 | PASS |

Note: Supplier #38 currently has **zero** invoices/payments in company 3, so commercial progress AP side is empty while contract value is live from operational assignments.

---

## 3. Project View

| Check | Result | Evidence |
|-------|--------|----------|
| Linked Main Payment CTA | PASS | `supplier_payment_add.php?supplier_id=38&project_id=1` |
| Unlinked Payment CTA | PASS | Error: link supplier first (Bab Alyaqoot) |
| Project-scoped AP strip | PASS | Same helpers as dashboard |

---

## 4. Supplier Profile

| Check | Result | Evidence |
|-------|--------|----------|
| Linked Contractor panel | PASS | Shows Aladeeb, projects, contract value, profile link |

---

## 5. Reports

Legacy contractor payment gross total excluded from cost unions: **11,808,500.00**.

| Report | Result |
|--------|--------|
| Contractor Summary (AP helpers) | PASS |
| Project Cost Summary (no CP query) | PASS |
| Budget vs Actual (no CP query) | PASS |
| Project Profitability (no CP query) | PASS |
| Project Cost Detail (no CP query) | PASS |

---

## 6. Retirement Tool

Two workflows are available:

| Mode | Match required? | Controls |
|------|-----------------|----------|
| Verified Match | Yes (Supplier/AP note) | Per-row confirm |
| Business Approved Legacy Retirement | **No** | Admin confirmations + reason ≥15 chars; single or bulk (`RETIRE ALL LEGACY`) |

| Pay # | Amount | Journal | Supplier/AP match | Action taken |
|------:|-------:|--------:|-------------------|--------------|
| 1–2 | 600,000 each | none | NONE | Pending Legacy or Verified workflow |
| 3 | 600,000 | 272 | NONE | Pending |
| 4–10 | various | 304–310 | NONE | Pending |
| 11 | 9,450,000 | 1674 | NONE | Pending |

Company 3 Supplier Payments inventory: **4** rows (largest AED 1,575). No fuzzy amount match to any Contractor Payment (gross or net).

**Pre-retirement Trial Balance (company 3, posted, not reversed):** Dr **14,979,773.98** = Cr **14,979,773.98** (PASS).

Legacy Retirement was **not** executed in the verification pass pending administrator use of the enhanced tool with a mandatory business reason.

---

## 7. Regression

| Area | Result | Evidence |
|------|--------|----------|
| Supplier/AP | PASS | 54 open/partial invoices; outstanding amounts resolve |
| Advances | PASS | `co_supplier_advance_balances` supplier #1 = 100.00 |
| Advance VAT | PASS | 2 documents (posted + reversed) |
| Quick Paid | PASS | Active + `legacy_archive` rows present |
| Bank reco | PASS | `co_contractor_payment` source mapping retained |
| Old payment create | PASS | POST path returns 410 |

---

## Required before Production Complete

1. **Administrator** runs Business Approved Legacy Retirement (bulk or per-row) with mandatory reason, **or** Verified Match where counterparts exist.  
2. Link **Bab Alyaqoot** to a Supplier.  
3. Confirm audit log + TB after retirement.  
4. (Recommended) Create Supplier invoices for Main Contractors so dashboard Invoiced/Paid reflect real progress.

Until steps 1–3 are done, status remains **NOT PRODUCTION COMPLETE**.
