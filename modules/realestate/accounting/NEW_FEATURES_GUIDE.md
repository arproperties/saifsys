# Accounting New Features – What We Added & How to Use Them

This guide explains each new feature and gives step-by-step examples.

---

## 1. Day Book Report

**What it is:** A report that lists **all posted journal entries** in date order for a date range. You see each journal as a header with optional line-by-line detail, so you can review “everything that happened” on a given day or period.

**When to use it:** To see daily/period activity in one place, reconcile what was posted, or audit by date.

**How to use it:**

1. Go to **Accounting → Day Book** (sidebar).
2. Set **From Date** and **To Date** (e.g. 2025-01-01 to 2025-01-31).
3. Optionally check **Show lines** to see each journal’s debit/credit lines.
4. Click **View**. You get a list of journals with date, journal #, type, description, totals.
5. Use **Print**, **CSV**, or **Excel** to export.

**Example:** You want to see everything posted in January 2025. Set From = 2025-01-01, To = 2025-01-31, check “Show lines”, click View. You see each journal (e.g. rent receipt, salary, utility payment) with its lines and totals.

---

## 2. Excel Export for Reports

**What it is:** Key reports can be downloaded as **Excel (.xlsx)** (and CSV) so you can open them in Excel, keep archives, or share with auditors.

**Where it is:** Buttons like **Excel** or **CSV** on:

- Trial Balance  
- Profit & Loss  
- Balance Sheet  
- Day Book  
- (and other reports that already had export)

**How to use it:**

1. Open the report (e.g. **Trial Balance**).
2. Set your filters (e.g. As of date).
3. Click **Excel** (or **CSV**). The file downloads (e.g. `trial_balance_2025-01-31.xlsx`).
4. Open in Excel to edit, print, or archive.

**Example:** You need the December 2024 trial balance for the auditor. Go to Trial Balance, set As of date = 2024-12-31, click **Excel**. You get a clean spreadsheet with Account Code, Account Name, Type, Debit, Credit.

---

## 3. Bank Statement CSV/Excel Import

**What it is:** You can **upload a bank statement file** (CSV or Excel) instead of typing lines manually. The system parses rows (date, description, debit, credit) and shows them for matching to existing transactions or creating new ones.

**When to use it:** When the bank gives you a CSV or Excel statement; speeds up reconciliation.

**How to use it:**

1. Go to **Accounting → Statement Import** (or **Bank Reconciliation → Import**).
2. Choose the **bank account** and **statement date**.
3. Click **Choose File** and select your CSV or Excel file.
4. Upload. The system shows a preview of parsed rows (date, description, amount).
5. Map columns if needed (which column is date, description, debit, credit).
6. Use the list to **match** lines to existing payments/deposits or mark as new.

**Example:** You have `statement_jan2025.csv` from the bank. You upload it, set account = “Company Current Account” and date = 2025-01-31. The screen shows 50 lines; you match each to a payment or journal, or create a new entry for bank fees.

---

## 4. Recurring Journals

**What it is:** You define a **repeating journal** (same accounts and amounts) and set a **schedule** (e.g. monthly on the 1st). The system can **generate** that journal automatically on the next run date so you don’t re-enter it every month.

**When to use it:** Monthly rent income, monthly depreciation, fixed salary accruals, etc.

**How to use it:**

1. Go to **Accounting → Recurring Journals** (or the recurring section under Journal Entries).
2. Click **Add Recurring Journal**.
3. Fill in:
   - **Name:** e.g. “Monthly rent income”
   - **Description:** optional
   - **Frequency:** Weekly or Monthly.
   - **Day of month:** e.g. 1 (for 1st of each month).
   - **Next run date:** e.g. 2025-02-01.
   - **Lines:** Add lines like a normal journal (e.g. Debit Bank 50,000, Credit Rent Income 50,000).
4. Save. The recurring definition is stored.
5. When the next run date arrives (or you run the “Generate recurring” process), the system creates a **new journal entry** from that definition and advances the next run date.

**Example:** You have 50,000 AED rent every month. You create a recurring journal “Monthly rent”, frequency Monthly, day 1, next run 2025-02-01, with one line Debit Bank 50,000 and one line Credit Rent Income 50,000. On 1 Feb the system (or you) runs “Generate”; it creates journal “Rent – Feb 2025” and sets next run to 2025-03-01.

---

## 5. Journal Templates (Save / Load)

**What it is:** You can **save** the lines of a journal (accounts and amounts) as a **template** and **load** that template when creating a new journal. The template does not store the date or journal number—only the structure (which accounts, debits, credits, descriptions).

**When to use it:** You often do the same type of entry (e.g. “Rent receipt – 3 accounts”, “Office expense split”, “Monthly accrual”) and want to avoid re-entering the same lines every time.

**How to use it:**

**Saving a template**

1. Go to **New Journal Entry**.
2. Add your lines (e.g. Debit Bank 50,000, Credit Rent Income 50,000, Debit VAT 2,500, Credit VAT Payable 2,500).
3. Click **Save as template** (in the Journal Lines card).
4. In the modal, enter **Template name** (e.g. “Rent receipt with VAT”) and optional **Description**.
5. Click **Save template**. You are still on the journal form; you can submit it or clear and load another template.

**Using a template**

1. Go to **New Journal Entry**.
2. In the **Journal Information** card, use **Load template** and choose e.g. “Rent receipt with VAT”. The page reloads with the template’s lines and optional description filled in.
3. Set **Journal date** and adjust amounts if needed, then **Create Journal Entry**.

**Managing templates**

- Go to **Journal Templates** (from the templates link or menu). You see all templates; **Use Template** opens the add screen with that template loaded; **Delete** removes the template (not any journals already created).

**Example:** You often book “Office supplies” to 3 accounts. You create a journal once with those 3 lines, click **Save as template**, name it “Office supplies split”. Next time you go to New Journal Entry, choose “Office supplies split” from **Load template**, change the date and amounts, and save. No need to re-pick the 3 accounts every time.

---

## 6. Account Drill-Down from Reports

**What it is:** On **Trial Balance**, **Profit & Loss**, and **Balance Sheet**, each **account code and name** can be a **link**. Clicking it opens the **General Ledger** for that account with the **same date range** as the report (or a sensible default like year-to-date), so you see the transactions that make up that balance.

**When to use it:** You see a number on a report and want to see “what’s in it” without opening General Ledger and picking the account and dates yourself.

**How to use it:**

1. Open e.g. **Trial Balance** with As of date = 2025-01-31.
2. You see a row: Code 4100, Rent Income, Credit 120,000.
3. Click **4100** or **Rent Income**. A new tab/page opens **General Ledger** with:
   - Account = 4100 Rent Income  
   - From = 2025-01-01, To = 2025-01-31 (or the report’s range).
4. You see the list of journals and lines that built the 120,000 credit.

**Example:** On P&L you see “Advertising expense 15,000”. You click the account name; General Ledger opens for that expense account for the P&L period. You see the 3 invoices that sum to 15,000.

---

## 7. Better Print Layout for Reports

**What it is:** When you **Print** (or Print to PDF) from a report page, the layout is improved: **sidebar and top navigation are hidden**, and **filter cards and action buttons** (Print, CSV, Excel) are hidden so the printed page shows mainly the **report title and table**.

**How to use it:**

1. Open any report (e.g. **Trial Balance**, **P&L**, **Balance Sheet**, **Day Book**, **General Ledger**).
2. Set your filters and click **View** / **Generate** so the report is on screen.
3. Press **Print** (browser menu or the **Print** button on the page).
4. In the print preview you see only the report content (and maybe a simple header). No sidebar, no “Print” / “Excel” buttons.
5. Print or “Save as PDF” as usual.

**Example:** You want a PDF of the December Trial Balance for the board. You open Trial Balance, set As of 2024-12-31, click Print. The PDF contains only the trial balance table and title, not the rest of the screen.

---

## 8. Approval Workflow (Optional)

**What it is:** An **optional** flow for journal entries: **Draft → Submit for approval → Approved → Post.**  
You can still **post directly from draft** if you don’t use approval. If you use it, someone can “Submit for approval” and another user can “Approve”; then the journal can be posted.

**When to use it:** When you want a second pair of eyes on certain journals (e.g. large or sensitive entries) before they hit the general ledger.

**Setup (one-time):** Run the migration so the approval columns exist:

- Run the SQL in `migrations/accounting_approval_workflow.sql` on your database (adds `approval_status`, `submitted_at`, `submitted_by`, `approved_at`, `approved_by` on `re_journal_headers`).

**How to use it:**

**Option A – Post without approval (unchanged)**

1. Create a journal (it is **Draft**).
2. Open the journal (View).
3. Click **Post Journal Entry**. It posts; no approval step.

**Option B – Use approval**

1. Create a journal (status **Draft**).
2. Open the journal. Click **Submit for Approval**. Status becomes **Submitted for approval**.
3. (Another user or same user) opens the journal. Click **Approve**. Status becomes **Approved**.
4. Click **Post Journal Entry**. It posts.

On the **Journal Entries** list you see status: **Draft**, **Submitted**, or **Approved** for unposted entries (when the workflow columns exist).

**Example:** You enter a large manual adjustment (100,000 AED). You click **Submit for approval**. Your manager opens the same journal, checks the lines, and clicks **Approve**. Then you (or they) click **Post**. The entry is now in the general ledger. If the migration was never run, only the **Post** button appears and behaviour is as before (no approval).

---

## Quick Reference Table

| Feature              | Where to find it                    | What you do |
|----------------------|-------------------------------------|-------------|
| Day Book             | Accounting → Day Book               | Set date range, view/print/export all posted journals. |
| Excel export         | Trial Balance, P&L, Balance Sheet, Day Book, etc. | Click **Excel** (or CSV) to download. |
| Bank import          | Accounting → Statement Import       | Upload CSV/Excel statement, map columns, match lines. |
| Recurring journals   | Recurring Journals (under Accounting) | Define a repeating journal and run “Generate” on schedule. |
| Journal templates    | New Journal Entry → Save as template / Load template; Journal Templates | Save current lines as template; load template when adding a journal. |
| Account drill-down   | Trial Balance, P&L, Balance Sheet   | Click an account code/name → General Ledger for that account. |
| Print layout         | Any report                          | Use browser Print; sidebar and buttons are hidden. |
| Approval workflow    | Journal entry View page             | Submit for approval → Approve → Post (optional; needs migration). |

If you tell me which feature you’re using first (e.g. “templates” or “approval”), I can give you a minimal 3-step checklist just for that one.
