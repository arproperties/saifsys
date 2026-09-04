# ARS Financial Core v1.0 — Freeze Register

**Version:** ARS Financial Core **v1.0**  
**Freeze date:** 2026-07-17  
**Phase:** 2E  
**Status:** FROZEN  

Classification legend:

| Code | Meaning |
|------|---------|
| **A** | FROZEN — NO MODIFICATION |
| **B** | PROTECTED INTERFACE — MAY BE CALLED BUT NOT CHANGED |
| **C** | PRESENTATION SAFE — display/formatting only |
| **D** | UI ADAPTER ALLOWED — presentation wrapper only; no behaviour change |

Phase 3 may **read** all rows. Phase 3 may **call** Class B/D. Phase 3 may **modify** only Class C (presentation) or add Class D wrappers.

---

## Core components

| Component | Purpose | Path / entry | Tables | Account roles | Flags | Class | Phase3 read | Phase3 call | Phase3 modify | Tests |
|-----------|---------|--------------|--------|---------------|-------|-------|-------------|-------------|----------------|-------|
| Shared accounting engine | Post/reverse journals | `modules/realestate/accounting/accounting_engine.php` — `create_and_post_journal`, `reverse_journal`, `find_account_by_code` | `re_journal_*`, `re_chart_of_accounts` | via codes | — | **A** | Y | via adapter only | **N** | 2C/2D REG-01 |
| Financial Adapter (core) | Sole ARS→engine path | `ars_financial_adapter.php` — `ars_adapter_*` | docs, allocs, links | all mapped | `financial_adapter_enabled` | **B** | Y | **Y** | **N** | 2B/2D |
| Financial Adapter (2D workflows) | Extension/CN/credit/Stripe/etc. | `ars_financial_adapter_phase2d.php` | credits, settlements, deposits | extended roles | same | **B** | Y | **Y** | **N** | 2D UAT |
| Legacy bridge | Flag-off posting | `ars_accounting.php` — `ars_post_*` | booking/payment journal_id | hard-coded codes (legacy) | flag off path | **B** | Y | **Y** | **N** | 2B REG-05 |
| Account role mapping | Role→COA | `ars_account_roles.php` | `ars_account_role_map` | all | — | **A** | Y | Y (resolve) | **N** | 2B/2D |
| Document state machine | Lifecycle | `ars_financial_document_sm.php` | transitions | — | — | **A** | Y | Y | **N** | SM-01/02 |
| Financial documents engine | Headers/lines | adapter insert/post | `ars_financial_documents`, `_lines`, `_transitions` | line `account_role` | — | **A** | Y | via adapter | **N** | 2B/2D |
| Payment allocation | FIFO open-item | `ars_adapter_allocate_payment` | `ars_payment_allocations` | — | — | **A** | Y | via adapter | **N** | 2B/2D |
| Guest credit | Overpay/CN credit | phase2d credit APIs | `ars_guest_credits`, `_applications` | `GUEST_CREDIT` | policy | **A** | Y | via adapter | **N** | WF-CREDIT-* |
| Refunds | Stay/credit refunds | `ars_adapter_create_refund*` | `ars_refunds` | AR/Cash/Credit | — | **A** | Y | via adapter | **N** | WF-REF |
| Credit notes / adjustments | Amendments | `ars_adapter_create_credit_note*`, `*_adjustment_*` | docs + `ars_credit_notes`, `ars_adjustments` | ROOM/VAT/etc. | — | **A** | Y | via adapter | **N** | WF-CN/ADJ |
| Extensions | Added nights | `ars_adapter_create_extension_invoice*` | docs + `ars_extension_documents` | ROOM_REVENUE | — | **A** | Y | via adapter | **N** | WF-EXT |
| Early checkout / shorten | Unused nights CN | `ars_adapter_shorten_booking*` | CN + credits | ROOM | policy | **A** | Y | via adapter | **N** | WF-EARLY |
| Additional services | Service invoice | `ars_adapter_create_service_invoice*` | docs + catalog | ADDITIONAL_SERVICE_REVENUE | — | **A** | Y | via adapter | **N** | WF-SVC |
| Damage charges | Damage invoice | same (line_type damage) | docs | DAMAGE_REVENUE | — | **A** | Y | via adapter | **N** | WF-DMG |
| Security deposits | Receive/refund | `ars_adapter_receive/refund_deposit` | `ars_security_deposits` | SECURITY_DEPOSIT | — | **A** | Y | via adapter | **N** | WF-B |
| Deposit apply / forfeit | Deduct/forfeit | `ars_adapter_apply_deposit_to_ar`, `forfeit_*` | deposits | FORFEIT/AR | — | **A** | Y | via adapter | **N** | WF-FORF |
| Cancellation accounting | Reverse/fee | `ars_adapter_cancel_financials*` | reverse + optional adj | LATE_FEE | policy % | **A** | Y | via adapter | **N** | WF-CANCEL |
| No-show accounting | Keep or reverse | `ars_adapter_no_show*` | booking status / reverse | — | policy mode | **A** | Y | via adapter | **N** | WF-NOSHOW |
| Stripe clearing/fee/settle | Sim accounting | `stripe_card_payment`, `stripe_settlement*` | `ars_stripe_settlements*` | CLEARING/FEE/BANK | fee % | **A** | Y | via adapter | **N** | WF-STRIPE |
| Deferred revenue | Unused (BC-02/03 A) | role only | COA 2400 | DEFERRED_REVENUE | — | **A** | Y | N/A post | **N** | design |
| Revenue recognition | On confirm | original invoice | docs/journals | ROOM/VAT/AR | — | **A** | Y | via adapter | **N** | EVT-01 |
| VAT treatment | Booking vat_mode | adapter VAT split | doc vat fields | VAT_OUTPUT | — | **A** | Y | via adapter | **N** | 2D |
| Financial locks | Money lock | `ars_financial_lock.php` | booking lock cols | — | — | **A** | Y | Y | **N** | Phase1 |
| Idempotency | Unique keys | unique indexes + adapter | idempotency_key cols | — | — | **A** | Y | via contracts | **N** | EDGE-01 |
| Policy settings | Configurable fees | `ars_financial_policy` + `ars_financial_policy()` | policy table | — | percents/modes | **A** | Y | read | **N*** | 2D |
| Reporting helpers | AR/docs/deposits | `ars_financial_reports.php` | read queries | — | — | **B** | Y | **Y** | **N**† | RPT-* |
| Financial reports UI | Staff screens | `financial_reports.php`, `financial_document_view.php` | reads | — | — | **C** / **D** | Y | — | **C** only | — |
| Activity financial events | Timeline | `ars_activity.php` + adapter logs | `ars_booking_activities` | — | — | **B** log / **C** UI | Y | log via adapter | UI **C** | ACT-* |
| Company isolation | Fail-closed | adapter assert + queries | company_id | — | — | **A** | Y | enforced | **N** | SEC-ISO |
| Feature flag | Adapter on/off | `ars_company_settings.financial_adapter_enabled` | settings | — | default 0 | **A** | Y | read | **N**‡ | FLAG-* |
| Version marker | Baseline id | `ars_financial_core_version.php` | — | — | — | **A** | Y | — | **N** | 2E |
| Rule register (docs) | Business rules | `PHASE2D_FINAL_BUSINESS_RULE_REGISTER.md` | — | — | — | **A** | Y | — | **N** | — |
| RE Invoice Mode | Out of ARS scope | `re_invoices` stack | RE tables | — | — | **A** (do not use for ARS) | Y | **N** for ARS | **N** | REG-RE |
| Mobile/customer APIs | Contracts | `api/customer/v1/*`, `stay/*` | shared | — | — | **A** contracts | Y | existing | **N** break | REG-API |

\* Policy **value** changes (fee %) need business approval; schema/code frozen.  
† Report **queries** frozen; Phase 3 may add presentation views calling helpers.  
‡ Flag may be toggled for localhost UAT only; permanent enablement needs approval.

---

## Dependencies

- Shared engine (DEC-008/014)  
- ARS company COA (1310/4100/2310/1110/1210/2200 + 1130/2210/5510/4200/4300/4900)  
- Phase 1 lock + Phase 1B activity  
- Option B tables (Phase 2B) + Phase 2D satellites  

## Approved status

**Approved** as ARS Financial Core v1.0 after Phases 2A–2D and Phase 2E baseline verification (24 PASS / 0 FAIL / 0 BLOCKED).
