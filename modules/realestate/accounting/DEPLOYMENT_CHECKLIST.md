# Real Estate Accounting Module - Deployment Checklist

**Deployment Date**: _______________  
**Deployed By**: _______________

---

## 📦 FILES TO UPLOAD TO LIVE SERVER

### 1. DATABASE MIGRATION FILES (Run in order)

**Location**: `migrations/`

1. ✅ `create_real_estate_accounting_tables.sql`
   - Creates all accounting tables (Chart of Accounts, Journals, Ledgers, etc.)
   - **MUST RUN FIRST**

2. ✅ `seed_real_estate_chart_of_accounts.sql`
   - Seeds default Chart of Accounts for Real Estate
   - **RUN AFTER** create_real_estate_accounting_tables.sql

3. ✅ `add_reconciliation_to_gl.sql`
   - Adds reconciliation columns to General Ledger
   - **RUN AFTER** create_real_estate_accounting_tables.sql

**⚠️ IMPORTANT**: Run these SQL files in the order listed above using phpMyAdmin or MySQL command line.

---

### 2. CORE ACCOUNTING ENGINE FILES

**Location**: `modules/realestate/accounting/`

1. ✅ `accounting_engine.php`
   - Core double-entry accounting functions
   - Journal creation, posting, reversal
   - Balance calculations

2. ✅ `accounting_integration.php`
   - Integration functions for invoices, payments, deposits
   - Auto-posting from Real Estate module

3. ✅ `README.md`
   - Documentation for accounting engine

---

### 3. FINANCIAL REPORTS

**Location**: `modules/realestate/accounting/`

1. ✅ `trial_balance.php`
2. ✅ `general_ledger.php`
3. ✅ `account_ledger.php`
4. ✅ `tenant_statement.php`
5. ✅ `profit_loss.php`
6. ✅ `balance_sheet.php`
7. ✅ `vat_report.php`

---

### 4. JOURNAL ENTRY MANAGEMENT

**Location**: `modules/realestate/accounting/`

1. ✅ `journal_entry_list.php`
2. ✅ `journal_entry_add.php`
3. ✅ `journal_entry_view.php`

---

### 5. BANK RECONCILIATION

**Location**: `modules/realestate/accounting/`

1. ✅ `bank_reconciliation.php`
2. ✅ `bank_reconciliation_match.php`
3. ✅ `ajax_reconcile_transactions.php`
4. ✅ `ajax_unreconcile_transactions.php`
5. ✅ `ajax_clear_match_results.php`

---

### 6. PERIOD MANAGEMENT

**Location**: `modules/realestate/accounting/`

1. ✅ `periods.php`
2. ✅ `period_close.php`

---

### 7. VAT CONFIGURATION

**Location**: `modules/realestate/accounting/`

1. ✅ `vat_config.php`

---

### 8. MODIFIED EXISTING FILES

**Location**: `modules/realestate/`

1. ✅ `includes/re_layout_header.php`
   - Added Accounting menu section
   - Added navigation links

**Location**: `modules/realestate/` (Integration files - check if modified)

2. ⚠️ `billing_invoice_create.php` (if accounting integration added)
3. ⚠️ `payment_add.php` (if accounting integration added)
4. ⚠️ `lease_add.php` (if accounting integration added)

**Note**: Check these files to see if `post_invoice_to_accounting()`, `post_payment_to_accounting()`, or `post_security_deposit_to_accounting()` calls were added.

---

### 9. DOCUMENTATION FILES (Optional - for reference)

**Location**: `modules/realestate/accounting/`

1. `ACCOUNTING_SYSTEM_DESIGN.md`
2. `IMPLEMENTATION_SUMMARY.md`
3. `INTEGRATION_COMPLETE.md`
4. `FEATURE_PARITY_REVIEW.md`

**Note**: These are documentation files. Upload if you want them on the server, but they're not required for functionality.

---

## 📋 DEPLOYMENT STEPS

### Step 1: Backup Database
```sql
-- Backup existing database before running migrations
mysqldump -u username -p database_name > backup_before_accounting.sql
```

### Step 2: Run Database Migrations
Run these SQL files in order using phpMyAdmin:

1. `migrations/create_real_estate_accounting_tables.sql`
2. `migrations/seed_real_estate_chart_of_accounts.sql`
3. `migrations/add_reconciliation_to_gl.sql`

### Step 3: Upload PHP Files
Upload all files listed in sections 2-8 above to the live server.

### Step 4: Verify File Permissions
Ensure PHP files have correct permissions (usually 644 for files, 755 for directories).

### Step 5: Test Access
1. Login to the system
2. Navigate to Real Estate module
3. Check if "Accounting" section appears in the sidebar
4. Try accessing each accounting page

### Step 6: Verify Database
```sql
-- Check if tables were created
SHOW TABLES LIKE 're_%';

-- Check if Chart of Accounts was seeded
SELECT COUNT(*) FROM re_chart_of_accounts WHERE company_id = 1;
-- Should return ~40+ accounts

-- Check if reconciliation columns exist
DESCRIBE re_general_ledger;
-- Should show is_reconciled, reconciled_at, reconciled_by columns
```

### Step 7: Configure VAT
1. Navigate to: Real Estate > Accounting > VAT Configuration
2. Set VAT rate (default: 5%)
3. Set VAT registration number (if applicable)
4. Map Input VAT and Output VAT accounts

### Step 8: Migrate Existing Data
1. Navigate to: Real Estate > Accounting > Migrate Data
2. Review statistics (total payments, invoices, etc.)
3. Select data types to migrate (Payments, Invoices, Deposits)
4. Set "As Of Date" (recommended: today's date)
5. Check "Skip Already Migrated" to avoid duplicates
6. Click "Start Migration"
7. Wait for completion (may take several minutes)
8. Review migration results

**⚠️ IMPORTANT:** 
- Backup database before migration
- Migration is safe - it doesn't modify existing data
- All existing payments/invoices will appear in accounting reports after migration

### Step 9: Test Core Functions
1. Create a test manual journal entry
2. Post the journal
3. View Trial Balance
4. View General Ledger
5. Test Bank Reconciliation (if bank accounts exist)

---

## ⚠️ IMPORTANT NOTES

1. **Company Isolation**: All accounting data is isolated by `company_id`. Ensure the correct company is selected.

2. **No Impact on Cleaning**: All accounting tables are prefixed with `re_` (Real Estate) and are completely separate from cleaning accounting.

3. **Existing Pages**: Existing Real Estate pages (Billing, Payments, Collections) continue to work. Accounting is additive.

4. **Integration**: If integration functions were added to existing pages, those pages will automatically post to accounting when invoices/payments are created.

5. **Permissions**: Ensure users have `DEPT_REALESTATE_FINANCIAL` department access to view accounting pages.

---

## 🔍 POST-DEPLOYMENT VERIFICATION

- [ ] All accounting pages load without errors
- [ ] Trial Balance shows accounts
- [ ] Can create and post manual journal entries
- [ ] VAT configuration can be set
- [ ] Bank reconciliation page loads
- [ ] Financial reports generate correctly
- [ ] No PHP errors in error logs
- [ ] Navigation menu shows Accounting section

---

## 📞 SUPPORT

If issues occur:
1. Check PHP error logs
2. Check MySQL error logs
3. Verify all files were uploaded
4. Verify database migrations ran successfully
5. Check user permissions

---

**Status**: ✅ Ready for Deployment
