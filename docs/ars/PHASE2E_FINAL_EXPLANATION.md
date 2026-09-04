# Phase 2E — Final Explanation

**ARS Financial Core v1.0**  
**Date:** 2026-07-17  

This document is the standalone guide for management and developers. You do not need to read the other Phase 2E files to understand the freeze.

---

## What was frozen

The complete ARS financial subsystem approved through Phases 2A–2D is frozen as **ARS Financial Core v1.0**, including:

- Financial Adapter and Phase 2D workflow extensions  
- Option B financial documents, lines, state machine, and transition audit  
- Account role mapping  
- Payments, allocations, guest credit, refunds, credit notes, adjustments  
- Extensions, early checkout, services, damage, deposits, forfeiture  
- Cancellation and no-show accounting  
- Stripe clearing / fee / settlement **simulation**  
- Revenue recognition on confirm (deferred unused)  
- VAT treatment tied to booking vat_mode  
- Financial locks, idempotency, company isolation  
- Reporting helpers and Activity Center financial events  
- Shared `accounting_engine.php` (unchanged)  
- Interim business rule register from Phase 2D  

Version marker (internal): `ARS_FINANCIAL_CORE_VERSION = 1.0` in `modules/ars/includes/ars_financial_core_version.php`.

---

## Why it was frozen

Phases 2A–2D delivered a validated financial core (24/24 UAT, 0 blocked). Phase 3 will redesign UX. Freezing prevents accidental changes to posting, VAT, recognition, or locks while screens are rebuilt.

---

## What Phase 3 may change

- Screens, navigation, layout, components, responsiveness, accessibility  
- Presentation view-models and **read-only** display queries  
- Filters, tables, cards, empty states, confirmations  
- Front-end validation that does **not** replace server financial validation  
- Safe UI wrappers that call approved adapter contracts  
- Activity Center **presentation**  

---

## What Phase 3 must not change

- Financial calculations, posting, journal construction  
- State-machine transitions or financial locks  
- Account-role mappings or hard-coded GL IDs  
- VAT / revenue recognition / refund / credit / deposit / Stripe rules  
- `accounting_engine.php`, Real Estate Invoice Mode, mobile/customer API contracts  
- Destructive schema changes  
- Permanent enablement of the Financial Adapter without approval  

---

## Protected interfaces

UI and AJAX must use contracts in `ARS_FINANCIAL_CORE_V1_INTERFACE_CONTRACTS.md` — primarily `ars_adapter_*` functions (and `ars_post_*` when the flag is off). Success/error shapes, idempotency, CSRF, and permissions remain as designed.

---

## Protected files

See `ARS_FINANCIAL_CORE_V1_PROTECTED_FILES.md`. Critical example:

- `accounting_engine.php` SHA-256: `9cbb880fc3241615b69d4fe5b3adfbee14e513d03964924214cc9325f9d7c7d6`  

**Protected manifest entries: 23** (Classes A/B/C–D listed).

---

## Database baseline

Schema-only reference: `ARS_FINANCIAL_CORE_V1_DATABASE_BASELINE.md` and `_ars_financial_core_v1_schema.sql`. No business data export. Feature flag default remains **OFF**.

---

## Verification results

| Metric | Result |
|--------|--------|
| Phase 2D UAT | 24 PASS / 0 FAIL / 0 BLOCKED |
| Engine SHA | Unchanged |
| Adapter after test | OFF |
| Orphans / missing journals / cross-company | 0 |
| `re_invoices` | Unchanged (190) |

---

## Version marker

`ARS Financial Core v1.0` — freeze date 2026-07-17.  
Bugfix → v1.0.1; enhancement → v1.1 — only via change control.

---

## Change-control process

Any financial-core change must be classified (defect / regulatory / approved rule amendment / controlled enhancement / integrity / performance-without-behaviour) and requires impact analysis, approval, tests, freeze-doc update, and version bump. **Do not bury financial changes inside Phase 3 UI PRs.**

Cursor rule: `.cursor/rules/14-module-ars/ars-financial-core-v1-freeze.mdc`.

---

## Can Phase 3A safely begin?

**Yes — after human approval of Phase 2E**, as a **UX audit / product strategy / design system** phase only.

Constraints remain:

- Localhost project rule for ARS  
- No production deploy  
- Adapter stays default OFF  
- No financial core edits  

**Phase 3A has not been started in this task.**

---

## Stop

Phase 2E complete. STOP. Do not begin Phase 3A in the same task. Do not redesign pages. Do not deploy.
