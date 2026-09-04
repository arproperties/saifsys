# Permission and Approval Model

**Stage:** 2  
**Date:** 2026-07-10  
**Rule:** Do not invent approval limits or monetary thresholds.

## Layers (Confirmed from code)

| Layer | Mechanism | Primary files |
|-------|-----------|---------------|
| Authentication | Session / portal session / JWT | `auth.php`, portal auth, API JWT |
| Company access | `user_companies`, `current_company_id`, `user_has_company_access` | `company_helper.php`, `switch_company.php` |
| Module access | `require_module_access`, MODULE_* | `module_access.php` |
| Department access | `has_department_access` / `require_department_access` | `rbac_department.php` |
| Role checks | `require_role` / `has_role` | `auth.php` |
| Granular permissions | JSON in `role_modules.permissions` via `has_permission` | `permissions.php` + `includes/permissions/*` |
| Worker route lock | Allowlist | `lib/Guard.php` |

Owner/Admin often bypass department/permission checks — Confirmed from code.

---

## Enforcement map (representative)

| Area | Typical gate | Enforcement quality | Notes |
|------|--------------|---------------------|-------|
| Cleaning accounts pages | `require_role(['Owner','Admin','Account'])` ± permission | Server-side | May not use MODULE_FINANCE consistently |
| RE pages | `require_module_access(MODULE_REALESTATE)` ± department | Server-side | Action-level perms vary by page |
| Construction | Module + department | Server-side | — |
| Grocery/Barber | Module + department + permissions | Server-side | Stronger granular pattern |
| Inventory | Module | Server-side | — |
| HR | Module / role / worker Guard | Server-side | Worker self-service constrained |
| AJAX endpoints | Mixed role/module; CSRF mixed | **Inconsistent** | Some missing CSRF |
| UI hide/show | Permission checks in layouts | **UI-only supplement** | Must not be sole control |
| Cron | Often none over HTTP | **Missing** | Critical |
| APIs | JWT Bearer | Server-side | Secret fallback risk |

---

## Action permissions (financial)

| Action | Observed control | Status |
|--------|------------------|--------|
| Post journal / invoice / receipt | Role/module on page + engine rules | Partially enforced; not uniform permission keys |
| Reverse journal | UI + `reverse_journal`; company re-check weak in engine | Needs hardening |
| Refund | Page actions; refund poster technical debt | Needs business confirmation who may refund |
| Credit note | RE credit note UI + role/module | Needs business confirmation |
| Payroll post | HR payroll finalize path | Needs business confirmation for dual approval |
| Bank reco confirm / create JV | Module pages + AJAX role checks (varies) | Needs business confirmation for “approval” |
| Expense approve | `sm_user_can_approve_expense` etc. | Present — not full maker/checker |
| WO adjustment approve | SM adjustment services | Present |
| Tenant service approve | Portal/staff approve flows | Present |
| Accounting mode override | `re_accounting_current_user_can_override_mode` | Policy-driven — Confirmed from code |
| User impersonation | — | **Not found** |

---

## Maker / checker

| Finding | Evidence |
|---------|----------|
| True maker/checker framework | **Not found** |
| Approval-style workflows | Expenses, adjustments, some requests — Confirmed from code |
| Self-approval by Admin/Accountant | Strong inference / Needs business confirmation to forbid |

**Do not invent** monetary limits. Record future rules via `docs/BUSINESS_RULE_CAPTURE_STANDARD.md`.

---

## Gaps summary

| Gap | Type | Priority |
|-----|------|----------|
| Cron unauthenticated | Missing | P0 |
| AJAX CSRF / auth gaps | Inconsistent | P0 |
| Company fallback `?: 1` | Weak isolation | P1 |
| Accounts role vs finance module mismatch | Inconsistent | P2 |
| UI-only hiding without server check on some actions | Risk | P1 — Needs manual review per action |
| Posted document edit rules | Needs business confirmation | P1 |
| Refund / CN / reverse permission matrix | Needs business confirmation | P1 |

---

## Explicit non-changes

No permission matrices invented or code changed.
