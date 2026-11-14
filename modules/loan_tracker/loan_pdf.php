<?php
/**
 * Loan Tracker — PDFs.
 *   loan_pdf.php?id=12     loan statement: borrower, documents, loan details, schedule, repayments, issuer
 *   loan_pdf.php?rep=7     receipt for one repayment on a Loan Tracker loan
 * Add &view=1 to open in the browser instead of downloading.
 *
 * Attachments: the receipt gets its proof of payment; the statement gets the documents, the
 * proof of payout and every repayment proof. Photos are shown on the page under "Attachments";
 * a PDF's pages are added after it, each labelled. A PDF that cannot be read (some scanners
 * save one the free reader cannot open) gets a page saying so instead.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/url_helper.php';
require_once __DIR__ . '/includes/lt_helper.php';
require_once __DIR__ . '/../../vendor/autoload.php';

$companyId = lt_boot($conn);
$q = $conn->prepare("SELECT name FROM companies WHERE id = ?");
$q->execute([$companyId]);
$companyName = (string)($q->fetchColumn() ?: '');

/** Load a loan of this company with what the PDFs need. */
function lt_pdf_loan(PDO $conn, int $id, int $companyId): ?array
{
    $q = $conn->prepare("SELECT l.*, e.employee_code, c.name AS emp_company, COALESCE(NULLIF(u.fullname,''), u.username) AS issuer_name
                           FROM lt_loans l LEFT JOIN employees e ON e.id = l.employee_id LEFT JOIN companies c ON c.id = e.company_id
                           LEFT JOIN user u ON u.id = l.issued_by WHERE l.id = ? AND l.company_id = ?");
    $q->execute([$id, $companyId]);
    return $q->fetch(PDO::FETCH_ASSOC) ?: null;
}

$css = '
  @page { margin: 28px 34px; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 10.5px; color: #1f2937; }
  .top { border-bottom: 3px solid #1e3a8a; padding-bottom: 10px; margin-bottom: 14px; }
  .co { font-size: 15px; font-weight: bold; color: #1e3a8a; }
  .doc { font-size: 12px; color: #6b7280; }
  h2 { font-size: 11px; text-transform: uppercase; letter-spacing: .06em; color: #1e3a8a; margin: 16px 0 6px; }
  table { width: 100%; border-collapse: collapse; }
  .kv td { padding: 4px 6px; vertical-align: top; }
  .kv td.k { color: #6b7280; width: 22%; }
  .grid th { background: #e0e7ff; color: #172554; text-align: left; padding: 5px 6px; font-size: 9.5px; text-transform: uppercase; }
  .grid td { padding: 5px 6px; border-bottom: 1px solid #e5e7eb; }
  .r { text-align: right; }
  .box { background: #eef2ff; border: 1px solid #c7d2fe; padding: 8px 10px; }
  .big { font-size: 14px; font-weight: bold; color: #172554; }
  .ok { color: #166534; font-weight: bold; }
  .sig { margin-top: 46px; }
  .sig td { padding-top: 4px; }
  .line { border-top: 1px solid #9ca3af; padding-top: 4px; color: #6b7280; width: 45%; }
  .muted { color: #6b7280; }
';

$html = '';
$fileName = 'loan';
$attachments = []; // each: ['title' => …, 'path' => absolute file path]

if (isset($_GET['rep'])) {
    $q = $conn->prepare("SELECT r.*, COALESCE(NULLIF(u.fullname,''), u.username) AS by_name FROM lt_loan_repayments r
                           LEFT JOIN user u ON u.id = r.created_by WHERE r.id = ?");
    $q->execute([(int)$_GET['rep']]);
    $rep = $q->fetch(PDO::FETCH_ASSOC);
    $loan = $rep ? lt_pdf_loan($conn, (int)$rep['loan_id'], $companyId) : null;
    if (!$rep || !$loan) { http_response_code(404); exit('Not found'); }
    $q = $conn->prepare("SELECT COALESCE(SUM(amount),0) FROM lt_loan_repayments WHERE loan_id = ? AND (paid_on < ? OR (paid_on = ? AND id <= ?))");
    $q->execute([(int)$loan['id'], $rep['paid_on'], $rep['paid_on'], (int)$rep['id']]);
    $after = max(0.0, (float)$loan['total_payable'] - (float)$q->fetchColumn());
    $no = 'RC-' . str_pad((string)$rep['id'], 5, '0', STR_PAD_LEFT);
    if (!empty($rep['file_path'])) {
        $attachments[] = ['title' => 'Proof of payment · ' . lt_date($rep['paid_on']), 'path' => lt_doc_abs_path(['source' => 'upload', 'file_path' => $rep['file_path']])];
    }
    $fileName = 'receipt-' . strtolower($no);
    $html = '<div class="top"><div class="co">' . h($companyName) . '</div><div class="doc">Repayment receipt · ' . h($no) . '</div></div>'
        . '<table class="kv">'
        . '<tr><td class="k">Received from</td><td><b>' . h($loan['borrower_name']) . '</b>' . ($loan['employee_code'] ? ' (' . h($loan['employee_code']) . ')' : '') . '</td></tr>'
        . '<tr><td class="k">Loan</td><td>' . h(lt_loan_no((int)$loan['id'])) . ' · issued ' . h(lt_date($loan['issue_date'])) . '</td></tr>'
        . '<tr><td class="k">Date paid</td><td>' . h(lt_date($rep['paid_on'])) . '</td></tr>'
        . '<tr><td class="k">Paid by</td><td>' . h(['salary' => 'Salary deduction', 'bank' => 'Bank transfer', 'cheque' => 'Cheque'][$rep['paid_by']] ?? 'Cash') . ($rep['reference'] ? ' · Ref ' . h($rep['reference']) : '') . '</td></tr>'
        . ($rep['notes'] ? '<tr><td class="k">Notes</td><td>' . h($rep['notes']) . '</td></tr>' : '')
        . '</table>'
        . '<table style="margin-top:14px"><tr>'
        . '<td class="box" style="width:50%"><div class="muted">Amount received</div><div class="big">AED ' . lt_money($rep['amount']) . '</div></td>'
        . '<td style="width:2%"></td>'
        . '<td class="box" style="width:48%"><div class="muted">Still to pay after this</div><div class="big">AED ' . lt_money($after) . '</div></td>'
        . '</tr></table>'
        . '<table class="sig"><tr><td class="line">Received by: ' . h($rep['by_name'] ?: '') . '</td><td style="width:10%"></td><td class="line">Paid by (signature)</td></tr></table>'
        . '<p class="muted" style="margin-top:18px">Printed ' . h(date('d M Y H:i')) . '</p>';
} else {
    $loan = lt_pdf_loan($conn, (int)($_GET['id'] ?? 0), $companyId);
    if (!$loan) { http_response_code(404); exit('Not found'); }
    $id = (int)$loan['id'];
    $fileName = strtolower(lt_loan_no($id));
    $t = lt_types()[$loan['loan_to']];
    $q = $conn->prepare("SELECT * FROM lt_loan_documents WHERE loan_id = ? ORDER BY FIELD(doc_kind,'emirates_id','passport','visa','trade_licence','other'), id");
    $q->execute([$id]);
    $docs = $q->fetchAll(PDO::FETCH_ASSOC);
    $q = $conn->prepare("SELECT * FROM lt_loan_installments WHERE loan_id = ? ORDER BY seq");
    $q->execute([$id]);
    $inst = $q->fetchAll(PDO::FETCH_ASSOC);
    if (!$inst && !empty($loan['details_saved_at'])) {
        foreach (lt_schedule((float)$loan['total_payable'], (int)$loan['installments'], $loan['first_due_date']) as $r) {
            $inst[] = ['seq' => $r['seq'], 'due_date' => $r['due'], 'amount' => $r['amount'], 'paid_amount' => 0];
        }
    }
    $q = $conn->prepare("SELECT * FROM lt_loan_repayments WHERE loan_id = ? ORDER BY paid_on, id");
    $q->execute([$id]);
    $reps = $q->fetchAll(PDO::FETCH_ASSOC);
    $paid = array_sum(array_map(fn($r) => (float)$r['amount'], $reps));
    $modes = lt_repayment_modes($loan['loan_to']);
    foreach ($docs as $d) {
        $attachments[] = ['title' => $d['title'] . ($d['doc_number'] ? ' · No. ' . $d['doc_number'] : ''), 'path' => lt_doc_abs_path($d)];
    }
    if (!empty($loan['proof_path'])) {
        $attachments[] = ['title' => 'Proof of payout · ' . lt_date($loan['issue_date']), 'path' => lt_doc_abs_path(['source' => 'upload', 'file_path' => $loan['proof_path']])];
    }
    foreach ($reps as $r) {
        if (!empty($r['file_path'])) {
            $attachments[] = ['title' => 'Repayment proof · ' . lt_date($r['paid_on']) . ' · AED ' . lt_money($r['amount']),
                'path' => lt_doc_abs_path(['source' => 'upload', 'file_path' => $r['file_path']])];
        }
    }

    $html = '<div class="top"><table><tr><td><div class="co">' . h($companyName) . '</div><div class="doc">Loan statement · ' . h(lt_loan_no($id)) . '</div></td>'
        . '<td class="r"><b>' . h(lt_statuses()[$loan['status']]['label']) . '</b><br><span class="muted">' . h(date('d M Y')) . '</span></td></tr></table></div>'
        . '<h2>' . ($loan['loan_to'] === 'company' ? 'Company' : 'Borrower') . '</h2><table class="kv">'
        . '<tr><td class="k">Name</td><td><b>' . h($loan['borrower_name']) . '</b>' . ($loan['employee_code'] ? ' (' . h($loan['employee_code']) . ')' : '') . '</td>'
        . '<td class="k">Loan to</td><td>' . h($t['label']) . '</td></tr>'
        . '<tr><td class="k">Phone</td><td>' . h($loan['phone'] ?: '—') . '</td><td class="k">Email</td><td>' . h($loan['email'] ?: '—') . '</td></tr>'
        . ($loan['emp_company'] ? '<tr><td class="k">Company</td><td colspan="3">' . h($loan['emp_company']) . '</td></tr>' : '')
        . ($loan['contact_name'] ? '<tr><td class="k">Contact person</td><td colspan="3">' . h($loan['contact_name']) . '</td></tr>' : '')
        . '</table>';

    $html .= '<h2>Documents</h2><table class="grid"><tr><th>Document</th><th>Number</th><th>Expiry</th><th>Status</th></tr>';
    foreach ($docs as $d) {
        $st = !empty($d['skipped']) ? 'Skipped: ' . $d['skip_reason'] : (trim((string)$d['file_path']) === '' ? 'No file' : ($d['source'] === 'hr' ? 'From HR' : 'Attached'));
        $html .= '<tr><td>' . h($d['title']) . '</td><td>' . h($d['doc_number'] ?: '—') . '</td><td>' . h(lt_date($d['expires_at'])) . '</td><td>' . h($st) . '</td></tr>';
    }
    if (!$docs) { $html .= '<tr><td colspan="4" class="muted">No documents.</td></tr>'; }
    $html .= '</table>';

    if (!empty($loan['details_saved_at'])) {
        $html .= '<h2>Loan details</h2>'
            . '<table><tr>'
            . '<td class="box" style="width:24%"><div class="muted">Amount</div><div class="big">AED ' . lt_money($loan['amount']) . '</div></td><td style="width:1%"></td>'
            . '<td class="box" style="width:24%"><div class="muted">Total to pay back</div><div class="big">AED ' . lt_money($loan['total_payable']) . '</div></td><td style="width:1%"></td>'
            . '<td class="box" style="width:24%"><div class="muted">Paid back</div><div class="big">AED ' . lt_money($paid) . '</div></td><td style="width:1%"></td>'
            . '<td class="box" style="width:25%"><div class="muted">Still to pay</div><div class="big">AED ' . lt_money(max(0, (float)$loan['total_payable'] - $paid)) . '</div></td>'
            . '</tr></table>'
            . '<table class="kv" style="margin-top:6px">'
            . '<tr><td class="k">Date of issue</td><td>' . h(lt_date($loan['issue_date'])) . '</td><td class="k">Paid in</td><td>' . ($loan['paid_out_by'] === 'bank' ? 'Bank transfer' : 'Cash') . '</td></tr>'
            . '<tr><td class="k">Instalments</td><td>' . (int)$loan['installments'] . '</td><td class="k">Interest</td><td>' . ((float)$loan['interest_rate'] > 0 ? h(rtrim(rtrim((string)$loan['interest_rate'], '0'), '.')) . '% flat' : 'None') . '</td></tr>'
            . '<tr><td class="k">Mode of repayment</td><td>' . h($modes[$loan['repayment_mode']] ?? '—') . '</td><td class="k">First due date</td><td>' . h(lt_date($loan['first_due_date'])) . '</td></tr>'
            . '</table>';

        $html .= '<h2>Repayment schedule</h2><table class="grid"><tr><th>#</th><th>Due date</th><th class="r">Amount (AED)</th><th class="r">Paid (AED)</th><th>Status</th></tr>';
        foreach ($inst as $r) {
            $done = (float)$r['paid_amount'] >= (float)$r['amount'] - 0.005;
            $html .= '<tr><td>' . (int)$r['seq'] . '</td><td>' . h(lt_date($r['due_date'])) . '</td><td class="r">' . lt_money($r['amount']) . '</td><td class="r">' . lt_money($r['paid_amount']) . '</td><td>'
                . ($done ? '<span class="ok">Paid</span>' : ((float)$r['paid_amount'] > 0 ? 'Part paid' : ($r['due_date'] < date('Y-m-d') ? 'Overdue' : 'Due'))) . '</td></tr>';
        }
        $html .= '</table>';
    }

    if ($reps) {
        $html .= '<h2>Repayments</h2><table class="grid"><tr><th>Date</th><th>How</th><th>Reference</th><th class="r">Amount (AED)</th></tr>';
        foreach ($reps as $r) {
            $html .= '<tr><td>' . h(lt_date($r['paid_on'])) . '</td><td>' . h($r['paid_by'] === 'salary' ? 'Deducted from salary' : lt_paid_by_label($r['paid_by'])) . '</td><td>' . h($r['reference'] ?: '—') . '</td><td class="r">' . lt_money($r['amount']) . '</td></tr>';
        }
        $html .= '<tr><td colspan="3"><b>Total paid back</b></td><td class="r"><b>' . lt_money($paid) . '</b></td></tr></table>';
    }

    if ($loan['status'] === 'closed' && $loan['close_note']) {
        $html .= '<p style="margin-top:10px"><b>Closed ' . h(lt_date($loan['closed_at'])) . ':</b> ' . h($loan['close_note']) . '</p>';
    }

    $html .= '<table class="sig"><tr>'
        . '<td class="line">Issued by: ' . h($loan['issuer_name'] ?: '') . ($loan['submitted_at'] ? ' · ' . h(lt_date($loan['submitted_at'])) : '') . '</td>'
        . '<td style="width:10%"></td><td class="line">Borrower (signature)</td></tr></table>'
        . ($loan['submitted_at'] ? '<p class="muted" style="margin-top:12px">The issuer confirmed that these details are correct when the loan was submitted.</p>' : '');
}

/**
 * A photo ready for the page: at most 1600 px, as JPEG (a phone photo would make the PDF many MB).
 * Returns [data URI, width mm, height mm] fitted into 180 x 225 mm, or null when it is not a picture.
 */
function lt_pdf_photo(string $path): ?array
{
    $img = @imagecreatefromstring((string)file_get_contents($path));
    if (!$img) {
        return null;
    }
    $w = imagesx($img);
    $h = imagesy($img);
    $k = min(1.0, 1600 / max($w, $h));
    if ($k < 1.0) {
        $small = imagecreatetruecolor((int)round($w * $k), (int)round($h * $k));
        imagefill($small, 0, 0, imagecolorallocate($small, 255, 255, 255));
        imagecopyresampled($small, $img, 0, 0, 0, 0, imagesx($small), imagesy($small), $w, $h);
        imagedestroy($img);
        $img = $small;
        $w = imagesx($img);
        $h = imagesy($img);
    }
    ob_start();
    imagejpeg($img, null, 82);
    $jpg = (string)ob_get_clean();
    imagedestroy($img);
    $scale = min(180 / max(1, $w), 225 / max(1, $h));
    return ['data:image/jpeg;base64,' . base64_encode($jpg), round($w * $scale, 1), round($h * $scale, 1)];
}

// Only files that are really there. Photos are shown on the page; PDFs are added after it.
$attachments = array_values(array_filter($attachments, static fn(array $a): bool => $a['path'] !== '' && is_file($a['path'])));
$finfo = new finfo(FILEINFO_MIME_TYPE);
$pdfAttachments = [];
if ($attachments) {
    $html .= '<h2>Attachments</h2>';
    foreach ($attachments as $i => $a) {
        $isPdf = (string)$finfo->file($a['path']) === 'application/pdf';
        $photo = $isPdf ? null : lt_pdf_photo($a['path']);
        if ($isPdf) {
            $pdfAttachments[] = $a + ['n' => $i + 1];
        }
        $html .= '<div style="page-break-inside:avoid;margin:8px 0 14px">'
            . '<div style="font-weight:bold;color:#1e3a8a;margin-bottom:4px">' . ($i + 1) . '. ' . h($a['title'])
            . ($isPdf ? ' <span class="muted" style="font-weight:normal">(PDF — added on the pages after this)</span>' : '') . '</div>'
            . ($photo ? '<img src="' . $photo[0] . '" style="width:' . $photo[1] . 'mm;height:' . $photo[2] . 'mm;border:1px solid #e5e7eb">' : '')
            . (!$isPdf && !$photo ? '<div class="muted">This file could not be shown. Open it from the loan in Loan Tracker.</div>' : '')
            . '</div>';
    }
}

$dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans']);
$dompdf->loadHtml('<!DOCTYPE html><html><head><meta charset="UTF-8"><style>' . $css . '</style></head><body>' . $html . '</body></html>', 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
if (!$pdfAttachments) {
    $dompdf->stream($fileName . '.pdf', ['Attachment' => empty($_GET['view'])]);
    exit;
}

// Statement / receipt pages first, then the attachments — put together with mPDF (its FPDI reads PDFs).
$tmp = sys_get_temp_dir() . '/lt_pdf';
if (!is_dir($tmp)) {
    @mkdir($tmp, 0775, true);
}
$mpdf = new \Mpdf\Mpdf(['format' => 'A4', 'tempDir' => $tmp, 'margin_left' => 15, 'margin_right' => 15, 'margin_top' => 16, 'margin_bottom' => 14]);
$mpdf->SetTitle($fileName);
$addPdfPages = static function (\Mpdf\Mpdf $mpdf, $source, string $label = ''): int {
    $n = $mpdf->setSourceFile($source);
    for ($i = 1; $i <= $n; $i++) {
        $tpl = $mpdf->importPage($i);
        $size = $mpdf->getTemplateSize($tpl);
        $mpdf->AddPageByArray(['orientation' => $size['width'] > $size['height'] ? 'L' : 'P',
            'sheet-size' => [$size['width'], $size['height']], 'mgl' => 0, 'mgr' => 0, 'mgt' => 0, 'mgb' => 0, 'mgh' => 0, 'mgf' => 0]);
        $mpdf->useTemplate($tpl, 0, 0, $size['width'], $size['height']);
        if ($label !== '') {
            $mpdf->SetFont('dejavusans', '', 7.5);
            $mpdf->SetTextColor(107, 114, 128);
            $mpdf->Text(6, 5, $label . ($n > 1 ? ' · page ' . $i . ' of ' . $n : ''));
        }
    }
    return $n;
};
$addPdfPages($mpdf, \setasign\Fpdi\PdfParser\StreamReader::createByString($dompdf->output()));

foreach ($pdfAttachments as $a) {
    $label = 'Attachment ' . $a['n'] . ' of ' . count($attachments) . ': ' . $a['title'];
    try {
        $addPdfPages($mpdf, $a['path'], $label);
    } catch (Throwable $e) {
        error_log('loan_pdf attachment ' . $a['path'] . ': ' . $e->getMessage());
        $mpdf->AddPageByArray(['sheet-size' => 'A4', 'orientation' => 'P', 'mgl' => 15, 'mgr' => 15, 'mgt' => 16, 'mgb' => 14]);
        $mpdf->WriteHTML('<div style="font-family:dejavusans;color:#6b7280;font-size:9pt">' . h($label) . '</div>'
            . '<div style="margin-top:40mm;text-align:center;font-family:dejavusans;font-size:12pt;color:#1f2937">'
            . 'This PDF could not be added here (the file uses a format that cannot be read for printing).<br><br>'
            . 'Open it from the loan in Loan Tracker.</div>');
    }
}
$mpdf->Output($fileName . '.pdf', empty($_GET['view']) ? \Mpdf\Output\Destination::DOWNLOAD : \Mpdf\Output\Destination::INLINE);
exit;
