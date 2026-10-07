---
name: lease-cheque-outstanding-fix
description: Explain and fix a real estate lease cheque row that still shows Outstanding / "Partially Collected" on lease_view.php although the tenant already paid. Use whenever the user sends a lease_view screenshot of the "Operational Payment / Cheque Schedule" plus a payment receipt and asks things like "tenant already paid, why outstanding", "why is it showing outstanding against the cheque", "cheque shows partially collected", "receipt shows overpaid / tenant credit", "link this receipt to the cheque", or the row carries an "Inferred" badge. Covers reading the two screenshots, the cause (unlinked receipt + money held as tenant credit), the guarded phpMyAdmin SQL that links the receipt to the cheque on live, and proving it on local first.
---

# Lease cheque row shows outstanding after the tenant paid

The tenant pays a cheque's amount (often a bounced cheque, paid by bank transfer
together with bounce and legal fees). The receipt page shows everything allocated,
but the cheque row on the lease page still shows an outstanding balance. Nothing
is owed; the schedule is just not counting part of the receipt.

He wants short answers, in plain words, and runs live SQL himself in phpMyAdmin.
Explain the cause first and offer the fix; do not write SQL until he picks it.

## 1. Read the two screenshots

Lease page (`lease_view.php?id=N`), the cheque row:
- Amount, Outstanding, status ("Partially Collected"), and the linked receipt.
- An **Inferred** badge next to the receipt = the receipt is not linked to this cheque.

Receipt page (`payment_view.php`), "Invoice Mode Allocation Details":
- Which invoices it paid, and a **Tenant Credit** line.
- If Tenant Credit = the row's Outstanding, this is the case below.

## 2. The cause

- The cheque covers several months, but the lease is invoiced monthly. The receipt
  pays the invoices that exist; the rest has no invoice yet, so it becomes tenant credit.
- For an unlinked receipt, the schedule counts only money allocated to that cheque's
  invoices (`re_cheque_build_receipt_summary` in
  `modules/realestate/includes/receipt_allocation_engine.php`). Tenant credit is not counted.
- A receipt **linked** to the cheque (`re_payments.cheque_id`) counts at its full
  amount, capped at the cheque amount. The app links automatically only when the
  receipt reference contains the cheque number or the amounts are equal; a bank
  transfer with fees on top matches neither.

Left alone, the row clears when the next invoice is issued and the credit is applied.

## 3. The two fixes to offer

1. **Link the receipt to the cheque** (this skill). Data only, one row, no accounting.
2. Change the schedule to count tenant credit. A code change for every lease; plan first.

There is no button in the app to link an existing receipt, so fix 1 is SQL.

If one receipt pays more than one cheque, do not set `cheque_id`. Those use
`re_receipt_cheque_links` (one row per cheque with `amount_applied`), see
`modules/realestate/includes/receipt_multi_cheque_helper.php`. Stop and plan it with him.

## 4. Write the SQL file

Copy `assets/link_receipt_to_cheque.example.sql` to
`live_hotfix/link_<receipt>_to_cheque_<number>.sql` and change the lease number,
cheque number, cheque amount, receipt number and receipt amount. Keep the shape:

- PART 1 looks ids up by lease number, cheque number and receipt number. Never
  hard-code ids; local and live ids differ.
- PART 2 writes only `WHERE @ok = 1` and only if `cheque_id` is still empty, so a
  second run changes nothing.
- PART 3 selects the receipt with its cheque.
- No `SELECT ROW_COUNT()`. phpMyAdmin shows 0 even when the update worked.

## 5. Prove it on local before handing over

A fresh live receipt is usually not in the local DB. Test inside a transaction and
roll back, so his local data is untouched:

1. `beginTransaction()`.
2. Copy an existing cleared invoice-mode receipt of the same lease with
   `INSERT ... SELECT`, overriding `receipt_number`, `amount` and `cheque_id = NULL`.
3. Run the SQL file's statements; then call
   `re_lease_cheque_receipt_summaries($conn, $companyId, $leaseId, false)`
   (`false` = do not write links) and check the cheque: `remaining = 0`,
   `display_status = cleared`. Check the next cheque is unchanged.
4. Run the statements again; PART 1 must say STOP.
5. `rollBack()` and confirm the test receipt is gone.

Connection comes from `includes/config.php`; load the helpers from
`modules/realestate/includes/` (`obligation_engine.php`, `receipt_allocation_engine.php`,
`cheque_*.php`, `receipt_multi_cheque_helper.php`).

## 6. Hand over

Tell him, briefly:
- Paste the whole file and run it once; the parts depend on each other.
- PART 1 should say "OK - will link"; the update banner should say "1 row affected";
  the last table should show the receipt with the cheque number.
- If PART 1 says STOP, nothing changed; ask for the output.
- The row will read Paid / Cleared even if the paper cheque bounced. Tenant credit
  stays as it is until the next invoice.
