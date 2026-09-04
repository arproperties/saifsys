# BR-ARS-FIN-002 — Additional service / damage invoice (locked bookings)

| Field | Value |
|-------|-------|
| Rule ID | BR-ARS-FIN-002 |
| Module | ARS (+ shared RE ledger company) |
| Workflow | Booking View Amendment → additional service or damage charge after confirm/lock |
| Business event | Mid-stay or post-confirm extra charge that must post revenue without rewriting the original stay invoice |
| Confirmed rule | **Draft — interim from Phase 2D BC-11 + BR-ARS-FIN-001.** Extra service/damage creates a **new** `service_invoice` via Financial Adapter. Revenue roles: ADDITIONAL_SERVICE_REVENUE / DAMAGE_REVENUE → HH COA **4140** on GL company **2**. VAT follows booking `vat_mode` / rate. Optional: apply held deposit toward damage (`apply_deposit`) reducing AR. Never rewrite posted stay invoice lines. Ops charge row may be mirrored for Money-tab visibility only. |
| Supported scenarios | Late checkout fee; extra cleaning; guest-caused damage billed as invoice; damage partially covered by deposit apply |
| Exceptions | Simple deposit-only damage/forfeit may use Deposit settle deductions (4140/4410, no VAT) per BR-ARS-OPS-002 instead of a service invoice. Stripe settlement UI out of scope. |
| Configuration options | Amount and description operator-entered (no invented fee schedule). Catalog/`ars_service_catalog` optional later. |
| Operational impact | Quick Actions Additional Service / Damage open Amendment wizard; posts journal on company 2 |
| Accounting impact | DR AR_GUEST (1340) / CR revenue 4140 (+ VAT 2310 as applicable). Deposit apply: DR SECURITY_DEPOSIT / CR AR when used. |
| Permissions | ARS Core finance actions (`create_service_invoice`) |
| Source of confirmation | Phase 2D workshop BC-11 interim; FIN-001 company/COA; **Needs business confirmation** for production fee schedules / mandatory deposit-apply rules |
| Related code paths | `ars_financial_adapter_phase2d.php` (`create_service_invoice`), `ajax_booking_actions.php`, booking Amendment modal |
| Status | **Draft** (UI enabled under interim rules; ratify before treating as Confirmed policy) |
| Approved by | — |
| Date | 2026-07-20 |

## Resolves / updates

- **BC-11:** Damage / additional services as `service_invoice`, not silent charge-only rows when adapter is on.
- Aligns revenue accounts with **BR-ARS-FIN-001** HH leaf codes on company 2 (not legacy ARS-company 4200 examples in older Phase 2D docs).

## Needs business confirmation

1. Whether deposit-apply is mandatory for damage when deposit is held.
2. Any fixed fee menus vs free-text amount (today: free-text).
3. Whether ops-only `add_charge` (no GL) remains allowed on unlocked bookings.
