# Phase 3B — Final Architecture Report

## Stack

| Layer | Choice |
|-------|--------|
| Layout | `ars_shell.php` (Wave 1, frozen) |
| Components | `ars_ui.php` (Wave 0) + `ars_ds.php` (extensions) |
| CSS | Scoped Tailwind `#ars-app`, preflight off |
| JS | Alpine UI state + existing vanilla AJAX |
| Icons | Lucide |
| Coexistence | `legacy_bootstrap` per page |

## Page migration model

```
Opt-in page → ars_shell_begin → workspace → ars_shell_end
Non-opt-in → legacy ars_layout_* (if any remain)
```

## Financial boundary

UI calls existing AJAX / POST / adapter contracts. No posting logic in Alpine/JS. No `accounting_engine.php` edits.

## Design system reuse

`ars_ui_*` / `ars_ds_*` are module-agnostic presentation helpers intended as ERP reference components (RE/Construction/HR later).

## Out of scope

Stay portal, mobile app UI, customer API contracts, offline queues, dark mode program.
