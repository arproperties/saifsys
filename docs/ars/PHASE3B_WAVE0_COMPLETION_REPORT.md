# Phase 3B Wave 0 — Completion Report

## Status

**Complete.** Wave 0 design-system technical foundation delivered for **internal ARS staff** only.

## Delivered

| Area | Deliverable |
|------|-------------|
| Scope correction | `PHASE3_SCOPE_CORRECTION_STAY_PORTAL_EXCLUDED.md` + Phase 3A focused updates |
| Plan | `PHASE3B_WAVE0_DETAILED_PLAN.md` |
| Tailwind | Scoped build, `#ars-app`, preflight off, Stay excluded |
| Tokens | In `assets/src/ars-app.css` |
| Alpine 3.14.8 | Self-hosted vendor |
| Lucide 0.469.0 | Self-hosted vendor |
| Font | Plus Jakarta Sans self-hosted |
| PHP UI | `includes/ars_ui.php` |
| Showcase / probe | `tools/ars_ui_*.php` (not in nav) |
| Docs | Build, catalog, coexistence, a11y, performance, protected diff, test, final explanation |

## Explicitly not done (correct)

- Wave 1+ shell/nav/pages  
- Stay portal work  
- Mobile app / API changes  
- Financial core changes  
- Adapter enablement  
- Production deploy  

## Rollback

Remove tools pages and stop calling `ars_ui_assets()`. No DB migration. Staff pages never opted in by default.
