#!/bin/bash
# Run all Construction module migrations in the correct order.
# Usage: ./run_construction_migrations.sh [DB_NAME] [DB_USER]
# Example: ./run_construction_migrations.sh herosysgro root
# You will be prompted for the MySQL password unless you set MYSQL_PWD.

set -e
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

DB_NAME="${1:-herosysgro}"
DB_USER="${2:-root}"

FILES=(
  "construction_module_phase1.sql"
  "construction_contractor_bank_fields.sql"
  "construction_phase2_project_phases.sql"
  "construction_phase2_variation_orders.sql"
  "construction_phase2_documents.sql"
  "construction_phase2_work_orders.sql"
  "construction_phase2_retention_releases.sql"
  "construction_subcontractors.sql"
  "construction_phase3_submittals_rfis.sql"
  "construction_suppliers_phase1.sql"
  "construction_supplier_invoices_phase2.sql"
  "construction_supplier_payments_phase3.sql"
  "construction_supplier_documents.sql"
  "construction_chart_of_accounts.sql"
)

echo "Database: $DB_NAME | User: $DB_USER"
echo "Running ${#FILES[@]} migration files..."
echo ""

for f in "${FILES[@]}"; do
  if [ -f "$f" ]; then
    echo "Running: $f"
    mysql -u "$DB_USER" -p "$DB_NAME" < "$f" && echo "  OK" || { echo "  FAILED"; exit 1; }
  else
    echo "SKIP (not found): $f"
  fi
done

echo ""
echo "All Construction migrations completed."
