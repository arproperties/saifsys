# Unified Permissions System - All Modules
## Comprehensive Permission Control Plan for Multi-Module System

**Date:** January 11, 2026  
**Status:** Planning Document  
**Priority:** High - Core Security Feature

---

## 🎯 Overview

Extend the existing multi-module role-based access control (RBAC) system to provide granular permissions across ALL modules (Cleaning, HR, Finance, Real Estate). This will leverage the existing `role_modules` table with JSON permissions field and provide a unified permission management interface.

---

## ⚠️ Important: Finance Module Separation

**Finance systems are kept separate for Cleaning and Real Estate because they serve fundamentally different business models:**

### **Finance Module (`finance`) - For Cleaning Business**
- **Purpose:** Service-based accounting for cleaning companies
- **Features:** Service invoicing, client payments, business expenses, general ledger
- **Data Tables:** `invoices`, `payments`, `expenses`, `receipts`, `chart_of_accounts`
- **Workflow:** One-time or recurring service invoicing → Client payment → Expense tracking
- **Location:** `/accounts/` directory

### **Real Estate Finance Features - Built into Real Estate Module**
- **Purpose:** Property management finance (rent collection, lease billing)
- **Features:** Rent payments, lease installments, post-dated cheques, service charges, collections
- **Data Tables:** `re_payments`, `re_lease_installments`, `re_billing_items`, `re_billing_cheques`
- **Workflow:** Lease creation → Installment generation → Rent collection → Collections management
- **Location:** `modules/realestate/payments.php`, `modules/realestate/billing_*.php`

**Why Separate?**
1. **Different Business Models:** Service invoicing vs. recurring rent collection
2. **Different Data Structures:** Separate tables (`payments` vs `re_payments`)
3. **Different Workflows:** Service-based vs. lease-based payments
4. **Different Requirements:** Installment tracking, post-dated cheques, deposit management (Real Estate) vs. service invoicing (Cleaning)

**In This Plan:**
- `finance` module permissions = Cleaning business accounting
- `realestate` module permissions = Real Estate operations (including its own finance features)

---

---

## 📊 Current State Analysis

### ✅ **Existing Infrastructure:**

1. **Database Tables:**
   - `roles` - Roles table with `module` column
   - `user_roles` - User-role assignments
   - `role_modules` - Role-module relationships with `permissions` JSON field (exists but not fully utilized)
   - `companies` - Company management
   - `user_companies` - User-company relationships

2. **Existing Features:**
   - Module-based role assignment (`roles.module`)
   - User role assignment (via `user_roles` table)
   - Module access control (`require_module_access()`)
   - Role-based access control (`require_role()`, `has_role()`)
   - Settings page user management (`/settings.php?tab=users`)
   - HR access control page (`/hr/access.php`)

3. **Current Modules:**
   - **Cleaning** (`cleaning`) - Cleaning operations module
   - **HR** (`hr`) - Human Resources module
   - **Finance** (`finance`) - Accounts/Finance module (for Cleaning business - service invoicing, client payments, general accounting)
   - **Real Estate** (`realestate`) - Property management module (includes its own finance features - rent collection, lease payments, billing)
   - **Core** (`core`) - Core system features

**Important Note:**
   - **Finance Module** (`finance`) is specifically for **Cleaning business** accounting (service invoicing, client payments, expenses, general ledger)
   - **Real Estate Module** (`realestate`) has its **own finance features** built-in (rent payments, lease billing, collections, post-dated cheques)
   - These are **separate systems** because they serve different business models and workflows

### ❌ **What's Missing:**

1. **Granular Permissions:**
   - JSON `permissions` field in `role_modules` exists but not used
   - No permission checking functions
   - No permission definitions
   - No UI for permission management

2. **Module-Specific Permissions:**
   - No permission structure defined per module
   - No way to assign granular permissions to roles
   - No permission checks in module pages

3. **Unified Management:**
   - Settings page shows users but no permission management
   - HR access page only assigns roles, not permissions
   - No central permission management interface

---

## 🏗️ Solution: Extend Existing System

### **Approach:**
Instead of creating a new system, we will:
1. **Utilize** the existing `role_modules.permissions` JSON field
2. **Extend** the existing settings page with permission management
3. **Create** permission helper functions that work across all modules
4. **Define** permission structures for each module
5. **Add** permission checks to existing pages

---

## 📋 Database Schema (Already Exists)

### **role_modules Table** (Already Created)

```sql
CREATE TABLE IF NOT EXISTS `role_modules` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `role_id` INT(11) NOT NULL,
  `module` VARCHAR(50) NOT NULL,
  `permissions` JSON DEFAULT NULL COMMENT 'Module-specific permissions',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_role_module` (`role_id`, `module`),
  KEY `idx_role_id` (`role_id`),
  KEY `idx_module` (`module`),
  FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

**JSON Structure Example:**
```json
{
  "buildings": ["view", "create", "edit"],
  "units": ["view", "create", "edit", "delete"],
  "tenants": ["view", "create"],
  "leases": ["view", "create", "edit", "generate_contract"],
  "payments": ["view"],
  "maintenance": ["view", "create", "edit"]
}
```

---

## 🔑 Permission Structure by Module

### **1. Cleaning Module (`cleaning`)**

#### **Permission Categories:**
- `clients` - Client management
- `orders` - Order management
- `workorders` - Work order management
- `services` - Service management
- `employees` - Employee assignment
- `scheduling` - Scheduling management
- `invoicing` - Invoice generation
- `reports` - Reports and analytics

#### **Example Permissions:**
- `clients.view`, `clients.create`, `clients.edit`, `clients.delete`
- `orders.view`, `orders.create`, `orders.edit`, `orders.delete`, `orders.assign`
- `workorders.view`, `workorders.create`, `workorders.edit`, `workorders.complete`
- `services.view`, `services.create`, `services.edit`, `services.delete`
- `scheduling.view`, `scheduling.manage`, `scheduling.approve`
- `invoicing.view`, `invoicing.create`, `invoicing.edit`, `invoicing.delete`
- `reports.view`, `reports.export`, `reports.financial`

---

### **2. HR Module (`hr`)**

#### **Permission Categories:**
- `employees` - Employee management
- `attendance` - Attendance tracking
- `leave` - Leave management
- `payroll` - Payroll management
- `documents` - Document management
- `performance` - Performance reviews
- `recruitment` - Recruitment management
- `reports` - HR reports

#### **Example Permissions:**
- `employees.view`, `employees.create`, `employees.edit`, `employees.delete`, `employees.view_salary`
- `attendance.view`, `attendance.record`, `attendance.approve`, `attendance.edit`
- `leave.view`, `leave.request`, `leave.approve`, `leave.manage`
- `payroll.view`, `payroll.run`, `payroll.edit`, `payroll.approve`, `payroll.view_all`
- `documents.view`, `documents.upload`, `documents.delete`
- `performance.view`, `performance.create`, `performance.edit`
- `recruitment.view`, `recruitment.create`, `recruitment.manage`
- `reports.view`, `reports.export`, `reports.payroll`

---

### **3. Finance Module (`finance`) - For Cleaning Business**

**Note:** This module is specifically for **Cleaning business** accounting. Real Estate has its own finance features (see Real Estate module).

#### **Permission Categories:**
- `invoices` - Service invoice management (for cleaning services)
- `payments` - Client payment processing
- `expenses` - Business expense management
- `accounts` - Chart of accounts
- `journal` - General journal entries
- `reports` - Financial reports (P&L, Balance Sheet, Cash Flow)
- `reconciliation` - Bank reconciliation
- `settings` - Finance settings

#### **Example Permissions:**
- `invoices.view`, `invoices.create`, `invoices.edit`, `invoices.delete`, `invoices.approve`, `invoices.send`
- `payments.view`, `payments.create`, `payments.edit`, `payments.delete`, `payments.reconcile`
- `expenses.view`, `expenses.create`, `expenses.edit`, `expenses.delete`, `expenses.approve`
- `accounts.view`, `accounts.create`, `accounts.edit`, `accounts.delete`
- `journal.view`, `journal.create`, `journal.edit`, `journal.delete`, `journal.post`
- `reports.view`, `reports.export`, `reports.balance_sheet`, `reports.profit_loss`, `reports.cash_flow`
- `reconciliation.view`, `reconciliation.manage`, `reconciliation.approve`
- `settings.view`, `settings.edit`, `settings.manage_accounts`

**Business Context:**
- Service-based invoicing (one-time or recurring cleaning services)
- Client payment tracking
- General business accounting
- Used by Cleaning companies

---

### **4. Real Estate Module (`realestate`)**

**Note:** This module includes its **own finance features** (rent payments, billing, collections) which are separate from the Finance module used by Cleaning business.

#### **Permission Categories:**
- `buildings` - Building management
- `units` - Unit management
- `tenants` - Tenant management
- `leases` - Lease management
- `payments` - Rent payment management (Real Estate specific - uses `re_payments` table)
- `billing` - Rent billing management (service charges, parking fees, etc.)
- `collections` - Collections management (overdue rent, notices)
- `cheques` - Post-dated cheques management
- `maintenance` - Maintenance management
- `vendors` - Vendor management
- `move_in_out` - Move-in/out management
- `tasks` - Task management
- `compliance` - Compliance management
- `documents` - Document management
- `reports` - Reports

#### **Example Permissions:**
- `buildings.view`, `buildings.create`, `buildings.edit`, `buildings.delete`
- `units.view`, `units.create`, `units.edit`, `units.delete`, `units.change_status`
- `tenants.view`, `tenants.create`, `tenants.edit`, `tenants.delete`, `tenants.view_documents`
- `leases.view`, `leases.create`, `leases.edit`, `leases.delete`, `leases.generate_contract`, `leases.send_contract`, `leases.renew`, `leases.terminate`
- `payments.view`, `payments.create`, `payments.edit`, `payments.delete`, `payments.print_receipts` (Real Estate rent payments)
- `billing.view`, `billing.create`, `billing.edit`, `billing.delete`, `billing.generate_invoices` (Rent billing)
- `collections.view`, `collections.manage`, `collections.send_notices` (Overdue rent collection)
- `cheques.view`, `cheques.create`, `cheques.edit`, `cheques.update_status` (Post-dated cheques)
- `maintenance.view`, `maintenance.create`, `maintenance.edit`, `maintenance.assign`, `maintenance.complete`, `maintenance.view_costs`
- `vendors.view`, `vendors.create`, `vendors.edit`, `vendors.delete`, `vendors.manage_agreements`
- `move_in.view`, `move_in.create`, `move_in.complete`, `move_out.view`, `move_out.create`, `move_out.complete`
- `tasks.view`, `tasks.create`, `tasks.edit`, `tasks.assign`, `tasks.complete`
- `compliance.view`, `compliance.manage`
- `documents.view`, `documents.upload`, `documents.delete`
- `reports.view`, `reports.export`, `reports.financial`

**Business Context:**
- Recurring rent collection (lease-based payments)
- Installment tracking (monthly/quarterly rent)
- Post-dated cheques management
- Service charges and additional fees billing
- Deposit management
- Used by Real Estate companies
- **Separate from Finance module** - different data structures (`re_payments` vs `payments` table)

---

### **5. Core Module (`core`)**

#### **Permission Categories:**
- `users` - User management
- `companies` - Company management
- `settings` - System settings
- `audit` - Audit log access
- `roles` - Role management

#### **Example Permissions:**
- `users.view`, `users.create`, `users.edit`, `users.delete`, `users.assign_roles`
- `companies.view`, `companies.create`, `companies.edit`, `companies.delete`, `companies.manage_users`
- `settings.view`, `settings.edit`, `settings.manage_branding`
- `audit.view`, `audit.export`
- `roles.view`, `roles.create`, `roles.edit`, `roles.delete`, `roles.manage_permissions`

---

## 💻 Implementation Approach

### **Phase 1: Core Infrastructure**

1. **Create Permission Helper Functions**
   - `has_permission($permission_key, $module = null)` - Check if user has permission
   - `require_permission($permission_key, $module = null)` - Require permission
   - `get_user_permissions($user_id, $module = null)` - Get all user permissions
   - `get_role_permissions($role_id, $module = null)` - Get role permissions
   - `set_role_permissions($role_id, $module, $permissions)` - Set role permissions

**File:** `includes/permissions.php` (NEW)

2. **Define Permission Structures**
   - Create permission definition arrays for each module
   - Store in configuration files or database

**Files:**
- `includes/permissions/cleaning_permissions.php`
- `includes/permissions/hr_permissions.php`
- `includes/permissions/finance_permissions.php` (for Cleaning business accounting)
- `includes/permissions/realestate_permissions.php` (includes Real Estate finance permissions)
- `includes/permissions/core_permissions.php`

### **Phase 2: Extend Settings Page**

1. **Add Permission Management Tab**
   - Add "Permissions" tab to `/settings.php`
   - Show role-based permission management interface
   - Allow assigning permissions to roles per module

2. **Enhance User Management Tab**
   - Show user's permissions (calculated from roles)
   - Show effective permissions across all modules
   - Display permission summary

**File:** `settings.php` (EXTEND)

### **Phase 3: Extend HR Access Page**

1. **Add Permission View**
   - Show permissions for selected role/module
   - Display effective permissions for user
   - Allow quick permission assignment (if user has permission)

**File:** `hr/access.php` (EXTEND)

### **Phase 4: Add Permission Checks to Pages**

1. **Gradually Add Permission Checks**
   - Start with high-priority pages (financial, sensitive data)
   - Add checks to create/edit/delete operations
   - Hide UI elements based on permissions

2. **Update Navigation**
   - Filter navigation based on permissions
   - Hide menu items user cannot access

**Files:** All module pages (GRADUAL UPDATE)

---

## 📁 File Structure

```
includes/
├── permissions.php                          # Core permission functions
├── permissions/
│   ├── cleaning_permissions.php            # Cleaning module permissions
│   ├── hr_permissions.php                  # HR module permissions
│   ├── finance_permissions.php             # Finance module permissions
│   ├── realestate_permissions.php          # Real Estate module permissions
│   └── core_permissions.php                # Core module permissions
│
settings.php                                 # EXTEND: Add permissions tab
hr/
└── access.php                               # EXTEND: Show permissions

modules/
├── cleaning/                                # Add permission checks
├── hr/                                      # Add permission checks
├── accounts/                                # Add permission checks
└── realestate/                              # Add permission checks
```

---

## 🔧 Core Permission Functions

### **has_permission($permission_key, $module = null)**

```php
/**
 * Check if current user has a specific permission
 * @param string $permission_key Permission key (e.g., "buildings.view")
 * @param string|null $module Module name (optional, auto-detect if null)
 * @return bool
 */
function has_permission(string $permission_key, ?string $module = null): bool {
    global $conn;
    
    $userId = current_user_id();
    if (!$userId) return false;
    
    // Owner and Admin have all permissions
    $roles = current_user_roles($conn);
    if (in_array('Owner', $roles, true) || in_array('Admin', $roles, true)) {
        return true;
    }
    
    // Auto-detect module from permission key if not provided
    if (!$module) {
        $parts = explode('.', $permission_key);
        $module = detect_module_from_permission($parts[0]);
    }
    
    // Get user's roles
    $stmt = $conn->prepare("
        SELECT rm.permissions
        FROM user_roles ur
        JOIN role_modules rm ON rm.role_id = ur.role_id
        WHERE ur.user_id = ? AND rm.module = ?
    ");
    $stmt->execute([$userId, $module]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Check each role's permissions
    foreach ($rows as $row) {
        $permissions = json_decode($row['permissions'] ?? '{}', true);
        if (check_permission_in_json($permissions, $permission_key)) {
            return true;
        }
    }
    
    return false;
}
```

### **require_permission($permission_key, $module = null)**

```php
/**
 * Require permission (throws 403 if not granted)
 * @param string $permission_key Permission key
 * @param string|null $module Module name (optional)
 * @return void
 */
function require_permission(string $permission_key, ?string $module = null): void {
    if (!has_permission($permission_key, $module)) {
        http_response_code(403);
        die('Access denied: Required permission "' . htmlspecialchars($permission_key) . '"');
    }
}
```

### **get_user_permissions($user_id, $module = null)**

```php
/**
 * Get all permissions for a user (across all roles and modules)
 * @param int $user_id User ID
 * @param string|null $module Module name (optional, returns all if null)
 * @return array Array of permissions
 */
function get_user_permissions(int $user_id, ?string $module = null): array {
    global $conn;
    
    // Get user's roles
    $sql = "
        SELECT rm.module, rm.permissions
        FROM user_roles ur
        JOIN role_modules rm ON rm.role_id = ur.role_id
        WHERE ur.user_id = ?
    ";
    $params = [$user_id];
    
    if ($module) {
        $sql .= " AND rm.module = ?";
        $params[] = $module;
    }
    
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $allPermissions = [];
    foreach ($rows as $row) {
        $permissions = json_decode($row['permissions'] ?? '{}', true);
        $mod = $row['module'];
        if (!isset($allPermissions[$mod])) {
            $allPermissions[$mod] = [];
        }
        $allPermissions[$mod] = array_merge($allPermissions[$mod], extract_permissions_from_json($permissions));
    }
    
    return $module ? ($allPermissions[$module] ?? []) : $allPermissions;
}
```

---

## 🎨 Permission Management UI

### **Settings Page - Permissions Tab**

1. **Role Selection**
   - Dropdown to select role
   - Show module tabs for each module

2. **Permission Assignment**
   - Checkbox grid per module
   - Grouped by permission category
   - Check/uncheck permissions

3. **Permission Structure Display**
   - Tree view or grouped list
   - Categories → Permissions
   - Visual indication of granted permissions

4. **User Permission View**
   - Show effective permissions for selected user
   - Calculated from all assigned roles
   - Per-module breakdown

### **HR Access Page - Enhanced**

1. **Permission Display**
   - Show permissions for selected user
   - Grouped by module
   - Show source role(s) for each permission

2. **Quick Permission Assignment** (Optional)
   - Allow assigning permissions directly (if user has permission)
   - Or redirect to settings page

---

## 🔐 Security Considerations

1. **Server-Side Validation**
   - Always check permissions on server-side
   - Never rely solely on client-side checks
   - Validate before database operations

2. **Default Deny**
   - Default to denying access if permission unclear
   - Explicitly grant permissions

3. **Least Privilege**
   - Assign minimum permissions needed
   - Regular permission audits

4. **Company Isolation**
   - Permissions respect company boundaries
   - Users only access their company's data

5. **Owner/Admin Override**
   - Owner and Admin roles have all permissions
   - Cannot be restricted (system-level)

---

## 📊 Permission Matrix (Quick Reference)

| Module | Category | View | Create | Edit | Delete | Special |
|--------|----------|------|--------|------|--------|---------|
| **Real Estate** | Buildings | ✅ | ✅ | ✅ | ✅ | manage_floors |
| **Real Estate** | Units | ✅ | ✅ | ✅ | ✅ | change_status |
| **Real Estate** | Leases | ✅ | ✅ | ✅ | ✅ | generate_contract, send_contract, renew |
| **Real Estate** | Payments | ✅ | ✅ | ✅ | ✅ | print_receipts |
| **Finance (Cleaning)** | Invoices | ✅ | ✅ | ✅ | ✅ | approve, send |
| **Finance (Cleaning)** | Payments | ✅ | ✅ | ✅ | ✅ | reconcile |
| **Finance (Cleaning)** | Reports | ✅ | - | - | - | export, balance_sheet, profit_loss |
| **Real Estate** | Payments (Rent) | ✅ | ✅ | ✅ | ✅ | print_receipts |
| **Real Estate** | Billing | ✅ | ✅ | ✅ | ✅ | generate_invoices |
| **Real Estate** | Collections | ✅ | ✅ | - | - | send_notices |
| **Real Estate** | Cheques | ✅ | ✅ | ✅ | ✅ | update_status |
| **HR** | Employees | ✅ | ✅ | ✅ | ✅ | view_salary |
| **HR** | Payroll | ✅ | ✅ | ✅ | ✅ | run, approve, view_all |
| **HR** | Leave | ✅ | request | ✅ | - | approve |
| **Cleaning** | Orders | ✅ | ✅ | ✅ | ✅ | assign |
| **Cleaning** | Workorders | ✅ | ✅ | ✅ | - | complete |
| **Core** | Users | ✅ | ✅ | ✅ | ✅ | assign_roles |
| **Core** | Roles | ✅ | ✅ | ✅ | ✅ | manage_permissions |

---

## 🚀 Migration Strategy

1. **Step 1: Create Helper Functions** (No impact on existing functionality)
   - Create `includes/permissions.php`
   - Create permission definition files
   - Test functions

2. **Step 2: Extend Settings Page** (Add UI, no breaking changes)
   - Add Permissions tab
   - Create permission management interface
   - Test UI

3. **Step 3: Seed Default Permissions** (Optional, for existing roles)
   - Create migration script
   - Assign default permissions to existing roles
   - Test default assignments

4. **Step 4: Add Permission Checks** (Gradually, module by module)
   - Start with high-priority modules (Finance, Core)
   - Add checks to sensitive operations
   - Test with different roles

5. **Step 5: Full Rollout** (Complete implementation)
   - Add checks to all modules
   - Update navigation
   - Complete testing

---

## 📝 Implementation Priority

### **High Priority:**
1. Core permission functions
2. Settings page - Permissions tab
3. Finance module permission checks
4. Core module permission checks (users, roles)

### **Medium Priority:**
5. Real Estate module permission checks
6. HR module permission checks
7. Cleaning module permission checks

### **Low Priority:**
8. Permission audit logging
9. Permission analytics/reporting
10. Advanced features (unit-level access, etc.)

---

## ✅ Success Criteria

- [ ] Permission helper functions implemented and tested
- [ ] Settings page has Permissions tab
- [ ] Can assign permissions to roles per module
- [ ] Permission checks work across all modules
- [ ] Navigation filtered based on permissions
- [ ] UI elements hidden/shown based on permissions
- [ ] Finance module has permission checks
- [ ] HR module has permission checks
- [ ] Real Estate module has permission checks
- [ ] Cleaning module has permission checks
- [ ] Core module has permission checks
- [ ] Documentation complete
- [ ] User training materials prepared

---

## 🔄 Backward Compatibility

- Existing role-based access continues to work
- `require_role()` still functions
- `require_module_access()` still functions
- Existing pages work without permission checks (gradual rollout)
- No breaking changes to existing functionality

---

**End of Document**
