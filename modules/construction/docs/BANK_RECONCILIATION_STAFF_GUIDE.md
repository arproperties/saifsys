# Construction — Bank Reconciliation Staff Guide

**Audience:** Finance team (accountants, AP/AR, treasury)  
**Module:** Construction only — does not affect Real Estate or Cleaning accounting  
**Last updated:** July 2026

---

## 1. What this feature does

Bank reconciliation links **bank statement lines** (from your bank CSV/Excel) to **ERP bank transactions** in the General Ledger.

| Bank side | ERP side |
|-----------|----------|
| Imported statement lines (`co_bank_statement_lines`) | Existing GL entries on the bank account (client receipts, supplier payments, etc.) |
| One line = one movement on the bank statement | Or a **new journal** you create from the reconcile screen |

**Goal:** Statement balance and ERP balance agree, and every statement line is either matched, coded, or marked for follow-up.

---

## 2. Daily workflow (recommended order)

```
┌──────────────┐     ┌──────────────┐     ┌──────────────┐     ┌──────────────┐
│ 1. Import    │ ──► │ 2. Reconcile │ ──► │ 3. Cash code │ ──► │ 4. Report &  │
│   statement  │     │   workbench  │     │   (bulk)     │     │   lock period│
└──────────────┘     └──────────────┘     └──────────────┘     └──────────────┘
```

1. **Import** the bank file for the period.
2. **Reconcile** line by line (Match, Create, Transfer, or Discuss).
3. Use **Cash coding** for many similar small lines (petty cash, bank charges).
4. Run the **Bank Reconciliation report**; lock the month when complete.

Optional (setup once, then maintain):

- **Bank rules** — pre-fill coding and suggest matches for recurring items.
- **Settings** — turn on **auto-reconcile** for safe, high-confidence rule matches only.

---

## 3. Where to find everything

All menus are under **Construction → Financial**.

| Screen | Menu link | Who uses it |
|--------|-----------|-------------|
| Bank Dashboard | **Bank Dashboard** | Everyone — status per bank account |
| Import | **Import Statement** | User with import permission |
| Workbench | **Reconcile** | Match/create/transfer lines |
| History | **History** | Audit trail; undo mistakes |
| Bank Rules | **Bank Rules** | Setup recurring patterns |
| Cash Coding | **Cash Coding** | Bulk code unreconciled lines |
| Settings | **Settings** | Auto-reconcile toggle |
| Bank Feeds | **Bank Feeds** | Placeholder (not live yet) |
| Report | **Reports → Bank Reconciliation** | Month-end review / export |

---

## 4. Permissions (for supervisors / IT)

Ask your administrator to assign Construction bank reconciliation permissions as needed:

| Permission | Allows |
|------------|--------|
| View | Dashboard, workbench (read), history (view) |
| Import | Upload statement files |
| Match | Confirm matches, auto-match, auto-reconcile |
| Create transaction | **Create** tab — post new journals |
| Transfer | **Transfer** tab — move money between bank accounts |
| Cash coding | Bulk create & reconcile |
| Rules | Bank rules + automation settings |
| Undo | **Remove & redo** on history |
| Report | Bank reconciliation report |
| Admin override | Change locked periods (use sparingly) |

---

## 5. Bank Dashboard — setup & overview

**Menu:** Construction → Financial → **Bank Dashboard**

### Link a bank account (one-time)

1. Choose a **GL account** in the 1100–1299 range (bank/cash accounts).
2. Enter **account name**, bank name, account number, IBAN if applicable.
3. Click **Add bank account**.

Each active bank shows:

| Column | Meaning |
|--------|---------|
| ERP balance | GL balance on the linked account as of today |
| Statement balance | Latest imported statement balance |
| Difference | ERP minus statement (should be **0.00** when done) |
| Unreconciled | Statement lines not fully matched |
| Status | Reconciled / Needs review / Difference found / No statement imported |

**Actions:** **Reconcile**, **Import**, **Settings**, **Bank Feeds**.

---

## 6. Import bank statement

**Menu:** **Import Statement**

1. Select the **bank account**.
2. Upload **CSV or Excel** (template available on the page).
3. Required columns: **date**, **amount** (or debit/credit), **description**, **reference** (optional but helpful).
4. Review the **preview** — fix mapping if columns are wrong.
5. Click **Confirm import**.

**Notes:**

- Duplicate lines (same date, amount, description, reference) are **skipped automatically**.
- Lines in a **locked period** cannot be imported.
- If **auto-reconcile** is enabled (see §10), the import success message shows how many lines were auto-confirmed.

---

## 7. Reconcile workbench — line-by-line

**Menu:** **Reconcile**

**Layout:** Statement lines on the **left**; actions on the **right**.

**Header bar:**

- Statement balance, ERP balance, **difference**, unreconciled count
- Filter by bank and date range → **Refresh**

Select a line, then use one of the tabs:

### 7.1 Match (default)

Use when the ERP already has the transaction (e.g. client payment or supplier payment posted earlier).

1. Review the **suggested match** (High / Medium / Low confidence).
2. Or pick from **ERP bank transactions** list below.
3. Click **OK — Reconcile** or **Match**.

**Confidence:**

| Label | Score | Typical use |
|-------|-------|-------------|
| High | 90+ | Safe to reconcile; required for auto-reconcile |
| Medium | 70–89 | Review before OK |
| Low | below 70 | Use Find & Match or Create |

**Important:** Matching an **existing** GL entry only links the statement line — it does **not** post a duplicate journal.

### 7.2 Create

Use when there is **no** matching ERP transaction yet.

Fields follow **Who / What / Why**:

| Field | Purpose |
|-------|---------|
| **Who (Contact)** | Client, supplier, or contractor (optional) |
| **Type** | Quick expense, bank charge, client receipt, direct project expense, cash withdrawal |
| **What (Account)** | GL account to post against |
| **Project** | Optional project link |
| **Why (Description / Reference)** | Narration on the journal |

Click **Create & reconcile** — the system posts **one journal** and confirms the match.

If a **bank rule** applies, fields are pre-filled (banner shows rule name).

### 7.3 Transfer

Use for **bank-to-bank** movements (same company).

1. Choose the **other bank account**.
2. Confirm amount and date.
3. Post — creates the transfer journal and reconciles the line.

### 7.4 Discuss

Use when you need to **pause** a line (query supplier, missing invoice, etc.).

- Add notes for the team.
- Line stays **unreconciled** but marked as discussed.
- Return later and Match or Create when resolved.

### 7.5 Find & Match

Advanced search when the Match tab suggestion is wrong or missing.

- Filter by date range, amount, contact, transaction type.
- Select one or more ERP items whose **total equals** the statement line.
- Confirm selection to reconcile.

---

## 8. Bank rules

**Menu:** **Bank Rules**

Rules automate **suggestions** for recurring statement patterns (e.g. “UBL CHG” → bank charges account).

### When to create a rule

- Same description or reference every month
- Fixed or range amounts (utilities, subscriptions)
- After coding a line once — use **Edit rule** link from the Create tab, or open **Bank Rules → New rule**

### Rule fields

| Section | Fields |
|---------|--------|
| **Scope** | Name, priority (lower = checked first), direction (spent / received / both), bank (or all banks) |
| **Conditions** | Description contains, reference contains, amount equals / min / max |
| **Action** | Transaction type, contact, account, project, description template |
| **Options** | **Active**, **Auto-suggest on reconcile** |

**Description template** placeholders: `{description}`, `{reference}`, `{amount}`.

### What rules do **not** do (by default)

- Rules **pre-fill** the Create tab and improve Match suggestions.
- They do **not** post journals unless you click Create (or use cash coding with your coding).
- **Auto-reconcile** (§10) only confirms matches to **existing** ERP transactions — never auto-creates journals.

---

## 9. Cash coding (bulk)

**Menu:** **Cash Coding**

For many similar unreconciled lines in one session:

1. Select bank and date range → **Load lines** (up to 200 per page).
2. Code each row: type, account, project, contact, description.
3. Tick rows to process → **Save & reconcile selected**.

Each selected row creates **one journal** and reconciles in a single action. Use for bank charges, small expenses, or repetitive receipts — not for complex items needing individual review.

---

## 10. Settings & auto-reconcile

**Menu:** **Settings**

### Toggle: Enable auto-reconcile for high-confidence rule matches

When **ON**, the system **automatically confirms** reconciliation (no manual OK) only if **all** of the following are true:

1. An **active bank rule** matches the statement line
2. Rule has **Auto-suggest** ON
3. A matching **existing** ERP bank transaction scores **90+** (High)
4. Amounts match **exactly** (no partial matches)
5. Period is **not locked**

When **OFF**, you always confirm matches manually on the workbench.

### When auto-reconcile runs

- After **statement import** (success message shows count)
- After **auto-match** on the workbench
- Manually: **Run auto-reconcile now** (bank + date range) on the Settings page

### Safe practice

1. Start with auto-reconcile **OFF** until rules are tested.
2. Turn **ON** only for stable, high-volume patterns (e.g. known client receipts with matching ERP entries).
3. Review **History** after the first automated run.

---

## 11. History — corrections (Remove & redo)

**Menu:** **History**

Shows confirmed matches with user, date, method, and amounts.

**Remove & redo** (requires undo permission):

- **Match-only:** removes the link; statement line becomes unreconciled again; **no** journal reversal.
- **Created transaction:** reverses the journal that was posted from Create / cash coding, then unreconciles.

Use for mistakes — not for routine edits. Re-do the line correctly on the workbench.

---

## 12. Bank Reconciliation report

**Menu:** Reports → **Bank Reconciliation**

1. Select bank and date range → **Run report**.
2. Tabs:
   - **Summary** — balances and counts
   - **Bank statement** — all lines and status
   - **Outstanding ERP** — GL bank items not yet matched
3. **Export** for audit or month-end files.

---

## 13. Bank Feeds (coming soon)

**Menu:** **Bank Feeds**

Placeholder for future direct bank connections. Today, use **Import Statement** with CSV/Excel from your bank.

---

## 14. Period locks

When a month is **locked** for a bank account:

- No import, match, create, transfer, undo, or cash coding in that period
- Users with **admin override** can still change locked periods — use only for corrections under supervision

Lock periods after month-end reconciliation is signed off (via dashboard / lock controls when enabled).

---

## 15. Line statuses (workbench)

| Status | Meaning |
|--------|---------|
| Unreconciled | No match yet |
| Suggested | System proposed a match — review and OK |
| Reconciled | Fully matched |
| Discussed | Flagged for follow-up |
| Ignored | Excluded from active work (if used) |

---

## 16. Common scenarios

### Client receipt on statement, payment already in ERP

1. Open line → **Match** tab.
2. Confirm suggested client payment (High confidence) → **OK**.
3. If not found, check date range on **Find & Match** or verify payment was posted to the **same bank GL account**.

### Supplier payment — not yet in ERP

1. **Create** tab → type **Quick expense** or post supplier payment in Suppliers first, then Match.
2. Or create rule for recurring supplier names.

### Bank charge / SMS fee

1. **Create** → type **Bank charge** → expense account.
2. Or **Cash coding** if many small charges in one import.

### Transfer between company bank accounts

1. **Transfer** tab → select destination bank → post.

### Statement balance ≠ ERP balance

1. Check **difference** on dashboard / workbench header.
2. Run report → **Outstanding ERP** (ERP items not on statement) and unreconciled statement lines.
3. Import missing days; reconcile remaining lines.

### Wrong reconciliation

1. **History** → **Remove & redo** on the match.
2. Reconcile correctly on the workbench.

---

## 17. Do’s and don’ts

| Do | Don’t |
|----|-------|
| Import before reconciling | Reconcile without a current statement import |
| Match to existing ERP entries when possible | Create duplicate journals for payments already posted |
| Use Discuss for unclear items | Force-match wrong amounts |
| Test bank rules before enabling auto-reconcile | Enable auto-reconcile on untested rules |
| Lock the period after month-end sign-off | Edit locked periods without admin approval |
| Use Cash coding for repetitive small items | Bulk-code complex transactions without review |

---

## 18. Quick reference — one page

```
IMPORT  →  Construction → Import Statement  →  CSV/Excel  →  Confirm
WORK    →  Construction → Reconcile  →  select line  →  Match | Create | Transfer | Discuss
BULK    →  Construction → Cash Coding  →  Load  →  code rows  →  Save & reconcile
RULES   →  Construction → Bank Rules  →  conditions + action + Auto-suggest
AUTO    →  Construction → Settings  →  enable toggle  →  (optional) Run now
CHECK   →  Reports → Bank Reconciliation  →  Summary / Outstanding
FIX     →  Construction → History  →  Remove & redo
```

---

*For technical setup (database migrations, permissions), contact your system administrator. Migrations: `construction_bank_reconciliation.sql`, `_v2.sql`, `_v3.sql`.*
