# Construction Contractor ↔ Supplier Business Partner — Program Complete

| Field | Value |
|-------|-------|
| Status | **IMPLEMENTED — NOT PRODUCTION COMPLETE** (verification blockers open; see Production Verification Report) |
| BR | [`docs/business-rules/CO_CONTRACTOR_SUPPLIER_LINK.md`](../business-rules/CO_CONTRACTOR_SUPPLIER_LINK.md) |
| Migration | [`migrations/construction_contractor_supplier_link.sql`](../../migrations/construction_contractor_supplier_link.sql) |

## What shipped

1. **Business Partner link** — `co_contractors.supplier_id` → `co_suppliers` (company-scoped 1:1).
2. **Bidirectional UX** — Contractor dashboard shows linked Supplier + commercial KPIs; Supplier profile shows Linked Contractor, projects, contract value, quick link.
3. **Commercial progress helpers** — `construction_contractor_supplier_helpers.php` (`co_contractor_commercial_progress`, project-scoped variants; extensible for Certification/VO).
4. **Payment CTAs** — Project/Contractor Payment redirects to `supplier_payment_add.php` with supplier + project defaults; old POST path returns 410.
5. **Reports** — Cost rollups no longer include `co_contractor_payments`; Contractor Summary uses Supplier/AP progress.
6. Admin retirement tool — `modules/construction/tools/contractor_payment_retirement.php`
   - **Verified Match** (BR-CO-BP-004): confirm Supplier/AP reference, reverse, delete, audit  
   - **Business Approved Legacy Retirement** (BR-CO-BP-007): no match required; admin confirmations + mandatory reason; single-row or bulk (`RETIRE ALL LEGACY`); reverse via `reverse_journal`; full audit with `retirement_mode`  
   Does **not** auto-delete without admin action.

## Local inventory (at implementation)

Company 3 had **11** `co_contractor_payments` rows (Aladeeb + Bab Alyaqoot). Retire via admin tool after verifying each against Supplier/AP.

## Production deploy

1. Run `migrations/construction_contractor_supplier_link.sql`.
2. Deploy PHP/UI.
3. Link each Main Contractor to its Supplier (or Create Supplier from Contractor).
4. Confirm Payment from Project lands in Supplier Payment Workspace.
5. Use **Retire Old Contractor Payments** for each historical row (financial dept).
6. Spot-check: contractor dashboard KPIs, project contractor strip, contractor summary report, cost reports, reversed journals.

## Out of scope (unchanged)

- Accounting engine redesign  
- Invoice-level retention in Supplier/AP  
- Dropping `co_contractor_payments` table  
- Merging contractor/supplier masters  
