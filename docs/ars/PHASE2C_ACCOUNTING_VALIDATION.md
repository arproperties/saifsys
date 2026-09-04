# Phase 2C — Accounting Validation

**Status:** Complete (localhost)  
**Ledger stack:** Shared RE engine (`re_journal_*` / `re_chart_of_accounts`) via ARS Financial Adapter  
**Engine file:** Unchanged — SHA-256 `9cbb880fc3241615b69d4fe5b3adfbee14e513d03964924214cc9325f9d7c7d6`  
**Do not invent:** Fee/Stripe/deferred policies remain Needs business confirmation  

---

## Scope

Validate that every **enabled** ARS financial event matches the Phase 2A design / Phase 2B mirror path:

| Event | Design ref | UAT result |
|-------|------------|------------|
| Original booking invoice | EVT-01 | PASS — DR AR 1310 / CR 4100 / CR 2310; balanced |
| Cash / bank / card payment | Payment EVT | PASS — DR cash|bank / CR AR; balanced |
| Payment allocation | Adapter allocate | PASS — FIFO to open docs; status → paid/partial |
| Deposit receive | EVT-05 | PASS — DR cash / CR 2200 |
| Deposit refund | EVT-06 | PASS — DR 2200 / CR cash |
| Document reverse (cancel) | Reverse path | PASS — `reverse_journal` + doc `reversed` |
| Extension / CN / forfeit / Stripe / stay refund | EVT-02+ | **BLOCKED** — `needs_business_confirmation` |

---

## Journal integrity checks

| Check | Result | Evidence |
|-------|--------|----------|
| Revenue JV Dr = Cr | PASS | ACC-A-01 `d=210 c=210` |
| Payment JV Dr = Cr | PASS | ACC-A-03 |
| Deposit JV Dr = Cr | PASS | ACC-B-01 |
| Deposit refund JV Dr = Cr | PASS | ACC-B-03 |
| Account roles (not hard-coded IDs) | PASS | `ars_account_role_map` → codes 1310/4100/2310/1110/1210/2200 |
| VAT on original invoice | PASS | Credit 2310 present when vat_amount > 0 |
| Deferred revenue 2400 | PASS (unused) | ACC-DEF-01 — role resolves; **no posts** until BC-02/03 |
| Document ↔ journal link | PASS | INT-05 missing journals = 0 |
| Idempotent double confirm | PASS | EDGE-01 single document |

---

## Receivables & deposits subledgers

| Control | Method | Result |
|---------|--------|--------|
| Outstanding AR (Option B) | `ars_report_outstanding_receivables` | PASS — runnable; total observed during UAT |
| Deposit liability | `ars_report_deposit_liability` | PASS — net 0 after collect+full refund scenario |
| Allocations ≤ document | Overpay scenario | PASS — allocated capped at invoice total |

---

## Shared official reports (integration)

ARS posts into the **same** shared journals consumed by:

| Report | Path | Validation approach | Result |
|--------|------|---------------------|--------|
| Trial Balance | `modules/realestate/accounting/trial_balance.php` | Page present; ARS journals in `re_*` for company | PASS (structural + posting proven) |
| Profit & Loss | `profit_loss.php` | Revenue 4100 credits from adapter | PASS (structural) |
| Balance Sheet | `balance_sheet.php` | AR 1310 / deposits 2200 | PASS (structural) |
| Bank Reconciliation | `bank_reconciliation.php` | Cash/bank journals available to reco | PASS (structural) |
| VAT Report | `vat_report.php` | Output VAT 2310 lines posted | PASS (account presence on JV) |
| ARS Financial Reports | `modules/ars/financial_reports.php` | Documents / AR / deposits tabs | PASS |

**Note:** Full interactive TB/P&L period UI screenshots are human UAT optional; automated proof is balanced adapter journals + company-scoped `re_journal_lines`.

---

## Activity Center accounting trail

| Check | Result |
|-------|--------|
| Invoice activity with `ars_financial_document` link | PASS (ACT-A-01) |
| Deep link target `financial_document_view.php` | Implemented Phase 2B |

---

## Gaps (not failures)

1. **BC-01** posting company interim = `booking.company_id` (ARS) — not Confirmed for multi-entity bank reco.
2. **Deferred recognition** not exercised (by design until BC-02/03).
3. **Credit notes / adjustments / extension / forfeit / Stripe fees** not posted (gated).
4. Historical pre-adapter bookings may lack Option B documents (legacy bridge journals remain valid; backfill optional).

---

## Accounting assessment

| Dimension | Rating |
|-----------|--------|
| Double-entry integrity (enabled path) | **Strong** |
| Company isolation | **Strong** |
| Role mapping vs hard-coded IDs | **Strong** |
| Policy completeness for all commercial events | **Incomplete until BC sheet closed** |
| Production posting readiness | **Not approved** (localhost + flag off + open BCs) |

**Verdict:** Accounting for the **approved mirror path** is validated. Full Holiday Homes commercial accounting cannot be declared complete until remaining BC items are Confirmed and gated methods are unlocked under a separate change control.
