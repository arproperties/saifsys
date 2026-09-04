# Phase 3A — Frontend Architecture

**Constraint:** Native PHP + MySQL ERP. No React/Vue SPA rewrite. Progressive enhancement.

---

## Approved direction

| Layer | Choice |
|-------|--------|
| CSS | Tailwind CSS (build preferred) with `--ars-*` tokens |
| JS | Alpine.js for UI state; existing vanilla for AJAX contracts |
| Icons | Lucide |
| Views | PHP partials `modules/ars/views/components/` |
| Logic | Server-side only |

## Tailwind integration

1. **Wave 0:** Add ARS-scoped build (`modules/ars/assets/src/` → `ars-app.css`) with content paths limited to ARS PHP/views.  
2. Avoid global Tailwind preflight colliding with Bootstrap: use `important` selector strategy `#ars-app` or disable preflight and use utility-only.  
3. **CDN Tailwind only for prototypes** under `docs/ars/prototypes/` — not production.  

## Bootstrap coexistence

- Existing pages keep Bootstrap until migrated.  
- Migrated pages wrap in `#ars-app` and load `ars-app.css` + Alpine.  
- Do not load conflicting icon fonts on same page longer than transition.  
- Shared ERP embeds (expenses/COA) may remain Bootstrap islands.

## Alpine

- `x-data` per drawer/wizard/filter.  
- Fetch existing AJAX endpoints; CSRF from `window.ARS_CSRF`.  
- No posting math in Alpine.

## Assets / cache

`?v=filemtime` pattern continues; hashed build output for Tailwind bundle.

## CSP

Prefer self-hosted Alpine/Lucide/Tailwind build over new CDNs in production. Prototype CDNs OK in docs only.

## Performance

Code-split calendar; lazy drawers; avoid large date libraries unless needed.

## Testing

Visual regression on migrated routes; contract tests unchanged for AJAX; a11y checks per wave; financial UAT harness untouched (Class A tools only if hygiene).

## Forbidden

Duplicate posting JS; enabling adapter by default; editing protected financial PHP from UI work.


## Stay portal & mobile APIs

- Tailwind content scan must **exclude** `stay/`.
- Do not load `#ars-app` / Wave assets into Stay pages.
- Do not modify `api/customer/**` contracts.
- See `PHASE3_SCOPE_CORRECTION_STAY_PORTAL_EXCLUDED.md`.
