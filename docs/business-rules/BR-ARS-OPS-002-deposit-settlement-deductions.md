# BR-ARS-OPS-002 — Deposit settlement with deductions (no VAT)

| Field | Value |
|-------|-------|
| Rule ID | BR-ARS-OPS-002 |
| Module | ARS |
| Workflow | Security deposit settle / refund |
| Business event | Operator settles held deposit with optional itemized deductions and cash/bank refund |
| Confirmed rule | Operator may release Guest Deposits Held (**2200**) via a single settlement journal: cash/bank refund portion + itemized deductions. Deduction types: `damage` → **4200** Extra Charges; `lost_item` / `other` → **4900** Other Revenue. **No VAT** on deposit deductions. Financial Adapter remains OFF for this ops path (posts via shared RE engine like existing deposit receive/refund). Stay revenue / early-checkout rules unchanged. |
| Supported scenarios | Deposit status `received` or `partially_refunded`; refund ≥ 0; deduction amounts > 0 with notes; refund + deductions ≤ remaining held; at least one of refund or deductions > 0; partial or full clear of remaining |
| Exceptions | Phase 2D damage service invoice + VAT + apply-to-AR (BR-ARS-006) is out of scope. Stripe deposit GL timing unchanged. |
| Configuration options | None |
| Operational impact | Deposit tab “Settle deposit” modal with deduction rows; Timeline financial event; Deposit tab shows forfeited amount + settlement journal |
| Accounting impact | One balanced JV `ars_deposit_settlement`: DR 2200 = refund + deductions; CR cash/bank = refund; CR 4200 / 4900 = deduction buckets. Pure refund-only (no deductions) remains DR 2200 / CR cash (`ars_deposit_refund`). |
| Permissions | Same as existing `refund_deposit` |
| Source of confirmation | Product owner (chat) 2026-07-19 — Option 1 simple settle |
| Related code paths | `ars_accounting.php` `ars_post_deposit_settlement`; `ajax_booking_actions.php` `settle_deposit` / `refund_deposit`; `ars_deposit.php`; `booking_view.php`; `ars-booking-view.js` |
| Status | Confirmed |
| Approved by | Product owner |
| Date | 2026-07-19 |

## Relation to BC-10 / BC-11 / Phase 2D

This ops rule confirms a **no-VAT liability release** for day-to-day ARS with adapter OFF. It does not replace the future adapter damage-invoice path. BC-10/BC-11 remaining items (Stripe GL timing, invoice+VAT damage) stay open for later confirmation.
