# Phase 3A — Information Architecture

**Goal:** One product — Holiday Homes / STR operations platform — not an ERP sidebar dump.

---

## Primary navigation (recommended)

| # | Area | Contains | Roles |
|---|------|----------|-------|
| 1 | **Command Center** | Today ops + alerts | All (role-weighted) |
| 2 | **Reservations** | List, Wizard, Workspace | Agents, GM |
| 3 | **Calendar** | Occupancy board | Agents, ops |
| 4 | **Guests** | Directory, profiles, credit glance | Agents, finance (read) |
| 5 | **Properties / Units** | Units, readiness, history | Ops, agents |
| 6 | **Operations** | HK, Maint, readiness, tasks | Ops depts |
| 7 | **Finance** | Booking money UX, documents, AR, deposits, settlements, reports | Finance + managers |
| 8 | **Activity Center** | Global activity feed (filterable) | Supervisors, finance |
| 9 | **Reports** | Occupancy, revenue, VAT (read) | GM, finance |
| 10 | **Settings** | Company, Stripe, policies (read), adapter (danger) | Admin |

**Retire / hide:** Empty `accounting/` link.  
**Promote:** `financial_reports.php`, `financial_document_view.php` under Finance.

---

## Secondary navigation

- **Reservations:** All / Arriving today / Departing today / In-house / Pending / Cancelled  
- **Operations:** Housekeeping | Maintenance | Blocks | Check-in readiness  
- **Finance:** Overview | Documents | Receivables | Deposits | Credits & refunds | Settlements | Chart of accounts | Expenses  

## Context navigation

Inside Booking Workspace: sticky subnav (Overview | Guest | Money | Ops | Documents | Timeline).

## Breadcrumbs

`ARS › Reservations › BK-… › Payments` — always deep-linkable.

## Global search

Cmd/Ctrl+K: bookings, guests, units, document numbers. Server-backed; no client financial math.

## Quick-create

+ menu: Reservation | Guest | Block | Maintenance | Housekeeping task | Note.

## Alerts

Top bar bell: late arrivals, dirty units blocking check-in, unpaid balances, deposit pending, refund queue, Stripe unsettled (when flag on).

## Role visibility

| Role | Hide |
|------|------|
| HK only | Finance posting, Settings, COA |
| Agent | Settings adapter, COA edits |
| Finance | HK assignment tools (optional) |
| Read-only | All write actions |

## Mobile nav

Bottom: Home | Calendar | Arrivals | Ops | More.

## Recently viewed / pins

Last 8 bookings/guests/units; pin Command Center widgets (Needs approval).
