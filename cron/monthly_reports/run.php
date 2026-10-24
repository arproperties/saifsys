<?php
// cron/monthly_reports/run.php
// On the 1st of each month: last month's renewal and new-tenant charge checks as 4 PDFs
// (all buildings + Ayla Residence), saved outside public_html and emailed.
// Read-only: it never writes to the database. Command line only.
//
// Hostinger cron (04:00 UTC = 08:00 Dubai):  0 4 1 * *  php <sys>/cron/monthly_reports/run.php
// By hand:  php run.php --month=2026-09 [--no-mail] [--force] [--out=/some/dir]
// A month is emailed only once (marker file .emailed in its folder); --force sends again.

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
date_default_timezone_set('Asia/Dubai');   // live PHP runs on UTC

const MONTHLY_REPORTS_TO = ['amran@ainalreempro.com'];

require_once __DIR__ . '/../../includes/config.php';   // DB constants only (db_connect.php would log usage = a DB write)
require_once __DIR__ . '/../../includes/mailer.php';

$opt   = getopt('', ['month:', 'no-mail', 'force', 'out:']);
$month = $opt['month'] ?? date('Y-m', strtotime('first day of last month'));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) { fwrite(STDERR, "bad --month\n"); exit(1); }
$label = date('M_Y', strtotime("$month-01"));

// Keep the PDFs out of the website: /home/<user>/monthly_reports/<month>/ on Hostinger
if (isset($opt['out'])) {
    $dir = rtrim($opt['out'], '/');
} elseif (preg_match('#^(/home/[^/]+)/#', __DIR__, $m)) {
    $dir = $m[1] . '/monthly_reports/' . $month;
} else {
    fwrite(STDERR, "not on the server - pass --out=DIR\n"); exit(1);
}
if (!is_dir($dir) && !mkdir($dir, 0750, true)) { fwrite(STDERR, "cannot create $dir\n"); exit(1); }
$log = fn($msg) => file_put_contents(dirname($dir) . '/monthly_reports.log', date('Y-m-d H:i:s') . " [$month] $msg\n", FILE_APPEND);

// The report script reads the same database as the site
$env = [
    'REPORT_DB_DSN'  => 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    'REPORT_DB_USER' => DB_USER,
    'REPORT_DB_PASS' => DB_PASS,
];
$reports = [
    ['ALL',            '',              "All_Buildings_Renewals_$label.pdf",   'All buildings · renewals'],
    ['ALL',            '--new-tenants', "All_Buildings_New_Tenants_$label.pdf", 'All buildings · new tenants'],
    ['AYLA RESIDENCE', '',              "Ayla_Renewals_$label.pdf",            'Ayla Residence · renewals'],
    ['AYLA RESIDENCE', '--new-tenants', "Ayla_New_Tenants_$label.pdf",         'Ayla Residence · new tenants'],
];
$done = []; $failed = [];
foreach ($reports as [$bld, $flag, $file, $title]) {
    $cmd = [PHP_BINARY, '-d', 'memory_limit=512M', __DIR__ . '/renewal_charges_report.php', $bld, $month, "$dir/$file"];
    if ($flag) $cmd[] = $flag;
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env + getenv());
    $outTxt = stream_get_contents($pipes[1]); $errTxt = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $code = proc_close($p);
    if ($code === 0 && is_file("$dir/$file")) {
        $done[] = ['path' => "$dir/$file", 'name' => $file, 'title' => $title, 'summary' => trim(preg_replace('/^OK\s*/', '', trim($outTxt)))];
        $log("ok $file: " . trim($outTxt));
    } else {
        $failed[] = $title;
        $log("FAILED $file (exit $code): " . trim($errTxt . ' ' . $outTxt));
    }
}

if (!isset($opt['no-mail']) && !isset($opt['force']) && is_file("$dir/.emailed")) { $log('already emailed - skipped'); echo "already emailed for $month\n"; exit(0); }
if (isset($opt['no-mail'])) { echo count($done) . " PDFs in $dir, " . count($failed) . " failed (no mail)\n"; exit($failed ? 1 : 0); }

// Email them using the site's existing SMTP settings (Settings → Email)
$pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$smtp = $pdo->query('SELECT * FROM app_email_settings WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
if (!$smtp || !(int)$smtp['is_enabled']) { $log('email not sent: SMTP settings disabled'); exit(1); }

$monthTxt = date('F Y', strtotime("$month-01"));
$rows = implode('', array_map(fn($d) => '<tr><td style="padding:4px 10px 4px 0"><b>' . htmlspecialchars($d['title']) . '</b></td><td style="padding:4px 0">' . htmlspecialchars($d['summary']) . '</td></tr>', $done));
$html = "<p>Monthly charges check for <b>$monthTxt</b> (leases starting 1–" . date('t', strtotime("$month-01")) . " $monthTxt), read from live saifsys.</p>"
      . "<table style='font-family:Arial,sans-serif;font-size:13px'>$rows</table>"
      . ($failed ? '<p style="color:#b42318"><b>Not created:</b> ' . htmlspecialchars(implode(', ', $failed)) . '</p>' : '')
      . '<p style="color:#666;font-size:12px">The PDFs are attached and also kept on the server outside the website. This report is read-only and changes nothing.</p>';
foreach (MONTHLY_REPORTS_TO as $to) {
    $r = send_smtp_mail_with_attachments($smtp, $to, "Monthly renewal & new tenant reports – $monthTxt", $html, $done);
    $log($r['ok'] ? "emailed $to" : "email to $to FAILED: " . $r['error']);
    if ($r['ok']) file_put_contents("$dir/.emailed", date('c') . " $to\n", FILE_APPEND);
}
echo count($done) . " PDFs, " . count($failed) . " failed\n";
exit($failed ? 1 : 0);
