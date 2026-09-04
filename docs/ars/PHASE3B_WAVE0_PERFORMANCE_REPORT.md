# Phase 3B Wave 0 — Performance Report

**Measured:** 2026-07-17 (local build)

| Asset | Size (bytes) | Notes |
|-------|-------------:|-------|
| `dist/ars-app.css` | 15,124 | Dev |
| `dist/ars-app.min.css` | **12,264** | Production CSS |
| `vendor/alpine.min.js` | 44,758 | Alpine 3.14.8 |
| `vendor/lucide.min.js` | 358,106 | Full UMD (Wave 0); may tree-reduce later |
| `js/ars-ui.js` | 2,637 | Init only |
| **JS total (new)** | **~405,501** | Only when `ars_ui_assets()` called |
| Fonts (4 woff2) | ~varies | Self-hosted; swap |

| Metric | Value |
|--------|-------|
| New asset requests (showcase) | CSS + Alpine + Lucide + ars-ui + up to 4 fonts |
| Duplicate loading | Prevented via `ars_ui_assets` static guard |
| Default staff pages | **0** new requests (assets not included) |
| Dev build time | ~0.2–0.3s compile step |
| Prod build time | **~2s** wall (`./build.sh prod`) |
| Calendar/chart/dnd libs | **Not added** |

## Result

**PASS** for Wave 0. Lucide full bundle is the main weight; acceptable for foundation; optimize in later wave if needed. No production-page load impact until pages opt in.
