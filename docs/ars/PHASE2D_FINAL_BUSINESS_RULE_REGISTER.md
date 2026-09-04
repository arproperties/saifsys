# Phase 2D — Final Business Rule Register

**Status:** Official implementation source for Phase 2D (localhost interim)  
**Source:** [`PHASE2D_BUSINESS_DECISION_WORKSHOP.md`](PHASE2D_BUSINESS_DECISION_WORKSHOP.md)  
**Ledger:** Shared RE engine via Financial Adapter only  
**Never:** Rewrite posted journals; write `re_invoices`; invent hard-coded fee schedules  

---

## Global rules (all workflows)

| Field | Rule |
|-------|------|
| Company | `booking.company_id` (BC-01 A) |
| Engine | `create_and_post_journal` / `reverse_journal` only |
| Idempotency | Unique `(company_id, idempotency_key)` |
| Lock | Posted money docs engage financial lock; amendments emit new docs |
| Activity | After successful commit |
| Flag | `financial_adapter_enabled` default 0 |
| VAT | Booking `vat_mode`/`vat_rate`; CN reverses VAT share |
| Deferred | Unused (BC-02/03 A) |
| Tourism | None (BC-05 A) |

Account roles (additive): `GUEST_CREDIT`→2210, `STRIPE_CLEARING`→1130, `STRIPE_FEE`→5510, `DAMAGE_REVENUE`→4200, `ADDITIONAL_SERVICE_REVENUE`→4200, `FORFEIT_REVENUE`→4900, `LATE_FEE_REVENUE`→4300.

---

## BR-ARS-001 — Original invoice (unchanged mirror)

Trigger: confirm. Doc: `original_invoice`. DR AR_GUEST / CR ROOM_REVENUE / CR VAT_OUTPUT. SM: draft→validated→posted.

---

## BR-ARS-002 — Payment + allocation + guest credit

Trigger: payment recorded. Journal: DR CASH|BANK|STRIPE_CLEARING / CR AR. Allocate FIFO to open invoices. **Unallocated remainder → guest credit** (DR AR already cleared for full payment; credit liability: for overpay portion post DR AR was full payment amount — accounting:

Correct overpay model:
1. Payment amount P received: DR Cash P / CR AR P (cash in, AR credited)
2. Allocate min(P, open balances) to documents
3. If P > allocated: the excess credit on AR is guest overpayment → reclass **DR AR / CR GUEST_CREDIT** for excess (moves liability to guest credit)

Or simpler: payment journal only for amount allocated to docs + credit portion:
- DR Cash P
- CR AR allocated
- CR GUEST_CREDIT (P - allocated)

**Use simpler balanced form** (preferred).

Apply credit: DR GUEST_CREDIT / CR AR + allocate. Refund credit: DR GUEST_CREDIT / CR CASH|BANK.

---

## BR-ARS-003 — Extension invoice

Trigger: approved extension with night delta > 0. Preconditions: availability OK; company match. Doc: `extension_invoice` + `ars_extension_documents`. Amounts: nights × rate; VAT per booking. Journal same shape as original. Update `check_out`/`nights` after post. Multiple extensions allowed (new doc each).

---

## BR-ARS-004 — Shorten / early checkout

Trigger: new check_out earlier. If `early_checkout_refundable=1` (default): `credit_note` for unused nights net+VAT against parent invoice; credit guest credit unless refund method set. Update dates after CN post. No rewrite of parent.

**Update 2026-07-19:** Live ops product rule **BR-ARS-OPS-001** Confirmed — early checkout via Check Out is **non-refundable** for unused stay nights; planned dates preserved; `actual_check_out` + cleaning on actual date. See `docs/business-rules/BR-ARS-OPS-001-early-checkout.md`. Adapter CN path must not be used for routine early checkout unless a future rule re-opens refunds.

---

## BR-ARS-005 — Additional service

Trigger: charge service. Doc: `service_invoice` line_type service. Role ADDITIONAL_SERVICE_REVENUE. VAT per booking.

---

## BR-ARS-006 — Damage

Trigger: damage charge. Doc: `service_invoice` line_type damage. Role DAMAGE_REVENUE. Optional deposit apply: forfeit portion + reduce open AR via allocation from deposit conversion.

Deposit apply sequence:
1. Post damage invoice (DR AR / CR damage rev / VAT)
2. If apply_deposit: forfeit min(open_deposit, invoice total) DR 2200 / CR AR (clears AR) — not revenue again
3. Refund leftover deposit separately if requested

---

## BR-ARS-007 — Deposit forfeit

Trigger: approved forfeit. Event `forfeit` on `ars_security_deposits`. DR SECURITY_DEPOSIT / CR FORFEIT_REVENUE. Reason required. Partial allowed.

---

## BR-ARS-008 — Credit note

Trigger: reduction / cancel unused / service removal. Doc: `credit_note`. Journal: DR ROOM_REVENUE|service role, DR VAT, CR AR (reduce receivable). Or if invoice paid: creates guest credit / refund path. Parent link required. Status transitions on parent balance.

---

## BR-ARS-009 — Adjustment invoice

Trigger: price increase. Doc: `adjustment_invoice`. Same posting as service/room increment.

---

## BR-ARS-010 — Cancellation

Default: reverse original (+ extension) revenue docs via `ars_adapter_reverse_document` / CN policy. If `cancellation_fee_percent`>0: post fee invoice first on LATE_FEE_REVENUE, then CN/reverse remainder of room docs.

---

## BR-ARS-011 — No-show

Default `no_show_fee_mode=keep_revenue`: keep invoices posted; booking status cancelled reason no_show; release unit. Alt `reverse_like_cancel`: BC-06 path.

---

## BR-ARS-012 — Guest refund

Source required: credit balance, CN, deposit, or payment. Doc `ars_refunds`. Journal per source. Methods cash/bank/stripe_sim.

---

## BR-ARS-013 — Stripe simulate

Card pay: DR STRIPE_CLEARING / CR AR. Settlement batch: DR BANK (net) + DR STRIPE_FEE (fee) / CR CLEARING (gross). Fee % from policy (default 0). Idempotent settlement refs.

---

## Policy settings keys (`ars_financial_policy`)

| Key | Default | Meaning |
|-----|---------|---------|
| early_checkout_refundable | 1 | CN unused nights |
| cancellation_fee_percent | 0 | Optional cancel fee |
| no_show_fee_mode | keep_revenue | keep_revenue \| reverse_like_cancel |
| stripe_fee_percent | 0 | Simulated fee |
| overpay_to_guest_credit | 1 | Always on for 2D |

---

## State machine (docs)

Unchanged core path. Credit notes: draft→validated→posted→(closed). Refunds: draft→validated→posted. Deposits: draft→posted. Settlements: draft→posted.

---

## Reports required

Guest credit balance, forfeiture, CN list, refunds, stripe clearing, services/damage revenue — via extended `ars_financial_reports.php` + UI tabs.
