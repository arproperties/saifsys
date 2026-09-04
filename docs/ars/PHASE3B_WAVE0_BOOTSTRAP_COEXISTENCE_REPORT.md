# Phase 3B Wave 0 — Bootstrap Coexistence Report

**Date:** 2026-07-17  

## Method

1. Confirmed production staff pages (`modules/ars/*.php`) do **not** include `ars_ui` / Wave 0 assets by default.  
2. Coexistence probe: `modules/ars/tools/ars_ui_coexistence_probe.php` — Bootstrap layout header/footer + `#ars-app` island.  
3. Tailwind preflight disabled; utilities scoped with `important: '#ars-app'`.

## Pages checked (no Wave 0 asset injection)

| Page | Result |
|------|--------|
| Dashboard `index.php` | Unchanged include chain |
| Bookings list | Unchanged |
| Booking add | Unchanged |
| Booking view | Unchanged |
| Calendar | Unchanged |
| Housekeeping | Unchanged |
| Maintenance | Unchanged |
| Settings | Unchanged |
| Financial reports | Unchanged |
| ARS layout header/sidebar | Unchanged; showcase **not** in nav |
| Shared ERP chrome | Not modified |
| RE / Construction pages | Not modified by Wave 0 |

## Probe expectations (manual)

| Check | Expected |
|-------|----------|
| Bootstrap primary button | Unchanged look |
| Bootstrap table | Unchanged |
| Bootstrap modal | Opens/closes normally |
| ARS island button/badge | Teal staff styling inside `#ars-app` only |
| Console | No Wave 0 JS on pages that do not load assets |

## Stay portal

**No Wave 0 changes.** No assets loaded. Not visually retested for modernization (out of scope). Compatibility: unchanged.

## Result

**PASS** — Wave 0 does not alter default staff or ERP Bootstrap pages. Isolation strategy is sound. Full visual browser pass recommended on localhost before Wave 1.
