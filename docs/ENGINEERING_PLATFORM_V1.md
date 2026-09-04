# HeroSysgro Engineering Platform V1

| Field | Value |
|-------|-------|
| **Platform Version** | **1.0** |
| **Approval Date** | 2026-07-10 |
| **Status** | Frozen — Engineering Platform Approved with Minor Notes |

---

## Purpose

This document officially finalizes the HeroSysgro Engineering Platform: the Cursor rules, skills, readonly agents, review workflows, and engineering documentation that guide all future ERP evolution.

It is **not** an ERP feature release. It does **not** change production business logic.

---

## Architecture Summary

- Native PHP + MySQL (PDO) + Bootstrap 5.3.3 + Apache rewrite; shared-hosting deployment.
- **Dual ledger:** Cleaning standalone `gl_*` vs Shared `re_*` engine (RE, Construction, ARS, non-cleaning payroll).
- **Invoice Mode** is the official Real Estate accounting model; **Legacy** is historical only.
- **Company isolation** is mandatory (`company_id` / session company).
- First-class modules: Real Estate, Construction, Cleaning, HR, Inventory, ARS, Grocery, Barber, Legal, Tenant Portal, Customer App/APIs; Tasks covered by generic rules.

**Single architectural source of truth:** [`docs/ERP_DECISIONS.md`](ERP_DECISIONS.md).

**Future commercial/business rules:** only via [`docs/BUSINESS_RULE_CAPTURE_STANDARD.md`](BUSINESS_RULE_CAPTURE_STANDARD.md) (Confirmed status).

---

## Rules Summary

Location: `.cursor/rules/` (hierarchy map: `.cursor/README.md`)

Layers: `01-core` → `02-architecture` → `03-security` → `04-database` → `05-accounting` → `06-ui` → `07-performance` → `08-deployment` → module extensions → `15-mobile-api` → `16-documentation`.

Always-on: core safety, no invented rules, dual ledger, company isolation, shared-code impact, security standards.

Module-specific rules only where behaviour differs (RE IM, Construction, Cleaning, HR, Inventory, ARS). Legal / Barber / Grocery / Tasks use generic coverage (documented in `.cursor/README.md`).

---

## Skills Summary

Location: `.cursor/skills/` — **20** procedures including accounting-review, invoice-mode-review, financial-report-validation, security/permission/api, database/migration, UI/workflow/performance, release/regression/code-review, bug/RCA, module-impact, cleaning/construction accounting reviews.

Each skill links to parent workflow(s), activating rules, agents, and human-approval conditions.

---

## Agents Summary

Location: `.cursor/agents/` — **10** specialists, all **`readonly: true`**, report-first, never implement production changes, never bypass approval gates:

erp-architect, accounting-architect, database-architect, security-reviewer, performance-reviewer, ui-ux-reviewer, workflow-reviewer, release-manager, code-reviewer, commercial-erp-reviewer.

---

## Workflow Summary

[`docs/cursor-audit/REVIEW_WORKFLOWS.md`](cursor-audit/REVIEW_WORKFLOWS.md)

| ID | Name |
|----|------|
| WF-1 | Accounting |
| WF-2 | Database / Migration |
| WF-3 | Release |
| WF-4 | Financial UI / Workflow |
| WF-5 | API / Portal |
| WF-6 | Bug → Release |
| WF-7 | Performance |

---

## Development Standards

- [`docs/ERP_DEVELOPMENT_GUIDE.md`](ERP_DEVELOPMENT_GUIDE.md)
- [`docs/HEROSYSGRO_DESIGN_SYSTEM.md`](HEROSYSGRO_DESIGN_SYSTEM.md)
- [`docs/cursor-audit/FINANCIAL_UI_STANDARDS.md`](cursor-audit/FINANCIAL_UI_STANDARDS.md)
- Evidence labels and audit pack under `docs/cursor-audit/`

---

## Engineering Principles

1. Do not invent architecture, accounting, or business rules.  
2. Preserve dual ledger; do not unify without a formal Accepted decision.  
3. RE new work → Invoice Mode only; do not extend Legacy.  
4. Company isolation on every financial read/write/report.  
5. Prefer incremental evolution; never rewrite the ERP.  
6. Human approval for posting behaviour, schema apply, and production deploy.  
7. Agents review; humans approve; implementation follows Stage 4B+ process.  
8. Distinguish business / operational / accounting / reporting layers.

---

## Known Limitations

- Automated test suite remains minimal (PHPUnit pilot is roadmap Phase 3).  
- Some alwaysApply rules add context weight on every chat (acceptable for ERP).  
- Performance/UI globs are intentionally broad on module PHP.  
- Cursor glob runtime may differ slightly from offline simulation — re-check in IDE if a rule seems silent.  
- Confirmed commercial business-rule register is empty by design until humans confirm rules.  
- Critical Stability ERP fixes (Phase 1A–1C) are **not** implemented in this platform freeze.

---

## Future Update Policy

The engineering platform is **frozen**.

Updates to `.cursor/**` or platform docs should occur **only when**:

- A significant architectural change is recorded as an Accepted decision in `ERP_DECISIONS.md`, or  
- A confirmed defect in rules/skills/workflows blocks safe ERP evolution.

Do not expand the platform for convenience or speculative coverage.

---

## Definition of Done (Platform V1)

- [x] Stages 1–4A.1 complete  
- [x] Rules, skills, agents, workflows consistent  
- [x] ERP_DECISIONS is architectural source of truth  
- [x] BUSINESS_RULE_CAPTURE_STANDARD is the only origin for future business rules  
- [x] Agents readonly  
- [x] Evolution roadmap phased with gates  
- [x] Stage 4B start guide published  
- [x] This V1 freeze document published  

**Platform status:** Engineering Platform Approved with Minor Notes (see Known Limitations).

### Finalization note (2026-07-10)
Consistency check added missing skill references on `no-invented-rules` and `company-module-isolation` only — not a hierarchy rewrite. No ERP production code changed.
