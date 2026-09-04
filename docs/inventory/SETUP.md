## Inventory Engine — Setup

### 1) Apply database migrations
Run on your MySQL database, in order:

1. `migrations/inventory_phase0_core_engine.sql`
2. `migrations/inventory_phase1_additions.sql` (alternate barcodes table)
3. `migrations/inventory_phase1_fix_lot_id_sentinel.sql` (lot_id `0` = no lot; required for MySQL PK on `inv_onhand`)

If you already ran phase 0 before this fix, run migration **3** once so existing `NULL` lot rows become `0` and columns match the engine.

4. `migrations/inventory_phase2_purchasing.sql` (purchase orders + GRN). Requires `re_vendors` (existing vendor migrations).
5. `migrations/inventory_phase3_requests.sql` (material requests + document context columns + emergency-issue flag).  
   If you ran an older phase 3 file without `request_date`, run `migrations/inventory_phase3_request_date_column.sql` once (skip if the column already exists).
6. `migrations/inventory_phase3_request_location_nullable.sql` — allows requests without a warehouse at creation (location is chosen at approval). Skip if `location_from_id` is already nullable.
7. `migrations/inventory_phase4_pos_prep.sql` — POS prep: `vat_rate`, item flags (`is_sellable`, `is_purchasable`, `is_consumable`, `is_service`), `inv_item_images` (original + thumb paths), `pos_sales` / `pos_sale_lines`. **Convention:** `sale_price` is **VAT-exclusive**; VAT is computed using `vat_rate`. Ensure `uploads/inventory/items/` is writable by the web server (the app falls back to `uploads/temp/inventory_items/` if the primary tree cannot be created).
8. `migrations/inventory_phase5_pos_retail.sql` — adds `pos_sales.payment_method` (`cash` / `card`) for the retail POS screen. Required before posting sales from the **Grocery** module (**Retail POS**).
9. `migrations/inventory_phase5b_default_pos_location.sql` — `inv_company_settings.default_pos_location_id` (per company). Configure under **Inventory → Locations** so **Grocery → Retail POS** can use a fixed selling location without asking the cashier.
10. `migrations/grocery_module_rbac.sql` — seeds **Grocery** module access for roles that already have Inventory (`role_departments` for `grocery_pos` and `grocery_backoffice`, plus `role_modules`). Run after deploying the Grocery module code. Existing installs that still have legacy `department='grocery'` should run `migrations/grocery_barber_department_split.sql` once.
11. *(Optional)* `migrations/inventory_seed_rani_mass_grocery_company.sql` — inserts company **Rani Mass Grocery LLC** (`code` = `RANI_GROCERY`) if missing. Assign users to this company in Core / user–company settings as needed. Business type must be **`supermarket`** so the **Grocery** module appears in **Select company & module**.

Design choices (WAC scope, numbering, stock take, opening import, barcodes, future GL layer) are summarized in `docs/inventory/DECISIONS.md`.

### 2) Module access
Inventory is a shared module (`MODULE_INVENTORY`). For users who are not Owner/Admin, grant access by assigning the **Inventory** department:

- `module='inventory'`, `department='inventory'` in `role_departments`

**Grocery** (`MODULE_GROCERY`) is a separate module for supermarket companies (`business_type = supermarket`). It hosts retail POS and POS backoffice; it still uses the shared inventory engine and `api/pos`. Grant:

- `module='grocery'`, `department` in (`grocery_pos`, `grocery_backoffice`) in `role_departments`, plus `role_modules` row for `grocery` (see migration `grocery_module_rbac.sql`). Use **System Settings → Departments** to assign POS only, back office only, or both.

Grocery screens run `ensure_current_company_supports_module(MODULE_GROCERY)` so the session company is a **supermarket** you can use when you had another business type selected. **Locations, default POS, items, and `pos_sales` are all per `company_id`:** configure Retail POS defaults under **Inventory → Locations** with the **same company** selected in the sidebar as your supermarket (not only a holding/real-estate entity). Use **Switch company** in Inventory or Grocery sidebars if you work with multiple companies.

### 3) Minimum master data needed
- Add at least **one UoM** (Inventory → UoM, or insert into `inv_uoms`; examples: `PCS`, `KG`, `L`)
- Create at least **one location** (Inventory → Locations)
- Create at least **one item** (Inventory → Items)

### 4) RBAC (requests)
Grant `inventory_requests.view`, `inventory_requests.create`, and for approvers `inventory_requests.approve` / `inventory_requests.reject` as needed.  
To open **Request inventory materials** from Construction, Real Estate, ARS, or Cleaning, the user needs `inventory_requests.create` on the **inventory** permission set, plus access to that module (or Inventory). Owner/Admin bypass applies as elsewhere.

**Where the form opens (module UI):** Cleaning → `operation/material_request_create.php` (sidebar: **Material request**). Real Estate → `modules/realestate/material_request_create.php`. Construction → `modules/construction/material_request_create.php`. ARS → `modules/ars/material_request_create.php`. Inventory-only → `modules/inventory/request_create.php`. Approval and the posted issue still live under Inventory (`request_view.php`).

### 5) POS API (Phase 4 backend, no POS UI)
JSON endpoints (session login required): `api/pos/index.php?action=...`  
Actions: `search`, `barcode`, `cart_get`, `cart_clear`, `cart_set_location` (POST `location_id`), `cart_add` (POST `item_id`, `qty`, optional `uom_id`), `cart_totals`, `sale_post` (POST optional `location_id`, `notes`). **Auth:** user must have company access and either **Inventory** (`inventory_items.view` / `inventory_docs.post`) or **Grocery** (`grocery_pos.view` / `grocery_pos.post`) for the current company. Service items (`is_service`) do not create inventory documents; stock lines post a `sale` document via `inv_post_doc`.

### 6) Smoke test
- Create a **receipt** document (set **To** location on the line or header)
- Add a line (qty + unit cost)
- Post
- Check Inventory → Reports: stock on hand and movement history should update
- Optional: **Material requests** → new request → approve & issue (stock must exist at the from-location for issue posting)
