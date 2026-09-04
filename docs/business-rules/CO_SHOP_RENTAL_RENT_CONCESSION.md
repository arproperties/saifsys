# Construction Shop Rental — Rent Concession / Free Rent (Confirmed)

| Field | Value |
|-------|-------|
| Rule ID | BR-CO-SHOP-RENT-CONCESSION-001 |
| Module | Construction (Shop Rental) |
| Workflow | Contract create / schedule generation |
| Status | **Confirmed** |
| Date | 2026-07-27 |

## Confirmed rule

Rent Concession **extends occupancy**, it does not reduce the chargeable rent period.

- `start_date` / `end_date` = **Occupancy Period** (legal lease)
- Chargeable Period = Occupancy months that do **not** overlap the concession window
- `rent_amount` remains total payable net rent for the **chargeable** term (BR-CO-SHOP-007)
- Rent schedules/invoices are generated **only** for chargeable months
- Never create AED 0 invoices; never invoice-then-credit/void for free months
- Deposit, Key Money, Commission remain independent
- Renewals and early termination use Occupancy dates
- Cheques continue to use Occupancy dates (no cheque redesign)
- No accounting / Payment Workspace / allocation engine changes

### Duration UX

Users enter concession **duration** (value + unit: months or days). For Beginning / End positions, From/To dates are calculated automatically from occupancy dates; Custom allows manual From/To.

### Stored concession value

After rent schedule generation, `concession_value_total` is stored on the contract for management reporting (building / project / customer / agent / period analysis).

## Explicit non-goals

- Accounting recognition of free rent as deferred/credit
- Multi-window concessions (v1 = one contiguous window)
- Retroactive concession on already-invoiced months

## Deliverables

| Item | Location |
|------|----------|
| Migration | `migrations/construction_shop_rental_phase_rent_concession.sql` |
| Helpers | `modules/construction/includes/construction_shop_rental_concession_helpers.php` |
| BR | this file |
