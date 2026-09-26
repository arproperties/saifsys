# Construction Quick Paid Expenses — Program Complete

| Field | Value |
|-------|-------|
| Program | Construction Quick Paid Expenses (Phases 0–4) |
| Status | **COMPLETE — APPROVED FOR PRODUCTION** |
| Approval date | 2026-07-27 |
| Module | Construction |
| Ledger | Shared `re_*` via `erp_post_expense` → `create_and_post_journal` (no engine redesign) |
| Boundary | Separate from Suppliers AP (`co_supplier_*`) — never creates AP / Outstanding / Advances |

---

## Final production state

- Live **Quick Paid Expenses** UI (nav + list + add/edit) for immediate Cash / Bank / Credit Card settlement  
- Journal: Dr Expense · Dr Input VAT (**2130**) · Cr settlement account  
- Optional Project (project cost when set; otherwise company overhead)  
- Optional Supplier informational only (profile panel; no AP impact)  
- Legacy Historical ERP Expenses (`legacy_archive = 1`) editable since 2026-09-26; saving reverses and reposts the journal (delete still owner/admin override only)  
- List filters: Project, Supplier, Expense Account, Payment Method, Date, Status (+ Archive)  

**Confirmed rules:** [`docs/business-rules/CO_QUICK_PAID_EXPENSES.md`](../business-rules/CO_QUICK_PAID_EXPENSES.md)  

**Overall deploy checklist (includes this feature):** [`CO_FINANCIAL_PRODUCTION_DEPLOYMENT_CHECKLIST.md`](CO_FINANCIAL_PRODUCTION_DEPLOYMENT_CHECKLIST.md)

**No further implementation** for this program unless driven by new business requirements or operational feedback.

---

## Migration

| Order | File |
|-------|------|
| After Suppliers/AP migrations (or independently if AP already deployed) | `migrations/construction_quick_paid_expenses.sql` |

Adds `erp_expense_headers.legacy_archive` and sets `legacy_archive = 1` for existing `source_module = 'construction'` rows at apply time. New Quick Paid rows remain `0`.

---

## Primary code map

| Area | Path |
|------|------|
| Posting (shared, Construction-gated) | `includes/erp_expense_posting.php` |
| Construction list | `modules/construction/expenses.php` |
| Construction add/edit wrappers | `modules/construction/expense_add.php`, `expense_edit.php` |
| Shared forms | `modules/realestate/expense_add.php`, `expense_edit.php` |
| Supplier profile panel | `modules/construction/supplier_view.php` |
| Project cost label | `modules/construction/reports/project_cost_detail.php` |

---

## Localhost smoke (company_id=3, 2026-07-27)

All acceptance checks **PASS** (post/VAT 2130/AP reject/overhead/legacy block/AP+advance unchanged/credit card/supplier link).

---

## Remaining recommendation (not open program work)

Because posting uses shared `erp_expense_posting.php`, run a **Real Estate Quick Paid regression smoke** after production deploy (see overall checklist §D). Construction VAT preference (**2130**) is `source_module`-gated and must not force RE VAT codes.
