---
name: lease-rebuild-orphan-links-fix
description: Explain and fix a real estate lease page that went wrong after its cheque schedule was rebuilt (Edit Lease save, Financial Rebook, Repair schedule) - the "Operational Collection Comparison" shows a Difference although everything is paid, and/or a cheque row shows Overdue / full Outstanding with no linked receipt although the tenant paid. Use whenever the user sends a lease_view screenshot and asks "why showing difference", "already collected, something is broke", "cheque shows overdue but tenant paid", or writes the sum of the payment (e.g. "13,850 + 3,150"). Covers the cause (receipts and merged service charges still pointing at deleted cheque rows), the code fix already live, the one-statement phpMyAdmin SQL that re-links the receipts, and proving it on local first.
---

# Lease page broken after a schedule rebuild

Rebuilding a lease's cheque schedule deletes the old `re_lease_installments` /
`re_post_dated_cheques` rows and creates new ones. Anything still pointing at the old
ids is orphaned:

| Points at the old row | Symptom on lease_view.php |
|---|---|
| `re_billing_items.installment_id` (service charge merged into a cheque) | **Difference** = that charge (e.g. 3,150 parking); Expected is too high |
| `re_payments.cheque_id` (receipt linked to the cheque) | Cheque row **Overdue**, full outstanding, no linked receipt |

Worked case: PARKPLAC-501-0001 (lease 355), rebuilt 15 Oct 2026. Old cheque 1 = id 4843 /
installment 4863; new = 5747 / 5744.

He wants short, plain answers and runs live SQL himself in phpMyAdmin.

## 1. Difference = a service charge: already fixed in code

`re_service_charge_schedule_adjustment_total` in
`modules/realestate/includes/service_charge_schedule_helper.php` now joins
`re_lease_installments`, so a bill on a deleted installment no longer counts. Live since
2026-10-26. If a Difference still shows, it is something else — check the live file
has the `JOIN re_lease_installments li` line before anything else.

## 2. Find the orphaned receipts (local copy of live)

```sql
SELECT p.id, p.receipt_number, p.amount, p.payment_date, p.cheque_id
FROM re_payments p
LEFT JOIN re_post_dated_cheques c ON c.id = p.cheque_id
WHERE p.lease_id = ? AND p.cheque_id IS NOT NULL AND c.id IS NULL;
```

Then the current cheques: `SELECT id, cheque_number, cheque_amount, status, installment_id
FROM re_post_dated_cheques WHERE lease_id = ?`. Match receipts to the new cheque by what
they paid (date, amount, the old cheque's position). If the receipts add up to more than
the cheque (e.g. cheque + a service charge), that is fine: a linked receipt counts in full,
capped at the cheque amount.

If one receipt must be split over **several** cheques, stop: that uses
`re_receipt_cheque_links`, plan it with him (see the lease-cheque-outstanding-fix skill).

## 3. Write the SQL — ONE statement

Copy `assets/relink_receipts.example.sql` to `live_hotfix/relink_<lease>_receipts_to_<cheque>.sql`.
Change lease number, cheque number + amount, receipt numbers + amounts.

- Look everything up by lease number, cheque number and receipt number; never hard-code ids.
- `WHERE oc.id IS NULL` = only receipts whose cheque no longer exists, so a rerun changes nothing.
- **No multi-part file with a check SELECT.** phpMyAdmin stops after the first SELECT that
  returns rows; the UPDATE never runs and the user sees "OK - will link" with nothing changed.
  (This happened on PARKPLAC-501-0001.) Give the check query separately, after.

## 4. Prove it on local

Copy `assets/test_relink.example.php` to the scratchpad, edit its CHANGE ME block and run
it with `php`. It runs inside a transaction and rolls back. Expect: target cheque
`remaining=0 status=cleared`, next cheque unchanged, second run 0 rows, cheque_ids restored
after rollback.

## 5. Hand over

- Run the statement on live; it should say **N rows affected** (N = receipts).
- Then this check; both rows should show the new cheque number:
  ```sql
  SELECT p.receipt_number, p.amount, p.cheque_id, c.cheque_number
  FROM re_payments p LEFT JOIN re_post_dated_cheques c ON c.id = p.cheque_id
  WHERE p.receipt_number IN ('...', '...');
  ```
- After refresh: cheque Paid / Cleared, 0 outstanding, Overdue count drops.
- A linked receipt may show "5,779.17 (of 15,425.00)": that is the part matched to the
  cheque's invoices issued so far. Normal; the cheque total already counts in full.

## 6. Still open

On the local copy (Oct 2026), 31 receipts on 13 leases (224,172 AED) pointed at deleted
cheques, plus 23 `re_receipt_cheque_links` rows. The rebuild itself does not carry links
over to the new rows — a code fix needs a plan first. Offer it; don't do it unasked.
