# Construction UI v2 — Design Layer (legacy pilot notes)

**Superseded for module-wide guidance by** [`DESIGN_SYSTEM.md`](DESIGN_SYSTEM.md).

**Scope:** Construction module only (`modules/construction/`).  
**Status:** Promoted to Construction Design System default (2026-07-12).  
**Constraint:** Presentation only — no accounting, schema (except `erp_module_themes`), permissions on business features, or route changes.

## History

UI v2 began as an opt-in pilot (`$coUiV2 = true`) on Commercial Leasing pages. The layout now defaults the design system on for all Construction pages. Set `$coUiV2 = false` before the header to opt out.

## Theme

Company-scoped colors: Owner → **Theme Settings** (`theme_settings.php`) → table `erp_module_themes`.

See [`DESIGN_SYSTEM.md`](DESIGN_SYSTEM.md) for tokens, helpers, and migration.
