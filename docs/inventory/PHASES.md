## Shared Inventory Engine — Phased Roadmap (Locked)

This document is the implementation roadmap and acceptance criteria for the shared inventory backbone used by all ERP modules.

### Phase 1 — Core inventory engine
- **Schema**: `migrations/inventory_phase0_core_engine.sql` then `migrations/inventory_phase1_additions.sql` (`inv_item_barcodes`)
  - Items, categories, locations, UoMs, documents, lines, lots, serials
  - Stock ledger (`inv_stock_moves`) + on-hand cache (`inv_onhand`)
  - WAC state (`inv_item_cost_state`, company + item only) and doc sequences (`inv_doc_sequences`)
  - Doc types include `opening_balance` and `stock_take` (see `DECISIONS.md`)
- **Posting engine**: `includes/inventory/`
  - Posting validates lot/serial, blocks negative stock by default, updates valued qty pool with outflows and non-costed positive adjustments
- **UI**: `modules/inventory/` — items (with category), categories, UoM, locations, documents (incl. opening & stock take), opening CSV import, reports (on-hand + movement history)
- **Acceptance**:
  - Receipt/opening: to location + unit cost → on-hand and WAC update
  - Issue from location → on-hand and valued qty decrease
  - Transfer → qty moves, WAC unchanged
  - Stock take line: counted qty at **To** location → variance posts; optional unit cost on positive variance
  - Reports: on-hand valuation and recent movements

### Phase 2 — Purchasing & receiving (implemented)
- **Suppliers**: shared `re_vendors`; Inventory → Suppliers
- **PO**: `inv_purchase_orders` / `inv_purchase_order_lines`; draft → **open** (receive) → **closed** / cancelled
- **GRN**: `inv_goods_receipts` (+ optional `reference_no`); posts via linked inventory `receipt` + `inv_post_doc`; partial receipts; optional over-receipt flag
- **Correction**: draft **negative receipt** from posted GRN, then post from Documents; edit `docs/inventory/PHASE2_PURCHASING.md`
- **Purchase history** report (posted GRNs)

### Phase 3 — Material requests & controlled consumption (implemented)
- **Requests**: `inv_request_headers` / `inv_request_lines`; numbering `INV-REQ-YYYY-#####`; approve + posted **issue** in **one DB transaction** (failure → rollback, request stays `pending`)
- **Policy A**: normal users use requests; **Owner/Admin** may create **emergency** manual **issue** documents (`is_emergency_issue` on `inv_doc_headers`)
- **UI**: Inventory → Material requests, request detail (approve/reject), request reports
- **Module entry points** (deep-link to `request_create.php` with context; still posts via `inv_request_create` + validation): Construction project / material issues list, RE maintenance request view, ARS booking view, Cleaning work order edit. Cross-module users need `inventory_requests.create` and access to that business module (or Inventory); Inventory users still use department gate.
- **Acceptance**:
  - Request → approve & issue → posted issue doc + request `completed`; insufficient stock or post error → no partial state
  - Emergency issues auditable; consumption/reporting by `source_module` and operational context fields

### Phase 4 — POS integration preparation (implemented — backend / inventory UI, no POS UI)
- **Schema**: `migrations/inventory_phase4_pos_prep.sql` — VAT rate on items (`sale_price` **ex VAT**), flags (`is_sellable`, `is_purchasable`, `is_consumable`, `is_service`), `inv_item_images` (original + thumbnail paths), `pos_sales` / `pos_sale_lines`
- **Pricing**: `includes/inventory/inv_pricing.php` — line net + tax + inclusive total
- **Images**: `includes/inventory/inv_item_images.php` — upload, thumb (GD), delete, primary; files under `uploads/inventory/items/`
- **POS posting**: `includes/inventory/inv_pos_sale.php` — `pos_post_sale()`; **stock lines** create/post `sale` on `inv_doc_headers`; **service lines** financial only (no `inv_doc_lines`, no stock moves)
- **API**: `api/pos/index.php` — item search, barcode resolve, session cart, totals, `sale_post`
- **Inventory UI**: `items.php` (thumb + ex VAT + VAT %), `item_edit.php` (full edit + gallery)
- **Acceptance**:
  - Barcode lookup via existing `inv_item_barcodes` + `inv_get_item_by_barcode`
  - Mixed POS sale: services recorded on `pos_sale_lines` only; stock items deduct via posted `sale` doc
  - Returns workflow: future phase

### Phase 5 — Retail / Grocery POS (implemented)
- **Schema**: `migrations/inventory_phase5_pos_retail.sql` — `pos_sales.payment_method`
- **Module**: **`modules/grocery/`** — supermarket-only (`MODULE_GROCERY`; company `business_type = supermarket`). Inventory sidebar stays focused on the shared engine; POS UI and backoffice live here.
- **UI**: `modules/grocery/pos_retail.php` — barcode-first cashier screen; VAT-inclusive prices; Cash / Card; `modules/grocery/pos_receipt.php` printable receipt. Legacy URLs under `modules/inventory/pos_*.php` redirect to the Grocery module.
- **API**: `api/pos/index.php` — `client=retail` uses isolated session cart (`pos_retail_cart_*`); `cart_add` merges duplicate **item** lines (single qty); `cart_set_line_qty`, `cart_remove_line`; `sale_post` requires `payment_method` for retail; `source_module` = `pos_retail`
- **Default store**: `inv_company_settings` + **Inventory → Locations** — set “default selling location” so cashiers do not pick a location when it is configured (otherwise dropdown remains)
- **Posting**: unchanged engine — `includes/inventory/inv_pos_sale.php` — `pos_post_sale()` with payment + source module
- **Acceptance**:
  - USB HID scanner: focus on barcode field; Enter resolves item and adds/merges line; stock posted from selected location; service lines do not move stock
  - `grocery_pos.post` required to complete payment (Grocery module permissions)
- **POS backoffice (reporting)**: `pos_dashboard.php`, `pos_sales_list.php`, `pos_sale_view.php`, `pos_reports_items.php`, `pos_report_item.php`, `pos_reports_cashiers.php`, `pos_reports_payments.php` in `modules/grocery/`; shared filters in `includes/grocery/pos_report_helpers.php`; permissions `grocery_reports.view` / `grocery_reports.export` (CSV); RBAC migration `migrations/grocery_module_rbac.sql`. Entry: **Grocery** sidebar, **Select company & module** (choose the **Grocery** module after a supermarket company), or `/modules/grocery/pos_dashboard.php`. Optional deep link: `select-module.php?goto=pos_dashboard&company_id=…` still works if needed.

### Phase 6 — Restaurant POS (future)
- Optional: recipes/BOM and kitchen store locations
- Wastage, modifiers, production issues

### Phase 7 — Grocery scale (future)
- High-volume tuning, packs/multi-barcode, promotions

