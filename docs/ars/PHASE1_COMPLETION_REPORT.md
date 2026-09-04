# ARS Phase 1 Completion Report — Operational Hardening & Financial Lock Foundations

**Date:** 2026-07-17  
**Status:** **Approved / Complete** (formal human approval 2026-07-17 after 65/65 functional verification)  
**Scope:** Phase 1 only (Phase 1B Activity Center UI is a separate approved follow-on; no Phase 2 Financial Adapter document tables)  
**Option B:** Accepted (DEC-016 updated)  
**Verification:** [`PHASE1_FUNCTIONAL_VERIFICATION_REPORT.md`](PHASE1_FUNCTIONAL_VERIFICATION_REPORT.md)

---

## Confirmation — protected surfaces

| Surface | Modified? |
|---------|-----------|
| `modules/realestate/accounting/accounting_engine.php` | **No** |
| Real Estate Invoice Mode posting / receipt / CN / allocation / ageing | **No** |
| `re_invoices` / `re_leases` / `re_tenants` | **No** |
| Mobile / customer API response field names | **No** |
| Historical journal/payment amounts rewritten | **No** |

---

## Migrations

| File | Executed locally? | Live? |
|------|-------------------|-------|
| [`migrations/ars_phase1_financial_lock_foundations.sql`](../../migrations/ars_phase1_financial_lock_foundations.sql) | **Yes** (DB `datanew`) | **No — awaiting separate live approval** |
| Design doc | [`docs/ars/PHASE1_MIGRATION_DESIGN.md`](PHASE1_MIGRATION_DESIGN.md) | — |

**Local result:** columns present; `ars_booking_activities` created; **4** existing bookings backfilled to `is_financially_locked=1`.

**Rollback:** documented in migration design doc.

---

## Files changed (summary)

### New
- `modules/ars/includes/ars_financial_lock.php`
- `modules/ars/includes/ars_activity.php`
- `modules/ars/includes/ars_permissions.php`
- `migrations/ars_phase1_financial_lock_foundations.sql`
- `docs/ars/PHASE1_MIGRATION_DESIGN.md`
- `docs/ars/BUSINESS_CONFIRMATION_SHEET.md`
- `docs/ars/PHASE1_COMPLETION_REPORT.md` (this file)

### Updated (staff ARS)
- `ajax_booking_actions.php` — CSRF, confirm/payment transactional integrity, charge notification fix, lock, permissions, activities
- `ajax_pricing_actions.php`, `ajax_blocked_dates_actions.php`, `ajax_housekeeping_actions.php`, `ajax_maintenance_actions.php`, `ajax_photo_upload.php` — CSRF
- `booking_view.php`, `booking_add.php`, `settings.php` — CSRF + lock UI / unit scoping
- `guests.php`, `guest_view.php`, `unit_edit.php`, `unit_profile.php` — CSRF on all POST forms; unit load/save scoped
- `revenue.php` — short-term unit scoping for occupancy KPI + filter dropdown
- `index.php`, `units.php`, `calendar.php`, `blocked_dates.php`, `pricing.php`, `maintenance.php` — company/unit scoping helpers
- `includes/ars_layout_header.php` — `ARS_CSRF` token
- `includes/ars_booking_requests.php` — lock gate + unit `FOR UPDATE` on extension
- JS FormData CSRF appends on pricing/maintenance/HK/blocked/unit_edit/booking_add
- `docs/ERP_DECISIONS.md` — DEC-016 → Accepted
- `docs/ars/PHASE1_RECOVERY_AUDIT.md` — post-crash audit + gap-closure status

---

## Defects fixed

| ID | Fix |
|----|-----|
| C1 Confirm ignores journal failure | Confirm runs in transaction; rolls back if journal fails; re-checks availability under locks |
| C3 `add_charge` payment notifications | Removed erroneous payment/receipt notifications; charge activity logged instead |
| C5 Double-post confirm | Rejects if `journal_id` already set; row `FOR UPDATE` |
| Payment journal failure ignored | Payment path rolls back insert if journal fails |
| Cancel journal failure ignored | Cancel rolls back if reverse fails |

---

## Security changes

- CSRF required on all ARS AJAX endpoints listed above (`_csrf` / session hash_equals)
- CSRF on staff POST forms: `booking_add`, `settings`, `guests`, `guest_view`, `unit_edit`, `unit_profile` (all POSTs) via `csrf_field()` / `csrf_verify()`
- Action-level authorization: Core-only finance actions vs Ops check-in/out (`ars_require_booking_action`)
- Booking AJAX always filters `company_id`
- Expenses: ARS wrappers reuse RE expense pages (CSRF owned there)

---

## Financial Lock behaviour

- Columns: `financial_status`, `is_financially_locked`, `financial_locked_at/by`, `financial_lock_reason`
- Lock engaged on: successful confirm (revenue JV), payment recorded, deposit received; backfill for existing money evidence
- Draft bookings: charges/date edits still allowed (until lock)
- Locked bookings: `add_charge` blocked with amendment preview message; lifecycle extension/cancel approval blocked with amendment required; `deposit_amount` change blocked via `ars_assert_financial_edit_allowed`
- Operational free fields: `special_requests`, `internal_notes` (`update_operational_notes` action)
- Impact detection API: `detect_amendment_impact` action returns preview document list (Phase 2 placeholders)

---

## Amendment impact-detection behaviour

`ars_detect_amendment_financial_impact()` compares proposed vs current protected fields; when locked, returns `requires_amendment`, `blocked_fields`, and `preview_documents`. Full amendment document creation is **Phase 2**.

---

## Company-scoping improvements

- `ars_short_term_units_where()` / `ars_fetch_short_term_units()` / `ars_assert_unit_usable_for_ars()`
- Applied to dashboard KPIs, units, calendar, pricing, blocked dates, maintenance, booking_add, revenue, unit_edit, unit_profile
- Fallback: if no units owned by ARS company, still show short-term units (shared RE inventory) — open question on exact `company_id` remains for Phase 2
- Maintenance create validates unit usability; blocked-date conflicts scoped by ARS `company_id`

---

## Permission changes

- Finance-mutating booking actions require ARS Core (when dept flags present)
- Check-in / check-out allowed for Ops or Core
- Users with module access but no dept flags (owner/admin path) remain allowed

---

## Mobile / API regression

- No customer API or `stay/` response schemas changed
- Additive DB columns only; guest booking detail keys unchanged
- Staff CSRF does not affect JWT guest APIs

**Recommended manual smoke (staff):** login → open booking → confirm/check-in with CSRF → attempt charge on locked booking → notes update  
**Recommended mobile smoke:** guest booking list/detail/payment-intent unchanged

---

## Existing-booking regression

- Backfill locked 4 bookings with prior journals/payments (local `datanew`)
- Historical journals preserved; no amount rewrites
- Operational status fields unchanged by migration

---

## Known limitations

1. Full Option B invoice/CN/allocation tables **not** created (Phase 2)
2. Legacy `ars_accounting.php` still used for confirm/payment/deposit — not yet behind Financial Adapter
3. Activity Center **writers only** — no unified timeline UI (Phase 1B)
4. Unit scoping still allows RE-owned short-term inventory when ARS company has zero owned units
5. Guest portal lifecycle approve path still staff-side; locked bookings correctly refuse money-impact approvals
6. Live DB migration **not** executed

---

## Recommended Phase 1B scope (after this report approved)

1. Booking Activity Center UI on `booking_view` (filters, deep links, Quick Actions shell)
2. Surface financial lock + amendment CTA in UX
3. Optional: paginate activity feed; link to existing payment/journal rows
4. Do **not** start Phase 2 adapter until Business Confirmation Sheet answers + Option B table design approved

---

## Phase 1 exit checklist

- [x] CSRF on ARS forms/AJAX (including guests, guest_view, unit_edit, unit_profile)
- [x] Confirm/payment integrity
- [x] add_charge notification fix
- [x] Company/unit scoping helpers (including revenue + unit_edit/profile)
- [x] Draft vs Financial Lock foundations + migration
- [x] Amendment impact detection
- [x] Prevent direct money edits when locked
- [x] Ops notes still editable
- [x] Availability/overbooking hardening on confirm + extension
- [x] Action-level permissions
- [x] Activity event writers
- [x] DEC-016 Accepted
- [x] Business Confirmation Sheet prepared
- [x] Post-crash recovery audit + remaining-gap closure
- [x] Human review of this Phase 1 report — **Approved**
- [x] Functional verification 65/65 — **Accepted**
- [ ] Live migration approval (separate — still pending)
- [x] Explicit go-ahead for Phase 1B — **Granted 2026-07-17**
