# Data Migration Guide - Existing Data to Accounting System

## Overview

This guide explains how existing data (payments, invoices, PDC cheques) will be handled when implementing the new accounting system.

---

## ✅ IMPORTANT: Your Existing Data is SAFE

**The new accounting system is ADDITIVE - it does NOT:**
- ❌ Delete existing payments
- ❌ Delete existing invoices
- ❌ Delete existing PDC cheques
- ❌ Modify existing records
- ❌ Affect existing functionality

**The new accounting system DOES:**
- ✅ Create accounting entries (journals) for existing data
- ✅ Make existing data appear in financial reports
- ✅ Link existing records to accounting entries
- ✅ Allow you to see historical data in General Ledger

---

## How It Works

### Current State (Before Migration)
```
re_payments table:     [Payment #1, Payment #2, Payment #3, ...]
re_invoices table:     [Invoice #1, Invoice #2, Invoice #3, ...]
re_post_dated_cheques: [PDC #1, PDC #2, ...]

Accounting Tables:     [EMPTY - No accounting entries yet]
```

### After Migration
```
re_payments table:     [Payment #1, Payment #2, Payment #3, ...] ← UNCHANGED
re_invoices table:     [Invoice #1, Invoice #2, Invoice #3, ...] ← UNCHANGED
re_post_dated_cheques: [PDC #1, PDC #2, ...] ← UNCHANGED

Accounting Tables:     
  re_journal_headers:  [Journal for Payment #1, Journal for Payment #2, ...]
  re_general_ledger:   [GL entries for all payments/invoices]
  re_account_ledgers:  [Tenant ledger entries]
```

---

## Migration Process

### Step 1: Deploy New Files
Upload all accounting files to the live server (see DEPLOYMENT_CHECKLIST.md)

### Step 2: Run Database Migrations
Run the SQL migration files to create accounting tables:
1. `create_real_estate_accounting_tables.sql`
2. `seed_real_estate_chart_of_accounts.sql`
3. `add_reconciliation_to_gl.sql`

### Step 3: Migrate Existing Data
Use the migration tool: `modules/realestate/accounting/migrate_existing_data.php`

**What the migration tool does:**
1. Reads all existing payments from `re_payments` table
2. Reads all existing invoices from `re_invoices` table
3. Reads all security deposits from `re_leases` table
4. Creates accounting journal entries for each record
5. Posts journals to General Ledger
6. Updates tenant ledgers

**Migration Options:**
- **Migrate Payments**: Creates Dr. Cash/Bank, Cr. Accounts Receivable
- **Migrate Invoices**: Creates Dr. Accounts Receivable, Cr. Income + VAT
- **Migrate Deposits**: Creates Dr. Cash/Bank, Cr. Security Deposits Payable
- **Skip Already Migrated**: Prevents duplicate entries if you run migration multiple times
- **As Of Date**: Only migrate records up to a specific date

---

## What Gets Migrated

### 1. Payments (`re_payments`)
**For each payment:**
- Creates a journal entry
- Debits: Cash or Bank account (based on payment method)
- Credits: Accounts Receivable (tenant ledger)
- Links journal to payment via `reference_type='payment'` and `reference_id=payment_id`

**Example:**
```
Payment #100: 5,000 AED on 2024-01-15
→ Creates Journal: JRN-2024-0001
  Dr. Bank Account: 5,000
  Cr. Accounts Receivable (Tenant): 5,000
```

### 2. Invoices (`re_invoices`)
**For each invoice:**
- Creates a journal entry
- Debits: Accounts Receivable (tenant ledger)
- Credits: Income accounts (Rent, Service Charges, etc.)
- Credits: Output VAT (if applicable)
- Links journal to invoice via `reference_type='invoice'` and `reference_id=invoice_id`

**Example:**
```
Invoice #50: 10,000 AED rent + 500 AED VAT on 2024-01-01
→ Creates Journal: JRN-2024-0002
  Dr. Accounts Receivable (Tenant): 10,500
  Cr. Rent Income: 10,000
  Cr. Output VAT: 500
```

### 3. Security Deposits (`re_leases.security_deposit`)
**For each lease with security deposit:**
- Creates a journal entry
- Debits: Cash or Bank account
- Credits: Security Deposits Payable
- Links journal to lease via `reference_type='deposit'` and `reference_id=lease_id`

**Example:**
```
Lease #25: 20,000 AED security deposit
→ Creates Journal: JRN-2024-0003
  Dr. Bank Account: 20,000
  Cr. Security Deposits Payable: 20,000
```

### 4. PDC Cheques (`re_post_dated_cheques`)
**Note:** PDC cheques are tracked separately and don't create accounting entries until they are cleared/deposited. They remain in the PDC tracking system.

---

## After Migration - What You'll See

### 1. General Ledger
- All historical payments will appear as journal entries
- All historical invoices will appear as journal entries
- All deposits will appear as journal entries
- You can filter by date, account, or journal type

### 2. Financial Reports
- **Trial Balance**: Will show balances from all historical transactions
- **Profit & Loss**: Will include income from all historical invoices
- **Balance Sheet**: Will show AR balances, deposits, etc.
- **Tenant Statement**: Will show all historical invoices and payments

### 3. Existing Pages
- **Billing Page**: Continues to work normally
- **Payments Page**: Continues to work normally
- **Collections Page**: Continues to work normally
- **No changes** to existing functionality

### 4. New Transactions
- **Future invoices**: Will automatically post to accounting (if integration is enabled)
- **Future payments**: Will automatically post to accounting (if integration is enabled)
- **Manual journals**: Can be created for adjustments

---

## Migration Best Practices

### 1. Backup First
```sql
-- Always backup before migration
mysqldump -u username -p database_name > backup_before_accounting_migration.sql
```

### 2. Test on Staging
- Test migration on a staging/test server first
- Verify all data appears correctly in reports
- Check that balances match expectations

### 3. Run During Off-Hours
- Migration can take time (depends on data volume)
- Run during low-usage periods
- Inform users that system may be slower during migration

### 4. Verify Results
After migration, verify:
- [ ] Trial Balance balances (Debit = Credit)
- [ ] Total payments in GL matches total in payments table
- [ ] Total invoices in GL matches total in invoices table
- [ ] Tenant statements show correct balances
- [ ] No duplicate entries (if "Skip Already Migrated" was used)

### 5. Handle Errors
- Check error logs if migration fails
- Failed entries are logged but don't stop the process
- Re-run migration with "Skip Already Migrated" to retry failed entries

---

## Troubleshooting

### Issue: Duplicate Entries
**Solution:** Use "Skip Already Migrated" option when re-running migration

### Issue: Missing Data in Reports
**Solution:** 
1. Check if migration completed successfully
2. Verify journal entries were created (check `re_journal_headers`)
3. Verify journals were posted (check `is_posted = 1`)

### Issue: Incorrect Balances
**Solution:**
1. Check if all data types were migrated (payments, invoices, deposits)
2. Verify Chart of Accounts is correct
3. Check if VAT was calculated correctly

### Issue: Migration Takes Too Long
**Solution:**
1. Migrate in batches (use "As Of Date" to migrate month by month)
2. Migrate one data type at a time
3. Check database performance/indexes

---

## Rollback Plan

**If something goes wrong:**

1. **Accounting entries can be reversed:**
   - Use journal reversal feature
   - Or delete unposted journals (if any)

2. **Original data is untouched:**
   - Payments, invoices, PDC cheques remain unchanged
   - You can re-run migration after fixing issues

3. **Database rollback:**
   - If needed, restore from backup
   - Re-run migrations
   - Re-run data migration

---

## Summary

✅ **Your existing data is SAFE**  
✅ **Migration is ADDITIVE** (creates new entries, doesn't modify existing)  
✅ **All historical data will appear in accounting reports**  
✅ **Existing pages continue to work normally**  
✅ **Future transactions automatically post to accounting**

**Next Steps:**
1. Deploy accounting files
2. Run database migrations
3. Use migration tool to import existing data
4. Verify results in reports
5. Start using accounting features!

---

**Questions?** Refer to DEPLOYMENT_CHECKLIST.md or contact support.
