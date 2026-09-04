# Phase 2A — Legacy ARS Accounting Transition Plan

**Status:** Design only — **do not rewrite history**  
**Principle:** Preserve posted journals; add Option B documents around them; never edit amounts in place.

---

## 1. Inventory of legacy artefacts (Confirmed)

| Artefact | Role today |
|----------|------------|
| `ars_accounting.php` | Direct poster to shared engine |
| `ars_bookings.journal_id` | Revenue JV pointer |
| `ars_bookings.deposit_*` / deposit journal ids | Deposit lifecycle |
| `ars_booking_payments` (+ optional `journal_id`) | Payments; some Stripe rows may lack JV |
| `ars_booking_charges` | Operational extras; may lack invoice docs |
| Activity backfill (Phase 1B) | Historical snapshots only |
| COA seed 1310/4100/2310/1110/1210/2200/2400 | ARS company chart |

---

## 2. Classification

### Historical read-only

- All existing `re_journal_headers` with `reference_type` in `ars_booking`, `ars_payment`, `ars_deposit`, `ars_deposit_refund`  
- Posted payment rows and booking totals already in production-like local data  

**Action:** Leave journals immutable; link from new docs where backfilled.

### Backfillable (conservative)

| Source | Proposed backfill doc |
|--------|---------------------|
| Booking with `journal_id` | `original_invoice` snapshot + `journal_id` link; balances from booking paid/balance **Needs review** |
| Each `ars_booking_payments` with journal | Optional allocation to backfilled invoice |
| Deposit journal present | `ars_security_deposits` received event |
| Phase 1B activity refs | Already present — do not duplicate |

**Rules:** Mark `source=backfill`; idempotent keys; do not invent line detail beyond journal totals; do not invent users.

### Opening balance candidates

- If BC-01 moves posting company: opening balances on new company **Needs finance process** — out of adapter automation unless Confirmed.  
- Unpaid confirmed bookings: invoice backfill with open `balance_due`.

### Manual review

- Stripe payments without `journal_id`  
- Duplicate/odd payment rows (e.g. historical doubles)  
- Cancelled bookings with unreversed journals  
- Charges without corresponding revenue  

### Excluded

- Fabricating nightly deferred schedules for past stays  
- Mapping guests into `re_tenants`  
- Rewriting `re_invoices`  

---

## 3. Cutover states

| State | Behaviour |
|-------|-----------|
| **Bridge-only** | Adapter flag off (today) |
| **Dual-write new only** | New confirms create Option B docs + journals; legacy rows untouched |
| **Read docs-first** | UI prefers financial documents; falls back to journal badges |
| **Bridge deprecated** | No new calls to `ars_post_*` helpers |

Rollback = return flag to bridge-only; keep tables.

---

## 4. Data quality gates before backfill (Phase 2B)

1. Backup DB  
2. Count journals by `reference_type`  
3. Reconcile `sum(payments)` vs `paid_amount` per booking  
4. List Stripe unpaid-GL exceptions  
5. Finance sign-off on sample of 10 bookings  

---

## 5. Activity Center

Backfilled financial docs must emit **one** `document_reference` / `invoice_backfilled` activity per doc with `is_backfill=1`, deduped — do not replay confirm/payment operational events.

---

## 6. Mobile

Backfill must not change guest API field names or remove payment arrays. Additive document lists only if product approves.
