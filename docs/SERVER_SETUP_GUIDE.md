# Server Setup Guide for Automated Scheduled Reports

This guide explains how to set up automated scheduled reports on your server.

## 1. Cron Job Setup

### Linux/Unix Servers

1. **Edit your crontab:**
   ```bash
   crontab -e
   ```

2. **Add the following line to check every 5 minutes for due reports:**
   ```bash
   # Check for scheduled reports every 5 minutes
   */5 * * * * /usr/bin/php /Applications/XAMPP/xamppfiles/htdocs/herosys/cron/process_scheduled_reports.php
   ```

3. **Alternative: Check every minute for more frequent processing:**
   ```bash
   # Check for scheduled reports every minute
   * * * * * /usr/bin/php /Applications/XAMPP/xamppfiles/htdocs/herosys/cron/process_scheduled_reports.php
   ```

### Windows Servers

1. **Create a batch file** (`process_reports.bat`):
   ```batch
   @echo off
   cd /d "C:\xampp\htdocs\herosys"
   php cron\process_scheduled_reports.php
   ```

2. **Set up Task Scheduler:**
   - Open Task Scheduler
   - Create Basic Task
   - Set trigger to "Daily" with repeat every 5 minutes
   - Set action to run the batch file

### XAMPP Local Development

1. **For Windows XAMPP:**
   ```batch
   # Add to Windows Task Scheduler
   * * * * * C:\xampp\php\php.exe C:\xampp\htdocs\herosys\cron\process_scheduled_reports.php
   ```

2. **For macOS XAMPP:**
   ```bash
   # Add to crontab
   */5 * * * * /Applications/XAMPP/xamppfiles/bin/php /Applications/XAMPP/xamppfiles/htdocs/herosys/cron/process_scheduled_reports.php
   ```

## 2. Email Configuration

### SMTP Settings

1. **Edit the email configuration file:**
   ```bash
   nano includes/email_config.php
   ```

2. **Update SMTP settings:**
   ```php
   define('EMAIL_SMTP_HOST', 'smtp.gmail.com'); // Your SMTP server
   define('EMAIL_SMTP_PORT', 587); // SMTP port
   define('EMAIL_SMTP_USERNAME', 'your-email@gmail.com'); // Your email
   define('EMAIL_SMTP_PASSWORD', 'your-app-password'); // Your app password
   define('EMAIL_SMTP_ENCRYPTION', 'tls'); // Encryption type
   define('EMAIL_FROM_EMAIL', 'your-email@gmail.com'); // From email
   define('EMAIL_FROM_NAME', 'BMSystem'); // From name
   ```

### Gmail Setup

1. **Enable 2-Factor Authentication** on your Gmail account
2. **Generate an App Password:**
   - Go to Google Account settings
   - Security → 2-Step Verification → App passwords
   - Generate a password for "Mail"
3. **Use the app password** in your configuration

### Other Email Providers

**Outlook/Hotmail:**
```php
define('EMAIL_SMTP_HOST', 'smtp-mail.outlook.com');
define('EMAIL_SMTP_PORT', 587);
define('EMAIL_SMTP_ENCRYPTION', 'tls');
```

**Yahoo:**
```php
define('EMAIL_SMTP_HOST', 'smtp.mail.yahoo.com');
define('EMAIL_SMTP_PORT', 587);
define('EMAIL_SMTP_ENCRYPTION', 'tls');
```

**Custom SMTP:**
```php
define('EMAIL_SMTP_HOST', 'your-smtp-server.com');
define('EMAIL_SMTP_PORT', 587);
define('EMAIL_SMTP_ENCRYPTION', 'tls');
```

## 3. File Permissions

### Linux/Unix

1. **Set proper permissions for the cron script:**
   ```bash
   chmod +x cron/process_scheduled_reports.php
   ```

2. **Set permissions for storage directories:**
   ```bash
   chmod 755 storage/
   chmod 755 storage/tmp/
   chmod 755 logs/
   ```

3. **Ensure web server can write to these directories:**
   ```bash
   chown -R www-data:www-data storage/
   chown -R www-data:www-data logs/
   ```

### Windows

1. **Ensure the web server has write permissions** to:
   - `storage/` directory
   - `logs/` directory
   - `storage/tmp/` directory

## 4. Database Setup

### Required Tables

Make sure these tables exist in your database:

1. **scheduled_reports** - Stores scheduled report configurations
2. **scheduled_report_runs** - Stores run history
3. **scheduled_report_recipients** - Stores recipient information
4. **email_log** - Stores email sending history

### Database Permissions

Ensure your database user has the following permissions:
- SELECT, INSERT, UPDATE, DELETE on all tables
- CREATE, ALTER on database (for migrations)

## 5. Testing the Setup

### Test Cron Job

1. **Run the cron script manually:**
   ```bash
   php cron/process_scheduled_reports.php
   ```

2. **Check the output** for any errors

3. **Verify in the database** that reports are being processed

### Test Email Sending

1. **Create a test scheduled report** in the web interface
2. **Set it to run immediately**
3. **Check if emails are sent** to the recipients
4. **Verify email logs** in the database

## 6. Monitoring and Logs

### Log Files

1. **Cron logs** are written to:
   - `logs/email_YYYY-MM-DD.log`
   - System error logs

2. **Email logs** are stored in the database table `email_log`

3. **Scheduled report logs** are stored in the database table `scheduled_report_runs`

### Monitoring

1. **Check cron job status:**
   ```bash
   crontab -l
   ```

2. **Monitor log files:**
   ```bash
   tail -f logs/email_$(date +%Y-%m-%d).log
   ```

3. **Check database for failed reports:**
   ```sql
   SELECT * FROM scheduled_report_runs WHERE status = 'failed';
   ```

## 7. Troubleshooting

### Common Issues

1. **Cron job not running:**
   - Check if cron service is running
   - Verify file paths are correct
   - Check file permissions

2. **Emails not sending:**
   - Verify SMTP settings
   - Check email credentials
   - Ensure firewall allows SMTP connections

3. **Reports not generating:**
   - Check database connections
   - Verify file permissions
   - Check for PHP errors

### Debug Mode

Enable debug mode in `includes/email_config.php`:
```php
define('EMAIL_DEBUG_MODE', true);
```

This will provide more detailed logging.

## 8. Security Considerations

1. **Protect configuration files** from web access
2. **Use strong passwords** for email accounts
3. **Limit cron job permissions** to necessary directories
4. **Regularly update** email credentials
5. **Monitor logs** for suspicious activity

## 9. Performance Optimization

1. **Adjust cron frequency** based on your needs
2. **Limit email batch sizes** to prevent server overload
3. **Clean up old log files** regularly
4. **Monitor server resources** during report generation

## 10. Backup and Recovery

1. **Backup configuration files** regularly
2. **Export scheduled report settings** from the database
3. **Keep email templates** backed up
4. **Test recovery procedures** periodically

---

## Quick Setup Checklist

- [ ] Cron job configured and running
- [ ] Email SMTP settings configured
- [ ] File permissions set correctly
- [ ] Database tables created
- [ ] Test report created and executed
- [ ] Email sending verified
- [ ] Logs being generated
- [ ] Monitoring in place
- [ ] Security measures implemented
- [ ] Backup procedures established

For additional support, check the system logs and database for error messages.
