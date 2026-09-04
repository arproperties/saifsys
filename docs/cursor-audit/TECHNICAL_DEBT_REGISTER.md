# Technical Debt Register

**Stage:** 1.5  
**Date:** 2026-07-10  
**Rule:** Incremental improvement only. **Do not rewrite the ERP.**

Priority: P0 Critical | P1 High | P2 Medium | P3 Low  
Effort: S days | M 1–3 weeks | L multi-sprint | XL program

| ID | Priority | Area | Description | Risk | Recommendation | Effort | Dependencies |
|----|----------|------|-------------|------|----------------|--------|--------------|
| TD-001 | P0 | Accounting / Cleaning | `gl_create_journal` lacks source duplicate refuse | Duplicate GL | Idempotent create + optional unique | M | Data cleanup |
| TD-002 | P0 | Accounting / Shared | No DB unique on journal references | Race duplicates | Cleanup then unique (non-skip types) | M | ATD-A02 |
| TD-003 | P0 | Accounting / RE | Refund/penalty `create_journal_entry` wrong signature | Failed/mis posts | Call `create_and_post_journal` | S | Tests |
| TD-004 | P1 | Accounting / RE | Legacy PDC clear without GL | Subledger≠GL | Block or route via IM receipt | M | Legacy freeze policy |
| TD-005 | P1 | Accounting / RE | Credit note `reference_id=0` + dup skip | Weak audit | Real CN document ids | M | CN table/UI |
| TD-006 | P1 | Accounting / RE | IM SD + receipt possible double Bank | BS misstatement | Single SD orchestrator | M | Live confirm |
| TD-007 | P1 | Accounting | Triple bank reco stacks | Maintenance drift | Shared ReconciliationService | L | DEC-010 |
| TD-008 | P1 | Isolation | `reverse_journal`/`post_journal` by id only | Cross-company | Require companyId match | S | Engine API |
| TD-009 | P1 | Product | Legacy RE still extendable in UI | Mode confusion | Freeze legacy; IM-only features | M | DEC-003/004 |
| TD-010 | P2 | VAT | 2130 vs 2320 / report methods differ | Filing error | VATService + policy | M | Finance sign-off |
| TD-011 | P2 | Construction | Contractor pay may save without GL | Incomplete books | Fail closed / txn | M | CO payments |
| TD-012 | P2 | Cleaning | SM recurring list not company-filtered | Cross-company UI | Add company filter | S | — |
| TD-013 | P2 | Isolation | `current_company_id() ?: 1` | Wrong books | Fail closed | M | Wide touch |
| TD-014 | P2 | RE data | Dual cheque tables | Confusion | One operational store | L | Migration plan |
| TD-015 | P2 | Reporting | Dual AR views / combined outstandings | Wrong AR | Canonical IM reports | M | — |
| TD-016 | P2 | Architecture | `accounting_integration.php` size/complexity | Change risk | Split by event/service facade | L | Service design |
| TD-017 | P2 | Security | Secrets in PHP config files (types only) | Leakage | Env-only secrets | M | Ops |
| TD-018 | P2 | Security | Per-page auth include discipline | Direct URL gaps | Checklist + future static checks | M | — |
| TD-019 | P3 | Testing | No PHPUnit project suite | Regressions | Pilot on IM receipt + journal | L | Composer already |
| TD-020 | P3 | UI | Per-module layout duplication | Inconsistent UX | Shared partials gradually | L | Design system |
| TD-021 | P3 | Modules | `tasks` outside MODULE_* | Access clarity | Register or document wrapper | S | — |
| TD-022 | P3 | Inventory | No inventory→GL bridge | Valuation gap | Optional later project | XL | Business need |
| TD-023 | P3 | Product | No plugin/license SKU layer | Hard to commercialize | Entitlement on module_access | L | Commercial plan |
| TD-024 | P3 | Docs | Scattered markdown vs Invoice Mode truth | Onboarding errors | Mark legacy docs Historical | M | DEC-003 |
| TD-025 | P3 | Ops | Schema sync tools easy to misuse | Live structure change | Human gate only; Cursor rules later | S | DEC-012 |

---

## Debt themes (rollup)

1. **Posting controls** (TD-001–006, 008) — highest financial value.  
2. **Mode/product clarity** (TD-009, 014, 015, 024) — freeze legacy.  
3. **Convergence** (TD-007, 016, 020) — services, not rewrite.  
4. **Platform** (TD-017–019, 023, 025) — commercial hygiene.

---

## Explicit non-changes

Register only. No remediation implemented in Stage 1.5.
