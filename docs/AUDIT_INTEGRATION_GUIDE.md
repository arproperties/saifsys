# Audit Log Integration Guide

## Overview

This guide explains how to integrate audit logging throughout the herosys application. The audit logging system tracks all important system events including authentication, database operations, settings changes, and file uploads.

## Architecture

### Core Components

1. **Database Table**: `audit_log` (+ enrichment columns; optional `audit_log_archive`)
2. **Service Class**: `includes/AuditService.php` — prefer `AuditService::logEvent()`
3. **Domain bridges**: `includes/audit_bridge.php` (RE/Construction money summaries)
4. **UI Interface**: Settings → Audit History (Owner)
5. **AJAX / Export**: `settings_ajax_audit_log.php`, `settings_export_audit_log.php`
6. **Retention**: `includes/audit_retention.php`, CLI `tools/audit_log_retention.php`

### Preferred API (`logEvent`)

```php
require_once __DIR__ . '/includes/AuditService.php';
AuditService::logEvent([
  'action' => 'payroll_posted',           // stable code
  'action_label' => 'Posted payroll',     // optional; auto from dictionary
  'module' => 'hr',
  'company_id' => $companyId,
  'object_type' => 'payroll_runs',
  'object_id' => (string)$runId,
  'object_ref' => 'Payroll #' . $runId,
  'summary' => 'Posted payroll run #' . $runId,
  'old_data' => $sparseOld,               // prefer sparse diffs
  'new_data' => $sparseNew,
  'source' => 'user',                     // user|api|system|job
  'success' => true,
]);
```

Legacy helpers (`logCreate` / `logUpdate` / …) still work and call the same writer.

### Database Schema

The `audit_log` table contains:
- User information (id, name, role)
- `company_id`, `module`, `source`, `action_label`, `object_ref` (enrichment)
- Action type (login, logout, insert, update, delete, upload, status_change, …)
- Object details (type, id, human ref)
- Before/after data snapshots (JSON; sparse preferred)
- Request metadata (IP address, user agent)
- Success/failure status
- Timestamps

Apply enrichment via `php tools/apply_audit_log_enrichment.php` (human approval on production).

## Integration Patterns

### 1. Authentication Events (Auto-Logged)

**Files**: `login.php`, `logout.php`

These events are automatically logged:
- Successful login
- Failed login attempts
- Logout

**Example** (already implemented in login.php):
```php
// After successful authentication
require_once __DIR__ . '/includes/AuditService.php';
AuditService::log([
    'action' => 'login',
    'object_type' => 'auth',
    'summary' => "User {$user['username']} logged in successfully",
    'success' => true
]);
```

### 2. Database CREATE Operations

**Pattern**: Log after INSERT, before redirect/response

**Example** (from `accounts/expense_add.php`):
```php
$conn->commit();

// Audit Log: Track expense creation
require_once __DIR__ . '/../includes/AuditService.php';
AuditService::logCreate('expenses', $expenseId, [
    'expense_date' => $expense_date,
    'vendor_id' => $vendor_id,
    'total' => $total
], "Created expense #{$expenseId} for " . number_format($total, 2) . " AED");

header("Location: expenses.php?ok=1");
```

**Files to Integrate**:
- ✅ `accounts/expense_add.php` - Already integrated
- ✅ `operation/order_add.php` - Already integrated
- `hr/employee_edit.php` - Employee creation
- `operation/ajax_add_client.php` - Client creation
- `accounts/invoice_create.php` - Invoice creation
- `hr/cash_advances.php` - Cash advance creation
- `hr/leave_requests.php` - Leave request creation
- `hr/overtime.php` - Overtime entry creation

### 3. Database UPDATE Operations

**Pattern**: Capture old data before update, log after commit

**Example** (from `settings.php`):
```php
// Before update
$oldSettings = $conn->query("SELECT * FROM company_settings WHERE id = 1")->fetch();

// Perform update
$stmt->execute([...]);
$conn->commit();

// After update - log with before/after
require_once __DIR__ . '/includes/AuditService.php';
AuditService::logUpdate('company_settings', '1', $oldSettings, $newSettings, 
    'Updated company information');
```

**Files to Integrate**:
- ✅ `settings.php` - Already integrated (company, system, email settings)
- `operation/order_edit.php` - Order modifications
- `hr/employee_edit.php` - Employee updates
- `operation/ajax_update_client.php` - Client updates
- `hr/attendance_edit.php` - Attendance corrections
- `hr/leave_balances.php` - Leave balance adjustments
- `accounts/expense_edit.php` - Expense modifications

### 4. Database DELETE Operations

**Pattern**: Capture data before deletion, log after

**Example** (from `accounts/expense_delete.php`):
```php
// Fetch data before deletion
$expenseData = $conn->query("SELECT * FROM expenses WHERE id=$id")->fetch();

// Perform deletion/void
$conn->prepare("UPDATE expenses SET status='void' WHERE id=?")->execute([$id]);

// Audit Log
require_once __DIR__ . '/../includes/AuditService.php';
AuditService::logDelete('expenses', $id, $expenseData, "Voided expense #{$id}");
```

**Files to Integrate**:
- ✅ `accounts/expense_delete.php` - Already integrated
- `operation/order_delete.php` - Order deletion
- `operation/ajax_delete_client.php` - Client deletion
- `hr/employee_delete.php` - Employee termination/deletion
- `accounts/invoice_delete.php` - Invoice void/deletion

### 5. Status Change Operations

**Pattern**: Log old and new status

**Example** (from `operation/order_cancel.php`):
```php
// After status change
require_once __DIR__ . '/../includes/AuditService.php';
AuditService::logStatusChange('make_order', $id, $oldStatus, 'cancelled', 
    "Cancelled order #{$id}" . ($reason ? " - Reason: {$reason}" : ""));
```

**Files to Integrate**:
- ✅ `operation/order_cancel.php` - Already integrated
- `hr/leave_requests.php` - Leave request approval/rejection
- `operation/order_complete.php` - Order completion
- `accounts/invoice_status.php` - Invoice status changes
- `hr/employees.php` - Employee status changes

### 6. File Upload Operations

**Pattern**: Log after successful file storage

**Example** (from `accounts/ajax/ajax_expense_attachment_upload.php`):
```php
// After file saved and database record inserted
require_once __DIR__ . '/../../includes/AuditService.php';
AuditService::logUpload($filename, $filepath, 'expenses', $expense_id, 
    "Uploaded attachment '{$filename}' to expense #{$expense_id}");
```

**Files to Integrate**:
- ✅ `accounts/ajax/ajax_expense_attachment_upload.php` - Already integrated
- `hr/employee_documents.php` - Employee document uploads
- `operation/ajax_client_documents.php` - Client document uploads
- `hr/employee_training.php` - Training certificate uploads
- `hr/leave_requests.php` - Leave attachment uploads

## AuditService API Reference

### Main Method

```php
AuditService::log(array $params): bool
```

**Parameters**:
- `action` (string, required): Action type
- `object_type` (string, required): Table name or category
- `object_id` (mixed, optional): Record ID
- `summary` (string, required): Human-readable description
- `old_data` (mixed, optional): Data before change
- `new_data` (mixed, optional): Data after change
- `success` (bool, optional): Success status (default: true)
- `error_message` (string, optional): Error details

### Helper Methods

```php
// CREATE operations
AuditService::logCreate(string $table, $id, $data, string $summary = ''): bool

// UPDATE operations
AuditService::logUpdate(string $table, $id, $oldData, $newData, string $summary = ''): bool

// DELETE operations
AuditService::logDelete(string $table, $id, $oldData, string $summary = ''): bool

// File uploads
AuditService::logUpload(string $filename, string $path, string $relatedTable = '', 
                        $relatedId = null, string $summary = ''): bool

// Status changes
AuditService::logStatusChange(string $table, $id, string $oldStatus, string $newStatus, 
                              string $summary = ''): bool

// Failed operations
AuditService::logFailure(string $action, string $object_type, $object_id, 
                         string $summary, string $error_message): bool
```

## Action Types Reference

| Action | Usage | Example |
|--------|-------|---------|
| `login` | User authentication | User login success/failure |
| `logout` | User logout | User ended session |
| `insert` | Record creation | Created new expense, order, client |
| `update` | Record modification | Updated client info, settings |
| `delete` | Record deletion | Deleted/voided record |
| `upload` | File upload | Uploaded document, attachment |
| `status_change` | Status modification | Order cancelled, leave approved |

## Object Types Reference

Common object types (use actual table names):
- `auth` - Authentication events
- `file` - File operations
- `settings` - System settings
- `company_settings` - Company information
- `expenses` - Expense records
- `make_order` - Work orders
- `client` - Client records
- `employees` - Employee records
- `user_roles` - User role assignments
- `invoices` - Invoices
- `receipts` - Payment receipts
- `leave_requests` - Leave requests

## Tables with Existing Audit Columns

Some tables already have `created_by`, `updated_by`, `created_at`, `updated_at` columns:

### Full Audit Support (all 4 columns):
- `attendance`
- `overtime_entries`

### Partial Audit Support:
- `chart_of_accounts` - has created_at, updated_at
- `expenses` - has created_by, created_at, updated_at
- `gl_journals` - has created_by, created_at
- `holidays` - has created_by, created_at, updated_at
- `invoices` - has created_by, created_at, updated_at
- `make_order` - has created_by, created_at, updated_at
- `receipts` - has created_by, created_at, updated_at
- `vendors` - has created_at, updated_at

For these tables, the existing columns complement but don't replace the audit_log system.

## Best Practices

### 1. Logging Placement
- Log **after** successful database operations (after `commit()`)
- Log **before** redirects or JSON responses
- Log **inside** try-catch blocks, after the operation succeeds
- Never let audit logging failures break the main operation

### 2. Summary Messages
- Be descriptive but concise
- Include key identifiers (IDs, names)
- Include important values (amounts, dates)
- **Never** include sensitive data (passwords, tokens, full credit card numbers)

**Good**:
```php
"Created expense #123 for 1,500.00 AED"
"Updated client 'ABC Corp' contact information"
"Cancelled order #456 - Reason: Client request"
```

**Bad**:
```php
"Expense created"
"Update"
"Cancelled"
```

### 3. Data Snapshots
- For **INSERT**: Only log `new_data`
- For **UPDATE**: Log both `old_data` and `new_data`
- For **DELETE**: Only log `old_data`
- Limit data size - don't log entire large text fields
- Sanitize sensitive data before logging

### 4. Error Logging
```php
try {
    // Perform operation
    $conn->commit();
    
    // Log success
    AuditService::logCreate(...);
} catch (Exception $e) {
    $conn->rollBack();
    
    // Log failure
    AuditService::logFailure('insert', 'expenses', null, 
        'Failed to create expense', $e->getMessage());
}
```

## Migration Plan

### Phase 1: Core Operations (Completed)
- ✅ Authentication (login/logout)
- ✅ Settings modifications
- ✅ Expense create/delete
- ✅ Order create/cancel
- ✅ File uploads

### Phase 2: Critical Business Operations
- [ ] Client create/update/delete
- [ ] Invoice create/void
- [ ] Employee create/update
- [ ] Payment receipts
- [ ] Leave requests

### Phase 3: Extended Coverage
- [ ] Attendance records
- [ ] Overtime entries
- [ ] Cash advances
- [ ] Performance reviews
- [ ] Training records

### Phase 4: Complete Coverage
- [ ] All remaining CRUD operations
- [ ] Batch operations
- [ ] Import/export operations
- [ ] System maintenance tasks

## Testing Checklist

After integrating audit logging into a new file:

- [ ] Test successful operation - verify audit record created
- [ ] Test failed operation - verify failure logged (if applicable)
- [ ] Check audit record in Settings → History
- [ ] Verify summary is descriptive
- [ ] Verify before/after data is captured (for updates)
- [ ] Ensure main operation still works correctly
- [ ] Confirm audit logging doesn't break on error
- [ ] Test with different user roles

## Performance Considerations

1. **Index Usage**: The `audit_log` table has indexes on frequently queried columns
2. **Async Logging**: Consider implementing background logging for high-volume operations
3. **Data Retention**: Implement periodic archival/purging of old audit records
4. **Query Limits**: UI limits results to prevent performance issues

## Troubleshooting

### Audit records not appearing

1. Check if `audit_log` table exists:
   ```sql
   SHOW TABLES LIKE 'audit_log';
   ```

2. Check for PHP errors in logs

3. Verify AuditService.php is included:
   ```php
   require_once __DIR__ . '/includes/AuditService.php';
   ```

4. Verify database connection is available

### "Access denied" error in History tab

- Only users with **Owner** role can view audit history
- Check user's role assignment in Settings → User Management

### CSV export not working

- Verify `settings_export_audit_log.php` exists
- Check file permissions
- Check PHP memory limit for large exports

## Security Notes

1. **Role-Based Access**: Only Owner role can view audit logs
2. **CSRF Protection**: All settings forms use CSRF tokens
3. **SQL Injection**: All queries use prepared statements
4. **XSS Prevention**: All output is escaped via `htmlspecialchars()`
5. **Sensitive Data**: Never log passwords, API keys, or full payment details

## Support

For questions or issues with audit logging:
1. Check this guide
2. Review example implementations in integrated files
3. Check AuditService.php documentation
4. Review audit_log table schema

---

**Last Updated**: 2025-10-21  
**Version**: 1.0

