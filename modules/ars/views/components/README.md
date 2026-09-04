# ARS UI components (Wave 0)

Presentational PHP helpers live in `modules/ars/includes/ars_ui.php`.

This directory holds optional thin partials for documentation and future Wave 1+ includes.

**Rules:**

- Escape output (`ars_ui_h`)
- No DB queries
- No financial calculations / posting / journals
- No Stay portal dependency
- Load assets only via `ars_ui_assets()` on opted-in staff pages

See `docs/ars/PHASE3B_WAVE0_COMPONENT_CATALOG.md`.
