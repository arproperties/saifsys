<?php
/**
 * Read-only CLI report: historical RE invoice income misposts.
 * Prefer the web UI: modules/realestate/accounting/income_mispost_report.php
 *
 * Usage:
 *   php tools/report_re_misposted_income_invoices.php [company_id]
 *   php tools/report_re_misposted_income_invoices.php 2 --csv > misposts.csv
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../modules/realestate/includes/re_income_account_roles.php';

$companyId = isset($argv[1]) && ctype_digit((string)$argv[1]) ? (int)$argv[1] : 2;
$asCsv = in_array('--csv', $argv, true);

$misposts = re_income_mispost_candidates($conn, $companyId);

if ($asCsv) {
    $out = fopen('php://output', 'w');
    if ($misposts) {
        fputcsv($out, array_keys($misposts[0]));
        foreach ($misposts as $m) {
            fputcsv($out, $m);
        }
    } else {
        fputcsv($out, ['message']);
        fputcsv($out, ['No misposted income lines found for company ' . $companyId]);
    }
    fclose($out);
    exit(0);
}

echo "RE misposted income invoice report (read-only)\n";
echo "Company ID: {$companyId}\n";
echo "Candidates: " . count($misposts) . "\n\n";

$byType = [];
foreach ($misposts as $m) {
    $key = $m['obligation_type'] . '|' . $m['posted_income_code'] . '→' . $m['expected_account_code'];
    $byType[$key] = ($byType[$key] ?? 0) + 1;
}
echo "Summary by obligation_type / posted→expected:\n";
foreach ($byType as $k => $c) {
    echo "  {$k}: {$c}\n";
}
echo "\nFirst 30 rows:\n";
foreach (array_slice($misposts, 0, 30) as $m) {
    echo sprintf(
        "  %s | %s | obl#%s %s/%s | posted %s %.2f | expected %s (%s)\n",
        $m['invoice_number'],
        $m['journal_number'],
        $m['obligation_id'],
        $m['obligation_type'],
        $m['accounting_class'],
        $m['posted_income_code'],
        (float)$m['posted_income_credit'],
        $m['expected_account_code'],
        $m['expected_role']
    );
}
echo "\nNo journals were modified.\n";
echo "Web UI: modules/realestate/accounting/income_mispost_report.php\n";
