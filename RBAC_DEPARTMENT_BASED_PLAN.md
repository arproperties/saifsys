# RBAC System - Department-Based Design
## Simple, Clear, Module-Aware Permission System

**Date:** January 12, 2026  
**Status:** Design Document  
**Approach:** Department-based (not granular permissions)

---

## 🎯 Core Concept

**Permission Model:** `Role → Module → Department → Access`

- **Roles** are assigned to **Users**
- **Roles** have access to **Modules** and **Departments** within those modules
- **No granular permissions** (no invoices.view, invoices.create, etc.)
- **Department-level access** only (if you have access to a department, you have full access to that department)

---

## 📦 Modules

### **1. Cleaning Module**
Business type: `cleaning`

### **2. Real Estate Module**
Business type: `realestate`

**Note:** These are completely separate systems, except where explicitly shared.

---

## 🏢 Departments / Functional Areas

### **Cleaning Module Departments:**

1. **Operations** (`cleaning_operations`)
   - All under http://localhost/herosysgro/operation.php
   
2. **Accounts** (`cleaning_accounts`)
   - All under http://localhost/herosysgro/account.php

3. **HR** (`hr`) - **SHARED**
   - All under http://localhost/herosysgro/hr/dashboard.php

### **Real Estate Module Departments:**

1. **Core Management** (`realestate_core`)
   - Dashboard
   - Buildings
   - Units
   - Tenants
   - Leases
   - Expiry Reminders
   - Renewal Workflow
   - Contract Templates

2. **Financial** (`realestate_financial`)
   - Payments
   - Billing
   - Collections
   - Post-Dated Cheques

3. **Maintenance** (`realestate_maintenance`)
   - Maintenance Requests
   - Preventive Maintenance
   - Vendors
   - SLA Dashboard
   - SLA Config

4. **Operations** (`realestate_operations`)
   - Move-Ins
   - Move-Outs
   - Tasks

5. **Compliance & Reports** (`realestate_compliance`)
   - Compliance
   - Documents
   - Reports

6. **HR** (`hr`) - **SHARED**
   - Same as Cleaning HR (shared across modules)

---

## 🔑 Permission Rules

### **Rule 1: Module-Specific Departments**
- `cleaning_operations` and `cleaning_accounts` are **ONLY** available in Cleaning module
- `realestate_core`, `realestate_financial`, `realestate_maintenance`, `realestate_operations`, `realestate_compliance` are **ONLY** available in Real Estate module

### **Rule 2: Shared Departments**
- `hr` is **SHARED** across both modules
- If a role has `hr` access, they can access HR in both Cleaning and Real Estate

### **Rule 3: Department-Level Access**
- If a role has access to a department, they have **FULL ACCESS** to all features in that department
- No sub-permissions (no view/create/edit/delete distinctions)
- Either you have the department or you don't

### **Rule 4: No Default Access**
- Users only see modules they have access to
- Users only see departments they have access to within those modules
- No cross-module data leakage

---

## 📊 Database Schema

### **Table: `role_departments`**

```sql
CREATE TABLE IF NOT EXISTS `role_departments` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `role_id` INT(11) NOT NULL,
  `module` VARCHAR(50) NOT NULL COMMENT 'cleaning or realestate',
  `department` VARCHAR(50) NOT NULL COMMENT 'Department code (e.g., cleaning_operations, hr)',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_role_module_department` (`role_id`, `module`, `department`),
  KEY `idx_role_id` (`role_id`),
  KEY `idx_module` (`module`),
  KEY `idx_department` (`department`),
  FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

**Department Codes:**
- `cleaning_operations` - Cleaning Operations
- `cleaning_accounts` - Cleaning Accounts
- `realestate_core` - Real Estate Core Management
- `realestate_financial` - Real Estate Financial
- `realestate_maintenance` - Real Estate Maintenance
- `realestate_operations` - Real Estate Operations
- `realestate_compliance` - Real Estate Compliance & Reports
- `hr` - HR (Shared)

---

## 👥 Example Roles

### **Example 1: Accountant (Cleaning Only)**
```sql
-- Role: Accountant (id = 4, module = 'cleaning')
INSERT INTO role_departments (role_id, module, department) VALUES
(4, 'cleaning', 'cleaning_accounts');
```

**Access:**
- ✅ Cleaning Module → Accounts Department
- ❌ Cleaning Operations
- ❌ HR
- ❌ Any Real Estate

### **Example 2: Accountant (Cleaning + Real Estate)**
```sql
-- Role: Accountant (id = 4)
INSERT INTO role_departments (role_id, module, department) VALUES
(4, 'cleaning', 'cleaning_accounts'),
(4, 'realestate', 'realestate_financial');
```

**Access:**
- ✅ Cleaning Module → Accounts
- ✅ Real Estate Module → Financial
- ❌ Operations in any module
- ❌ HR (unless explicitly added)

### **Example 3: Operations Role (Cleaning)**
```sql
-- Role: Operations Manager (id = X, module = 'cleaning')
INSERT INTO role_departments (role_id, module, department) VALUES
(X, 'cleaning', 'cleaning_operations');
```

**Access:**
- ✅ Cleaning Module → Operations
- ❌ Cleaning Accounts
- ❌ HR
- ❌ Any Real Estate

### **Example 4: HR Role (Shared)**
```sql
-- Role: HR Manager (id = Y)
INSERT INTO role_departments (role_id, module, department) VALUES
(Y, 'cleaning', 'hr'),
(Y, 'realestate', 'hr');
```

**Access:**
- ✅ HR in Cleaning Module
- ✅ HR in Real Estate Module
- ❌ Operations or Accounts in any module

### **Example 5: Property Manager (Real Estate)**
```sql
-- Role: Property Manager (id = Z, module = 'realestate')
INSERT INTO role_departments (role_id, module, department) VALUES
(Z, 'realestate', 'realestate_core'),
(Z, 'realestate', 'realestate_financial'),
(Z, 'realestate', 'realestate_maintenance'),
(Z, 'realestate', 'realestate_operations'),
(Z, 'realestate', 'realestate_compliance');
```

**Access:**
- ✅ All Real Estate departments
- ❌ Any Cleaning module

---

## 💻 Implementation

### **Helper Functions**

#### **1. Check Department Access**
```php
/**
 * Check if user has access to a department in a module
 * @param string $module Module name (cleaning, realestate)
 * @param string $department Department code
 * @param PDO $conn Database connection
 * @return bool
 */
function has_department_access(string $module, string $department, ?PDO $conn = null): bool {
    // Owner and Admin have all access
    $roles = current_user_roles($conn);
    if (in_array('Owner', $roles, true) || in_array('Admin', $roles, true)) {
        return true;
    }
    
    $userId = current_user_id();
    if (!$userId) return false;
    
    // Check if user's roles have access to this department
    $stmt = $conn->prepare("
        SELECT COUNT(*) 
        FROM user_roles ur
        JOIN role_departments rd ON rd.role_id = ur.role_id
        WHERE ur.user_id = ? 
        AND rd.module = ? 
        AND rd.department = ?
    ");
    $stmt->execute([$userId, $module, $department]);
    return $stmt->fetchColumn() > 0;
}
```

#### **2. Require Department Access**
```php
/**
 * Require department access (throws 403 if not granted)
 */
function require_department_access(string $module, string $department, ?PDO $conn = null): void {
    if (!has_department_access($module, $department, $conn)) {
        http_response_code(403);
        die('Access denied to ' . $department . ' department');
    }
}
```

#### **3. Get User's Accessible Departments**
```php
/**
 * Get all departments user has access to, grouped by module
 * @param int $userId User ID
 * @param PDO $conn Database connection
 * @return array ['module' => ['department1', 'department2'], ...]
 */
function get_user_departments(int $userId, ?PDO $conn = null): array {
    // Implementation
}
```

#### **4. Get User's Accessible Modules**
```php
/**
 * Get modules user has access to (based on departments)
 */
function get_user_modules_v2(PDO $conn, int $userId): array {
    // Get distinct modules from role_departments
    // Return modules where user has at least one department access
}
```

---

## 🗺️ Page-to-Department Mapping

### **Cleaning Module**

#### **Operations Department** (`cleaning_operations`)
- `/operation.php` - Main operations page
- `/operation/workorder_list.php` - Work orders
- `/operation/clients.php` - Clients
- `/operation/online_bookings.php` - Online bookings
- All work order related pages

#### **Accounts Department** (`cleaning_accounts`)
- `/account.php` - Main accounts page
- `/accounts/invoices.php` - Invoices
- `/accounts/payments.php` - Payments
- `/accounts/expenses.php` - Expenses
- `/accounts/coa.php` - Chart of Accounts
- All accounts related pages

#### **HR Department** (`hr`) - Shared
- `/hr/dashboard.php` - HR Dashboard
- `/hr/employees.php` - Employees
- `/hr/attendance.php` - Attendance
- `/hr/leave.php` - Leave
- `/hr/payroll.php` - Payroll
- All HR related pages

### **Real Estate Module**

#### **Core Management Department** (`realestate_core`)
- `/modules/realestate/index.php` - Dashboard
- `/modules/realestate/buildings.php` - Buildings
- `/modules/realestate/units.php` - Units
- `/modules/realestate/tenants.php` - Tenants
- `/modules/realestate/leases.php` - Leases
- `/modules/realestate/lease_expiry_reminders.php` - Expiry Reminders
- `/modules/realestate/lease_renewal_workflow.php` - Renewal Workflow
- `/modules/realestate/lease_templates.php` - Contract Templates

#### **Financial Department** (`realestate_financial`)
- `/modules/realestate/payments.php` - Payments
- `/modules/realestate/billing.php` - Billing
- `/modules/realestate/billing_*.php` - All billing pages
- `/modules/realestate/collections.php` - Collections
- `/modules/realestate/billing_cheques.php` - Post-Dated Cheques

#### **Maintenance Department** (`realestate_maintenance`)
- `/modules/realestate/maintenance.php` - Maintenance
- `/modules/realestate/preventive_maintenance.php` - Preventive Maintenance
- `/modules/realestate/vendors.php` - Vendors
- `/modules/realestate/sla_dashboard.php` - SLA Dashboard
- `/modules/realestate/sla_config.php` - SLA Config

#### **Operations Department** (`realestate_operations`)
- `/modules/realestate/move_in.php` - Move-Ins
- `/modules/realestate/move_out.php` - Move-Outs
- `/modules/realestate/tasks.php` - Tasks

#### **Compliance & Reports Department** (`realestate_compliance`)
- `/modules/realestate/compliance.php` - Compliance
- `/modules/realestate/documents.php` - Documents
- `/modules/realestate/reports.php` - Reports
- All report pages

#### **HR Department** (`hr`) - Shared
- Same as Cleaning HR

---

## 🎨 UI Implementation

### **Navigation Sidebar**

**Rule:** Only show departments user has access to

```php
// Example: Real Estate sidebar
<?php if (has_department_access('realestate', 'realestate_core', $conn)): ?>
    <div class="nav-sect">Core Management</div>
    <a href="buildings.php">Buildings</a>
    <a href="units.php">Units</a>
    <!-- etc -->
<?php endif; ?>

<?php if (has_department_access('realestate', 'realestate_financial', $conn)): ?>
    <div class="nav-sect">Financial</div>
    <a href="payments.php">Payments</a>
    <!-- etc -->
<?php endif; ?>
```

### **Page Access Control**

```php
// Example: accounts/invoices.php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db_connect.php';
require_once __DIR__.'/../includes/rbac_department.php';

require_login();
require_department_access('cleaning', 'cleaning_accounts', $conn);
```

---

## 🔄 Migration Strategy

### **Step 1: Create New Table**
- Create `role_departments` table

### **Step 2: Migrate Existing Roles**
- For each role, determine which departments they should have
- Insert into `role_departments` table

### **Step 3: Update Helper Functions**
- Create new `rbac_department.php` file
- Implement department-based functions

### **Step 4: Update Pages**
- Replace permission checks with department checks
- Update navigation to show only accessible departments

### **Step 5: Update Settings UI**
- Replace granular permission UI with department selection UI

---

## ✅ Benefits

1. **Simple:** No complex permission matrices
2. **Clear:** Easy to understand who has access to what
3. **Scalable:** Easy to add new departments
4. **Maintainable:** Less code, less complexity
5. **User-Friendly:** Clear department-based access

---

## 📝 Next Steps

1. Review and approve this design
2. Create database migration
3. Implement helper functions
4. Update pages with department checks
5. Update UI for department assignment
6. Test with example roles

---

**End of Document**
