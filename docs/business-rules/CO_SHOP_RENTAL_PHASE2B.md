# Phase 2B — Commercial Leasing Management & Analytics

**Scope:** Construction Shop Rental (Madar Al Wadi) only.  
**Date:** 2026-07-12  
**Constraint:** No accounting / posting behaviour changes. No redesign of Phase 2A lifecycle workflows.

---

## Gap review (what already existed)

| Area | Already available (reused) | Added in 2B |
|------|----------------------------|-------------|
| Control Center base KPIs | Phase 1.75 snapshot (units, contracts, OS, deferred, deposits, commission, pending) | Occupancy %, expired/terminated/renewed tiles, recognized MTD, penalty income, charts, forecast panel |
| Reports | Shop rental income, tenant commission, deferred recognition posting, contract expiry | Maturity, occupancy/vacancy, shop/tenant profitability, deposit liability, deferred analysis, forecast, termination penalty |
| Productivity | Basic contracts list | Global search, advanced + saved filters, bulk select/export/open, CSV export |
| Performance | Existing source/due indexes | Additive indexes + batched trend queries + batch shop labels |

---

## Deliverables

### Helpers
- `modules/construction/includes/construction_shop_rental_analytics_helpers.php`
  - `co_shop_batch_shops_labels` (N+1 fix)
  - `co_shop_penalty_income_snapshot`
  - `co_shop_monthly_recognized` / `co_shop_financial_trends` (batched)
  - `co_shop_management_forecast` (30/60/90 operational)
  - `co_shop_control_center_snapshot_v2`

### UI
- `shop_rental_control_center.php` — executive dashboard + Chart.js
- `shop_rental_contracts.php` — search / filters / saved filters / bulk / export

### Reports (`modules/construction/reports/`)
- `shop_lease_maturity.php`
- `shop_occupancy.php` (modes: all / occupied / vacant)
- `shop_profitability.php`
- `shop_tenant_profitability.php`
- `shop_deposit_liability.php`
- `shop_deferred_analysis.php`
- `shop_forecast.php`
- `shop_termination_penalty.php`

### Migration
- `migrations/construction_shop_rental_phase2b_indexes.sql` (applied on local `datanew`)

### Nav
- Construction Reports sidebar links for all new shop leasing reports

---

## Explicit non-goals (unchanged)
- Real Estate merge
- Posting engine / COA / recognition journal logic
- Renewal / termination / deposit settlement workflow redesign
- Invented commercial policy thresholds

---

## Regression checklist

| Check | Result |
|-------|--------|
| Helpers load without circular require | Pass (analytics no longer requires helpers.php) |
| `co_shop_control_center_snapshot_v2(company 3)` | Pass — occupancy 66.7%, trends 12 months, forecast present |
| PHP `-l` on contracts + analytics + 8 reports | Pass |
| Index migration applied | Pass |
| Accounting posting paths untouched | Pass (read-only analytics/reports only) |
| Company isolation via `co_shop_require_company_id` | Preserved on Control Center, contracts, reports |
| Multi-shop money still contract-level | Preserved (profitability notes primary-shop attribution) |
| Phase 2A lifecycle pages | Not modified in 2B completion pass |

### Manual UI smoke (recommended)
1. Open Leasing Control Center — KPIs, two charts, forecast card, report links.
2. Contracts list — search tenant/shop, save filter, export CSV, bulk open.
3. Each new report — Generate + CSV/Excel export.
4. Spot-check Contract View activate / invoice / recognition still works (unchanged).

---

## Performance notes
- Trend charts reduced from ~84 queries/12 months to ~3 grouped queries + 12 outstanding snapshots.
- Contracts list uses `co_shop_batch_shops_labels` instead of per-row shop queries.
- Indexes: status/end_date, client/status, schedule status/due, schedule_type/status, invoice source+date, alloc company+invoice, unit status.
