# ARS Financial Core v1.0 — Interface Contracts

**Audience:** Phase 3 UI / AJAX developers  
**Rule:** Pages and JavaScript must call these contracts (or `ars_post_*` bridge when flag off). **Do not** post journals directly.

Common response shape: `{ success, error?, code?, document_id?, journal_id?, ... }`.

Common requirements unless noted: `company_id` from booking (fail closed); CSRF on state-changing HTTP; permission via `ars_require_booking_action` / ops+core; idempotency key unique per company; Activity Center after commit; financial lock after money posts.

---

## Create & post original invoice

| Field | Contract |
|-------|----------|
| Entry | `ars_adapter_create_original_invoice` / `ars_post_booking_revenue` |
| Input | booking row; `user_id`; `idempotency_key` default `invoice:original:{id}` |
| Validation | company; COA roles; positive total |
| Output | document_id, journal_id |
| SM | draft→validated→posted |
| Activity | `invoice_created` |
| Lock | engages |

## Receive payment + allocate

| Field | Contract |
|-------|----------|
| Entry | `ars_adapter_record_payment` / `ars_post_payment_journal` |
| Input | payment row + booking |
| Validation | company match; amount > 0 |
| Output | journal_id, allocation_ids, unallocated, credit_id? |
| Idempotency | payment.journal_id or key |
| Activity | `payment_recorded` |
| Note | Overpay → guest credit when policy on |

## Allocate payment (explicit)

| Entry | `ars_adapter_allocate_payment` |
| Input | company_id, booking_id, payment_id, amount, idempotency_key |
| Output | allocation_ids, unallocated |

## Guest credit create / apply / refund

| Action | Entry |
|--------|-------|
| Create (usually via overpay/CN) | `ars_adapter_create_guest_credit` |
| Apply | `ars_adapter_apply_guest_credit($conn,$companyId,$creditId,$documentId,$amount,$opts)` |
| Refund | `ars_adapter_refund_guest_credit` |

## Extend booking

| Entry | `ars_adapter_create_extension_invoice` |
| Input | booking; `prior_check_out`, `new_check_out`; user_id |
| Validation | availability; nights > 0; rate |
| Side effect | updates check_out/nights after post |
| Activity | `extension_invoice_posted` |

## Add service / damage

| Entry | `ars_adapter_create_service_invoice` |
| Input | amount_net, description, line_type `service`\|`damage`, optional apply_deposit |
| Roles | ADDITIONAL_SERVICE_REVENUE / DAMAGE_REVENUE |

## Deduct deposit to AR / forfeit

| Action | Entry |
|--------|-------|
| Apply to AR | `ars_adapter_apply_deposit_to_ar` |
| Forfeit | `ars_adapter_forfeit_deposit` (amount + **reason** required) |

## Adjustment / credit note

| Action | Entry |
|--------|-------|
| Adjustment | `ars_adapter_create_adjustment_invoice` |
| Credit note | `ars_adapter_create_credit_note` (optional `to_guest_credit`) |

## Shorten / early checkout

| Entry | `ars_adapter_shorten_booking` |
| Input | `new_check_out`; respects `early_checkout_refundable` |

## Cancel / no-show

| Action | Entry |
|--------|-------|
| Cancel financials | `ars_adapter_cancel_financials` |
| No-show | `ars_adapter_no_show` (policy keep_revenue vs reverse) |

## Refund (stay)

| Entry | `ars_adapter_create_refund` |
| Input | amount, method, source; or credit_id for credit refund |

## Stripe (simulated)

| Action | Entry |
|--------|-------|
| Card via clearing | `ars_adapter_stripe_card_payment` |
| Settlement + fee | `ars_adapter_stripe_settlement` (`company_id`, `payment_ids`) |

## Deposits receive/refund

| Entry | `ars_adapter_receive_deposit` / `ars_adapter_refund_deposit` (or `ars_post_deposit_*`) |

## Reverse document

| Entry | `ars_adapter_reverse_document` |

---

## UI wrapper guidance (Class D)

Allowed: thin PHP/JS that collects form fields, verifies CSRF/permission, calls one contract, maps result to toast/redirect.  
Forbidden: recalculating VAT/totals for posting, building journal lines, updating `re_journal_*`, hard-coding account IDs.
