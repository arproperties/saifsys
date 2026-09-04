# Construction Contractor ↔ Supplier Business Partner Link

| Field | Value |
|-------|-------|
| Program | Contractor ↔ Supplier/AP integration |
| Status | **Confirmed** (product owner approval 2026-07-27) |
| Module | Construction |
| Ledger | Shared `re_*` via existing Supplier/AP wrappers only |
| Related | [`CO_SUPPLIERS_AP.md`](CO_SUPPLIERS_AP.md) |

Do not invent additional commercial rules beyond this document. Capture new policy via the Business Rule Capture Standard.

---

## BR-CO-BP-001 — Separate entities, linked Business Partner

| Field | Value |
|-------|-------|
| Rule ID | BR-CO-BP-001 |
| Status | **Confirmed** |
| Confirmed rule | `co_contractors` remains the operational entity. `co_suppliers` remains the accounting entity. They are linked by nullable `co_contractors.supplier_id` (company-scoped). Do **not** merge tables. |
| Date | 2026-07 |

---

## BR-CO-BP-002 — One Supplier per Contractor; one Contractor per Supplier

| Field | Value |
|-------|-------|
| Rule ID | BR-CO-BP-002 |
| Status | **Confirmed** |
| Confirmed rule | A contractor may link to at most one supplier. A supplier may be linked from at most one contractor in the same company. Link is bidirectional in UX (both profiles show the partner). |
| Date | 2026-07 |

---

## BR-CO-BP-003 — Supplier/AP is sole payment source of truth

| Field | Value |
|-------|-------|
| Rule ID | BR-CO-BP-003 |
| Status | **Confirmed** |
| Confirmed rule | New contractor-related payments are recorded only via Supplier Invoices / Supplier Payments. The old Contractor Payment create path is retired. Payment CTAs redirect to Supplier Payment Workspace with supplier (and project when known) defaults. |
| Accounting impact | No new `co_contractor_payments` inserts; no new journals with source `co_contractor_payment`. |
| Date | 2026-07 |

---

## BR-CO-BP-004 — Historical Contractor Payments retirement (Verified Match)

| Field | Value |
|-------|-------|
| Rule ID | BR-CO-BP-004 |
| Status | **Confirmed** |
| Confirmed rule | Existing `co_contractor_payments` rows are incorrect historical records. They must **not** be auto-deleted. Workflow A (Verified Match): an admin tool lists each row, requires human verification against Supplier/AP, reverses any posted journal, deletes the ops row, and writes an audit log. |
| Date | 2026-07 |

---

## BR-CO-BP-007 — Business Approved Legacy Retirement

| Field | Value |
|-------|-------|
| Rule ID | BR-CO-BP-007 |
| Status | **Confirmed** |
| Confirmed rule | When the organization has officially retired the Contractor Payment workflow and adopted Supplier/AP as the sole financial source of truth, administrators may use **Business Approved Legacy Retirement**. This mode does **not** require matching Supplier/AP transactions. It requires administrator confirmation checkboxes and a mandatory written reason (≥15 characters). It reverses related posted journals via the standard `reverse_journal` process, deletes the legacy ops rows, and writes a complete audit log with `retirement_mode = business_approved_legacy`. Supports single-row and bulk (all remaining for the company, with typed phrase `RETIRE ALL LEGACY`). Bank-reconciled payments remain blocked until unmatched. Dashboards and reports refresh automatically (live queries). |
| Date | 2026-07 |

---

## BR-CO-BP-005 — Commercial progress KPIs

| Field | Value |
|-------|-------|
| Rule ID | BR-CO-BP-005 |
| Status | **Confirmed** |
| Confirmed rule | Contractor Dashboard commercial KPIs are computed in reusable helpers from linked Supplier/AP + operational contract values: Contract Value, Total Invoiced (posted invoice subtotal), Total Paid, Outstanding AP, Remaining Contract Value (`max(0, contract − invoiced)`), Billing Progress %, Payment Progress %. Certification / VO extensions may add fields later without redesigning the dashboard. |
| Date | 2026-07 |

---

## BR-CO-BP-006 — Retention residual only

| Field | Value |
|-------|-------|
| Rule ID | BR-CO-BP-006 |
| Status | **Confirmed** |
| Confirmed rule | Retention release (`co_retention_releases` / account 2125) remains for clearing residual held balances. New retention holds are **not** created via Contractor Payments. Merging retention into Supplier/AP invoice posting remains deferred (see `CO_SUPPLIERS_AP.md`). |
| Date | 2026-07 |

---

## Explicitly deferred

- Invoice-level retention posting into Supplier/AP  
- Certification / IPC module  
- Auto-matching contractor name to supplier name  
- Dropping `co_contractor_payments` table (separate approval after zero rows)  
