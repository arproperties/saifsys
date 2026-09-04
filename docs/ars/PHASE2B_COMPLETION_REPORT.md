# Phase 2B — Completion Report

**Status:** Implementation complete on **localhost** — awaiting human review  
**Date:** 2026-07-17  
**Environment:** Local XAMPP / `datanew` only  
**Production deploy:** **Not done** — blocked until Phase 2B approval  

---

## Verdict

Phase 2B delivered the Option B Financial Adapter stack behind a feature flag (default **off**), with account role mapping, a formal document state machine, mirror-path posting (original invoice / payment+allocation / deposit receive+refund), Activity Center deep links, and ARS reporting helpers. Shared `accounting_engine.php` is unchanged. BC-dependent workflows are implemented as gated stubs (no invented fee/Stripe/forfeit amounts).

**Do not begin Phase 3 until this report is reviewed and approved.**

---

## Roadmap status

| Phase | Status |
|-------|--------|
| 0 – Architecture Review & Audit | ✔ |
| 1 – Operational Foundations | ✔ |
| 1B – Booking Activity Center Foundation | ✔ |
| 2A – Business Confirmation & Financial Architecture | ✔ Approved |
| **2B – Financial Adapter & Accounting Implementation** | ✔ Code complete (localhost) — **awaiting human review** |
| 3 – Premium UX | ⬜ Not started |
| 4 – Advanced Holiday Homes | ⬜ Not started |

---

## Files changed / created

### Created
| Path | Purpose |
|------|---------|
| `migrations/ars_phase2b_financial_documents.sql` | Additive Option B schema + role map + flag |
| `modules/ars/includes/ars_account_roles.php` | Account role → COA resolution |
| `modules/ars/includes/ars_financial_document_sm.php` | Document lifecycle state machine |
| `modules/ars/includes/ars_financial_adapter.php` | Financial Adapter (sole new posting path when flag on) |
| `modules/ars/includes/ars_financial_reports.php` | Company-scoped document/AR/deposit helpers |
| `modules/ars/financial_document_view.php` | Deep-link document / deposit / refund view |
| `modules/ars/financial_reports.php` | ARS financial reports UI |
| `tools/ars_phase2b_verify.php` | Localhost verification CLI |
| `docs/ars/PHASE2B_COMPLETION_REPORT.md` | This report |

### Modified
| Path | Change |
|------|--------|
| `modules/ars/includes/ars_accounting.php` | Delegates to adapter when `financial_adapter_enabled=1`; fail-closed `company_id` on bridge |
| `modules/ars/includes/ars_activity.php` | Deep links for financial document / deposit / refund |
| `modules/ars/settings.php` | Feature flag toggle + CSRF-safe save |
| Cursor/master ARS roadmap plan | Phase 2A ✔; Phase 2B in progress → complete pending review |

### Explicitly unchanged
| Path | Evidence |
|------|----------|
| `modules/realestate/accounting/accounting_engine.php` | SHA-256 `9cbb880fc3241615b69d4fe5b3adfbee14e513d03964924214cc9325f9d7c7d6` |
| RE Invoice Mode / `re_invoices` writers | Adapter never writes `re_invoices` |
| Mobile/customer API contracts | No endpoint signature changes in Phase 2B |

---

## Migrations created (local applied)

`migrations/ars_phase2b_financial_documents.sql` (re-runnable, additive):

- `ars_company_settings.financial_adapter_enabled` (default `0`)
- `ars_account_role_map`
- `ars_financial_documents` + `ars_financial_document_lines`
- `ars_payment_allocations`
- `ars_refunds`
- `ars_security_deposits`
- `ars_extension_documents`, `ars_credit_notes`, `ars_adjustments`
- `ars_financial_document_transitions`
- Nullable links: `ars_booking_payments.financial_document_id`, `ars_bookings.primary_invoice_document_id`, `ars_booking_charges.financial_document_id`
- Seeded role map for company `code=ARS` (14 roles)

**Live:** do not run without separate written approval.

---

## Account Role Mapping

Roles: `AR_GUEST`, `ROOM_REVENUE`, `ADDITIONAL_SERVICE_REVENUE`, `DAMAGE_REVENUE`, `VAT_OUTPUT`, `SECURITY_DEPOSIT`, `DEFERRED_REVENUE`, `STRIPE_CLEARING`, `BANK`, `CASH`, `REFUND`, `BAD_DEBT`, `DISCOUNT`, `ROUNDING`.

Resolution order: `ars_account_role_map` (company + role) → defaults mirroring current bridge codes → `find_account_by_code`. Adapter never hard-codes account **IDs**.

---

## Financial Document State Machine

Lifecycle implemented:

`draft` → `validated` → `posted` → `partially_paid` → `paid` → `closed`  
Optional terminals: `cancelled`, `voided`, `reversed`, `refunded`

Enforced via `ars_fin_doc_can_transition` / `ars_fin_doc_transition` (no skip of draft→posted). Transitions audited in `ars_financial_document_transitions`.

**Design note vs Phase 2A ENUM:** Phase 2B go-ahead mandated `Validated` and `Closed`. Implemented as additive statuses on the Option B header. No deviation from engine or dual-ledger rules.

---

## Financial Adapter

Path: `modules/ars/includes/ars_financial_adapter.php`

| Method | Status |
|--------|--------|
| `ars_adapter_create_original_invoice` | ✔ Mirror EVT-01 |
| `ars_adapter_record_payment` + `ars_adapter_allocate_payment` | ✔ Mirror payment + FIFO allocations |
| `ars_adapter_receive_deposit` / `ars_adapter_refund_deposit` | ✔ Mirror deposit JV + `ars_security_deposits` |
| `ars_adapter_reverse_document` | ✔ `reverse_journal` + status |
| Extension / service / CN / adjustment / forfeit / stay refund / Stripe | Gated `needs_business_confirmation` (BC-06…13) |
| `ars_adapter_detect_amendment_documents` | ✔ Read-only preview enrichment |

Safety: company fail-closed; DB transactions for doc+journal; idempotency keys; activity after commit; flag default off.

Bridge cutover: `ars_post_*` in `ars_accounting.php` delegates when flag enabled (no dual-post).

---

## Posting workflows

| Workflow | Adapter behaviour |
|----------|-------------------|
| Original booking invoice | Fully implemented (mirror) |
| Payment + allocation | Fully implemented |
| Deposit receive / refund | Fully implemented |
| Extension / services / damage / CN / adjustment | Structure + BC gate |
| Deposit forfeiture | BC-10/11 gate |
| Cancellation reverse | `ars_adapter_reverse_document` available |
| Stripe settlement / fees | BC-12/13 gate |
| No-show / early checkout fees | Not auto-posted (Needs BC) |

Interim posting company: `booking.company_id` (Phase 2A interim until BC-01 Confirmed).

---

## Reporting integration

- Shared TB / BS / P&L / VAT / Bank Reco continue via existing `re_*` journals (adapter posts into same engine).
- New ARS surfaces: `financial_reports.php` — documents filter, outstanding AR, deposit liability.
- Helpers: `ars_report_financial_documents`, `ars_report_outstanding_receivables`, `ars_report_deposit_liability`, `ars_report_adapter_journal_ids`.
- Guest/booking statement polish and dedicated Stripe clearing report UI remain Phase 3+ / post-BC.

---

## Activity Center integration

- Invoice / payment / deposit events written with `title`, `dedupe_key`, journal + entity links.
- Deep links: `ars_financial_document` → `financial_document_view.php`; deposits/refunds linked.

---

## Feature flags

`ars_company_settings.financial_adapter_enabled` (default `0`), toggle on ARS Settings (CSRF). Local verify temporarily enables then restores **off**.

---

## Regression / verification results

CLI: `tools/ars_phase2b_verify.php` → **23 passed, 0 failed**

| Check | Result |
|-------|--------|
| Engine SHA unchanged | PASS |
| Tables + role map | PASS |
| Role resolution for ARS COA | PASS |
| SM no skip draft→posted | PASS |
| Original invoice + balanced journal | PASS |
| Idempotent replay | PASS |
| Payment allocate → partially_paid/paid | PASS |
| Company fail-closed | PASS |
| BC gate on extension | PASS |
| Activity invoice event | PASS |
| Flag restored off | PASS |
| Bridge still present | PASS |

Manual / scope notes:

- Existing historical bookings/journals not rewritten by migration.
- Real Estate Invoice Mode not exercised for write paths (no `re_invoices` insert from adapter).
- Mobile/customer API files not modified in Phase 2B.
- Full interactive UI booking confirm with flag on recommended during human UAT.

---

## Performance

Verify script invoice+payment path completed in ~0.5s on localhost. No unbounded list queries introduced (report helpers `LIMIT 500`). No N+1 posting loops.

---

## Defects discovered & fixes

| Defect | Fix |
|--------|-----|
| Activity payload used `summary` / `actor_user_id` (ignored by Phase 1B logger requiring `title`) | Adapter now sends `title` + `created_by` + `dedupe_key` |
| Verify script assumed `currency` on `ars_bookings` / `debit` on journal lines | Aligned to actual schema |

---

## Known limitations

1. BC-01…15 mostly still Pending — non-mirror workflows remain gated.
2. Legacy bookings with journals but no Option B documents are not backfilled (optional later).
3. When flag off, bridge continues (intentional).
4. Stripe still does not post clearing GL until BC-12/13.
5. Dedicated guest/booking PDF statement wiring to Option B not expanded.
6. Permission strings on SM transitions are documented; UI does not yet call `ars_fin_doc_transition` independently of adapter auto path.

---

## Deployment readiness

| Item | Ready? |
|------|--------|
| Localhost code + migration | Yes |
| Feature flag default off | Yes |
| Engine / RE Invoice Mode / APIs preserved | Yes |
| Production migration | **No** — needs separate approval |
| Enable adapter in production | **No** — after UAT + BC freeze for enabled events |
| Phase 3 start | **No** — stop for human review |

---

## Recommended human review checklist

1. Approve Option B tables + role map seed codes for ARS company.
2. Confirm interim BC-01 (`booking.company_id`) acceptable for flag-on UAT.
3. Decide which BC items must be Confirmed before enabling adapter for live new bookings.
4. UAT: Settings flag on → confirm booking → invoice view → payment → Activity Center link → financial reports → flag off.
5. Approve or reject live migration plan (separate from this report).

---

## Stop condition

**Phase 2B coding complete. STOP. Do not begin Phase 3. Do not deploy to production until Phase 2B is reviewed and approved.**
