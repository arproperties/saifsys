# Phase 2D — Final Explanation

**Document purpose:** Standalone explanation of Phase 2D for management and future developers.  
**Date:** 2026-07-17  
**Environment:** Localhost / XAMPP `datanew` only  

---

## 1. Executive summary

Phase 2C proved the Financial Adapter technically, but **ten core commercial scenarios remained BLOCKED** because Business Confirmation (BC) items were unresolved.

Phase 2D:

1. Documented every blocked scenario and BC option in a **Business Decision Workshop**  
2. Adopted **interim localhost rules** based on Phase 2A recommendations and current ARS behaviour (fee amounts remain **configurable**, not invented hard-coded schedules)  
3. Implemented the gated Financial Adapter workflows  
4. Re-tested successfully: **24 PASS / 0 FAIL / 0 BLOCKED / 0 SKIP**  
5. Left the adapter feature flag **OFF** and did **not** deploy to production  

**Verdict:** ARS financial lifecycle is complete and testable on localhost under interim rules. Production go-live remains **NO-GO** until business ratifies interim BCs and separately approves any live migration.

---

## 2. Why Phase 2D was required

Phase 2C UAT scored well on integrity, security, regression, and performance, but readiness was only **4/10** because extension, credit notes, forfeiture, Stripe clearing, guest credit, no-show, early checkout, and related paths returned `needs_business_confirmation`.

Without Phase 2D, Phase 3 UX would either invent policy in the UI or leave broken buttons. Phase 2D closes that gap on localhost.

---

## 3. Previously blocked scenarios (Phase 2C)

| ID | Scenario |
|----|----------|
| WF-CREDIT-01 | Guest credit / overpayment |
| WF-EXT-01 | Booking extension invoice |
| WF-SVC-01 | Additional services |
| WF-ADJ-01 | Invoice adjustment |
| WF-CN-01 | Credit notes |
| WF-FORF-01 | Deposit forfeiture |
| WF-REF-01 | Guest stay refund |
| WF-STRIPE-01 | Stripe settlement |
| WF-NOSHOW-01 | No-show |
| WF-EARLY-01 | Early checkout |
| (+ SKIP) | Live Stripe charge — replaced by simulation |

---

## 4. Final decision for each scenario

| Topic | Interim decision |
|-------|------------------|
| BC-01 Posting company | Keep `booking.company_id` (ARS) |
| BC-02/03 Recognition / deferred | Recognize on confirm; **do not** use deferred 2400 |
| BC-04 VAT | Keep booking vat_mode/rate; CN reverses VAT |
| BC-05 Tourism | Not required / not posted |
| BC-06 Cancellation | Default full reverse; optional fee % (default 0) |
| BC-07 No-show | Default **keep revenue**; alt reverse_like_cancel |
| BC-08 Early checkout | Credit note unused nights → guest credit (refundable default) |
| BC-09 Extension | Extension invoice for added nights at nightly rate |
| BC-10/11 Deposit / damage | Forfeit to FORFEIT_REVENUE; damage as service_invoice |
| BC-12/13 Stripe | Clearing 1130 then settle to bank; fee expense 5510 |
| BC-14 Guests | Remain `ars_guests` |
| BC-15 AR / credit | Overpay → guest credit liability 2210 (fully traceable) |

Details: `PHASE2D_BUSINESS_DECISION_WORKSHOP.md` and `PHASE2D_FINAL_BUSINESS_RULE_REGISTER.md`.

---

## 5. Business rules implemented

Encoded in `ars_financial_policy` (per company):

- `early_checkout_refundable` (default 1)  
- `cancellation_fee_percent` (default 0)  
- `no_show_fee_mode` (`keep_revenue` \| `reverse_like_cancel`)  
- `stripe_fee_percent` (default 0)  
- `overpay_to_guest_credit` (default 1)  

Normative register: `PHASE2D_FINAL_BUSINESS_RULE_REGISTER.md`.

---

## 6. Accounting treatment implemented

- Shared engine only (`create_and_post_journal` / `reverse_journal`)  
- Role mapping — never hard-coded account IDs  
- New COA (ARS company): 1130 Stripe Clearing, 2210 Guest Credit Liability, 5510 Stripe Fees  
- Overpayment: allocate then reclass excess AR → Guest Credit  
- Stripe card: DR Clearing / CR AR; settlement: DR Bank + DR Fee / CR Clearing  
- Credit notes: DR revenue+VAT / CR AR; optional guest credit reclass  
- Deposit forfeit: DR 2200 / CR 4900  

Never writes `re_invoices`. Never modifies `accounting_engine.php`.

---

## 7. Tables and migrations

Migration: `migrations/ars_phase2d_gated_workflows.sql` (additive, localhost applied)

- `ars_financial_policy`  
- `ars_guest_credits`, `ars_guest_credit_applications`  
- `ars_stripe_settlements`, `ars_stripe_settlement_lines`  
- `ars_service_catalog`  
- COA seeds + `ars_account_role_map` updates  

---

## 8. Files changed

| File | Change |
|------|--------|
| `ars_financial_adapter_phase2d.php` | **New** — gated workflow implementations |
| `ars_financial_adapter.php` | Overpay credit; ungated entry points |
| `ars_account_roles.php` | New roles |
| `tools/ars_phase2d_uat.php` | **New** UAT harness |
| `docs/ars/PHASE2D_*.md` | Full documentation set |

---

## 9. Financial Adapter changes

Previously gated methods now execute under interim rules:

`create_extension_invoice`, `create_service_invoice`, `create_adjustment_invoice`, `create_credit_note`, `forfeit_deposit`, `create_refund`, `stripe_settlement`, plus new `shorten_booking`, `no_show`, `cancel_financials`, `stripe_card_payment`, guest credit APIs.

---

## 10. State-machine changes

Existing document SM retained (draft→validated→posted→paid…). Credit notes/adjustments/extensions use the same poster path. Deposit/refund/settlement satellites use their own posted statuses. Transition audits continue on financial documents.

---

## 11. Account-role mapping changes

Added/updated: `GUEST_CREDIT`, `STRIPE_CLEARING`, `STRIPE_FEE`, `DAMAGE_REVENUE`, `ADDITIONAL_SERVICE_REVENUE`, `FORFEIT_REVENUE`, `LATE_FEE_REVENUE`.

---

## 12. Activity Center changes

New event types include: `guest_credit_created`, `guest_credit_applied`, `*_invoice_posted`, `deposit_forfeited`, `refund_completed`, `no_show_recorded`, plus existing invoice/payment events. Deep links reuse Phase 2B document view patterns.

---

## 13. Reports added or updated

Phase 2B ARS financial reports remain; deposit liability includes forfeits. Guest credit / Stripe settlement / CN lists are queryable via new tables (UI tabs can be expanded in Phase 3). Shared TB/P&L/BS/Bank reco continue to consume `re_*` journals.

---

## 14. Complete UAT results

From `_phase2d_uat_results.json`:

- **PASS: 24**  
- **FAIL: 0**  
- **BLOCKED: 0**  
- **SKIP: 0**  

All ten Phase 2C blocked scenarios: **completed**.

---

## 15. Complete regression results

Engine SHA unchanged; no `re_invoices` writes; adapter flag restored OFF; mobile/customer API files not modified in 2D.

---

## 16. Performance results

Phase 2D UAT suite completed in &lt;1s on localhost. No new bottlenecks identified.

---

## 17. Security and integrity results

Company fail-closed; idempotency keys; transactional document+journal posting; flag off after tests; CSRF unchanged on AJAX surfaces.

---

## 18. Remaining limitations

1. Interim BC rules need formal business/finance sign-off for production  
2. Non-zero commercial fee schedules not prescribed (settings default safe)  
3. Full guest/booking statement UI polish deferred to Phase 3  
4. Live Stripe webhooks/keys not used — simulation only  
5. Deferred revenue model not activated  
6. Tourism fees not implemented (N/A)  
7. Not all adapter methods are wired into every UI button yet  

---

## 19. Deployment and rollback notes

- **Do not** run Phase 2D migration on live without separate approval  
- **Do not** enable `financial_adapter_enabled` permanently  
- Rollback: leave flag OFF; reverse journals via existing reverse APIs; do not drop tables with posted data  

---

## 20. Readiness score

**Overall production readiness: 5 / 10 — NO-GO for production.**  
Localhost financial completeness under interim rules: **strong**.

---

## 21. Recommended next step

1. Human review of Phase 2D package (especially workshop + register)  
2. Ratify or amend interim BC decisions  
3. On approval: begin **Phase 3 UX** on localhost only  
4. Keep adapter OFF outside controlled UAT  

---

## 22. Can Phase 3 safely begin?

**Yes — after formal Phase 2D human approval**, with these constraints:

- Localhost only  
- No production deploy / live migration / live Stripe keys  
- Adapter remains default disabled  
- Phase 3 must follow the Final Business Rule Register (not invent fees)  

**Phase 3 has NOT been started by this work.**

---

## Quick stats (for the Phase 2D stop condition)

| Metric | Value |
|--------|------:|
| Total PASS | **24** |
| Total FAIL | **0** |
| Total BLOCKED | **0** |
| Total SKIP | **0** |
| All 10 Phase 2C blocked scenarios completed? | **Yes** |
| Phase 3 may begin after approval? | **Yes (UX only; not production)** |
