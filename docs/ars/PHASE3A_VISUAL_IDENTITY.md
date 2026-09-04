# Phase 3A — ARS Visual Identity

**Personality:** Calm hospitality operations — confident, warm, precise. Not flashy SaaS purple. Not generic ERP grey. Related to HeroSysgro brand, distinct as a product vertical.

**Tone:** Premium, quiet, trustworthy. Dense when needed; never noisy.

---

## Colour direction (proposal — Needs approval)

| Role | Direction | Notes |
|------|-----------|-------|
| Primary | Deep teal / ink (`#0F4C5C` family) | Hospitality trust; distinct from ERP maroon |
| Accent | Soft sand / champagne (`#C4A574`) | Warmth; use sparingly |
| Surface | Warm off-white `#F7F5F2` + pure white cards | Avoid cold grey #F4F1EA bias clusters |
| Text | Near-black `#1A1A1A` / muted `#5C5C5C` | |
| Success / Warn / Danger / Info | Semantic set with icons + labels | Never colour-only |
| Financial posted | Cool slate + lock icon | |
| Financial draft/pending | Amber |
| Financial voided | Muted + strikethrough |

**ERP coexistence:** Keep HeroSysgro chrome/brand where required; ARS shell may use vertical accent tokens under `--ars-*` without overriding ERP financial status colours.

**Avoid:** Purple gradients, glassmorphism, multi-layer neon shadows, emoji as UI, over-rounded pills.

## Typography

| Role | Proposal |
|------|----------|
| UI | Clean geometric sans (e.g. Plus Jakarta Sans or similar — Needs approval) |
| Numbers | Tabular lining figures |
| Hierarchy | Page title 24/32 · Section 18/28 · Body 14/22 · Meta 12/16 |

## Spacing / radius / elevation

- 4px base scale; page gutters 16–24; section gaps 24–32  
- Radius: 6–10px controls; 12px cards max  
- Elevation: 1 soft shadow for floating panels only; tables flat  

## Icons

Lucide outline, 20px default, 16px dense. Status always icon + text.

## Motion

150–250ms ease; reduce-motion respects OS. No decorative loops.

## Dark mode

Optional later; Phase 3B ship light-first. If ERP dark-mode on, ensure ARS tokens don’t invert financial reds incorrectly.

## Contrast

WCAG AA body ≥ 4.5:1; large text ≥ 3:1; interactive focus ≥ 3:1 against adjacent.
