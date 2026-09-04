# HR Document Expiry Reminders Cron Setup

The Organization > Reminders tab manages:

- SMTP sender settings
- reminder schedules, such as 60/30/10 days before expiry
- reminder recipients
- the email template

The automated sender is:

```text
cron/document_reminders.php
```

## Recommended Cron

Run once daily. The script de-duplicates by day, document, schedule, and recipient, so repeated runs on the same day will not resend the same reminder.

For local XAMPP on macOS:

```cron
15 8 * * * /Applications/XAMPP/bin/php /Applications/XAMPP/xamppfiles/htdocs/herosysgro/cron/document_reminders.php >> /Applications/XAMPP/xamppfiles/htdocs/herosysgro/logs/hr_document_reminders.log 2>&1
```

For live hosting, replace the PHP path and project path:

```cron
15 8 * * * /usr/bin/php /home/USERNAME/public_html/herosysgro/cron/document_reminders.php >> /home/USERNAME/public_html/herosysgro/logs/hr_document_reminders.log 2>&1
```

Create the log folder if it does not exist:

```bash
mkdir -p /Applications/XAMPP/xamppfiles/htdocs/herosysgro/logs
```

## Manual Test

From terminal:

```bash
/Applications/XAMPP/bin/php /Applications/XAMPP/xamppfiles/htdocs/herosysgro/cron/document_reminders.php
```

Expected output:

```text
Reminders: found=0 eligible=0 sent=0 skipped=0 failed=0
```

Counts will be higher when employee documents match active reminder schedules.

## Notes

- Only active/current workforce employees are included: `active`, `on_leave`, `notice_period`.
- If sending is disabled in the Reminders tab, matching reminders are logged as `skipped`.
- Failed email attempts are logged in `app_reminder_logs` with the mailer error.
- The manual `Run now` button in Organization > Reminders uses the same runner.
