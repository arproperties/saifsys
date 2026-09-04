# ARS Phase 1B Completion Report — Booking Activity Center Foundation

**Status:** **Approved / Complete** (formal human approval 2026-07-17)  
**Scope:** Phase 1B only (Activity Center UI + event-store foundations + conservative backfill)  
**Next:** Phase 2A – Business Confirmation & Financial Architecture (design only)  
**Not in scope:** Phase 2B Financial Adapter implementation, live migration, production deploy, Phase 3 UI modernization

---

## Confirmation — protected surfaces

| Surface | Modified? |
|---------|-----------|
| `modules/realestate/accounting/accounting_engine.php` | **No** (SHA-256 unchanged) |
| Real Estate Invoice Mode / lease posting / ageing | **No** |
| `re_invoices` / `re_leases` / `re_tenants` counts | **Unchanged** (190 / 189 / 184) |
| Mobile / customer API response field names | **Unchanged** (`booking`, `unit`, `payments`, `security_deposit`; lock fields not exposed) |
| Historical journal/payment amounts rewritten | **No** |
| Live migration / production deploy | **Not performed** |

---

## Migrations

| File | Locally executed? | Live? |
|------|-------------------|-------|
| [`migrations/ars_phase1b_activity_center.sql`](../../migrations/ars_phase1b_activity_center.sql) | **Yes** (`datanew`) | **No** |
| Phase 1 store (already present) | [`migrations/ars_phase1_financial_lock_foundations.sql`](../../migrations/ars_phase1_financial_lock_foundations.sql) | Live still pending |

**Additive columns on `ars_booking_activities`:** `source`, `is_backfill`, `dedupe_key` (+ unique `(booking_id, dedupe_key)`).

**Local backfill result:** 28 conservative historical snapshot rows across existing bookings (idempotent re-run kept count at 28).

---

## Activity schema

Core table (from Phase 1 + 1B additives):

- `company_id`, `booking_id`, `booking_number`
- `event_category`, `event_type`, `title`, `description`
- `previous_value`, `new_value`
- `related_entity_type`, `related_entity_id`, `related_document_number`, `related_journal_id`
- `status`, `source`, `is_backfill`, `dedupe_key`, `meta_json`
- `created_by`, `created_at`

**Guarantees:** writer validates booking∈company before insert; orphans rejected; failures catch+`error_log` (never throw into business txn); dedupe via `dedupe_key` where set.

### Event categories

`operational`, `financial`, `payment`, `accounting`, `housekeeping`, `maintenance`, `notes`, `documents`, `system`

### Representative event types

| Type | Category | Origin |
|------|----------|--------|
| `historical_snapshot` / `status_snapshot` | system / operational | Backfill |
| `journal_reference` / `payment_reference` / `deposit_journal_reference` / `document_reference` / `financial_lock_snapshot` | accounting / payment / financial / documents | Backfill |
| `booking_created` / `booking_confirmed` / `check_in` / `check_out` / `booking_completed` / `booking_cancelled` | operational | Live writers |
| `payment_recorded` / `additional_charge_added` | payment / financial | Live writers |
| `financial_lock_engaged` | financial | Lock helper |
| `amendment_required` | financial | Blocked charge / lifecycle |
| `internal_note_added` / `notes_updated` | notes | Activity AJAX / booking AJAX |
| `guest_information_changed` | operational | Guest profile update |
| `housekeeping_*` / `maintenance_request_created` | housekeeping / maintenance | HK / maintenance AJAX |

Designed so Phase 2 document entities can attach via `related_entity_type` + `related_entity_id` without redesigning the feed.

---

## Files changed

### New
- `migrations/ars_phase1b_activity_center.sql`
- `modules/ars/ajax_activity_actions.php`
- `docs/ars/PHASE1B_COMPLETION_REPORT.md` (this file)

### Updated
- `modules/ars/includes/ars_activity.php` — safe writer, fetch/filter/pagination, deep links, guest-change helper
- `modules/ars/booking_view.php` — Activity Center feed + Quick Actions + note modal + anchors
- `modules/ars/assets/ars_styles.css` — Activity Center / Quick Actions styles
- `modules/ars/ajax_booking_actions.php` — amendment-required activity on blocked charge/lifecycle
- `modules/ars/ajax_housekeeping_actions.php` — HK activities when `ars_booking_id` present
- `modules/ars/ajax_maintenance_actions.php` + `maintenance.php` — optional `booking_id` link + activity
- `modules/ars/guest_view.php` — guest-change activities on open bookings
- Docs/plan: Phase 1 marked **Approved/Complete**; Phase 1B plan todo completed

---

## UI and filters

On `booking_view.php`:

- Chronological Activity Center (`#activity-center`) with icons, user, datetime, title, description, previous→new, status, reference, deep link
- Filters: All, Operational, Financial, Payments, Accounting, Housekeeping, Maintenance, Notes, Documents, System
- Pagination via **Load more** (20/page, scrollable feed max-height)
- Empty state copy for filtered/empty feeds
- HTML escaped in client renderer (`escHtml`)
- Company + booking scoped list query

## Quick Actions

| Action | Status |
|--------|--------|
| New Payment | Wired → existing payment modal |
| Extension | Scroll to lifecycle requests |
| Additional Service / Damage Charge | Wired when unlocked; disabled under financial lock (Phase 2 amendment) |
| Security Deposit | Scroll to deposit section |
| Housekeeping Request | Link `housekeeping.php` |
| Maintenance Request | Link + prefill unit/booking |
| Add Internal Note | New modal → `ajax_activity_actions.php` |
| Upload Attachment | Disabled (no staff upload path yet) |
| Send Invoice / Send Receipt | Disabled (Phase 2) |
| Print Documents | `window.print()` |
| Amendment / Refund / CN | Disabled (Phase 2) |

## Deep links

| Entity | Destination |
|--------|-------------|
| Journal | `../realestate/accounting/journal_entry_view.php?id=` |
| Guest | `guest_view.php?id=` |
| Unit | `unit_profile.php?id=` |
| Payment / charge | Booking anchors `#payments` / `#charges` |
| Housekeeping / maintenance | Module pages (+ highlight query when id known) |
| Document | Booking `#documents` placeholder anchor |

Destination pages retain their own authz/company checks.

## Backfill behaviour

Conservative only:

- Booking existed
- Current status snapshot
- Existing journal / payment / deposit journal / document references
- Financial lock state

All marked `source=backfill`, `is_backfill=1`, unique `dedupe_key`. No invented intermediate lifecycle users/timestamps.

## Permissions and scoping

- Activity list/note endpoints: `arsPageAuth` + CSRF + booking `company_id` match
- Cross-company booking_id → empty / not found
- Note action uses operational-notes permission path (non–core-only)

---

## Tests performed (localhost)

| Area | Result |
|------|--------|
| Migration apply + re-run idempotent | PASS (28 → 28) |
| Activity fetch filters + company scoping | PASS |
| Orphan rejection / dedupe | PASS |
| Booking view renders Activity Center + Quick Actions | PASS (HTTP) |
| Activity AJAX CSRF missing/invalid → controlled error; valid → JSON list | PASS |
| Existing booking amounts/journals unchanged (sample booking 1) | PASS |
| Customer API shape regression | PASS |
| `accounting_engine.php` hash unchanged | PASS |
| `re_invoices` / `re_leases` / `re_tenants` counts unchanged | PASS |
| PHP lint on touched files | PASS |

Temporary verifier users cleaned up after HTTP tests.

### Defects found and fixed during 1B

None outstanding after UI header repair (Quick Actions card briefly mis-labeled during edit; corrected before verification).

---

## Known limitations

1. Staff document **upload** still not implemented — Quick Action disabled.
2. Send Invoice / Receipt / formal Amendment / Refund / CN deferred to Phase 2.
3. Housekeeping activity only when order has `ars_booking_id`.
4. Maintenance activity only when `booking_id` posted and unit matches booking.
5. Backfill does not reconstruct full historical timelines.
6. Live migration not run.
7. Phase 3 visual polish (Alpine/Lucide) not started.

## Phase 2 dependencies

- Financial Adapter + Option B document tables
- Amendment workflow emitting real child documents (Activity Center already supports entity links)
- Invoice/receipt send actions
- Business Confirmation Sheet answers for posting `company_id` / recognition

---

## Final notes

- **No live migration / deployment**
- **No Phase 2B started**
- Phase 1 remains **Approved/Complete**; Phase 1B **Approved/Complete** 2026-07-17
- Next gate: **Phase 2A** design approval before any Phase 2B coding
