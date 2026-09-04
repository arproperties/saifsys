# Construction Module — Accounting Integration

## Is construction accounting separate from Real Estate?

**Yes.** The accounting engine is **shared** (same tables and code), but data is **strictly separated by company**.

- Every table has `company_id`: `re_journal_headers`, `re_journal_lines`, `re_chart_of_accounts`, `re_account_ledgers`, etc.
- When you post a **contractor payment** or **project cost** from the Construction module, the journal is created with the **construction company’s** `company_id` (e.g. Madar Alwadi).
- When you post an **invoice** or **payment** from the Real Estate module, the journal uses the **real estate company’s** `company_id`.

So:

- **Real Estate company** → its own Chart of Accounts, journals, ledgers, reports.
- **Construction company** → its own Chart of Accounts, journals, ledgers, reports.

They never mix. No cross-company access.

---

## How do I find construction journals and reports?

1. **Be in the Construction company**
   - Use **Switch Module** → select the construction company (e.g. Madar Alwadi), or ensure your current company is the construction company.

2. **Open Accounting from the Construction module**
   - In the Construction sidebar, under **Financial**, use **Journals** (and optionally **General Ledger**, **Chart of Accounts**).
   - Those links open the same accounting screens used by Real Estate, but they only show data for the **current company**. So with the construction company selected, you see only construction journals and GL.

3. **What you’ll see**
   - **Journals**: All journal entries for the construction company (including contractor payments and project cost postings). Filter by date, type, posted status.
   - **General Ledger**: Account balances and movements for the construction company.
   - **Chart of Accounts**: The construction company’s own accounts (including construction-specific codes like Construction in Progress, Project COGS, etc.).

So: **same accounting engine, same UI, different company → different (separate) data.** You “find” construction things by having the construction company selected and then using the accounting links in the Construction module.


---

## Setup for construction company

1. **Chart of accounts** — Run `migrations/construction_chart_of_accounts.sql` once per construction company (set `@company_id` to your construction company ID). The company must already have a base COA.
2. **Account codes used** — 1515 Construction in Progress, 5125 Project COGS, 2145 Contractor Payable, 2125 Retention Payable, 1210/1110 Bank/Cash.
