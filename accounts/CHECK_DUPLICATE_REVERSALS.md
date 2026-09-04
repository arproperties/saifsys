# How to Check for Duplicate Reversals

## Quick Check Methods

### 1. Use the Diagnostic Tool (Easiest)

**Location:** `accounts/diagnose_reversals.php`

**Steps:**
1. Go to: `http://your-domain/accounts/diagnose_reversals.php`
2. The page will automatically show:
   - **All invoices with duplicate reversals** (if any exist)
   - **Count of extra reversals** that need to be voided
3. Click on any invoice to see detailed information
4. Use "Void All Extra Reversals" button to fix issues

**What to look for:**
- If the count is **0**, no duplicate reversals exist ✅
- If the count is **> 0**, there are still issues that need fixing

---

### 2. SQL Query to Check for Duplicates

Run this query in your database to find duplicate reversals:

```sql
-- Find all journals with multiple reversals
SELECT 
    orig_j.id as original_journal_id,
    orig_j.journal_no as original_journal_no,
    orig_j.source,
    orig_j.source_id,
    COUNT(rev.id) as reversal_count,
    GROUP_CONCAT(rev.journal_no ORDER BY rev.id SEPARATOR ', ') as reversal_journals,
    GROUP_CONCAT(rev.id ORDER BY rev.id) as reversal_ids
FROM gl_journals orig_j
JOIN gl_journals rev ON rev.source = 'reversal' 
    AND rev.source_id = orig_j.id 
    AND rev.is_posted = 1
WHERE orig_j.is_posted = 1
GROUP BY orig_j.id
HAVING reversal_count > 1
ORDER BY reversal_count DESC;
```

**Expected Result:** Should return **0 rows** if the fix is working.

---

### 3. Check Error Logs

**Location:** PHP error log (usually in `/Applications/XAMPP/xamppfiles/logs/php_error_log`)

**What to look for:**
```bash
# Search for reversal-related errors
grep -i "reversal" /Applications/XAMPP/xamppfiles/logs/php_error_log | tail -20

# Search for "Skipping reversal" messages (these are expected - they show the fix is working)
grep "Skipping reversal" /Applications/XAMPP/xamppfiles/logs/php_error_log | tail -20
```

**Expected Messages:**
- `"Skipping reversal for journal #XXX: Journal #XXX is already reversed..."` - This is **GOOD** - it means the fix is preventing duplicates
- `"Error voiding reversal"` - Only appears if there's an issue with the voiding process

---

### 4. Test the Fix Manually

**Test Scenario 1: Edit an Invoice Multiple Times**

1. Create or find an invoice with status "issued" or "partially_paid"
2. Edit the invoice (change amount, add items, etc.)
3. Save the invoice
4. **Wait 2-3 seconds**
5. Edit the same invoice again (make another change)
6. Save again
7. **Check the diagnostic tool** or run the SQL query above
8. **Expected:** Should see only **1 reversal** per original journal (not multiple)

**Test Scenario 2: Rapid Edits (Race Condition Test)**

1. Open an invoice in two browser tabs
2. Make different changes in each tab
3. Save both tabs quickly (within 1-2 seconds)
4. **Check the diagnostic tool**
5. **Expected:** Should see only **1 reversal** per original journal (the fix should prevent duplicates even with rapid edits)

**Test Scenario 3: Add Payment Then Edit**

1. Create an invoice
2. Add a payment to the invoice
3. Immediately edit the invoice
4. **Check the diagnostic tool**
5. **Expected:** Should see only **1 reversal** per original journal

---

### 5. Monitor New Reversals

**SQL Query to Monitor Recent Reversals:**

```sql
-- Show all reversals created in the last 24 hours, grouped by original journal
SELECT 
    orig_j.id as original_journal_id,
    orig_j.journal_no as original_journal_no,
    COUNT(rev.id) as reversal_count,
    GROUP_CONCAT(rev.journal_no ORDER BY rev.created_at SEPARATOR ', ') as reversal_journals,
    MAX(rev.created_at) as last_reversal_time
FROM gl_journals orig_j
JOIN gl_journals rev ON rev.source = 'reversal' 
    AND rev.source_id = orig_j.id 
    AND rev.is_posted = 1
WHERE rev.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
GROUP BY orig_j.id
HAVING reversal_count > 1
ORDER BY last_reversal_time DESC;
```

**Expected Result:** Should return **0 rows** if the fix is working.

---

### 6. Check Specific Invoice

**SQL Query to Check a Specific Invoice:**

```sql
-- Replace 'INV-2025-00394' with your invoice number
SELECT 
    i.invoice_no,
    orig_j.id as journal_id,
    orig_j.journal_no,
    orig_j.is_reversed,
    COUNT(rev.id) as reversal_count,
    GROUP_CONCAT(rev.journal_no ORDER BY rev.id SEPARATOR ', ') as reversals
FROM invoices i
JOIN gl_journals orig_j ON orig_j.source = 'invoice' AND orig_j.source_id = i.id
LEFT JOIN gl_journals rev ON rev.source = 'reversal' 
    AND rev.source_id = orig_j.id 
    AND rev.is_posted = 1
WHERE i.invoice_no = 'INV-2025-00394'
GROUP BY orig_j.id
ORDER BY orig_j.id;
```

**Expected Result:** Each journal should have `reversal_count = 0` or `1` (never more than 1).

---

## What the Fix Does

The fix adds **multiple layers of protection**:

1. **Pre-check before reversing:** Checks if a reversal already exists before creating a new one
2. **Double-check with lock:** Uses `FOR UPDATE` to prevent race conditions
3. **Exception handling:** Catches errors gracefully and logs them instead of creating duplicates
4. **Data consistency:** Marks journals as reversed even if a reversal already exists

---

## If You Still See Duplicates

If you still see duplicate reversals after the fix:

1. **Check the error log** for any exceptions
2. **Note the timestamp** when the duplicate was created
3. **Check what action** was being performed (edit invoice, add payment, etc.)
4. **Use the diagnostic tool** to void the extra reversals
5. **Report the issue** with:
   - Invoice number
   - Timestamp
   - Action that triggered it
   - Error log entries

---

## Regular Monitoring

**Recommended:** Check the diagnostic tool weekly or after major invoice operations:

1. Go to `accounts/diagnose_reversals.php`
2. If count > 0, review and void extra reversals
3. Check error logs for any "Skipping reversal" messages (these are normal and show the fix is working)

---

## Quick Status Check

Run this one-liner to get a quick status:

```sql
SELECT 
    COUNT(DISTINCT orig_j.id) as journals_with_duplicates,
    SUM(reversal_count - 1) as total_extra_reversals
FROM (
    SELECT 
        orig_j.id,
        COUNT(rev.id) as reversal_count
    FROM gl_journals orig_j
    JOIN gl_journals rev ON rev.source = 'reversal' 
        AND rev.source_id = orig_j.id 
        AND rev.is_posted = 1
    WHERE orig_j.is_posted = 1
    GROUP BY orig_j.id
    HAVING reversal_count > 1
) as duplicates;
```

**Expected Result:** Both columns should be **0** if everything is working correctly.

