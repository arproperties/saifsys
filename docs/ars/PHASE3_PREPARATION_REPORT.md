# Phase 3 — Preparation Report

**Status:** Preparation only — **Phase 3 not started**  
**Inputs:** Phase 2C UAT, accounting validation, UX walkthrough of current ARS screens  
**Constraint:** Do not redesign UI in this document’s delivery phase; recommendations only  

---

## Purpose

Capture operational UX friction and product opportunities so Phase 3 (Premium UX & Workflow Modernization) can start with a clear backlog after human approval of Phase 2C.

---

## Current operational UX findings (Phase 2C)

| Finding | Location | Impact | Phase 3 idea |
|---------|----------|--------|--------------|
| Single booking page is very dense | `booking_view.php` | High cognitive load | Tabbed hub: Ops / Money / Activity / Guest |
| Lifecycle buttons scattered vs Quick Actions | Header + right rail | Extra scanning | Unified action bar with status-aware enables |
| Locked booking disables service/damage without guided path | Quick Actions disabled | Confusion | Amendment wizard → preview docs → confirm |
| Extension via lifecycle requests is multi-step | Lifecycle section | Too many clicks | Booking Wizard step: dates → price → documents |
| Financial docs not prominent after confirm | Deep link via Activity | Easy to miss | “Money” tab: invoices, allocations, journals |
| Deposit vs stay payment conceptually mixed | Payments + deposit cards | Mis-posting risk | Clear labels + warnings (deposit ≠ rent) |
| No-show / early checkout fee not explained | Cancel / checkout | Staff may invent amounts | Policy banners + BC-driven fee calculator |
| Reports split (ARS financial vs RE TB) | Separate modules | Finance hunting | Financial dashboard deep-linking to RE reports |
| Calendar / occupancy not first-class in UAT scope | Bookings list | Ops planning hard | Occupancy calendar + conflict warnings |
| HK / maintenance boards exist but separate | `housekeeping.php`, `maintenance.php` | Context switch | Embed status chips on booking + unit board |
| Mobile responsiveness of booking_view | Bootstrap layout | Tablet awkward | Responsive Phase 3 polish |
| Missing confirmations on money actions | Some modals only | Accidental posts | Explicit “Posts journal / creates invoice” copy |
| Activity Center strong but filters easy to miss | Activity card | Underused | Sticky filters + deep-link chips |

---

## Recommended Phase 3 workstreams

### 1. Dashboard improvements
- Today’s arrivals / departures / in-house
- Unpaid balances & open deposits widgets (Option B reports)
- Activity exceptions (failed payments, locked amendment needed)

### 2. Booking Wizard
- Create/edit with live pricing
- Confirm step shows financial preview (invoice totals, VAT)
- Amendment wizard for locked bookings (extension, shorten, services, damage)

### 3. Calendar & occupancy
- Unit × date grid with booking status colours
- Blocked dates / maintenance overlays
- Drag-resize only via amendment when locked

### 4. Housekeeping board
- Kanban by status with booking deep links
- Check-in/out driven task templates

### 5. Maintenance board
- Unit/booking prefill already partial — elevate to board + SLA cues

### 6. Guest profile enhancements
- Stay history, statements, open AR, deposit liability
- Document vault shortcuts

### 7. Financial dashboards
- AR ageing from Option B
- Deposit liability
- Revenue by unit/property (after BC revenue rules)
- One-click to shared TB / bank reco (company-aware)

### 8. Mobile responsiveness
- Priority: booking hub, calendar, Quick Actions
- Preserve Activity Center as signature surface (Phase 1B intent)

### 9. Premium UI/UX
- Alpine/Lucide parity with ERP standards
- Reduce clicks for confirm → pay → check-in happy path
- Stronger empty states and permission-aware disabled reasons

---

## Dependencies before Phase 3 coding

1. **Human approval of Phase 2C** deliverables.  
2. Prefer progress on **BC sheet** for any UI that promises automatic financial documents (extension, CN, forfeit, Stripe).  
3. Keep **adapter flag OFF** on any non-local environment until separate approval.  
4. Do **not** invent fee formulas in UX copy — show “policy pending” where BC open.

---

## Out of scope for Phase 3 kickoff

- Production deployment  
- Live migrations  
- Unlocking BC-gated adapter methods without Confirmed rules  
- Rewriting historical journals  

---

## Suggested Phase 3 acceptance themes (future)

- Happy-path confirm→pay→check-in ≤ N clicks (target TBD with ops)
- Amendment wizard always shows document preview from `ars_adapter_detect_amendment_documents`
- Financial tab reconciles to Activity + journals
- Calendar prevents silent locked-field edits

---

## Stop

This document prepares Phase 3 only. **Do not begin Phase 3 implementation until Phase 2C is formally approved.**
