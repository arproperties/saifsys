# Phase 2A — ARS Accounting Event Catalog

**Status:** Design draft — amounts/accounts marked where policy is unconfirmed  
**Ledger:** Shared RE engine (`re_journal_*`) via Financial Adapter  
**Do not invent** fee formulas — see [`PHASE2_BUSINESS_CONFIRMATION_SHEET.md`](PHASE2_BUSINESS_CONFIRMATION_SHEET.md)

Account roles (current bridge Confirmed): **1310** AR-Guests, **4100** Room Revenue, **2310** Output VAT, **1110** Cash, **1210** Bank, **2200** Deposit liability. **2400** Deferred seeded unused.

---

## How to read each event

| Field | Meaning |
|-------|---------|
| Business trigger | Ops moment |
| Financial documents | Option B docs |
| Debit / Credit roles | GL roles (codes may follow BC-01 company COA) |
| VAT / Deferred | Policy |
| Lifecycles | Ops + financial status effects |
| Activity Center | event_type |
| Reversal | How to undo |

---

## EVT-01 — Booking invoice (original)

| | |
|--|--|
| **Business trigger** | Staff/guest confirm booking |
| **Financial documents** | `original_invoice` |
| **Debit** | AR (1310) |
| **Credit** | Revenue (4100); Output VAT (2310) if applicable |
| **VAT** | Per booking vat_mode |
| **Deferred** | Only if BC-02/03 chooses deferred (else none) |
| **Booking lifecycle** | → confirmed |
| **Financial lifecycle** | draft → invoice_created; lock engaged |
| **Activity** | `booking_confirmed`, `invoice_created`, `financial_lock_engaged` |
| **Reversal** | Cancel → reverse journal + void/CN (BC-06) |
| **Evidence** | Current `ars_post_booking_revenue` |

---

## EVT-02 — Extension

| | |
|--|--|
| **Trigger** | Extension amendment approved |
| **Documents** | `extension_invoice` + extension meta |
| **Debit / Credit** | AR / Revenue+VAT for **incremental** nights only |
| **VAT / Deferred** | Same as original policy |
| **Ops** | check_out/nights updated after docs posted |
| **Financial** | may move partially_paid if unpaid increment |
| **Activity** | `extension_invoiced` |
| **Reversal** | CN for extension doc |
| **Depends** | BC-09 |

---

## EVT-03 — Additional services

| | |
|--|--|
| **Trigger** | Extra service charge (unlocked charge or locked amendment) |
| **Documents** | `service_invoice` |
| **Debit / Credit** | AR / Revenue (+VAT) |
| **Activity** | `additional_charge_added` / `service_invoiced` |
| **Reversal** | CN |
| **Note** | Unlocked path today adjusts booking totals without invoice doc |

---

## EVT-04 — Damage

| | |
|--|--|
| **Trigger** | Damage charge / deposit deduction |
| **Documents** | `service_invoice` (line_type damage) and/or deposit forfeit (BC-11) |
| **Debit / Credit** | Needs income/AR vs deposit liability accounts — **BC-11** |
| **Activity** | `damage_charged` |
| **Reversal** | CN / deposit adjust |

---

## EVT-05 — Deposit hold / received

| | |
|--|--|
| **Trigger** | Set amount; receive funds |
| **Documents** | `ars_security_deposits` events `hold_set` / `received` |
| **Debit / Credit** | DR Cash/Bank; CR Deposit liability 2200 (Confirmed current) |
| **Activity** | `deposit_received` |
| **Reversal** | Deposit refund event |
| **Stripe** | GL only per BC-12 |

---

## EVT-06 — Deposit refund

| | |
|--|--|
| **Trigger** | Refund deposit to guest |
| **Documents** | deposit `full_refund` / `partial_refund` |
| **Debit / Credit** | DR 2200; CR Cash/Bank |
| **Activity** | `deposit_refunded` |
| **Evidence** | `ars_post_deposit_refund` |

---

## EVT-07 — Deposit forfeiture

| | |
|--|--|
| **Trigger** | Forfeit policy (Needs BC-10/11) |
| **Documents** | deposit `forfeit` ± damage invoice |
| **Debit / Credit** | DR 2200; CR income/AR — **unconfirmed accounts** |
| **Activity** | `deposit_forfeited` |
| **Reversal** | Manual correcting docs only |

---

## EVT-08 — Payment

| | |
|--|--|
| **Trigger** | Payment recorded / Stripe succeeded (settlement policy) |
| **Documents** | payment row + `ars_payment_allocations` |
| **Debit / Credit** | DR 1110/1210/clearing; CR 1310 |
| **Financial lifecycle** | partially_paid / paid |
| **Activity** | `payment_recorded` |
| **Reversal** | Refund event — do not delete payment |

---

## EVT-09 — Refund (stay)

| | |
|--|--|
| **Trigger** | Refund against stay payments |
| **Documents** | `ars_refunds` + often `credit_note` |
| **Debit / Credit** | Policy-dependent (BC-06/08/12) |
| **Activity** | `refund_posted` |
| **Reversal** | Correcting entry documents |

---

## EVT-10 — Credit note

| | |
|--|--|
| **Trigger** | Cancel unused value; rate correction down; early checkout credit |
| **Documents** | `credit_note` + meta; parent link |
| **Debit / Credit** | Typically DR Revenue/VAT; CR AR (or reverse of original lines) |
| **Activity** | `credit_note_posted` |
| **Reversal** | Rare; prefer new adjustment |

---

## EVT-11 — Cancellation

| | |
|--|--|
| **Trigger** | Cancel confirmed booking |
| **Documents** | Reverse original and/or CN + optional fee invoice (BC-06) |
| **Current** | Full journal reverse if `journal_id` set |
| **Ops** | status cancelled |
| **Activity** | `booking_cancelled` |
| **Target** | Adapter chooses reverse vs CN+fee per Confirmed policy |

---

## EVT-12 — No-show

| | |
|--|--|
| **Trigger** | Guest no-show |
| **Documents** | Per BC-07 (may equal cancel or fee keep revenue) |
| **Activity** | `no_show_assessed` |
| **Depends** | BC-07 |

---

## EVT-13 — Early checkout

| | |
|--|--|
| **Trigger** | Early departure with financial impact |
| **Documents** | CN for unused nights and/or keep fee (BC-08) |
| **Activity** | `early_checkout_settled` |
| **Depends** | BC-08 |

---

## EVT-14 — Stripe settlement

| | |
|--|--|
| **Trigger** | Payout / success capture |
| **Documents** | Payment + optional clearing transfer |
| **Debit / Credit** | Clearing ↔ Bank (BC-12/13) |
| **Activity** | `stripe_settled` |
| **Current** | No GL from Stripe module |

---

## EVT-15 — Stripe fees

| | |
|--|--|
| **Trigger** | Fee on charge/payout |
| **Documents** | Fee line or expense journal — **Needs BC-12** |
| **Debit / Credit** | Fee expense / clearing |
| **Activity** | `stripe_fee_posted` |

---

## EVT-16 — Adjustments

| | |
|--|--|
| **Trigger** | Rate/promo correction after lock |
| **Documents** | `adjustment_invoice` or CN |
| **Activity** | `adjustment_posted` |
| **Reversal** | Further adjusting docs |

---

## Event → Activity Center category map

| Events | Typical category |
|--------|------------------|
| Confirm, cancel, check-in/out, no-show | operational (+ accounting when docs) |
| Invoice, CN, extension, adjustment | accounting / financial |
| Payment, refund, stripe | payment |
| Deposit* | financial |
| Damage/service | financial |

---

## Catalog status

| Event | Ready for Phase 2B mirror of today? | Blocked on BC? |
|-------|-------------------------------------|----------------|
| EVT-01,05,06,08 | Yes (mirror current) | BC-01 for company target |
| EVT-02–04,07,09–16 | Design only | Yes as noted |

**No Phase 2B posting of blocked events until Confirmed.**
