# Phase 3A — Booking Workspace Blueprint

**Replaces:** Dense single-page `booking_view.php` as primary ops surface.  
**Financial Core:** All money actions → existing AJAX → adapter; UI shows lock/disabled with reason.

---

## Header (always visible)

- Booking reference · Guest · Unit · Stay dates · Booking status · Financial status · Lock badge  
- Primary actions: Check-in / Check-out / Collect payment / Message note  
- Overflow: Cancel, No-show, Extend, Shorten, Print, Material request  

## Main composition

| Region | Content | Pattern |
|--------|---------|---------|
| Stay overview | Dates, nights, channel, adults | Always visible |
| Guest | Contact, ID, preferences | Collapsible |
| Charges | Line items, server totals | Panel |
| Payments | Receipts, allocations, due | Panel + drawer actions |
| Deposit | Held / refunded / forfeited | Separate visual from stay |
| Services | Add-ons | Drawer |
| Housekeeping | Status, last clean | Chip + link |
| Maintenance | Open tickets | Chip + link |
| Documents | Financial docs, invoices | Table + deep link |
| Notes | Internal notes | Inline |
| Timeline | Activity Center full feed | Sticky bottom or tab |

## Side panel (desktop)

Quick actions · Alerts · Financial summary (due/paid/credit/deposit) · Ops checklist (ready for CI/CO).

## Tabs vs drawers

- Tabs: Overview | Money | Ops | Documents | Timeline  
- Drawers: Payment, Deposit, Service, Extension request  
- Modals: Confirm financial post / cancel / forfeit  

## Action classes

| Class | Examples | UI |
|-------|----------|-----|
| Primary | Check-in, Collect payment | Solid primary |
| Secondary | Add note, Print | Outline |
| Dangerous | Cancel, Forfeit, Refund | Danger + confirm |
| Locked | Amend after confirm | Disabled + “Request amendment / CN path” |
| Permission-denied | Hide or disabled with reason | Tooltip |

## Mobile

Header compact; bottom sticky actions; panels as stacked accordion; timeline separate screen.

## Permission & finance

Accountant sees Money + Documents first; HK sees Ops checklist; never expose adapter flag here.
