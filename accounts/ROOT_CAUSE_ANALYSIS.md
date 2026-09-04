# Root Cause Analysis: Recurring GL Discrepancies

## Executive Summary

The recurring issues with Trial Balance, General Ledger, Trade Receivable Validation, and Reversal Diagnostic tools are all caused by **duplicate journal entries and reversals** that occur when invoices are edited. The system was creating multiple reversals and journals for the same invoice, leading to data inconsistencies.

## Why These Issues Keep Happening

### 1. **Multiple Journals Per Invoice**
When an invoice is edited:
- The system finds ALL existing non-reversed journals for that invoice
- It reverses ALL of them (creating multiple reversals)
- It creates a new journal with updated amounts

**Problem**: If an invoice is edited multiple times, you get:
- Journal #1 (original) → Reversal #1 → Journal #2 → Reversal #2 → Journal #3
- This creates duplicate reversals and multiple journals

### 2. **Race Conditions**
If an invoice is edited quickly or concurrently:
- Multiple processes might try to reverse the same journal
- The check for existing reversals might not catch duplicates in time
- This results in multiple reversals for the same journal

### 3. **No Validation Before Editing**
The system didn't warn users when editing invoices that have payments:
- Editing an invoice with payments can cause GL discrepancies
- The reversal/reposting process can create orphaned entries
- No warning was shown to prevent accidental edits

### 4. **Duplicate Journal Creation**
Even though `gl_post_invoice` had a check for existing journals:
- The check wasn't using row-level locking (`FOR UPDATE`)
- Race conditions could still create duplicate journals
- Multiple journals could exist for the same invoice

## What Was Fixed

### Fix #1: Only Reverse the Most Recent Journal
**File**: `includes/ar_helpers.php` - `ar_post_or_repost_invoice()`

**Before**: Reversed ALL non-reversed journals for an invoice
**After**: Only reverses the MOST RECENT non-reversed journal

```php
// OLD: Found all journals and reversed all
$journalIds = gl_find_invoice_journals($conn, $invoice_id);
foreach ($journalIds as $jid) { ... }

// NEW: Only finds and reverses the most recent one
$journalSt = $conn->prepare("
  SELECT id FROM gl_journals 
  WHERE source='invoice' AND source_id=? AND is_posted=1 AND is_reversed=0 
  ORDER BY id DESC LIMIT 1
");
```

**Impact**: Prevents multiple reversals when an invoice is edited multiple times.

### Fix #2: Row-Level Locking in gl_post_invoice
**File**: `includes/gl_posting.php` - `gl_post_invoice()`

**Before**: Checked for existing journals without locking
**After**: Uses `FOR UPDATE` to lock the row and prevent race conditions

```php
// OLD: No locking
$existingCheck = $db->prepare("SELECT id FROM gl_journals ...");

// NEW: Row-level locking
$existingCheck = $db->prepare("SELECT id FROM gl_journals ... FOR UPDATE");
```

**Impact**: Prevents duplicate journals from being created during concurrent edits.

### Fix #3: Warning for Invoices with Payments
**File**: `accounts/invoice_edit.php`

**Before**: No warning when editing invoices with payments
**After**: Shows a warning message if the invoice has payments

**Impact**: Users are informed that editing may cause discrepancies, allowing them to make informed decisions.

## How to Prevent Future Issues

### 1. **Avoid Editing Invoices with Payments**
- If an invoice has payments, consider creating a credit note or adjustment instead
- Only edit invoices in "draft" or "issued" status before payments are received
- If you must edit, verify the Trial Balance and AR Ageing reports afterward

### 2. **Use the Diagnostic Tools Regularly**
- Run "Validate Trade Receivable" weekly to catch issues early
- Use "Reversal Diagnostic & Repair Tool" to fix any duplicate reversals
- Check "Trial Balance" vs "AR / AP Ageing Report" for discrepancies

### 3. **Best Practices for Invoice Management**
- **Create invoices carefully**: Review all details before posting to GL
- **Edit only when necessary**: Prefer creating new invoices or credit notes over editing
- **Verify after editing**: Always check reports after editing invoices
- **Use draft status**: Keep invoices in "draft" status until finalized

### 4. **Monitor for Issues**
- Set up regular checks of the validation tools
- Review the General Ledger for unexpected reversals
- Check for invoices with multiple journals (should only have one active journal)

## What to Do If Issues Still Occur

### Step 1: Run Diagnostic Tools
1. Go to **Reports** → **Validate Trade Receivable**
2. Review invalid entries
3. Click **"Fix Invalid Entries"** if needed

### Step 2: Check for Duplicate Reversals
1. Go to **Reports** → **Reversal Diagnostic & Repair Tool**
2. Review invoices with duplicate reversals
3. Click **"Void All Extra Reversals"** to fix them

### Step 3: Verify Reports Match
1. Check **Trial Balance** → Trade Receivables (1110)
2. Check **AR / AP Ageing Report** → Total AR Outstanding
3. They should match (allowing for unallocated receipts)

### Step 4: If Discrepancies Persist
1. Check for unallocated receipts (shown in AR Ageing Report)
2. Verify invoice statuses are correct
3. Review General Ledger for orphaned entries
4. Contact support if issues persist

## Technical Details

### Database Tables Involved
- `gl_journals`: Stores journal entries
- `gl_journal_lines`: Stores journal line items
- `gl_account_balances`: Stores period balances
- `invoices`: Invoice header data
- `receipt_allocations`: Links payments to invoices

### Key Functions
- `ar_post_or_repost_invoice()`: Posts or reposts invoice to GL
- `gl_post_invoice()`: Creates journal entry for invoice
- `gl_reverse_journal()`: Creates reversal journal
- `gl_find_invoice_journals()`: Finds journals for an invoice

### Expected Behavior
- **One invoice** = **One active journal** (when posted)
- **One journal** = **One reversal** (when reversed)
- **Multiple reversals** = **INVALID** (indicates a bug)

## Summary

The root cause was the system reversing ALL journals when editing an invoice, instead of just the most recent one. This has been fixed, along with improved locking to prevent race conditions. The system now:

1. ✅ Only reverses the most recent journal when editing
2. ✅ Uses row-level locking to prevent duplicates
3. ✅ Warns users when editing invoices with payments
4. ✅ Provides diagnostic tools to fix any existing issues

**Next Steps**: Run the diagnostic tools to clean up any existing duplicate reversals, then follow the best practices above to prevent future issues.

