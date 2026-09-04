# Phase 3B — Wave 0 Detailed Execution Plan

**Status:** Authorized for implementation  
**Wave:** 0 only — Design-system technical foundation  
**Scope correction:** See `PHASE3_SCOPE_CORRECTION_STAY_PORTAL_EXCLUDED.md`  
**Financial Core:** v1.0 frozen — no Class A/B modifications  

---

## 1. Scope

| In | Out |
|----|-----|
| Scoped Tailwind build under `#ars-app` | Wave 1+ shell/nav redesign |
| Design tokens (staff ARS only) | Stay portal assets / content scan |
| Self-hosted Alpine + Lucide | React/Vue/SPA |
| PHP presentational components | Business/financial logic in components |
| Isolated UI showcase (no live nav) | Production page redesign |
| Bootstrap coexistence proof | Route replacement / legacy retirement |
| Docs + tests + protected-file verify | Mobile API / Stay changes |
| Light-first, online-only | Dark mode / offline queues |

## 2. Exact files (planned create)

| Path | Purpose |
|------|---------|
| `modules/ars/assets/tailwind.config.js` | Tailwind config (no Stay paths) |
| `modules/ars/assets/src/ars-app.css` | Tokens + Tailwind layers |
| `modules/ars/assets/dist/ars-app.css` | Compiled production CSS |
| `modules/ars/assets/vendor/alpine.min.js` | Alpine 3.14.8 |
| `modules/ars/assets/vendor/lucide.min.js` | Lucide 0.469.0 |
| `modules/ars/assets/vendor/fonts/plus-jakarta-sans/*` | Self-hosted fonts |
| `modules/ars/assets/js/ars-ui.js` | Init icons/toasts; no business logic |
| `modules/ars/assets/bin/tailwindcss` | Local CLI (not required on Hostinger) |
| `modules/ars/assets/build.sh` | Dev/prod build commands |
| `modules/ars/includes/ars_ui.php` | Asset loader + component helpers |
| `modules/ars/views/components/*.php` | Component partials |
| `modules/ars/tools/ars_ui_showcase.php` | Isolated showcase |
| `modules/ars/tools/ars_ui_coexistence_probe.php` | Bootstrap + `#ars-app` probe |

## 3. Routes

| Route | Linked from nav? | Notes |
|-------|------------------|-------|
| `modules/ars/tools/ars_ui_showcase.php` | **No** | Auth + ARS module required |
| `modules/ars/tools/ars_ui_coexistence_probe.php` | **No** | Auth + ARS; probe only |

No production route replacement.

## 4. Components (presentational only)

App wrapper, page header, breadcrumbs, action toolbar, buttons, badges, lock indicator, KPI/alert cards, empty/error/skeleton, form field/error, filter bar, table shell, drawer/modal/confirm, toast region, timeline item, permission-disabled, network-error.

## 5. Data / permissions

- Showcase: **demo strings only** — no live guest/financial queries for money.  
- Auth: existing `arsPageAuth` / module access.  
- CSRF preserved where forms exist (showcase forms are inert).  
- No new permission model.

## 6. Financial contract touchpoints

**None.** Wave 0 must not call posting endpoints or adapter methods.

## 7. Desktop / tablet / mobile

Showcase demonstrates breakpoints; no operational mobile redesign.

## 8. Accessibility

Per `PHASE3A_ACCESSIBILITY_STANDARD.md` — focus, labels, live regions, reduced-motion, 44px targets, status text+icon+colour.

## 9. Acceptance criteria

1. Tailwind prod build succeeds without CDN.  
2. `#ars-app` isolation; preflight off / scoped.  
3. Stay excluded from content scan.  
4. Components render without DB/financial logic.  
5. Showcase not in sidebar.  
6. Existing staff pages unchanged (no new asset includes by default).  
7. Stay portal file hashes unchanged for Wave 0 commits touching Stay = zero.  
8. Mobile/customer API files unchanged.  
9. Protected Class A/B hashes match Phase 2E.  
10. Adapter flag remains OFF.  
11. Documentation set complete.

## 10. Tests

Build, PHP lint, JS smoke, keyboard/focus/responsive/contrast/reduced-motion (manual + documented), coexistence smoke, Stay no-change, API no-change, protected-file diff, adapter OFF.

## 11. Rollback

Remove `ars_ui` includes from any page (showcase only). Delete/ignore new assets. No DB migration.

## 12. Protected-file comparison

End of Wave 0: re-hash all Class A/B paths vs `ARS_FINANCIAL_CORE_V1_PROTECTED_FILES.md`.
