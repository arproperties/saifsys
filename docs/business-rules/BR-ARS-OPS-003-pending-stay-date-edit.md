# BR-ARS-OPS-003 — Pending stay date correction (unlocked)

| Field | Value |
|-------|-------|
| Rule ID | BR-ARS-OPS-003 |
| Module | ARS |
| Workflow | Booking create → pending → Confirm |
| Business event | Staff corrects check-in / check-out before Confirm |
| Confirmed rule | *(Draft — behaviour implemented; commercial deposit auto-policy not confirmed)* Staff may edit stay dates only while `status=pending` and the booking is **not financially locked**. System rechecks availability and minimum stay, recalculates nights and stay totals via existing pricing engines, and does **not** post revenue / adapter documents. Security deposit is **operator-decided**: Keep current amount (default) or Set a new amount when deposit status is still `none`/`pending`. |
| Supported scenarios | Operator mistyped dates on create; booking still pending; no payment / journal / deposit evidence locking the booking |
| Exceptions | Confirmed or locked bookings require amendment path (out of scope). Deposit already received/settled cannot be changed via this flow. Unit change not included. |
| Configuration options | None |
| Operational impact | “Edit stay dates” on booking view; Preview then Apply; Timeline event `stay_dates_corrected` |
| Accounting impact | **None** at apply time. Confirm later posts original invoice/revenue from corrected totals (legacy or Financial Adapter) |
| Permissions | ARS Core (`preview_stay_dates`, `apply_stay_dates`) |
| Source of confirmation | Product owner direction (chat) 2026-07-19 — implement without inventing deposit commercial auto-rule |
| Related code paths | `ars_booking_preview_pending_stay_dates`, `ars_booking_apply_pending_stay_dates` in `ars_booking_requests.php`; `ajax_booking_actions.php`; `booking_view.php`; `ars-booking-view.js` |
| Status | Draft |
| Approved by | — |
| Date | 2026-07-19 |

## Needs business confirmation

- Whether deposit should ever auto-scale with nights / auto top-up (not implemented; operator Keep/Set only).
- Whether Ops (non-Core) may edit pending dates (currently Core-only like Confirm).
