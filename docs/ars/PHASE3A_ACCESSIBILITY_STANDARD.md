# Phase 3A — Accessibility & Usability Standard

Target: **WCAG 2.1 AA** for staff ARS chrome (Phase 3B waves).

| Topic | Standard |
|-------|----------|
| Keyboard | All actions reachable; wizard steps arrow/tab; Esc closes overlay |
| Focus | Visible 2px ring; trap focus in modal/drawer; restore on close |
| Semantics | Landmarks, headings h1→h2, labelled inputs |
| Screen readers | Live region for toasts/errors; status text not colour-only |
| Contrast | Body ≥ 4.5:1; UI components ≥ 3:1 |
| Errors | `aria-invalid`, describedby, announce on submit fail |
| Required | Visible indicator + aria-required |
| Status | Icon + text + (optional) colour |
| Destructive | Confirm dialog; name the consequence |
| Touch | ≥ 44px targets |
| Motion | `prefers-reduced-motion: reduce` disables non-essential motion |
| Dates | Native or accessible picker; not mouse-only |
| Tables | Caption/summary; sticky header; row headers where useful |
| Modals | `role="dialog"` aria-modal labelledby |

Financial lock: announce “Financially locked” when focus lands on disabled money control.
