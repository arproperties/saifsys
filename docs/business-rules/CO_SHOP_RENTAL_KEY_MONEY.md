# Construction Shop Rental — Key Money (Confirmed)

| Field | Value |
|-------|-------|
| Rule ID | BR-CO-SHOP-KEY-MONEY-001 |
| Module | Construction (Shop Rental) |
| Workflow | Contract charges → AR invoice → Payment Workspace → receipt |
| Status | **Confirmed** |
| Date | 2026-07-27 |

## Confirmed rule

Key Money is a **one-time revenue** charge to the tenant.

- Revenue (not security deposit, deferred revenue, advance rent, or customer credit)
- Invoice-based receivable via existing Contract Charges + `shop_charge` invoices
- Paid through the standard Payment Workspace (partial / full / mixed / credit / reverse / void / reallocate)
- Included in receipts, timeline, financial summary, and reports
- Revenue recognized when the Key Money invoice is **posted** (existing income invoice posting)
- Default income account **4170** (Key Money Income) so it stays separate from Rental Income **4120**
- VAT uses Contract Charges modes (`exclusive` / `inclusive` / `none` + rate); do not hardcode VAT
- Invoice timing (generation only): **Generate Immediately** (default) or **Generate On Contract Start Date**

## Explicit non-goals

- Liability / deferred / deposit treatment
- Spreading Key Money over the lease term
- Changes to `accounting_engine.php`, RE allocation engine, or Payment Workspace architecture
- Using account **4160** (Deposit Recovery Income)

## Deliverables

| Item | Location |
|------|----------|
| Migration | `migrations/construction_shop_rental_phase_key_money.sql` |
| Helpers | `modules/construction/includes/construction_shop_rental_charge_helpers.php` |
| Income account | `4170` Key Money Income |
| Report | `modules/construction/reports/shop_key_money.php` |
