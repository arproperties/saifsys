# Inventory — locked decisions (MVP)

## 1) Costing (WAC)
- **Weighted Average Cost at `company_id` + `item_id` only** (MVP). Not per location.
- `inv_item_cost_state` is the single source for average cost and **valued quantity** for that pool.

## 2) Stock count / stock take
- **Document type `stock_take`** (`INV-STK-YYYY-#####`).
- Lines: **physical counted qty** at a location (same fields as other docs); posting computes **variance vs system on-hand** and posts only the difference (in/out + ledger), with optional `unit_cost` on positive variance to adjust WAC like a receipt.

## 3) Opening balance / import
- **Document type `opening_balance`** (`INV-OPN-YYYY-#####`): same posting rules as **receipt** (qty in, `unit_cost` required, updates WAC).
- **CSV import** (`opening_import.php`): creates a **draft** opening-balance document from rows `item_code,location_code,qty,unit_cost` for bulk cutover (no separate inventory subsystem).

## 4) Document numbering
- Pattern: **`INV-{TYP}-{YYYY}-{SEQ5}`** per company, per year, per type (see `inv_doc_type_code()` in code).
- Sequence table: `inv_doc_sequences` (concurrency-safe).

## 5) Barcode strategy (grocery POS–ready)
- **`inv_items.barcode`**: primary scan field (nullable, unique per company).
- **`inv_item_barcodes`**: alternate pack/EAN codes (`is_primary=0`), optional `uom_id` for “case vs unit” later. POS resolves **any** barcode to `item_id`.

## 6) Accounting integration (later layer)
- **Out of scope for Phase 1.** Inventory posts **only** stock + WAC.
- Next layer: on **posted** inventory docs (configurable per doc type), generate **`re_*` journals** via the shared accounting engine (e.g. inventory asset / COGS / GRNI), using `company_id` and document links as audit trail.
