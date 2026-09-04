# 📝 How to Use UPDATE_PATHS_FOR_DEPLOYMENT.php

## Step-by-Step Instructions

### Step 1: Determine Your Base Path

First, decide where your site will be accessible on Hostinger:

- **Root Domain** (e.g., `https://yourdomain.com/`)
  - New base path: `/`
  
- **Subdirectory** (e.g., `https://yourdomain.com/herosysgro/`)
  - New base path: `/herosysgro` (keep same)
  
- **Subdomain** (e.g., `https://app.yourdomain.com/`)
  - New base path: `/`

### Step 2: Edit the Script

Open `UPDATE_PATHS_FOR_DEPLOYMENT.php` in your editor and update **line 24**:

```php
// Change this line:
$newBasePath = '/'; // CHANGE THIS TO YOUR ACTUAL BASE PATH

// Examples:
// For root domain: $newBasePath = '/';
// For subdirectory: $newBasePath = '/herosysgro';
// For subdomain: $newBasePath = '/';
```

**Keep line 17 as is** (it's already correct):
```php
$oldBasePath = '/herosysgro'; // This is correct, don't change
```

### Step 3: Run the Script

Open your terminal/command prompt and navigate to your project directory:

```bash
cd /Applications/XAMPP/xamppfiles/htdocs/herosysgro
php UPDATE_PATHS_FOR_DEPLOYMENT.php
```

**Or if you're on Windows:**
```bash
cd C:\xampp\htdocs\herosysgro
php UPDATE_PATHS_FOR_DEPLOYMENT.php
```

### Step 4: Review the Output

The script will show you:
- ✅ Which files were updated
- ✅ How many replacements were made in each file
- ✅ Backup files created (`.backup` extension)

**Example output:**
```
========================================
Path Update Script for Deployment
========================================

Old Base Path: /herosysgro
New Base Path: /

✅ Backed up: .htaccess.backup
✅ Updated: .htaccess (1 replacements)
✅ Backed up: operation.php.backup
✅ Updated: operation.php (12 replacements)
✅ Backed up: account.php.backup
✅ Updated: account.php (10 replacements)
✅ Backed up: includes/module_access.php.backup
✅ Updated: includes/module_access.php (2 replacements)

========================================
Summary
========================================
Files changed: 4
Total replacements: 25

✅ Changes completed!

Next steps:
1. Review the changes in the files listed above
2. Test locally if possible
3. Upload to server
4. Delete .backup files after verification
5. Delete this script (UPDATE_PATHS_FOR_DEPLOYMENT.php)
```

### Step 5: Review the Changes

**Option A: Check the backup files**
```bash
# Compare original vs updated
diff .htaccess.backup .htaccess
```

**Option B: Open files in your editor**
- Open the updated files
- Search for your new base path (e.g., `/`)
- Verify the changes look correct

**Option C: Quick visual check**
- Open `.htaccess` and check line 3: `RewriteBase /` (should be `/` not `/herosysgro/`)
- Open `operation.php` and search for navigation links - they should use `/` instead of `/herosysgro/`

### Step 6: Test Locally (Optional)

If you want to test before uploading:
1. Keep the changes
2. Test your local site
3. If something breaks, restore from `.backup` files

**To restore a file:**
```bash
cp .htaccess.backup .htaccess
```

### Step 7: Upload to Server

After verifying the changes:
1. Upload all updated files to Hostinger
2. **Don't upload** the `.backup` files (they're just for reference)
3. **Don't upload** `UPDATE_PATHS_FOR_DEPLOYMENT.php` (delete it after use)

### Step 8: Clean Up

After successful deployment:
1. Delete all `.backup` files:
   ```bash
   rm *.backup
   rm includes/*.backup
   ```
2. Delete the script:
   ```bash
   rm UPDATE_PATHS_FOR_DEPLOYMENT.php
   ```

---

## 🎯 Quick Example

**Scenario:** Your site will be at `https://yourdomain.com/` (root domain)

1. **Edit the script:**
   ```php
   $newBasePath = '/'; // Line 24
   ```

2. **Run:**
   ```bash
   php UPDATE_PATHS_FOR_DEPLOYMENT.php
   ```

3. **Output shows:**
   - 4 files updated
   - 25 replacements made
   - Backup files created

4. **Review changes** in the files

5. **Upload** to Hostinger

6. **Delete** `.backup` files and the script

---

## ⚠️ Important Notes

- **The script creates backups** - your original files are safe!
- **Test the changes** before uploading if possible
- **Don't upload `.backup` files** to the server
- **Delete the script** after deployment for security

---

## 🔧 Troubleshooting

**Q: Script says "No changes were made"**
- Check that `$oldBasePath` matches what's in your files
- Check that files exist in the paths listed

**Q: Want to undo changes?**
- Restore from `.backup` files:
  ```bash
  cp .htaccess.backup .htaccess
  cp operation.php.backup operation.php
  # etc.
  ```

**Q: Need to update more files?**
- Edit the `$filesToUpdate` array in the script (line 27-33)
- Add more file paths as needed

---

**That's it! The script does all the hard work for you.** 🚀
