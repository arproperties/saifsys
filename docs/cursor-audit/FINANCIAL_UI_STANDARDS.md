# Financial UI Standards (Future)

**Stage:** 2 — Standards only. Not implemented.  
**Policy:** Invoice Mode is official RE accounting UI. Legacy screens labeled Historical.

## Mandatory on every financial screen

| Requirement | Rationale |
|-------------|-----------|
| **Company visibly identified** | Prevent cross-company mistakes |
| **Document status ≠ accounting status** | e.g. Invoice `issued` vs Journal `posted`/`reversed` |
| **Currency shown** | Multi-company clarity |
| **Debit and credit totals visible** on journals | Prove balance |
| **Posting date visible** | Period control |
| **Source document traceability** | Link journal ↔ invoice/receipt/bill/payroll |
| **Posted / reversed state visible** | No silent edits |
| **Audit history accessible** | Who/when/what |
| **No ambiguous destructive actions** | Separate Void / Reverse / Delete / Cancel labels |
| **Operational schedule ≠ accounting documents** | Cheques/installments vs invoices/receipts/journals |
| **Clear money concepts** | Invoice balance, receipt amount, allocation amount, tenant credit, bank reco status — never collapsed into one ambiguous “paid” |

---

## Screen-type standards

### Invoices (RE IM / Cleaning / CO client)
- Show: number, date, due, customer/tenant, company, currency, line VAT, totals, outstanding, status, journal link if posted.
- Actions: Issue/Print/Credit note/Void (permissioned); never “Delete posted” without reverse path.

### Receipts & allocations (RE IM)
- Show: receipt #, method/source, amount, lease, allocation table (target type, doc #, amount), unallocated/credit.
- Distinguish **receipt amount** vs **sum allocated** vs **credit created**.
- Confirm step before post.

### Tenant credit
- Balance, transactions, apply/refund actions with permissions.
- Do not show credit as “payment” without allocation context.

### Security deposits
- Agreed amount, held liability, movements, settlement status, journal refs.
- Separate from rent AR.

### Journal entries
- Header: company, number, date, type, reference type/id, posted/reversed.
- Lines table: account code/name, Dr, Cr, description.
- Footer: total Dr, total Cr, difference (must be zero).
- Reverse action distinct from edit.

### Trial balance / P&L / BS
- Company, period, as-of, currency, generated timestamp.
- Drill-down to GL lines where feasible.
- Export labeled with company/period.

### VAT
- Method stated (GL vs source documents) — stacks differ today.
- Input vs output clearly separated; rates/TRN from config.

### Bank reconciliation
- Statement balance vs book balance vs difference.
- Match status ≠ create-transaction status.
- Undo requires permission; show if undo reverses a journal.

### Supplier bills / payments / payroll postings
- AP/payroll: document status, payment status, journal id, company.
- Payroll: show which GL stack used (Cleaning vs Shared) for the company.

### Reversals / voids
- Require reason; show original + reversal journal ids; block if period locked.

---

## Status vocabulary (recommended UI language)

| Term | Use for |
|------|---------|
| Draft / Issued / Partially paid / Paid / Cancelled | Subledger documents |
| Unposted / Posted / Reversed | Journals |
| Matched / Unmatched / Confirmed | Bank reco |
| Held / Settled / Refunded | Deposits |
| Historical (Legacy) | Legacy RE mode UI only |

Do not invent monetary approval thresholds here.

---

## Explicit non-changes

No financial screens modified in Stage 2.
