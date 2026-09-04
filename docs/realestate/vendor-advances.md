# Real Estate Vendor Advances / Unapplied Payments (M1–M7)

**Status:** **COMPLETE** for M1–M7 (including M5 Advance VAT Documents and M7 Advance Refunds)  
**Stack:** Shared RE `re_*` only (Cleaning `gl_*` untouched)  
**Phase scope:** M1–M4 advances + M5 document-driven Input VAT + M6 verification + M7 refunds — **all done**

See also: `docs/realestate/vendor-advance-refunds-m7-closure.md`

---

## 1. Business purpose and user workflow

### Purpose

Allow RE companies to record vendor payments that are **not fully allocated to bills**, hold the unallocated portion as an **asset** (Vendor Advances / Prepayments), then **apply** that advance to open vendor bills later—without inventing credit notes or negative payments.

### End-user workflow

1. **Payment Made** (`vendor_payment_add.php`)  
   Select vendor → enter payment amount → allocate to open bills (FIFO or manual) → any remainder becomes **vendor advance**.  
   Fully allocated payments behave exactly as before (no advance).

2. **Apply advance** (`vendor_bill_view.php`)  
   On an open posted bill, enter apply amount (optional source payment IDs). Default consumption is **FIFO** by payment date / id among payments with remaining advance.

3. **Unapply** (`vendor_bill_view.php`)  
   Reverse a posted application before voiding the bill or reversing the source payment.

4. **Reverse payment** (`vendor_payments.php`)  
   Allowed only when the payment has **no** posted advance applications, **no** posted Advance VAT, **no** posted refunds, and **no** bill allocations. Unused advance balance on that payment is removed.

5. **Refund advance** (`vendor_advance_refund_add.php`)  
   Refund unused remaining advance to bank: **Dr Bank / Cr 1410**. Blocked while Advance VAT remains posted on that payment.

6. **Reports / lists**  
   SOA, AP Aging, Vendor Ledger, Payments list, and Vendors list show advance remaining / Gross–Advances–Net where applicable. Aging **buckets stay gross** (not netted). Remaining = Original − Applied − Advance VAT − Refunded.

---

## 2. Supported scenarios

| Scenario | Behaviour |
|----------|-----------|
| Fully allocated payment | `advance_amount = 0`; journal **Dr AP / Cr Bank**; no balance row change |
| Partial allocate + advance | Allocated → Dr AP; remainder → Dr 1410; Cr Bank = full payment |
| Full advance (100% unallocated) | Dr 1410 / Cr Bank; bill paid amounts unchanged |
| Apply | Dr AP / Cr 1410; one application row per source payment; bill paid/status refreshed |
| Unapply | Reverse application journal; restore advance balance; refresh bill |
| Payment reversal | Blocked if apps or allocations remain; else reverse payment JE and reduce unused advance |

---

## 3. Accounting journal entries

Account codes are settings-driven where noted; default advance account is **1410**.

### 3.1 Vendor payment – legacy (fully allocated)

| Dr/Cr | Account | Amount |
|-------|---------|--------|
| Dr | Accounts Payable (e.g. 2130) | Payment amount |
| Cr | Bank / Cash | Payment amount |

`reference_type = vendor_payment`, `reference_id = payment_id`.

### 3.2 Vendor payment – with advance

| Dr/Cr | Account | Amount |
|-------|---------|--------|
| Dr | Accounts Payable | Allocated to bills (if any) |
| Dr | **1410 Vendor Advances / Prepayments** | `advance_amount` |
| Cr | Bank / Cash | Full payment amount |

Also: increase `re_vendor_advance_balances.balance_aed` by `advance_amount`.  
Vendor sub-ledger entries posted for AP and/or 1410 with resolved `journal_line_id` (fail closed).

### 3.3 Apply vendor advance to bill

| Dr/Cr | Account | Amount |
|-------|---------|--------|
| Dr | Accounts Payable | Application slice |
| Cr | **1410** | Application slice |

- One journal per application row.  
- `reference_type = vendor_advance_application`, `reference_id = application_id` (unique; avoids engine duplicate-reference rule).  
- Decrease vendor advance balance by total applied.

### 3.4 Unapply vendor advance

- `reverse_journal` on the application journal.  
- Sub-ledger opposite entries on AP and 1410 (fail closed).  
- Restore advance balance; set application `status = reversed`; refresh bill.

### 3.5 Reverse original vendor payment

**Guards:** no posted applications on that payment; no bill allocations.

- `reverse_journal` on payment journal.  
- If unused advance remains on that payment: decrease advance balance; credit 1410 sub-ledger.  
- If payment had an allocated AP portion (and allocations were cleared first): AP sub-ledger credit as needed.  
- Payment `status = void`.

### 3.6 Not in this phase

| Event | Intended future JE | Status |
|-------|-------------------|--------|
| M5 Advance VAT documents | Document-driven Dr Input VAT / Cr 1410; bill posts remaining VAT; VAT report net-debit Input | **Implemented** — `vendor-advance-vat-m5-closure.md` |
| Vendor Advance Refund | Dr Bank / Cr 1410 | Documented only; not coded |

---

## 4. Database migration and schema

**File:** [`migrations/re_vendor_advances.sql`](../../migrations/re_vendor_advances.sql)

### Objects

1. **`re_vendor_payments.advance_amount`**  
   `DECIMAL(15,2) NOT NULL DEFAULT 0.00` — original advance portion of the payment (additive, idempotent `ALTER` via information_schema check).

2. **`re_vendor_advance_balances`**  
   Per `(company_id, vendor_id)` available advance (`balance_aed`). Unique key on company + vendor.

3. **`re_vendor_advance_applications`**  
   Links source payment → bill, amount, `journal_id`, `status` (`posted` \| `reversed`).

4. **COA seed** — account **1410** (see §5).

5. **Setting** — `re_vendor_advance_account_code` = `1410` (`INSERT … ON DUPLICATE KEY UPDATE value = value`).

Migration is additive and re-runnable: column guard, `CREATE TABLE IF NOT EXISTS`, COA `NOT EXISTS` 1410, setting upsert.

---

## 5. COA account 1410 – Vendor Advances / Prepayments

| Field | Value |
|-------|--------|
| `account_code` | 1410 |
| `account_name` | Vendor Advances / Prepayments |
| `account_type` | Asset |
| `normal_balance` | debit |
| `is_header` | 0 |
| Parent | Prefer company **1500** Prepaid Expenses; else **1000** Current Assets. Never orphan when either header exists. |

Existing accounts are not modified. Lookup via setting `re_vendor_advance_account_code` (default 1410).

---

## 6. Posting, sub-ledger and balance logic

### Core helpers

| File | Role |
|------|------|
| [`modules/realestate/includes/vendor_ap_helper.php`](../../modules/realestate/includes/vendor_ap_helper.php) | `re_ap_post_vendor_payment` (gated legacy vs advance); bill paid = allocations + posted advances; void blocked if advances applied |
| [`modules/realestate/includes/vendor_advance_helper.php`](../../modules/realestate/includes/vendor_advance_helper.php) | Balance lock/adjust, FIFO sources, apply, unapply, payment reverse, fail-closed sub-ledger post |

### Rules

- **Compatibility:** `advance_amount ≈ 0` and allocations = amount → legacy Dr AP / Cr Bank only.  
- **Atomicity:** each post/apply/unapply/reverse uses a DB transaction; on failure, full rollback.  
- **Locking:** `SELECT … FOR UPDATE` on advance balance (and payment/application rows where relevant).  
- **Sub-ledger:** `re_ap_post_to_vendor_ledger_required` resolves `journal_line_id` from `re_journal_lines` and throws if missing or if `post_to_vendor_ledger()` returns false.  
- **Bill paid:** `re_ap_bill_paid_amount` = payment allocations + posted advance applications.  
- **Available per payment:** `advance_amount − SUM(posted applications) − SUM(posted Advance VAT)`.  
- **Engine signatures:** no changes to `create_and_post_journal` / `post_journal` / `reverse_journal` / `post_to_vendor_ledger`.

### Connection note

`post_to_vendor_ledger`, journal engine helpers, and `get_or_create_vendor_ledger` use `global $conn`. Normal RE web pages load a single PDO from `includes/db_connect.php` and pass that same instance into helpers, so journal + balance + sub-ledger share one connection/transaction. Alternate CLI bootstraps must not pass a different PDO than the global.

---

## 7. Controls

| Control | Implementation |
|---------|----------------|
| Company isolation | Helpers require `company_id > 0`; loads use `id + company_id`; bill view / payment pages fail closed if no company (no `?: 1` on financial write pages for advances) |
| Permissions | `require_login` + financial department access, else RE module access |
| CSRF | `csrf_verify()` on Payment Made, bill apply/unapply/void, payment reverse |
| Period lock | Shared engine `is_period_locked` on create/post |
| Idempotency | Re-post payment rejected if `journal_id` already set; unapply/reverse already-done short-circuit; application journals keyed by unique `application_id` |
| Concurrency | Balance/payment/application `FOR UPDATE` inside transactions |

---

## 8. UI pages and reports changed

| Page | Change |
|------|--------|
| `modules/realestate/accounting/vendor_payment_add.php` | Remainder → advance; summary “To Vendor Advance” |
| `modules/realestate/accounting/vendor_payments.php` | Columns: Amount, Allocated, Original Advance, Applied, **Advance VAT**, Remaining |
| `modules/realestate/accounting/vendor_bill_view.php` | Apply / unapply; Advance VAT link panel; FIFO source dropdown |
| `modules/realestate/accounting/vendor_advance_vat_*.php` | M5 Advance VAT document workflow |
| `modules/realestate/accounting/vat_report.php` | Input VAT net Dr−Cr |
| `modules/realestate/accounting/vendor_statement.php` | Gross AP / Available Advances / Net; period advance applications table |
| `modules/realestate/accounting/vendor_ledger.php` | Available Vendor Advance metric |
| `modules/realestate/accounting/ap_aging.php` | Gross AP / Available Advances / Net cards; buckets remain gross |
| `modules/realestate/vendors.php` | Advance Remaining on accounting summary |

Supporting: `modules/realestate/includes/re_ap_ui_assets.php` (AP UI assets; Bootstrap/Alpine/Lucide/SweetAlert2 — no Flatpickr/Tom Select/Tailwind for this work).

---

## 9. Migration and deployment instructions

### Prerequisites

- Human approval to run financial migration on the target environment.  
- Database and source backup.  
- Confirm RE companies have COA headers **1500** and/or **1000**.

### Deploy steps

1. Deploy application code (helpers + accounting UI pages + this doc).  
2. Run migration:

```bash
mysql -u <user> -p <database> < migrations/re_vendor_advances.sql
```

3. Verify per company (example):

```sql
SELECT c.company_id, c.account_code, c.account_name, p.account_code AS parent_code
FROM re_chart_of_accounts c
LEFT JOIN re_chart_of_accounts p ON p.id = c.parent_id
WHERE c.account_code = '1410';

SELECT `key`, `value` FROM settings WHERE `key` = 're_vendor_advance_account_code';

SHOW COLUMNS FROM re_vendor_payments LIKE 'advance_amount';
SHOW TABLES LIKE 're_vendor_advance_%';
```

4. Smoke tests: **T01, T03, T04, T06, T18** (see §10).  
5. Manual UI acceptance on Payment Made, Bill view apply/unapply, Payments reverse, SOA/Aging cards.

### Rollback procedure

- **Code:** redeploy previous application revision (helpers gate on schema readiness / `advance_amount`).  
- **Data:** do **not** drop 1410 or truncate applications if live postings exist—coordinate accounting cleanup.  
- **Schema rollback (dev only, unused):** drop applications/balances tables and `advance_amount` only if no production use; never drop 1410 if journals reference it.

---

## 10. Deployment checklist

- [ ] Database backup completed  
- [ ] Application/source backup or tagged release  
- [ ] Migration `migrations/re_vendor_advances.sql` executed successfully  
- [ ] Account **1410** present under parent **1500** or **1000** for each RE company  
- [ ] Setting `re_vendor_advance_account_code` = `1410`  
- [ ] Smoke **T01** – fully allocated payment (legacy Dr AP / Cr Bank)  
- [ ] Smoke **T03** – 100% advance (Dr 1410 / Cr Bank; balance increases)  
- [ ] Smoke **T04** – apply to one bill (Dr AP / Cr 1410; one application)  
- [ ] Smoke **T06** – unapply (reversal JE; balances restored)  
- [ ] Smoke **T18** – reverse blocked with apps; succeeds after unapply  
- [ ] Rollback plan understood (code revert; no casual COA drop)

---

## 11. M6 test matrix and final results

Local transactional matrix (outer transaction rolled back; no lasting DB pollution). **23/23 passed** on final run.

| ID | Scenario | Result |
|----|----------|--------|
| T01 | Fully allocate (`advance_amount=0`) | PASS |
| T02 | Partial allocate + advance | PASS |
| T03 | 100% unallocated advance | PASS |
| T04 | Apply single source | PASS |
| T05 | Apply exceeds advance | PASS (rejected) |
| T06 | Unapply | PASS |
| T07 | Void with payments/advances | PASS (blocked) |
| T08 | SOA / balance table tie-out | PASS |
| T09 | Aging Gross/Advances/Net; buckets gross | PASS |
| T10 | Company isolation | PASS |
| T11 | Idempotent payment re-post | PASS |
| T12 | Period lock helper present | PASS (no locked period in local DB for live reject) |
| T13 | No VAT on advance payment JE | PASS |
| T15 | Legacy regression | PASS (via T01) |
| T17 | Multi-source FIFO (≥2 application rows) | PASS |
| T18a | Reverse blocked with posted apps | PASS |
| T18b | Reverse after unapply | PASS |
| T19 | Refunds follow-up documented | PASS |
| NEG-direct | `post_to_vendor_ledger` null line → false | PASS |
| NEG-rollback | Failed reverse; state unchanged | PASS |
| COA-1410 | Parent 1500/1000 | PASS |
| SCHEMA | Tables + column | PASS |
| ROLLBACK | Outer tx restored snapshots | PASS |

T14 (M5 VAT) — **completed under M5** (see `vendor-advance-vat-m5-closure.md`; automated 27/27 + manual 3 UI scenarios GO).

---

## 12. Known non-blocking technical debt

1. `vendors.php` still uses `current_company_id($conn) ?: 1` on vendor delete (pre-existing; advance column is display-only).  
2. `reverse_journal` does not re-verify caller company on the journal header (pre-existing shared engine; advance callers are company-scoped).  
3. Bill void path may still call `post_to_vendor_ledger` with nullable line id (pre-existing).  
4. Advance balance is delta-maintained under locks; no DB constraint that it equals sum of per-payment remaining.  
5. Wrong-company `bank_account_id` on payment may fall back to default cash/bank instead of hard reject.  
6. Module-level RE access (without financial department) can still perform monetary AP actions—same pattern as other AP pages; needs business confirmation if tightening is required.  
7. `post_to_vendor_ledger` / engine rely on `global $conn` (pre-existing); safe for standard web UI.

---

## 13. Deferred scope

_None for Vendor Advances program._ M7 refunds complete — see [`vendor-advance-refunds-m7-closure.md`](vendor-advance-refunds-m7-closure.md).

### M5 – Vendor Advance VAT documents

**Complete.** See [`vendor-advance-vat-m5-closure.md`](vendor-advance-vat-m5-closure.md).

### M7 – Vendor Advance Refunds

**Complete.** Journal Dr Bank / Cr 1410; VAT guard; reversal; UI + reports.

---

## 14. Final closure

| Item | Value |
|------|--------|
| M1–M4 migration | `migrations/re_vendor_advances.sql` |
| M5 migration | `migrations/re_vendor_advance_vat_documents.sql` |
| M7 migration | `migrations/re_vendor_advance_refunds.sql` |
| Documentation | `docs/realestate/vendor-advances.md`, `vendor-advance-vat-m5-closure.md`, `vendor-advance-refunds-m7-closure.md` |
| M6 (M1–M4 matrix) | Passed (23/23) |
| M5 matrix | Passed (27/27 automated) |
| M7 matrix | Passed (14/14 automated) |
| Manual UI journal audit | 3 Test Pool scenarios — 54/54 PASS (M5) |
| Deferred | Listed technical debt only |
| **Program status** | **COMPLETE (M1–M7) — GO for controlled deployment** |
