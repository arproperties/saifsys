# Phase 2C — Performance Report

**Status:** Complete (localhost)  
**Harness timings:** `tools/ars_phase2c_uat.php`  
**Machine:** Local XAMPP (developer workstation)  

---

## Measured timings (UAT run)

| Operation | ms | Soft limit | Result |
|-----------|---:|----------:|--------|
| Booking create (insert) | 2.78 | 200 | PASS |
| Original invoice + journal post | 21.10 | 1500 | PASS |
| Financial documents report query | 0.28 | 500 | PASS |
| Outstanding AR report | 0.23 | 500 | PASS |
| Deposit liability report | 0.19 | — | OK |
| Activity Center fetch (25) | 0.38 | 500 | PASS |
| 20 booking inserts (volume) | 14.48 | 5000 | PASS |

---

## Assessment

| Area | Assessment |
|------|------------|
| Booking creation | Excellent on localhost |
| Invoice / posting | Excellent (~21ms including engine post) |
| ARS financial reports | Excellent (bounded LIMIT 500) |
| Activity Center | Excellent for single-booking feed |
| Volume inserts | No bottleneck at 20 rows |

---

## Bottlenecks / watch list (not failing)

1. **Shared TB/P&L/BS** for large multi-year companies — not re-profiled in 2C; follow existing ERP performance standards when enabling ARS volume in a shared company.
2. **Activity Center unbounded history** — pagination exists; ensure production UI always pages.
3. **Report helpers** — currently `LIMIT 500`; Phase 3 dashboards should add date indexes usage and export streaming if volumes grow.
4. **Concurrent users** — not load-tested; recommend ApacheBench/k6 before any go-live (still localhost-only for ARS project rule).

---

## Optimizations applied in Phase 2C

None required (all soft targets met). No premature micro-optimizations.

---

## Recommendations for Phase 3 / later

- Add composite indexes review if document list filters by guest+date under load.
- Cache role map per-request (static) if posting hot paths expand.
- Dashboard widgets should call aggregate SQL, not N× booking detail loads.

---

## Performance verdict

**Localhost performance is production-capable for the mirror path at current data scale.**  
Final production capacity planning remains out of scope until BC unlocks + explicit go-live approval (ARS still localhost-only).
