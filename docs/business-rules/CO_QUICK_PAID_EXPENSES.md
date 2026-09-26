# Construction Quick Paid Expenses — Confirmed Business Rules

| Field | Value |
|-------|-------|
| Program | Construction Quick Paid Expenses (Phases 0–4) |
| Status | **COMPLETE — APPROVED** (2026-07-27) |
| Module | Construction |
| Ledger | Shared `re_*` via `erp_post_expense` → `create_and_post_journal` |
| Related | Separate from Suppliers AP (`co_supplier_*`) |
| Production checklist | [`docs/construction/CO_FINANCIAL_PRODUCTION_DEPLOYMENT_CHECKLIST.md`](../construction/CO_FINANCIAL_PRODUCTION_DEPLOYMENT_CHECKLIST.md) |
| Program complete | [`docs/construction/CO_QUICK_PAID_EXPENSES_COMPLETE.md`](../construction/CO_QUICK_PAID_EXPENSES_COMPLETE.md) |

No further rules should be invented under this program. Capture new commercial policy via the Business Rule Capture Standard only when confirmed by the business.

---

## BR-CO-QPE-001 — Immediate settlement only

| Field | Value |
|-------|-------|
| Rule ID | BR-CO-QPE-001 |
| Status | **Confirmed** |
| Confirmed rule | Settlement methods are **Cash**, **Bank**, and **Credit Card** only. **Accounts Payable is prohibited.** Journal is always Dr Expense / Dr Input VAT / Cr Bank|Cash|Credit settlement account. |
| Date | 2026-07-27 |

---

## BR-CO-QPE-002 — No Supplier AP impact

| Field | Value |
|-------|-------|
| Rule ID | BR-CO-QPE-002 |
| Status | **Confirmed** |
| Confirmed rule | Optional Supplier (`co_suppliers`) is informational only. Quick Paid Expenses never create Supplier Invoices, Payments, Allocations, or Advances, and never change Outstanding AP / Net Payable. |
| Date | 2026-07-27 |

---

## BR-CO-QPE-003 — Input VAT COA

| Field | Value |
|-------|-------|
| Rule ID | BR-CO-QPE-003 |
| Status | **Confirmed** |
| Confirmed rule | Construction Quick Paid Input VAT posts to Construction account **2130** (then fallbacks only if 2130 missing). |
| Date | 2026-07-27 |

---

## BR-CO-QPE-004 — Optional project

| Field | Value |
|-------|-------|
| Rule ID | BR-CO-QPE-004 |
| Status | **Confirmed** |
| Confirmed rule | Project is optional. If selected, the expense contributes to Project Cost reporting. If not selected, it is company overhead (not in project cost unions). |
| Date | 2026-07-27 |

---

## BR-CO-QPE-005 — Legacy archive immutability

| Field | Value |
|-------|-------|
| Rule ID | BR-CO-QPE-005 |
| Status | **Confirmed** |
| Confirmed rule | **Revised 2026-09-26:** pre-existing Construction ERP expenses (`legacy_archive = 1`) are editable by anyone with Construction Financial access, exactly like a normal Quick Paid expense. Saving one reverses its journal and reposts it; a legacy AP row therefore moves its credit from Accounts Payable to the chosen cash/bank/credit account. The `Historical` badge and an on-page warning stay, because the user must confirm the bill was not already settled by a Supplier Payment (otherwise the payment is counted twice). Deleting a legacy row still needs the owner/admin override in QPE/Supplier Duplicates. |
| Superseded rule | 2026-07-27: read-only forever; journals never rewritten. |
| Date | 2026-09-26 |
