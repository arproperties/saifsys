# 🚀 Quick Deployment Guide for Hostinger

## Step 1: Update Database Configuration

**File:** `includes/config.php`

```php
// Change these lines:
define('APP_ENV', 'prod'); // Change from 'dev'
define('DB_HOST', 'localhost'); // Usually 'localhost' on Hostinger
define('DB_NAME', 'u385648797_Mainsys'); // Your Hostinger database name
define('DB_USER', 'u385648797_Mainsys'); // Your Hostinger database user  
define('DB_PASS', 'YOUR_DATABASE_PASSWORD'); // Your Hostinger database password
```

## Step 2: Determine Your Base Path

**Question:** Where will your site be accessible?

- **Option A: Root domain** (e.g., `https://yourdomain.com/`)
  - Change all `/herosysgro/` → `/`
  
- **Option B: Subdirectory** (e.g., `https://yourdomain.com/herosysgro/`)
  - Keep `/herosysgro/` as is
  
- **Option C: Subdomain** (e.g., `https://app.yourdomain.com/`)
  - Change all `/herosysgro/` → `/`

## Step 3: Update Base Paths

### Method 1: Automatic (Recommended)

Run the helper script:
```bash
php UPDATE_PATHS_FOR_DEPLOYMENT.php
```

Edit the script first to set:
- `$oldBasePath = '/herosysgro';`
- `$newBasePath = '/';` (or your actual path)

### Method 2: Manual

Update these files manually:

**1. `.htaccess` (Line 3)**
```apache
RewriteBase /  # Change from /herosysgro/
```

**2. `includes/module_access.php` (Lines 197, 234)**
```php
$base = '/'; // Change from '/herosysgro'
```

**3. `operation.php` and `account.php`**
- Find all instances of `/herosysgro/`
- Replace with your base path (e.g., `/`)

## Step 4: Update Security Settings

**File:** `api/mobile/config.php` (Line 25)

```php
// Generate a strong secret:
// Run: openssl rand -base64 32
define('JWT_SECRET', 'YOUR_STRONG_RANDOM_SECRET_HERE');
```

## Step 5: Upload Files

**Upload via FTP/File Manager:**

✅ **Upload these:**
- All PHP files
- All directories (`includes/`, `operation/`, `hr/`, `modules/`, `api/`, etc.)
- `uploads/` directory (create if doesn't exist)
- `.htaccess` file
- All CSS, JS, images

❌ **Don't upload:**
- `.git/` directory
- `node_modules/` (if any)
- `migrations/` (optional - keep for reference)
- `*.md` files (optional)
- `UPDATE_PATHS_FOR_DEPLOYMENT.php` (delete after use)

## Step 6: Set File Permissions

**Via FTP/File Manager:**
- **Directories:** `755`
- **PHP Files:** `644`
- **Upload Directories:** `755` or `775`

**Critical directories:**
- `uploads/` → `755`
- `uploads/expenses/` → `755`
- Any other upload directories → `755`

## Step 7: Test

1. **Visit:** `https://yourdomain.com/login`
2. **Test login**
3. **Check navigation links work**
4. **Test file uploads** (if applicable)
5. **Test Real Estate module:** `https://yourdomain.com/modules/realestate/`

## Step 8: Common Issues

### ❌ 404 Errors
**Fix:** Check `.htaccess` RewriteBase matches your directory structure

### ❌ Database Connection Error
**Fix:** Verify credentials in `includes/config.php`

### ❌ CSS/JS Not Loading
**Fix:** Check base paths in HTML links

### ❌ Uploads Not Working
**Fix:** Check directory permissions (755)

## ✅ Final Checklist

- [ ] Database credentials updated
- [ ] Base paths updated
- [ ] JWT_SECRET changed
- [ ] Files uploaded
- [ ] Permissions set
- [ ] Login works
- [ ] Navigation works
- [ ] All modules accessible

---

**Need more details?** See `DEPLOYMENT_CHECKLIST.md` for comprehensive guide.
