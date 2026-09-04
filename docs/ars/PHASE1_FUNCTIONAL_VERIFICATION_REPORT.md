# ARS Phase 1 Functional Verification Report

**Date:** 2026-07-17  
**Environment:** Localhost only (`http://127.0.0.1/herosysgro`, DB `datanew`, host MacBookPro / XAMPP MySQL)  
**Harness:** `scripts/ars_phase1_functional_verify.php`  
**Raw results:** `docs/ars/PHASE1_FUNCTIONAL_VERIFICATION_RESULTS.json`  
**Final run:** **65 PASS / 0 FAIL**

---

## 1. Test preparation

| Item | Result |
|------|--------|
| Safe local backup | **Yes** — `docs/ars/backups/datanew_phase1_pre_verify_20260717_174150.sql.gz` (3.6 MB) |
| Target | Localhost Apache + local MySQL `datanew` only |
| Phase 1 migration | Already applied (lock columns + `ars_booking_activities`) |
| Test data policy | Disposable `PHASE1VF-*` bookings only; existing ARS-26-00001…00005 used read-only |
| Production-like records | Not altered (regression snapshot compared before/after) |

### Initial existing booking states (read-only baselines)

| ID | Booking # | Status | Locked | Financial status | Journal | Deposit JV | Paid | Deposit |
|----|-----------|--------|--------|------------------|---------|------------|------|---------|
| 1 | ARS-26-00001 | checked_out | 1 | paid | 1793 | 1795 | 1260.00 | 500.00 |
| 2 | ARS-26-00002 | checked_in | 1 | paid | 2370 | — | 455.70 | 0.00 |
| 3 | ARS-26-00003 | confirmed | 1 | invoice_created | 2371 | — | 0.00 | 0.00 |
| 4 | ARS-26-00004 | expired | 0 | draft | — | — | 0.00 | 0.00 |
| 5 | ARS-26-00005 | confirmed | 1 | paid | 2393 | 2394 | 3645.60 | 500.00 |

Disposable test booking IDs from the final successful run were created then cleaned up (see JSON `test_booking_ids_created`). Representative IDs used during verification included confirm/payment/cancel/overlap sets under the `PHASE1VF-*` prefix.

---

## 2. Test results by case

### A. Draft booking tests — PASS

| ID | Expected | Actual | Result | Refs |
|----|----------|--------|--------|------|
| A-01 | Unlocked draft financial status | locked=0, fin=draft | PASS | PHASE1VF draft booking |
| A-02 | Operational notes editable | notes updated | PASS | same |
| A-03 | Charge allowed; no journal; one charge activity | acts=1, jv=0, unlocked | PASS | charge row + activity |
| A-04 | Activities increment without unexpected duplicates | +1 activity | PASS | same |

### B. Booking confirmation integrity — PASS

| ID | Expected | Actual | Result |
|----|----------|--------|--------|
| B-01 | Journal fails when COA missing | `success=false` missing 1310 | PASS |
| B-02 | Confirm rolls back; status pending; no journal | status=pending, journal null, jv=0 | PASS |
| B-03 | Availability rechecked under unit lock | available=true before post | PASS |
| B-04 | Confirm → status confirmed, lock fields set, one journal, activities | confirmed + lock reason/user/at + jv=1 + confirm/lock activities | PASS |
| B-05 | Repeat confirm does not create second journal | jv remains 1 | PASS |

### C. Payment integrity — PASS

| ID | Expected | Actual | Result |
|----|----------|--------|--------|
| C-01 | Payment journal failure rolls back; no orphan payment | orphan_count=0 | PASS |
| C-02 | Payment once + journal + lock + activity | payment + journal + locked + `payment_recorded` activity | PASS |

Mobile/API shapes verified in section K (unchanged keys).

### D. Add-charge correction — PASS

| ID | Expected | Actual | Result |
|----|----------|--------|--------|
| D-01 | Unlocked booking remains charge-eligible | unlocked | PASS |
| D-02 | Locked booking blocks charge; amendment preview returned | `requires_amendment` + preview docs | PASS |
| D-03 | No Phase 2 Option B tables created | `ars_fin_%` count=0 | PASS |

Charge activity (not payment notification) verified on unlocked draft (A-03). Locked path returns amendment message without rewriting totals/journals.

### E. Cancellation / extension protection — PASS

| ID | Expected | Actual | Result |
|----|----------|--------|--------|
| E-01 | Extension on locked booking requires amendment; preview only | blocked `check_out` + preview docs | PASS |
| E-02 | Financially impactful lifecycle gated when locked | gate=true for extension | PASS |
| E-03 | Cancel rolls back when reverse fails | status unchanged (not cancelled) | PASS |

### F. CSRF verification — PASS (21/21)

| Coverage | Missing token | Invalid token | Valid token / form token present |
|----------|---------------|---------------|----------------------------------|
| Booking AJAX | PASS (419/CSRF) | PASS | PASS (`detect_amendment_impact`) |
| Pricing AJAX | PASS | PASS | — |
| Blocked dates AJAX | PASS | PASS | — |
| Housekeeping AJAX | PASS | PASS | — |
| Maintenance AJAX | PASS | PASS | — |
| Photo upload AJAX | PASS | PASS | — |
| guests.php POST | PASS | — | — |
| settings / booking_add / unit_edit / guest_view / unit_profile forms | — | — | PASS (`csrf_field` present) |

Verifier login used a temporary local Owner user (removed after tests). Responses for missing/invalid CSRF were controlled JSON/HTTP 419 (or CSRF error text for forms).

### G. Permissions — PASS

| ID | Expected | Actual | Result |
|----|----------|--------|--------|
| G-01 | Core user has core, not ops | true | PASS |
| G-02 | Ops user has ops, not core | true | PASS |
| G-03 | Ops denied `confirm`; allowed `checkin` | denied / allowed | PASS |
| G-04 | Owner path has core+ops | true | PASS |

Temporary Core/Ops roles and users were created and deleted after tests. User without ARS role/company was stripped of access for deny-path setup.

### H. Company / unit scoping — PASS

| ID | Expected | Actual | Result |
|----|----------|--------|--------|
| H-01 | Scoped short-term units returned | count > 0 | PASS |
| H-02 | Non short-term unit rejected | assert throws | PASS |
| H-03 | Cross-company booking id returns empty | empty | PASS |
| H-04 | Shared RE-owned short-term units under documented fallback | measured shared count (informational) | PASS |

### I. Availability / overbooking — PASS

| ID | Expected | Actual | Result |
|----|----------|--------|--------|
| I-01 | Competing overlapping confirm blocked; only one confirmed | a2 unavailable; confirmed_count=1 | PASS |

### J. Existing booking regression — PASS

| ID | Expected | Actual | Result |
|----|----------|--------|--------|
| J-01 | Bookings 1–5 unchanged | unchanged | PASS |
| J-02 | Backfill locks consistent (1 locked with journal; 4 unlocked draft) | consistent | PASS |
| J-03 | Deposit booking retains amount + deposit journal | 500.00 + deposit_journal_id | PASS |

### K. Mobile / customer API regression — PASS

| ID | Expected | Actual | Result |
|----|----------|--------|--------|
| K-01 | Detail keeps top-level + booking keys | `booking`, `unit`, `payments`, `security_deposit` + core booking fields | PASS |
| K-02 | Lock fields not exposed on booking payload | no `financial_status` / `is_financially_locked` / `financial_locked_at` | PASS |
| K-03 | Booking list returns items | count > 0 (guest_id=4) | PASS |
| K-04 | Payments + security_deposit present | present | PASS |

Payment-intent live Stripe charge was **not** executed (unsafe/unnecessary locally); response builders and deposit payload shapes were verified via the same guest API functions used by `api/customer/v1`.

---

## 3. Database integrity checks — PASS

| ID | Check | Result |
|----|-------|--------|
| DB-01 | Activity rows point to valid bookings | PASS (0 orphans) |
| DB-02 | No duplicate journals for test bookings | PASS |
| DB-03 | No locked+draft without financial evidence | PASS |
| DB-04 | Migration remains re-runnable (guards present) | PASS |
| DB-05 | `accounting_engine.php` unchanged | PASS (SHA-256 unchanged) |
| DB-06 | `re_invoices` / `re_leases` / `re_tenants` counts unchanged | PASS (190 / 189 / 184) |

Post-run cleanup:
- Disposable bookings, charges, payments, activities, temp users/roles removed.
- PHASE1VF verification journals (23) were **properly reversed** via `ars_reverse_booking_journal` so no unreversed orphan revenue/payment journals remain.

---

## 4. Protected surfaces confirmation

| Surface | Changed during verification? |
|---------|------------------------------|
| `modules/realestate/accounting/accounting_engine.php` | **No** (hash unchanged) |
| Real Estate Invoice Mode / lease posting behaviour | **No** |
| `re_invoices` / `re_leases` / `re_tenants` | **No** row-count change |
| Mobile/customer API response key names | **Unchanged** (lock fields not exposed) |

---

## 5. Defects discovered and fixes

### Defect 1 — Charge/payment totals recalc SQL alias bug (blocking)

- **Symptom:** `ars_recalc_booking_totals()` failed with `Unknown column 'p.payment_type'` when adding charges.
- **Cause:** `ars_payment_room_balance_sql_filter()` defaulted to alias `p`, but recalc SQL has no alias.
- **Fix:** Support empty alias in `ars_deposit.php`; call with `''` from `ars_pricing.php`.
- **Re-test:** A-03 and payment paths PASS.

### Defect 2 — PHP warning polluted booking AJAX JSON

- **Symptom:** Valid CSRF booking AJAX returned HTTP 200 JSON preceded by `Undefined variable $bookingId` warning.
- **Cause:** `$arsAudit` closure captured `$bookingId` before assignment.
- **Fix:** Define `$action` / `$bookingId` before the closure; capture by reference (`&$bookingId`).
- **Re-test:** F-booking-valid PASS (clean JSON success).

No architectural changes were made. Phase 1B was not started.

---

## 6. Final recommendation

**Approve Phase 1 functionally for local completion** — all critical verification cases passed after the two defect fixes above.

Still **await explicit human approval** before:
1. Live DB migration / live deployment  
2. Phase 1B (Activity Center UI)  
3. Phase 2 Financial Adapter / Option B tables  

**Do not start Phase 1B until that approval is given.**
