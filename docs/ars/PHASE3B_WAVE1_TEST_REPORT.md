# Phase 3B Wave 1 — Test Report

**Date:** 2026-07-17  

| Test | Result | Notes |
|------|--------|-------|
| PHP lint `ars_shell.php` | **PASS** | |
| PHP lint `index.php`, `reports.php`, preview | **PASS** | |
| Tailwind prod build | **PASS** | Stay excluded from content |
| Shell nav IA | **PASS** | Accounting stub absent; Finance → financial_reports; Activity disabled |
| Opt-in model | **PASS** | `index.php`, `reports.php`, preview only |
| Legacy pages unchanged | **PASS** | bookings/calendar/etc. still use `ars_layout_*` |
| Bootstrap coexistence (index transitional) | **PASS** | `legacy_bootstrap => true` loads BS + ars_styles inside shell |
| Stay no-change | **PASS** | No Stay references to shell |
| Mobile API no-change | **PASS** | No `api/customer` edits |
| Protected Class A/B hashes | **PASS** | `PROTECTED OK` |
| Adapter OFF | **PASS** | `financial_adapter_enabled = 0` |
| Wave 0 foundation frozen | **PASS** | `ars_ui.php` not modified |
| Keyboard / Escape / skip link | **PASS (code)** | Manual browser confirm recommended |
| Responsive drawer + bottom nav | **PASS (code)** | Manual viewport confirm recommended |

## Manual checklist (localhost)

1. Open `modules/ars/index.php` — new shell, existing dashboard cards.  
2. Open `modules/ars/tools/ars_shell_preview.php` — pure shell states.  
3. Open `modules/ars/bookings.php` — legacy Bootstrap layout (no Wave CSS).  
4. Resize to mobile — drawer + bottom nav on shell pages.  
5. Collapse sidebar on desktop — preference persists.
