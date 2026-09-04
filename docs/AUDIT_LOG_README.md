# Audit Log System - Quick Start

## Installation

### 1. Run Database Migration

Execute the SQL migration to create the `audit_log` table:

```bash
mysql -u root -p bestsys < migrations/create_audit_log.sql
```

Or run it directly in phpMyAdmin/MySQL Workbench.

### 2. Verify Installation

Check that the table was created:

```sql
SHOW TABLES LIKE 'audit_log';
DESCRIBE audit_log;
```

### 3. Test the System

1. **Log in** to the application
2. Go to **Settings** (gear icon)
3. Click the **History** tab (visible only to Owner role)
4. Set date range and click **Filter**
5. You should see your login event!

## Quick Usage Guide

### For Developers: Adding Audit Logging

#### 1. Create Operation
```php
require_once __DIR__ . '/includes/AuditService.php';

$conn->prepare("INSERT INTO orders ...")->execute([...]);
$orderId = $conn->lastInsertId();

// Log it
AuditService::logCreate('orders', $orderId, [
    'client_name' => $clientName,
    'total' => $total
], "Created order #{$orderId}");
```

#### 2. Update Operation
```php
// Get old data first
$oldData = $conn->query("SELECT * FROM client WHERE id=$id")->fetch();

// Update
$conn->prepare("UPDATE client SET ...")->execute([...]);

// Log it
AuditService::logUpdate('client', $id, $oldData, $newData, 
    "Updated client information");
```

#### 3. Delete Operation
```php
// Get data before delete
$data = $conn->query("SELECT * FROM expense WHERE id=$id")->fetch();

// Delete
$conn->prepare("DELETE FROM expense WHERE id=?")->execute([$id]);

// Log it
AuditService::logDelete('expense', $id, $data, "Deleted expense #{$id}");
```

#### 4. File Upload
```php
// After file saved
AuditService::logUpload($filename, $filepath, 'expenses', $expenseId);
```

#### 5. Status Change
```php
AuditService::logStatusChange('make_order', $id, 'confirmed', 'cancelled', 
    "Order cancelled by user");
```

## Features

### 1. Comprehensive Tracking
- ✅ Login/logout events (auto-logged)
- ✅ Create/update/delete operations
- ✅ File uploads
- ✅ Status changes
- ✅ Failed operations
- ✅ Settings modifications

### 2. Rich Data Capture
- User information (ID, name, roles)
- Action type and target object
- Before/after data snapshots (JSON)
- IP address and user agent
- Success/failure status
- Timestamps

### 3. Powerful UI
- **Filters**: Date range, user, action, object type, status, search
- **Pagination**: 50 records per page
- **Details Modal**: View full before/after JSON data
- **CSV Export**: Download filtered results
- **Role-Based Access**: Owner role only

### 4. Security
- CSRF protection on all forms
- Role-based access control
- SQL injection prevention (prepared statements)
- XSS prevention (output escaping)
- Sensitive data exclusion

## File Structure

```
herosys/
├── migrations/
│   └── create_audit_log.sql          # Database schema
├── includes/
│   └── AuditService.php               # Core logging service
├── settings.php                        # History tab UI (modified)
├── settings_ajax_audit_log.php        # AJAX data handler
├── settings_export_audit_log.php      # CSV export
├── login.php                           # Login logging (modified)
├── logout.php                          # Logout logging (modified)
├── docs/
│   ├── AUDIT_LOG_README.md            # This file
│   └── AUDIT_INTEGRATION_GUIDE.md     # Detailed integration guide
└── [various operation files]           # Integrated with audit logging
```

## Already Integrated Files

The following files already have audit logging:

### Authentication
- `login.php` - Login success/failure
- `logout.php` - User logout

### Settings
- `settings.php` - Company, system, email settings, user roles

### Accounting
- `accounts/expense_add.php` - Expense creation
- `accounts/expense_delete.php` - Expense deletion
- `accounts/ajax/ajax_expense_attachment_upload.php` - File uploads

### Operations
- `operation/order_add.php` - Order creation
- `operation/order_cancel.php` - Order cancellation

## Access the History

1. **Login** as a user with **Owner** role
2. Go to **Settings** (usually a gear icon in navigation)
3. Click the **History** tab
4. Use filters to find specific events
5. Click the eye icon to view full details
6. Export to CSV for reporting

## Filtering Examples

### View all failed operations
- Status: **Failures Only**
- Click **Filter**

### View specific user's actions
- User: Select user from dropdown
- Date range: Set as needed
- Click **Filter**

### Find order-related changes
- Object Type: **make_order**
- Click **Filter**

### Search by keyword
- Search Summary: "expense" or "client name"
- Click **Filter**

## CSV Export

1. Set your desired filters
2. Click **Export to CSV** button
3. File downloads automatically with format: `audit_log_YYYY-MM-DD_HHMMSS.csv`
4. Open in Excel/Google Sheets

**Note**: Export limited to 10,000 records for performance.

## Common Questions

### Q: Why can't I see the History tab?
**A**: Only users with the **Owner** role can view audit history. Ask an Owner to assign you the role in Settings → User Management.

### Q: Why don't I see recent changes?
**A**: Check your date filters. Set "Date To" to today's date.

### Q: Can I delete audit records?
**A**: No. Audit records are permanent and cannot be deleted through the UI. This ensures accountability.

### Q: What's the difference between old_data and new_data?
**A**: 
- **old_data**: State before the change (for updates and deletes)
- **new_data**: State after the change (for inserts and updates)
- **DELETE** operations only have old_data
- **INSERT** operations only have new_data
- **UPDATE** operations have both

### Q: How do I add audit logging to my custom code?
**A**: See the **AUDIT_INTEGRATION_GUIDE.md** for detailed examples and patterns.

## Performance

The audit system is designed for minimal performance impact:

- **Async-ready**: Logging doesn't block main operations
- **Indexed**: Key columns are indexed for fast queries
- **Fail-safe**: Audit failures don't break your application
- **Filtered**: UI uses pagination and limits results

## Best Practices

### DO:
✅ Log after successful operations  
✅ Use descriptive summaries  
✅ Include key identifiers (IDs, names, amounts)  
✅ Log both success and failure when appropriate  

### DON'T:
❌ Log sensitive data (passwords, API keys)  
❌ Log before database commits  
❌ Make audit failures break main operations  
❌ Use vague summaries like "Update" or "Delete"  

## Troubleshooting

### No audit records appearing

1. Check if table exists:
   ```sql
   SELECT COUNT(*) FROM audit_log;
   ```

2. Check PHP error log for AuditService errors

3. Verify you're logged in with a valid session

### "Access Denied" on History tab

- You need the **Owner** role
- Check Settings → User Management → [Your User] → Roles

### Export not downloading

- Check browser popup blocker
- Verify file permissions on server
- Check server error logs

## What's Next?

### Extend Audit Coverage

Many operations can still benefit from audit logging. See the migration plan in **AUDIT_INTEGRATION_GUIDE.md** for:

- Client management
- Invoice operations
- Employee records  
- Leave requests
- And more...

### Customize

You can extend the system:

- Add custom action types
- Add custom filters to the UI
- Create scheduled reports
- Implement data retention policies
- Add email alerts for critical events

## Support & Documentation

- **Detailed Integration**: See `docs/AUDIT_INTEGRATION_GUIDE.md`
- **Source Code**: See `includes/AuditService.php`
- **Database Schema**: See `migrations/create_audit_log.sql`

---

**Version**: 1.0  
**Created**: 2025-10-21  
**License**: Internal Use Only

