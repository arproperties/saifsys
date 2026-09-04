# Troubleshooting: Real Estate Module Access Issues

## Common Problem: System Opens Cleaning Module Instead of Real Estate

If after setting up a Real Estate company and assigning users, the system still opens the Cleaning module, follow these steps:

## ✅ Checklist

### 1. Verify User Has Real Estate Company Assignment

```sql
-- Check if user is assigned to Real Estate company
SELECT uc.user_id, u.username, c.name as company_name, c.business_type, uc.is_primary
FROM user_companies uc
JOIN user u ON u.id = uc.user_id
JOIN companies c ON c.id = uc.company_id
WHERE u.username = 'YOUR_USERNAME' AND c.business_type = 'realestate';
```

**If no results:** User is NOT assigned to Real Estate company. Go to Step 3 in setup.

### 2. Verify User Has Real Estate Role

```sql
-- Check if user has Real Estate role
SELECT ur.user_id, u.username, r.name as role_name, r.module
FROM user_roles ur
JOIN user u ON u.id = ur.user_id
JOIN roles r ON r.id = ur.role_id
WHERE u.username = 'YOUR_USERNAME' AND r.module = 'realestate';
```

**If no results:** User does NOT have a Real Estate role. Go to Step 4 in setup.

### 3. Check User's Primary Company

```sql
-- Check which company is set as primary
SELECT uc.user_id, u.username, c.name as company_name, c.business_type, uc.is_primary
FROM user_companies uc
JOIN user u ON u.id = uc.user_id
JOIN companies c ON c.id = uc.company_id
WHERE u.username = 'YOUR_USERNAME'
ORDER BY uc.is_primary DESC;
```

**If Cleaning company is primary:** This might cause auto-redirect to Cleaning module.

## 🔧 Solutions

### Solution 1: Use Debug Tool

1. Go to: `http://localhost/herosysgro/debug_user_access.php?user_id=USER_ID`
2. This will show you exactly what the system sees for that user
3. Check the "Auto-Redirect Logic Analysis" section

### Solution 2: Set Real Estate Company as Primary

**Option A: Using Setup Page**
1. Go to `setup_realestate_company.php`
2. Use the "Set Primary Company" section
3. Select user and Real Estate company
4. Click "Set as Primary Company"

**Option B: Using SQL**
```sql
-- Replace USER_ID and REAL_ESTATE_COMPANY_ID
UPDATE user_companies 
SET is_primary = 0 
WHERE user_id = USER_ID;

UPDATE user_companies 
SET is_primary = 1 
WHERE user_id = USER_ID AND company_id = REAL_ESTATE_COMPANY_ID;
```

### Solution 3: Ensure User Has Real Estate Role

**Critical:** Users MUST have a Real Estate role to access the module, even if they're assigned to a Real Estate company.

1. Go to `setup_realestate_company.php`
2. Step 2: Create Real Estate roles (if not done)
3. Step 4: Assign a Real Estate role to the user

**SQL Method:**
```sql
-- Assign Property Manager role to user
INSERT INTO user_roles (user_id, role_id) 
SELECT USER_ID, id FROM roles WHERE name = 'Property Manager' AND module = 'realestate';
```

## 🎯 Expected Behavior

### Scenario 1: User has ONE company and ONE module
- **Result:** Auto-redirects to that module
- **Example:** User only has Real Estate company + Real Estate role → Goes to Real Estate

### Scenario 2: User has MULTIPLE companies OR MULTIPLE modules
- **Result:** Shows module selector page
- **Example:** User has Cleaning + Real Estate companies → Shows selector

### Scenario 3: Owner/Admin with multiple companies
- **Result:** Shows index (group overview) or selector
- **Note:** Owner/Admin automatically have access to ALL modules

## 🔍 Debug Steps

1. **Check user's companies:**
   ```sql
   SELECT * FROM user_companies WHERE user_id = USER_ID;
   ```

2. **Check user's roles:**
   ```sql
   SELECT ur.*, r.name, r.module 
   FROM user_roles ur 
   JOIN roles r ON r.id = ur.role_id 
   WHERE ur.user_id = USER_ID;
   ```

3. **Check what modules user should see:**
   - Use the debug tool: `debug_user_access.php?user_id=USER_ID`
   - This shows exactly what `get_user_modules()` returns

4. **Clear session and try again:**
   - Logout completely
   - Clear browser cache/cookies
   - Login again

## ⚠️ Common Mistakes

1. **Only assigning company, not role:**
   - ❌ User assigned to Real Estate company but no Real Estate role
   - ✅ User needs BOTH company assignment AND role

2. **Cleaning company still primary:**
   - ❌ Real Estate company exists but Cleaning is set as primary
   - ✅ Set Real Estate company as primary OR ensure user sees selector

3. **Owner/Admin expecting single module:**
   - ❌ Owner/Admin with multiple companies expects auto-redirect
   - ✅ Owner/Admin with multiple companies should see selector or index

## 📝 Quick Fix SQL

If you want to quickly fix a user's access:

```sql
-- 1. Get Real Estate company ID
SET @re_company_id = (SELECT id FROM companies WHERE business_type = 'realestate' LIMIT 1);

-- 2. Get Real Estate role ID
SET @re_role_id = (SELECT id FROM roles WHERE module = 'realestate' LIMIT 1);

-- 3. Assign user to Real Estate company (replace USER_ID)
INSERT IGNORE INTO user_companies (user_id, company_id, is_primary) 
VALUES (USER_ID, @re_company_id, 1);

-- 4. Set Real Estate as primary, remove primary from others
UPDATE user_companies SET is_primary = 0 WHERE user_id = USER_ID;
UPDATE user_companies SET is_primary = 1 WHERE user_id = USER_ID AND company_id = @re_company_id;

-- 5. Assign Real Estate role (replace USER_ID)
INSERT IGNORE INTO user_roles (user_id, role_id) 
VALUES (USER_ID, @re_role_id);
```

## 🆘 Still Not Working?

1. Use the debug tool to see what's happening
2. Check browser console for JavaScript errors
3. Verify database tables are correct
4. Check Apache error logs
5. Ensure `.htaccess` is working (try accessing `/herosysgro/modules/realestate/` directly)

