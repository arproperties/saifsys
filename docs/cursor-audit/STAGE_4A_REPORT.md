# Stage 4A Report — Engineering Platform Validation

**Date:** 2026-07-10  
**Type:** Platform validation only  
**Production ERP code modified:** **No**  
**Stage 4B (enhancements):** **Not started**

---

## 1. Engineering Platform Health Score

| Dimension | Score (1–10) | Notes |
|-----------|-------------:|-------|
| Rules Health | **7.5** | Strong hierarchy and DEC alignment; minor trigger/coverage gaps |
| Skills Health | **8.0** | Realistic procedures; a few domain gaps |
| Agents Health | **8.0** | All `readonly: true`; clear roles; mild overlap |
| Workflow Health | **7.0** | Solid skill chains; under-specify Rules and Performance/UI deploy gates |
| Documentation Health | **8.5** | Decisions, guides, roadmap, capture standard present |
| Overall platform readiness for Stage 4B | **7.5 / 10** | **Ready for controlled Stage 4B** after small platform fixes below |

**Verdict:** The Stage 3 brain is coherent and usable. It will guide real development safely if humans enforce review workflows. Recommended platform polish (docs/rules only) before or in parallel with Stage 4B Critical Stability work—not a blocker for planning 4B, but **fix trigger gaps first** if possible.

---

## 2. Part 1 — Rules review

### 2.1 Inventory

| Layer | Files | Apply mode |
|-------|------:|------------|
| 00 Hierarchy | 1 | **No YAML frontmatter** (see issue R1) |
| 01-core | 2 | alwaysApply |
| 02-architecture | 3 | alwaysApply |
| 03-security | 2 | 1 always + 1 globs |
| 04–08 | 5 | globs |
| 09–14 modules | 6 | globs |
| 15–16 | 2 | globs |
| **Total** | **21** | 6 alwaysApply |

### 2.2 Hierarchy and inheritance

| Check | Result |
|-------|--------|
| Top-down layering | **Pass** — core → architecture → domain → modules |
| Generic before module-specific | **Pass** |
| DEC alignment | **Pass** — DEC-001…012 referenced consistently |
| No invented business rules | **Pass** — capture standard cited |
| Invoice Mode vs Legacy | **Pass** — no contradiction |
| Dual ledger | **Pass** — Cleaning vs Shared consistent |

### 2.3 Duplication / redundancy

| Item | Assessment | Recommendation |
|------|------------|----------------|
| `erp-core` + `no-invented-rules` | Mild overlap on “don’t invent rules” | **Acceptable**; keep both (core = decisions/safety; second = commercial policy) |
| `dual-ledger` (always) + module cleaning/CO/ARS/HR | Intentional reinforcement | Keep; modules add local HOW |
| `security-standards` (always) + `api-portal-security` | Complementary | Keep |
| `accounting-integrity` + `invoice-mode` | Complementary | Keep |
| Hierarchy file vs `erp-core` sources list | Mild doc duplication | OK as map |

**No contradictory Accepted guidance found.**

### 2.4 Too broad / too narrow / missing / conflicting

| ID | Rule | Classification | Issue | Recommendation (docs/rules only) |
|----|------|----------------|-------|----------------------------------|
| R1 | `00-HIERARCHY.mdc` | **Missing frontmatter** | May not load as a Cursor rule | Add `alwaysApply: false` + description, or move to `.cursor/README.md` |
| R2 | `06-ui/ui-ux-standards.mdc` | **Trigger risk** | `globs` wrapped in one quoted string — may not split patterns | Unquote / use multi-pattern form Cursor expects |
| R3 | `05-accounting/accounting-integrity.mdc` | **Too narrow globs** | Misses many RE money files (`lease_add.php`, `billing_*.php`, `payment_*.php`, `includes/invoice_engine.php`, `accounting_integration.php` is under `accounting/` OK; lease/payment roots may miss) | Broaden to `modules/realestate/**/*.php` *or* rely on `09-module-realestate` + always dual-ledger (document that IM rule covers RE) |
| R4 | Module coverage | **Missing** | No dedicated rules for Legal, Barber, Tasks; Grocery only via inventory | Add thin module notes *or* document “covered by generic rules” in hierarchy |
| R5 | `07-performance` | **Too narrow** | Misses Construction reports, HR payroll ZIP, accounts non-`report_*` heavy pages | Extend globs: `modules/construction/**`, `hr/**`, `accounts/**` selectively |
| R6 | `08-deployment` | **Gap** | Doesn’t glob root deploy markdown like `DEPLOYMENT_*.md` at repo root consistently (`**/DEPLOYMENT*.md` OK) | Verify; add `docs/**/DEPLOYMENT*` if needed |
| R7 | alwaysApply ×6 | **Slightly broad context** | Every chat loads dual-ledger + security + isolation | Acceptable for ERP; if context pressure, demote `shared-code-impact` to globs on `includes/**` + engine paths |
| R8 | `16-documentation` `**/*.md` | **Too broad** | May fire on vendor/root noise markdown | Prefer `docs/**/*.md` only |

**Conflicts:** None between Accepted decisions and rules.

---

## 3. Part 2 — Skills review

### 3.1 Inventory (19)

All skills include: Purpose, When, Discovery, Checklist, Required documents, Evidence, Acceptance, Output, Escalation, Stop, Prohibited.

### 3.2 Health by skill

| Skill | Realistic? | Evidence? | Stops? | Acct/Sec safety? | Output? | Gap |
|-------|------------|-----------|--------|------------------|---------|-----|
| accounting-review | Yes | Yes | Yes | Strong | Yes | — |
| accounting-event-design | Yes | Yes | Yes | Strong | Yes | — |
| invoice-mode-review | Yes | Yes | Yes | Strong | Yes | — |
| cleaning / construction accounting | Yes | Yes | Yes | Strong | Yes | — |
| database-impact / migration | Yes | Yes | Yes | DB safety | Yes | — |
| security / permission / api | Yes | Yes | Yes | Strong | Yes | — |
| ui-consistency / workflow | Yes | Yes | Yes | Policy-safe | Yes | No dedicated a11y skill |
| performance | Yes | Yes | Yes | No prod load tests | Yes | — |
| module-impact | Yes | Yes | Yes | — | Yes | — |
| release / regression / code-review | Yes | Yes | Yes | Gates | Yes | — |
| bug / RCA | Yes | Yes | Yes | No delete posted history | Yes | — |

### 3.3 Missing capabilities (recommendations only)

| Gap | Priority | Recommendation |
|-----|----------|----------------|
| Financial report validation skill | P2 | Add before Stage 4B report work (TB/P&L/VAT checks) |
| Accessibility review skill | P3 | Optional; standards doc exists |
| Legal / Barber thin skills | P3 | Usually covered by code-review + module-impact |
| Explicit “link to WF-id” in each skill | P2 | Add “Parent workflow: WF-1…” section |
| Automated enforcement | N/A | Skills are procedural—not CI; human/agent must invoke |

**No skill invents business rules or weakens accounting/security stops.**

---

## 4. Part 3 — Agents review

| Agent | Responsibility | Readonly | Overlap | Assessment |
|-------|----------------|----------|---------|------------|
| erp-architect | Cross-module architecture | Yes | vs commercial / accounting | Clear; keep |
| accounting-architect | Posting/IM/dual ledger | Yes | vs erp-architect on engine | OK — accounting deeper |
| database-architect | Schema/migrations | Yes | Low | Clear |
| security-reviewer | Authz/CSRF/API/cron | Yes | vs api skill | OK |
| performance-reviewer | N+1/lists/cron/PDF | Yes | Low | Clear |
| ui-ux-reviewer | Design system / financial UI | Yes | vs workflow-reviewer | OK — UI vs E2E |
| workflow-reviewer | Multi-step UX | Yes | vs ui-ux | Keep both |
| release-manager | Go/No-Go | Yes | vs code-reviewer | Clear |
| code-reviewer | Diff quality | Yes* | Broad | *Body says readonly unless parent asked implementation — **clarify** to always report-first; `readonly: true` already set |
| commercial-erp-reviewer | Productization/roadmap | Yes | vs erp-architect | OK |

**Permissions:** Agents are review-only; none should modify ERP.  
**Recommendation C1:** Tighten `code-reviewer` body to remove “unless implementation” ambiguity while `readonly: true` remains.

---

## 5. Part 4 — Review workflows

| Workflow | Covers | Skills referenced | Rules referenced | Gap |
|----------|--------|-------------------|------------------|-----|
| WF-1 Accounting | Accounting | Yes | **Implicit only** | Add explicit rule list |
| WF-2 Database | DB/Migration/Security | Yes | Implicit | Add `database-safety`, `deployment-safety` |
| WF-3 Release | Deploy/Regression/Sec/Acct | Yes | Implicit | Add performance check when lists/reports touched |
| WF-4 UI money | UI/Workflow/Permission | Yes | Implicit | Name `ui-ux-standards`, `financial-ui` doc |
| WF-5 API | API/Sec/Permission | Yes | Implicit | Name `api-portal-security` |
| WF-6 Bug→Release | Full chain | Yes | Implicit | OK |

| Area | Workflow coverage |
|------|-------------------|
| Accounting | WF-1, WF-3, WF-6 |
| Database / Migration | WF-2, WF-3 |
| Security | WF-2, WF-3, WF-5 |
| UI | WF-4 |
| Deployment / Release | WF-3 |
| Performance | **Weak** — no dedicated WF |
| Legal/Barber-specific | Generic only |

**Recommendation W1:** Add **WF-7 Performance** (performance-review → optional database-impact → human if index/migration).  
**Recommendation W2:** Amend `REVIEW_WORKFLOWS.md` to list activating Rules per WF (documentation-only fix in 4B prep).

---

## 6. Part 5 — Simulated development scenarios

*No production code was modified. Simulations only.*

### Scenario 1 — Add one field to a report
| | |
|--|--|
| **Example** | Add column to RE outstandings or Cleaning VAT report |
| **Rules** | alwaysApply core/architecture/security; `ui-ux-standards` if PHP view; `performance-standards` if RE/report path; `documentation-standards` if docs; IM rule if RE |
| **Skills** | `ui-consistency-review` (light); `performance-review` if query heavier; `code-review` |
| **Agents** | ui-ux-reviewer; performance-reviewer if slow path |
| **Docs** | FINANCIAL_UI_STANDARDS, PERFORMANCE_REVIEW, ACCOUNTING reports section |
| **Human approval** | Usually **No** if display-only and company-scoped; **Yes** if changes totals/recognition |

### Scenario 2 — Modify invoice logic
| | |
|--|--|
| **Example** | Change RE `post_invoice_to_accounting` or Cleaning `gl_post_invoice` |
| **Rules** | Core + dual-ledger + isolation + shared-impact; `accounting-integrity`; IM or cleaning module rule |
| **Skills** | **WF-1:** accounting-review → module-impact → invoice-mode or cleaning-accounting → (db-impact if schema) |
| **Agents** | accounting-architect; erp-architect if engine touched |
| **Docs** | ACCOUNTING_ARCHITECTURE, EVENT_CATALOG, ERP_DECISIONS DEC-001/003 |
| **Human approval** | **Yes** (posting behaviour) |

### Scenario 3 — Create new financial report
| | |
|--|--|
| **Example** | New IM AR ageing page |
| **Rules** | Accounting integrity (if queries journals); UI; performance; IM; company isolation |
| **Skills** | accounting-event-design (if new metrics); accounting-review; ui-consistency; performance-review; permission-review |
| **Agents** | accounting-architect; ui-ux-reviewer; performance-reviewer |
| **Docs** | FINANCIAL_UI_STANDARDS, EVENT_CATALOG, DESIGN_SYSTEM |
| **Human approval** | **Yes** if defines new “official” balances; else review recommended |

### Scenario 4 — Improve dashboard UI
| | |
|--|--|
| **Example** | RE `index.php` or `account.php` KPI layout |
| **Rules** | UI standards; performance (dashboard globs); core |
| **Skills** | ui-consistency-review; performance-review (cache); workflow-review if nav changes |
| **Agents** | ui-ux-reviewer; performance-reviewer |
| **Docs** | DESIGN_SYSTEM, PERFORMANCE_REVIEW |
| **Human approval** | **No** for pure CSS/layout; **Yes** if KPI definitions change |

### Scenario 5 — Modify security permissions
| | |
|--|--|
| **Example** | New `require_permission` on refund action |
| **Rules** | security-standards; company-isolation; permission via docs |
| **Skills** | permission-review; security-review; (accounting-review if money action) |
| **Agents** | security-reviewer |
| **Docs** | PERMISSION_AND_APPROVAL_MODEL, BUSINESS_RULE_CAPTURE_STANDARD |
| **Human approval** | **Yes** if changing who can post/reverse/refund; do not invent thresholds |

### Scenario 6 — Add database column
| | |
|--|--|
| **Example** | `migrations/add_x.sql` on `re_invoices` |
| **Rules** | database-safety; deployment-safety; module-impact via shared-code if hot table; accounting if financial meaning |
| **Skills** | **WF-2:** database-impact → migration-review → module-impact → security if needed |
| **Agents** | database-architect; accounting-architect if money semantics |
| **Docs** | DATABASE_ARCHITECTURE, ERP_DECISIONS |
| **Human approval** | **Yes** before apply/sync; migration file authoring OK with review |

### Scenario 7 — Create new module
| | |
|--|--|
| **Example** | New first-class vertical under `modules/foo` |
| **Rules** | erp-core; dual-ledger (choose stack); isolation; UI; documentation; possibly new module rule |
| **Skills** | module-impact; accounting-event-design if money; security; ui; workflow; release later |
| **Agents** | erp-architect; commercial-erp-reviewer; accounting-architect if GL |
| **Docs** | ERP_MODULE_MAP, DEVELOPMENT_GUIDE, CURSOR_ENGINEERING_STRATEGY § new modules, ERP_DECISIONS (new DEC) |
| **Human approval** | **Yes** — architecture decision + MODULE_* registration + ledger profile |

**Platform reaction summary:** Scenarios 2, 5, 6, 7 correctly demand human approval; 1 and 4 stay lightweight; 3 depends on whether the report invents new accounting meaning.

---

## 7. Part 6 — ERP Evolution Roadmap review

| Check | Result |
|-------|--------|
| Phase 1 = Critical Stability first | **Logical** — matches Stage 1.5/2 P0s |
| Phase 2 services after controls | **Logical** — facades after idempotency |
| Phase 3 productization after architecture | **Logical** |
| Phase 4 commercial / SaaS last | **Logical**; SaaS correctly optional |
| Phase 5 intelligent/MCP last | **Logical**; MCP readonly-first |
| No rewrite | **Pass** |
| Aligns with DEC-* | **Pass** |

### Recommended adjustments (not implemented)

| Adj | Suggestion | Why |
|-----|------------|-----|
| A1 | Split Phase 1 into **1A Security ops** and **1B Accounting controls** | Parallel tracks; security often ops-only |
| A2 | Move “paginate RE lists” earlier within Phase 1 (alongside security) | UX/perf pain is immediate; low accounting risk |
| A3 | Explicitly gate Phase 2 service extraction on Phase 1 idempotency acceptance | Already implied; make dependency bold |
| A4 | Add “Platform polish (4A findings R1–R8, W1–W2)” as Phase 0 / 4A follow-up | Improves Stage 4B agent reliability |
| A5 | Do not elevate SaaS (Phase 4) without new Accepted decision | Already cautious — keep |

**Priorities remain sound for Stage 4B kickoff = Phase 1 Critical Stability.**

---

## 8. Coverage map

| Concern | Rules | Skills | Agents | Workflows |
|---------|-------|--------|--------|-----------|
| Dual ledger | Yes | Yes | Yes | WF-1 |
| Invoice Mode | Yes | Yes | Yes | WF-1 |
| Company isolation | Yes | Yes | Yes | WF-1/2/5 |
| Security/CSRF/JWT | Yes | Yes | Yes | WF-2/3/5 |
| Migrations | Yes | Yes | Yes | WF-2/3 |
| UI/Design system | Yes | Yes | Yes | WF-4 |
| Performance | Partial | Yes | Yes | **Weak WF** |
| Legal/Barber/Tasks | Generic only | Generic | Generic | — |
| MCP | Doc only | — | commercial | — |

---

## 9. Missing capabilities & potential improvements

**Before Stage 4B (platform-only, recommended):**

1. Fix `00-HIERARCHY` frontmatter or relocate to README (**R1**).  
2. Fix UI rule glob quoting (**R2**).  
3. Broaden accounting/RE globs or document reliance on `09-module-realestate` (**R3**).  
4. Narrow docs glob to `docs/**` (**R8**).  
5. Extend `REVIEW_WORKFLOWS.md` with Rules lists + **WF-7 Performance** (**W1/W2**).  
6. Clarify `code-reviewer` readonly wording (**C1**).  
7. Optionally note Legal/Barber/Tasks “generic coverage” in hierarchy.

**During early Stage 4B (optional platform):**

8. Add `financial-report-validation` skill.  
9. Thin Legal module rule if legal-cheque money paths grow.

---

## 10. Recommendations before Stage 4B

| Priority | Action | Type |
|----------|--------|------|
| P0 | Treat platform as **validated with caveats**; do not block 4B planning | Process |
| P0 | When 4B starts, first changes must still run **WF-1/WF-2/WF-3** as applicable | Process |
| P1 | Apply platform polish R1, R2, R3, W2 (docs/rules only—still not ERP features) | Platform |
| P1 | Stage 4B scope = Evolution Roadmap **Phase 1** only (Critical Stability) | Scope lock |
| P2 | Add WF-7 Performance; financial-report-validation skill | Platform |
| P2 | Roadmap adjustments A1–A4 as documentation edits | Docs |

**Do not begin large features, redesigns, or refactors in 4B until Phase 1 items are sequenced.**

---

## 11. Explicit confirmations

- No production PHP, JavaScript, SQL, migrations, configuration, or UI were modified in Stage 4A.  
- No ERP enhancements were implemented.  
- Validation is based on inspecting `.cursor/**` and Stage 3 docs, plus simulated scenarios.

---

## 12. Stop

**Stage 4A complete.**  

Await explicit approval before **Stage 4B** (ERP Critical Stability enhancements using this platform).
