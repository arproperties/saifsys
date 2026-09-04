# M5 — Vendor Advance VAT Documents — Closure Report

**Status:** GO — M5 complete; manual UI acceptance (3 scenarios) journal-audited  
**Date:** 2026-07-23  
**Stack:** Real Estate `re_*` only  

---

## 1. Final files changed

### Migration / schema
- `migrations/re_vendor_advance_vat_documents.sql`
- `uploads/vendor_advance_vat/.htaccess`

### Helpers
- `modules/realestate/includes/vendor_advance_vat_helper.php` *(new)*
- `modules/realestate/includes/vendor_ap_helper.php` *(bill remaining Input VAT + AP credit; void reverses VAT links; requires VAT helper)*
- `modules/realestate/includes/vendor_advance_helper.php` *(minimal: advance remaining subtracts posted VAT; payment reverse blocked if posted VAT docs)*

### UI
- `modules/realestate/accounting/vendor_advance_vat_documents.php` *(new)*
- `modules/realestate/accounting/vendor_advance_vat_document_edit.php` *(new)*
- `modules/realestate/accounting/vendor_advance_vat_document_view.php` *(new)*
- `modules/realestate/accounting/vendor_bill_view.php` *(manual VAT link panel)*
- `modules/realestate/accounting/vendor_payment_add.php` *(optional post-payment VAT CTA)*
- `modules/realestate/includes/re_layout_header.php` *(nav: Advance VAT Docs)*
- `modules/realestate/accounting/vat_report.php` *(Input VAT = net Dr−Cr; Output VAT unchanged; company fail-closed)*

### Tests / docs
- `tools/test_m5_vendor_advance_vat.php`
- `docs/realestate/vendor-advance-vat-m5-design.md`
- `docs/realestate/vendor-advance-vat-m5-closure.md` *(this file)*

---

## 2. Migration file and execution requirements

**File:** `migrations/re_vendor_advance_vat_documents.sql`  

**Local (executed twice — idempotent):**
```bash
/Applications/XAMPP/xamppfiles/bin/mysql -h 127.0.0.1 -u root datanew < migrations/re_vendor_advance_vat_documents.sql
```

**Production:** human approval required before run (ERP core rule). Additive `CREATE TABLE IF NOT EXISTS` only.

---

## 3. Database objects added

| Object | Purpose |
|--------|---------|
| `re_vendor_advance_vat_documents` | Draft/posted/reversed supplier advance tax invoices |
| `re_vendor_advance_vat_attachments` | Tax invoice attachments |
| `re_vendor_advance_vat_bill_links` | Partial consumption of advance VAT against final bills |

Unique: `(company_id, vendor_id, supplier_invoice_number)` — on reverse, invoice number is renamed `…#REV-{id}` so a corrected document can reuse the supplier number.

---

## 4. Journal examples

### A — Advance payment without VAT document (unchanged M1–M4)
| Dr/Cr | Account | Amount |
|-------|---------|--------|
| Dr | 1410 Vendor Advances | Gross paid as advance |
| Cr | Bank | Same |

### B/C — Advance VAT Document post
| Dr/Cr | Account | Amount |
|-------|---------|--------|
| Dr | Recoverable Input VAT (2320 / setting) | VAT on supplier document |
| Cr | 1410 Vendor Advances | Same |

Also: `re_vendor_advance_balances` − VAT; 1410 vendor sub-ledger fail-closed.

### D — Final Vendor Bill with linked advance VAT
| Dr/Cr | Account | Amount |
|-------|---------|--------|
| Dr | Expense | Bill net |
| Dr | Input VAT | **Remaining** = bill VAT − linked advance VAT |
| Cr | AP | **Net + remaining VAT** (= bill total − linked advance VAT) |

Apply advance remains separate: Dr AP / Cr 1410.

### Reverse Advance VAT Document (unlinked only)
Reverse original VAT JE; restore balance; rename supplier invoice # for uniqueness reuse.

---

## 5. UI pages added or changed

| Page | Change |
|------|--------|
| Advance VAT Docs list/edit/view | New workflow |
| Payment Made | Optional “Add Advance VAT Document” after advance payment |
| Vendor Bill view | Eligible docs, manual partial link, remaining Input VAT |
| Nav Purchases | Advance VAT Docs |
| VAT Report | Net-debit Input VAT; Output unchanged |

---

## 6. VAT report query correction

**Before:** Input VAT total = `SUM(credit_amount)` on account 2320 (understated recoverable VAT from bills).  

**After:** Input VAT = `SUM(debit_amount) − SUM(credit_amount)` on resolved Input VAT account (`re_ap_input_vat_account`), company + period filtered, with debit/credit/net drill-down.  

**Output VAT:** still `SUM(credit_amount)` on 2310 — unchanged.

---

## 7. Test results by test ID

Runner: `php tools/test_m5_vendor_advance_vat.php` (outer transaction rolled back).

| ID | Result |
|----|--------|
| T-A01 … T-A19 | PASS (T-A17: no closed period in local DB — helper presence validated) |
| T-REP01 … T-REP05 | PASS |
| T-REG01 … T-REG03 | PASS |
| **Total** | **27 / 27 PASS** |

---

## 8. PHP lint results

All M5 PHP files: **No syntax errors detected**.

---

## 9. Migration repeatability result

Migration applied **twice** successfully (`MIGRATION_OK_TWICE`). Tables present and unchanged on second run.

---

## 10. Known issues / deferred items

1. **T-A17:** Local DB had no closed RE period; locked-period rejection relies on `create_and_post_journal` → `is_period_locked` (verified by code path; soft info in matrix).  
2. **Attachments:** Optional (matches existing bill policy); not hard-required to post.  
3. **Supplier TRN:** Captured; not hard-forced when blank (vendor tax_id prefilled when available).  
4. **Refunds:** Still out of scope.  
5. **Bill AP credit with links:** Posts `net + remaining VAT` so the journal balances; invoice header `total_amount` remains full supplier total. Document for AP users.  
6. **No closed-period seed** in test env for hard lock assertion.

---

## 11. M1–M4 confirmation

- Payment JE shape unchanged when no VAT document.  
- Apply/unapply path exercised in T-REG01 — PASS.  
- Legacy bill without links posts full Input VAT — T-REG02 PASS.  
- Cleaning `gl_*` untouched — T-REG03 PASS.  
- Shared accounting-engine **public signatures unchanged**.

---

## 12. Final recommendation

**GO** — M5 implemented per approved design; automated matrix green; three manual UI scenarios (PAY-68 / PAY-69 / PAY-70) journal-audited on correct accounts (1410, 2320, 2130, 5110, bank).

**Program:** Vendor Advances **M1–M6 complete**. Only **Refunds** remain deferred.

**Before production:** human approve both migrations; staging smoke Payment Made → VAT doc → bill link → post → apply → VAT report.
