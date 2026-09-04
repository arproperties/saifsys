# HeroSysgro ERP Development Guide

**Official guide for future developers and Cursor agents.**  
**Based on:** Stages 1–3 audits and `docs/ERP_DECISIONS.md`.  
**Do not rewrite the ERP.** Build on native PHP + MySQL + Bootstrap 5.3.3.

---

## 1. Folder structure (confirmed)

| Path | Role |
|------|------|
| `includes/` | Shared auth, company, permissions, Cleaning GL, services |
| `accounts/`, `operation/` | Cleaning finance & ops |
| `modules/{realestate,construction,ars,inventory,grocery,barber,legal,tasks}/` | Business modules |
| `hr/` | HR & payroll |
| `api/` | Mobile, customer v1, POS |
| `tenant_portal/`, `stay/` | External portals |
| `migrations/`, `database/` | Schema evolution & sync tooling (human-gated) |
| `docs/` | Decisions, design system, audits |
| `.cursor/` | Rules, skills, agents |

Routing is file-based PHP + Apache rewrite — not a framework front controller.

---

## 2. Naming conventions

- Module layouts: `*_layout_header.php` / `*_layout_footer.php`
- RE Invoice Mode engines: `*_engine.php`, `post_*_to_accounting`
- Construction: `co_*` tables/helpers; Cleaning SM: `sm_*`; Inventory: `inv_*`; Shared journals: `re_*`
- Permissions packs: `includes/permissions/*_permissions.php`

---

## 3. Controllers / pages

One PHP page ≈ one screen or AJAX endpoint. Always include auth gates at top (`require_login`, `require_module_access`, `require_role`, and/or `require_permission`).

---

## 4. Services (current + target)

**Current:** procedural helpers (`accounting_engine.php`, `gl_posting.php`, domain `post_*`, `sm_*`).  
**Target (incremental, DEC service architecture):** facades such as AccountingPostingService / JournalService — wrap existing functions first; do not big-bang rewrite.

---

## 5. Repositories

No formal repository layer required today. When extracting data access, keep PDO prepared statements and company filters. Do not introduce ORM mandates.

---

## 6. Views / UI

Use module layouts + Bootstrap 5.3.3. Follow `docs/HEROSYSGRO_DESIGN_SYSTEM.md` and `FINANCIAL_UI_STANDARDS.md`. Brand tokens from `includes/branding.php`.

---

## 7. Accounting integration

| Company type | Stack | Entry |
|--------------|-------|-------|
| Cleaning | `gl_*` | `includes/gl_posting.php` |
| RE / Construction / ARS / non-cleaning payroll | `re_*` | `accounting_engine.php` |

RE official model: **Invoice Mode**. Legacy: historical only — do not extend.

Always separate: business agreement → operational schedule → accounting documents → reporting.

---

## 8. Permissions

Layers: login → company → module → department → role → granular permission → Guard (workers). See `PERMISSION_AND_APPROVAL_MODEL.md`. Do not invent approval thresholds.

---

## 9. Validation

Server-side validation required. CSRF on state-changing POST/AJAX. Escape output with `h()` / `htmlspecialchars`.

---

## 10. Database & transactions

PDO via `includes/db_connect.php`. Use transactions for multi-step financial writes. Prefer additive `migrations/`. Never run sync/repair without approval.

---

## 11. Error handling & logging

Fail closed on missing company for financial writes (target). Log errors without secrets. Prefer reverse journals over deleting posted history.

---

## 12. Audit

Use existing audit tables/helpers (`audit_log`, `re_accounting_audit_log`, domain audits). New money events should write audit records.

---

## 13. Reports

Scope by company. State VAT methodology (GL vs documents). Paginate heavy RE reports.

---

## 14. Testing

Minimal automated suite today. For financial changes: manual test plan from `regression-analysis` skill; pilot PHPUnit later per evolution roadmap.

---

## 15. Deployment

Shared hosting; `.htaccess` / `.htaccess.live`. Use `release-readiness` skill. No unapproved cron HTTP exposure.

---

## 16. Documentation & review

- Decisions → `docs/ERP_DECISIONS.md`
- Business rules → capture standard (Confirmed only)
- Reviews → `docs/cursor-audit/REVIEW_WORKFLOWS.md` (WF-1…WF-7) + `.cursor/skills/`
- Official financial reports → `financial-report-validation` skill
- Platform map → `.cursor/README.md`

---

## 17. Review process (summary)

Shared/accounting/DB/API/UI money paths must pass the matching workflow before merge/deploy. Use WF-7 for heavy lists/reports/cron/PDF. Human approval required for posting behaviour, schema apply, and production deploy. Agents are readonly and report-first.
