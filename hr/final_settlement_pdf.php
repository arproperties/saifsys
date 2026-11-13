<?php
/**
 * Final settlement sheet as PDF (same layout as the Glaiza sheet of Nov 2026).
 * Heroes Zone prints on its letterhead PDF (footer rewritten without the
 * second phone number); other companies get logo + name from company_settings.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/includes/hr_final_settlement.php';
require_role(['Owner', 'Admin', 'HR'], $conn);

$id = (int)($_GET['id'] ?? 0);
$s = hr_fs_table_ready($conn) && $id > 0 ? hr_fs_load($conn, $id) : null;
if (!$s) {
    http_response_code(404);
    exit('Settlement sheet not found.');
}

$e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$m = static fn($x) => number_format((float)$x, 2);
$hd = $s['header'];
$name = trim((string)($hd['full_name'] ?? ''));
$companyId = (int)$s['company_id'];
$co = hr_fs_company($conn, $companyId);
$letterhead = hr_fs_letterhead($companyId);
$net = (float)$s['net_amount'];

$css = <<<CSS
body { font-family: dejavusans; font-size: 8.8pt; color: #1f2933; }
h1 { font-size: 16pt; color: #0b3d5c; margin: 4px 0 8px 0; text-align: center; }
table { border-collapse: collapse; width: 100%; }
.info td { padding: 4px 6px; border: 1px solid #d5dde5; }
.info td.l { background: #f6f8fa; font-weight: bold; width: 22%; }
.t th { background: #0b3d5c; color: #fff; padding: 5px 6px; }
.t td { padding: 5px 6px; border-bottom: 1px solid #e3e8ee; }
.n { white-space: nowrap; }
.s { font-size: 7.8pt; color: #5f6b7a; }
.tot td { font-weight: bold; background: #eef3f7; }
.net td { font-weight: bold; font-size: 11.5pt; background: #0b3d5c; color: #fff; padding: 7px 6px; }
h2 { font-size: 10.5pt; color: #0b3d5c; margin: 10px 0 4px 0; }
.sg td { border-top: 1px solid #1f2933; padding-top: 4px; font-size: 8.5pt; width: 30%; }
CSS;

// ---- heading for companies without a letterhead
$top = '';
if (!$letterhead) {
    $sub = implode(' · ', array_filter([$co['address'], $co['phone'], $co['email']]));
    $top = '<table style="border-bottom:2px solid #0b3d5c; margin-bottom:6px"><tr>'
        . '<td style="width:22%; padding-bottom:4px">' . ($co['logo'] ? '<img src="' . $e($co['logo']) . '" style="height:22mm">' : '') . '</td>'
        . '<td style="text-align:center; vertical-align:middle"><div style="font-size:14pt; font-weight:bold; color:#0b3d5c">' . $e($co['name']) . '</div>'
        . '<div style="font-size:8pt; color:#5f6b7a">' . $e($sub) . ($co['trn'] !== '' ? '<br>TRN ' . $e($co['trn']) : '') . '</div></td>'
        . '<td style="width:22%"></td></tr></table>';
}

// ---- employee details: two per row
$cells = [];
foreach (hr_fs_header_fields() as $k => $label) {
    $cells[] = "<td class='l'>" . $e($label) . "</td><td>" . $e($hd[$k] ?? '') . "</td>";
}
$info = "<table class='info'>";
foreach (array_chunk($cells, 2) as $pair) {
    $info .= '<tr>' . implode('', $pair) . '</tr>';
}
$info .= '</table>';

$rowsTable = static function (array $rows, string $totLabel, bool $deduct) use ($e, $m): string {
    $h = "<table class='t'><tr><th style='width:34%; text-align:left'>Item</th><th style='text-align:left'>Details</th><th style='width:15%; text-align:right'>AED</th></tr>";
    $tot = 0.0;
    foreach ($rows as $r) {
        $a = (float)$r['amount'];
        $tot += $a;
        $shown = $deduct ? '-' . $m($a) : $m($a);
        $h .= "<tr><td>" . $e($r['item']) . "</td><td class='s'>" . $e($r['details']) . "</td><td class='n' align='right'>" . $shown . "</td></tr>";
    }
    $h .= "<tr class='tot'><td colspan='2'>" . $e($totLabel) . "</td><td class='n' align='right'>" . ($deduct ? '-' : '') . $m($tot) . "</td></tr></table>";
    return $h;
};

$html = $top . "<h1>FINAL SETTLEMENT</h1>" . $info;
$html .= "<h2>Amounts due to the employee</h2>" . $rowsTable($s['due'], 'Total due', false);
if ($s['deductions']) {
    $html .= "<h2>Deductions</h2>" . $rowsTable($s['deductions'], 'Total deductions', true);
}
$html .= "<br><table class='t'><tr class='net'><td>NET AMOUNT PAYABLE TO EMPLOYEE</td><td align='right'>AED " . $m($net) . "</td></tr></table>";
if (trim((string)$s['note']) !== '') {
    $html .= "<div class='s'>" . $e($s['note']) . "</div>";
}

// ---- payment
$method = (string)$s['payment_method'];
$box = static fn(string $k) => $method === $k ? '[X]' : '[&nbsp;&nbsp;]';
$cheque = trim((string)$s['cheque_no']) !== '' ? $e($s['cheque_no']) : '________';
$methodLine = $method === ''
    ? "[&nbsp;&nbsp;] WPS / bank transfer &nbsp; [&nbsp;&nbsp;] Cheque no. ________ &nbsp; [&nbsp;&nbsp;] Cash"
    : $box('wps') . " WPS / bank transfer &nbsp; " . $box('cheque') . " Cheque no. " . ($method === 'cheque' ? $cheque : '________') . " &nbsp; " . $box('cash') . " Cash";
$paid = $s['paid_date'] ? $e(date('j M Y', strtotime($s['paid_date']))) : '____ / ____ / 20____';
$html .= "<h2>Payment</h2><table class='info'><tr><td class='l'>Method</td><td>$methodLine</td></tr><tr><td class='l'>Date paid</td><td>$paid</td></tr></table>";

// ---- declaration + signatures
$coName = $co['name'] !== '' ? $co['name'] : 'the company';
$html .= "<h2>Declaration by the employee</h2><div>I, <b>" . $e($name) . "</b>, confirm that I have received <b>AED " . $m($net)
    . "</b> as my full and final settlement from " . $e($coName) . ", covering my salary, leave and all other dues up to my last working day. "
    . "I have read and understood the amounts and deductions above. I agree that my employment has ended and that I have no further claims against the company.</div>";
$html .= "<table style='margin-top:34px'><tr>"
    . "<td class='sg' style='border-top:1px solid #1f2933; width:30%'>Employee signature<br>" . $e($name) . "<br><br>Date:</td><td style='width:5%'></td>"
    . "<td class='sg' style='border-top:1px solid #1f2933; width:30%'>For the company<br>Name / signature / stamp<br><br>Date:</td><td style='width:5%'></td>"
    . "<td class='sg' style='border-top:1px solid #1f2933; width:30%'>Witness<br>Name / signature<br><br>Date:</td></tr></table>";

// ---- PDF
$tmp = sys_get_temp_dir() . '/mpdf_hr_fs';
if (!is_dir($tmp)) {
    @mkdir($tmp, 0775, true);
}
$fontDefaults = (new \Mpdf\Config\ConfigVariables())->getDefaults();
$fontData = (new \Mpdf\Config\FontVariables())->getDefaults();
$mpdf = new \Mpdf\Mpdf([
    'format' => 'A4',
    'margin_top' => $letterhead ? 30 : 12,
    'margin_bottom' => $letterhead ? 30 : 14,
    'margin_left' => 14, 'margin_right' => 14,
    'tempDir' => $tmp,
    'fontDir' => array_merge($fontDefaults['fontDir'], [__DIR__ . '/includes/final_settlement']),
    'fontdata' => $fontData['fontdata'] + ['calibrib' => ['R' => 'calibrib.ttf']],
]);
$mpdf->SetTitle('Final Settlement - ' . $name);
if ($letterhead) {
    $mpdf->SetDocTemplate($letterhead, true);
}
$mpdf->WriteHTML("<style>$css</style>" . $html);
if ($letterhead && $companyId === 1) {
    // Heroes Zone letterhead footer: hide the second phone number (user, 12 Nov 2026)
    $pages = count($mpdf->pages);
    for ($p = 1; $p <= $pages; $p++) {
        $mpdf->page = $p;
        $mpdf->SetFillColor(255, 255, 255);
        $mpdf->Rect(30, 263, 150, 9, 'F');
        $mpdf->WriteFixedPosHTML('<div style="font-family:calibrib; font-size:11pt; text-align:center; color:#000">Www.heroeszone.ae | Info@heroeszone.ae | 052-500-4123</div>', 30, 263.4, 150, 8, 'visible');
    }
    $mpdf->page = $pages;
}
$file = 'Final_Settlement_' . preg_replace('/[^A-Za-z0-9]+/', '_', $name ?: ('sheet_' . $id)) . '.pdf';
$mpdf->Output($file, \Mpdf\Output\Destination::INLINE);
