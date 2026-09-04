# 🚀 Safe Database Deployment Guide - Localhost to Hostinger

## ⚠️ CRITICAL: Read This First!

**This guide will help you safely update your live database on Hostinger without losing any existing data.**

---

## 📋 Pre-Deployment Checklist

### Step 1: Backup Your Live Database (MANDATORY)

**Option A: Using phpMyAdmin (Recommended for Hostinger)**

1. Log into your Hostinger cPanel
2. Open **phpMyAdmin**
3. Select your database (usually `bestsys` or similar)
4. Click **Export** tab
5. Choose **Quick** export method
6. Format: **SQL**
7. Click **Go** to download the backup
8. **Save this file in a safe place!**

**Option B: Using Command Line (if you have SSH access)**

```bash
mysqldump -u your_username -p your_database_name > backup_$(date +%Y%m%d_%H%M%S).sql
```

**Option C: Using Hostinger Backup Tool**

1. Go to Hostinger cPanel
2. Find **Backup** or **Backup Wizard**
3. Create a full backup including database
4. Download and verify the backup file

---

### Step 2: Compare Database Structures

**On Localhost:**
```bash
# Export structure only (no data)
mysqldump -u root -p --no-data bestsys > localhost_structure.sql
```

**On Live Server (via phpMyAdmin):**
1. Go to phpMyAdmin
2. Select database
3. Click **Export**
4. Choose **Custom** method
5. Uncheck **Data** (only export structure)
6. Click **Go**

**Compare the files** to see what's different.

---

### Step 3: Identify What's New

The main new addition is:
- ✅ **`role_departments` table** - For the new department-based RBAC system

**Other tables that should already exist:**
- `companies`
- `user_companies`
- `role_modules`
- All Real Estate tables (`re_*`)
- All document management tables

---

## 🔧 Deployment Steps

### Method 1: Using phpMyAdmin (Easiest - Recommended)

1. **Log into Hostinger cPanel**
2. **Open phpMyAdmin**
3. **Select your database** (e.g., `bestsys`)
4. **Click the SQL tab**
5. **Open the migration file:**
   - File: `migrations/SAFE_LIVE_UPDATE.sql`
   - Copy the entire contents
6. **Paste into the SQL query box**
7. **Click Go**
8. **Check for errors:**
   - If you see "Table already exists" - that's OK, it means the table was already there
   - If you see "Duplicate column name" - that's OK, column already exists
   - If you see other errors, **STOP** and check the error message

### Method 2: Using Command Line (SSH)

```bash
# Connect to your server via SSH
ssh your_username@your_server_ip

# Navigate to your project directory
cd /path/to/your/project

# Run the migration
mysql -u your_db_user -p your_database_name < migrations/SAFE_LIVE_UPDATE.sql
```

### Method 3: Using MySQL Client

```bash
mysql -h your_host -u your_username -p your_database_name < migrations/SAFE_LIVE_UPDATE.sql
```

---

## ✅ Verification Steps

After running the migration, verify everything is correct:

### 1. Check if `role_departments` table exists:

```sql
SHOW TABLES LIKE 'role_departments';
```

Should return: `role_departments`

### 2. Check table structure:

```sql
DESCRIBE role_departments;
```

Should show:
- `id` (INT, PRIMARY KEY)
- `role_id` (INT)
- `module` (VARCHAR(50))
- `department` (VARCHAR(50))
- `created_at` (DATETIME)

### 3. Verify existing data is intact:

```sql
-- Check your main tables still have data
SELECT COUNT(*) FROM user;
SELECT COUNT(*) FROM client;
SELECT COUNT(*) FROM companies;
SELECT COUNT(*) FROM user_companies;
```

All counts should match your previous numbers.

### 4. Test the new functionality:

1. Log into your live system
2. Go to **Settings → Departments** tab
3. You should be able to assign departments to roles
4. Test user login and department access

---

## 🔄 What This Migration Does

### ✅ Safe Operations (No Data Loss):

1. **Creates `role_departments` table** (only if it doesn't exist)
   - This is the new table for department-based permissions
   - Empty table, no data migration needed

2. **Does NOT:**
   - ❌ Delete any tables
   - ❌ Delete any columns
   - ❌ Modify any existing data
   - ❌ Drop any indexes
   - ❌ Change any data types

### ⚠️ What You Need to Do Manually:

1. **Assign departments to roles:**
   - After migration, go to **Settings → Departments**
   - Assign departments to your existing roles
   - This is a one-time setup

2. **Update user permissions:**
   - Users will inherit department access from their roles
   - No manual user updates needed

---

## 🚨 Rollback Plan (If Something Goes Wrong)

If you encounter any issues:

### Step 1: Stop the Migration
- If migration is still running, wait for it to complete or cancel if possible

### Step 2: Restore from Backup
- Go to phpMyAdmin
- Select your database
- Click **Import** tab
- Choose your backup file
- Click **Go**

### Step 3: Verify Data
- Check that all your data is back
- Test a few key functions

---

## 📝 Post-Deployment Checklist

- [ ] Migration completed without errors
- [ ] `role_departments` table exists
- [ ] All existing data is intact
- [ ] Can log into the system
- [ ] Settings → Departments page works
- [ ] Can assign departments to roles
- [ ] User permissions work correctly
- [ ] No PHP errors in error logs

---

## 🆘 Troubleshooting

### Error: "Table 'role_departments' already exists"
- **Solution:** This is OK! The table was already created. Skip this part.

### Error: "Unknown column 'company_id' in table"
- **Solution:** You may need to run earlier migrations first. Check which tables are missing `company_id` columns.

### Error: "Foreign key constraint fails"
- **Solution:** Make sure the `roles` table exists and has data. The `role_departments` table references `roles(id)`.

### Error: "Access denied"
- **Solution:** Check your database user permissions. You need CREATE and ALTER privileges.

### Data seems missing after migration
- **Solution:** Immediately restore from your backup! The migration shouldn't delete data, but if it did, restore immediately.

---

## 📞 Support

If you encounter issues:
1. Check the error message carefully
2. Verify your backup is good
3. Check Hostinger error logs
4. Contact support if needed

---

## ✅ Success Criteria

You'll know the migration was successful when:
- ✅ No errors during migration
- ✅ `role_departments` table exists
- ✅ All existing data is present
- ✅ System functions normally
- ✅ New department permissions work

---

**Last Updated:** January 14, 2026  
**Migration File:** `migrations/SAFE_LIVE_UPDATE.sql`
