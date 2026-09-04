# Phase 3B Wave 1 — Detailed Execution Plan

**Status:** Authorized  
**Wave 0:** Frozen — do not modify unless critical defect  
**Scope:** Internal ARS application shell & navigation only  
**Out:** Stay portal, mobile APIs, Financial Core, workflow redesign (Wizard/Workspace/Calendar boards/Finance UX/Reports redesign)

---

## 1. Objective

Deliver the permanent staff shell that future waves inherit: sidebar IA, header, workspace, breadcrumbs, search/notification placeholders, user menu, responsive drawer + mobile bottom nav, shared page chrome (header/toolbar/states).

## 2. Files to create

| Path | Purpose |
|------|---------|
| `modules/ars/includes/ars_shell.php` | Nav model, permissions, begin/end layout API |
| `modules/ars/assets/js/ars-shell.js` | Minimal shell helpers (optional; prefer Alpine) |
| `modules/ars/tools/ars_shell_preview.php` | Opt-in showcase of shell + empty/loading/error |
| Docs `PHASE3B_WAVE1_*.md` | Plan, tests, completion, performance, protected diff, final |

## 3. Files to modify (minimal)

| Path | Change |
|------|--------|
| `modules/ars/assets/src/ars-app.css` | Shell layout tokens/utilities |
| `modules/ars/assets/build.sh` content globs | Include shell PHP/JS |
| `modules/ars/index.php` | **Opt-in** to new shell; keep existing dashboard body (no Command Center redesign) |
| Phase 3 docs / roadmap markers | Wave 1 status |

**Do not modify:** legacy `ars_layout_header.php` behaviour for non-opt-in pages; Stay; API; Class A/B financial files.

## 4. Navigation (approved)

| Item | Route / behaviour |
|------|-------------------|
| Command Center | `index.php` |
| Reservations | `bookings.php` |
| Calendar | `calendar.php` |
| Guests | `guests.php` |
| Properties & Units | `units.php` |
| Operations ▸ Housekeeping | `housekeeping.php` |
| Operations ▸ Maintenance | `maintenance.php` |
| Finance | `financial_reports.php` (promoted); empty `accounting/` **retired** from shell |
| Activity Center | Disabled placeholder (no global page yet) |
| Reports | `revenue.php` interim (Reports hub is Wave 8) |
| Settings | `settings.php` |

Permission-aware via existing `has_department_access` (Core / Operations). Unauthorized items hidden.

## 5. Opt-in model

```php
$arsUseShell = true;
require ars_shell.php;
ars_shell_begin([...]);
// page body
ars_shell_end();
```

Pages without `$arsUseShell` keep Bootstrap legacy layout.

## 6. Acceptance

1. Shell renders on preview + opted-in index  
2. Nav matches IA; accounting stub absent  
3. Responsive: desktop collapse, tablet, mobile drawer + bottom nav  
4. Keyboard: skip link, sidebar toggle, focus visible  
5. Legacy pages unchanged (no Wave assets)  
6. Protected hashes PASS; Adapter OFF; Stay untouched  
7. Wave 0 foundation not rewritten  

## 7. Rollback

Revert `index.php` to legacy includes; remove shell require. Legacy layout remains intact.
