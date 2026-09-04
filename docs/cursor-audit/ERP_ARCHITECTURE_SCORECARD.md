# ERP Architecture Scorecard

**Stage:** 1.5  
**Date:** 2026-07-10  
**Method:** Evidence from Stage 1 deep traces + Stage 1.5 quality review. Scores 1–10.

| Dimension | Score | One-line verdict |
|-----------|------:|------------------|
| Architecture | 5 | Clear modules + intentional dual ledger; coupling and legacy drag |
| Database | 5 | Rich schema; weak referential uniqueness for journals; artifact-based view |
| Accounting | 5 | Invoice Mode solid direction; control gaps and historical dual paths |
| Security | 5 | Auth/CSRF/RBAC present; secrets & per-page gate discipline weak |
| Performance | 5 | Adequate for current scale; report/page weight risks |
| UI consistency | 4 | Per-module Bootstrap shells; duplicated patterns |
| Modularity | 5 | Folders/modules exist; shared engine is a magnet |
| Technical debt | 4 | Manageable if legacy frozen; critical posting gaps remain |
| Testing | 2 | Almost no automated suite |
| Deployment | 5 | Shared-hosting workable; operational risk in sync tools |
| Commercial readiness | 4 | Domain-strong, product-weak |
| Future scalability | 5 | Can grow with discipline; not SaaS-ready |

**Weighted qualitative average (equal weights): ~4.5 / 10**

---

## Detailed explanations

### Architecture — 5
File-based PHP ERP with real module boundaries and a deliberate Cleaning vs Shared ledger split. Invoice Mode gives RE a coherent target architecture. Drag: legacy RE still in tree, Construction/ARS require RE engine files, three bank-reco clones.

### Database — 5
~450 tables, views for AR, migrations culture, company_id on key headers. Gaps: no triggers; no DB unique on shared journal references; child tables often omit company_id; dump/snapshot drift possible.

### Accounting — 5
Double-entry engines exist; Invoice Mode event chain (obligation → invoice → receipt allocation) is the right commercial model. Critical/High debt: Cleaning idempotency, broken refund/penalty signatures, legacy PDC without GL, CN weak references, possible SD double-post.

### Security — 5
Session auth, CSRF, roles, module access, Guard for workers. Weaknesses: hardcoded secret *types* in config paths, permissive CSP, per-page auth includes, JWT fallbacks (values not documented here).

### Performance — 5
Indexes and caches help; large PHP pages and combined reports will hurt as data grows. No evidence of need for rewrite or new DB engine.

### UI consistency — 4
Bootstrap 5.3.3 module layouts are a shared language, but each module owns chrome; root header/footer empty; financial UIs vary (accounts vs RE accounting vs CO).

### Modularity — 5
MODULE_* registry and directories are real. Shared accounting and inventory create hard edges. Tasks module unregistered.

### Technical debt — 4
Debt is concentrated and knowable (see registers). Freezing legacy RE improves trajectory without deletion.

### Testing — 2
Commercial ERP without posting regression tests is high risk.

### Deployment — 5
Composer + Apache rewrite + migrations fit shared hosting. Schema sync tools are powerful and must stay human-gated.

### Commercial readiness — 4
Sellable as vertical suite after control hardening and packaging; not ready as generic multi-tenant SaaS.

### Future scalability — 5
Vertical scale + archiving + service facades are enough for years; Kubernetes/microservices not indicated.

---

## Score interpretation for leadership

| Band | Meaning |
|------|---------|
| 1–3 | Blocker for commercial sale in that dimension |
| 4–6 | Operable internally; needs program of work |
| 7–8 | Competitive |
| 9–10 | Best-in-class |

**Blockers to address first:** Testing (2), Plugin/licensing productization (via commercial doc), Accounting critical ATDs, Developer onboarding via decisions + future Cursor rules.
