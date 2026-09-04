# Stage 2 Report — UI/UX, Security, Performance

**Date:** 2026-07-10  
**Type:** Read-only audit + design standards  
**Production logic changed:** **No**  
**Cursor Rules / Skills / subagents created:** **No**

---

## 1. Executive summary

Stage 2 evaluated HeroSysgro as a commercial ERP UX and control surface. Staff UI is **Bootstrap 5.3.3 with per-module shells** and brand tokens from `includes/branding.php` (maroon/gold). The largest UX risk is **Real Estate complexity**—especially long lease forms and **Invoice Mode vs Legacy** surfaces—while operational cheque schedules must not be confused with accounting documents. Security has a solid session/CSRF/RBAC baseline but **critical deployment gaps** (JWT fallbacks, open cron, `.fixperms.php`, upload/CSRF holes). Performance risk concentrates in **unbounded RE lists**, **dual outstandings**, **dashboard fan-out**, and **cron N+1**. Design system and financial UI standards are proposed for gradual adoption—**no screens redesigned**.

---

## 2. Files created

| File |
|------|
| [`docs/cursor-audit/UI_UX_ARCHITECTURE.md`](UI_UX_ARCHITECTURE.md) |
| [`docs/cursor-audit/WORKFLOW_UX_REVIEW.md`](WORKFLOW_UX_REVIEW.md) |
| [`docs/HEROSYSGRO_DESIGN_SYSTEM.md`](../HEROSYSGRO_DESIGN_SYSTEM.md) |
| [`docs/cursor-audit/FINANCIAL_UI_STANDARDS.md`](FINANCIAL_UI_STANDARDS.md) |
| [`docs/cursor-audit/SECURITY_AND_CONTROLS.md`](SECURITY_AND_CONTROLS.md) |
| [`docs/cursor-audit/PERMISSION_AND_APPROVAL_MODEL.md`](PERMISSION_AND_APPROVAL_MODEL.md) |
| [`docs/cursor-audit/PERFORMANCE_REVIEW.md`](PERFORMANCE_REVIEW.md) |
| [`docs/cursor-audit/ACCESSIBILITY_AND_RESPONSIVE_STANDARDS.md`](ACCESSIBILITY_AND_RESPONSIVE_STANDARDS.md) |
| [`docs/cursor-audit/UI_COMPONENT_INVENTORY.md`](UI_COMPONENT_INVENTORY.md) |
| [`docs/cursor-audit/STAGE_2_ROADMAP.md`](STAGE_2_ROADMAP.md) |
| [`docs/BUSINESS_RULE_CAPTURE_STANDARD.md`](../BUSINESS_RULE_CAPTURE_STANDARD.md) |
| [`docs/cursor-audit/STAGE_2_REPORT.md`](STAGE_2_REPORT.md) (this file) |

---

## 3. Most serious UX risks

1. RE dual-mode cognitive load (Legacy still visible).  
2. `lease_add.php` length / fee vs cheque schedule confusion.  
3. Operational schedule mistaken for accounting recognition.  
4. Weak mobile nav on RE (largest module).  
5. Unbounded lists making navigation feel “broken” under load.

---

## 4. Most serious security risks

1. JWT hardcoded/placeholder fallbacks + CORS `*`.  
2. Unauthenticated HTTP cron.  
3. `.fixperms.php` if deployed.  
4. Upload extension-only + CSRF gap on document AJAX.  
5. Session-only login rate limit + legacy password acceptance.

---

## 5. Most serious permission risks

1. Per-page gate discipline (missed include = open endpoint).  
2. Company fallback `?: 1`.  
3. Approvals without true maker/checker (policy Needs business confirmation).  
4. Inconsistent accounts `require_role` vs module permissions.  
5. Reverse/refund/CN permission matrix incomplete / Needs business confirmation.

---

## 6. Most serious performance risks

1. `outstandings_report.php` dual unbounded queries.  
2. RE `leases.php` / units / tenants without pagination.  
3. Collections/penalty crons N+1.  
4. Dashboard multi-query fan-out; cache underused.  
5. Synchronous PDF/batch generation.

---

## 7. Design-system recommendations

- Anchor staff chrome on **confirmed** branding tokens (`#7a0000` / `#ffd86a`).  
- Treat vertical palettes as optional accents pending approval.  
- Shared shell + page header + filter/empty/loading patterns; keep Bootstrap.  
- No SPA rewrite.

---

## 8. Real Estate workflow findings

- Invoice Mode is the official accounting path; receipt allocation is the cash application hub.  
- Lease create mixes commercial terms, fee options, and **operational cheque schedules**—flexibility must be preserved until business rules are confirmed via the capture standard.  
- Multiple leases per tenant appear supported; cross-lease money rules Need business confirmation.  
- PDC clear in IM goes through receipts; legacy clear remains a Historical risk.  
- Layers (agreement → schedule → invoice/obligation → receipt → allocation → journal → reco → report) must stay distinct in UI copy.

---

## 9. Findings from other modules

| Module | Highlight |
|--------|-----------|
| Construction | Solid mobile drawer; bank reco parallel UX; contractor GL fail-open risk (from Stage 1.5) |
| Cleaning | Dense accounts/ops UIs; chrome not extracted; better pagination than RE lists |
| HR | Pills nav; worker Guard; payroll dual-stack posting |
| Inventory | Stock workflows; no GL bridge found |
| ARS/Stay/Tenant | Separate portal brands; Stay login weaker than tenant |
| Grocery/Barber | Sidebar-only POS chrome; strong department perms |

---

## 10. Items requiring business confirmation

- Fee payment treatments (included / separate cheque / separate invoice / other).  
- Allowed cheque schedule cardinalities and exceptions.  
- Cross-lease allocation/credit sharing.  
- Deposit timing and deduction approvals.  
- Bounce penalty policy.  
- Maker/checker and self-approval rules (no invented thresholds).  
- Whether petty cash module is required.  
- Portal/vertical brand unification vs intentional diversity.  
- RTL requirements by module.  
- Posted-document edit policies.

---

## 11. Top 20 Stage 2 recommendations

1. Lock down cron HTTP access.  
2. Remove `.fixperms.php` from prod.  
3. Fail-closed JWT secrets; restrict CORS.  
4. CSRF + MIME on uploads.  
5. Prod `APP_ENV` / error display check.  
6. Persistent login rate limiting; password hash migration end-state.  
7. Paginate RE lists.  
8. Fix/split outstandings performance.  
9. RE mobile drawer.  
10. Label Legacy Historical; IM-first UX copy.  
11. Company context fail-closed on financial writes.  
12. Full AJAX CSRF audit.  
13. Cache dashboards.  
14. Batch heavy crons.  
15. Stay portal login hardening.  
16. Financial UI status vocabulary on IM screens.  
17. Extract Cleaning layout package.  
18. Lease form progressive disclosure (keep flexibility).  
19. Accessibility pass on top pages.  
20. Start capturing confirmed rules with `BUSINESS_RULE_CAPTURE_STANDARD.md`.

---

## 12. Confirmation — no production logic changed

No PHP, JavaScript, SQL, migrations, configuration, database objects, workflows, or financial logic were modified. Documentation and design recommendations only. No packages installed. No Cursor Rules/Skills/subagents created.

---

## 13. Recommended scope for Stage 3 (Cursor Rules) — proposal only

When approved, Stage 3 should create **project rules/skills** that encode:

- Dual-ledger + Invoice Mode official / Legacy historical (no extension).  
- Company isolation fail-closed; no speculative business rules.  
- Financial UI + security checklists before money-path edits.  
- Impact analysis for shared engine / layouts.  
- Reference audit docs under `docs/cursor-audit/` and `docs/ERP_DECISIONS.md`.  
- Business rules only from Confirmed entries (capture standard).

**Stop here. Do not start Stage 3 without explicit approval.**
