# Phase 2A Review Summary — Business Confirmation & Financial Architecture

**Date:** 2026-07-17  
**Phase status:** Design complete — **STOP. Awaiting human review. Do not start Phase 2B.**  
**Prior phases:** 0 ✔ · 1 ✔ · 1B ✔ Approved/Complete  

---

## 1. Roadmap terminology (updated)

| Old name | New name | Status |
|----------|----------|--------|
| Phase 0 | Architecture Review & Audit | ✔ |
| Phase 1 | Operational Foundations | ✔ |
| Phase 1B | Booking Activity Center Foundation | ✔ Approved |
| Phase 2 Gate | **Phase 2A – Business Confirmation & Financial Architecture** | ⬜ This phase |
| Phase 2 | **Phase 2B – Financial Adapter & Accounting Implementation** | ⬜ Blocked |
| Phase 3 | Premium UX & Workflow Modernization | ⬜ |
| Phase 4 | Advanced Holiday Homes Platform | ⬜ |

Artifacts updated: master plan, `ARS_MODERNIZATION_AUDIT_AND_ROADMAP.md`, Phase 1B completion report, confirmation sheet pointer.

---

## 2. Deliverables produced (Phase 2A)

| Document | Path |
|----------|------|
| Business confirmation sheet | [`PHASE2_BUSINESS_CONFIRMATION_SHEET.md`](PHASE2_BUSINESS_CONFIRMATION_SHEET.md) |
| Option B document design | [`PHASE2_FINANCIAL_DOCUMENT_DESIGN.md`](PHASE2_FINANCIAL_DOCUMENT_DESIGN.md) |
| Financial Adapter spec | [`PHASE2_FINANCIAL_ADAPTER_DESIGN.md`](PHASE2_FINANCIAL_ADAPTER_DESIGN.md) |
| Accounting event catalog | [`PHASE2_ACCOUNTING_EVENT_CATALOG.md`](PHASE2_ACCOUNTING_EVENT_CATALOG.md) |
| Bank reco & reporting | [`PHASE2_BANK_RECO_REPORTING_BLUEPRINT.md`](PHASE2_BANK_RECO_REPORTING_BLUEPRINT.md) |
| Legacy transition | [`PHASE2_LEGACY_TRANSITION_PLAN.md`](PHASE2_LEGACY_TRANSITION_PLAN.md) |
| Phase 2B blueprint | [`PHASE2_IMPLEMENTATION_BLUEPRINT.md`](PHASE2_IMPLEMENTATION_BLUEPRINT.md) |
| This summary | `PHASE2A_REVIEW_SUMMARY.md` |

**Not done (by design):** adapter code, accounting migrations executed, posting changes, live deploy, engine/RE IM changes.

---

## 3. Confirmed decisions (do not reopen lightly)

| Decision | Source |
|----------|--------|
| Option B — ARS financial docs + adapter; not `re_invoices` | DEC-016 Accepted |
| Protect `accounting_engine.php`; no breaking engine changes | DEC-014 / AD-001 |
| Shared RE financial environment; ARS ops independent | DEC-015 |
| Never rewrite financial history; amendments create new docs | AD-005/006 |
| Activity Center mandatory | AD-009 |
| Dual operational + financial lifecycles | AD-003 |
| Phase 1 lock + Phase 1B Activity Center foundations | Approved |

---

## 4. Remaining business approvals (block advanced 2B events)

From confirmation sheet **BC-01…BC-15** (all _Pending_ except Option B):

1. Posting `company_id`  
2. Revenue recognition / deferred 2400  
3. VAT presentation mandates  
4. Tourism/municipality fees  
5. Cancellation / no-show / early checkout commercial rules  
6. Extension pricing  
7. Deposit forfeit / damage offset  
8. Stripe settlement + clearing + fees  
9. Guest party exclusivity (`ars_guests`)  
10. Receivable ageing dimension  

**MVP Phase 2B mirror** of today’s confirm/payment/deposit can proceed after Phase 2A approval **if** BC-01 is decided or interim “keep ARS company” is explicitly accepted.

---

## 5. Final architecture (Option B)

```text
Booking (master)
 ├── Original Invoice
 ├── Extension Invoice
├── Service Invoice (incl. damage lines)
├── Adjustment Invoice
├── Credit Note
 ├── Payments (existing table) + Payment Allocations
├── Receipt (presentation of payment; not separate RE receipt engine)
├── Refunds
├── Security Deposit documents
 └── Journal Entries (re_journal_headers via adapter)
```

Physical tables: unified `ars_financial_documents` + lines; satellites for extension/CN/adjustment meta, allocations, refunds, security deposits. See document design.

Parent/child: `booking_id` on all; `parent_document_id` for CN/adjustment; Activity Center `related_entity_type=ars_financial_document`.

Idempotency: unique `(company_id, idempotency_key)` on mutating docs.

---

## 6. Proposed migrations (Phase 2B — not run)

M1 documents+lines → M2 satellites → M3 payment links → M4 feature flag → M5 optional backfill.  
Rollback: flag off; never delete posted journals.

---

## 7. Financial Adapter design (summary)

- New module helper (proposed `ars_financial_adapter.php`)  
- Methods for invoice, extension, service, CN, adjustment, payment, allocate, deposit*, refund, reverse, amendment preview  
- Transactions: document + journal atomic  
- Activity logging fail-safe  
- Feature flag cutover from `ars_accounting.php`  

---

## 8. Risks

| Risk | Mitigation |
|------|------------|
| Posting company mismatch vs bank reco | BC-01 before enable |
| Stripe without GL | Keep ops-only until BC-12 |
| Double-post during cutover | Idempotency + single call path |
| Backfill inventing history | Conservative classification only |
| Accidental engine change | Explicit ban + hash check in tests |
| Mobile break | Additive API only |

---

## 9. Regression protections

- Engine SHA check  
- RE `re_invoices` / leases / tenants untouched  
- Phase 1 lock + 1B Activity Center regression  
- Customer API key snapshot  
- Company-scoped queries fail closed  

---

## 10. Mobile compatibility

- No renames/removals of `booking`, `payments`, `security_deposit` keys  
- Optional future `financial_documents` array only with product approval  
- Staff CSRF unchanged for guest JWT APIs  

---

## 11. Real Estate protections

- No `re_invoices` for ARS  
- No fake leases/tenants  
- No Invoice Mode behaviour changes for leases  
- No Cleaning ledger merge  
- Bank reco consumes standard journals only  

---

## 12. Recommended Phase 2B execution sequence

1. Human approve Phase 2A  
2. Decide BC-01 (or interim)  
3. Local M1–M4 + adapter mirror path  
4. Test checklists in implementation blueprint  
5. Optional backfill  
6. Separate approval for live migration/deploy  
7. Only then implement BC-dependent events (fees, Stripe clearing, etc.)

---

## 13. Stop condition

**Phase 2A is complete as a design package.**

**Do not begin Phase 2B** (no Financial Adapter implementation, no accounting migrations, no posting changes, no production deployment) until this review is explicitly approved by a human.
