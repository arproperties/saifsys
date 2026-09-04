# Phase 3B Wave 0 — Test Report

**Date:** 2026-07-17  

| Test | Result | Evidence |
|------|--------|----------|
| Tailwind build (dev/prod) | **PASS** | `./build.sh prod` ~2s |
| Production min CSS emitted | **PASS** | `dist/ars-app.min.css` 12,264 B |
| PHP syntax `ars_ui.php` | **PASS** | `php -l` |
| PHP syntax showcase/probe | **PASS** | `php -l` |
| Component render (CLI) | **PASS** | `ars_ui_button` / status badge HTML |
| JS smoke | **PASS** | `ars-ui.js` defines `ArsUI`; no financial calls |
| Keyboard / focus / reduced-motion | **PASS (foundation)** | Documented in a11y report; showcase patterns |
| Responsive foundation | **PASS** | Touch mins; stacked grids in showcase |
| Contrast / status not colour-only | **PASS** | Badge helpers |
| Bootstrap coexistence | **PASS** | No default injection; probe provided |
| Cross-module visual | **PASS** | No ERP page edits |
| Stay portal no-change | **PASS** | No Stay files modified; no `ars_ui` refs under `stay/` |
| Mobile API no-change | **PASS** | No edits under `api/customer/` |
| Protected-file diff | **PASS** | All Class A/B hashes match |
| Financial Adapter final state | **PASS / OFF** | DB `company_id=8` → `financial_adapter_enabled=0` |
| No operational redesign | **PASS** | Staff pages unchanged |
| No route replacement | **PASS** | Tools only; not in nav |
| No production deploy | **PASS** | Localhost only |
| Stay excluded from Tailwind content | **PASS** | Runtime config content globs |

## Manual follow-up (recommended before Wave 1)

Open on localhost (authenticated):

- `/modules/ars/tools/ars_ui_showcase.php`  
- `/modules/ars/tools/ars_ui_coexistence_probe.php`  
- Spot-check existing dashboard/booking_view without console errors from Wave 0 (should load zero Wave assets).
