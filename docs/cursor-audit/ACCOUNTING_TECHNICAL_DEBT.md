# Accounting Technical Debt

**Stage:** 1.5  
**Date:** 2026-07-10  
**Policy:** Invoice Mode = official RE accounting. Legacy = historical only (retain for compatibility; do not extend; do not delete in this audit).

Severity: **Critical** | **High** | **Medium** | **Low**

---

## Critical

### ATD-A01 — Cleaning journal create has no source-level duplicate refuse
| Field | Detail |
|-------|--------|
| Location | `includes/gl_posting.php` → `gl_create_journal`, `gl_post_invoice`, `gl_post_receipt` |
| Business impact | Duplicate customer invoices/receipts in books; trust loss |
| Accounting impact | Inflated revenue/cash; broken trial balance vs subledger |
| Risk | Re-post / double-click / repair scripts needed historically |
| Recommendation | Add transactional refuse-if-exists on `(company_id, source, source_id)` before insert; prefer DB unique where safe |
| Future architecture | `JournalService` (Cleaning adapter) with idempotency keys |

### ATD-A02 — Shared journal reference uniqueness is application-only
| Field | Detail |
|-------|--------|
| Location | `journal_duplicate_exists` in `accounting_engine.php`; commented `uq_company_reference` in migrations |
| Business impact | Race conditions under concurrent posting |
| Accounting impact | Duplicate posted journals for same source document |
| Risk | High under multi-user / API concurrency |
| Recommendation | Enable DB unique for non-skipped reference types after data cleanup; keep skip list for recurring/credit_note redesigned with proper keys |
| Future architecture | `JournalService` + DB constraint + idempotency |

### ATD-A03 — Broken refund / penalty journal API calls
| Field | Detail |
|-------|--------|
| Location | `post_refund_to_accounting`, `post_penalty_payment_to_accounting` in `accounting_integration.php` — call `create_journal_entry($companyId, [array])` vs real signature |
| Business impact | Tenant refunds / penalty collections may fail or mis-post |
| Accounting impact | Liability 2410 / income not correctly cleared |
| Risk | Silent accounting gaps if UI still invokes |
| Recommendation | Rewrite callers to `create_and_post_journal`; add regression tests |
| Future architecture | `TenantCreditService.refund`, `PenaltyCollectionService` |

---

## High

### ATD-A04 — Legacy PDC clear without GL posting
| Field | Detail |
|-------|--------|
| Location | `billing_cheque_view.php` legacy clear path |
| Business impact | Cash appears collected in subledger without bank/AR journal |
| Accounting impact | Subledger vs GL mismatch |
| Risk | Historical leases still on legacy path |
| Recommendation | For remaining legacy leases: block clear without post, or force Invoice Mode receipt path; do not build new legacy features |
| Future architecture | PDC events always emit through Invoice Mode `ReceiptAllocated` |

### ATD-A05 — Credit notes weak source link + skipped duplicate check
| Field | Detail |
|-------|--------|
| Location | `post_credit_note_to_accounting` — `reference_id=0`, engine skip for `credit_note` |
| Business impact | Hard to audit which CN created which journal |
| Accounting impact | Multiple CNs indistinguishable at journal reference layer |
| Risk | Over-crediting AR without clear trail |
| Recommendation | Use real credit-note document id as `reference_id`; store CN header table if missing; enable duplicate rules per CN id |
| Future architecture | `CreditNoteService` → `JournalService` |

### ATD-A06 — Invoice Mode security deposit vs receipt posting interaction
| Field | Detail |
|-------|--------|
| Location | `re_sd_post_liability_for_receipt_allocation` + `post_invoice_mode_receipt_to_accounting` |
| Business impact | Possible double bank debit / wrong AR for deposits |
| Accounting impact | BS cash and liabilities misstated |
| Risk | Strong inference from dual Bank posts — needs live confirmation |
| Recommendation | Single orchestrator: SD obligation allocations post only 2200 path, not AR 1310 |
| Future architecture | `SecurityDepositService` owns SD journal lines exclusively |

### ATD-A07 — Three parallel bank reconciliation implementations
| Field | Detail |
|-------|--------|
| Location | Cleaning / RE / Construction reco stacks |
| Business impact | Triple maintenance; inconsistent staff UX |
| Accounting impact | Divergent match/create/undo behaviour |
| Risk | Bug fixed in one stack remains in others |
| Recommendation | Shared ReconciliationService; Cleaning adapter for `gl_*`, shared adapter for `re_general_ledger` |
| Future architecture | One reco engine, two ledger adapters |

### ATD-A08 — `company_id` not re-validated on engine reverse/post-by-id
| Field | Detail |
|-------|--------|
| Location | `post_journal`, `reverse_journal` |
| Business impact | Cross-company reverse if caller bypasses UI filters |
| Accounting impact | Wrong company books altered |
| Risk | Medium–high for custom scripts/API |
| Recommendation | Require `companyId` argument on reverse/post; match header |
| Future architecture | `JournalService.reverse(companyId, journalId)` |

### ATD-A09 — Dual RE accounting modes increase cognitive load
| Field | Detail |
|-------|--------|
| Location | `accounting_mode` legacy vs invoice throughout RE |
| Business impact | Training errors; wrong screen used |
| Accounting impact | Mixed AR definitions (installment vs invoice) |
| Risk | High during transition; declining if legacy frozen |
| Recommendation | Official docs + UI: Invoice Mode only for new work; legacy read-only badge; no new legacy features |
| Future architecture | Deprecation program (not deletion now) |

---

## Medium

### ATD-A10 — VAT report methodology inconsistency
| Field | Detail |
|-------|--------|
| Location | RE GL 2310/2320; CO source docs 2130/2310; Cleaning document VAT |
| Business impact | Statutory filing confusion |
| Accounting impact | Input VAT under/over statement |
| Recommendation | Company VAT policy + `VATService` + one report builder per stack |
| Future architecture | Configurable VAT accounts per company |

### ATD-A11 — Contractor payment can save without successful GL
| Field | Detail |
|-------|--------|
| Location | Construction contractor payment flow |
| Business impact | Project cost recorded without books |
| Accounting impact | Incomplete CIP/expense |
| Recommendation | Single transaction: fail closed or compensating reverse |
| Future architecture | `AccountingPostingService` transactional outbox |

### ATD-A12 — SM recurring list not company-scoped
| Field | Detail |
|-------|--------|
| Location | `accounts/recurring_journals.php` list query |
| Business impact | Cross-company template visibility |
| Accounting impact | Wrong template run risk |
| Recommendation | Filter by `cleaning_accounting_company_id` |
| Future architecture | Period/recurring under company scope always |

### ATD-A13 — Session company fallback to `1`
| Field | Detail |
|-------|--------|
| Location | Widespread `current_company_id($conn) ?: 1` |
| Business impact | Silent wrong-company writes |
| Accounting impact | Cross-company contamination |
| Recommendation | Fail closed if company missing |
| Future architecture | Mandatory company context object |

### ATD-A14 — Legacy deferred revenue path still active for historical leases
| Field | Detail |
|-------|--------|
| Location | `post_deferred_payment_to_accounting`, `deferred_revenue_mode` |
| Business impact | Two recognition models |
| Accounting impact | 2410 vs invoice recognition diverge |
| Recommendation | Freeze for legacy only; Invoice Mode uses obligation→invoice |
| Future architecture | `DeferredRevenueService` only for historical runoff OR map to Invoice Mode |

### ATD-A15 — Parallel cheque tables
| Field | Detail |
|-------|--------|
| Location | `re_lease_cheques` + `re_post_dated_cheques` |
| Business impact | Staff confusion |
| Accounting impact | Coverage/clearance inconsistency |
| Recommendation | Designate one operational store for Invoice Mode; legacy table read-only |
| Future architecture | Single PDC aggregate |

---

## Low

### ATD-A16 — Petty cash is COA-only
| Field | Detail |
|-------|--------|
| Location | Seed accounts; no module |
| Business impact | Informal cash handling |
| Accounting impact | Weak float control |
| Recommendation | Optional PettyCashService later if product needs it |
| Future architecture | Optional commercial module |

### ATD-A17 — Invoice Mode receipt void path not found
| Field | Detail |
|-------|--------|
| Location | Receipt allocation confirm path |
| Business impact | Corrections via manual reverse only |
| Accounting impact | Incomplete event matrix |
| Recommendation | Formal `ReceiptVoided` / `ReceiptReversed` event |
| Future architecture | AllocationService.reverse |

### ATD-A18 — Outstandings report dual model forever
| Field | Detail |
|-------|--------|
| Location | `outstandings_report.php` |
| Business impact | Combined totals mix definitions |
| Recommendation | Default Invoice Mode; legacy section labeled Historical |
| Future architecture | ReportingService with mode dimension |

---

## Summary counts

| Severity | Count |
|----------|------:|
| Critical | 3 |
| High | 6 |
| Medium | 6 |
| Low | 3 |

**No code was changed in producing this register.**
