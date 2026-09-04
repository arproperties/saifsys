<?php
/**
 * Real Estate Accounting - Day Book
 * All posted transactions by date (journal summary with optional line detail)
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/branding.php';
require_once __DIR__ . '/../../../includes/company_helper.php';
require_once __DIR__ . '/../../../includes/module_access.php';
require_once __DIR__ . '/../../../includes/rbac_department.php';

require_login();
if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_FINANCIAL, $conn)) {
    require_module_access($conn, MODULE_REALESTATE);
}

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;
$dateFrom = !empty($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01');
$dateTo = !empty($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
$showLines = !empty($_GET['show_lines']);

$stmt = $conn->prepare("
    SELECT jh.id, jh.journal_number, jh.journal_date, jh.journal_type, jh.description,
           jh.total_debit, jh.total_credit, jh.reference_type, jh.reference_id,
           u.username as created_by_name
    FROM re_journal_headers jh
    LEFT JOIN user u ON u.id = jh.created_by
    WHERE jh.company_id = ? AND jh.is_posted = 1 AND jh.journal_date BETWEEN ? AND ?
    ORDER BY jh.journal_date ASC, jh.id ASC
");
$stmt->execute([$currentCompanyId, $dateFrom, $dateTo]);
$journals = $stmt->fetchAll(PDO::FETCH_ASSOC);

$linesByJournal = [];
if ($showLines && !empty($journals)) {
    $ids = array_column($journals, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $conn->prepare("
        SELECT jl.journal_id, jl.debit_amount, jl.credit_amount, jl.description as line_desc,
               coa.account_code, coa.account_name
        FROM re_journal_lines jl
        JOIN re_chart_of_accounts coa ON coa.id = jl.account_id
        WHERE jl.journal_id IN ($placeholders)
        ORDER BY jl.journal_id, jl.line_number
    ");
    $stmt->execute($ids);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $linesByJournal[$row['journal_id']][] = $row;
    }
}

// Export before any HTML
if (!empty($_GET['export'])) {
    $format = $_GET['export'] === 'excel' ? 'excel' : 'csv';
    $filename = 'day_book_' . $dateFrom . '_' . $dateTo;
    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($out, ['Date', 'Journal #', 'Type', 'Description', 'Debit', 'Credit']);
        foreach ($journals as $j) {
            fputcsv($out, [$j['journal_date'], $j['journal_number'], $j['journal_type'], $j['description'], $j['total_debit'], $j['total_credit']]);
        }
        fclose($out);
        exit;
    }
    if ($format === 'excel') {
        $vendorPath = __DIR__ . '/../../../vendor/autoload.php';
        if (file_exists($vendorPath)) {
            require_once $vendorPath;
            if (class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet')) {
                $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
                $sheet = $spreadsheet->getActiveSheet();
                $sheet->setTitle('Day Book');
                $sheet->setCellValue('A1', 'Day Book - ' . $dateFrom . ' to ' . $dateTo);
                $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
                $sheet->mergeCells('A1:F1');
                foreach (['Date', 'Journal #', 'Type', 'Description', 'Debit', 'Credit'] as $ci => $h) {
                    $sheet->setCellValueByColumnAndRow($ci + 1, 3, $h);
                }
                $sheet->getStyle('A3:F3')->getFont()->setBold(true);
                $row = 4;
                foreach ($journals as $j) {
                    $sheet->setCellValue('A' . $row, $j['journal_date']);
                    $sheet->setCellValue('B' . $row, $j['journal_number']);
                    $sheet->setCellValue('C' . $row, $j['journal_type']);
                    $sheet->setCellValue('D' . $row, $j['description']);
                    $sheet->setCellValue('E' . $row, $j['total_debit']);
                    $sheet->setCellValue('F' . $row, $j['total_credit']);
                    $row++;
                }
                foreach (range('A','F') as $c) $sheet->getColumnDimension($c)->setAutoSize(true);
                header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                header('Content-Disposition: attachment; filename="' . $filename . '.xlsx"');
                $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
                $writer->save('php://output');
                exit;
            }
        }
        header('Location: ?date_from=' . urlencode($dateFrom) . '&date_to=' . urlencode($dateTo) . '&export=csv');
        exit;
    }
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$pageTitle = 'Day Book';
require_once __DIR__ . '/../includes/re_layout_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 no-print">
    <div class="page-header-label"><i class="bi bi-journal-book"></i> Day Book</div>
    <div>
        <button type="button" class="btn btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
        <a href="?date_from=<?= h($dateFrom) ?>&date_to=<?= h($dateTo) ?>&export=csv" class="btn btn-outline-primary"><i class="bi bi-download"></i> CSV</a>
        <a href="?date_from=<?= h($dateFrom) ?>&date_to=<?= h($dateTo) ?>&export=excel" class="btn btn-outline-success"><i class="bi bi-file-earmark-excel"></i> Excel</a>
    </div>
</div>

<div class="card card-round mb-4 no-print">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-3">
                <label class="form-label">From Date</label>
                <input type="date" name="date_from" class="form-control" value="<?= h($dateFrom) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">To Date</label>
                <input type="date" name="date_to" class="form-control" value="<?= h($dateTo) ?>">
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <div class="form-check">
                    <input type="checkbox" name="show_lines" id="showLines" value="1" <?= $showLines ? 'checked' : '' ?> onchange="this.form.submit()">
                    <label class="form-check-label" for="showLines">Show lines</label>
                </div>
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button type="submit" class="btn btn-primary w-100">View</button>
            </div>
        </form>
    </div>
</div>

<div class="card card-round">
    <div class="card-header bg-light">
        <h5 class="mb-0">Day Book – <?= date('M d, Y', strtotime($dateFrom)) ?> to <?= date('M d, Y', strtotime($dateTo)) ?></h5>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0" id="dayBookTable">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Journal #</th>
                        <th>Type</th>
                        <th>Description</th>
                        <th class="text-end">Debit</th>
                        <th class="text-end">Credit</th>
                        <?php if ($showLines): ?><th>Line detail</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($journals)): ?>
                        <tr><td colspan="<?= $showLines ? 7 : 6 ?>" class="text-center text-muted py-4">No posted transactions in this period.</td></tr>
                    <?php else: ?>
                        <?php foreach ($journals as $j): ?>
                            <tr>
                                <td><?= date('Y-m-d', strtotime($j['journal_date'])) ?></td>
                                <td><a href="journal_entry_view.php?id=<?= $j['id'] ?>"><?= h($j['journal_number']) ?></a></td>
                                <td><span class="badge bg-info"><?= h($j['journal_type']) ?></span></td>
                                <td><?= h($j['description'] ?: '-') ?></td>
                                <td class="text-end"><?= number_format($j['total_debit'], 2) ?></td>
                                <td class="text-end"><?= number_format($j['total_credit'], 2) ?></td>
                                <?php if ($showLines): ?>
                                    <td>
                                        <?php if (!empty($linesByJournal[$j['id']])): ?>
                                            <?php foreach ($linesByJournal[$j['id']] as $line): ?>
                                                <small class="d-block"><?= h($line['account_code']) ?> <?= h($line['account_name']) ?>
                                                    <?= $line['debit_amount'] > 0 ? ' Dr ' . number_format($line['debit_amount'], 2) : ' Cr ' . number_format($line['credit_amount'], 2) ?></small>
                                            <?php endforeach; ?>
                                        <?php else: ?>-<?php endif; ?>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/re_layout_footer.php'; ?>
