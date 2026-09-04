# Construction Company Module — Implementation Plan

**For:** Madar Alwadi Building Contracting  
**Document Version:** 1.0  
**Status:** DRAFT — Awaiting Review & Approval  
**Last Updated:** January 25, 2026

---

## 1. Executive Summary

This document outlines the implementation plan for a new **Construction Company Module** to be added to the existing multi-company ERP system. The module will support Madar Alwadi Building Contracting and operate in two modes: **Owner/Developer Projects** (building own properties) and **Client Projects** (contractor for external clients).

The module will:
- Follow the existing ERP architecture (modules, company isolation, shared systems)
- Be **project-centric** — all costs, materials, labor, billing, and reporting link to projects
- Reuse the **shared accounting engine** (already in Real Estate) — no duplicate accounting systems
- Implement in **phases** — Phase 1 MVP first, stabilize, then Phase 2, then Phase 3
- Be **mobile-friendly** — consistent with the Tenant Portal UI patterns

---

## 2. Current ERP Architecture (Brief)

| Component | Location | Usage |
|-----------|----------|--------|
| **Real Estate Module** | `modules/realestate/` | Full module with buildings, units, leases, billing, accounting |
| **Cleaning Module** | Root (`operation/`, `account/`) | Operations & accounts |
| **HR System** | `hr/` | Shared: employees, leave, payroll |
| **Accounting Engine** | `modules/realestate/accounting/` | Shared: chart of accounts, journals, GL, bank reconciliation |
| **Inventory** | (Arrange it to be a Separate Module) Referenced but not a full module yet | Mentioned in requirements; integration TBD |
| **Company Isolation** | `company_id` on all tables | `current_company_id()`, `user_companies` |
| **Permissions** | `rbac_department.php`, `module_access.php` | Role → Module → Department |

**Key patterns:**
- `company_id` on every financial and transactional table
- `business_type` on `companies` (e.g. `cleaning`, `realestate`) — Construction will add `construction`
- Module routes: `get_module_route()`, `get_department_route()`
- UI: Bootstrap 5, `re_layout_header.php` / `re_layout_footer.php` pattern
- Mobile-first: Tenant Portal uses `tenant_portal.css` with CSS variables, `min-height: 44px` touch targets

---

## 3. Construction Module — Business Model

### 3.1 Two Operating Modes

| Mode | Description | Accounting Treatment |
|------|-------------|----------------------|
| **OWNER** | Company builds its own properties | Costs → Construction in Progress (Asset) |
| **CLIENT** | Builds projects for external clients | Costs → Project Cost / COGS; Revenue from client billing |

### 3.2 Core Principle

**PROJECT-CENTRIC:** All costs, materials, labor, billing, and reporting must link to projects.

---

## 4. Multi-Company Architecture (CRITICAL)

### 4.1 Requirements

- Every financial record includes `company_id`
- Separate general ledger per company (existing accounting engine already does this)
- Separate chart of accounts per company (existing)
- Separate VAT & financial reports (existing)
- Users only see their company data (`WHERE company_id = current_company_id()`)
- No cross-company financial mixing

### 4.2 Compliance

- **DO NOT** create separate accounting systems
- **REUSE** `re_chart_of_accounts`, `re_journal_headers`, `re_journal_lines`, etc.
- Add construction-specific account codes to the shared chart (e.g. Construction in Progress, Project COGS)
- All construction journal entries use `company_id` and the existing posting functions

---

## 5. Phase 1 — MVP (Core Operations)

### 5.1 Phase 1 Goal

Allow real construction projects to run and track costs accurately.

### 5.2 Phase 1 Scope

| # | Feature | Description |
|---|---------|-------------|
| 1 | **Project Setup** | Create/manage projects, type (OWNER/CLIENT), budget, dates, project manager |
| 2 | **Contractor Management** | Contractor profiles, contract value, progress payments, retention %, retention balance |
| 3 | **Project Cost Tracking** | Track per project: materials, labor, subcontractors, equipment, miscellaneous |
| 4 | **Internal Labor Tracking** | Assign labor & engineers to project, record costs, allocate engineer costs |
| 5 | **Inventory Integration (Basic)** | Issue materials to project, record cost to project |
| 6 | **Accounting Integration** | Contractor payments post to accounting; project cost ledger accumulation |
| 7 | **Basic Reporting** | Total project cost, budget vs actual, contractor paid vs remaining |
| 8 | **Client Billing** | (Client projects only) Create project invoices, record payments, revenue posting |

### 5.3 Accounting Behavior (Phase 1)

| Project Type | Cost Posting | Revenue Posting |
|--------------|--------------|-----------------|
| OWNER | Costs → Construction in Progress (Asset) | Rental A Constructions Tools and Heavy Equipments ( Invoice → AR; Payment → Bank, credit AR) (We Have Some Heavy Equipments like Pom Loader , Tower cranes with Different Sizes so we can add this part in the Module to manage the Tools like to add tool with details , adding Lease details and Duration and amount, and also Maintenance of those Machines as Expenses , so you need to Arrange this part )|
| CLIENT | Costs → Project Cost / COGS | Invoice → AR; Payment → Bank, credit AR |

---

## 6. Database Structure Proposal (Phase 1)

### 6.1 Naming Convention

- Prefix: `co_` (construction)
- All tables: `company_id` + `FOREIGN KEY (company_id) REFERENCES companies(id)`
- Unique keys: `(company_id, ...)` where appropriate

### 6.2 Proposed Tables (Phase 1)

#### 6.2.1 Projects

```sql
co_projects
  id, company_id, project_code, project_name
  project_type ENUM('OWNER','CLIENT')
  location, start_date, expected_completion_date
  project_manager_id (FK employees), status ENUM('draft','active','on_hold','completed','cancelled')
  approved_budget DECIMAL(15,2), contractor_contract_value DECIMAL(15,2)
  client_id INT NULL (for CLIENT projects, FK clients/co_clients)
  contract_value DECIMAL(15,2) NULL (for CLIENT projects)
  notes TEXT
  created_at, updated_at, created_by
```

#### 6.2.2 Clients (for CLIENT projects)

```sql
co_clients
  id, company_id, client_name, contact_person, email, phone, address
  tax_number, notes
  created_at, updated_at
```

#### 6.2.3 Contractors

```sql
co_contractors
  id, company_id, contractor_name, contact_person, email, phone, address
  tax_number, bank_details, notes
  is_active TINYINT(1) DEFAULT 1
  created_at, updated_at
```

#### 6.2.4 Project–Contractor Contracts

```sql
co_project_contractors
  id, company_id, project_id, contractor_id
  contract_value DECIMAL(15,2), retention_pct DECIMAL(5,2)
  start_date, end_date, status
  created_at, updated_at
```

#### 6.2.5 Contractor Payments (Progress Payments)

```sql
co_contractor_payments
  id, company_id, project_contractor_id
  payment_date, amount DECIMAL(15,2)
  retention_held DECIMAL(15,2), net_paid DECIMAL(15,2)
  journal_id INT NULL (FK re_journal_headers)
  reference, notes
  created_at, created_by
```

#### 6.2.6 Project Cost Categories

```sql
co_project_cost_categories
  id, company_id, code, name
  category_type ENUM('materials','labor','subcontractor','equipment','miscellaneous')
  is_active TINYINT(1) DEFAULT 1
```

#### 6.2.7 Project Costs (Actual Cost Entries)

```sql
co_project_costs
  id, company_id, project_id
  cost_date, category_id (FK co_project_cost_categories)
  amount DECIMAL(15,2)
  cost_type ENUM('materials','labor','subcontractor','equipment','miscellaneous')
  vendor_id INT NULL, contractor_id INT NULL (for subcontractor)
  employee_id INT NULL (for labor)
  description, reference
  journal_id INT NULL (FK re_journal_headers)
  created_at, created_by
```

#### 6.2.8 Material Issues (Inventory Integration)

```sql
co_material_issues
  id, company_id, project_id
  issue_date, item_code, item_name
  quantity DECIMAL(15,4), unit_cost DECIMAL(15,2), total_cost DECIMAL(15,2)
  cost_posted TINYINT(1) DEFAULT 0, journal_id INT NULL
  notes
  created_at, created_by
```

*Note: If shared inventory tables exist later, this can link to them. For Phase 1, we can start with a simple material log.*

#### 6.2.9 Project Labor Assignments

```sql
co_project_labor
  id, company_id, project_id, employee_id
  role ENUM('labor','engineer','supervisor')
  from_date, to_date
  daily_rate DECIMAL(15,2), hours_worked DECIMAL(8,2)
  cost_amount DECIMAL(15,2)
  posted_to_cost TINYINT(1) DEFAULT 0
  created_at, updated_at, created_by
```

#### 6.2.10 Client Invoices (CLIENT projects only)

```sql
co_client_invoices
  id, company_id, project_id, client_id
  invoice_number, invoice_date
  total_amount DECIMAL(15,2), vat_amount DECIMAL(15,2)
  status ENUM('draft','sent','paid','partial','cancelled')
  journal_id INT NULL
  created_at, created_by
```

#### 6.2.11 Client Invoice Lines

```sql
co_client_invoice_lines
  id, company_id, invoice_id
  line_number, description, amount DECIMAL(15,2)
```

#### 6.2.12 Client Payments

```sql
co_client_payments
  id, company_id, invoice_id
  payment_date, amount DECIMAL(15,2)
  journal_id INT NULL
  reference
  created_at, created_by
```

---

## 7. Integration Points

### 7.1 Accounting Engine

| Action | Integration |
|--------|-------------|
| Contractor payment | `post_journal_entry()` — Debit Contractor/Expense, Credit Bank/Cash |
| Project cost (materials, labor, etc.) | Post to Construction in Progress (OWNER) or Project COGS (CLIENT) |
| Client invoice | Similar to `post_invoice_to_accounting()` — Debit AR, Credit Revenue |
| Client payment | Similar to `post_payment_to_accounting()` — Debit Bank, Credit AR |

**New account codes (to be added to chart of accounts):**
- `1510` — Construction in Progress (Asset)
- `5120` — Project Cost / Construction COGS (Expense)
- `4115` — Construction Revenue (Income)

**Implementation approach:**
- Create `modules/construction/includes/construction_accounting_integration.php`
- Require `modules/realestate/accounting/accounting_engine.php`
- Call `create_journal_entry()`, `post_journal()`, etc. with appropriate parameters

### 7.2 HR System

| Integration | Usage |
|-------------|--------|
| `employees` table | Project manager, labor, engineers — `project_manager_id`, `employee_id` in `co_project_labor` |
| Labor cost | Allocate based on `co_project_labor` (daily rate × days/hours) |

*Note: HR payroll is separate; we only allocate labor cost to projects for cost tracking.*

### 7.3 Inventory (Basic)

| Integration | Phase 1 Approach |
|-------------|------------------|
| Materials to project | `co_material_issues` — manual entry of item, qty, unit cost; optionally link to inventory if a shared table exists |
| Cost allocation | Post cost to project via `co_project_costs` or direct journal |

*If no shared inventory module exists, we log material issues as free-form entries. When inventory is built, we can add FK to inventory tables.*

---

## 8. Contractor Payment & Retention Logic

### 8.1 Retention

- Each `co_project_contractors` has `retention_pct` (e.g. 5%, 10%)
- On each progress payment:
  - `retention_held = amount × retention_pct`
  - `net_paid = amount - retention_held`
- Retention balance = sum of `retention_held` - sum of retention releases (Phase 2)

### 8.2 Payment Flow

1. User creates `co_contractor_payments` for a project–contractor
2. On save:
   - Create journal: Debit Contractor Payable / Expense, Credit Bank
   - Update `journal_id` on payment record
3. Report: Total paid vs contract value vs remaining

---

## 9. Project Cost Tracking Workflow

### 9.1 Cost Entry Types

| Type | Source | Posting |
|------|--------|---------|
| Materials | `co_material_issues` or manual `co_project_costs` | Debit CIP/COGS, Credit Inventory (or Cash if purchased) |
| Labor | `co_project_labor` → cost calculation | Debit CIP/COGS, Credit Labor expense / WIP |
| Subcontractor | `co_contractor_payments` (project contractor) | Debit CIP/COGS, Credit Contractor Payable |
| Equipment | `co_project_costs` | Debit CIP/COGS, Credit Cash/Equipment |
| Miscellaneous | `co_project_costs` | Debit CIP/COGS, Credit Cash |

### 9.2 Workflow

1. User adds cost (material issue, labor assignment, or direct cost)
2. System calculates total project cost
3. On "Post to Accounting" (or auto-post):
   - Create journal with project reference
   - OWNER: Debit 1510 Construction in Progress
   - CLIENT: Debit 5120 Project COGS
   - Credit appropriate account (Bank, Inventory, Payable, etc.)

---

## 10. Reporting Structure (Phase 1)

| Report | Description |
|--------|-------------|
| **Project Cost Summary** | Total cost by project, by category |
| **Budget vs Actual** | Approved budget vs actual cost |
| **Contractor Paid vs Remaining** | Per contractor: paid, retention, remaining |
| **Project List** | All projects with status, dates, manager |

---

## 11. Permissions & Company Isolation

### 11.1 New Module & Departments

**Add to `includes/module_access.php`:**
```php
define('MODULE_CONSTRUCTION', 'construction');
```

**Add to `includes/rbac_department.php`:**
```php
define('DEPT_CONSTRUCTION_CORE', 'construction_core');
define('DEPT_CONSTRUCTION_PROJECTS', 'construction_projects');
define('DEPT_CONSTRUCTION_FINANCIAL', 'construction_financial');
define('DEPT_CONSTRUCTION_REPORTS', 'construction_reports');
```

### 11.2 Company Filtering

**Add to `get_user_company_modules()` in `module_access.php`:**
```php
elseif ($moduleName === MODULE_CONSTRUCTION && $company['business_type'] === 'construction') {
    $companyModules[] = $module;
}
```

### 11.3 Company Setup

- Add `construction` to `companies.business_type` (ENUM or varchar)
- Migration to support new value

### 11.4 Data Isolation

- Every query: `WHERE company_id = ?` with `current_company_id($conn)`
- Use `require_module_access($conn, MODULE_CONSTRUCTION)` and `require_department_access(MODULE_CONSTRUCTION, DEPT_*)` on all pages

---

## 12. UI/UX — Mobile-Friendly

### 12.1 Requirements

- All UI must be **mobile-friendly** (as with Tenant Portal)
- Use Bootstrap 5.3
- Responsive layout: cards, tables that stack on small screens
- Touch targets: `min-height: 44px` for buttons/links
- Use `modules/realestate/includes/re_layout_header.php` and `re_layout_footer.php` as base (or create `modules/construction/includes/construction_layout_header.php` following same pattern)

### 12.2 CSS

- Reuse or extend Bootstrap 5
- Add `modules/construction/assets/construction.css` for module-specific styles
- Mobile-first: base styles for mobile, `@media (min-width: 768px)` for desktop

### 12.3 Key Screens (Phase 1)

- Dashboard (project list, summary cards)
- Projects: list, add, view, edit
- Contractors: list, add, view
- Project Contractors: link contractor to project, set contract value, retention
- Contractor Payments: add payment, view history
- Project Costs: add cost (material, labor, equipment, misc.)
- Labor Assignments: assign employees to project
- Client Invoices (CLIENT projects): create, view, record payment
- Reports: project cost, budget vs actual, contractor summary

---

## 13. File Structure & Module Layout

```
modules/construction/
├── index.php                    # Dashboard
├── projects.php                 # Project list
├── project_add.php
├── project_view.php
├── project_edit.php
├── contractors.php
├── contractor_add.php
├── contractor_view.php
├── project_contractors.php      # Link contractor to project
├── contractor_payment_add.php
├── project_costs.php
├── project_cost_add.php
├── project_labor.php
├── material_issues.php
├── material_issue_add.php
├── client_invoices.php          # CLIENT projects
├── client_invoice_create.php
├── client_payment_add.php
├── reports/
│   ├── project_cost_summary.php
│   ├── budget_vs_actual.php
│   └── contractor_summary.php
├── includes/
│   ├── construction_layout_header.php
│   ├── construction_layout_footer.php
│   ├── construction_accounting_integration.php
│   └── construction_helpers.php
├── assets/
│   └── construction.css
└── ajax_*.php                   # As needed
```

---

## 14. Implementation Sequence (Phase 1)

| Step | Task | Dependencies |
|------|------|--------------|
| 1 | Create `CONSTRUCTION_MODULE_PLAN.md` (this file) | — |
| 2 | **Review & approval** of plan | — |
| 3 | Migration: Add `construction` to `companies.business_type` | — |
| 4 | Migration: Create all `co_*` tables | — |
| 5 | Add `MODULE_CONSTRUCTION`, departments, routes | module_access, rbac_department |
| 6 | Create module folder structure, layout header/footer | — |
| 7 | Projects CRUD | DB |
| 8 | Contractors CRUD | DB |
| 9 | Project–Contractor linking | Projects, Contractors |
| 10 | Contractor payments + accounting integration | Accounting engine |
| 11 | Project costs (all types) | Projects |
| 12 | Project labor assignments | Projects, HR employees |
| 13 | Material issues | Projects |
| 14 | Client invoices & payments (CLIENT projects) | Projects, Clients |
| 15 | Basic reports | All above |
| 16 | Permissions, company isolation checks | All pages |
| 17 | Mobile-friendly UI polish | All screens |
| 18 | Testing & stabilization | — |

---

## 15. Dependencies & Risks

| Risk | Mitigation |
|------|------------|
| Accounting engine is under `realestate/` | Use require_once; treat as shared. No refactor needed for Phase 1. |
| No shared inventory yet | Use simple `co_material_issues` table; extend later when inventory module exists. |
| HR integration | Use `employees` table; no changes to HR module. |
| Chart of accounts | Add construction account codes via migration or manual setup. |

---

## 16. Phase 2 & Phase 3 (Summary)

**Phase 2** (after Phase 1 approval):
- Project phasing & progress tracking
- Variation orders
- Subcontractor work orders
- Advanced budget control
- Retention release tracking
- Document management
- Profitability analysis

**Phase 3** (after Phase 2 approval):
- Equipment & machinery tracking
- Mobile site reporting
- Issue & RFI tracking
- Cost forecasting & cash flow
- Multi-site warehouse

---

## 17. Deliverables Checklist (Phase 1)

- [x] Database structure proposal (Section 6)
- [x] Integration points with accounting, HR, inventory (Section 7)
- [x] Contractor payment & retention logic (Section 8)
- [x] Project cost tracking workflow (Section 9)
- [x] Reporting structure (Section 10)
- [x] Permissions & company isolation logic (Section 11)
- [x] Implementation sequence (Section 14)

---

## 18. Next Steps

1. **Review this plan** — revise or edit as needed
2. **Confirm approval** — notify developer to proceed
3. **After Phase 1 completion** — proceed to Phase 2**
4. **After Phase 2 completion** — proceed to Phase 3**

---

*End of Plan*
