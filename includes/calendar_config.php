<?php
// includes/calendar_config.php
declare(strict_types=1);

/** Timezone label to embed in ICS. Keep in sync with your local time. */
const ICS_TZID = 'Asia/Dubai';

/** How many days backward/forward to include in the feed */
const ICS_PAST_DAYS  = 14;
const ICS_FUTURE_DAYS = 90;

/** Product name shown in ICS header */
const ICS_PRODID = '-//YourCompany//Cleaning Scheduler//EN';

/** Base URL (no trailing slash). Change to your real domain later.
function calendar_base_url(): string {
  // If you already have a site-wide base URL helper, reuse it.
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host   = $_SERVER['HTTP_HOST'] ?? 'localhost/bmsystem-web';
  return $scheme . '://' . $host;
}
 */

function calendar_base_url(): string
{
  // LOCALHOST: point to the project root (no trailing slash)
  return 'http://localhost/bmsystem-web';
}
