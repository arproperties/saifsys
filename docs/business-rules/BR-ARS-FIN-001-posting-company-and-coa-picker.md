# BR-ARS-FIN-001 — ARS posts under RE company; cash/bank COA picker

| Field | Value |
|-------|-------|
| Rule ID | BR-ARS-FIN-001 |
| Module | ARS (+ shared RE ledger company) |
| Workflow | Confirm invoice → stay payment → deposit receive/refund (and future adapter money events) |
| Business event | ARS financial posting company + operator selection of cash/bank GL account |
| Confirmed rule | All ARS Financial Adapter journals post with `company_id = 2` (AIN AL REEM PROPERTIES / Real Estate). Operators must choose the posting cash or bank account from company 2 COA on stay payment, deposit receive, and deposit refund (cash and bank transfer). Bank allow-list: **1210–1250**. Cash allow-list: **1110, 1120, 1130** (1130 explicitly allowed). ARS ops company (`companies.id=8`) remains the operational booking company; it is **not** the GL posting company. Holiday Homes leaf COA on company 2 per seed migration. **Cutover:** leave existing company-8 ARS journals as history; new posts start fresh on company 2. |
| Supported scenarios | Guest pays cash to RE cashier; guest bank-transfers to any listed RE bank (Park Place / Ayla / Management / Main / FAB); deposit collect and full/partial refund using the same picker |
| Exceptions | Stripe clearing/settlement wiring until role map + UI use **1140** / **5510**. Historical company-8 ARS journals left as history (no auto-move). |
| Configuration options | `ars_financial_company_id` (target = 2); allow-lists for cash codes / bank codes; role→COA map rows for company 2 |
| Operational impact | Payment / deposit modals require account selection; RE bank reco and TB for company 2 include ARS activity |
| Accounting impact | All ARS invoice, payment, allocation, deposit, refund journals: `re_journal_headers.company_id = 2`. No change to `accounting_engine.php` behaviour beyond calling it with company 2 + selected account codes. |
| Permissions | Same ARS Core finance actions as today; account picker required (fail closed if missing) |
| Source of confirmation | Product owner chat 2026-07-20 (decisions 1–3) |
| Related code paths | `ars_financial_adapter.php`, `ars_account_roles.php`, `ars_accounting.php` (bridge), `ajax_booking_actions.php`, booking payment/deposit UI; RE COA company 2 |
| Status | Confirmed (policy) — COA seed + role map + adapter/UI implemented |
| Approved by | Product owner (chat) |
| Date | 2026-07-20 |

## Resolves / updates

- **BC-01:** Owner choice = post under primary Real Estate company (**2**), not ARS ops company 8. Aligns with DEC-015 intent (shared bank reco).
- Does **not** store ARS invoices in `re_invoices` (DEC-016 Option B unchanged).
- Cash **1130** allowed in picker.
- Cutover: **leave company-8 journals as history**; start fresh on company 2.

## COA seed migration

`migrations/ars_br_fin_001_seed_hh_coa_company2.sql` — additive only; safe to re-run; no PHP / engine changes.

## Role map + receipt columns

`migrations/ars_br_fin_001_role_map_and_receipt_account.sql` — `ars_account_role_map` for company 2 + `receipt_account_code` on payments/deposits.

## Implementation (ARS only — engine untouched)

- Ops company (`ars_bookings.company_id` / documents) stays **8**.
- Journals post with `company_id = 2` via `ars_financial_gl_company_id()`.
- Payment / deposit receive / deposit settle require operator-selected RE cash (1110–1130) or bank (1210–1250).

## Still open

1. Live UAT: new booking confirm → pay with bank 1220 → deposit → refund; verify journals on company 2 and RE bank reco.
2. Stripe clearing UI wiring (1140/5510) when Stripe enabled.

---

## COA recommendation for company 2 (Holiday Homes)

### Reuse existing company 2 accounts (money in / money out)

| Use | Code | Name | Why reuse |
|-----|------|------|-----------|
| Cash picker | 1110 | Cash on Hand | Real cash drawer |
| Cash picker | 1120 | Petty Cash | Optional |
| Cash picker | 1130 | CASH - MR. AMRAN AKHTAR | Only if ops actually uses it |
| Bank picker | 1210–1250 | Park Place / Ayla / Management / Main / FAB | Statement + bank reco |
| VAT on stay invoices | 2310 | Output VAT | Same VAT payable as RE |

Do **not** invent a second set of bank accounts for ARS.

### Create new Holiday Homes accounts on company 2 (recommended)

DEC-015 calls for **dedicated Holiday Homes GL accounts** so lease rent/receivable/deposits are not mixed with short-stay activity on the same codes.

| Role | Suggested code | Suggested name | Why new (not reuse) |
|------|----------------|----------------|---------------------|
| AR_GUEST | **1340** | AR - Holiday Homes Guests | **1310** is Rent Receivable (leases) |
| SECURITY_DEPOSIT | **2210** | HH Guest Deposits Held | **2200** is lease Security Deposits Payable |
| GUEST_CREDIT | **2220** | HH Guest Credit Liability | Not on company 2 today |
| ROOM_REVENUE | **4130** | Holiday Homes Room Revenue | **4100/4110/4120** are lease rent income |
| ADDITIONAL_SERVICE_REVENUE / DAMAGE_REVENUE | **4140** | Holiday Homes Extra / Damage Revenue | Keep distinct from **4200** Service Charge |
| LATE_FEE_REVENUE | **4150** | Holiday Homes Late Fee Revenue | Optional; else map later to **4300** only if finance confirms |
| FORFEIT_REVENUE | **4410** | Holiday Homes Forfeit / Other HH Income | Optional until forfeit BC confirmed |
| DEFERRED_REVENUE | **2420** | HH Unearned / Deferred Revenue | Only if BC-02 ever uses deferred; **2410** is lease unearned |
| STRIPE_CLEARING | **1140** | HH Stripe Clearing | **1130** on company 2 is already a named cash account |
| STRIPE_FEE | **5510** | Stripe Processing Fees (HH) | Missing on company 2 |

Parents (1000/1100/1200/1300/2000/2200/4000) already exist — only leaf accounts above need adding.

### Map summary (target `ars_account_role_map` for company_id = 2)

| Role | Account |
|------|---------|
| CASH | *operator pick* among 1110/1120/(1130) — default 1110 |
| BANK | *operator pick* among 1210–1250 — no single default without confirmation |
| AR_GUEST | 1340 |
| ROOM_REVENUE | 4130 |
| VAT_OUTPUT | 2310 |
| SECURITY_DEPOSIT | 2210 |
| GUEST_CREDIT | 2220 |
| ADDITIONAL_SERVICE_REVENUE / DAMAGE_REVENUE | 4140 |
| LATE_FEE_REVENUE | 4150 (or pending) |
| STRIPE_CLEARING | 1140 |
| STRIPE_FEE | 5510 |
| DEFERRED_REVENUE | 2420 |
| FORFEIT_REVENUE | 4410 |

### Explicitly avoid

| Do not map ARS to | Reason |
|-------------------|--------|
| 1310 Rent Receivable | Lease AR / Invoice Mode ageing |
| 4100–4120 Rent Income | Lease P&L |
| 2200 Security Deposits Payable | Lease tenant deposits |
| Company 8 COA for new posts | Breaks RE bank reco (owner decision) |

---

## Implementation notes (when approved — not started)

- Change adapter posting company resolution to financial company **2**; keep `ars_bookings.company_id = 8` for ops/module access.
- Persist selected `account_code` / `account_id` on payment and deposit rows.
- Fail closed if picker empty or account not in allow-list / wrong company.
- Seed COA + role map via additive migration (human approval before run).
- Do **not** modify `modules/realestate/accounting/accounting_engine.php`.
