# Stage 1.5 Report — Architecture Quality Review

**Date:** 2026-07-10  
**Type:** Read-only architecture evaluation (commercial ERP lens)  
**Production logic changed:** **No**

## Business policy applied

- **Invoice Mode** = official Real Estate accounting architecture.  
- **Legacy lease accounting** = historical only: identify, do not extend, do not remove in this audit.  
- Cleaning ledger remains separate; shared `re_*` engine serves RE / Construction / ARS; HR routes by `business_type`; company isolation mandatory.

---

## Files created (Stage 1.5)

| File |
|------|
| [`docs/cursor-audit/ACCOUNTING_QUALITY_REVIEW.md`](ACCOUNTING_QUALITY_REVIEW.md) |
| [`docs/cursor-audit/ACCOUNTING_TECHNICAL_DEBT.md`](ACCOUNTING_TECHNICAL_DEBT.md) |
| [`docs/cursor-audit/ACCOUNTING_SERVICE_ARCHITECTURE.md`](ACCOUNTING_SERVICE_ARCHITECTURE.md) |
| [`docs/cursor-audit/ACCOUNTING_EVENT_CATALOG.md`](ACCOUNTING_EVENT_CATALOG.md) |
| [`docs/ERP_DECISIONS.md`](../ERP_DECISIONS.md) |
| [`docs/cursor-audit/COMMERCIAL_ERP_READINESS.md`](COMMERCIAL_ERP_READINESS.md) |
| [`docs/cursor-audit/ERP_ARCHITECTURE_SCORECARD.md`](ERP_ARCHITECTURE_SCORECARD.md) |
| [`docs/cursor-audit/TECHNICAL_DEBT_REGISTER.md`](TECHNICAL_DEBT_REGISTER.md) |
| [`docs/cursor-audit/STAGE_1_5_REPORT.md`](STAGE_1_5_REPORT.md) (this file) |

**Not created:** Cursor rules, skills, agents, Stage 2 UI/security docs.

---

## Architecture quality verdict

HeroSysgro is a **serious multi-company vertical ERP** with real accounting engines—not a prototype. It is **not yet commercial-product-grade** in controls, testing, packaging, or onboarding. The highest-leverage path is **incremental**: freeze legacy RE, harden posting idempotency, introduce service facades over existing functions, converge bank reco—**not a rewrite**.

Scorecard average ≈ **4.5/10**. Accounting integrity ≈ **5/10** with **critical** control gaps.

---

## Top 20 improvements (highest long-term value)

### Quick wins (S / short M)

1. **Fix** `post_refund_to_accounting` / `post_penalty_payment_to_accounting` signatures (use `create_and_post_journal`).  
2. **Fail closed** when `current_company_id` missing (remove `?: 1` on financial writes).  
3. **Company-filter** SM recurring journal list.  
4. **Require `companyId`** on `reverse_journal` / `post_journal` API.  
5. **UI/docs policy:** badge + block new legacy features; Invoice Mode only for new RE work.  
6. **Label** outstandings report sections Historical Legacy vs Invoice Mode AR.  
7. **Credit notes:** stop using `reference_id=0`; link real CN id.  
8. **Gate** legacy PDC clear behind GL post or force IM receipt path.  
9. **Record** all Accepted decisions in `docs/ERP_DECISIONS.md` for every future shared change (process win).  
10. **Secret hygiene plan:** move DB/SMTP/JWT to env (ops)—document paths only until executed.

### Medium-term improvements

11. **Idempotent Cleaning journals** (`source`+`source_id`+company refuse + unique where safe).  
12. **DB unique** on shared journal references after cleanup (non-skip types).  
13. **SecurityDepositService orchestration** so IM receipt does not double-post Bank for SD.  
14. **VATService** + single statutory report policy per company/stack.  
15. **Facade AccountingPostingService / JournalService** wrapping existing functions (no behaviour change first).  
16. **PHPUnit pilot** for IM receipt confirm + journal duplicate/reverse.  
17. **Freeze legacy RE** code paths (read-only for historical leases; no enhancements).  
18. **Construction payment fail-closed** when GL post fails.

### Strategic improvements

19. **ReconciliationService** converging RE + Construction reco (Cleaning adapter separate).  
20. **Commercial packaging:** entitlements/SKUs on `module_access`, onboarding docs, Cursor rules/skills (later stage), optional inventory→GL and SaaS multi-tenant only as separate product programs.

---

## What not to do

- Do not rewrite in Laravel/React/microservices.  
- Do not merge Cleaning `gl_*` into `re_*` without a multi-year program.  
- Do not delete legacy RE code in the next sprint.  
- Do not run schema-sync/repair/posting scripts without explicit approval.

---

## Stop

Stage 1.5 ends here. **Await approval before Stage 2** (UI/UX + Security audits) or any Cursor rules/skills work.
