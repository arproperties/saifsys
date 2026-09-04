# Real Estate Reminders Cron Setup

Run the migration first:

```bash
mysql -u YOUR_DB_USER -p YOUR_DB_NAME < migrations/re_reminders.sql
```

Recommended scheduler frequency is every minute:

Create the log folder once if it does not already exist:

```bash
mkdir -p /Applications/XAMPP/xamppfiles/htdocs/herosysgro/logs
```

```cron
* * * * * /Applications/XAMPP/bin/php /Applications/XAMPP/xamppfiles/htdocs/herosysgro/modules/realestate/reminder_scheduler_cron.php >> /Applications/XAMPP/xamppfiles/htdocs/herosysgro/logs/re_reminders_cron.log 2>&1
```

If your live server path is different, replace both paths with the live server paths. If you prefer every 5 minutes:

```cron
*/5 * * * * /usr/bin/php /path/to/herosysgro/modules/realestate/reminder_scheduler_cron.php >> /path/to/herosysgro/logs/re_reminders_cron.log 2>&1
```

URL fallback, if CLI cron is not available:

```text
https://your-domain.com/herosysgro/modules/realestate/reminder_scheduler_cron.php?run_cron=1
```

Email reminders require SMTP to be enabled in the system Email Settings. In-app reminders are written to `re_in_app_notifications`.
