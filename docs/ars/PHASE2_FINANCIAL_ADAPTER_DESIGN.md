# Phase 2A — Financial Adapter Design Specification

**Status:** Design only — **no implementation in Phase 2A**  
**Target path (proposed):** `modules/ars/includes/ars_financial_adapter.php`  
**Engine:** call existing `create_and_post_journal` / `reverse_journal` only — **no** `accounting_engine.php` signature changes  
**Supersedes as permanent model:** direct expansion of `ars_accounting.php` (becomes legacy bridge)

---

## 1. Responsibilities

| Does | Does not |
|------|----------|
| Validate booking + company + lock rules | Redesign RE Invoice Mode |
| Create Option B documents + lines | Write `re_invoices` |
| Post/reverse journals via shared engine | Change engine behaviour |
| Allocate payments to documents | Dual-write Cleaning `gl_*` |
| Emit Activity Center events | Break mobile response shapes |
| Enforce idempotency | Rewrite historical amounts |

---

## 2. Cross-cutting rules

1. **Company:** All writes use confirmed posting company (BC-01); fail closed if missing.  
2. **Transactions:** Document insert + journal post in one DB transaction; on journal failure → rollback documents.  
3. **Idempotency:** Every mutating method requires `idempotency_key`; unique per company.  
4. **Activity:** Log after successful commit (or safe post-commit); logging failure must not roll back finance (same Phase 1 pattern).  
5. **Lock:** Money mutations on locked bookings require amendment context / new documents — never silent field edits.  
6. **Errors:** Return structured `{success, error, code, document_id?, journal_id?}`; never partial posted journal without document (or vice versa).

---

## 3. Method catalog

### 3.1 `ars_adapter_create_original_invoice(PDO $conn, array $booking, array $opts): array`

| Aspect | Spec |
|--------|------|
| **Trigger** | Booking confirm (replaces direct `ars_post_booking_revenue` when flag on) |
| **Inputs** | Booking row; `user_id`; `idempotency_key`; optional `document_date` |
| **Validation** | Pending→confirm path; no existing posted original invoice for booking; COA 1310/4100/2310 exist for posting company |
| **Documents** | `ars_financial_documents` type `original_invoice` + lines (room + VAT) |
| **Journal** | Same shape as current revenue: DR AR, CR revenue, CR VAT (amounts from booking nets — BC-02) |
| **Rollback** | Transaction abort; no orphan journal |
| **Idempotency** | Key e.g. `invoice:original:{booking_id}` |
| **Activity** | `accounting` / `invoice_created` + existing `booking_confirmed` (do not duplicate revenue event) |
| **Errors** | Missing COA; already invoiced; availability failure is ops layer before adapter |

### 3.2 `ars_adapter_create_extension_invoice(...)`

| Aspect | Spec |
|--------|------|
| **Trigger** | Approved extension amendment |
| **Inputs** | Booking; prior/new checkout; night delta; amounts; amendment id; idempotency |
| **Documents** | `extension_invoice` + `ars_extension_documents` meta |
| **Journal** | Incremental revenue/VAT/AR only for added amount |
| **Depends on** | BC-09 |

### 3.3 `ars_adapter_create_service_invoice(...)`

| Aspect | Spec |
|--------|------|
| **Trigger** | Additional service / damage on locked booking (amendment) or unlocked charge promotion |
| **Inputs** | Charge lines; booking; idempotency |
| **Documents** | `service_invoice` (+ damage line_type) |
| **Journal** | DR AR CR revenue/VAT (or damage income account — BC-11) |
| **Activity** | Charge/invoice events once |

### 3.4 `ars_adapter_create_adjustment_invoice(...)` / `ars_adapter_create_credit_note(...)`

| Aspect | Spec |
|--------|------|
| **Trigger** | Rate correction; cancel unused nights; early checkout |
| **Documents** | `adjustment_invoice` or `credit_note` + meta tables; `parent_document_id` |
| **Journal** | Balanced adjustment or CN (reduce AR / revenue / VAT as designed) |
| **Depends on** | BC-06…BC-08 |
| **Reversal** | CN does not edit parent totals; parent `amount_allocated`/`balance` recalculated |

### 3.5 `ars_adapter_record_payment(...)`

| Aspect | Spec |
|--------|------|
| **Trigger** | Staff/manual payment; Stripe success settlement (BC-12) |
| **Inputs** | Amount, method, booking, optional document targets |
| **Documents** | Insert/keep `ars_booking_payments`; allocations to open invoices |
| **Journal** | DR cash/bank/clearing CR AR (current 1110/1210/1310 unless BC-12/13) |
| **Idempotency** | Stripe: gateway payment intent id; manual: client key |
| **Activity** | `payment_recorded` once |

### 3.6 `ars_adapter_allocate_payment(...)`

| Aspect | Spec |
|--------|------|
| **Trigger** | After payment or explicit allocate |
| **Documents** | `ars_payment_allocations` rows; update document balances |
| **Journal** | Normally none if payment JV already cleared AR |
| **Validation** | Sum allocations ≤ payment; ≤ document balance; company match |

### 3.7 `ars_adapter_receive_deposit(...)` / `ars_adapter_refund_deposit(...)` / `ars_adapter_forfeit_deposit(...)`

| Aspect | Spec |
|--------|------|
| **Mirror** | Current deposit helpers; also write `ars_security_deposits` |
| **Journal** | 1110/1210 ↔ 2200; forfeit Needs BC-10/11 accounts |
| **Stripe** | No silent GL until BC-12 Confirmed |

### 3.8 `ars_adapter_create_refund(...)`

| Aspect | Spec |
|--------|------|
| **Trigger** | Stay payment refund |
| **Documents** | `ars_refunds` (+ optional CN) |
| **Journal** | DR AR or revenue path per policy; CR bank — Needs BC |
| **Activity** | `refund_posted` |

### 3.9 `ars_adapter_reverse_document(...)`

| Aspect | Spec |
|--------|------|
| **Trigger** | Cancel before settle / void draft mistake |
| **Behaviour** | Prefer CN for posted invoices; `reverse_journal` only when policy allows full reverse (current cancel) |
| **Never** | Delete posted journal rows |

### 3.10 `ars_adapter_detect_amendment_documents(...)`

| Aspect | Spec |
|--------|------|
| **Trigger** | Phase 1 impact detection enrichment |
| **Output** | Concrete document types/amounts preview (replaces Phase 1 placeholder strings) |
| **Side effects** | None (read-only) |

---

## 4. Feature flag & cutover

| Flag | Behaviour |
|------|-----------|
| `ars_financial_adapter_enabled=0` | Keep `ars_accounting.php` bridge |
| `=1` | Confirm/payment/deposit call adapter; bridge unused for new posts |

Shadow mode (optional Phase 2B): adapter builds document draft without posting — Needs approval.

---

## 5. Error codes (suggested)

`missing_coa`, `idempotent_replay`, `booking_locked`, `validation_failed`, `journal_failed`, `company_mismatch`, `over_allocation`

---

## 6. Testing requirements (for Phase 2B)

- Journal failure rolls back document  
- Double confirm → one invoice  
- Cross-company rejected  
- Mobile payment keys unchanged  
- Engine file hash unchanged  
- Activity events not duplicated across bridge+adapter  

---

## 7. Explicit non-goals (Phase 2B)

- No Laravel rewrite  
- No Cleaning GL merge  
- No RE Invoice Mode schema changes for ARS  
- No live deploy without separate approval  
