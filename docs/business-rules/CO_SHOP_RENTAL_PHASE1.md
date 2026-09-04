# Construction Shop Rental — Confirmed Business Rules

Source of confirmation: Product owner approval in Cursor sessions (2026-07-11).  
Module: Construction (Madar Al Wadi Building Contracting only).

---

## BR-CO-SHOP-001 — Period rent amount (superseded)

| Field | Value |
|-------|-------|
| Rule ID | BR-CO-SHOP-001 |
| Status | **Superseded** by BR-CO-SHOP-007 |
| Confirmed rule (historical) | `rent_amount` was one billing period based on `payment_frequency`. |
| Date | 2026-07-11 |
| Superseded date | 2026-07-11 |

---

## BR-CO-SHOP-007 — Three-layer shop rental model (Option B)

| Field | Value |
|-------|-------|
| Rule ID | BR-CO-SHOP-007 |
| Module | Construction |
| Workflow | Shop rental contract / cheques / invoices / recognition |
| Business event | Commercial shop lease |
| Confirmed rule | Treat **commercial contract**, **operational payment plan**, and **accounting obligations** as three independent layers. |
| Commercial layer | `rent_amount` stores **total contract rent (net)** for the lease term. Monthly equivalent is derived from contract duration (total ÷ months). |
| Operational layer | `payment_frequency` and cheque counts control **only** the cheque / payment plan (timing and split of collections). They do **not** define invoice or recognition periods. |
| Accounting layer | Invoices, deferred revenue (2215), and monthly revenue recognition follow the **contract earning period** (monthly over the lease term), not payment frequency. |
| VAT | Remains configurable per contract (`vat_mode`, `vat_collection_method`) and **independent of cheque planning**. |
| Supported scenarios | Annual/total rent with 4–6 PDCs; monthly recognition with deferred default; VAT included / proportional / separate; separate VAT paid by different method |
| Exceptions | None confirmed |
| Accounting impact | Monthly earning schedules → invoices/AR; cheque clear allocates to open invoices; recognition Dr 2215 / Cr 4120 monthly |
| Related code paths | `co_shop_build_earning_months`, `co_shop_distribute_rent_cheques`, schedule/invoice/recognition flows |
| Related decision | DEC-013 |
| Status | Confirmed |
| Approved by | Product owner (Cursor session) |
| Date | 2026-07-11 |

---

## BR-CO-SHOP-002 — Configurable VAT collection

| Field | Value |
|-------|-------|
| Rule ID | BR-CO-SHOP-002 |
| Module | Construction |
| Confirmed rule | VAT collection method is **configurable per contract**. Do not hard-code a single method. |
| Supported scenarios | (1) VAT included in each rent installment; (2) VAT distributed proportionally across rent installments; (3) VAT collected once up front as a **Payment Receipt** credited to prepaid Output VAT liability (2330); monthly rent invoices are the official Tax Invoices (Net + VAT + Total) and recognize Output VAT; prepaid VAT is auto-consumed (Dr 2330 / Cr AR) so outstanding = rent only; (4) VAT paid with a different payment method than rent. |
| Configuration options | `vat_mode` exclusive/inclusive; `vat_collection_method` included_in_installment / proportional / separate |
| Accounting impact | VAT is independent of cheque planning (BR-CO-SHOP-007). For `separate`: no VAT-only tax invoice; receipt → Dr Bank / Cr 2330; monthly invoice → Dr AR (gross) / Cr Income / Cr 2310 then consume prepaid; Payment Workspace collectible = rent only. |
| Status | Confirmed |
| Date | 2026-07-11 |
| Updated | 2026-07-28 — Separate VAT prepaid liability model (finance redesign) |

---

## BR-CO-SHOP-003 — Security deposit posting path

| Field | Value |
|-------|-------|
| Rule ID | BR-CO-SHOP-003 |
| Confirmed rule | Cash and PDC deposits both supported; **one posting path** only (`co_shop_deposit_receipts`). |
| Exceptions | Refund/settlement deferred to Phase 2 |
| Status | Confirmed |
| Date | 2026-07-11 |

---

## BR-CO-SHOP-004 — Deferred rent default

| Field | Value |
|-------|-------|
| Rule ID | BR-CO-SHOP-004 |
| Confirmed rule | Deferred rent revenue **enabled by default**, configurable per contract. |
| Status | Confirmed |
| Date | 2026-07-11 |

---

## BR-CO-SHOP-005 — Partial payments and tenant credit

| Field | Value |
|-------|-------|
| Rule ID | BR-CO-SHOP-005 |
| Confirmed rule | Partial payments, overpayments, and tenant credit are supported. |
| Status | Confirmed |
| Date | 2026-07-11 |

---

## BR-CO-SHOP-006 — Deposit refund timing

| Field | Value |
|-------|-------|
| Rule ID | BR-CO-SHOP-006 |
| Confirmed rule | Deposit refund and settlement workflow is **Phase 2**. |
| Status | Confirmed |
| Date | 2026-07-11 |

---

## Phase 1.5 — Visibility & operational control (2026-07-11)

Non-accounting UX layer for Madar Al Wadi shop rental only.

| Deliverable | Location |
|-------------|----------|
| Read-only ops helpers (dashboard, health, diagnostics, audit timeline, portfolio, quick actions) | `modules/construction/includes/construction_shop_rental_ops_helpers.php` |
| Contract View UX | `modules/construction/shop_rental_contract_view.php` |
| Portfolio list indicators | `modules/construction/shop_rental_contracts.php` |
| Optional invoice/receipt filters (`client_id`, `source_type`) | `client_invoices.php`, `client_payments.php` |

**Explicit non-goals:** no change to posting, deferred revenue recognition math, company isolation, or Real Estate coupling. Bulk “Generate Invoices” reuses the existing single-schedule invoice + post path only.

---

## Phase 1.75 — Multi-shop contracts & Control Center (2026-07-11)

| Confirmation | Rule |
|--------------|------|
| Overlap | Hard-block date overlap vs **ACTIVE** contracts only; draft shows warning only |
| Occupancy | Shops occupied only when contract is **ACTIVE**; draft never reserves |
| Primary shop | Explicit selection; default first selected |
| Expiry | Manual Mark Expired / Terminate; Past End Date warning only — no silent status change |
| Money | Contract-level only; never multiply by shop count |

| Deliverable | Location |
|-------------|----------|
| Migration + backfill | `migrations/construction_shop_rental_phase175.sql` |
| Multi-shop / occupancy helpers | `modules/construction/includes/construction_shop_rental_multishop_helpers.php` |
| Control Center | `modules/construction/shop_rental_control_center.php` |
| Junction table | `co_shop_rental_contract_shops` (legacy `shop_unit_id` = primary) |

---

## Phase 1.6 — Tenant commission (2026-07-11)

| Field | Value |
|-------|-------|
| Rule ID | BR-CO-SHOP-008 |
| Confirmed rule | Madar Al Wadi charges **tenant commission** on shop rental contracts. Default **5% of total net contract rent**. Basis may be **percent or fixed**; **manual override** of net amount allowed. Commission is **contract-level** (not multiplied by shop count). **VAT on commission is configurable**. Separate `shop_commission` client invoice posts Dr AR `1310` / Cr income `4140` (+ Cr Output VAT `2310` when VAT enabled). Receipts use normal Payment Manager allocation. |
| Status | Confirmed |
| Date | 2026-07-11 |
| Explicit non-goals | Landlord-paid commission; broker payable; payroll commission; shared/split commission; Real Estate lease commission |

| Deliverable | Location |
|-------------|----------|
| Migration | `migrations/construction_shop_rental_phase16_commission.sql` |
| Helpers | `modules/construction/includes/construction_shop_rental_commission_helpers.php` |
| Income account | `4140` Shop Tenant Commission Income |
| Contract View / Payment Manager | `shop_rental_contract_view.php` |
| Control Center KPIs | `shop_rental_control_center.php` |
| Report | `modules/construction/reports/shop_tenant_commission.php` |

---

## Key Money (2026-07-27)

| Field | Value |
|-------|-------|
| Rule ID | BR-CO-SHOP-KEY-MONEY-001 |
| Confirmed rule | See [`docs/business-rules/CO_SHOP_RENTAL_KEY_MONEY.md`](../business-rules/CO_SHOP_RENTAL_KEY_MONEY.md) — one-time AR revenue via Contract Charges / `shop_charge`, income **4170**, optional invoice timing immediate vs on start date. |
| Status | Confirmed |
| Migration | `migrations/construction_shop_rental_phase_key_money.sql` |

### Combined first collection cheque (opt-in)

| Field | Value |
|-------|-------|
| Status | Confirmed (operational UX; not a new commercial BR) |
| Rule | At contract create / cheque regenerate, opt-in **combined first cheque**: first rent PDC face = 1st rent installment + separate VAT (if VAT method = separate) + tenant commission. No separate `VAT_SEPARATE` PDC when combined. Accounting invoices/schedules remain independent (BR-CO-SHOP-007). Clear via Payment Manager **Combined payment**. |
| Migration | `migrations/construction_shop_rental_combined_first_cheque.sql` (`combined_first_cheque` TINYINT) |
| Default | Off |

---

## Phase 2A — Commercial leasing lifecycle (2026-07-11)

| Field | Value |
|-------|-------|
| Rule ID | BR-CO-SHOP-009 |
| Confirmed rule | Renewal hybrid: parent stays **Active** until renewal **Activated**, then parent → **Renewed**. Draft renewal may overlap. Early termination penalty default **2 months** (company setting), overridable (0 / 0.5 / 1 / 1.5 / 2 / custom). Penalty income **4150**. Deposit recoveries **4160**. Move-out inspection (ops) may suggest editable deductions; **inspection never posts GL**; accounting only on Deposit Settlement finalize. Deductions require completed inspection. |
| Status | Confirmed |
| Date | 2026-07-11 |
| Explicit non-goals | Real Estate merge; landlord/broker commission; auto-priced damage matrices |

| Deliverable | Location |
|-------------|----------|
| Migration | `migrations/construction_shop_rental_phase2a.sql` |
| Lifecycle / renewal / amendments | `construction_shop_rental_lifecycle_helpers.php` |
| Inspection | `construction_shop_rental_inspection_helpers.php` + `shop_rental_move_out_inspection.php` |
| Deposit settlement | `construction_shop_rental_deposit_settlement_helpers.php` + `shop_rental_deposit_settle.php` |
| Termination | `construction_shop_rental_termination_helpers.php` + `shop_rental_terminate.php` |
| Cheque lifecycle | `construction_shop_rental_cheque_lifecycle_helpers.php` |
| Renewal UI | `shop_rental_renew.php` |
| GL | 4150, 4160 |

---

## Safe / purge contract delete (2026-07-12)

| Field | Value |
|-------|-------|
| Rule ID | BR-CO-SHOP-010 |
| Status | Confirmed (admin control — destructive) |
| Rule | **Purge delete** permanently removes a shop rental contract **and** its linked rent/VAT/commission/penalty invoices, payment receipts/allocations, deposit receipts & settlements, recognitions, operational cheques/schedules, **and** the related Shared RE journals (`re_journal_*` + `re_general_ledger`) for this company only. User must type `DELETE` to confirm. Blocked if renewal child contracts still exist, or any linked journal falls in a **locked accounting period**. Does not touch Cleaning `gl_*` or other companies. Prefer Archive for normal lifecycle; purge is for mistake cleanup / full wipe. |
| Date | 2026-07-12 |
| Code | `construction_shop_rental_delete_helpers.php`, list + contract view Actions |

---

## Phase 2B — Management & analytics (2026-07-12)

| Field | Value |
|-------|-------|
| Rule ID | (no new commercial BR — management visibility only) |
| Confirmed rule | Phase 2B adds executive KPIs, trends, operational forecasts, and reports for Construction shop leasing. Does **not** change posting, recognition, deposit settlement, or lifecycle workflows from Phase 1.x / 2A. |
| Status | Confirmed (implementation scope) |
| Date | 2026-07-12 |
| Doc | `docs/business-rules/CO_SHOP_RENTAL_PHASE2B.md` |
