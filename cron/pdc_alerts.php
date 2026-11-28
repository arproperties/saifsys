<?php
/**
 * cron/pdc_alerts.php — Company's Post Dated Cheques: no longer does anything.
 *
 * The cheques, and the emails about them (due within 7 days, overdue, bounced), are kept and
 * sent by the Reem app now: it checks by itself every morning (server/companyPdc.js there).
 * This file is kept only so that a cron line still pointing at it does not fail; the line
 * can be removed.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
echo date('Y-m-d H:i:s'), " · Company's Post Dated Cheques alerts are sent by Reem now; nothing to do here.\n";
