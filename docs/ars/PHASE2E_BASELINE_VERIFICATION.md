# Phase 2E — Baseline Verification

**Date:** 2026-07-17  
**Version:** ARS Financial Core v1.0  

---

## Verification run

| Check | Result |
|-------|--------|
| Phase 2D UAT (`tools/ars_phase2d_uat.php`) | **24 PASS / 0 FAIL / 0 BLOCKED / 0 SKIP** |
| Shared `accounting_engine.php` SHA | **MATCH** `9cbb880fc3241615b69d4fe5b3adfbee14e513d03964924214cc9325f9d7c7d6` |
| Financial Adapter flag after test | **OFF** (`0`) |
| `re_invoices` count | **190** (unchanged vs 2D marker) |
| Orphan document lines | **0** |
| Documents with missing journals | **0** |
| Cross-company doc/booking mismatch | **0** |
| Mobile/customer API contracts | **Unchanged** (no Phase 2E API edits) |

### Note on harness hygiene

First re-run failed due to leftover UAT idempotency keys / unit overlaps from prior Phase 2D runs. **Only** `tools/ars_phase2d_uat.php` was adjusted (unique run IDs + expire prior `ARS-2D-%` pending). No financial core code changed for that fix.

---

## Confirmation checklist

- [x] 0 failures  
- [x] 0 blocked core scenarios  
- [x] Engine unchanged  
- [x] Adapter OFF by default after testing  
- [x] RE records unchanged  
- [x] Company isolation intact  
- [x] No orphan financial lines / missing journals  
- [x] Freeze docs + Cursor rule published  

**Baseline verification: PASSED**
