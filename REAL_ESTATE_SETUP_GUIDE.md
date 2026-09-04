# Real Estate Company Setup Guide

This guide will help you add a Real Estate company to your system and assign users with proper access.

## 🚀 Quick Setup (Recommended)

### Option 1: Use the Web Interface (Easiest)

1. **Access the Setup Page:**
   - URL: `http://localhost/herosysgro/setup_realestate_company.php`
   - You must be logged in as Owner or Admin

2. **Follow the 4 Steps:**

   **Step 1: Create Real Estate Company**
   - Enter company name (e.g., "ABC Real Estate")
   - Enter company code (optional, e.g., "ABC-RE")
   - Click "Create Company"

   **Step 2: Create Roles**
   - Click "Create Real Estate Roles"
   - This creates: Property Manager, Leasing Agent, Property Administrator

   **Step 3: Assign Users to Company**
   - Select a user from the dropdown
   - Select the Real Estate company
   - Check "Set as Primary Company" if this is their main company
   - Click "Assign User to Company"

   **Step 4: Assign Roles to Users**
   - Select a user
   - Select a Real Estate role (Property Manager, Leasing Agent, etc.)
   - Click "Assign Role to User"

### Option 2: Use SQL Script

1. **Open the SQL file:**
   - File: `migrations/setup_realestate_company.sql`

2. **Edit and run the SQL commands:**
   - Replace placeholder values with your actual data
   - Run in phpMyAdmin or your MySQL client

## 📋 Step-by-Step Manual Setup

### Step 1: Create the Company

```sql
INSERT INTO companies (name, code, business_type, is_active) 
VALUES ('Your Real Estate Company Name', 'COMPANY_CODE', 'realestate', 1);
```

**Example:**
```sql
INSERT INTO companies (name, code, business_type, is_active) 
VALUES ('ABC Real Estate', 'ABC-RE', 'realestate', 1);
```

### Step 2: Create Real Estate Roles

```sql
INSERT IGNORE INTO roles (name, module, is_system) VALUES 
('Property Manager', 'realestate', 0),
('Leasing Agent', 'realestate', 0),
('Property Administrator', 'realestate', 0);
```

### Step 3: Assign Users to the Company

First, find your user ID:
```sql
SELECT id, username, fullname FROM user;
```

Then assign the user to the Real Estate company:
```sql
-- Replace USER_ID and COMPANY_ID with actual values
INSERT INTO user_companies (user_id, company_id, is_primary) 
VALUES (USER_ID, COMPANY_ID, 0);
```

**Example:**
```sql
-- If user ID is 1 and Real Estate company ID is 2
INSERT INTO user_companies (user_id, company_id, is_primary) 
VALUES (1, 2, 0);
```

### Step 4: Assign Roles to Users

```sql
-- Replace USER_ID with actual user ID
-- This assigns Property Manager role
INSERT INTO user_roles (user_id, role_id) 
SELECT USER_ID, id FROM roles WHERE name = 'Property Manager' AND module = 'realestate';
```

**Example:**
```sql
-- Assign Property Manager to user ID 1
INSERT INTO user_roles (user_id, role_id) 
SELECT 1, id FROM roles WHERE name = 'Property Manager' AND module = 'realestate';

-- Assign Leasing Agent to user ID 2
INSERT INTO user_roles (user_id, role_id) 
SELECT 2, id FROM roles WHERE name = 'Leasing Agent' AND module = 'realestate';
```

## ✅ Verification Queries

### Check if company was created:
```sql
SELECT id, name, code, business_type, is_active 
FROM companies 
WHERE business_type = 'realestate';
```

### Check user-company assignments:
```sql
SELECT uc.user_id, u.username, uc.company_id, c.name as company_name, uc.is_primary
FROM user_companies uc
JOIN user u ON u.id = uc.user_id
JOIN companies c ON c.id = uc.company_id
WHERE c.business_type = 'realestate';
```

### Check user roles:
```sql
SELECT ur.user_id, u.username, r.name as role_name, r.module
FROM user_roles ur
JOIN user u ON u.id = ur.user_id
JOIN roles r ON r.id = ur.role_id
WHERE r.module = 'realestate';
```

## 🎯 What Happens After Setup?

Once you've completed the setup:

1. **User Login:**
   - User logs in at `/login`
   - System detects they have access to Real Estate module
   - They see the module selector page

2. **Module Selection:**
   - User selects the Real Estate company
   - User selects the Real Estate module
   - They're redirected to `/herosysgro/modules/realestate/`

3. **Access:**
   - User can now access all Real Estate features:
     - Buildings management
     - Units management
     - Tenants management
     - Leases management
     - Payments tracking
     - Maintenance requests

## 🔐 Role Permissions

### Property Manager
- Full access to all Real Estate features
- Can manage buildings, units, tenants, leases
- Can process payments
- Can handle maintenance requests

### Leasing Agent
- Can manage tenants and leases
- Can process payments
- Limited access to building/unit management

### Property Administrator
- Administrative access
- Can manage all aspects of the Real Estate module

## 📝 Notes

- **Primary Company:** Set `is_primary = 1` if this is the user's main company
- **Multiple Companies:** Users can be assigned to multiple companies
- **Multiple Roles:** Users can have multiple roles
- **Owner/Admin:** Owner and Admin roles automatically have access to all modules

## 🆘 Troubleshooting

**Issue: User can't see Real Estate module**
- Check: User is assigned to a Real Estate company (`user_companies` table)
- Check: User has a Real Estate role (`user_roles` table)
- Check: Company `business_type` is `'realestate'`

**Issue: "Access denied" error**
- Check: User has the correct role assigned
- Check: User is assigned to the correct company
- Check: Company is active (`is_active = 1`)

**Issue: Module selector not showing**
- Check: User has access to multiple companies/modules
- If only one company/module, system auto-redirects

## 📞 Need Help?

If you encounter issues:
1. Check the verification queries above
2. Review the error messages
3. Check the browser console for JavaScript errors
4. Verify database tables are created correctly

