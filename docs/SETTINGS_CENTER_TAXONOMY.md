# Settings Center Taxonomy

**Status:** Confirmed architecture for ERP Settings reorganization (Bootstrap UI; no Tailwind/SPA rewrite).  
**UI shell:** Light white + gold **Administration Design System** — see `docs/admin/ADMIN_DESIGN_SYSTEM.md` (Bootstrap 5 + Alpine + Lucide + Chart.js, same architecture as Construction, not dark theme).  
**HR module:** Same light white + gold Control Center look — see `docs/hr/HR_DESIGN_SYSTEM.md` (`assets/hr/hr-ui-v2.css`, dedicated HR shell; not dark brand sidebar).  
**Related:** `docs/HEROSYSGRO_DESIGN_SYSTEM.md`, `docs/ERP_DECISIONS.md` (DEC-001 dual ledger), Settings & Audit review plan.

## Principles

1. **Hub = navigation + policy.** Deep operational screens (period close, bank reco, COA maintenance) stay in-module with deep-links from Settings.
2. **Scope tags** on every setting: Global | Company | Module | Role.
3. **No ledger unification** — Cleaning `gl_*` and shared `re_*` remain separate.
4. **Do not invent** approval monetary thresholds or commercial rules.

## Information architecture

| Group | Scope | Contents |
|-------|-------|----------|
| Organization | Global / Company | Companies & modules, company profile (selected company), branding (global) |
| Access | Role | Users, roles, departments, task permissions |
| People (HR) | Global / Company | Cash advance / loan policy, leave types, holidays, emp profile features, payroll GL mapping |
| Finance policies | Global / Module | Locale/VAT defaults; deep-links to Cleaning accounting, RE Invoice Mode, Construction finance |
| Communications | Global / Company | Single SMTP (`app_email_settings`); RE/ARS/AMC notification recipients |
| Module configuration | Module / Company | Cleaning SM categories & gap minutes; RE accounting mode & alerts; Construction docs/theme links; ARS defaults; Inventory defaults |
| Documents & numbering | Module | Cleaning invoice templates; sequence overview |
| Integrations | Company / Env | ARS Stripe (status); mobile JWT (env status only — never print secret values) |
| Security | Global | Audit retention; login policy (future) |
| Audit History | Global (Owner) | Owner-friendly activity feed |

## Existing hub tabs (mapping)

| Current `settings.php` tab | Maps to group | Notes |
|----------------------------|---------------|-------|
| company | Organization | Must become company-scoped (stop hardcoding id=1) |
| users / roles / departments | Access | |
| system | Finance / Organization | Locale defaults |
| branding | Organization | Global |
| emp_profile | People (HR) | |
| email | Communications | Canonical SMTP UI |
| re_email | Communications | Company-scoped |
| accounting | Module (Cleaning) | Label as Cleaning-only |
| service_categories | Module (Cleaning) | |
| cash_advance_policy | People (HR) | |
| companies | Organization | Expand beyond RE-only links |
| history | Audit History | Owner-only |
| module_hub | People / Finance / Module links | Deep-links to HR holidays, RE accounting mode, Construction docs, ARS, Inventory |
| integrations | Integrations | Status only (JWT env, Stripe flags) — never secret values |
| security | Security | Owner audit retention + archive job |

## Duplicate UIs to consolidate

- SMTP: `settings.php?tab=email` (canonical). `operation/email_settings.php` redirects here. `hr/org_units.php` reminders keep schedules/recipients; SMTP edits removed (link only).
- Hardcoded `includes/email_config.php` should not override DB settings for app mail (tracked separately for security).

## Explicit non-goals

- Grocery / Barber settings in this program
- React / Tailwind Settings rewrite
- Inventing maker/checker thresholds
