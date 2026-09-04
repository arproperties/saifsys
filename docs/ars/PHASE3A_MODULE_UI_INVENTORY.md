# Phase 3A — Module UI Inventory

**Status:** Design audit only — no production UI changes  
**Date:** 2026-07-17  
**Stack today:** Bootstrap 5.3.3 + Bootstrap Icons + `ars_styles.css` + vanilla JS  
**Target stack (proposed):** Tailwind + Alpine + Lucide with controlled coexistence (see Frontend Architecture)  
**Financial Core:** v1.0 frozen — pages may **call** adapter contracts; must not alter them  

---

## Totals

| Surface | Count |
|---------|------:|
| Staff user-facing pages | **26** |
| Staff AJAX endpoints | **7** |
| Stay portal pages | **12** (+ logout) — **LEGACY — OUT OF MODERNIZATION SCOPE** |
| Stay AJAX | **1** |
| UI layout shells | **4** (ARS header/footer, Stay header/footer) |
| Empty stub | `modules/ars/accounting/` (sidebar link, no files) |

---

## Staff pages

| Path | Purpose | Primary user | Main actions | UI tech | UX quality | Problems | Fin-core | Disposition |
|------|---------|--------------|--------------|---------|------------|----------|----------|-------------|
| `index.php` | KPI dashboard | Manager / agent | View counts, open bookings | BS5 | Medium | Not ops “today” command center; weak urgency | Read payments MTD | **Redesign** → Command Center |
| `calendar.php` | Month unit grid | Agent / ops | Scan availability | BS5 table | Medium | Not PMS occupancy board; no week/day; no drag | None | **Replace** with Occupancy Calendar |
| `bookings.php` | Booking list | Agent | Filter, open, add | BS5 | Medium | Dense filters; weak status chips | Payment status display | **Redesign** |
| `booking_add.php` | Create booking | Agent | Price preview, save | BS5 + fetch | Medium–Low | Long form; not wizard; easy miss | May post historical revenue | **Replace** with Booking Wizard |
| `booking_view.php` | Booking hub | All ops | Lifecycle, pay, deposit, Activity | BS5 modals | High value / Low clarity | Dense; too many jobs; financial docs buried | **Heavy** via AJAX→adapter | **Redesign** → Booking Workspace |
| `units.php` | Unit list | Agent | Open unit | BS5 | Medium | Basic cards/table | None | Redesign |
| `unit_edit.php` | Edit unit + photos | Admin | Save, photos | BS5 | Medium | Split from profile | None | Merge into unit workspace |
| `unit_profile.php` | Unit history | Agent | View history | BS5 | Medium | Deposit fields mixed | Partial | Redesign |
| `unit_history_search.php` | History search | Agent | Search | BS5 | Medium | Discoverability | None | Merge into Guests/Units |
| `guests.php` | Guest directory | Agent | Search, open | BS5 | Medium | No credit/AR glance | None | Redesign |
| `guest_view.php` | Guest profile | Agent | Edit, history | BS5 | Medium | No financial summary | None | Redesign + credit/AR read |
| `pricing.php` | Rules & promos | Admin | CRUD modals | BS5 | Medium | Power-user only | None | Keep + polish |
| `housekeeping.php` | HK board | HK coord | Start/complete | BS5 | Medium | Not kanban; weak mobile | None | **Replace** board |
| `blocked_dates.php` | Blocks | Ops | CRUD | BS5 | Medium | Separate from calendar | None | Merge into calendar/ops |
| `maintenance.php` | Maint requests | Maint | Create/view | BS5 | Medium | Not board; limited SLA | None | **Replace** board |
| `revenue.php` | Revenue report | Finance | Filter totals | BS5 | Medium | Parallel to adapter reports | Read | Merge into Finance hub |
| `expenses.php` | ERP expenses | Finance | List | BS5 | Medium | Dual chrome with RE | Shared expenses | Keep wrapper + polish |
| `expense_add.php` / `expense_edit.php` | Expense forms | Finance | Post expense | RE embed | Medium | Context switch | Shared journals | Keep (shared) |
| `chart_of_accounts.php` | COA | Finance | Manage accounts | RE embed | Medium | Power user | Shared COA | Keep |
| `settings.php` | ARS settings | Admin | Stripe, adapter flag | BS5 | Medium | Adapter flag buried; risk | Flag config | Redesign + warnings |
| `financial_reports.php` | Adapter reports | Finance | Tabs AR/docs/deposits | BS5 | Medium | **Not in nav** | **Yes** read | Promote into Finance |
| `financial_document_view.php` | Doc deep link | Finance | View JV | BS5 | Medium | Not in nav | **Yes** | Keep + polish |
| Material request ×3 | Inventory bridge | Ops | Create/view | Inv+BS | Medium | Side quest | None | Keep linked from workspace |

## AJAX

| Path | Purpose | Fin-core | Disposition |
|------|---------|----------|-------------|
| `ajax_booking_actions.php` | Lifecycle + money | **Yes** | Keep contracts; UI wrappers only |
| `ajax_activity_actions.php` | Activity list/notes | No | Keep |
| `ajax_pricing_actions.php` | Pricing CRUD/preview | No | Keep |
| `ajax_blocked_dates_actions.php` | Blocks | No | Keep |
| `ajax_housekeeping_actions.php` | HK status | No | Keep |
| `ajax_maintenance_actions.php` | Create request | No | Keep |
| `ajax_photo_upload.php` | Photos | No | Keep |

## Stay portal (`/stay/`) — LEGACY — OUT OF MODERNIZATION SCOPE

**Do not modernize.** Official guest channel = published mobile app (App Store / Play Store).
Stay may remain for legacy compatibility. No Phase 3 assets, waves, or design-system work.
See `PHASE3_SCOPE_CORRECTION_STAY_PORTAL_EXCLUDED.md`.

### Inventory (compatibility awareness only; excluded from acceptance)


| Path | Purpose | Disposition |
|------|---------|-------------|
| Browse/search/unit/book | Guest acquisition | Separate guest design system; Phase 3B later wave |
| Dashboard/booking/profile | Guest self-service | Polish; payment display only |
| Auth pages | Login/register/reset | Standard |

## Nav gaps

- Finance section links empty `accounting/`  
- Adapter financial reports not in sidebar  
- No Command Center, no Occupancy board, no Guest credit UI  

## Recommendation summary

| Action | Count (approx) |
|--------|----------------|
| Redesign | 12 |
| Replace | 4 |
| Merge | 4 |
| Keep + polish | 8 |
| Retire (empty accounting/) | 1 stub |
