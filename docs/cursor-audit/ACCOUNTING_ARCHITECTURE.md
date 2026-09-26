# Accounting Architecture Audit

**Project:** HeroSysgro ERP  
**Audit stage:** Stage 1  
**Generated:** 2026-07-10  
**Scope:** Read-only deep trace of posting flows. **No accounting logic was changed.**

## Evidence labels

Confirmed from code | Confirmed from schema artifact | Strong inference | Needs database confirmation | Needs business confirmation | Not found

---

## 1. Executive architecture

Two separate general-ledger stacks exist:

| Stack | COA table | Journal tables | Create/post API | Used by |
|-------|-----------|----------------|-----------------|---------|
| Cleaning / SM | `chart_of_accounts` | `gl_journals`, `gl_journal_lines`, `gl_account_balances` | `gl_create_journal()`, `gl_reverse_journal()` in `includes/gl_posting.php` | Cleaning accounts, SM prepaid/recurring, cleaning payroll |
| Shared engine | `re_chart_of_accounts` | `re_journal_headers`, `re_journal_lines`, `re_general_ledger` | `create_journal_entry()`, `post_journal()`, `create_and_post_journal()`, `reverse_journal()` in `modules/realestate/accounting/accounting_engine.php` | Real Estate, Construction, ARS, non-cleaning payroll, ERP expenses |

Domain wrappers:

- RE: `modules/realestate/accounting/accounting_integration.php`
- Construction: `modules/construction/includes/construction_accounting_integration.php`
- ARS: `modules/ars/includes/ars_accounting.php`
- Payroll: `hr/includes/hr_payroll_accounting.php`

---

## 2. Chart of accounts

| Stack | Structure | Evidence |
|-------|-----------|----------|
| Cleaning | `chart_of_accounts`; lookup via `coa_id()` in `gl_posting.php`; UI `accounts/coa.php` | Confirmed from code |
| Shared | `re_chart_of_accounts` unique on `(company_id, account_code)`; seeds in migrations | Confirmed from schema artifact / code |
| Common shared codes (RE/CO) | AR **1310**, Bank **1210**, Cash **1110**, Output VAT **2310**, Input VAT **2130** (CO) / **2320** (RE report), Deferred **2410**, Security deposit liability **2200** | Confirmed from code |

**Needs database confirmation:** Every live company has a complete seeded COA.

---

## 3. Company and ledger separation

| Mechanism | Detail | Evidence |
|-----------|--------|----------|
| Company master | `companies.business_type` | Confirmed from code |
| Cleaning isolation | `includes/cleaning_accounting_context.php` resolves cleaning company; `/accounts` is Cleaning books | Confirmed from code |
| Shared isolation | `company_id` on `re_journal_headers` / COA / most source docs | Confirmed from schema artifact / code |
| Payroll routing | Cleaning → standalone; else → shared (exclusive, not dual-write) | Confirmed from code |

### 3.1 `company_id` verification on shared `re_*` engine

| Check | Verdict | Evidence |
|-------|---------|----------|
| Required on journal create | **Yes** — empty `companyId` returns error in `create_journal_entry()` | Confirmed from code |
| Schema NOT NULL | **Yes** on `re_journal_headers.company_id` | Confirmed from schema artifact |
| Populated on header/lines/GL | **Yes** on create/post path | Confirmed from code |
| Filtered on account lookup | **Yes** — `get_account` / `find_account_by_code` take company | Confirmed from code |
| Validated on `post_journal` / `reverse_journal` against caller company | **No** — engine loads by journal id only; company comes from stored header. UI `journal_entry_view.php` filters by company before reverse | Confirmed from code |
| Protected in financial reports | **Yes** — P&L/TB/BS/VAT/day book filter `company_id` | Confirmed from code |
| Protected in invoice-mode allocations | **Yes** — allocation SQL includes `company_id` | Confirmed from code |
| Protected in all legacy allocation reads | **Partial** — some `re_payment_allocations` SUM queries use `payment_id` only | Confirmed from code |
| Session fallback risk | Many pages use `current_company_id($conn) ?: 1` | Confirmed from code / Strong inference |
| DB unique on `(company_id, reference_type, reference_id)` | **Not present** — commented optional unique in `migrations/accounting_critical_controls.sql`; app-level check only | Confirmed from schema artifact |

**Conclusion:** `company_id` is required and generally populated/filtered, but is **not** a complete isolation guarantee. Engine reverse/post by id, session fallbacks, and missing DB unique on references remain residual risks.

---

## 4. Journal header / line structure (shared engine)

| Table | Role | Evidence |
|-------|------|----------|
| `re_journal_headers` | Header: company, type, reference_type/id, posted/reversed flags, totals | Confirmed from schema artifact |
| `re_journal_lines` | Dr/Cr lines with account_id | Confirmed from schema artifact |
| `re_general_ledger` | Posted GL rows (used by reports & bank reco flags) | Confirmed from code |
| `re_journal_sequences` | Journal numbering per company | Confirmed from code |
| `re_accounting_audit_log` | Engine audit | Confirmed from code |

Cleaning equivalent: `gl_journals` + `gl_journal_lines` + `gl_account_balances` (auto-posted on create). Confirmed from code.

---

## 5. Posting workflow and status

### Shared engine

1. `create_journal_entry` — unposted header+lines; period lock; duplicate check (with skips)  
2. `post_journal` — writes `re_general_ledger`, sets `is_posted=1`  
3. `create_and_post_journal` — create then post  
4. `reverse_journal` — creates reversal journal (swapped Dr/Cr), marks original `is_reversed=1`

### Cleaning

1. `gl_create_journal` — inserts posted journal immediately (`is_posted=1`)  
2. `gl_reverse_journal` — creates reversal; blocks double-reversal / reversing a reversal

---

## 6. Duplicate-posting prevention (exact inventory)

| Location | Guard | Gap? | Evidence |
|----------|-------|------|----------|
| `journal_duplicate_exists()` | Posted, non-reversed, non-reversal row for `(company_id, reference_type, reference_id)` | App-level only; **no DB unique** | Confirmed from code / schema artifact |
| `create_journal_entry` skip list | Skips check for `journal_type=reversal` OR `reference_type IN ('recurring_journal','credit_note')` | Intentional multi-post for those types | Confirmed from code |
| Credit notes | `post_credit_note_to_accounting` uses `reference_id=0` + skip | Weak source link; unlimited CNs | Confirmed from code |
| `gl_create_journal` | **None** on `(source, source_id)` | Duplicate invoices/receipts possible; health/fix scripts exist | Confirmed from code |
| `gl_post_invoice` / `gl_post_receipt` | No refuse-if-exists | Same | Confirmed from code |
| Invoice-mode receipt | Explicit pre-check + engine | Stronger | Confirmed from code |
| Payroll | `hr_payroll_existing_journal_id` / shared equivalent + engine | Present | Confirmed from code |
| SM amortize | Row status `pending`→`posted` | `gl_create_journal` still has no source key | Confirmed from code |
| SM recurring | Advances `next_run_date` only | No source dup guard in `gl_create_journal` | Confirmed from code |

**Do not fix in this task** — document only.

---

## 7. Priority workflow deep traces

### 7.1 Real-estate invoices

| Step | Detail | Evidence |
|------|--------|----------|
| UI / API | Invoice Mode: `accounting/invoice_preview.php` → `invoice_generate.php`; also auto-issue from receipt allocation. Legacy: `billing_invoice_create.php` | Confirmed from code |
| Processing | `invoice_engine.php` (`re_invoice_engine_issue_candidate_for_lease`) | Confirmed from code |
| Helper | `post_invoice_to_accounting($invoiceId, $companyId, $createdBy)` | Confirmed from code |
| Tables | `re_invoices`, `re_invoice_items`; Invoice Mode also `re_invoice_candidates`, `re_obligations` | Confirmed from code |
| Journal | `create_and_post_journal(..., 'invoice', 'invoice', $invoiceId, ...)` — Dr 1310; Cr income; Cr 2310 VAT | Confirmed from code |
| Source link | `reference_type='invoice'`, `reference_id=invoiceId` | Confirmed from code |
| Duplicate | Engine + Invoice Mode `invoice_key` / obligation guards | Confirmed from code |
| Reversal | `re_invoice_engine_void_unpaid_invoice` → `reverse_journal` (unpaid, no allocations) | Confirmed from code |
| Reports | P&L, TB, BS, VAT, exception dashboard | Confirmed from code |
| company_id | Invoice load `WHERE id=? AND company_id=?` | Confirmed from code |

**Event matrix (RE invoice)**

| Event | Supported? | Notes | Evidence |
|-------|------------|-------|----------|
| Draft | Yes | Candidates / draft states in invoice mode | Confirmed from code |
| Approval | Partial / mode-dependent | Needs business confirmation for all modes | Needs business confirmation |
| Posting | Yes | `post_invoice_to_accounting` | Confirmed from code |
| Partial settlement | Yes | Via receipts/allocations | Confirmed from code |
| Full settlement | Yes | Outstanding → paid | Confirmed from code |
| Overpayment | Via receipt path | Tenant credit / 2410 | Confirmed from code |
| Cancellation / void | Unpaid void path | Paid invoices restricted | Confirmed from code |
| Reversal | Via `reverse_journal` on void | Confirmed from code |
| Refund | Separate refund flows | See §7.7 | Confirmed from code |
| Reopening | Not found as first-class | Not found |

---

### 7.2 Tenant receipts / payments

#### Legacy (`payment_add.php`)

| Step | Detail | Evidence |
|------|--------|----------|
| UI | `modules/realestate/payment_add.php` (Invoice Mode leases redirect) | Confirmed from code |
| Tables | `re_payments`; optional `re_payment_allocations` / billing allocations; tenant credit tables | Confirmed from code |
| Router | Billing alloc → `post_payment_with_billing_allocations_to_accounting`; deferred mode → `post_deferred_payment_to_accounting`; else → `post_payment_to_accounting` | Confirmed from code |
| Journal | `journal_type='payment'`, `reference_type='payment'`, `reference_id=$paymentId` | Confirmed from code |
| Lines | Dr Bank/Cash; Cr 1310 (and/or 2410 overpay / apply credit) | Confirmed from code |
| Duplicate | Engine-level only (no extra pre-check in simple post) | Confirmed from code |
| Reversal | Delete/edit paths call `reverse_journal` / `reverse_and_repost_payment` | Confirmed from code |

#### Invoice Mode (`receipt_allocation`)

| Step | Detail | Evidence |
|------|--------|----------|
| UI | `accounting/receipt_allocation.php` → `receipt_allocation_confirm.php` | Confirmed from code |
| Processing | `re_receipt_confirm` in `receipt_allocation_engine.php` | Confirmed from code |
| Helper | `post_invoice_mode_receipt_to_accounting` | Confirmed from code |
| Tables | `re_payments` (`accounting_mode='invoice'`), `re_receipt_allocations` | Confirmed from code |
| Duplicate | Explicit pre-check + engine | Confirmed from code |
| Dedicated void | Not found | Not found |

---

### 7.3 Payment allocation

| Mode | Tables | GL | company_id | Evidence |
|------|--------|-----|------------|----------|
| Legacy installment | `re_payment_allocations` | Folded into payment journal | Insert includes company; some SUM by payment_id only | Confirmed from code |
| Legacy billing item | `re_billing_item_payment_allocations` | Via billing-alloc post helper | Filtered in post helpers | Confirmed from code |
| Invoice Mode | `re_receipt_allocations` (invoice/obligation/tenant_credit) | Via invoice-mode receipt post | Consistently filtered | Confirmed from code |

---

### 7.4 Overpayments and tenant credit

| Path | Mechanism | Evidence |
|------|-----------|----------|
| Legacy overpay | `update_tenant_credit(..., 'credit')`; GL Cr **2410** in payment post | Confirmed from code |
| Apply credit | Debit tenant credit; GL Dr **2410** / Cr **1310** via payment or `apply_tenant_credit_to_invoice_accounting` | Confirmed from code |
| Invoice Mode remainder | `target_type='tenant_credit'` allocation + Cr 2410 | Confirmed from code |
| Tables | `re_tenant_credit_balances`, `re_tenant_credit_transactions` | Confirmed from code |
| Report | `unearned_revenue_report.php` | Confirmed from code |

---

### 7.5 Deferred / unearned revenue

| Stage | Detail | Evidence |
|-------|--------|----------|
| Seed | `seed_recognition_schedule_for_lease` → `re_rent_recognition_schedule` | Confirmed from code |
| Cash in | `post_deferred_payment_to_accounting`: Dr Bank, Cr **2410** | Confirmed from code |
| Recognize | UI `revenue_recognition.php` → `process_revenue_recognition`: Dr 2410, Cr income (+ VAT); `reference_type='recognition_schedule'` | Confirmed from code |
| Duplicate | One journal per schedule row via engine | Confirmed from code |
| Un-recognise | Dedicated path Not found | Not found |
| Construction deferred rent | Account **2215**; report `modules/construction/reports/deferred_rent_recognition.php` | Confirmed from code |

---

### 7.6 Security deposits

| Path | Helper | Journal | Evidence |
|------|--------|---------|----------|
| Lease create | `post_security_deposit_to_accounting` | Dr Bank, Cr **2200**; `reference_type='lease'` | Confirmed from code |
| Invoice Mode obligation alloc | `re_sd_post_liability_for_receipt_allocation` | Dr Bank, Cr **2200**; `reference_type='security_deposit_receipt'` | Confirmed from code |
| Idempotency (path B) | `re_security_deposit_audit` action `liability_posted` | Confirmed from code |
| Move-out / refund | `re_sd_process_move_out_refund` / `post_deposit_refund_to_accounting` | Dr 2200, Cr Bank (+ income for deductions) | Confirmed from code |

**Strong inference risk:** Invoice Mode receipt posts Bank + AR for obligation allocations **and** SD helper may also Dr Bank / Cr 2200 for the same SD portion → possible double Bank / wrong AR. Needs business + database confirmation of live behaviour.

---

### 7.7 Credit notes and refunds

#### Credit notes (RE)

| Step | Detail | Evidence |
|------|--------|----------|
| UI | `accounting/credit_note_add.php` | Confirmed from code |
| Helper | `post_credit_note_to_accounting` | Confirmed from code |
| Journal | Dr income 4110, Cr 1310; `reference_type='credit_note'`, **`reference_id=0`** | Confirmed from code |
| Duplicate | **Skipped** by engine for credit_note | Confirmed from code |
| Subledger | Updates `re_invoices.outstanding_amount`; tenant ledger | Confirmed from code |
| CN-specific reverse | Not found | Not found |

#### Tenant credit refunds

| Step | Detail | Evidence |
|------|--------|----------|
| UI | `lease_view.php` refund action | Confirmed from code |
| Intended helper | `post_refund_to_accounting` — Dr 2410, Cr Bank | Confirmed from code |
| Defect | Calls `create_journal_entry($companyId, [array...])` but signature is `($companyId, $journalType, $referenceType, $referenceId, $lines, ...)`. Same pattern in `post_penalty_payment_to_accounting` (~lines 2137, 2235) | Confirmed from code |
| Deposit refunds | Prefer SD helpers using `create_and_post_journal` | Confirmed from code |

#### Cleaning credit notes / refunds

| Area | Paths | Evidence |
|------|-------|----------|
| Credit notes | `accounts/credit_note_*.php`; tables `credit_notes*` | Confirmed from code |
| Refunds | `accounts/refunds.php` | Confirmed from code |

---

### 7.8 PDC clearance, bounce, replacement

| Event | Entry | Subledger | GL | Evidence |
|-------|-------|-----------|----|----------|
| Helper | `cheque_lifecycle_helper.php` | Status + `re_cheque_lifecycle_audit` | **No GL in helper** | Confirmed from code |
| Clear — Invoice Mode | Cheque view → receipt allocation → `post_invoice_mode_receipt_to_accounting` | Payments + allocations + cheque cleared | Yes | Confirmed from code |
| Clear — Legacy | `billing_cheque_view.php` creates `re_payments`, marks installment paid | Yes | **No automatic `post_*` call** | Confirmed from code |
| Bounce | `re_cheque_update_status(..., 'bounced')`; penalty billing item | Penalty item | GL only when penalty later collected | Confirmed from code |
| Replace | New PDC row + replaced links | Cheques | No GL until receipt | Confirmed from code |
| Construction shop PDCs | `co_shop_rent_cheques` | Separate lifecycle | Needs deeper business confirmation for GL | Confirmed from schema artifact / Needs business confirmation |

---

### 7.9 Construction expenses and vendor payments

**Suppliers/AP program status:** Phases 0–4 **COMPLETE — APPROVED (2026-07-27)**. Production checklist: [`docs/construction/CO_SUPPLIERS_AP_PROGRAM_COMPLETE.md`](../construction/CO_SUPPLIERS_AP_PROGRAM_COMPLETE.md). Confirmed rules: [`docs/business-rules/CO_SUPPLIERS_AP.md`](../business-rules/CO_SUPPLIERS_AP.md).

| Document | UI | Helper | Journal pattern | Source link | Reversal | Evidence |
|----------|----|--------|-----------------|-------------|----------|----------|
| Supplier invoice | `supplier_invoice_*.php` | `co_post_supplier_invoice_to_accounting` | Dr expense/CIP/COGS + Dr 2130 **remaining** Input VAT; Cr 2110 for `total − linked advance VAT` | `co_supplier_invoice` | Void / Void+Amend (unpaid) → `reverse_journal`; draft delete | Confirmed from code (Phases 0–4) |
| Supplier payment | `supplier_payment_*.php` | `co_post_supplier_payment_to_accounting` | Dr 2110 (allocated) + Dr Advances asset (remainder); Cr bank | `co_supplier_payment` | Guarded reverse (block if apps / Advance VAT / refunds remain) | Confirmed from code |
| Allocations | — | `co_supplier_payment_allocations` | Not separate GL | — | — | Confirmed from code |
| Advance apply | Invoice view | `co_supplier_apply_advance` | Dr 2110 / Cr Advances | `co_supplier_advance_applications` | Unapply reverses JE | Confirmed from code |
| Advance VAT | `supplier_advance_vat_*.php` | `co_supplier_post_advance_vat_document` | Dr 2130 / Cr Advances | `co_supplier_advance_vat_documents` | Reverse blocked if invoice links remain | Confirmed from code |
| Advance refund | `supplier_advance_refund_*.php` | `co_supplier_post_advance_refund` | Dr bank / Cr Advances | `co_supplier_advance_refunds` | Reverse; refund blocked while Advance VAT posted | Confirmed from code |
| Contractor payment | `contractor_payment_add.php` | `co_post_contractor_payment_to_accounting` | Dr CIP/COGS; Cr bank; Cr retention 2125 | `co_contractor_payment` | — | Confirmed from code |
| Project cost | `project_cost_add.php` | `co_post_project_cost_to_accounting` | Dr CIP/COGS; Cr bank | `co_project_cost` | — | Confirmed from code |
| ERP expenses / Quick Paid | `expenses.php` / `expense_*.php` | `erp_post_expense` | Construction Quick Paid **COMPLETE — APPROVED (2026-07-27):** Dr expense + Dr **2130** / Cr bank\|cash\|credit; AP prohibited. Legacy `legacy_archive=1` editable since 2026-09-26 (save reverses + reposts). Optional informational `co_supplier_id`; optional `project_id` for project cost. See [`CO_QUICK_PAID_EXPENSES_COMPLETE.md`](../construction/CO_QUICK_PAID_EXPENSES_COMPLETE.md). | `erp_expense` | Cancel/repost allowed for legacy since 2026-09-26 | Confirmed |

**COA (Construction Suppliers):** Payable **2110**, Input VAT **2130**, Supplier Advances asset default **1410** (`settings.co_supplier_advance_account_code`). Do not use **2410** (client advances).

**Lifecycle:** `Draft → Posted → Partially Paid → Paid → Voided`. Posted financial values are immutable; corrections via Void / Void+Amend.

**Note:** Contractor payment can save even if GL post fails (error shown, payment row retained). Confirmed from code / Strong inference from helper behaviour.

**Duplicate / idempotency:** Invoice and payment post paths early-return when `journal_id` already set (Phase 0 harden). Confirmed from code.

---

### 7.10 Construction bank reconciliation

| Action | Side effect | Evidence |
|--------|-------------|----------|
| Match confirm | Sets `re_general_ledger.is_reconciled` flags — **no new journal** | Confirmed from code |
| Create / cash coding / transfer | `co_bank_reco_create_transaction` → `create_and_post_journal(..., 'manual', 'co_bank_reconciliation', $lineId, ...)` | Confirmed from code |
| Undo create/adjust/transfer | `reverse_journal` on `created_transaction_id` | Confirmed from code |
| Tables | `co_bank_statement_lines`, `co_reconciliation_matches`, `co_bank_reco_audit`, `re_bank_accounts` | Confirmed from code |
| company_id | Scoped on lines/accounts/GL | Confirmed from code |

Parallel stacks also exist for Cleaning (`cleaning_bank_*`) and RE (`re_bank_reconciliation_*`). Confirmed from code.

---

### 7.11 Payroll posting (both GL stacks — exclusive routing)

| Step | Detail | Evidence |
|------|--------|----------|
| UI | `hr/payroll_run_build.php` finalize/post | Confirmed from code |
| Helper | `hr_payroll_post_accounting` in `hr_payroll_accounting.php` | Confirmed from code |
| Router | `business_type === 'cleaning'` → `gl_create_journal`; else → `create_and_post_journal` | Confirmed from code |
| Source link | Cleaning: `source='payroll'`, `source_id=runId`; Shared: `reference_type='payroll'` | Confirmed from code |
| Duplicate | Existing journal id checks + engine (shared) | Confirmed from code |
| Dedicated reverse | Not found in payroll helper | Not found |
| Important | Posts to **one** stack per company — not simultaneous dual-write | Confirmed from code |

---

### 7.12 VAT

| Module | Posting | Report | Evidence |
|--------|---------|--------|----------|
| RE | Output **2310** on invoice post; config `re_vat_config` | `vat_report.php` scans GL 2310/2320 | Confirmed from code |
| Construction | Input **2130**, Output **2310** | `construction_vat_report.php` from **source documents** | Confirmed from code |
| Cleaning | Invoice/expense VAT fields | `accounts/report_vat.php` from invoices/expenses | Confirmed from code |

**Risks:** RE VAT report input account **2320** vs Construction post **2130**; RE report totals using credit amounts for input may understate recoverable VAT. Confirmed from code / Strong inference.

---

### 7.13 Recurring journals and prepaid expenses

| Feature | Stack | Entry | Post | Duplicate | Evidence |
|---------|-------|-------|------|-----------|----------|
| RE recurring | Shared | `modules/realestate/accounting/recurring_journals.php` | `create_and_post_journal` ref `recurring_journal` | **Skipped** by design | Confirmed from code |
| SM recurring | Cleaning | `accounts/recurring_journals.php`; run via `sm_recurring_run_due` / prepaid cron | `gl_create_journal` | Relies on `next_run_date`; no source dup | Confirmed from code |
| SM prepaid purchase | Cleaning | Expense add/edit | `sm_gl_post_prepaid_expense` | Caller-side | Confirmed from code |
| SM amortize | Cleaning | `accounts/prepaid_schedules.php` | `sm_prepaid_run_amortization` | Row status | Confirmed from code |

**UI gap:** SM recurring list query not company-filtered though insert sets `company_id`. Confirmed from code.

---

## 8. Accounts receivable / payable control

| Control | Cleaning | Shared RE/CO | Evidence |
|---------|----------|--------------|----------|
| AR | `invoices` + `receipts` + `receipt_allocations` + `gl_*` | `re_invoices` / obligations + payments + 1310 | Confirmed from code |
| AP | Expenses / credit notes / SM | Construction supplier invoices 2110; ERP expenses | Confirmed from code |
| Ageing views | `v_ar_*` | RE tenant statements / outstandings | Confirmed from schema artifact / code |

---

## 9. Financial reports (source queries)

| Report | Stack | Primary source | Evidence |
|--------|-------|----------------|----------|
| RE P&L / TB / BS / GL / day book | Shared | `re_general_ledger` / headers | Confirmed from code |
| RE VAT | Shared | GL 2310/2320 | Confirmed from code |
| Unearned revenue | Shared | Tenant credit + GL 2410 | Confirmed from code |
| Construction VAT | Shared | Source docs + journal_id | Confirmed from code |
| Construction bank reco report | Shared flags | Matches + GL reconciled | Confirmed from code |
| Cleaning VAT | Cleaning | invoices/expenses | Confirmed from code |
| Cleaning AR views | Cleaning | `v_ar_*` | Confirmed from schema artifact |

---

## 10. Accounting risks summary

1. **Two GL stacks** — wrong helper / wrong `business_type` can post to the wrong books.  
2. **No DB unique** on shared journal references — race can bypass `journal_duplicate_exists`.  
3. **`gl_create_journal` has no source duplicate refuse** — Cleaning duplicate invoice/receipt journals historically addressed by repair tools.  
4. **Credit notes** use `reference_id=0` and skip duplicate check.  
5. **Legacy PDC clear** creates payment without GL post.  
6. **`post_refund_to_accounting` / `post_penalty_payment_to_accounting`** call `create_journal_entry` with wrong signature.  
7. **Invoice Mode SD + receipt** possible double Bank posting (Strong inference).  
8. **Invoice Mode receipt void** path Not found.  
9. **`company_id` session fallback to 1** and reverse-by-id without caller company re-check.  
10. **VAT report account/code inconsistencies** across modules.  
11. **Contractor payment** may persist without successful GL.  
12. **SM recurring list** not company-scoped.

---

## 11. Historical data compatibility

| Topic | Finding | Evidence |
|-------|---------|----------|
| Dual RE payment modes | Legacy installment vs Invoice Mode coexist | Confirmed from code |
| Dual cheque tables | `re_lease_cheques` and `re_post_dated_cheques` | Confirmed from schema artifact |
| Cleaning payment duality | `order_payment` vs `receipts` | Confirmed from code |
| Repair tooling | `accounts/fix_duplicate_invoice_journals.php`, accounting health service | Confirmed from code |
| Live vs dump drift | Possible | Needs database confirmation |

---

## 12. Petty cash

Dedicated petty-cash module / float vouchers: **Not found**. COA accounts named Petty Cash exist in seeds. Confirmed from schema artifact / Not found as module.

---

## 13. Items requiring human confirmation

1. Confirm live behaviour of Invoice Mode security-deposit + receipt double-post hypothesis.  
2. Confirm whether legacy PDC-clear-without-GL is intentional or a defect.  
3. Confirm whether `post_refund_to_accounting` is reachable in production UI today.  
4. Confirm VAT input account standard (2130 vs 2320).  
5. Confirm fiscal period lock usage in all companies.  
6. Confirm live COA completeness per company.

---

## 14. Explicit non-changes

No posting logic, journals, or schema were modified. Defects are documented only.
