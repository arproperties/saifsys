# Phase 3A — Command Center Blueprint

**Replaces:** Dense KPI-only `index.php` as primary home.  
**Job:** Answer “What needs attention **today**?” in under 10 seconds.

---

## Hierarchy

### First (must see)

1. Arrivals today (count + list strip)  
2. Departures today  
3. Units not ready for arrival (dirty / blocked)  
4. Outstanding payment actions (if finance role)  

### Second

5. Guests in-house  
6. Late arrivals / expected late  
7. Housekeeping delayed vs SLA  
8. Maintenance blocks affecting inventory  

### Third (glance)

9. New bookings (24h) / cancellations  
10. Occupancy % today / week  
11. Revenue MTD (read-only; existing sources)  
12. Deposits pending / refunds queue (finance)  
13. Recent Activity Center feed  

## Role variants

| Role | Emphasize | De-emphasize |
|------|-----------|--------------|
| Agent / reception | Arrivals, departures, unpaid | COA |
| HK coordinator | Dirty units, due cleans | Revenue |
| Finance | AR, deposits, refunds, settlements | HK assignment |
| GM | Occupancy, revenue, exceptions | Detail lists |

## Layout (desktop)

```
┌─ Header: date · property filter · quick create ─────────┐
│ [Arrivals] [Departures] [Not ready] [Alerts]  (KPI row) │
├─────────────┬──────────────────────────┬────────────────┤
│ Today lists │ Occupancy mini / map     │ Alerts + tasks │
│ + Activity  │                          │                │
└─────────────┴──────────────────────────┴────────────────┘
```

## Mobile

Stacked cards; bottom nav Home opens Command Center; lists as cards.

## Rules

- Do not overload: max ~8 widgets visible by default; rest behind “More”.  
- Every widget links to Workspace / Calendar / Finance with deep filters.  
- No client-side financial aggregation beyond displaying server KPIs.
