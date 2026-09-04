# Phase 3 — Inventory requests & controlled consumption (design for approval)

**Status:** **Implemented** — single-step approve + issue (transactional rollback on failure), policy A manual issues (Owner/Admin emergency only), UI under Inventory → Material requests.

**Principles:** No direct stock issues from business modules. All consumption flows **request → approval (full/partial/reject) → automatic inventory `issue` document** → request **completed**. Stock moves only after approval; costing remains **WAC** via existing `issue` posting.

---

## 1) Database schema

### Naming

Use two tables (clearer than a single `inv_requests` name for both):

- **`inv_request_headers`**
- **`inv_request_lines`**

### `inv_request_headers`

| Column | Type | Notes |
|--------|------|--------|
| `id` | BIGINT PK AI | |
| `company_id` | INT NOT NULL | |
| `request_no` | VARCHAR(50) NOT NULL | Unique per company; e.g. `INV-REQ-YYYY-#####` via `inv_doc_sequences` |
| `request_type` | VARCHAR(20) NOT NULL | `issue` (MVP), `return`, `transfer` reserved for later |
| `source_module` | VARCHAR(40) NOT NULL | e.g. `cleaning`, `realestate`, `construction`, `ars`, `inventory` |
| `source_table` | VARCHAR(80) NULL | Originating entity table name |
| `source_id` | INT NULL | Originating entity PK |
| `location_from_id` | INT NOT NULL | Store/warehouse to issue **from** (validated against `inv_locations`) |
| `status` | VARCHAR(20) NOT NULL | `pending`, `approved`, `rejected`, `completed` |
| `requested_by` | INT NULL | User id |
| `approved_by` | INT NULL | User id |
| `approved_at` | DATETIME NULL | Set when moved to approved (and optionally when execution starts) |
| `rejected_by` | INT NULL | Optional |
| `rejected_at` | DATETIME NULL | Optional |
| `rejection_reason` | VARCHAR(255) NULL | |
| `issue_doc_id` | INT NULL | `inv_doc_headers.id` of the generated **issue** (posted) |
| `notes` | TEXT NULL | Free text |
| **Operational context (nullable; filterable in reports)** | | |
| `context_building_id` | INT NULL | RE / ARS |
| `context_unit_id` | INT NULL | RE / ARS |
| `context_project_id` | INT NULL | Construction |
| `context_booking_id` | INT NULL | ARS |
| `context_work_order_id` | INT NULL | RE maintenance / WO |
| `context_housekeeping_id` | INT NULL | ARS housekeeping ref (or VARCHAR ref if no table) |
| `context_cleaning_job_id` | INT NULL | Cleaning optional |
| `context_json` | JSON NULL | Spillover: team id, task label, etc. |
| `created_at` / `updated_at` | DATETIME | |

Indexes (minimum): `(company_id, status)`, `(company_id, source_module, created_at)`, `(company_id, context_building_id)`, `(company_id, context_unit_id)`, `(company_id, context_project_id)`, `(company_id, context_booking_id)`, `(issue_doc_id)`.

**Validation rules (application layer):**

| Module | Required context (MVP policy) |
|--------|-------------------------------|
| Real Estate | `context_building_id`; `context_unit_id` optional; `context_work_order_id` optional but recommended when WO-driven |
| Construction | `context_project_id`; optional task/site in `context_json` |
| ARS | `context_building_id`, `context_unit_id`; `context_booking_id` / `context_housekeeping_id` optional |
| Cleaning | No required FK; optional `context_cleaning_job_id` / JSON |

### `inv_request_lines`

| Column | Type | Notes |
|--------|------|--------|
| `id` | BIGINT PK AI | |
| `header_id` | BIGINT NOT NULL | FK → `inv_request_headers.id` |
| `company_id` | INT NOT NULL | Denormalized for queries |
| `item_id` | INT NOT NULL | |
| `uom_id` | INT NOT NULL | |
| `requested_qty` | DECIMAL(18,4) NOT NULL | |
| `approved_qty` | DECIMAL(18,4) NULL | Set at approval; `NULL` until approved |
| `lot_number` / `expiry_date` / `serial_number` | As in `inv_doc_lines` | Optional; required when item flags demand it |
| `sort_order` | INT DEFAULT 0 | |
| `line_notes` | VARCHAR(255) NULL | |
| `created_at` | DATETIME | |

Index: `(header_id)`, `(company_id, item_id)`.

### Optional: extend `inv_doc_headers` (recommended)

So the **issued** document and reports match the request without parsing notes:

Add nullable columns (same names as request context for 1:1 copy on issue creation):

- `context_building_id`, `context_unit_id`, `context_project_id`, `context_booking_id`, `context_work_order_id`, `context_housekeeping_id`, `context_cleaning_job_id` (all INT NULL)

Populate when creating the **issue** draft from an approved request. Existing `source_module` / `source_table` / `source_id` should point at the **business** object (WO, project, booking row); request can additionally set `source_table` = `inv_request_headers` and `source_id` = request id for **trace request → issue** (optional but useful).

---

## 2) Workflow logic

```
[pending] --(approve, set approved_qty)--> [approved]
[pending] --(reject)--> [rejected]
[approved] --(execute: create+post issue)--> [completed]
```

1. **Create request** (module or inventory UI): `status = pending`, lines have `requested_qty` only; `approved_qty` NULL.
2. **Approve** (inventory role): For each line, set `approved_qty` (≤ `requested_qty`, or policy for explainable overrun). Set `approved_by`, `approved_at`, `status = approved`. *Alternatively* split “approval” and “issue” into two steps; your spec says **on approval** auto-issue — so implement **single transaction**: validate → persist approval fields → create draft `issue` doc → `inv_add_line` per line with `qty = approved_qty`, `location_from_id` from header → `inv_post_doc` → set `issue_doc_id`, `status = completed`. If post fails, **rollback** approval state back to `pending` (or leave `approved` without issue — product choice; rollback is safer).
3. **Reject**: `status = rejected`, no inventory document.
4. **Partial approval**: Some lines `approved_qty` = 0 means skip line or reject line-level — define: **skip lines with approved_qty 0** or **require reject entire request**; recommend **skip zero lines**, issue only positive `approved_qty`.
5. **Return / transfer** `request_type`: schema ready; execution path deferred (no stock effect in MVP beyond `issue`).

**Stock rules:** `inv_post_doc` with `allowNegativeOverride = false`; no negative stock.

**Costing:** Unchanged — issue uses **WAC** from `inv_item_cost_state`.

---

## 3) UI changes

### Inventory module (`modules/inventory/`)

| Screen | Purpose |
|--------|---------|
| `requests.php` | List filters: status, module, date, building, unit, project, booking |
| `request_edit.php` or split **view** + **approve** | Detail: header context, lines, actions **Approve & issue** / **Reject** |
| Approval form | Editable `approved_qty` per line; optional rejection reason |
| Optional: create request manually (internal store requisition) with same context fields |

### Business modules (per module, minimal pattern)

| Element | Behavior |
|---------|----------|
| **Request Materials** (or label per module) | Opens form: items + qty + UoM; **module-specific required context** (building/unit/project/booking/WO); `location_from` if not defaulted |
| Submit | POST to shared handler `includes/inventory/inv_requests_api.php` or module-specific script that calls `inv_request_create()` |
| List link | “My requests” optional |

**Real Estate:** building + optional unit + optional WO id pickers (search/select from existing RE tables).  
**Construction:** project (+ optional task ref in JSON).  
**ARS:** building, unit, optional booking / housekeeping.  
**Cleaning:** items + qty; optional job ref later.

---

## 4) Integration approach

1. **Single library** `includes/inventory/inv_requests.php`: create/update request, approve+execute (transactional issue), reject, list queries for reports.
2. **No** `inv_post_doc` calls from Cleaning/RE/Construction/ARS except **inside** `inv_requests` after approval (or only Inventory module calls the executor).
3. **Permissions:** e.g. `inventory_requests` (`view`, `create`, `approve`, `reject`) — `create` for module roles that may request; `approve` for storekeeper/admin.
4. **API contract:** `create_request(company_id, source_module, source_table, source_id, location_from_id, lines[], context*)` returns `request_id` / `request_no`.
5. **Breaking change policy:** Remove or gate any existing “create issue document” entry points in other modules behind feature flag or deprecate in favor of request flow (coordinate per module).

---

## 5) Files to create / modify

### Create

| Path | Role |
|------|------|
| `migrations/inventory_phase3_requests.sql` | `inv_request_headers`, `inv_request_lines`; optional `ALTER inv_doc_headers` context columns |
| `includes/inventory/inv_requests.php` | CRUD, approve+issue, validation by module |
| `modules/inventory/requests.php` | List |
| `modules/inventory/request_view.php` (or `_edit`) | Detail + approve/reject |
| `includes/permissions/...` or extend `inventory_permissions.php` | `inventory_requests` keys |
| Module stubs (incremental): e.g. `modules/cleaning/request_materials.php`, similar for RE/Construction/ARS — **or** one generic `modules/inventory/external_request.php?module=` with dynamic form |

### Modify

| Path | Role |
|------|------|
| `includes/inventory/inv_posting.php` | Only if issue header needs new context columns copied into INSERT (or set in wrapper after `inv_create_doc`) |
| `includes/permissions.php` | Register `inventory_requests` category |
| `modules/inventory/includes/inv_layout_header.php` | Nav: Requests |
| `docs/inventory/PHASES.md` | Replace old “Phase 3” bullet with request workflow |
| `docs/inventory/SETUP.md` | New migration file |

### Reporting

| Path | Role |
|------|------|
| `modules/inventory/reports_requests.php` (or extend `reports.php`) | By module, approved vs rejected, consumption by request, trace to `issue_doc_id` / `inv_stock_moves` |

Queries join: `inv_request_headers` → `inv_doc_headers` ON `issue_doc_id` → `inv_stock_moves` ON `doc_id` + `doc_type = issue`.

---

## 6) Test checklist

### Schema & permissions

- [ ] Migration applies on clean DB; `request_no` unique per company
- [ ] Role with `inventory_requests.approve` can approve; without cannot

### Create & validation

- [ ] RE request without `building_id` rejected (or validation message)
- [ ] Construction without `project_id` rejected
- [ ] ARS without building/unit rejected per policy
- [ ] Cleaning request with only items/qty succeeds
- [ ] Lot/serial items rejected without required fields on lines

### Approval & stock

- [ ] Pending request: no `inv_stock_moves` for those items from this flow
- [ ] Approve full qty: `issue` posted, `inv_request_headers.status = completed`, `issue_doc_id` set, on-hand down, WAC qty_valued down
- [ ] Approve partial qty: issued qty = `approved_qty`; request completed
- [ ] Reject: no issue doc, status rejected, no stock change
- [ ] Insufficient stock: post fails, transaction rollback, request stays pending or approved without completion (per chosen rule)

### Traceability

- [ ] `inv_doc_headers` (issue) carries context columns matching request
- [ ] Report filters by `context_building_id` / `context_project_id` / `source_module` work
- [ ] Drill-down: request → issue doc → stock movements

### Module integration

- [ ] No module path creates draft `issue` without request (grep / manual test)
- **Implemented (deep links):** `includes/inventory/inv_request_links.php` builds `request_create.php?…` with `source_module`, `source_table`, `source_id`, and context IDs. Entry points: **Construction** `project_view.php` / `material_issues.php` (project filter), **Real Estate** `maintenance_view.php`, **ARS** `booking_view.php`, **Cleaning** `operation/order_edit.php`. Optional helper: `operation/includes/cleaning_inventory_bridge.php` → `cleaning_inv_material_request_url()`.

### Regression

- [ ] Phase 1/2 flows (receipt, GRN, manual issue from Inventory **if still allowed**) unchanged — **note:** policy may forbid manual issue; if forbidden, remove or restrict Inventory → Documents issue creation in UI

---

## Policy decision (confirm before implementation)

**Manual issue documents:** Phase 1 allows **Issue** from Inventory → Documents. Phase 3 rule says “all issues must go through request approval.” Options:

- **A)** Hide/disable **Issue** doc type for non-admin users; only `inventory_requests` execution creates issues; **or**
- **B)** Allow emergency manual issue only for role `inventory_adjustments` / admin with audit flag

State preference when approving this design.

---

## Approval

Implement only after sign-off on: table/column names, operational context columns, single-step “approve + issue” vs two-step, and manual issue policy (A vs B).
