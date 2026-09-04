# Construction Module — Phases Overview

## Phase 1 (MVP) — DONE
- **Migration:** `construction_module_phase1.sql`
- **Scope:** Companies (construction type), co_clients, co_projects, co_contractors, co_project_contractors, co_contractor_payments, co_project_costs, co_project_labor, co_material_issues, client invoices/payments, reports (cost summary, budget vs actual, contractor summary), setup.

## Phase 2 — DONE
| Migration | Tables | Features |
|-----------|--------|----------|
| `construction_phase2_project_phases.sql` | co_project_phases | Phases/milestones per project, progress % |
| `construction_phase2_variation_orders.sql` | co_variation_orders | VO number, amount, status (draft/approved/rejected) |
| `construction_phase2_retention_releases.sql` | co_retention_releases | Release retention, post to accounting |
| `construction_phase2_work_orders.sql` | co_work_orders | Subcontractor work orders per project–contractor |
| `construction_phase2_documents.sql` | co_documents | Project & contractor documents (file upload) |

**Phase 2 UI:** Project phases (list/add/edit), variation orders (list/add/edit), retention release (add + accounting), work orders (list/add/edit), project profitability report, documents (list/add/edit/delete, file upload), nav links for Retention Release & Work Orders.

## Phase 3 — Submittals & RFIs
- **Migration:** `construction_phase3_submittals_rfis.sql`
- **Tables:** co_submittals, co_rfis
- **Features:** Project submittals (drawings/specs/samples) with status workflow; Request for Information (RFI) log per project with response tracking.
