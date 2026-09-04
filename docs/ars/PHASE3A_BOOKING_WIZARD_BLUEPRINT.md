# Phase 3A — Booking Wizard Blueprint

**Replaces:** `booking_add.php` long form.  
**Financial:** Preview/totals from existing pricing + Financial Core contracts on confirm/pay. **No browser recalculation of VAT/revenue.**

---

## Entry points

- Quick-create → New reservation  
- Calendar empty cell → prefill dates/unit  
- Command Center → New booking  

Search-first: dates + guests + property/unit type before guest PII.

## Steps

| Step | Name | Content | Validation |
|------|------|---------|------------|
| 1 | Stay & availability | Check-in/out, nights, adults/children, property filter | Dates valid; min stay |
| 2 | Unit selection | Available units with rate hint; conflict/overbook block | Unit free; capacity |
| 3 | Guest | Lookup returning guest; create if new; duplicate email/phone warn | Required identity |
| 4 | Pricing & charges | Server price preview; extras list | Accept totals |
| 5 | Services & deposit | Optional services; deposit policy display | Policy shown |
| 6 | Payment arrangement | Method intent (collect now / later / Stripe link) | Method allowed |
| 7 | Review & confirm | Full summary; confirm posts via existing confirm path | Explicit confirm |

## Behaviours

- **Draft:** Optional save as pending (existing pending expiry).  
- **Conflict prevention:** Server availability check before each step advance.  
- **Overbooking:** Hard stop; never soft-overbook without human policy (Needs confirmation).  
- **Warnings:** Rate override, past dates, unpaid history guest.  
- **Keyboard:** Tab through steps; Enter advances when valid.  
- **Mobile:** Full-width steps; sticky Next/Back; summary sheet before confirm.  
- **Error recovery:** Stay on step; show field errors; preserve inputs.  
- **Activity:** Create/confirm events via Activity Center writers.  
- **Confirmation screen:** Booking ref, unit, dates, financial document # if posted, next actions (check-in prep, collect payment).

## Out of scope for wizard

Editing locked bookings; refunds; CN — those live in Workspace + adapter actions.
