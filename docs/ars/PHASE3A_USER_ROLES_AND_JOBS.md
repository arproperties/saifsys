# Phase 3A — User Roles and Jobs

**Status:** Strategy — Needs human confirmation of role names vs org chart  

---

## Role catalog

| Role | Daily focus | Top screens (today → future) | Mobile | Fin access | Pain points |
|------|-------------|------------------------------|--------|------------|-------------|
| Reservation agent | Create/confirm bookings, answer availability | Bookings, Calendar, booking_add/view → Wizard, Occupancy, Workspace | Medium | Payments/deposits via ops | Long forms; calendar weak; overbooking fear |
| Guest service / reception | Arrivals, check-in/out, guest questions | Booking view, guests → Workspace, Arrivals strip | High | Record payment, deposit | Dense booking page; unclear ready status |
| Operations supervisor | Day plan, exceptions | Dashboard, HK, maint → Command Center | Medium | Read financial alerts | Dashboard not operational |
| Housekeeping coordinator | Assign/complete cleans | housekeeping.php → HK board | High | None | List not board; poor unit readiness link |
| Maintenance coordinator | Blocks & tickets | maintenance, blocked_dates → Maint board + calendar blocks | Medium | None | Split from calendar |
| Accountant | Docs, AR, deposits, VAT | revenue, financial_reports, COA → Finance hub | Low | Full read; posting via contracts | Reports split; docs not in nav |
| Finance manager | Exceptions, refunds, settlements | Same + settings policy | Low | Approvals (future UI) | Stripe/settlement UX missing |
| General manager | Occupancy & revenue glance | index → Command Center | Medium | Read KPIs | No single “today” view |
| Administrator | Settings, Stripe, flag, users | settings | Low | Flag (dangerous) | Adapter toggle without strong warnings |
| Read-only management | View only | Reports/dashboard | Low | Read | No dedicated read chrome |

## Department mapping (current code)

| Dept | Access |
|------|--------|
| `DEPT_ARS_CORE` | Management + Finance + Settings |
| `DEPT_ARS_OPERATIONS` | Housekeeping, Blocked Dates, Maintenance |

**Gap:** No fine-grained “finance only” vs “reservations only” in UI chrome beyond dept. Phase 3B should map menu visibility to roles more clearly without inventing new permission models without approval.

## Sensitive financial actions (must stay behind contracts + CSRF)

Confirm booking (invoice), record payment, receive/refund/forfeit deposit, credit/refund, CN/extension (when UI wired), cancel financials, Stripe settlement sim, toggle adapter flag.
