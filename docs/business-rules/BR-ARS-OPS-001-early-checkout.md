# BR-ARS-OPS-001 — Early checkout (operational, non-refundable)

| Field | Value |
|-------|-------|
| Rule ID | BR-ARS-OPS-001 |
| Module | ARS |
| Workflow | Check-out |
| Business event | Guest leaves before planned `check_out` |
| Confirmed rule | Early checkout does **not** refund unused stay nights. Planned `check_in`/`check_out` and posted stay revenue remain unchanged. Record `actual_check_out` and show an “Earlier check-out” badge with that date. Cleaning work order service date uses **actual** check-out date. Operator must see a clear no-refund warning before confirming. |
| Supported scenarios | Guest checked in; planned check_out is after today; operator clicks Check Out |
| Exceptions | Security deposit refund remains a separate operator action (unchanged). Late checkout (after planned date) is not “early” — no early badge. |
| Configuration options | None for this ops path. (Phase 2D adapter `early_checkout_refundable` is a separate financial-amendment path; this rule is the live ops product behaviour.) |
| Operational impact | Warning on Check Out; badge on booking; cleaning WO dated to actual departure; unit inventory/availability frees from `actual_check_out` (same-day turnaround allowed; planned `check_out` kept for contract/revenue) |
| Accounting impact | **None** for stay nights (no CN / no journal change). Deposit refund unchanged. |
| Permissions | Same as existing checkout (ARS ops/core as today) |
| Source of confirmation | Product owner (chat) 2026-07-19 |
| Related code paths | `ajax_booking_actions.php` checkout; `ars_early_checkout.php`; `ars_cleaning_trigger.php`; `ars_availability.php`; `booking_view.php` |
| Status | Confirmed |
| Approved by | Product owner |
| Date | 2026-07-19 |

## Relation to Phase 2D BR-ARS-004

BR-ARS-004 described an optional credit-note path when `early_checkout_refundable=1` under Financial Adapter. **This confirmed ops rule supersedes that for day-to-day checkout:** no stay refund on early departure. Adapter CN path must not be used for early checkout unless a future Confirmed rule re-opens refunds.
