# Phase 2C — Regression Report

**Status:** Complete (localhost)  
**Date:** 2026-07-17  

---

## Objective

Confirm Phase 2B Financial Adapter work introduced **no regressions** in shared accounting, Real Estate, APIs, or legacy ARS bridge behaviour.

---

## Results summary

| Area | Result |
|------|--------|
| Shared `accounting_engine.php` | **PASS** — SHA unchanged before & after UAT |
| Real Estate `re_invoices` count | **PASS** — 190 → 190 |
| Real Estate `re_leases` count | **PASS** — 189 → 189 |
| Customer/mobile API files present | **PASS** — stay / guest / index endpoints unchanged in Phase 2C |
| Legacy bridge when flag OFF | **PASS** — `ars_post_booking_revenue` posts without `adapter` flag |
| Adapter flag restored OFF | **PASS** |
| CSRF on ARS AJAX | **PASS** — booking/activity/HK/maintenance |
| Financial lock persistence | **PASS** |

---

## Shared accounting engine

| Check | Expected | Actual |
|-------|----------|--------|
| File hash | `9cbb880fc3241615b69d4fe5b3adfbee14e513d03964924214cc9325f9d7c7d6` | Match (REG-01, REG-04) |
| Signature changes | None | None |
| Cleaning `gl_*` usage by ARS | None | None observed |

---

## Real Estate module

| Check | Result |
|-------|--------|
| Invoice Mode tables not written by ARS adapter | PASS (`re_invoices` count stable) |
| Lease rows untouched | PASS |
| Bank reco / TB / P&L / BS pages still present | PASS |

---

## Mobile / customer APIs

| File | Regression check |
|------|------------------|
| `api/customer/v1/stay_endpoints.php` | Present; Phase 2C made **no** contract edits |
| `api/customer/v1/guest_endpoints.php` | Present; no Phase 2C edits |
| `api/customer/v1/index.php` | Present |

**Note:** Full HTTP contract snapshot suite not re-run in 2C; no API source changes in this phase.

---

## ARS legacy vs adapter

| Mode | Behaviour | Result |
|------|-----------|--------|
| `financial_adapter_enabled=0` | Bridge `ars_accounting.php` direct journals | PASS (REG-05) |
| `=1` (UAT only) | Adapter documents + journals | PASS (workflow suite) |
| After UAT | Flag forced back to `0` | PASS |

No dual-post observed: call sites go through `ars_post_*` which delegates **or** bridges, not both.

---

## Bank reconciliation / shared reports

ARS cash/bank journals remain `re_journal_*` rows eligible for shared bank reco. No schema break to reco tables. Structural presence PASS; interactive match UI not exercised in CLI UAT.

---

## Regressions found

**None** (0 FAIL in regression category).

---

## Residual risk

| Risk | Mitigation |
|------|------------|
| Enabling adapter without BC unlocks incomplete amendment docs | Keep flag off; gate methods return `needs_business_confirmation` |
| Legacy bookings without Option B docs | Optional conservative backfill later; do not rewrite history |
