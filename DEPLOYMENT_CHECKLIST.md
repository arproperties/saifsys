# 🚀 Deployment Checklist for Hostinger

## Pre-Deployment Steps

### 1. ✅ Database Migration
- [x] Database migration completed (`COMPREHENSIVE_LIVE_UPDATE_SAFE.sql`)
- [x] All Real Estate tables created
- [x] All new columns added
- [x] All indexes added

### 2. 📝 Files to Update Before Upload

#### **A. Database Configuration** (`includes/config.php`)
```php
// Change these values:
define('APP_ENV', 'prod'); // Change from 'dev' to 'prod'
define('DB_HOST', 'localhost'); // Usually 'localhost' on Hostinger
define('DB_NAME', 'u385648797_Mainsys'); // Your Hostinger database name
define('DB_USER', 'u385648797_Mainsys'); // Your Hostinger database user
define('DB_PASS', 'YOUR_PASSWORD'); // Your Hostinger database password
```

#### **B. Base Path Configuration**
The system uses `/herosysgro/` as base path. You need to:

**Option 1: If your domain is root (e.g., `yourdomain.com`)**
- Change all `/herosysgro/` to `/` in:
  - `.htaccess` (RewriteBase)
  - All PHP files with hardcoded paths

**Option 2: If your domain has subdirectory (e.g., `yourdomain.com/herosysgro`)**
- Keep `/herosysgro/` as is
- Update `.htaccess` RewriteBase if different

**Option 3: If your domain is subdomain (e.g., `app.yourdomain.com`)**
- Change all `/herosysgro/` to `/` in:
  - `.htaccess` (RewriteBase)
  - All PHP files with hardcoded paths

#### **C. Files with Hardcoded `/herosysgro/` Paths:**
1. `.htaccess` - Line 3: `RewriteBase /herosysgro/`
2. `operation.php` - Multiple navigation links
3. `account.php` - Multiple navigation links
4. `includes/module_access.php` - `get_department_route()` function (line ~80)
5. Any other files with navigation menus

#### **D. Security Settings** (`api/mobile/config.php`)
```php
// Change JWT_SECRET to a strong random string:
define('JWT_SECRET', 'YOUR_STRONG_RANDOM_SECRET_HERE');
// Generate with: openssl rand -base64 32
```

#### **E. Email Configuration** (`includes/email_service.php`)
- Default SMTP settings are in database (`app_email_settings` table)
- Configure via Settings → Email Settings after deployment

### 3. 📤 Files to Upload

**Upload ALL files EXCEPT:**
- ❌ `.git/` directory (if using Git)
- ❌ `node_modules/` (if any)
- ❌ `migrations/` directory (optional - keep for reference)
- ❌ `*.md` documentation files (optional)
- ❌ `localhost_database_structure.sql` (if exists)
- ❌ `live_database_structure.sql` (if exists)

**Required Directories:**
- ✅ `includes/`
- ✅ `operation/`
- ✅ `account/` or `accounts/`
- ✅ `hr/`
- ✅ `modules/realestate/`
- ✅ `api/`
- ✅ `uploads/` (ensure write permissions)
- ✅ `assets/` or `css/`, `js/`, `images/`
- ✅ All root PHP files

### 4. 🔐 File Permissions (via FTP/File Manager)

Set these permissions:
```
Directories: 755
PHP Files: 644
Upload Directories: 755 (or 775 if needed)
```

**Critical Upload Directories:**
- `uploads/` - 755
- `uploads/expenses/` - 755
- `uploads/documents/` - 755
- `modules/realestate/uploads/` - 755 (if exists)
- Any other upload directories

### 5. 🌐 .htaccess Configuration

**If your site is in root directory:**
```apache
RewriteBase /
# Change all /herosysgro/ to / in rewrite rules
```

**If your site is in subdirectory (e.g., `/herosysgro/`):**
```apache
RewriteBase /herosysgro/
# Keep as is
```

**If your site is on subdomain:**
```apache
RewriteBase /
# Change all /herosysgro/ to / in rewrite rules
```

### 6. 🔍 Post-Deployment Verification

After uploading, check:

1. **Database Connection:**
   - Visit: `https://yourdomain.com/login`
   - Should connect to database without errors

2. **File Paths:**
   - Check navigation links work
   - Check CSS/JS load correctly
   - Check images display

3. **Upload Functionality:**
   - Test file uploads (if applicable)
   - Check upload directory permissions

4. **Module Access:**
   - Test login
   - Test module selection
   - Test department access

5. **Real Estate Module:**
   - Access: `https://yourdomain.com/modules/realestate/`
   - Should load without errors

### 7. 🐛 Common Issues & Fixes

**Issue: 404 errors on all pages**
- **Fix:** Check `.htaccess` RewriteBase matches your directory structure
- **Fix:** Ensure mod_rewrite is enabled on Hostinger

**Issue: Database connection error**
- **Fix:** Verify database credentials in `includes/config.php`
- **Fix:** Check database name, user, password are correct
- **Fix:** Ensure database user has proper permissions

**Issue: CSS/JS not loading**
- **Fix:** Check base path in HTML links
- **Fix:** Verify file paths are relative or use correct base URL

**Issue: Uploads not working**
- **Fix:** Check directory permissions (755 or 775)
- **Fix:** Verify upload directory exists
- **Fix:** Check PHP upload_max_filesize and post_max_size in php.ini

**Issue: Session errors**
- **Fix:** Check `SESSION_SECURE` setting in `includes/config.php`
- **Fix:** If using HTTPS, ensure `SESSION_SECURE` is true

### 8. 📋 Quick Reference: What to Change

| File | What to Change | Example |
|------|---------------|---------|
| `includes/config.php` | APP_ENV, DB_* constants | `APP_ENV = 'prod'` |
| `.htaccess` | RewriteBase | `/` or `/herosysgro/` |
| `operation.php` | Navigation links | `/herosysgro/` → `/` or keep |
| `account.php` | Navigation links | `/herosysgro/` → `/` or keep |
| `includes/module_access.php` | Base path in `get_department_route()` | Line ~80 |
| `api/mobile/config.php` | JWT_SECRET | Strong random string |

### 9. 🔄 Rollback Plan

If something goes wrong:
1. Keep backup of current live system
2. Restore from backup if needed
3. Fix issues locally
4. Re-upload fixed files

### 10. ✅ Final Checklist

Before going live:
- [ ] Database migrated successfully
- [ ] All configuration files updated
- [ ] Base paths updated for your domain structure
- [ ] File permissions set correctly
- [ ] .htaccess configured correctly
- [ ] Database credentials correct
- [ ] JWT_SECRET changed
- [ ] Test login works
- [ ] Test navigation works
- [ ] Test file uploads (if applicable)
- [ ] Test Real Estate module access
- [ ] Test all main modules (Cleaning, HR, Accounts)

---

## 🎯 Quick Start Commands

**1. Update config.php:**
```php
define('APP_ENV', 'prod');
define('DB_HOST', 'localhost');
define('DB_NAME', 'u385648797_Mainsys');
define('DB_USER', 'u385648797_Mainsys');
define('DB_PASS', 'YOUR_PASSWORD');
```

**2. Determine your base path:**
- Root domain: Change `/herosysgro/` → `/`
- Subdirectory: Keep `/herosysgro/`
- Subdomain: Change `/herosysgro/` → `/`

**3. Upload all files via FTP/File Manager**

**4. Set file permissions**

**5. Test the system!**

---

**Need Help?** Check the error logs in Hostinger's control panel or enable error display temporarily in `includes/config.php`:
```php
ini_set('display_errors', 1);
error_reporting(E_ALL);
```
(Remember to disable this after debugging!)
