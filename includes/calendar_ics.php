<?php
// includes/calendar_ics.php
declare(strict_types=1);

require_once __DIR__.'/calendar_config.php';

/** Escape text for ICS (CRLF; escape commas, semicolons, backslashes) */
function ics_escape(string $s): string {
  $s = str_replace("\\", "\\\\", $s);
  $s = str_replace([",",";"], ["\\,", "\\;"], $s);
  // CRLF is required; fold long lines happens later
  $s = preg_replace("/\r?\n/", "\\n", $s);
  return $s;
}

/** Fold long lines to 75 octets per RFC5545 (basic, safe fold) */
function ics_fold(string $line): string {
  $out = '';
  while (strlen($line) > 75) {
    $out .= substr($line, 0, 75) . "\r\n ";
    $line = substr($line, 75);
  }
  return $out . "\r\n";
}

/**
 * Build ICS text from events.
 * Each event item: [
 *   'uid' => 'unique-string',
 *   'summary' => 'Title',
 *   'description' => 'Body',
 *   'location' => 'Address',
 *   'dtstart' => 'YYYYMMDDTHHMMSS',
 *   'dtend'   => 'YYYYMMDDTHHMMSS',
 *   'tzid'    => 'Asia/Dubai',
 *   'lastmod' => 'YYYYMMDDTHHMMSSZ'
 * ]
 */
function ics_build(string $calName, array $events): string {
  $lines = [];
  $lines[] = "BEGIN:VCALENDAR";
  $lines[] = "VERSION:2.0";
  $lines[] = "PRODID:" . ICS_PRODID;
  $lines[] = "CALSCALE:GREGORIAN";
  $lines[] = "METHOD:PUBLISH";
  $lines[] = "X-WR-CALNAME:" . ics_escape($calName);
  $lines[] = "X-WR-TIMEZONE:" . ICS_TZID;

  foreach ($events as $e) {
    $tz   = $e['tzid'] ?? ICS_TZID;
    $sum  = ics_escape($e['summary'] ?? 'Booking');
    $desc = ics_escape($e['description'] ?? '');
    $loc  = ics_escape($e['location'] ?? '');
    $uid  = $e['uid'] ?? (uniqid('uid_', true) . '@local');

    $lines[] = "BEGIN:VEVENT";
    $lines[] = "UID:$uid";
    if (!empty($e['lastmod'])) $lines[] = "LAST-MODIFIED:{$e['lastmod']}";
    $lines[] = "DTSTART;TZID={$tz}:{$e['dtstart']}";
    $lines[] = "DTEND;TZID={$tz}:{$e['dtend']}";
    if ($loc !== '') $lines[] = "LOCATION:$loc";
    $lines[] = "SUMMARY:$sum";
    if ($desc !== '') $lines[] = "DESCRIPTION:$desc";
    $lines[] = "END:VEVENT";
  }

  $lines[] = "END:VCALENDAR";

  // fold them nicely
  $buf = '';
  foreach ($lines as $ln) $buf .= ics_fold($ln);
  return $buf;
}

/** Helper: make yyyymmddThhmmss from Y-m-d and H:i local */
function ics_local_dt(string $dateISO, string $hhmm): string {
  [$H,$M] = array_map('intval', explode(':', $hhmm));
  $t = strtotime("$dateISO $H:$M:00");
  return date('Ymd\THis', $t);
}

/** Helper: UTC last-mod time */
function ics_lastmod(?string $ts = null): string {
  return gmdate('Ymd\THis\Z', $ts ? strtotime($ts) : time());
}
