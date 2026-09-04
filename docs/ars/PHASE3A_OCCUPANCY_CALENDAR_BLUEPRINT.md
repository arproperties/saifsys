# Phase 3A — Occupancy & Availability Calendar Blueprint

**Replaces:** Month table `calendar.php` as primary inventory view.

---

## Model

- **Rows:** Units (group by property / unit type)  
- **Columns:** Days (day / week / month context)  
- **Bars:** Bookings with status colour + arrival/departure markers  
- **Overlays:** Cleaning status, maintenance blocks, unit blocks  

## Interactions

| Action | Behaviour |
|--------|-----------|
| Click empty range | Open wizard prefilled |
| Click booking bar | Preview popover → open Workspace |
| Hover | Guest, dates, pay status |
| Filter | Property, type, status, HK state |
| Search | Unit #, guest, booking ref |
| Date change | **Not** free drag-post; opens confirm → approved backend workflow |

## Drag-and-drop (optional later)

- **Allowed only as UX gesture** that opens confirmation calling existing extend/move APIs.  
- **Forbidden:** Client-side financial mutation; silent date change on locked bookings.

## Performance

Virtualize rows for large inventories; load bars by viewport date range; debounce filters.

## Mobile alternative

Agenda list: Arrivals / Departures / In-house by day; unit picker + mini week strip — not a tiny Gantt.

## Conflict display

Overlapping bars red outline; blocked cells hatched; never allow create into conflict.
