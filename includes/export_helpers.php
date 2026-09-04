<?php
// includes/export_helpers.php
// Unified export service for CSV, Excel, and PDF formats

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/company_helper.php';

class ExportService {
  private $conn;
  
  public function __construct(PDO $conn) {
    $this->conn = $conn;
  }
  
  /**
   * Export data in the specified format
   * 
   * @param array $data Array of data to export
   * @param array $columns Column definitions ['key' => 'Display Name']
   * @param string $format 'csv', 'excel', or 'pdf'
   * @param string $filename Base filename (without extension)
   * @param string $title Report title for PDF
   * @return void (sends file to browser)
   */
  public function export(array $data, array $columns, string $format, string $filename, string $title = '') {
    switch (strtolower($format)) {
      case 'csv':
        $this->exportCSV($data, $columns, $filename);
        break;
      case 'excel':
        $this->exportExcel($data, $columns, $filename, $title);
        break;
      case 'pdf':
        $this->exportPDF($data, $columns, $filename, $title);
        break;
      default:
        throw new InvalidArgumentException('Unsupported export format: ' . $format);
    }
  }
  
  /**
   * Export to CSV format
   */
  private function exportCSV(array $data, array $columns, string $filename) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Add BOM for UTF-8 compatibility with Excel
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Write headers
    fputcsv($output, array_values($columns));
    
    // Write data
    foreach ($data as $row) {
      $csvRow = [];
      foreach (array_keys($columns) as $key) {
        $csvRow[] = $this->formatValue($row[$key] ?? '');
      }
      fputcsv($output, $csvRow);
    }
    
    fclose($output);
    exit;
  }
  
  /**
   * Export to Excel format using PhpSpreadsheet
   */
  private function exportExcel(array $data, array $columns, string $filename, string $title) {
    // Check if PhpSpreadsheet is available
    if (!class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet')) {
      // Fallback to CSV if PhpSpreadsheet not available
      $this->exportCSV($data, $columns, $filename);
      return;
    }
    
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    
    // Set title
    if ($title) {
      $sheet->setCellValue('A1', $title);
      $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
      $sheet->mergeCells('A1:' . chr(65 + count($columns) - 1) . '1');
      $row = 3;
    } else {
      $row = 1;
    }
    
    // Write headers
    $col = 1;
    foreach ($columns as $key => $displayName) {
      $sheet->setCellValueByColumnAndRow($col, $row, $displayName);
      $sheet->getStyleByColumnAndRow($col, $row)->getFont()->setBold(true);
      $col++;
    }
    $row++;
    
    // Write data
    foreach ($data as $dataRow) {
      $col = 1;
      foreach (array_keys($columns) as $key) {
        $value = $this->formatValue($dataRow[$key] ?? '');
        $sheet->setCellValueByColumnAndRow($col, $row, $value);
        $col++;
      }
      $row++;
    }
    
    // Auto-size columns
    foreach (range(1, count($columns)) as $col) {
      $sheet->getColumnDimensionByColumn($col)->setAutoSize(true);
    }
    
    // Set headers and send file
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '.xlsx"');
    
    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
  }
  
  /**
   * Export to PDF format using Dompdf
   */
  private function exportPDF(array $data, array $columns, string $filename, string $title) {
    // Check if Dompdf is available
    if (!class_exists('Dompdf\Dompdf')) {
      throw new RuntimeException('Dompdf library not available for PDF export');
    }
    
    $html = $this->generatePDFHTML($data, $columns, $title);
    
    $dompdf = new \Dompdf\Dompdf();
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();
    
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '.pdf"');
    
    echo $dompdf->output();
    exit;
  }
  
  /**
   * Generate HTML for PDF export
   */
  private function generatePDFHTML(array $data, array $columns, string $title): string {
    $html = '<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>' . htmlspecialchars($title) . '</title>
  <style>
    body { font-family: Arial, sans-serif; font-size: 12px; margin: 20px; }
    h1 { color: #800000; margin-bottom: 20px; }
    table { width: 100%; border-collapse: collapse; margin-top: 10px; }
    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
    th { background-color: #f5f5f5; font-weight: bold; }
    tr:nth-child(even) { background-color: #f9f9f9; }
    .text-right { text-align: right; }
    .text-center { text-align: center; }
  </style>
</head>
<body>';
    
    if ($title) {
      $html .= '<h1>' . htmlspecialchars($title) . '</h1>';
    }
    
    $html .= '<table>
  <thead>
    <tr>';
    
    foreach ($columns as $displayName) {
      $html .= '<th>' . htmlspecialchars($displayName) . '</th>';
    }
    
    $html .= '</tr>
  </thead>
  <tbody>';
    
    foreach ($data as $row) {
      $html .= '<tr>';
      foreach (array_keys($columns) as $key) {
        $value = $this->formatValue($row[$key] ?? '');
        $html .= '<td>' . htmlspecialchars($value) . '</td>';
      }
      $html .= '</tr>';
    }
    
    $html .= '</tbody>
</table>
</body>
</html>';
    
    return $html;
  }
  
  /**
   * Format a value for display
   */
  private function formatValue($value) {
    if (is_numeric($value)) {
      return number_format((float)$value, 2);
    }
    if (is_bool($value)) {
      return $value ? 'Yes' : 'No';
    }
    if ($value === null) {
      return '';
    }
    return (string)$value;
  }
  
  /**
   * Get export options for a specific data type
   */
  public static function getExportOptions(string $dataType): array {
    $options = [
      'formats' => ['csv', 'excel', 'pdf'],
      'columns' => []
    ];
    
    switch ($dataType) {
      case 'invoices':
        $options['columns'] = [
          'invoice_no' => 'Invoice No',
          'issue_date' => 'Issue Date',
          'due_date' => 'Due Date',
          'client_name' => 'Client',
          'total' => 'Total',
          'amount_paid' => 'Paid',
          'balance_due' => 'Balance',
          'status' => 'Status'
        ];
        break;
        
      case 'payments':
        $options['columns'] = [
          'receipt_no' => 'Receipt No',
          'receipt_date' => 'Date',
          'client_name' => 'Client',
          'method' => 'Method',
          'amount' => 'Amount',
          'notes' => 'Notes'
        ];
        break;
        
      case 'expenses':
        $options['columns'] = [
          'expense_no' => 'Expense No',
          'expense_date' => 'Date',
          'vendor_name' => 'Vendor',
          'description' => 'Description',
          'total' => 'Total',
          'status' => 'Status'
        ];
        break;
        
      case 'dashboard':
        $options['columns'] = [
          'metric' => 'Metric',
          'value' => 'Value',
          'description' => 'Description'
        ];
        break;
    }
    
    return $options;
  }
}

/**
 * Helper function to create export buttons
 */
function createExportButtons(string $dataType, array $selectedIds = []): string {
  $options = ExportService::getExportOptions($dataType);
  $buttons = '<div class="btn-group btn-group-sm">';
  
  foreach ($options['formats'] as $format) {
    $icon = $format === 'csv' ? 'file-csv' : ($format === 'excel' ? 'file-excel' : 'file-pdf');
    $buttons .= '<button class="btn btn-outline-secondary" onclick="exportData(\'' . $format . '\', \'' . $dataType . '\')">
      <i class="bi bi-' . $icon . '"></i> ' . strtoupper($format) . '
    </button>';
  }
  
  $buttons .= '</div>';
  return $buttons;
}

/**
 * AJAX endpoint for export requests
 */
function handleExportRequest(PDO $conn, string $dataType, string $format, array $filters = [], array $selectedIds = []) {
  $exportService = new ExportService($conn);
  $options = ExportService::getExportOptions($dataType);
  
  // Get data based on type and filters
  $data = getExportData($conn, $dataType, $filters, $selectedIds);
  
  $filename = $dataType . '_export_' . date('Y-m-d_H-i-s');
  $title = ucfirst($dataType) . ' Export - ' . date('Y-m-d H:i:s');
  
  $exportService->export($data, $options['columns'], $format, $filename, $title);
}

/**
 * Get data for export based on type and filters
 */
function getExportData(PDO $conn, string $dataType, array $filters = [], array $selectedIds = []): array {
  switch ($dataType) {
    case 'invoices':
      return getInvoicesExportData($conn, $filters, $selectedIds);
    case 'payments':
      return getPaymentsExportData($conn, $filters, $selectedIds);
    case 'expenses':
      return getExpensesExportData($conn, $filters, $selectedIds);
    case 'dashboard':
      return getDashboardExportData($conn, $filters);
    default:
      return [];
  }
}

function getInvoicesExportData(PDO $conn, array $filters, array $selectedIds): array {
  $where = ["1=1"];
  $args = [];

  $currentCompanyId = function_exists('current_company_id') ? (current_company_id($conn) ?: 1) : 1;
  $where[] = "i.company_id = ?";
  $args[] = $currentCompanyId;
  
  if (!empty($selectedIds)) {
    $placeholders = str_repeat('?,', count($selectedIds) - 1) . '?';
    $where[] = "i.id IN ($placeholders)";
    $args = array_merge($args, $selectedIds);
  }
  
  if (!empty($filters['status'])) {
    $where[] = "i.status = ?";
    $args[] = $filters['status'];
  }

  if (!empty($filters['q'])) {
    $where[] = "(i.invoice_no LIKE ? OR c.client_name LIKE ? OR c.email LIKE ?)";
    $searchTerm = '%' . $filters['q'] . '%';
    $args = array_merge($args, [$searchTerm, $searchTerm, $searchTerm]);
  }

  if (!empty($filters['from'])) {
    $where[] = "i.issue_date >= ?";
    $args[] = $filters['from'];
  }

  if (!empty($filters['to'])) {
    $where[] = "i.issue_date <= ?";
    $args[] = $filters['to'];
  }

  if (!empty($filters['due_from'])) {
    $where[] = "i.due_date >= ?";
    $args[] = $filters['due_from'];
  }

  if (!empty($filters['due_to'])) {
    $where[] = "i.due_date <= ?";
    $args[] = $filters['due_to'];
  }

  if (!empty($filters['amount_min'])) {
    $where[] = "i.total >= ?";
    $args[] = $filters['amount_min'];
  }

  if (!empty($filters['amount_max'])) {
    $where[] = "i.total <= ?";
    $args[] = $filters['amount_max'];
  }

  if (!empty($filters['overdue'])) {
    $where[] = "i.due_date < CURDATE() AND i.status IN ('issued', 'partially_paid')";
  }

  if (!empty($filters['client_status'])) {
    $where[] = "c.client_status = ?";
    $args[] = $filters['client_status'];
  }
  
  $sql = "
    SELECT
      i.invoice_no,
      i.issue_date,
      i.due_date,
      c.client_name,
      i.total,
      COALESCE(pa.amount_paid, 0) AS amount_paid,
      GREATEST(ROUND(COALESCE(i.total,0) - COALESCE(pa.amount_paid,0), 2), 0) AS balance_due,
      i.status
    FROM invoices i
    LEFT JOIN client c ON c.id = i.client_id
    LEFT JOIN (
      SELECT ra.invoice_id, ROUND(SUM(ra.amount_applied), 2) AS amount_paid
      FROM receipt_allocations ra
      GROUP BY ra.invoice_id
    ) pa ON pa.invoice_id = i.id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY i.issue_date DESC
  ";
  
  $st = $conn->prepare($sql);
  $st->execute($args);
  return $st->fetchAll(PDO::FETCH_ASSOC);
}

function getPaymentsExportData(PDO $conn, array $filters, array $selectedIds): array {
  $where = ["1=1"];
  $args = [];
  
  if (!empty($selectedIds)) {
    $placeholders = str_repeat('?,', count($selectedIds) - 1) . '?';
    $where[] = "r.id IN ($placeholders)";
    $args = array_merge($args, $selectedIds);
  }
  
  $sql = "
    SELECT
      r.receipt_no,
      r.receipt_date,
      c.client_name,
      r.method,
      r.amount,
      r.notes
    FROM receipts r
    LEFT JOIN client c ON c.id = r.client_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY r.receipt_date DESC
  ";
  
  $st = $conn->prepare($sql);
  $st->execute($args);
  return $st->fetchAll(PDO::FETCH_ASSOC);
}

function getExpensesExportData(PDO $conn, array $filters, array $selectedIds): array {
  $where = ["1=1"];
  $args = [];
  
  if (!empty($selectedIds)) {
    $placeholders = str_repeat('?,', count($selectedIds) - 1) . '?';
    $where[] = "e.id IN ($placeholders)";
    $args = array_merge($args, $selectedIds);
  }
  
  $sql = "
    SELECT
      e.expense_no,
      e.expense_date,
      v.name as vendor_name,
      e.description,
      e.total,
      e.status
    FROM expenses e
    LEFT JOIN vendors v ON v.id = e.vendor_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY e.expense_date DESC
  ";
  
  $st = $conn->prepare($sql);
  $st->execute($args);
  return $st->fetchAll(PDO::FETCH_ASSOC);
}

function getDashboardExportData(PDO $conn, array $filters): array {
  // Get summary data for dashboard export
  $summary = $conn->query("SELECT * FROM v_ar_summary LIMIT 1")->fetch(PDO::FETCH_ASSOC);
  $ageing = $conn->query("SELECT * FROM v_ar_ageing LIMIT 1")->fetch(PDO::FETCH_ASSOC);
  
  return [
    ['metric' => 'Open Invoices', 'value' => $summary['open_count'] ?? 0, 'description' => 'Number of open invoices'],
    ['metric' => 'AR Total', 'value' => $summary['ar_total'] ?? 0, 'description' => 'Total accounts receivable'],
    ['metric' => 'Overdue Total', 'value' => $summary['overdue_total'] ?? 0, 'description' => 'Total overdue amount'],
    ['metric' => 'Paid This Month', 'value' => $summary['paid_this_month'] ?? 0, 'description' => 'Payments received this month'],
    ['metric' => 'Current (0 days)', 'value' => $ageing['bucket_0'] ?? 0, 'description' => 'Current receivables'],
    ['metric' => '1-30 days', 'value' => $ageing['bucket_30'] ?? 0, 'description' => '1-30 days overdue'],
    ['metric' => '31-60 days', 'value' => $ageing['bucket_60'] ?? 0, 'description' => '31-60 days overdue'],
    ['metric' => '61-90 days', 'value' => $ageing['bucket_90'] ?? 0, 'description' => '61-90 days overdue'],
    ['metric' => '90+ days', 'value' => $ageing['bucket_120'] ?? 0, 'description' => '90+ days overdue']
  ];
}
