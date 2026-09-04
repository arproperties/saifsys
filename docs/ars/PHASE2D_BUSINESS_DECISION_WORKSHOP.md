# Phase 2D — Business Decision Workshop

**Status:** Interim localhost decisions proposed for Phase 2D implementation  
**Environment:** LOCALHOST ONLY  
**Authority basis:** Phase 2A recommended options + Confirmed current ARS code behaviour + Phase 2D directive to close gates without inventing fee formulas (use configurable settings; defaults preserve current behaviour)  
**Human ratification:** Still recommended before any non-local enablement  

---

## How to read this document

Each item was **BLOCKED** in Phase 2C. Phase 2D adopts an **Interim Localhost Rule** so workflows can be implemented and tested. Fee **amounts/percentages** are never hard-coded as universal commercial law — they live in `ars_financial_policy` settings (default = current behaviour: no automatic fee).

Evidence labels: **Confirmed** (code), **Strong inference** (Phase 2A recommendation + current behaviour), **Configurable** (settings), **N/A**.

---

## Inventory of Phase 2C blocked / skipped items

| ID | Scenario | Root BC |
|----|----------|---------|
| WF-CREDIT-01 | Guest credit / overpayment | BC-15 + Phase 2D req |
| WF-EXT-01 | Extension invoice | BC-09 |
| WF-SVC-01 | Additional services / damage invoice | BC-11 |
| WF-ADJ-01 | Adjustment invoice | BC-06/08 |
| WF-CN-01 | Credit notes | BC-06/08 |
| WF-FORF-01 | Deposit forfeiture | BC-10/11 |
| WF-REF-01 | Guest stay refund | BC-06/08/12 |
| WF-STRIPE-01 | Stripe settlement | BC-12/13 |
| WF-NOSHOW-01 | No-show fee | BC-07 |
| WF-EARLY-01 | Early checkout | BC-08 |
| WF-STRIPE-UI-01 | Live Stripe charge | External — simulate only |

Additional gaps closed in 2D: shortening, cancel fee (optional), deferred (confirm unused), tourism (N/A), VAT continuity, guest credit apply/refund.

---

## BC-01 — Posting company

| | |
|--|--|
| **Scenario** | Which company owns ARS journals |
| **Blocked why** | Multi-entity bank reco ambiguity |
| **Current** | `booking.company_id` (ARS) — Confirmed |
| **Options** | A ARS / B RE primary / C mapping |
| **Interim rule** | **A** — continue `booking.company_id` |
| **User approval** | Required before changing to B/C |
| **Status** | Interim Confirmed for localhost |

---

## BC-02 / BC-03 — Revenue recognition & deferred

| | |
|--|--|
| **Scenario** | When room revenue hits P&L |
| **Current** | On confirm; 2400 unused — Confirmed |
| **Options** | A confirm / B check-in / C deferred nightly |
| **Interim rule** | **A + BC-03 A** — recognize on confirm; do **not** post deferred |
| **Accounting** | DR AR / CR Room Revenue / CR VAT (unchanged mirror) |
| **Risk** | Cancel after confirm needs reverse/CN (already designed) |
| **User approval** | Required to switch to B/C |
| **Status** | Interim Confirmed |

---

## BC-04 — VAT

| | |
|--|--|
| **Interim rule** | Preserve booking `vat_mode` + `vat_rate` on all new documents; CN reverses VAT proportionally |
| **Status** | Interim Confirmed (matches current) |

---

## BC-05 — Tourism / municipality fees

| | |
|--|--|
| **Current** | No GL — Confirmed |
| **Interim rule** | **A — Not required.** No tourism fee posting. |
| **If later required** | New BC + configurable charge type — stop and re-approve |
| **Status** | Interim Confirmed N/A |

---

## BC-06 — Cancellation

| | |
|--|--|
| **Current** | Reverse revenue journal; no fee — Confirmed |
| **Options** | A full reverse / B fee+CN / C tiered |
| **Interim rule** | **A by default.** Optional cancellation fee if `cancellation_fee_percent` > 0: post fee as `adjustment_invoice` (Late Fee / Other Revenue role) **before** CN/reverse of remainder. Default percent = **0**. |
| **Does not invent** | Tier schedules — only optional flat % of invoice total from settings |
| **Status** | Interim Confirmed |

---

## BC-07 — No-show

| | |
|--|--|
| **Options** | Same as cancel / separate fee / keep full revenue |
| **Interim rule** | **Keep recognized revenue** (invoice stays); set booking cancelled with reason `no_show`; optional `no_show_fee_mode=keep_revenue` (default). Alternative setting `reverse_like_cancel` uses BC-06 path. |
| **Deposit** | Refundable via normal deposit refund unless forfeit flagged |
| **Status** | Interim Confirmed (configurable) |

---

## BC-08 — Early checkout / shortening

| | |
|--|--|
| **Interim rule** | Issue **credit_note** for unused nights (net+VAT) at booking nightly rate; never rewrite original invoice. Unused amount → guest credit (default) or cash/bank refund if `refund_method` provided. |
| **Non-refundable** | If `early_checkout_refundable=0`, no CN (ops date change only) — default **1** (refundable) matching common guest-friendly interim |
| **Status** | Interim Confirmed — **human should ratify refundable default** |

---

## BC-09 — Extensions

| | |
|--|--|
| **Interim rule** | Always **extension_invoice** for added nights under same booking; rate = `rate_override` else `nightly_rate`; availability recheck; never duplicate booking |
| **VAT** | Same vat_mode/rate as booking |
| **Status** | Interim Confirmed |

---

## BC-10 / BC-11 — Deposit forfeit & damage

| | |
|--|--|
| **Damage** | `service_invoice` line_type `damage`; revenue role `DAMAGE_REVENUE` → 4200 |
| **Deposit apply** | Optional: forfeit/deduct from 2200 up to min(damage, open deposit); remainder on AR |
| **Forfeit without damage** | DR 2200 / CR `FORFEIT_REVENUE` (4900); reason required |
| **No double charge** | Deposit deduction reduces invoice balance_due / allocations tracked |
| **Status** | Interim Confirmed |

---

## BC-12 / BC-13 — Stripe clearing & fees

| | |
|--|--|
| **Interim rule** | **Clearing model B:** card success → DR STRIPE_CLEARING / CR AR; settlement → DR BANK / DR STRIPE_FEE / CR CLEARING (fee from settings %, default 0 for sim) |
| **Accounts** | Seed 1130 clearing, 5510 fee expense if missing; roles mapped |
| **Live keys** | Never — localhost simulation only |
| **Status** | Interim Confirmed |

---

## BC-14 — Guest party

| | |
|--|--|
| **Interim rule** | Guests remain `ars_guests` (DEC-016) |
| **Status** | Interim Confirmed |

---

## BC-15 — AR + Guest credit

| | |
|--|--|
| **Overpayment** | Allocate to open docs; **remainder → guest credit liability** (not silent discard) |
| **Account** | Role `GUEST_CREDIT` → 2210 |
| **Apply** | Apply credit to documents (DR credit liability / CR AR) without new cash |
| **Refund credit** | DR credit / CR cash|bank |
| **Status** | Interim Confirmed (Phase 2D explicit requirement) |

---

## BC-16 (new) — Additional services catalog

| | |
|--|--|
| **Interim rule** | Free-form charge description + optional `service_code`; revenue `ADDITIONAL_SERVICE_REVENUE` → 4200; VAT per booking mode |
| **Status** | Interim Confirmed |

---

## BC-17 (new) — Adjustment invoices

| | |
|--|--|
| **Interim rule** | Positive adjustment_invoice for price increases; reductions use credit_note |
| **Status** | Interim Confirmed |

---

## Items requiring explicit human ratification before production

1. Changing BC-01 away from ARS company  
2. Switching to deferred/nightly recognition  
3. Enabling tourism fees  
4. Non-zero cancellation/no-show fee percents as commercial policy  
5. Early-checkout non-refundable default  
6. Live Stripe keys / real clearing COA on RE company  

---

## Sign-off

| Role | Decision | Date |
|------|----------|------|
| Phase 2D implementation authority | Adopt interim rules above for **localhost** | 2026-07-17 |
| Business owner (production) | _Pending ratification_ | |
| Finance | _Pending ratification_ | |
