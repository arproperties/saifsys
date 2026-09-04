# M7 Vendor Advance Refunds — Closure

**Status:** COMPLETE / **GO**  
**Date:** 2026-07-23  
**Stack:** Shared RE `re_*` only (Cleaning `gl_*` untouched)  
**Scope:** Refund unused Vendor Advances to Bank/Cash. No redesign of M1–M6.

---

## 1. Files changed

### New
- `migrations/re_vendor_advance_refunds.sql`
- `modules/realestate/includes/vendor_advance_refund_helper.php`
- `modules/realestate/accounting/vendor_advance_refunds.php`
- `modules/realestate/accounting/vendor_advance_refund_add.php`
- `modules/realestate/accounting/vendor_advance_refund_view.php`
- `tools/test_m7_vendor_advance_refunds.php`
- `docs/realestate/vendor-advance-refunds-m7-closure.md` (this file)

### Updated
- `modules/realestate/includes/vendor_advance_helper.php` — remaining = advance − applied − VAT − refunded; source FIFO; payment reverse blocked if refunds posted
- `modules/realestate/includes/vendor_advance_vat_helper.php` — net remaining delegates to payment remaining (includes refunds)
- `modules/realestate/includes/vendor_ap_helper.php` — loads refund helper
- `modules/realestate/includes/re_layout_header.php` — Advance Refunds nav
- `modules/realestate/accounting/vendor_payments.php` — Refunded column + Refund button
- `modules/realestate/accounting/vendor_statement.php` — refunds period table
- `modules/realestate/accounting/vendor_ledger.php` — informational Adv Refund rows
- `docs/realestate/vendor-advances.md` — M7 complete

---

## 2. Database changes

Additive / idempotent:

```sql
CREATE TABLE IF NOT EXISTS re_vendor_advance_refunds (...);
```

Columns: company/vendor/payment, refund_date, amount, bank_account_id, reference, notes, journal_id, status (posted|reversed), posted/reversed audit fields, reversal_journal_id.

**Deploy (human approval required on production):**

```bash
mysql -h 127.0.0.1 -u <user> -p <database> < migrations/re_vendor_advance_refunds.sql
```

Repeatability: migration executed twice locally — second run no-op (table exists).

---

## 3. Journal examples

### Refund (full or partial)

| Account | Debit | Credit |
|---------|-------|--------|
| Bank / Cash (payment bank GL) | X | |
| 1410 Vendor Advances / Prepayments | | X |

Does **not** touch AP, expense, Input VAT, or Output VAT.

### Refund reversal

Engine `reverse_journal` + sub-ledger restore Dr 1410; vendor advance balance increased by X; refund status → `reversed`.

---

## 4. UI changes

- Nav: **Advance Refunds**
- List / New Refund / View (history, journal drill-down, reverse, audit)
- Payments Made: **Refunded** column; **Refund** on eligible rows (posted advance remaining, no posted Advance VAT)
- Vendor SOA: refunds-in-period card
- Vendor Ledger: Adv Refund informational lines (AP balance unchanged)

---

## 5. Business rules (implemented)

- Only posted advances; amount ≤ payment remaining and vendor balance
- Full / partial / multiple refunds; independent per payment
- Posted Advance VAT on source payment → **block refund** until VAT reversed (no auto VAT journals)
- Reversal restores advance + audit history
- Company fail-closed, CSRF, period locks via journal engine, FOR UPDATE, transactions

**Remaining formula:**  
`Original − Applied − Posted Advance VAT − Posted Refunds`

---

## 6. Test results

`php tools/test_m7_vendor_advance_refunds.php` — outer tx rolled back.

| ID | Scenario | Result |
|----|----------|--------|
| T01 | Full refund Dr Bank / Cr 1410 | PASS |
| T02 | Partial refund | PASS |
| T03 | Multiple refunds | PASS |
| T04 | Exceed available | PASS (rejected) |
| T05 | After VAT posting | PASS (blocked) |
| T06 | Refund reversal | PASS |
| T07 | Locked period helper | PASS |
| T08 | Duplicate over-refund | PASS |
| T09 | Savepoint rollback | PASS |
| T10 | Company isolation | PASS |
| T11 | Report reconciliation | PASS |
| T12 | M1–M6 regression + reverse blocked | PASS |
| T13 | No AP/VAT/Expense on JE | PASS |
| T14 | Independent advances | PASS |

**14/14 PASS → GO**

### Regression
- M5 matrix: **27/27 PASS**

### PHP lint
All touched PHP files: **No syntax errors detected**

### Migration repeatability
Ran `re_vendor_advance_refunds.sql` twice — OK

---

## 7. Recommendation

**GO** for Vendor Advance Refunds (M7), completing the Vendor Advances lifecycle (M1–M7).

Production: deploy code + run migration with backup and human approval. Smoke: full refund, partial, VAT block, reverse refund, Payments Made columns, SOA refunds section.
