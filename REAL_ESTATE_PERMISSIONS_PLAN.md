# Real Estate Module - Users, Roles & Permissions System
## Comprehensive Permission Control Plan

**Date:** January 11, 2026  
**Status:** Planning Document  
**Priority:** High - Core Security Feature

---

## 🎯 Overview

Implement a comprehensive role-based access control (RBAC) system for the Real Estate module that provides granular permissions for different users and roles. This will allow fine-grained control over what each user can see, access, create, edit, and delete.

---

## 📊 Current State

### ✅ **What We Have:**
- Multi-company role system exists (`roles`, `user_roles`, `companies`)
- Module access control (`require_module_access`)
- Real Estate roles can be created
- Company-based filtering is implemented

### ❌ **What's Missing:**
- Granular permissions (view, create, edit, delete)
- Role-specific features/views
- Unit-level access control
- Financial data restrictions
- Feature-specific permissions

---

## 🏗️ Database Schema

### 1. **Permission Definitions Table**

```sql
CREATE TABLE IF NOT EXISTS `re_permissions` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `permission_key` VARCHAR(100) NOT NULL UNIQUE,
  `permission_name` VARCHAR(255) NOT NULL,
  `module` VARCHAR(50) NOT NULL DEFAULT 'realestate',
  `category` VARCHAR(50) NOT NULL COMMENT 'buildings, units, tenants, leases, payments, maintenance, etc.',
  `description` TEXT DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_module_category` (`module`, `category`),
  KEY `idx_permission_key` (`permission_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

### 2. **Role Permissions Table**

```sql
CREATE TABLE IF NOT EXISTS `re_role_permissions` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `role_id` INT(11) NOT NULL,
  `permission_id` INT(11) NOT NULL,
  `granted` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = granted, 0 = denied',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_role_permission` (`role_id`, `permission_id`),
  KEY `idx_role` (`role_id`),
  KEY `idx_permission` (`permission_id`),
  FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`permission_id`) REFERENCES `re_permissions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

### 3. **User Unit Access Table** (For Unit-Level Permissions)

```sql
CREATE TABLE IF NOT EXISTS `re_user_unit_access` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `unit_id` INT(11) NOT NULL,
  `company_id` INT(11) NOT NULL,
  `can_view` TINYINT(1) NOT NULL DEFAULT 1,
  `can_edit` TINYINT(1) NOT NULL DEFAULT 0,
  `notes` TEXT DEFAULT NULL,
  `granted_by` INT(11) DEFAULT NULL COMMENT 'user_id who granted access',
  `granted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_unit` (`user_id`, `unit_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_unit` (`unit_id`),
  KEY `idx_company` (`company_id`),
  FOREIGN KEY (`user_id`) REFERENCES `user`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`unit_id`) REFERENCES `re_units`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`granted_by`) REFERENCES `user`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

---

## 🔑 Permission Structure

### Permission Categories

#### **1. Buildings (Category: `buildings`)**
- `buildings.view` - View buildings list
- `buildings.view_details` - View building details
- `buildings.create` - Create new buildings
- `buildings.edit` - Edit existing buildings
- `buildings.delete` - Delete buildings
- `buildings.manage_floors` - Manage building floors

#### **2. Units (Category: `units`)**
- `units.view` - View units list
- `units.view_details` - View unit details
- `units.create` - Create new units
- `units.edit` - Edit existing units
- `units.delete` - Delete units
- `units.change_status` - Change unit status
- `units.view_financial` - View unit financial data

#### **3. Tenants (Category: `tenants`)**
- `tenants.view` - View tenants list
- `tenants.view_details` - View tenant details
- `tenants.create` - Create new tenants
- `tenants.edit` - Edit existing tenants
- `tenants.delete` - Delete tenants
- `tenants.view_documents` - View tenant documents
- `tenants.manage_documents` - Manage tenant documents

#### **4. Leases (Category: `leases`)**
- `leases.view` - View leases list
- `leases.view_details` - View lease details
- `leases.create` - Create new leases
- `leases.edit` - Edit existing leases
- `leases.delete` - Delete leases
- `leases.change_status` - Change lease status
- `leases.generate_contract` - Generate lease contracts
- `leases.send_contract` - Send contracts via email
- `leases.renew` - Renew leases
- `leases.terminate` - Terminate leases
- `leases.view_financial` - View lease financial data

#### **5. Payments (Category: `payments`)**
- `payments.view` - View payments list
- `payments.view_details` - View payment details
- `payments.create` - Record new payments
- `payments.edit` - Edit existing payments
- `payments.delete` - Delete payments
- `payments.view_receipts` - View payment receipts
- `payments.print_receipts` - Print payment receipts

#### **6. Billing (Category: `billing`)**
- `billing.view` - View billing items
- `billing.create` - Create billing items
- `billing.edit` - Edit billing items
- `billing.delete` - Delete billing items
- `billing.generate_invoices` - Generate invoices
- `billing.view_cheques` - View post-dated cheques
- `billing.manage_cheques` - Manage cheques (update status)

#### **7. Collections (Category: `collections`)**
- `collections.view` - View collections/overdue items
- `collections.manage` - Manage collections
- `collections.send_notices` - Send collection notices

#### **8. Maintenance (Category: `maintenance`)**
- `maintenance.view` - View maintenance requests
- `maintenance.view_details` - View request details
- `maintenance.create` - Create maintenance requests
- `maintenance.edit` - Edit maintenance requests
- `maintenance.assign` - Assign maintenance to vendors/staff
- `maintenance.complete` - Mark maintenance as complete
- `maintenance.view_costs` - View maintenance costs
- `maintenance.approve_costs` - Approve maintenance costs
- `preventive_maintenance.view` - View preventive maintenance
- `preventive_maintenance.manage` - Manage preventive maintenance

#### **9. Vendors (Category: `vendors`)**
- `vendors.view` - View vendors list
- `vendors.view_details` - View vendor details
- `vendors.create` - Create new vendors
- `vendors.edit` - Edit existing vendors
- `vendors.delete` - Delete vendors
- `vendors.view_performance` - View vendor performance
- `vendors.manage_agreements` - Manage vendor agreements

#### **10. Move-In/Out (Category: `move_in_out`)**
- `move_in.view` - View move-in records
- `move_in.create` - Create move-in records
- `move_in.complete` - Complete move-in process
- `move_out.view` - View move-out records
- `move_out.create` - Create move-out records
- `move_out.complete` - Complete move-out process

#### **11. Tasks (Category: `tasks`)**
- `tasks.view` - View tasks
- `tasks.create` - Create tasks
- `tasks.edit` - Edit tasks
- `tasks.assign` - Assign tasks
- `tasks.complete` - Complete tasks

#### **12. Compliance & Documents (Category: `compliance`)**
- `compliance.view` - View compliance records
- `compliance.manage` - Manage compliance
- `documents.view` - View documents
- `documents.upload` - Upload documents
- `documents.delete` - Delete documents

#### **13. Reports (Category: `reports`)**
- `reports.view` - View reports
- `reports.export` - Export reports
- `reports.financial` - View financial reports
- `reports.analytics` - View analytics/reports

#### **14. Settings (Category: `settings`)**
- `settings.view` - View settings
- `settings.edit` - Edit settings
- `settings.manage_users` - Manage users and roles
- `settings.manage_permissions` - Manage permissions

---

## 👥 Role Definitions

### **1. Property Manager (Full Access)**
- Full access to all features
- Can manage all buildings, units, tenants, leases
- Can view and manage all financial data
- Can manage users and permissions (within their company)

**Permissions:**
- All `*.view`, `*.create`, `*.edit`, `*.delete` permissions
- All `settings.*` permissions

### **2. Leasing Agent (Lease Management)**
- View buildings, units, tenants
- Create and manage leases
- Generate and send contracts
- View lease-related payments
- Cannot access financial reports or maintenance costs

**Permissions:**
- `buildings.view`, `buildings.view_details`
- `units.view`, `units.view_details`, `units.change_status`
- `tenants.*` (all tenant permissions)
- `leases.*` (all lease permissions)
- `payments.view`, `payments.view_details`, `payments.create`
- `move_in.*`, `move_out.*`
- `documents.view`, `documents.upload`

### **3. Finance Officer (Financial Management)**
- View all properties and tenants (read-only)
- Full access to payments, billing, collections
- Can view and manage financial reports
- Cannot edit leases or maintenance requests
- Cannot manage units/buildings

**Permissions:**
- `buildings.view`, `buildings.view_details`
- `units.view`, `units.view_details`
- `tenants.view`, `tenants.view_details`
- `leases.view`, `leases.view_details`, `leases.view_financial`
- `payments.*` (all payment permissions)
- `billing.*` (all billing permissions)
- `collections.*` (all collection permissions)
- `reports.view`, `reports.export`, `reports.financial`

### **4. Maintenance Team (Maintenance Management)**
- View buildings and units (read-only)
- Full access to maintenance requests
- Can view preventive maintenance
- Can view vendor information
- Cannot access financial data or lease information

**Permissions:**
- `buildings.view`, `buildings.view_details`
- `units.view`, `units.view_details`
- `maintenance.*` (all maintenance permissions)
- `preventive_maintenance.*`
- `vendors.view`, `vendors.view_details`, `vendors.view_performance`
- `tasks.view`, `tasks.create`, `tasks.edit`, `tasks.complete`

### **5. Property Administrator (Limited Management)**
- View all information
- Can edit units, tenants, leases
- Cannot delete records
- Cannot access financial reports
- Cannot manage users

**Permissions:**
- `buildings.view`, `buildings.view_details`, `buildings.edit`
- `units.*` (except `units.delete`)
- `tenants.*` (except `tenants.delete`)
- `leases.*` (except `leases.delete`, `leases.terminate`)
- `payments.view`, `payments.view_details`
- `maintenance.view`, `maintenance.view_details`, `maintenance.edit`
- `move_in.*`, `move_out.*`

### **6. Tenant Services (Customer Service)**
- View tenant information
- View lease information
- Can create maintenance requests
- Can view move-in/out records
- Cannot access financial data

**Permissions:**
- `tenants.view`, `tenants.view_details`
- `leases.view`, `leases.view_details`
- `maintenance.view`, `maintenance.create`, `maintenance.view_details`
- `move_in.view`, `move_in.view_details`
- `move_out.view`, `move_out.view_details`
- `documents.view`

### **7. Read-Only User (Viewer)**
- Can view all information (read-only)
- Cannot create, edit, or delete anything
- Cannot access financial reports

**Permissions:**
- All `*.view` and `*.view_details` permissions
- No `*.create`, `*.edit`, `*.delete` permissions

---

## 💻 Implementation Approach

### **Phase 1: Database & Core Infrastructure**

1. **Create Database Tables**
   - Create `re_permissions` table
   - Create `re_role_permissions` table
   - Create `re_user_unit_access` table (optional, for unit-level access)

2. **Seed Permissions**
   - Create migration script to insert all permission definitions
   - Define all permissions as listed above

3. **Create Permission Helper Functions**
   - `has_re_permission($permission_key)` - Check if user has permission
   - `require_re_permission($permission_key)` - Require permission (throws error if not)
   - `get_user_re_permissions($user_id)` - Get all user permissions
   - `can_user_access_unit($user_id, $unit_id)` - Check unit-level access

**File:** `modules/realestate/includes/re_permissions.php`

### **Phase 2: Permission Checks in Existing Code**

1. **Update All Pages**
   - Add permission checks to all pages
   - Hide UI elements based on permissions
   - Show/hide buttons and actions based on permissions

2. **Update Navigation**
   - Filter sidebar navigation based on permissions
   - Hide menu items user cannot access

3. **Update Forms**
   - Disable/hide form fields based on permissions
   - Prevent unauthorized submissions

**Example Implementation:**
```php
// At top of page
require_re_permission('leases.view');

// In template
<?php if (has_re_permission('leases.create')): ?>
    <a href="lease_add.php" class="btn btn-primary">New Lease</a>
<?php endif; ?>

<?php if (has_re_permission('leases.edit')): ?>
    <a href="lease_add.php?id=<?= $leaseId ?>" class="btn btn-sm btn-secondary">Edit</a>
<?php endif; ?>

<?php if (has_re_permission('leases.delete')): ?>
    <button onclick="deleteLease(<?= $leaseId ?>)" class="btn btn-sm btn-danger">Delete</button>
<?php endif; ?>
```

### **Phase 3: Role Management UI**

1. **Role Permissions Management Page**
   - Create `roles_permissions.php` page
   - Allow admins to assign permissions to roles
   - Visual interface with checkboxes per permission category

2. **User Role Assignment**
   - Enhance existing user/role assignment
   - Show available roles with descriptions
   - Allow multiple role assignment per user

### **Phase 4: Advanced Features**

1. **Unit-Level Access Control**
   - Implement unit access restrictions
   - Allow property managers to grant unit access to specific users
   - Filter unit lists based on access

2. **Financial Data Restrictions**
   - Hide financial data from non-finance users
   - Create separate views for financial data
   - Implement data masking for sensitive information

3. **Audit Logging**
   - Log permission-denied access attempts
   - Track who accessed what and when

---

## 📁 File Structure

```
modules/realestate/
├── includes/
│   ├── re_permissions.php          # Permission helper functions
│   ├── re_role_permissions.php     # Role permission management
│   └── re_user_access.php          # User access checks
├── admin/
│   ├── roles_permissions.php       # Manage role permissions
│   ├── user_roles.php              # Assign roles to users
│   └── permissions_list.php        # View all permissions
└── migrations/
    ├── create_re_permissions_tables.sql
    └── seed_re_permissions.php
```

---

## 🔐 Security Considerations

1. **Server-Side Validation**
   - Always check permissions on server-side
   - Never rely solely on client-side checks
   - Validate permissions before database operations

2. **Default Deny**
   - Default to denying access if permission is unclear
   - Explicitly grant permissions, never assume

3. **Least Privilege**
   - Assign minimum permissions needed
   - Regularly review and audit permissions

4. **Company Isolation**
   - Ensure permissions respect company boundaries
   - Users can only access their company's data

---

## 📊 Permission Matrix (Quick Reference)

| Permission Category | Property Manager | Leasing Agent | Finance Officer | Maintenance Team | Property Admin | Tenant Services | Read-Only |
|---------------------|------------------|---------------|-----------------|------------------|----------------|-----------------|-----------|
| Buildings | All | View | View | View | View/Edit | - | View |
| Units | All | View | View | View | All (no delete) | - | View |
| Tenants | All | All | View | - | All (no delete) | View | View |
| Leases | All | All | View/Financial | - | All (no delete/terminate) | View | View |
| Payments | All | View/Create | All | - | View | - | View |
| Billing | All | View | All | - | View | - | View |
| Maintenance | All | View | View | All | View/Edit | Create/View | View |
| Vendors | All | View | - | View | View | - | View |
| Reports | All | - | Financial | - | - | - | - |

---

## 🚀 Migration Strategy

1. **Step 1: Create Tables** (No impact on existing functionality)
2. **Step 2: Seed Permissions** (Add permission definitions)
3. **Step 3: Assign Default Permissions** (Assign permissions to existing roles)
4. **Step 4: Add Permission Checks** (Gradually add checks to pages)
5. **Step 5: Test & Refine** (Test with different roles, adjust as needed)

---

## 📝 Next Steps

1. **Review and Approve Plan**
   - Review permission structure
   - Approve role definitions
   - Confirm implementation approach

2. **Create Database Schema**
   - Create migration scripts
   - Seed initial permissions
   - Test database structure

3. **Implement Core Functions**
   - Create permission helper functions
   - Test permission checking logic

4. **Start Implementation**
   - Begin with high-priority pages (Leases, Payments)
   - Gradually add to all pages
   - Test with different user roles

---

## ✅ Success Criteria

- [ ] All permission tables created and seeded
- [ ] Permission helper functions implemented and tested
- [ ] All pages have appropriate permission checks
- [ ] Navigation filtered based on permissions
- [ ] UI elements hidden/shown based on permissions
- [ ] Role management UI functional
- [ ] Different roles can access appropriate features
- [ ] Financial data properly restricted
- [ ] Unit-level access control working (if implemented)
- [ ] Documentation complete
- [ ] User training materials prepared

---

**End of Document**
