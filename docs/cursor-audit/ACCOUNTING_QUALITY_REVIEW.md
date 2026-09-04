# Accounting Quality Review

**Stage:** 1.5 — Architecture quality (read-only)  
**Date:** 2026-07-10  
**Stance:** Invoice Mode is the **official** Real Estate accounting model. Legacy lease accounting is **historical architecture only** — do not extend; do not remove in this audit.

## Evidence labels

Confirmed from code | Confirmed from schema artifact | Strong inference | Needs database confirmation | Needs business confirmation | Not found

---

## 0. Official vs historical Real Estate accounting

| Layer | Status | Key paths | Recommendation |
|-------|--------|-----------|----------------|
| **Invoice Mode** | Current architecture | `receipt_allocation_engine.php`, `invoice_engine.php`, `post_invoice_mode_receipt_to_accounting`, obligations, `re_receipt_allocations`, `accounting_mode='invoice'` | Invest all new RE accounting work here |
| **Legacy lease accounting** | Historical | `payment_add.php`, `post_payment_to_accounting`, `post_deferred_payment_to_accounting`, `re_payment_allocations`, installments as AR, `accounting_mode='legacy'` / default coalesce | Keep for backward compatibility of historical leases; **do not extend**; future deprecation candidate after data migration/read-only freeze |
| Mode policy | Confirmed from code | `includes/accounting_mode_helper.php` — defaults for new/renewal leases = `invoice`; feature flag `re_accounting_invoice_mode_enabled` | Treat Invoice Mode as product standard |

**Still referenced (legacy):** `payment_add.php`, `lease_payments_manage.php` (legacy actions blocked for invoice leases), `outstandings_report.php` (combined legacy + invoice), `post_payment_*` / deferred helpers, installment outstanding queries. Confirmed from code.

**Do not recommend removing legacy code during this audit.**

---

## 1. Duplicated accounting logic catalogue

Difficulty scale: **S** = small (days), **M** = medium (1–3 weeks), **L** = large (multi-sprint), **XL** = multi-month / cross-module.

### 1.1 Duplicated journal creation / posting

| Locations | Risk | Recommendation | Difficulty |
|-----------|------|----------------|------------|
| Shared: `create_journal_entry` / `create_and_post_journal` in `accounting_engine.php` | Canonical for RE/CO/ARS — good | Keep as single JournalService core | — |
| Cleaning: `gl_create_journal` in `gl_posting.php` | Parallel stack by design | Keep separate; document boundary; do not merge stacks hastily | XL to unify |
| Domain wrappers call engine with inconsistent APIs (`post_refund_to_accounting` wrong signature) | Broken / silent failure risk | Fix signature to use `create_and_post_journal` (future change — not this stage) | S |
| Construction `co_post_*` + RE `post_*` + ARS `ars_accounting` + ERP expense posting | Multiple “build lines then post” patterns | Extract shared line-builder + posting facade over engine | L |

### 1.2 Duplicated VAT calculations

| Locations | Risk | Recommendation | Difficulty |
|-----------|------|----------------|------------|
| RE invoice post (2310), lease VAT calculator, `re_vat_config` | Drift between lease calc and journal | Single VATService used by invoice issue + reports | M |
| Construction supplier/client VAT (2130/2310) | Different input code vs RE report 2320 | Standardize chart codes + VATService config per company | M |
| Cleaning invoice/expense VAT fields + `report_vat.php` | Document-based vs GL-based reports diverge | Align report method per stack; document which is statutory | M |
| Bank reco VAT splits (CO) | Ad-hoc VAT on cash coding | Route through VATService | M |

### 1.3 Duplicated allocation logic

| Locations | Risk | Recommendation | Difficulty |
|-----------|------|----------------|------------|
| **Invoice Mode:** `re_receipt_allocations` + `receipt_allocation_engine.php` | Official path | Canonical AllocationService | — |
| **Legacy:** `re_payment_allocations` + `payment_allocation_helper.php` | Historical; still used for legacy leases | Freeze; no new features; read-only when possible | M (freeze) |
| Billing-item allocations: `re_billing_item_payment_allocations` + `post_payment_with_billing_allocations_to_accounting` | Overlaps legacy/penalty paths | Map penalties into Invoice Mode obligations; stop extending billing-alloc GL path | M |
| Construction: `co_supplier_payment_allocations`, `co_client_payment_allocations` | Separate AP/AR alloc | Keep domain-specific; share Allocation primitives (amount apply, outstanding update) | L |

### 1.4 Duplicated tenant-credit logic

| Locations | Risk | Recommendation | Difficulty |
|-----------|------|----------------|------------|
| `update_tenant_credit` in `payment_allocation_helper.php` | Used by legacy + invoice remainder | Promote to TenantCreditService; single balance truth | M |
| GL overpay/apply in `post_payment_to_accounting` (legacy) vs `post_invoice_mode_receipt_to_accounting` + `apply_tenant_credit_to_invoice_accounting` | Two GL shapes for same liability 2410 | One credit apply/post API for Invoice Mode; legacy frozen | M |
| Refund of credit via broken `post_refund_to_accounting` | Refunds may fail | Repair under TenantCreditService | S |

### 1.5 Duplicated security-deposit logic

| Locations | Risk | Recommendation | Difficulty |
|-----------|------|----------------|------------|
| Lease-create `post_security_deposit_to_accounting` | Assumes cash at lease start | Prefer Invoice Mode obligation + receipt liability path for new leases | M |
| Invoice Mode `re_sd_post_liability_for_receipt_allocation` | Official receipt-based liability | Canonical SecurityDepositService | — |
| Move-out `re_sd_process_move_out_refund` vs `post_deposit_refund_to_accounting` | Two refund posters | Consolidate under SecurityDepositService | M |
| Interaction with invoice-mode receipt AR posting | Possible double Bank (Strong inference) | Single posting orchestrator for SD obligations | M |

### 1.6 Duplicated bank reconciliation

| Locations | Risk | Recommendation | Difficulty |
|-----------|------|----------------|------------|
| Cleaning: `includes/cleaning_bank_reconciliation.php` + `accounts/bank_reconciliation*` | Separate stack (correct for Cleaning GL) | Keep Cleaning reco on `gl_*` / cleaning tables | — |
| RE: `re_bank_reco_*` helpers + accounting UI | Shared GL | Extract ReconciliationService interface | L |
| Construction: `construction_bank_reconciliation.php` + near-clone ajax/UI | High duplication with RE | Share engine; company-scoped adapters | L |

### 1.7 Duplicated report calculations

| Locations | Risk | Recommendation | Difficulty |
|-----------|------|----------------|------------|
| RE financials from `re_general_ledger` vs Cleaning from docs/`gl_account_balances` | Expected dual-stack | ReportingService per stack with shared presentation | L |
| `outstandings_report.php` combines legacy installments + invoice AR | Correct for transition; confusing long-term | Split “Historical Legacy AR” vs “Invoice Mode AR”; default Invoice Mode | S–M |
| VAT reports: GL scan (RE) vs source docs (CO) vs invoices/expenses (Cleaning) | Statutory inconsistency risk | One VAT reporting policy per jurisdiction/company | M |
| AR views `v_ar_*` vs `*_optimized` | Formula drift | Single canonical view set | M |

### 1.8 Duplicated deferred / revenue recognition

| Locations | Risk | Recommendation | Difficulty |
|-----------|------|----------------|------------|
| Legacy deferred: `deferred_revenue_mode` + `post_deferred_payment_to_accounting` + schedule | Historical for legacy leases | Freeze; Invoice Mode recognizes via obligation → invoice issue | M (freeze) |
| Invoice Mode: obligations + invoice candidates + issue | Official recognition timing | DeferredRevenueService / RevenueRecognitionService on this path | — |
| Construction deferred rent report / 2215 | Parallel concept | Align naming/accounts with shared liability model | M |

---

## 2. Quality verdict (Chief Accountant + Architect)

| Criterion | Verdict |
|-----------|---------|
| Double-entry foundation | Present on both stacks |
| Official RE model clarity | Invoice Mode is clear in helpers/UI; legacy still coexists for history |
| Idempotency | Incomplete (especially Cleaning `gl_*`; shared lacks DB unique) |
| Isolation | `company_id` required but not fully hardened |
| Commercial product readiness of accounting core | Not yet — too many parallel paths and three bank-reco clones |

---

## 3. Explicit non-changes

No code modified. No legacy removal recommended in this stage.
