# Phase 3A — Responsive & Mobile Strategy

**Principle:** Mobile is a different job (field ops), not a shrunk desktop.

---

## Patterns by surface

| Surface | Mobile pattern |
|---------|----------------|
| Command Center | Card stack; priority alerts first |
| Booking search | Full-screen search → results cards |
| Booking create | Wizard full-bleed steps |
| Booking workspace | Accordion + sticky bottom bar |
| Arrivals / departures | Day lists with call/WhatsApp actions |
| Housekeeping / maint | Kanban as swipe columns or list+filter |
| Guest lookup | Search-first |
| Payment / deposit | Drawer → confirm sheet |
| Activity / alerts | Feed cards |

## Chrome

- **Bottom nav:** Home · Calendar · Arrivals · Ops · More  
- **Sticky action bar:** 1–2 primary actions  
- **Drawers** over modals for forms  

## Tables → cards

Lists become cards with key fields + overflow menu. Tables only landscape tablet+.

## Touch

Min 44×44px; 8px gaps; no hover-only actions.

## Density

Reduce secondary meta; progressive disclosure.

## Network

Show explicit fail toasts; disable double-submit; optional optimistic UI only for non-financial (notes). Financial always await server.


## Stay portal

**Out of scope.** Internal staff mobile patterns only. Do not redesign Stay responsive layouts under Phase 3. See `PHASE3_SCOPE_CORRECTION_STAY_PORTAL_EXCLUDED.md`.
