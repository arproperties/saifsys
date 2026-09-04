# Expenses, Suppliers & VAT – Recommendation for Construction

> **Status (2026-07-27):** Suppliers + invoices + payments + VAT (including advances) and **Quick Paid Expenses** are **delivered and approved**.  
> **Suppliers/AP:** [`docs/construction/CO_SUPPLIERS_AP_PROGRAM_COMPLETE.md`](../../docs/construction/CO_SUPPLIERS_AP_PROGRAM_COMPLETE.md)  
> **Quick Paid:** [`docs/construction/CO_QUICK_PAID_EXPENSES_COMPLETE.md`](../../docs/construction/CO_QUICK_PAID_EXPENSES_COMPLETE.md)  
> **Production deploy checklist:** [`docs/construction/CO_FINANCIAL_PRODUCTION_DEPLOYMENT_CHECKLIST.md`](../../docs/construction/CO_FINANCIAL_PRODUCTION_DEPLOYMENT_CHECKLIST.md)  
> This file is retained as historical recommendation context. Do **not** treat the phased “next step” at the bottom as open program work.

## Can you depend only on "Add Project Cost"?

**No.** The current **Add Project Cost** page is good for:

- Recording a **single cost entry** (project, date, amount, cost type, optional labor/contractor link).
- Posting to the **GL** as: Debit CIP/Project COGS, Credit Bank (no supplier, no invoice, no VAT).

It does **not** provide:

- **Supplier master** (register suppliers, profile per supplier).
- **Supplier invoices** (invoice number, date, lines, VAT amount, due date).
- **Tracking per supplier** (how much you paid, purchase history, balance).
- **VAT on expenses** (input VAT per invoice, VAT account in GL).
- **VAT report** for the construction company (e.g. input VAT by period).

So for a **professional expenses and VAT** setup, you need a dedicated **Suppliers + Expense Invoices** flow, with proper GL and VAT handling.

---

## What a professional setup should include

### 1. Suppliers (master data)

- **Suppliers list** – all suppliers for the construction company.
- **Supplier profile** per supplier:
  - Name, contact, tax/VAT number, address, bank details.
  - **Total purchased** (sum of invoice totals).
  - **Total paid** (sum of payments).
  - **Balance due** (invoices not yet paid).
  - **History** – list of invoices and payments (purchase history).

### 2. Supplier / expense invoices

- **Register an invoice** from a supplier:
  - Supplier, invoice number, invoice date, due date.
  - Optional link to **project** (for project-wise reporting).
  - **Subtotal**, **VAT %**, **VAT amount**, **Total**.
  - Description, reference.
- **GL posting** when invoice is “posted”:
  - Debit **Expense** (or CIP if project-linked) for net amount.
  - Debit **VAT recoverable / Input VAT** for VAT amount.
  - Credit **Supplier Payable** (or Trade Payable) for total.
- So the **GL is correct** and every expense + VAT is in the ledger.

### 3. Payments to suppliers

- **Record payment** against a supplier (and optionally against specific invoices).
- **GL**: Debit Supplier Payable, Credit Bank.
- Update **amount paid** and **balance** on supplier profile and invoices.

### 4. VAT handling in accounting

- **Chart of accounts** must include at least:
  - **Supplier / Trade Payable** (e.g. 2110).
  - **Input VAT / VAT recoverable** (e.g. 2130 or 1140).
- **Journal entries** for supplier invoices must post:
  - Expense (or CIP) + Input VAT + Supplier Payable.
- Then a **VAT report** can be built from:
  - Journal lines (or a VAT register) that hit the Input VAT account, by period, for the construction company.

### 5. VAT report for construction company

- Filter by **company** (construction company) and **date range**.
- Sum **input VAT** (from expense invoices) and optionally **output VAT** (if you also record sales in the same company).
- So you can get the **VAT position** (recoverable vs payable) for the construction company.

---

## Recommended approach

### Keep "Add Project Cost" for simple entries

- Use it for **quick cost entries** that don’t go through a supplier invoice (e.g. petty cash, internal transfers, or one-off expenses without a formal supplier invoice).
- Optionally later: add a **“Supplier invoice” cost type** that creates or links to a supplier invoice instead of only posting one line to CIP/COGS and Bank.

### Add a dedicated “Suppliers & Expenses” flow

Implement in phases:

| Phase | What | Outcome |
|-------|------|--------|
| **1** | **Suppliers master** | Table `co_suppliers`. List, Add, View/Edit supplier. Supplier profile with totals (purchases, paid, balance) and history. |
| **2** | **Supplier invoices** | Table `co_supplier_invoices` (supplier_id, project_id optional, invoice_no, date, due_date, subtotal, vat_pct, vat_amount, total, status, journal_id). Form to register invoice; on “Post” create journal (Expense/CIP + Input VAT + Supplier Payable). Link to GL accounts (including VAT account). |
| **3** | **Payments to suppliers** | Table `co_supplier_payments` (supplier_id, invoice_id optional, amount, date, journal_id). Post Debit Payable, Credit Bank. Update supplier and invoice balances. |
| **4** | **VAT report** | Report (or page under Construction Financial) that reads journal lines for the construction company’s Input VAT (and optionally Output VAT) account(s) for a date range and shows VAT summary / report. |

### GL and chart of accounts

- Reuse the same **accounting engine** (e.g. `re_journal_headers`, `re_journal_lines`, `re_chart_of_accounts`) and **company_id** so construction stays in the same ledger as today.
- In **Setup construction accounts** (or equivalent), ensure you have:
  - **Supplier Payable** (e.g. 2110).
  - **Input VAT / VAT recoverable** (e.g. 2130).
- Construction posting (contractor payments, project costs, **and** supplier invoices/payments) should use these accounts so **one** GL and **one** VAT report per company are possible.

---

## Summary

- **Do not depend only on Add Project Cost** for professional expenses, supplier tracking, and VAT.
- **Add Project Cost** = simple cost entry and GL (CIP/COGS vs Bank); no suppliers, no invoices, no VAT.
- For a **professional expenses page** with **suppliers, invoices, payments, GL and VAT**:
  - Add **Suppliers** (master + profile + history).
  - Add **Supplier invoices** (with VAT and GL posting to Expense/CIP + Input VAT + Supplier Payable).
  - Add **Payments to suppliers** (GL: Payable vs Bank).
  - Add **VAT report** for the construction company based on VAT accounts in the GL.

**Program closed:** Suppliers master, invoices + GL + VAT, payments, advances, Advance VAT, refunds, VAT report, and dashboard/statement are in production-ready code. Further changes require new business requirements or operational feedback — not this historical roadmap.
