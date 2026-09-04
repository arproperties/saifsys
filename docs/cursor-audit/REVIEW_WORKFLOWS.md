# Review Workflows (Engineering Standards)

**Stage:** 4A.1  
**Purpose:** Reusable gates before high-risk work. Skills live under `.cursor/skills/`. Agents under `.cursor/agents/` are **readonly**.

---

## WF-1 — Before modifying accounting

| Field | Content |
|-------|---------|
| **Activating Rules** | `01-core/*`, `02-architecture/dual-ledger`, `company-module-isolation`, `shared-code-impact`, `05-accounting/accounting-integrity`, module rules (`09` RE IM / `10` CO / `11` Cleaning / `12` HR / `14` ARS) as applicable |
| **Required Skills** | `accounting-review` → optional `database-impact-analysis` → `module-impact-analysis` (shared engine/`gl_posting`) → `invoice-mode-review` (RE) → `construction-accounting-review` or `cleaning-accounting-review` → `financial-report-validation` if official reports change → `accounting-event-design` if new event |
| **Recommended Agents** | `accounting-architect`; `erp-architect` if shared engine; `database-architect` if schema |
| **Required documents** | `ACCOUNTING_ARCHITECTURE.md`, `ACCOUNTING_TECHNICAL_DEBT.md`, `ACCOUNTING_EVENT_CATALOG.md`, `ERP_DECISIONS.md` |
| **Human approval** | Any posting, allocation, VAT, deposit, refund, credit note, or reversal behaviour change |
| **Stop conditions** | Legacy RE extension; ledger stack unclear; unconfirmed commercial policy required |
| **Expected output** | Stack/company identified; trace; severity findings; report impact; open confirmations |

---

## WF-2 — Before modifying shared database / migrations

| Field | Content |
|-------|---------|
| **Activating Rules** | `04-database/database-safety`, `08-deployment/deployment-safety`, `02-architecture/company-module-isolation`, `shared-code-impact` if hot tables |
| **Required Skills** | `database-impact-analysis` → `migration-review` → optional `security-review` → `module-impact-analysis` |
| **Recommended Agents** | `database-architect`; `security-reviewer` if exposure; `accounting-architect` if financial columns |
| **Required documents** | `DATABASE_ARCHITECTURE.md`, `MODULE_DEPENDENCY_MAP.md`, `docs/database/DATABASE_STRUCTURE_SYNC.md` |
| **Human approval** | Before any apply/sync/repair/posting tool or live DDL |
| **Stop conditions** | Destructive SQL without backup plan; unique constraints without cleanup plan |
| **Expected output** | Table/module impact matrix; risks; rollback; approval needed |

---

## WF-3 — Before deployment / release

| Field | Content |
|-------|---------|
| **Activating Rules** | `08-deployment/deployment-safety`, plus domain rules for changed areas |
| **Required Skills** | `release-readiness` → `regression-analysis` → `security-review` (P0/P1) → `migration-review` if migrations → `accounting-review` if money paths → `financial-report-validation` if official reports → `performance-review` if lists/reports/cron/PDF touched → `code-review` |
| **Recommended Agents** | `release-manager`; `security-reviewer`; `accounting-architect` if money; `performance-reviewer` if heavy paths |
| **Required documents** | `REVIEW_WORKFLOWS.md`, `ERP_EVOLUTION_ROADMAP.md`, `SECURITY_AND_CONTROLS.md` |
| **Human approval** | Always for production deploy |
| **Stop conditions** | Open Critical accounting/security blockers |
| **Expected output** | Go/No-Go; blockers; test plan; rollback |

---

## WF-4 — Before financial UI / workflow changes

| Field | Content |
|-------|---------|
| **Activating Rules** | `06-ui/ui-ux-standards`, `09-module-realestate/invoice-mode` if RE, `05-accounting` if money semantics, core no-invented-rules |
| **Required Skills** | `workflow-review` → `ui-consistency-review` → `permission-review` → `financial-report-validation` if report UI defines totals |
| **Recommended Agents** | `workflow-reviewer`; `ui-ux-reviewer`; `security-reviewer` if authz UI |
| **Required documents** | `HEROSYSGRO_DESIGN_SYSTEM.md`, `FINANCIAL_UI_STANDARDS.md`, `WORKFLOW_UX_REVIEW.md`, `BUSINESS_RULE_CAPTURE_STANDARD.md` |
| **Human approval** | Policy-dependent UX; changes to official balance meanings |
| **Stop conditions** | Inventing commercial rules; removing supported flexibility without Confirmed rules; extending Legacy UX |
| **Expected output** | Workflow map; UI deviations; confirmations; acceptance criteria |

---

## WF-5 — Before API / portal changes

| Field | Content |
|-------|---------|
| **Activating Rules** | `03-security/security-standards`, `api-portal-security`, `15-mobile-api/mobile-api`, company isolation |
| **Required Skills** | `api-review` → `security-review` → `permission-review` |
| **Recommended Agents** | `security-reviewer` |
| **Required documents** | `SECURITY_AND_CONTROLS.md`, `MCP_READINESS.md` (if tooling) |
| **Human approval** | New public endpoints; JWT/CORS/secret scheme changes |
| **Stop conditions** | Hardcoded secret fallbacks; unscoped tenant/company queries |
| **Expected output** | Endpoint authz/scoping findings; severity; recommendations |

---

## WF-6 — Bug → fix → release

| Field | Content |
|-------|---------|
| **Activating Rules** | Domain rules for the buggy area |
| **Required Skills** | `bug-investigation` → optional `root-cause-analysis` → domain reviews (accounting/security/db) → `code-review` → `regression-analysis` → `release-readiness` |
| **Recommended Agents** | Matching domain agent + `code-reviewer` + `release-manager` |
| **Required documents** | Relevant audit docs + `TECHNICAL_DEBT_REGISTER.md` |
| **Human approval** | Data repair, posting corrections, production hotfix deploy |
| **Stop conditions** | Insufficient evidence; request to delete posted history instead of reverse |
| **Expected output** | Cause; safe fix options; regression matrix; Go/No-Go |

---

## WF-7 — Performance review

| Field | Content |
|-------|---------|
| **Activating Rules** | `07-performance/performance-standards`; `04-database` if indexes/schema; `08-deployment` if cron/tools |
| **Required Skills** | `performance-review` → optional `database-impact-analysis` → optional `migration-review` → `module-impact-analysis` if shared code → `financial-report-validation` if official report queries change meaning/perf together |
| **Recommended Agents** | `performance-reviewer`; `database-architect` if schema/index |
| **Required documents** | `PERFORMANCE_REVIEW.md`, `DATABASE_ARCHITECTURE.md` |
| **Human approval** | Index/schema change; caching policy change; official report behaviour/total changes |
| **Stop conditions** | Speculative optimization without profiling evidence on hot paths; production load tests without approval |
| **Expected output** | Hotspots; fix type (pagination/cache/restructure/index); profile needed?; risks |
| **Covers** | Unbounded queries; loops/N+1; pagination; reports; dashboard fan-out; cron batching; PDF/export; API payload size; caching; indexes; live profiling requirement |

**Normal sequence:**  
`performance-review` → optional `database-impact-analysis` → optional `migration-review` → `module-impact-analysis` if shared → human approval if index/schema/caching/official report behaviour changes.

---

## Notes

- Workflows do not auto-run production tools.  
- Agents are readonly and report-first.  
- Unclear commercial policy → Business Rule Capture Standard — do not invent.
