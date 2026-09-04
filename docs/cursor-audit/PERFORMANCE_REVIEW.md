# Performance Review

**Stage:** 2  
**Date:** 2026-07-10  
**Scope:** Static code patterns only. **No load tests. No production profiling run.**

## Evidence labels

Confirmed from code | Strong inference | Needs live profiling

---

## Findings

### PERF-01 — Unbounded RE list queries
| Field | Content |
|-------|---------|
| Paths | `modules/realestate/leases.php`, `units.php`, `tenants.php`, `payments.php`, `billing_invoices.php` |
| Pattern | Heavy joins + `fetchAll` without LIMIT |
| Risk | High–Critical as data grows |
| Impact | Slow pages; PHP memory; DB load |
| Optimization | Pagination; split list vs aggregate columns |
| Index / cache / pagination / restructure | **Pagination + restructure**; indexes likely help — Needs live profiling |
| Live profiling | **Yes** |

### PERF-02 — Combined outstandings report
| Field | Content |
|-------|---------|
| Path | `modules/realestate/accounting/outstandings_report.php` |
| Pattern | Dual unbounded IM + legacy queries; large joins; filter dropdowns load all units |
| Risk | Critical at scale |
| Impact | Collections/AR screens unusable |
| Optimization | Paginate; summary-first; split Historical vs IM; AJAX filters |
| Type | Pagination + restructure + indexes |
| Live profiling | **Yes** |

### PERF-03 — Dashboard query fan-out
| Field | Content |
|-------|---------|
| Paths | `index.php`, `modules/realestate/index.php`, `modules/construction/index.php`, `account.php` |
| Pattern | Many sequential COUNT/SUM; RE revenue loop per month |
| Risk | High |
| Impact | Slow home screens |
| Optimization | Wire to `CachingService` / `dashboard_cache`; consolidate SQL |
| Note | `OptimizedDashboardService` bypasses cache on some summary paths — Confirmed from code |
| Live profiling | **Yes** |

### PERF-04 — Cron N+1
| Field | Content |
|-------|---------|
| Paths | `collections_alert_cron.php`, `cron_lease_penalties.php` |
| Pattern | Per-company/per-lease loops with further queries/alerts |
| Risk | Critical under volume |
| Impact | Slow cron; duplicate alerts; DB spikes |
| Optimization | Set-based SQL; batch notifications; pass config once |
| Live profiling | **Yes** |

### PERF-05 — PDF / batch document CPU
| Field | Content |
|-------|---------|
| Paths | `contract_pdf_generator.php`, `accounts/api_batch_invoices.php`, `scheduled_reports_generator.php`, `hr/payroll_run_slips_zip.php` |
| Pattern | mPDF/Dompdf synchronous |
| Risk | High |
| Impact | Timeouts; memory |
| Optimization | Queue/chunk; cache company settings/templates |
| Live profiling | **Yes** on batch |

### PERF-06 — GL / ageing report complexity
| Field | Content |
|-------|---------|
| Paths | `accounts/report_general_ledger.php`, `report_ar_ap_ageing.php`, TB/BS reports |
| Pattern | Large joins; nested EXISTS for reversals |
| Risk | High |
| Impact | Slow finance close |
| Optimization | Simplify; covering indexes; precompute |
| Live profiling | **Yes** |

### PERF-07 — N+1 in interactive flows
| Field | Content |
|-------|---------|
| Paths | `lease_add.php` unit/VAT loops; `vendor_payment_add.php` alloc loops |
| Pattern | prepare/execute inside foreach |
| Risk | Medium |
| Impact | Slow save on multi-unit leases |
| Optimization | Batch IN updates / prefetch |
| Live profiling | Optional |

### PERF-08 — Cache underused for RE
| Field | Content |
|-------|---------|
| Paths | `includes/caching_service.php` exists; RE dashboards largely uncached |
| Risk | Medium–High gap |
| Optimization | Adopt cache for RE KPIs; fix bypass in optimized dashboard |
| Live profiling | Yes after wiring |

### PERF-09 — Frontend duplicate assets
| Field | Content |
|-------|---------|
| Paths | `lease_add.php` second Bootstrap + Select2/jQuery; `settings.php` duplicate CSS; many pages own CDN |
| Risk | Medium–High waterfall |
| Impact | Slower first paint |
| Optimization | Dedupe; layout-owned assets only |
| Live profiling | Browser waterfall |

### PERF-10 — Construction clients unbounded
| Field | Content |
|-------|---------|
| Path | `modules/construction/clients.php` `SELECT *` all |
| Risk | Medium |
| Optimization | Pagination |
| Live profiling | Optional |

### PERF-11 — Expensive views
| Field | Content |
|-------|---------|
| Paths | `v_ar_*` / optimized variants in `database/views/erp_schema_views.sql` |
| Risk | Medium if used on hot paths |
| Optimization | Confirm usage; index base tables |
| Live profiling | Needs live profiling |

### PERF-12 — Repeated company_settings lookups
| Field | Content |
|-------|---------|
| Paths | PDF/email/helpers |
| Risk | Low–Medium |
| Optimization | Request-static cache (branding already does) |
| Live profiling | Optional |

---

## Priority order for future work

1. Paginate RE lists + fix outstandings  
2. Cache dashboards  
3. Cron batching  
4. PDF queue  
5. Asset dedupe  

**No optimizations implemented in Stage 2.**
