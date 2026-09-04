# AMC Alert Cron Job Setup Guide - Hostinger

## 📋 Overview

The AMC alert cron job (`amc_alert_cron.php`) automatically generates alerts for:
- **Contract expiry** (30 days and 7 days before)
- **Certificate expiry** (30 days and 7 days before)
- **Visit due** (scheduled visits that are due or overdue)
- **Auto-updates** expired contracts and certificates status

---

## 🚀 Setup Methods

### **Method 1: Hostinger hPanel Cron Jobs (Recommended)**

This is the easiest and most reliable method on Hostinger.

#### Step 1: Access Cron Jobs in hPanel

1. Log in to your **Hostinger hPanel**
2. Navigate to **Advanced** → **Cron Jobs**
3. Click **Create Cron Job**

#### Step 2: Configure the Cron Job

**Cron Job Settings:**

- **Name:** `AMC Alert Generator`
- **Email:** (Your email to receive notifications)
- **Common Settings:** Select **Daily** (or choose custom)
- **Minute:** `0`
- **Hour:** `9` (9:00 AM - adjust as needed)
- **Day:** `*` (every day)
- **Month:** `*` (every month)
- **Weekday:** `*` (every weekday)

**Command to Run:**

```
php /home/u123456789/domains/yourdomain.com/public_html/modules/realestate/amc_alert_cron.php
```

**Important:** Replace the path with your actual Hostinger path. To find your path:
- Check your hosting account details in hPanel
- Or use: `<?php echo __DIR__; ?>` in a PHP file to get the absolute path
- Format is usually: `/home/u[account_id]/domains/[domain]/public_html/...`

#### Step 3: Alternative Command Format (If above doesn't work)

If the direct PHP path doesn't work, use:

```
/usr/bin/php /home/u123456789/domains/yourdomain.com/public_html/modules/realestate/amc_alert_cron.php
```

Or with full URL (if your server allows):

```
curl -s https://yourdomain.com/modules/realestate/amc_alert_cron.php?run_cron=1
```

#### Step 4: Save and Test

1. Click **Create** to save the cron job
2. Wait for the scheduled time or manually trigger it
3. Check the AMC alerts page to verify alerts were generated

---

### **Method 2: Manual URL Trigger (For Testing)**

You can test the cron job manually by visiting this URL in your browser:

```
https://yourdomain.com/modules/realestate/amc_alert_cron.php?run_cron=1
```

**Note:** The script checks for `?run_cron=1` parameter when accessed via web browser.

---

### **Method 3: Custom Cron Schedule**

If you want more frequent checks (e.g., every 6 hours):

**Cron Expression:**
```
0 */6 * * *
```

This runs every 6 hours.

**Common Schedules:**
- `0 9 * * *` - Daily at 9:00 AM
- `0 9,15 * * *` - Twice daily at 9:00 AM and 3:00 PM
- `0 */6 * * *` - Every 6 hours
- `0 0 * * *` - Daily at midnight

---

## 🔍 Finding Your Server Path

### Option 1: Check hPanel File Manager

1. Go to **Files** → **File Manager** in hPanel
2. Navigate to your domain's `public_html` folder
3. The full path is usually shown at the top

### Option 2: Create a Path Finder Script

Create a temporary file `get_path.php` in your root:

```php
<?php
echo "Current Directory: " . __DIR__ . "\n";
echo "Script Path: " . __FILE__ . "\n";
echo "Document Root: " . $_SERVER['DOCUMENT_ROOT'] . "\n";
?>
```

Upload it and visit it in your browser to see the paths.

### Option 3: Check via SSH (if available)

If you have SSH access:

```bash
pwd
# This shows your current directory
```

---

## ✅ Testing the Cron Job

### Test 1: Manual URL Test

1. Visit: `https://yourdomain.com/modules/realestate/amc_alert_cron.php?run_cron=1`
2. You should see: `AMC Alert Cron Job completed. Generated X alerts.`
3. Check `amc_alerts.php` page to see if alerts were created

### Test 2: Check Cron Job Logs

1. In hPanel → **Cron Jobs**
2. Look for execution logs or email notifications
3. Check for any error messages

### Test 3: Verify Alerts Generated

1. Go to **Real Estate** → **AMC Alerts**
2. Check if new alerts appear for:
   - Contracts expiring in 30 days
   - Contracts expiring in 7 days
   - Certificates expiring soon
   - Visits that are due

---

## 🔧 Troubleshooting

### Problem: Cron job not running

**Solutions:**
1. **Check path is correct** - Verify the full path to the PHP file
2. **Check PHP path** - Try `/usr/bin/php` instead of just `php`
3. **Check file permissions** - Ensure the file is readable (644 or 755)
4. **Check cron syntax** - Verify the cron expression is correct

### Problem: "Permission denied" error

**Solution:**
- Ensure the file has read permissions: `chmod 644 amc_alert_cron.php`
- Check if the directory path is correct

### Problem: No alerts generated

**Possible causes:**
1. **No contracts/certificates** - Check if you have AMC contracts in the system
2. **Dates not matching** - Alerts only generate for contracts/certificates expiring at configured dates
3. **Alert config disabled** - Check `re_amc_alert_config` table to ensure alerts are enabled

### Problem: Script runs but shows errors

**Solution:**
- Check PHP error logs in hPanel
- Ensure database connection is working
- Verify all AMC tables exist (run the migration if needed)

---

## 📧 Email Notifications

### Enable Email Notifications (Optional)

To receive email notifications when alerts are generated, modify `amc_alert_cron.php`:

Add this at the end of the file (after the success message):

```php
// Send email notification (optional)
if ($alertsGenerated > 0 && php_sapi_name() === 'cli') {
    $to = 'your-email@example.com';
    $subject = 'AMC Alerts Generated';
    $message = "AMC Alert Cron Job completed.\n\nGenerated {$alertsGenerated} new alerts.\n\nCheck your dashboard: https://yourdomain.com/modules/realestate/amc_alerts.php";
    mail($to, $subject, $message);
}
```

---

## 🎯 Recommended Schedule

**Best Practice:** Run the cron job **once daily** at **9:00 AM** (or your preferred time).

This ensures:
- Alerts are generated early in the day
- Staff can review alerts during business hours
- Not too frequent to overload the system

**Cron Expression:** `0 9 * * *`

---

## 📝 Quick Setup Checklist

- [ ] Locate your server path (via hPanel File Manager or path finder script)
- [ ] Access hPanel → Advanced → Cron Jobs
- [ ] Create new cron job with correct path
- [ ] Set schedule (recommended: Daily at 9:00 AM)
- [ ] Test manually via URL first
- [ ] Verify alerts are generated in `amc_alerts.php`
- [ ] Monitor for a few days to ensure it's working
- [ ] Set up email notifications (optional)

---

## 🔗 Related Files

- **Cron Script:** `modules/realestate/amc_alert_cron.php`
- **Alerts Page:** `modules/realestate/amc_alerts.php`
- **Alert Config:** `re_amc_alert_config` table in database

---

## 💡 Pro Tips

1. **Test First:** Always test manually via URL before setting up the cron job
2. **Monitor Initially:** Check the alerts page daily for the first week to ensure it's working
3. **Adjust Schedule:** If you need more frequent checks, adjust the cron schedule
4. **Backup:** The cron job doesn't modify data, only creates alerts, so it's safe
5. **Logs:** Keep an eye on cron job execution logs in hPanel

---

## 🆘 Need Help?

If you encounter issues:
1. Check Hostinger's cron job documentation
2. Verify file paths are correct
3. Test the script manually first
4. Check PHP error logs in hPanel
5. Ensure database connection is working

---

**Last Updated:** January 2025
