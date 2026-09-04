# Phase 8 — Reports & GM Dashboard

## Goal

GM and accountants see **one consistent set of numbers** across dashboards, P&L, and registers — all driven by `ServiceAccountingService` via `SmReportingService`.

## Setup

```bash
php tools/sm_apply_phase8_schema.php
```

Settings:

| Key | Default |
|-----|---------|
| `sm_phase8_enabled` | `1` |
| `sm_gm_dashboard_default_days` | `30` |

## Pages

| Page | Path | Purpose |
|------|------|---------|
| **GM Dashboard** | `accounts/gm_dashboard.php` | Single-page KPIs: revenue (GL), expenses, profit, AR, collections, jobs, trend chart, cross-checks |
| **Invoice Register** | `accounts/report_invoice_register.php` | All collectible invoices in range + CSV export |
| **Expense Summary** | `accounts/report_expense_summary.php` | By expense type + GL account + CSV export |

Nav: **Accounts → GM Dashboard** and **Reports** hub cards.

## Service layer

`includes/sm_reporting_service.php` — `SmReportingService` wraps `ServiceAccountingService` and adds:

- `getGMReport($from, $to)` — full dashboard bundle
- `getCrossChecks($from, $to)` — invoice register vs GL revenue, expense table vs GL
- `getInvoiceRegister($from, $to)`
- `getExpenseSummary($from, $to)`
- `getRevenueByServiceCategory($from, $to)`
- `getMonthlyTrend($months)`
- `getJobsCompletedCount` / `getJobsFinalizedCount`

## Cross-checks (GM Dashboard)

| Check | Meaning |
|-------|---------|
| Invoice register vs GL invoice postings | Invoice `total` by issue date vs revenue credited on GL journals (`source = invoice`) |
| GL revenue reversals | Credit notes / voids that reduce net GL revenue (informational) |
| Invoice register vs net GL revenue | Register totals may exceed net GL when reversals exist but invoice status is still `issued` |
| Expense table vs GL | Prepaid amortization hits GL expense accounts without an expense header row |

**Revenue bridge** (bottom of cross-check card):

`Register → GL invoice postings − reversals = Net GL revenue`

Large timing gaps → check journal dates vs invoice issue dates in P&L detail.

## Hybrid orders (Cleaning + Pest Control)

Revenue by category and invoice register **split by `order_services` lines** linked to each catalog service’s category — not the work order header category alone.

Invoice register shows:
- **Categories** — e.g. `Cleaning + Pest Control`
- **Split (AED)** — e.g. `Cleaning 180.00 · Pest Control 127.50`

## Success criteria (from roadmap)

- GM dashboard revenue ≈ P&L revenue (same period, GL basis)
- Invoice register total ≈ `getRevenueFromInvoices()` for same dates
- Outstanding AR on GM = AR dashboard = allocation-based balance

## Related

- Phase 7: Prepaid & JV reports linked from Expense Summary
- Phase 0: `accounts/system_health_check.php`
- Workflow: `docs/service_management/WORKFLOW.md`
