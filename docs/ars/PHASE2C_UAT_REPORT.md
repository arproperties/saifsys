# Phase 2C — UAT Report

**Status:** Complete on localhost — awaiting human review  
**Date:** 2026-07-17  
**Environment:** Local XAMPP / `datanew` only  
**Harness:** `tools/ars_phase2c_uat.php`  
**Raw results:** `docs/ars/_phase2c_uat_results.json`  

---

## Project rule (enforced)

| Constraint | Status |
|------------|--------|
| ARS remains localhost only | ✔ |
| No production deployment | ✔ |
| No live database updates | ✔ |
| No live feature activation | ✔ |
| No production migration | ✔ |
| Adapter flag final state | **OFF** (`financial_adapter_enabled=0`) |

---

## Executive summary

End-to-end UAT of the Phase 2B Financial Adapter baseline ran successfully on localhost.

| Metric | Count |
|--------|------:|
| **Total scenarios** | **77** |
| **Passed** | **66** |
| **Failed** | **0** |
| **Blocked (BC / policy)** | **10** |
| **Skipped** | **1** |

Mirror-path financial workflows (invoice, multi-method payments, allocations, deposits, cancel reverse, ops lifecycle) passed with balanced journals and intact integrity. Policy-dependent workflows remain correctly **gated** until Business Confirmations (BC-06…13) are Confirmed — this is intentional, not a defect.

---

## Roadmap

| Phase | Status |
|-------|--------|
| 0–2A | ✔ |
| 2B Financial Adapter | ✔ Approved |
| **2C UAT & Production Readiness** | ✔ Complete (localhost) — **awaiting review** |
| 3 Premium UX | ⬜ Not started |
| 4 Advanced HH | ⬜ Not started |

---

## Scenarios tested

### Operational lifecycle (PASS)

| ID | Scenario | Result |
|----|----------|--------|
| WF-A-01 | Draft booking | PASS |
| WF-A-02 | Confirm → original invoice + journal | PASS |
| WF-A-03 | Financial lock | PASS |
| WF-A-08…10 | Check-in → Check-out → Complete | PASS |
| WF-C-03 | Booking cancellation (ops) | PASS |

### Payments (PASS)

| ID | Scenario | Result |
|----|----------|--------|
| WF-A-04 | Cash partial payment | PASS |
| WF-A-05 | Bank transfer | PASS |
| WF-A-06 | Card | PASS |
| WF-A-07 | Allocation → document `paid` | PASS |
| EDGE-02 | Overpayment capped to invoice balance | PASS |

### Deposits (PASS)

| ID | Scenario | Result |
|----|----------|--------|
| WF-B-01 | Deposit collection | PASS |
| WF-B-02 | Deposit refund | PASS |

### Cancel / reverse (PASS)

| ID | Scenario | Result |
|----|----------|--------|
| WF-C-01/02 | Reverse financial document + status `reversed` | PASS |

### Edge / safety (PASS)

| ID | Scenario | Result |
|----|----------|--------|
| EDGE-01 | Duplicate idempotent invoice | PASS |
| SEC-ISO-01 | Company fail-closed | PASS |
| SM-01/02 | State machine no-skip | PASS |

### Blocked until business confirmation (expected)

| ID | Scenario | Gate |
|----|----------|------|
| WF-EXT-01 | Booking extension invoice | BC-09 |
| WF-SVC-01 | Additional services / damage invoice | BC-11 |
| WF-ADJ-01 | Invoice adjustments | BC-06/07/08 |
| WF-CN-01 | Credit notes | BC-06 |
| WF-FORF-01 | Deposit forfeiture | BC-10/11 |
| WF-REF-01 | Guest stay refund GL | BC |
| WF-CREDIT-01 | Credit balance handling | BC |
| WF-STRIPE-01 | Stripe settlement / fees | BC-12/13 |
| WF-NOSHOW-01 | No-show fee posting | BC |
| WF-EARLY-01 | Early checkout fee/CN | BC-08 |

### Skipped

| ID | Reason |
|----|--------|
| WF-STRIPE-UI-01 | No live Stripe charge on localhost; GL path gated |

---

## Bugs discovered

| Bug | Severity | Status |
|-----|----------|--------|
| None in this UAT run | — | **0 failed assertions** |

Prior Phase 2B activity payload defect (`title` vs `summary`) was already fixed before 2C and re-validated (ACT-A-01 PASS).

---

## Bugs fixed during Phase 2C

None required (0 FAIL).

---

## Outstanding issues

1. Open Business Confirmations (BC-01…15) block full commercial scenario coverage for amendments, fees, Stripe clearing, deferred revenue policy.
2. Interactive browser double-click / session-expiry UI stress not automated (CLI covers idempotency + CSRF static checks).
3. Concurrent multi-user load not simulated beyond volume inserts.
4. Guest/booking PDF statements not re-asserted as Option B-native (existing document catalog still operational).

---

## UX findings (for Phase 3 — no redesign in 2C)

See [`PHASE3_PREPARATION_REPORT.md`](PHASE3_PREPARATION_REPORT.md). Highlights:

- Booking view is dense (Activity + payments + charges + deposit + lifecycle on one page).
- Locked bookings disable some Quick Actions without a guided amendment wizard.
- Extension/service/damage paths need clearer “will create financial document” warnings once BC unlocks posting.

---

## Related deliverables

- [`PHASE2C_ACCOUNTING_VALIDATION.md`](PHASE2C_ACCOUNTING_VALIDATION.md)
- [`PHASE2C_REGRESSION_REPORT.md`](PHASE2C_REGRESSION_REPORT.md)
- [`PHASE2C_PERFORMANCE_REPORT.md`](PHASE2C_PERFORMANCE_REPORT.md)
- [`PHASE2C_PRODUCTION_READINESS.md`](PHASE2C_PRODUCTION_READINESS.md)
- [`PHASE3_PREPARATION_REPORT.md`](PHASE3_PREPARATION_REPORT.md)

---

## Stop condition

**Phase 2C complete. STOP. Do not begin Phase 3 until human approval.**
