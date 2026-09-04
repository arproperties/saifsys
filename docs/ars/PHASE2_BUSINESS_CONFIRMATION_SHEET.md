# Phase 2A — Business Confirmation Sheet (ARS Holiday Homes Accounting)

**Status:** Open — awaiting business decisions  
**Phase:** 2A (design only)  
**Created:** 2026-07-17  
**Evidence standard:** Document current behaviour; label Confirmed / Strong inference / Needs business confirmation. **Do not invent rules.**  
**Related:** DEC-014, DEC-015, DEC-016; [`ARS_MODERNIZATION_AUDIT_AND_ROADMAP.md`](ARS_MODERNIZATION_AUDIT_AND_ROADMAP.md); prior [`BUSINESS_CONFIRMATION_SHEET.md`](BUSINESS_CONFIRMATION_SHEET.md)

---

## How to use this sheet

For each topic: review **Current behaviour**, choose among **Options**, record **Owner decision** + date. Phase **2B must not** change posting behaviour until each decision that affects journals is Confirmed via Business Rule Capture Standard.

---

## BC-01 — Posting company (`company_id`)

| Field | Content |
|-------|---------|
| **Current behaviour** | Confirmed from code: `ars_accounting.php` posts with `(int)$booking['company_id']` or payment `company_id` (ARS company id=8 / code `ARS` in local DB). Units often live under RE `company_id` (e.g. 2) while bookings use ARS company. |
| **Options** | **A)** Continue posting under ARS company row. **B)** Post under primary Real Estate company (shared bank reco entity). **C)** Configurable mapping (ARS ops company → RE financial company). |
| **Advantages** | A: matches current journals/local COA seed. B: single bank reco with RE. C: flexible multi-entity. |
| **Risks** | A: ARS GL may be separate from RE bank. B: cross-module company confusion; COA must exist on RE company. C: misconfiguration risk. |
| **Accounting impact** | Determines which `re_chart_of_accounts` / journals / bank reco company owns HH activity. |
| **Operational impact** | Staff company switcher vs “where do I look for HH journals?” |
| **Recommendation** | Prefer **C** (explicit mapping) or **B** if business confirms one shared RE financial entity — **not Confirmed**. Document both current A and target. |
| **Business decision required** | Which `company_id` is the shared Holiday Homes financial environment? |
| **Owner decision** | _Pending_ |
| **Date** | |

---

## BC-02 — Revenue recognition timing

| Field | Content |
|-------|---------|
| **Current behaviour** | Confirmed: revenue journal on **booking confirm** (`ars_post_booking_revenue`): DR 1310, CR 4100, CR 2310. Deferred revenue **2400** seeded in ARS COA but **unused**. |
| **Options** | **A)** Recognize on confirm (current). **B)** Recognize on check-in. **C)** Nightly/periodic deferred (use 2400 → 4100). |
| **Advantages** | A: simple, already live. B: aligns with stay start. C: matches occupancy accounting. |
| **Risks** | A: unearned if cancel after confirm. B/C: more complex amendments/reversals. |
| **Accounting impact** | Timing of P&L vs liability; cancel/extension documents. |
| **Operational impact** | When “invoice created” financial status engages vs stay. |
| **Recommendation** | Keep **A** as interim unless finance requires B/C — **Needs business confirmation**. |
| **Business decision required** | Confirm / check-in / deferred nightly? |
| **Owner decision** | _Pending_ |
| **Date** | |

---

## BC-03 — Deferred revenue (account 2400)

| Field | Content |
|-------|---------|
| **Current behaviour** | Account seeded; not posted by ARS. |
| **Options** | **A)** Do not use (recognize immediately). **B)** Use for pre-stay unearned after invoice. **C)** Use only for packages/prepaid. |
| **Advantages / Risks** | Tied to BC-02. Using 2400 without process creates imbalance risk. |
| **Accounting impact** | BS liability vs revenue. |
| **Recommendation** | Follow BC-02; if A, leave 2400 unused. |
| **Business decision required** | Use deferred revenue? When release? |
| **Owner decision** | _Pending_ |

---

## BC-04 — VAT treatment

| Field | Content |
|-------|---------|
| **Current behaviour** | Confirmed: booking `vat_mode` exclusive/inclusive/none; confirm journal credits **2310** Output VAT for VAT portion; settings default VAT rate. |
| **Options** | **A)** Keep exclusive default + modes. **B)** Always inclusive. **C)** Always exclusive UAE 5%. |
| **Risks** | Tourism fee interaction (BC-05); credit note VAT. |
| **Recommendation** | Preserve current modes until policy Confirmed — do not remove flexibility. |
| **Business decision required** | Any mandatory VAT presentation for HH invoices? |
| **Owner decision** | _Pending_ |

---

## BC-05 — Tourism / municipality fees

| Field | Content |
|-------|---------|
| **Current behaviour** | No dedicated fee line or GL in `ars_accounting.php`. Extra charges are free-form `ars_booking_charges`. |
| **Options** | **A)** Not required. **B)** Required % or flat per night — Needs rate + payable/expense account. **C)** Pass-through only (liability). |
| **Recommendation** | **A** until Confirmed; if B/C, capture BR via Business Rule Capture. |
| **Business decision required** | Required? Rate? Account? Inclusive of VAT? |
| **Owner decision** | _Pending_ |

---

## BC-06 — Cancellation rules (financial)

| Field | Content |
|-------|---------|
| **Current behaviour** | Confirmed: cancel reverses revenue journal if present (`ars_reverse_booking_journal`); no automatic cancellation fee document. Locked bookings block money-impact cancel without amendment (Phase 1). |
| **Options** | **A)** Full reverse only (current). **B)** Fee invoice + CN for remainder. **C)** Tiered fee by lead time. |
| **Recommendation** | Design adapter for A+B; fee formula **Needs business confirmation**. |
| **Business decision required** | Fee formula + when charged + VAT. |
| **Owner decision** | _Pending_ |

---

## BC-07 — No-show rules

| Field | Content |
|-------|---------|
| **Current behaviour** | Status can expire/cancel; no dedicated no-show fee posting. |
| **Options** | Treat as cancel (BC-06) / separate no-show fee / full charge keep revenue. |
| **Business decision required** | Distinct from cancel? Amount? |
| **Owner decision** | _Pending_ |

---

## BC-08 — Early checkout

| Field | Content |
|-------|---------|
| **Current behaviour** | Guest lifecycle requests exist; financially locked → amendment required (Phase 1). No automatic CN for unused nights. |
| **Options** | Full refund unused nights / partial / no refund / case-by-case. |
| **Business decision required** | Default commercial policy. |
| **Owner decision** | _Pending_ |

---

## BC-09 — Booking extensions

| Field | Content |
|-------|---------|
| **Current behaviour** | Lifecycle extension can change dates/pricing when unlocked; locked → Phase 2 amendment. No extension invoice table yet. |
| **Options** | Always Extension Invoice for added nights / adjust original (forbidden after lock) / manual. |
| **Recommendation** | After lock: **new Extension Invoice** only (AD-005). Pricing source Needs confirmation. |
| **Business decision required** | Rate for extended nights; VAT; deposit top-up? |
| **Owner decision** | _Pending_ |

---

## BC-10 — Security deposits

| Field | Content |
|-------|---------|
| **Current behaviour** | Confirmed: liability **2200**; receive/refund journals; statuses on booking; Stripe deposit path may not post GL (`ars_stripe` intentionally no journals). |
| **Options** | Keep 2200 / separate HH deposit liability / forfeit to income account (Needs code). |
| **Business decision required** | Forfeit rules; damage vs forfeit; Stripe deposit GL timing. |
| **Owner decision** | _Pending_ |

---

## BC-11 — Damage deductions

| Field | Content |
|-------|---------|
| **Current behaviour** | Charge type `damage` on unlocked bookings only; no separate damage invoice/GL split. Locked → amendment. |
| **Options** | Service/damage invoice from deposit / AR invoice / expense recover. |
| **Business decision required** | Offset deposit first? Invoice guest? |
| **Owner decision** | _Pending_ |

---

## BC-12 — Stripe settlement

| Field | Content |
|-------|---------|
| **Current behaviour** | Confirmed: Stripe records payments/intents; **no** automatic GL from `ars_stripe.php`. Manual/cash/bank payments post 1110/1210. |
| **Options** | **A)** Immediate DR bank on succeed. **B)** DR clearing then settle to bank. **C)** Ops-only until batch settle. |
| **Business decision required** | Clearing account codes; fee netting; payout timing. |
| **Owner decision** | _Pending_ |

---

## BC-13 — Clearing accounts

| Field | Content |
|-------|---------|
| **Current behaviour** | No Stripe clearing COA seeded for ARS. |
| **Options** | Add clearing asset/liability under posting company (BC-01) / reuse RE clearing if exists. |
| **Business decision required** | Account codes for Stripe clearing + fees. |
| **Owner decision** | _Pending_ |

---

## BC-14 — Guest accounting party

| Field | Content |
|-------|---------|
| **Current behaviour** | Guests in `ars_guests` only; AR is GL 1310 (not per-guest subledger in RE tenants). |
| **Options** | **A)** Remain `ars_guests` + GL AR (Option B). **B)** Map to `re_tenants` (rejected for Option A storage; possible future party map). |
| **Recommendation** | **A** aligned with DEC-016 — confirm exclusivity. |
| **Business decision required** | Guests exclusively `ars_guests`? |
| **Owner decision** | _Pending_ (sheet historically open) |

---

## BC-15 — Receivable ownership / AR control

| Field | Content |
|-------|---------|
| **Current behaviour** | AR balance in 1310 under booking company; payments credit 1310; no IM-style allocation table for ARS yet. |
| **Options** | Open-item per ARS financial document (Phase 2B allocations) / balance-forward only. |
| **Recommendation** | Open-item via `ars_payment_allocations` (Option B design). |
| **Business decision required** | Ageing by guest vs booking? |
| **Owner decision** | _Pending_ |

---

## Already confirmed (do not reopen without new DEC)

| ID | Decision | Status |
|----|----------|--------|
| DEC-016 / BC-OptionB | Option B document tables + adapter; not `re_invoices` | **Approved** 2026-07-17 |
| DEC-014 | Financial Adapter; protect engine | Accepted |
| DEC-015 | Shared financial env; ops independent | Accepted |
| AD-005 | Never rewrite financial history | Accepted |
| AD-009 | Activity Center mandatory | Accepted |

---

## Sign-off block

| Role | Name | Date | Signature |
|------|------|------|-----------|
| Business owner | | | |
| Finance / accountant | | | |
| Technical lead | | | |

**Phase 2B may not implement posting changes for any BC row still _Pending_ that affects journal lines, recognition timing, or company mapping.**
