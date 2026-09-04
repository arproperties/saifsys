# Phase 1 — Full verification package

Use this document to sign off Phase 1 before Phase 2. It reflects the current implementation in `includes/inventory/inv_posting.php` and the Inventory UI under `modules/inventory/`.

**Global rules (all types)**

- Quantities on lines are converted to the item’s **base UoM** using `inv_uom_conversions` when defined; otherwise line UoM is treated like base.
- **Lot** and **serial** rules apply when the item has `track_lot` / `track_serial` enabled (lot required; serial requires qty = 1 in base UoM and a serial on the line).
- **Locations** on a line override header locations: use `location_from_id` / `location_to_id` on the line, else fall back to `inv_doc_headers.location_from_id` / `location_to_id`.
- Posting is **all-or-nothing** in a DB transaction: any error rolls back the whole post.
- **Ledger**: each posted line produces one or more rows in `inv_stock_moves` (append-only). **On-hand** is updated in `inv_onhand`. **WAC** is stored in `inv_item_cost_state` (company + item only).

---

## 1) Test checklist by document type

### Receipt (`receipt`)

| Step | Check |
|------|--------|
| Preconditions | Item active; base UoM set; at least one location; for lot-tracked items, lot on line |
| Create doc | New document type **Receipt**; optional default locations on header |
| Line | Positive qty; **To** location (line or header); **unit cost** ≥ 0 (required) |
| Post | Succeeds; doc status **posted** |
| Data | `inv_onhand` increases at **To** location; `inv_stock_moves` rows with `qty_in` > 0 |
| Reports | Stock on hand and movement history show the receipt |

### Issue (`issue`)

| Step | Check |
|------|--------|
| Preconditions | Stock exists at **From** location (unless negative override — see §3) |
| Line | Positive qty; **From** location |
| Post | On-hand decreases; `qty_out` moves; serial item → serial status `issued` |
| WAC | `qty_valued` decreases; average cost unchanged on pure issue |

### Transfer (`transfer`)

| Step | Check |
|------|--------|
| Line | **From** and **To** set; **From ≠ To** |
| Post | From location decreases; To increases by same base qty |
| WAC | **No change** to average cost or valued qty (company-wide pool) |

### Adjustment (`adjustment`)

| Step | Check |
|------|--------|
| Line qty | **Positive** qty → stock in at chosen location; **negative** qty → stock out (or enter positive qty and use negative in UI if your entry pattern uses sign — engine uses sign of `qty`) |
| Location | At least one of From / To on line or header (effective location = `to` if set, else `from`) |
| Unit cost | For **positive** qty: optional; if provided (≥ 0), behaves like a receipt for WAC; if omitted, valued qty increases at **current WAC** (no change to average until costed receipt-like flows) |
| Post | Matches expected in/out and WAC rules in §2 |

### Opening balance (`opening_balance`)

| Step | Check |
|------|--------|
| Same as receipt | **To** location; **unit cost** required; positive qty |
| Numbering | Doc no pattern `INV-OPN-YYYY-#####` |
| CSV import | `opening_import.php` produces a **draft** only; post manually after review |

### Stock take (`stock_take`)

| Step | Check |
|------|--------|
| Line | **Counted qty** in `qty` (base UoM after conversion); **To** location = count location (line or header) |
| Unit cost | Optional: on **positive variance**, if provided, updates WAC like a receipt; if omitted, valued qty increases at current WAC for that variance qty |
| Post | If counted = system on-hand for that item/lot/location → **line skipped** (no moves). Otherwise variance posts in or out |
| Numbering | `INV-STK-YYYY-#####` |

---

## 2) Expected posting behavior

Legend: **OH** = `inv_onhand` at relevant location/lot; **WAC** = `inv_item_cost_state.avg_cost`; **QV** = `inv_item_cost_state.qty_valued`.

### Receipt

| Area | Behavior |
|------|-----------|
| **On-hand** | **OH** at **To** ↑ by line qty (base) |
| **WAC** | New weighted average: \((QV_{old} \times WAC_{old} + qty \times unit\_cost) / (QV_{old} + qty)\); **QV** ↑ by `qty` |
| **Validation** | `unit_cost` required (not null), must not be &lt; 0; **To** location required; qty ≠ 0; item active |

### Issue (same engine path as `wastage`, `sale`, `return`)

| Area | Behavior |
|------|-----------|
| **On-hand** | **OH** at **From** ↓ |
| **WAC** | **QV** ↓ by `qty` (avg cost unchanged); **WAC** unchanged |
| **Validation** | **From** location required; sufficient stock unless override (§3); serial/lot as per item |

### Transfer

| Area | Behavior |
|------|-----------|
| **On-hand** | **From** ↓, **To** ↑ (same base qty, same lot if used) |
| **WAC** | **No change** |
| **Validation** | Both locations required; must differ |

### Adjustment

| Case | On-hand | WAC / QV |
|------|---------|----------|
| **qty &gt; 0** | **OH** ↑ at effective location | If `unit_cost` provided (≥ 0): receipt-like WAC update and **QV** ↑. If not: **QV** ↑ by qty at unchanged **WAC**; valuation uses current WAC for `value_in` |
| **qty &lt; 0** | **OH** ↓ | **QV** ↓ by \|qty\| at unchanged **WAC**; `value_out` at WAC |

| **Validation** | At least one location (from or to); qty ≠ 0 |

### Opening balance

| Area | Behavior |
|------|-----------|
| **On-hand / WAC** | Same as **Receipt** |
| **Validation** | Same as receipt (**To**, `unit_cost` required, qty &gt; 0 in practice) |

### Stock take

| Area | Behavior |
|------|-----------|
| **On-hand** | Adjusts by **variance** = counted (base) − system OH at count location (same lot bucket) |
| **WAC** | **Positive variance**: optional `unit_cost` → receipt-like WAC + **QV**; else **QV** ↑ at unchanged WAC. **Negative variance**: **QV** ↓; out at current WAC |
| **Validation** | Count location (**To**) required; if variance = 0, line produces **no** stock move |

---

## 3) Negative stock test cases

Default: posting **fails** with an error such as `Insufficient stock (would go negative).` when `applyOut` would drive on-hand below zero.

| # | Case | Setup | Action | Expected |
|---|------|--------|--------|----------|
| N1 | Issue exceeds OH | OH = 10 | Issue 11 from same location/lot | Post **fails** |
| N2 | Transfer exceeds OH | OH = 5 at From | Transfer 6 to another location | Post **fails** |
| N3 | Negative adjustment | OH = 3 | Adjustment qty **−5** at that location | Post **fails** (unless override) |
| N4 | Stock take negative variance | System OH = 8, count = 3 | Post stock take | **Succeeds** (reduces OH by 5); not blocked as “negative stock” in the same way — variance is the intended correction |
| N5 | **Override** | Same as N1 | User has `inventory_adjustments.post` | Post **succeeds**; on-hand may go negative (engine allows override when `inv_post_doc(..., allowNegativeOverride: true)` — wired from document post when user has that permission) |

**How to test N5:** Use a user with **`inventory_adjustments.post`** and without it; only the former should allow negative OH when the code passes `allowNegativeOverride` (see `document_edit.php` post action).

---

## 4) Opening balance CSV test cases (`opening_import.php`)

Required header columns: **`item_code`**, **`location_code`**, **`qty`**, **`unit_cost`** (exact names, case-insensitive match on header row).

| # | Case | CSV content | Expected |
|---|------|-------------|----------|
| O1 | Happy path | Two valid rows, known item + location codes | Draft doc created; two lines; redirect to document edit |
| O2 | Empty file / only header | Header row only, no data | Error: no data rows |
| O3 | Bad header | Missing `unit_cost` column | Error: must list required columns |
| O4 | Unknown item | `item_code` not in `inv_items` | Transaction rolled back; error names line |
| O5 | Unknown location | `location_code` not found (match on `inv_locations.code`) | Rolled back; error |
| O6 | Zero qty | qty = 0 on a row | Error on that line |
| O7 | Negative unit cost | unit_cost &lt; 0 | Error |
| O8 | Blank row skip | Empty item + location + zero qty | Row skipped (if all blank/zero) |
| O9 | Commas in numbers | Use numeric fields without thousands separators; code strips commas from qty/cost | Parses correctly |

After import, **post** the draft via normal document flow; posting rules match **opening balance** (same as receipt).

---

## 5) Permission test cases

Permissions are defined in `includes/permissions/inventory_permissions.php`. Typical UI checks:

| Permission | Where used (examples) | Without it |
|------------|------------------------|------------|
| `inventory_items.view` | Items, Categories | No access to those screens |
| `inventory_items.create` | Create item, create category, create UoM (`uoms.php`) | Create blocked |
| `inventory_locations.view` / `create` | Locations | Blocked |
| `inventory_docs.view` | Documents list, document view | Blocked |
| `inventory_docs.create` | Create document, **opening import** | Blocked |
| `inventory_docs.edit` | Add lines on **draft** | Blocked |
| `inventory_docs.post` | Post document | Post button ineffective / blocked |
| `inventory_adjustments.post` | Passed into `inv_post_doc` as negative-stock override | Cannot post into negative OH when stock insufficient |
| `inventory_reports.view` | Reports | Blocked |
| `inventory_settings.view` / `edit` | Reserved for settings screens | As implemented |

**Note:** `inventory_docs.void` exists in the permission list but **there is no void/reversal workflow implemented** in Phase 1 inventory screens — see §6.

Also verify **module** access (`MODULE_INVENTORY`) and **department** (`DEPT_INVENTORY`) match your role matrix for non-admin users.

---

## 6) Rollback / correction if a posted document is wrong

**What Phase 1 does *not* do**

- There is **no** “unpost” or **void** API that reverses `inv_stock_moves`, restores `inv_onhand`, and rolls back `inv_item_cost_state` in one click.
- `inv_stock_moves` is **append-only** by design.

**Practical correction methods (operational)**

1. **Compensating document (recommended)**  
   Post a second document that reverses the **effect**:
   - Wrong receipt / opening → use **issue** (or negative **adjustment**) from the same location/lot for the same qty; for WAC, note that issues consume at **current** average (you may need a manual adjustment later if amounts must tie to accounting — future GL layer).
   - Wrong issue → **receipt** or positive **adjustment** with appropriate cost rules.
   - Wrong transfer → reverse transfer (transfer back).
   - Wrong stock take → new stock take or adjustment with notes referencing the error.

2. **Traceability**  
   Use **notes** on the compensating doc and, when Phase 2+ links exist, `source_module` / `source_table` / `source_id` (already on headers/moves for future use).

3. **Data / support path**  
   Direct SQL edits to posted history are **not** recommended; if used, they must reconcile `inv_onhand`, `inv_item_cost_state`, and audit expectations.

**Future (not Phase 1)**  

- Formal **void** with reversing entries, or integration journals via `re_*`, can be added later with clear audit rules.

---

## Sign-off

| Area | Tester | Date | Pass / Fail |
|------|--------|------|-------------|
| Receipt | | | |
| Issue | | | |
| Transfer | | | |
| Adjustment | | | |
| Opening balance + CSV | | | |
| Stock take | | | |
| Negative stock + override | | | |
| Permissions | | | |
| Correction policy understood | | | |

Wait for explicit confirmation after testing before starting **Phase 2**.
