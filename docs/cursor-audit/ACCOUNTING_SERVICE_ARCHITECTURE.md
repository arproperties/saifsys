# Accounting Service Architecture (Design Only)

**Stage:** 1.5  
**Date:** 2026-07-10  
**Status:** Design proposal — **not implemented**. No code moved.

## Design principles

1. **Invoice Mode is the official Real Estate accounting model.** Legacy helpers remain as compatibility adapters only.
2. Preserve the **two ledger stacks** (Cleaning `gl_*` vs Shared `re_*`) behind adapters — do not force a big-bang merge.
3. Every write goes through a service that enforces: company context, idempotency key, period lock, audit log, balanced lines.
4. Domain services build journal intents; only `JournalService` persists journals.

```text
UI / API / Cron
    → Domain Services (Invoice, Allocation, Deposit, Payroll, …)
        → AccountingPostingService (orchestrates intent + idempotency)
            → JournalService (CleaningAdapter | SharedEngineAdapter)
                → gl_*  OR  re_*
```

---

## Target services

### 1. AccountingPostingService
**Role:** Orchestrate “business event → journal intent → post → link source → audit”.  
**Eventually owns / wraps:**
- Call patterns from `accounting_integration.php` `post_*` (Invoice Mode first)
- `construction_accounting_integration.php` `co_post_*`
- `ars_accounting.php` posting entry points
- `hr_payroll_post_accounting` routing decision (not line math)
- `includes/erp_expense_posting.php` post path

### 2. JournalService
**Role:** Create, post, reverse, duplicate-check, sequence numbers.  
**Adapters:**
- `SharedJournalAdapter` ← `create_journal_entry`, `post_journal`, `create_and_post_journal`, `reverse_journal`, `journal_duplicate_exists`
- `CleaningJournalAdapter` ← `gl_create_journal`, `gl_reverse_journal`

### 3. AllocationService
**Role:** Apply cash to open items; update outstanding; emit allocation rows.  
**Official RE:** `receipt_allocation_engine.php` (`re_receipt_*`).  
**Historical adapters (freeze):** `payment_allocation_helper.php`, `re_payment_allocations`, billing-item allocation posts.  
**Construction:** supplier/client payment allocation helpers.

### 4. TenantCreditService
**Role:** Credit balance, apply to invoice, refund.  
**Maps from:** `update_tenant_credit`, invoice-mode tenant_credit allocations, `apply_tenant_credit_to_invoice_accounting`, fixed `post_refund_to_accounting`.

### 5. DeferredRevenueService / RevenueRecognitionService
**Official (Invoice Mode):** Obligation schedule → invoice candidate → issue (`invoice_engine` + `post_invoice_to_accounting`). Recognition timing = invoice issue (and any explicit recognition jobs still used).  
**Historical:** `post_deferred_payment_to_accounting`, `process_revenue_recognition` on `re_rent_recognition_schedule` for legacy deferred leases — **compatibility only**.

### 6. SecurityDepositService
**Maps from:** `security_deposit_helper.php` (`re_sd_*`), Invoice Mode liability on obligation allocation; move-out settlement/refund.  
**Historical:** `post_security_deposit_to_accounting` on lease create — compatibility / review for new leases.

### 7. VATService
**Maps from:** `vat_config.php` / `re_vat_config`, `lease_vat_calculator.php`, Construction VAT account constants, Cleaning invoice/expense VAT fields, bank-reco VAT splits.  
**Reports:** feed `vat_report.php`, `construction_vat_report.php`, `accounts/report_vat.php` from one calculation policy per company.

### 8. ReconciliationService
**Adapters:**
- Cleaning ← `cleaning_bank_reconciliation.php`
- Shared ← `re_bank_reco_*` + `construction_bank_reconciliation.php` (converge)

### 9. ReportingService
**Role:** Trial balance, P&L, BS, AR ageing, VAT, unearned revenue — parameterized by company + ledger stack.  
**Maps from:** RE `accounting/*` reports, Cleaning `accounts/report_*`, Construction reports, `v_ar_*` views.

### 10. PeriodLockService
**Maps from:** `is_period_locked` / `re_fiscal_years`; extend concept to Cleaning if/when fiscal locks exist there (Needs business confirmation).

### 11. AuditService
**Maps from:** `accounting_audit_log` / `re_accounting_audit_log`, cheque/deposit/bank reco audits, `includes/AuditService.php`, `audit_log`.  
**Rule:** Every posting/reversal/allocation writes an audit record with company, user, source, before/after.

### Supporting domain services (recommended)

| Service | Source functions / areas |
|---------|--------------------------|
| InvoiceService (RE Invoice Mode) | `invoice_engine.php`, `post_invoice_to_accounting`, void unpaid |
| CreditNoteService | `post_credit_note_to_accounting` (fixed references) |
| PdcLifecycleService | `cheque_lifecycle_helper.php` + Invoice Mode clear via receipt |
| PayrollAccountingService | `hr_payroll_accounting.php` |
| PrepaidService (Cleaning) | `sm_prepaid_service.php` |
| RecurringJournalService | RE recurring UI + SM recurring runner |

---

## Migration strategy (conceptual only)

1. **Document boundaries** (this stage) — done as design.  
2. **Stop extending legacy RE paths** — product rule.  
3. **Introduce facades** that call existing functions (no behaviour change).  
4. **Move idempotency + company checks** into JournalService adapters.  
5. **Converge bank reco** behind ReconciliationService.  
6. **Deprecate legacy adapters** only after historical leases are frozen/read-only.

**Do not rewrite the ERP. Do not move code in Stage 1.5.**
