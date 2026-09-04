# HeroSysgro ERP Evolution Roadmap

**Official long-term roadmap.**  
**Sources:** Stages 1–4A.1, `ERP_DECISIONS.md`, confirmed architecture and technical debt.  
**Principle:** Incremental evolution. **Never rewrite the ERP.**

Effort: S / M / L / XL · Priority: P0–P3

---

## Hard gates

- **Phase 2 service extraction cannot begin until Phase 1B controls are accepted.**
- **No large redesigns before Critical Stability (Phases 0–1C) is sequenced.**
- **SaaS / multi-tenancy require a future Accepted ERP decision** (not implied by multi-company).
- **No rewrite** (Laravel/React/microservices/K8s) is recommended.

---

## Phase 0 — Engineering Platform Polish

| Item | Business value | Technical value | Dependencies | Risk | Effort | Priority | Expected outcome |
|------|----------------|-----------------|--------------|------|--------|----------|------------------|
| Stage 4A.1 rule trigger fixes | High | Reliable agent guidance | Stage 4A report | Low | S | P0 | Rules load and match files |
| Workflow coverage (WF-1…WF-7) | High | Consistent gates | Stage 3 | Low | S | P0 | Explicit Rules/Skills/Agents |
| `financial-report-validation` skill | High | Safe report changes | WF-1/3/7 | Low | S | P0 | Official reports validated |
| Agent readonly clarifications | Medium | No accidental implementation | Stage 4A | Low | S | P0 | Report-first agents |

**Status:** Completed in Stage 4A.1 (this polish).

---

## Phase 1A — Security, Operations and Infrastructure Stability

| Item | Business value | Technical value | Dependencies | Risk | Effort | Priority | Expected outcome |
|------|----------------|-----------------|--------------|------|--------|----------|------------------|
| Cron HTTP lockdown | Very High | Stop abuse | Web server | Low | S | P0 | Crons CLI-only |
| Remove `.fixperms.php` from prod | High | Stop chmod attack | Deploy hygiene | Low | S | P0 | Safer upload dirs |
| JWT env-only + CORS allowlist | Very High | API safety | Ops secrets | Low | S | P0 | Unforgeable tokens |
| Upload MIME + CSRF on document AJAX | High | Upload safety | — | Low | S | P0 | Safer documents |
| APP_ENV / error display on live | High | No info leaks | Ops | Low | S | P0 | Prod-safe config |
| Secret hygiene (env, no values in docs) | High | Reduce leak risk | Ops | Low | M | P0 | Secrets out of repo defaults |
| Login rate-limit persistence + hash migration end-state | High | Auth hardening | Ops | Medium | M | P0 | Stronger login |

---

## Phase 1B — Accounting Integrity and Company Isolation

| Item | Business value | Technical value | Dependencies | Risk | Effort | Priority | Expected outcome |
|------|----------------|-----------------|--------------|------|--------|----------|------------------|
| Fix refund/penalty `create_journal_entry` signatures | High | Restores broken paths | Tests | Low | S | P0 | Working refunds/penalties |
| Company fail-closed financial writes | Very High | Isolation | Wide touch | Medium | M | P0 | Fewer cross-company errors |
| Cleaning journal source idempotency | Very High | Stops duplicate GL | Data review | Medium | M | P0 | Trustworthy Cleaning books |
| Shared journal uniqueness design (after cleanup) | Very High | Race-safe posting | Data cleanup | Medium | M | P0 | No duplicate shared journals |
| Security-deposit posting confirmation / orchestration | High | Fix double-post risk | Live confirm | Medium | M | P0 | Correct deposits |
| Legacy freeze (Historical only; no new Legacy features) | High | Mode clarity | DEC-003/004 | Low | S | P0 | IM-first product |
| Financial-report validation on official report changes | High | Correct totals | Phase 0 skill | Low | M | P0 | Safe report evolution |

---

## Phase 1C — Low-risk Performance and Usability Stability

| Item | Business value | Technical value | Dependencies | Risk | Effort | Priority | Expected outcome |
|------|----------------|-----------------|--------------|------|--------|----------|------------------|
| Pagination for top RE lists | High | Usability/scale | WF-7 | Medium | M | P0 | Usable RE lists |
| Outstandings performance planning (split IM vs Historical) | High | Report scale | 1B Legacy freeze | Medium | M | P1 | Safer AR screens |
| Dashboard cache use (RE + Cleaning) | Medium | Faster home | CachingService | Low | M | P1 | Snappier UX |
| Cron batching (collections/penalties) | Medium | Ops stability | 1A cron lockdown | Medium | M | P1 | Predictable crons |

---

## Phase 2 — Architecture Improvements

**Gate:** Phase 1B accepted.

| Item | Business value | Technical value | Dependencies | Risk | Effort | Priority | Expected outcome |
|------|----------------|-----------------|--------------|------|--------|----------|------------------|
| JournalService adapters (wrap existing) | High | Single posting API | 1B idempotency | Medium | L | P1 | Safer future changes |
| AccountingPostingService orchestration | High | Event consistency | JournalService | Medium | L | P1 | Clearer posting |
| AllocationService (IM canonical; Legacy adapter frozen) | High | Cleaner cash app | Legacy freeze | Medium | L | P1 | Fewer alloc bugs |
| TenantCreditService | High | Credit/refund integrity | Signature fixes | Medium | M | P1 | Reliable credits |
| SecurityDepositService | High | Single orchestrator | SD confirmation | Medium | M | P1 | Correct deposits |
| VATService + report policy | High | Statutory clarity | Finance sign-off | Medium | M | P1 | Consistent VAT |
| ReconciliationService (RE+CO; Cleaning adapter) | Medium | Less duplication | 1A/1B | High | L | P2 | One reco brain |
| PeriodLockService fail-closed | Medium | Close integrity | — | Low | S | P1 | Safer closes |

---

## Phase 3 — ERP Productization

| Item | Business value | Technical value | Dependencies | Risk | Effort | Priority | Expected outcome |
|------|----------------|-----------------|--------------|------|--------|----------|------------------|
| Shared staff layout package | Medium | UI consistency | Design system | Medium | L | P2 | Cohesive chrome |
| Permission model alignment | High | Clearer ACL | Business rules | Medium | L | P2 | Predictable access |
| Internal accounting API | Medium | Integration ready | Phase 2 services | Medium | L | P2 | Stable contracts |
| License/entitlement on module_access | High | SKU gating | Product | Medium | L | P2 | Sellable editions |
| PHPUnit pilot (IM receipt + journals) | High | Regression safety | Composer | Medium | L | P1 | Automated guards |
| Plugin hooks around posting | Medium | Extensions | Phase 2 | High | XL | P3 | Customization without forks |

---

## Phase 4 — Commercial ERP

| Item | Business value | Technical value | Dependencies | Risk | Effort | Priority | Expected outcome |
|------|----------------|-----------------|--------------|------|--------|----------|------------------|
| Multi-company hardening program | Very High | Isolation maturity | Phase 1B | Medium | L | P1 | Enterprise-ready companies |
| White-label branding | High | Themes | Brand approval | Medium | M | P2 | Partner branding |
| Marketplace readiness | Medium | Ecosystem | License + plugins | High | XL | P3 | Third-party modules |
| Multi-tenant SaaS readiness | High (new line) | Beyond company | **New Accepted DEC required** | Very High | XL | P3 | Optional product line |

---

## Phase 5 — Intelligent ERP

| Item | Business value | Technical value | Dependencies | Risk | Effort | Priority | Expected outcome |
|------|----------------|-----------------|--------------|------|--------|----------|------------------|
| Readonly MySQL MCP (staging) | Medium | Faster audits | MCP_READINESS | Medium | M | P2 | Evidence-backed DB answers |
| AI assistants using Cursor brain | High | Faster delivery | Phase 0 platform | Medium | M | P2 | Consistent AI changes |
| Workflow automation (extend carefully) | High | Less manual ops | Confirmed business rules | Medium | L | P2 | Reliable automations |
| Advanced / predictive reporting | Medium | Insights | Clean data | High | XL | P3 | Management foresight |
| Custom HeroSysgro MCP (readonly allowlist) | Medium | Safe agent tools | New DEC | High | L | P3 | Controlled agent ops |

---

## Explicit non-goals

- Full rewrite  
- Merging Cleaning `gl_*` and Shared `re_*` without a formal multi-year program  
- Deleting Legacy RE code in Phase 1  
- Inventing commercial fee/cheque universals without Confirmed business rules
