# Phase 3B — Implementation Plan (Waves)

**Status:** Master wave map **approved**. Execute **one wave at a time** with a dedicated detailed plan. Stay portal **excluded**. Wave 0 authorized separately.  
**Financial Core v1.0 remains frozen.**

---

## Wave 0 — Design-system technical foundation

- Scope: tokens, Tailwind build scoped to ARS, Alpine/Lucide install, PHP component stubs, coexistence docs  
- Files: `modules/ars/assets/*`, `views/components/*`, package config if approved  
- Fin touch: none  
- Risk: CSS bleed into ERP — mitigate with `#ars-app` scope  
- Test: sample component page in non-routed prototype first, then opt-in flag  
- Rollback: remove asset includes  

## Wave 1 — Application shell & navigation

- New sidebar IA; fix empty accounting; promote finance reports  
- Risk: permission leakage — permission-review  
- Rollback: restore layout header  

## Wave 2 — Command Center

- Replace dashboard widgets; role variants  
- Fin: read-only KPIs  

## Wave 3 — Booking wizard

- New create flow; keep old route redirect optional  
- Fin: confirm/pay via existing endpoints  

## Wave 4 — Booking workspace

- Redesign booking_view regions; drawers for money  
- **Highest fin-core touch (UI only)**  
- Test: 2D UAT still green; manual money paths  

## Wave 5 — Occupancy calendar

- Board UI; no unsafe drag-post  
- Test: conflict prevention  

## Wave 6 — Housekeeping, Maintenance, Guests and Units

- Boards; mobile actions  

## Wave 7 — Financial presentation

- Finance hub; document UX; credit/refund presentation  
- Adapter still default OFF  

## Wave 8 — Reports & settings

- Settings danger UX; report IA  

## Wave 9 — Mobile & accessibility

- Bottom nav; a11y pass  

## Wave 10 — Regression & polish

- Full UX regression; freeze UI baseline  

Each wave: acceptance criteria checklist, rollback (feature flag or previous layout include), no Class A/B file edits.
