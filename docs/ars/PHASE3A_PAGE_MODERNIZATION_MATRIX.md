# Phase 3A — Page Modernization Matrix

| Current file | Purpose | Quality | Future name | Structure | Components | Fin dep | Risk | Wave | Disposition |
|--------------|---------|---------|-------------|-----------|------------|---------|------|------|-------------|
| index.php | Dashboard | Med | Command Center | Widget grid | KPI, alerts, lists | Read | Med | 2 | Redesign |
| calendar.php | Month grid | Med | Occupancy Calendar | Gantt board | Bars, filters | None | High | 5 | Replace |
| bookings.php | List | Med | Reservations | Filters + table/cards | Status, search | Read | Low | 3 | Redesign |
| booking_add.php | Create | Med-Low | Booking Wizard | Multi-step | Selectors, summary | On confirm | High | 3 | Replace |
| booking_view.php | Hub | Low clarity | Booking Workspace | Header+tabs+side | Money, timeline | **Heavy** | High | 4 | Redesign |
| units.php | List | Med | Units | Cards | Status | None | Low | 6 | Redesign |
| unit_edit.php | Edit | Med | Unit Workspace | Form regions | Photos | None | Med | 6 | Merge |
| unit_profile.php | Profile | Med | Unit Workspace | History tab | Timeline | Partial | Med | 6 | Merge |
| unit_history_search.php | Search | Med | Units/Guests search | Global search | — | None | Low | 1 | Merge |
| guests.php | Directory | Med | Guests | Table/cards | Search | None | Low | 6 | Redesign |
| guest_view.php | Profile | Med | Guest Profile | Summary+history | Credit glance | Read | Med | 6 | Redesign |
| pricing.php | Rules | Med | Pricing | Keep + polish | Modals→drawers | None | Low | 8 | Keep |
| housekeeping.php | HK | Med | HK Board | Kanban | Cards | None | Med | 6 | Replace |
| blocked_dates.php | Blocks | Med | Calendar/Ops | Integrated | — | None | Med | 5–6 | Merge |
| maintenance.php | Maint | Med | Maint Board | Kanban | Cards | None | Med | 6 | Replace |
| revenue.php | Revenue | Med | Finance reports | Hub tile | Charts optional | Read | Low | 7–8 | Merge |
| expenses* | Expenses | Med | Finance | Shared embed | — | Shared | Med | 8 | Keep |
| chart_of_accounts.php | COA | Med | Finance | Embed | — | Shared | Med | 8 | Keep |
| settings.php | Settings | Med | Settings | Sections + danger | Flag UX | Flag | High | 8 | Redesign |
| financial_reports.php | Adapter reports | Med | Finance hub | Tabs | Tables | **Yes** | Med | 7 | Promote |
| financial_document_view.php | Doc | Med | Document view | Summary | JV link | **Yes** | Med | 7 | Keep+polish |
| material_request* | Inv bridge | Med | Linked from workspace | Keep | — | None | Low | 8 | Keep |
| stay/* | Guest portal (inactive channel) | n/a | — | — | — | — | — | — | **LEGACY — OUT OF MODERNIZATION SCOPE** |

**Pages audited (staff):** 26 · **Stay:** 12 · **AJAX:** 7  


---

## Scope correction (approved)

The Stay web portal is **LEGACY — OUT OF MODERNIZATION SCOPE**. The published mobile application is the official guest channel. See [`PHASE3_SCOPE_CORRECTION_STAY_PORTAL_EXCLUDED.md`](PHASE3_SCOPE_CORRECTION_STAY_PORTAL_EXCLUDED.md). Phase 3 modernizes **internal staff ARS only**. Stay pages are excluded from acceptance counts.
