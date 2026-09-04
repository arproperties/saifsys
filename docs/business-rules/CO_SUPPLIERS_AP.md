# Construction Suppliers / AP — Confirmed Business Rules

| Field | Value |
|-------|-------|
| Program | Construction Suppliers/AP uplift Phases 0–4 |
| Status | **COMPLETE — APPROVED** (2026-07-27) |
| Source of confirmation | Product owner approvals across Phases 0–4 (Cursor sessions, 2026-07) |
| Module | Construction |
| Ledger | Shared `re_*` via Construction posting wrappers only |
| Production checklist | [`docs/construction/CO_SUPPLIERS_AP_PROGRAM_COMPLETE.md`](../construction/CO_SUPPLIERS_AP_PROGRAM_COMPLETE.md) |

No further rules should be invented under this program. Capture new commercial policy via the Business Rule Capture Standard only when confirmed by the business.

---

## BR-CO-SUP-001 — Document naming and ownership

| Field | Value |
|-------|-------|
| Rule ID | BR-CO-SUP-001 |
| Status | **Confirmed** |
| Confirmed rule | Construction payables use **Suppliers** naming (`co_suppliers`, supplier invoices, supplier payments). Do not merge into RE `re_vendors` or call RE `vendor_*` helpers. |
| Accounting impact | Posts only through `co_post_supplier_*` → shared `create_and_post_journal` / `reverse_journal`. |
| Date | 2026-07 |

---

## BR-CO-SUP-002 — Invoice lifecycle immutability

| Field | Value |
|-------|-------|
| Rule ID | BR-CO-SUP-002 |
| Status | **Confirmed** |
| Confirmed rule | Lifecycle is `Draft → Posted → Partially Paid → Paid → Voided`. Draft is editable/deletable. Posted+ financial values are locked. Corrections use **Void** (unpaid) or **Void + Amend** (new draft via `amended_from_invoice_id`). |
| Exceptions | None confirmed |
| Date | 2026-07 |

---

## BR-CO-SUP-003 — Supplier Advances COA

| Field | Value |
|-------|-------|
| Rule ID | BR-CO-SUP-003 |
| Status | **Confirmed** |
| Confirmed rule | Supplier Advances are an **asset**. Default account code **1410**, overridable via `settings.co_supplier_advance_account_code`. Do **not** reuse **2410** (client advances / liability). Payable remains **2110**; Input VAT remains **2130**. |
| Date | 2026-07 |

---

## BR-CO-SUP-004 — Advance remaining and refunds

| Field | Value |
|-------|-------|
| Rule ID | BR-CO-SUP-004 |
| Status | **Confirmed** |
| Confirmed rule | Remaining advance on a payment = original advance − applied − posted Advance VAT − posted refunds. Refunds (Dr Bank / Cr Advances) are **blocked** while Advance VAT remains posted on that payment. |
| Date | 2026-07 |

---

## BR-CO-SUP-005 — Advance VAT on final invoices

| Field | Value |
|-------|-------|
| Rule ID | BR-CO-SUP-005 |
| Status | **Confirmed** |
| Confirmed rule | Advance VAT documents post Dr Input VAT **2130** / Cr Advances. Links are allowed on **draft** invoices only. Invoice GL post uses **remaining** Input VAT; AP credit = invoice total − linked advance VAT. |
| Date | 2026-07 |

---

## BR-CO-SUP-006 — Position KPIs

| Field | Value |
|-------|-------|
| Rule ID | BR-CO-SUP-006 |
| Status | **Confirmed** |
| Confirmed rule | First-class supplier position is Outstanding AP, Supplier Advance Balance, and Net Payable (AP − Advances). Dashboard also shows Current VAT Pending (draft invoice VAT net of links + unlinked posted Advance VAT), Open Invoices, and Partially Paid Invoices. Supplier timeline includes Created / Posted / Payment / Advance / Advance VAT / Applied / Refund / Void / Amend. |
| Date | 2026-07 |

---

## Explicitly deferred (not Confirmed for this program)

- Supplier credit/debit notes  
- PO / GRN / 3-way match  
- Maker-checker monetary thresholds (Needs business confirmation)  
- Merging contractor retention into Suppliers AP (retention posting remains residual via 2125; see [`CO_CONTRACTOR_SUPPLIER_LINK.md`](CO_CONTRACTOR_SUPPLIER_LINK.md) for Business Partner link + payment redirect)  
- Merging Historical ERP Expenses into AP lifecycle  
- (Historical ERP Expenses remain a separate legacy archive; new immediate payments use Quick Paid Expenses — see `CO_QUICK_PAID_EXPENSES.md`)  
