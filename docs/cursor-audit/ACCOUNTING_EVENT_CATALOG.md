# Accounting Event Catalog

**Stage:** 1.5  
**Date:** 2026-07-10  
**Policy:** Invoice Mode events are **current**. Legacy events are marked **Historical** and retained for compatibility documentation only.

Legend for Mode: **IM** = Invoice Mode (official) | **LEG** = Legacy historical | **CLN** = Cleaning | **CO** = Construction | **HR** = Payroll | **SH** = Shared engine consumers

---

## Real Estate — Invoice Mode (official)

| Event | Business meaning | Trigger | Journal (typical) | Source tables | Reports affected |
|-------|------------------|---------|-------------------|---------------|------------------|
| Obligation Generated | Future charge recognized as obligation | Lease save / obligation engine | None until invoice | `re_obligations` | Obligation / outstandings (IM) |
| Invoice Candidate Created | Eligible bill draft | Invoice engine / scheduler | None | `re_invoice_candidates` | Invoice preview |
| Invoice Issued / Posted | AR and revenue/VAT recognized | `invoice_generate` / engine issue → `post_invoice_to_accounting` | Dr 1310; Cr income; Cr 2310 | `re_invoices`, items; journal | P&L, TB, BS, VAT, AR |
| Invoice Voided (unpaid) | Cancel unissued economic effect | Void unpaid path → `reverse_journal` | Reversal of invoice journal | invoices + journals | Same as above |
| Receipt Allocated | Cash applied to invoice/obligation/credit | `receipt_allocation` → `re_receipt_confirm` → `post_invoice_mode_receipt_to_accounting` | Dr Bank; Cr 1310 and/or Cr 2410 | `re_payments`, `re_receipt_allocations` | AR, cash, unearned |
| Credit Applied to Invoice | Tenant credit reduces AR | `apply_tenant_credit_to_invoice_accounting` | Dr 2410; Cr 1310 | credit txns, invoices | AR, unearned |
| Overpayment → Tenant Credit | Unallocated cash becomes liability | Remainder on receipt confirm | Cr 2410 portion | credit balances/txns | Unearned revenue |
| Security Deposit Received (obligation) | Deposit liability on receipt | Obligation alloc type SD → `re_sd_post_liability_*` | Dr Bank; Cr 2200 | SD audit, obligations, payments | BS liabilities, cash |
| Deposit Settled / Refunded | Move-out release / deductions | `re_sd_process_move_out_refund` | Dr 2200; Cr Bank / income | settlements, refunds | BS, P&L |
| Credit Note Issued | Reduce AR / revenue | `credit_note_add` → `post_credit_note_to_accounting` | Dr income; Cr 1310 | invoices outstanding | AR, P&L |
| PDC Cleared (IM) | Cheque becomes receipt | Cheque view → receipt allocation | Receipt journal | `re_post_dated_cheques`, payments | AR, cash, cheque reports |
| PDC Bounced | Cheque failed; penalty may arise | Lifecycle bounce + penalty item | No bounce journal; later collection | cheques, billing/obligations | Collections |
| PDC Replaced | New cheque supersedes old | Replace action | None until clear | cheques + audit | Cheque register |
| Vendor Bill Posted | AP expense | `post_vendor_bill_to_accounting` | Dr expense; Cr AP | vendor invoices | AP, P&L |
| Recurring Journal Generated | Standing JV | Recurring journals UI | Per template lines | `re_recurring_journals` | TB, P&L |

---

## Real Estate — Legacy (historical only)

| Event | Business meaning | Trigger | Journal | Source tables | Notes |
|-------|------------------|---------|---------|---------------|-------|
| Legacy Payment Recorded | Cash against installments | `payment_add.php` → `post_payment_to_accounting` | Dr Bank; Cr 1310 / 2410 | `re_payments`, `re_payment_allocations` | Do not extend |
| Legacy Deferred Payment | Cash to unearned | `post_deferred_payment_to_accounting` | Dr Bank; Cr 2410 | payments, recognition schedule | Historical runoff |
| Revenue Recognized (schedule) | Earn deferred rent | `process_revenue_recognition` | Dr 2410; Cr income (+VAT) | `re_rent_recognition_schedule` | Historical |
| Legacy PDC Cleared | Cheque → payment row | `billing_cheque_view` legacy path | **Often no GL** | payments, installments | Known debt |
| Lease-start Deposit Posted | Deposit at lease create | `post_security_deposit_to_accounting` | Dr Bank; Cr 2200 | lease | Prefer IM obligation path for new |

---

## Cleaning / SM

| Event | Business meaning | Trigger | Journal | Source tables | Reports |
|-------|------------------|---------|---------|---------------|---------|
| Invoice Posted | Customer AR | Invoice create/post → `gl_post_invoice` | Dr AR; Cr income/VAT (per COA) | `invoices`, `gl_journals` | Cleaning AR/VAT |
| Receipt Posted | Cash collection | Payments UI → `gl_post_receipt` | Dr bank; Cr AR | `receipts`, allocations | AR, cash |
| Expense Posted | Cost / AP | Expense post | Via `gl_create_journal` / SM | `expenses` | P&L, VAT |
| Credit Note Posted | Reduce AR | Credit note UI | CN GL postings | `credit_notes*` | AR |
| Refund Posted | Return cash | Refunds UI | Refund journals | `refunds` | Cash |
| Prepaid Purchased | Asset + VAT | Prepaid expense | Dr prepaid/VAT; Cr bank/AP | `sm_prepaid_schedules` | BS |
| Prepaid Amortized | Expense recognition | Amort run / cron | Dr expense; Cr prepaid | `sm_prepaid_amortization` | P&L |
| Recurring JV Run | Standing entry | SM recurring runner | `gl_create_journal` | `sm_recurring_journals` | TB |
| Bank Reco Match/Create | Statement vs books | Cleaning bank reco | Match flags or create JV | `cleaning_bank_*` | Bank reco |

---

## Construction

| Event | Business meaning | Trigger | Journal | Source tables | Reports |
|-------|------------------|---------|---------|---------------|---------|
| Supplier Invoice Posted | AP bill | `co_post_supplier_invoice_to_accounting` | Dr expense/CIP + 2130; Cr 2110 | `co_supplier_invoices` | AP, VAT |
| Supplier Paid | Settle AP | `co_post_supplier_payment_to_accounting` | Dr 2110; Cr bank | payments + allocations | AP, cash |
| Contractor Paid | Project cost / retention | `co_post_contractor_payment_to_accounting` | Dr CIP/COGS; Cr bank/retention | contractor payments | Project cost |
| Project Cost Posted | Direct cost | `co_post_project_cost_to_accounting` | Dr CIP/COGS; Cr bank | `co_project_costs` | CIP |
| Client Invoice / Payment | Construction AR | Client invoice/payment helpers | Shared engine | `co_client_*` | AR |
| Bank Reco Confirmed | Mark GL reconciled | Match confirm | No journal | matches + `re_general_ledger` flags | Reco report |
| Bank Reco Create/Adjust | New books entry from statement | `co_bank_reco_create_transaction` | Manual JV ref line | statement lines, journals | Reco, TB |
| ERP Expense Posted | Shared expense | ERP expense posting | Shared engine | `erp_expense_*` | P&L, VAT |

---

## HR / Payroll

| Event | Business meaning | Trigger | Journal | Source tables | Reports |
|-------|------------------|---------|---------|---------------|---------|
| Payroll Posted (Cleaning) | Wage expense / liabilities | Payroll finalize → `gl_create_journal` | Cleaning COA | `payroll_runs`, `gl_journals` | P&L, BS |
| Payroll Posted (Shared) | Same for non-cleaning | → `create_and_post_journal` | Shared COA | `payroll_runs`, `re_journal_*` | P&L, BS |

---

## ARS (shared engine)

| Event | Business meaning | Trigger | Journal | Source tables | Reports |
|-------|------------------|---------|---------|---------------|---------|
| Booking Revenue / Deposit Posted | Short-stay income / deposits | `ars_accounting.php` | Shared codes (e.g. AR/income/VAT/deposit) | `ars_*` | ARS revenue, VAT |

---

## Cross-cutting / control events

| Event | Business meaning | Trigger | Journal | Source tables | Reports |
|-------|------------------|---------|---------|---------------|---------|
| Manual Journal Posted | Ad-hoc adjustment | Journal UI | As entered | journal headers/lines | TB, day book |
| Journal Reversed | Correct prior entry | `reverse_journal` / `gl_reverse_journal` | Swap Dr/Cr | journals | All GL reports |
| Period Locked | Close fiscal year | Fiscal year close | Blocks new posts | `re_fiscal_years` | Control |
| Accounting Audit Logged | Traceability | Engine / domain audits | N/A | `re_accounting_audit_log`, etc. | Audit |

---

## Events Not found as first-class modules

| Event | Status |
|-------|--------|
| Petty Cash Voucher / Float Replenish | Not found (COA only) |
| Invoice Mode Receipt Void | Not found as dedicated event |
| Payroll Reverse | Not found as dedicated helper |

---

## Explicit non-changes

Catalog only. No posting behaviour changed.
