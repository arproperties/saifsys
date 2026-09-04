# ARS Financial Core v1.0 — Database Baseline

**Freeze date:** 2026-07-17  
**Scope:** Schema structure only (no business data export)  
**Full DDL artifact:** [`_ars_financial_core_v1_schema.sql`](_ars_financial_core_v1_schema.sql)  

---

## Feature flag

| Table | Column | Default | Meaning |
|-------|--------|---------|---------|
| `ars_company_settings` | `financial_adapter_enabled` | `0` | Adapter path when 1 |

## Policy

| Table | Purpose | Isolation |
|-------|---------|-----------|
| `ars_financial_policy` | early_checkout_refundable, cancellation_fee_percent, no_show_fee_mode, stripe_fee_percent, overpay_to_guest_credit | `company_id` UNIQUE |

## Account roles

| Table | Keys |
|-------|------|
| `ars_account_role_map` | UNIQUE (`company_id`,`role_code`); `account_code`; `is_active` |

## Financial documents

| Table | Key fields |
|-------|------------|
| `ars_financial_documents` | `company_id`, `booking_id`, `guest_id`, `document_type`, `document_number`, `status` (SM), amounts, `vat_*`, `journal_id`, `reversal_journal_id`, `parent_document_id`, `idempotency_key` UNIQUE per company, audit timestamps |
| `ars_financial_document_lines` | `document_id`+`line_no` UNIQUE, `account_role`, amounts, `company_id` |
| `ars_financial_document_transitions` | audit from→to status |

Indexes: booking/status/journal/parent; unique number + idempotency.

## Satellites

| Table | Notes |
|-------|-------|
| `ars_extension_documents` | PK `document_id` |
| `ars_credit_notes` | PK `document_id`, `applies_to_document_id` |
| `ars_adjustments` | PK `document_id` |
| `ars_payment_allocations` | payment↔document; idempotency UNIQUE |
| `ars_refunds` | refund_number UNIQUE; idempotency UNIQUE |
| `ars_security_deposits` | event_type receive/refund/forfeit; idempotency UNIQUE |
| `ars_guest_credits` | guest/booking balances; idempotency UNIQUE |
| `ars_guest_credit_applications` | credit→document applications |
| `ars_stripe_settlements` / `_lines` | sim settlement; payment UNIQUE on lines |
| `ars_service_catalog` | optional service codes |

## Booking / payment link columns (additive)

| Table | Column |
|-------|--------|
| `ars_bookings` | `primary_invoice_document_id`, lock/financial_status fields (Phase 1) |
| `ars_booking_payments` | `financial_document_id`, `journal_id` |
| `ars_booking_charges` | `financial_document_id` |

## Shared journals (not owned by ARS schema, consumed)

`re_journal_headers`, `re_journal_lines`, `re_chart_of_accounts` — company-scoped; ARS posts via engine only.

## Integrity expectations (v1.0)

- Every posted document with `journal_id` must resolve to `re_journal_headers`
- No document line without header
- No allocation without document
- No activity without matching company booking
- Idempotency unique per company

## Change rule

Additive migrations only; no destructive drops of posted tables; any schema change requires financial change control and version bump (v1.0.1 / v1.1).
