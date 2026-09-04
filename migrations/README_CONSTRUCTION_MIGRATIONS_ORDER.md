# Construction Module — Database Migrations (Run Order)

Run these migrations **in this exact order** on the live server. Each file depends on the previous ones (and on existing app tables: `companies`, `employees`, `user`; for accounting features, `re_chart_of_accounts` and `re_journal_headers` from the Real Estate/accounting setup).

---

## 1. Core module (run first)

| Order | File | What it does |
|-------|------|----------------|
| **1** | `construction_module_phase1.sql` | Adds `construction` business type; creates `co_clients`, `co_projects`, `co_contractors`, `co_project_contractors`, `co_contractor_payments`, `co_project_cost_categories`, `co_project_costs`, `co_material_issues`, `co_project_labor`, `co_client_invoices`, `co_client_invoice_lines`, `co_client_payments`. **Must run first.** |

---

## 2. Contractor & project extensions

| Order | File | What it does |
|-------|------|----------------|
| **2** | `construction_contractor_bank_fields.sql` | Adds `bank_name`, `account_number`, `iban`, `swift_code` to `co_contractors`. |
| **3** | `construction_phase2_project_phases.sql` | Creates `co_project_phases` (phases/milestones per project). |
| **4** | `construction_phase2_variation_orders.sql` | Creates `co_variation_orders`. |
| **5** | `construction_phase2_documents.sql` | Creates `co_documents` (project & contractor file uploads). |
| **6** | `construction_phase2_work_orders.sql` | Creates `co_work_orders`. |
| **7** | `construction_phase2_retention_releases.sql` | Creates `co_retention_releases`. |
| **8** | `construction_subcontractors.sql` | Alters `co_project_contractors`: adds `parent_project_contractor_id` (subcontractor link). |
| **9** | `construction_phase3_submittals_rfis.sql` | Creates `co_submittals` and `co_rfis`. |

---

## 3. Suppliers & expenses (Phases 1–3 + documents)

| Order | File | What it does |
|-------|------|----------------|
| **10** | `construction_suppliers_phase1.sql` | Creates `co_suppliers`. |
| **11** | `construction_supplier_invoices_phase2.sql` | Creates `co_supplier_invoices` (depends on `co_suppliers`, `co_projects`). |
| **12** | `construction_supplier_payments_phase3.sql` | Creates `co_supplier_payments` (depends on `co_suppliers`). |
| **13** | `construction_supplier_documents.sql` | Creates `co_supplier_documents` and `co_supplier_invoice_documents` (depends on `co_suppliers`, `co_supplier_invoices`). |

---

## 4. Chart of accounts (optional — run per company)

| Order | File | What it does |
|-------|------|----------------|
| **14** | `construction_chart_of_accounts.sql` | Inserts construction accounts (1515, 5125, 2145, 2125) into `re_chart_of_accounts`. **Requires:** Base COA already exists for the company (e.g. from `seed_real_estate_chart_of_accounts.sql`). **Before running:** Set `@company_id` inside the file to your construction company ID. Alternatively, use the Construction UI: **Setup Accounts** to create these + 2110, 2130, 2310. |

---

## Quick copy-paste list (file names only)

```
construction_module_phase1.sql
construction_contractor_bank_fields.sql
construction_phase2_project_phases.sql
construction_phase2_variation_orders.sql
construction_phase2_documents.sql
construction_phase2_work_orders.sql
construction_phase2_retention_releases.sql
construction_subcontractors.sql
construction_phase3_submittals_rfis.sql
construction_suppliers_phase1.sql
construction_supplier_invoices_phase2.sql
construction_supplier_payments_phase3.sql
construction_supplier_documents.sql
construction_chart_of_accounts.sql
```

---

## Prerequisites

- **Database:** `companies`, `employees`, `user` tables exist (main app).
- **Accounting (if you use GL posting):** Run the Real Estate/base accounting migrations first so `re_chart_of_accounts`, `re_journal_headers`, `re_general_ledger`, etc. exist. Then run Construction migrations 1–13. Use **14** or the Construction **Setup Accounts** page to add construction COA accounts (1515, 5125, 2145, 2125, 2110, 2130, 2310) for each company.

---

## How to run on the live server

**Option A — One by one (recommended):**

```bash
cd /path/to/herosysgro/migrations
mysql -u YOUR_USER -p YOUR_DATABASE < construction_module_phase1.sql
mysql -u YOUR_USER -p YOUR_DATABASE < construction_contractor_bank_fields.sql
# ... repeat for each file in order
```

**Option B — From MySQL client:**

```sql
SOURCE /path/to/herosysgro/migrations/construction_module_phase1.sql;
SOURCE /path/to/herosysgro/migrations/construction_contractor_bank_fields.sql;
-- ... etc.
```

**Option C — phpMyAdmin:** Import each `.sql` file in the order above.

After migrations, use **Construction → Setup Accounts** in the app to create 2110 (Supplier Payable), 2130 (Input VAT), and 2310 (Output VAT) if not already in your COA.
