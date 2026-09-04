<?php
// accounts/statements_export.php
// Standalone PDF export for Statements (called directly by the PDF button)

declare(strict_types=1);

// ---- DEBUG SWITCH -----------------------------------------------------------
// Add &debug=1 to the querystring to see readable errors instead of a blank 500.
$DEBUG = isset($_GET['debug']) && $_GET['debug'] == '1';
if ($DEBUG) {
  ini_set('display_errors', '1');
  ini_set('display_startup_errors', '1');
  error_reporting(E_ALL);
}

// ---- BOOTSTRAP --------------------------------------------------------------
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/ar_helpers.php';
require_once __DIR__ . '/../includes/composer_autoload_safe.php';

if (!herosysgro_composer_autoload_safe() || !class_exists('\Dompdf\Dompdf')) {
  if ($DEBUG) {
    die('PDF export requires Dompdf (Composer). On this server PHP may be below the Composer platform requirement; use CSV export or upgrade PHP.');
  }
  http_response_code(503);
  header('Content-Type: text/plain; charset=utf-8');
  echo 'PDF export is temporarily unavailable on this server. Please use CSV export or contact support.';
  exit;
}

use Dompdf\Dompdf;
use Dompdf\Options;

// ---- INPUTS -----------------------------------------------------------------
$client_id = (int)($_GET['client_id'] ?? 0);
$from      = $_GET['from'] ?? '';
$to        = $_GET['to']   ?? '';
$view      = trim($_GET['view'] ?? 'open'); // 'open' | 'ledger' | 'all'
$include_fully_paid = isset($_GET['incl_paid']) && $_GET['incl_paid'] == '1';

// Debug: Uncomment to verify view parameter
// if ($DEBUG) { error_log("DEBUG: view parameter = '" . $view . "'"); }

// Sanitize dates (accept YYYY-MM-DD or empty)
$validDate = fn(string $d) => ($d === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $d));
if (!$client_id || !$validDate($from) || !$validDate($to)) {
  if ($DEBUG) { die('Bad client or date range.'); }
  http_response_code(400); exit;
}

// ---- FETCH DATA -------------------------------------------------------------
try {
  // Client name
  $cst = $conn->prepare("SELECT client_name FROM client WHERE id=?");
  $cst->execute([$client_id]);
  $client_name = (string)($cst->fetchColumn() ?: 'Client');

  // Initialize data containers
  $rows = [];
  $totals = ['debit' => 0.0, 'credit' => 0.0, 'balance' => 0.0];
  $open_balance = 0.00;
  $paid_in_period = 0.00;
  $last_payment_date = null;
  $invoice_count = 0;

  // Debug: Log view parameter (remove after testing)
  if ($DEBUG) {
    error_log("DEBUG statements_export: view='$view', client_id=$client_id, from=$from, to=$to");
  }

  if ($view === 'ledger') {
    /* ---------- LEDGER VIEW: Invoices + Receipts with Running Balance ---------- */
    
    // Last payment date (all receipts, not just in period)
    $lp = $conn->prepare("SELECT MAX(receipt_date) FROM receipts WHERE client_id=?");
    $lp->execute([$client_id]);
    $last_payment_date = $lp->fetchColumn() ?: null;
    
    // Paid in period
    $pir = $conn->prepare("
      SELECT COALESCE(SUM(ra.amount_applied),0)
      FROM receipt_allocations ra
      JOIN receipts r ON r.id = ra.receipt_id
      WHERE r.client_id=? AND r.receipt_date BETWEEN ? AND ?
    ");
    $pir->execute([$client_id, $from, $to]);
    $paid_in_period = (float)$pir->fetchColumn();
    
    // Opening balance BEFORE the "from" date
    $openBefore = $conn->prepare("
      SELECT
        COALESCE((
          SELECT SUM(i.total)
          FROM invoices i
          WHERE i.client_id=? AND i.issue_date < ? AND COALESCE(i.status,'') <> 'void'
            AND " . ar_collectible_invoice_sql('i') . "
        ),0) -
        COALESCE((
          SELECT SUM(ra.amount_applied)
          FROM receipt_allocations ra
          JOIN receipts r ON r.id = ra.receipt_id
          WHERE r.client_id=? AND r.receipt_date < ?
        ),0) AS opening
    ");
    $openBefore->execute([$client_id, $from, $client_id, $from]);
    $running = (float)$openBefore->fetchColumn();

    // Ledger rows within the range
    $ledgerSql = "
      SELECT * FROM (
        SELECT i.issue_date AS row_date,
               i.invoice_no AS ref,
               'Invoice'   AS kind,
               i.total     AS debit,
               0.00        AS credit,
               ''          AS note,
               i.id        AS invoice_id,
               NULL        AS receipt_id
        FROM invoices i
        WHERE i.client_id=? AND i.issue_date BETWEEN ? AND ? AND COALESCE(i.status,'') <> 'void'
          AND " . ar_collectible_invoice_sql('i') . "

        UNION ALL

        SELECT r.receipt_date AS row_date,
               CONCAT('RCPT-', r.id) AS ref,
               'Receipt'   AS kind,
               0.00        AS debit,
               COALESCE(SUM(ra.amount_applied),0) AS credit,
               COALESCE(CONCAT('Applied to ', GROUP_CONCAT(distinct inv.invoice_no ORDER BY inv.invoice_no SEPARATOR ', ')),'') AS note,
               NULL        AS invoice_id,
               r.id        AS receipt_id
        FROM receipts r
        LEFT JOIN receipt_allocations ra ON ra.receipt_id = r.id
        LEFT JOIN invoices inv ON inv.id = ra.invoice_id
        WHERE r.client_id=? AND r.receipt_date BETWEEN ? AND ?
        GROUP BY r.id
      ) x
      ORDER BY x.row_date, x.kind, x.ref
    ";
    $st = $conn->prepare($ledgerSql);
    $st->execute([$client_id, $from, $to, $client_id, $from, $to]);
    $ledgerRows = $st->fetchAll(PDO::FETCH_ASSOC);

    // Prepend opening row
    $rows = [[
      'row_date' => $from,
      'ref' => 'Opening',
      'kind' => 'Opening',
      'debit' => 0.00,
      'credit' => 0.00,
      'note' => 'Balance brought forward',
      'running' => $running,
    ]];

    // Compute running balance and totals
    $totals = ['debit' => 0.0, 'credit' => 0.0, 'balance' => $running];

    foreach ($ledgerRows as $r) {
      $d = (float)$r['debit'];
      $c = (float)$r['credit'];
      $totals['debit'] += $d;
      $totals['credit'] += $c;
      $running += ($d - $c);
      $r['running'] = $running;
      $rows[] = $r;
      if ($r['kind'] === 'Invoice') {
        $invoice_count++;
      }
    }
    $totals['balance'] = $running;
    $open_balance = $running; // Final running balance for display

  } else {
    /* ---------- OPEN VIEW: Invoices only ---------- */
    
    // Base WHERE
    $where = ["i.client_id = ?"];
    $args  = [$client_id];

    // Date filter (by issue_date), if provided
    if ($from !== '') { $where[] = "i.issue_date >= ?"; $args[] = $from; }
    if ($to   !== '') { $where[] = "i.issue_date <= ?"; $args[] = $to; }

    // View filter
    if ($view === 'open') {
      // show issued + partially_paid, optionally include fully paid when checkbox ticked
      if ($include_fully_paid) {
        $where[] = "i.status IN ('issued','partially_paid','paid')";
      } else {
        $where[] = "i.status IN ('issued','partially_paid')";
      }
    } else {
      // 'all' — show anything except void
      $where[] = "i.status <> 'void'";
    }
    $where[] = ar_collectible_invoice_sql('i');

    // Pull statement rows with payments rollup
    $sql = "
      SELECT
        i.id AS invoice_id,
        i.invoice_no,
        i.issue_date,
        i.total,
        COALESCE(SUM(ra.amount_applied),0) AS amount_paid,
        (i.total - COALESCE(SUM(ra.amount_applied),0)) AS balance_due,
        GROUP_CONCAT(
          CONCAT(DATE(r.receipt_date),' ',FORMAT(ra.amount_applied,2))
          ORDER BY r.receipt_date SEPARATOR '; '
        ) AS payments
      FROM invoices i
      LEFT JOIN receipt_allocations ra ON ra.invoice_id = i.id
      LEFT JOIN receipts r             ON r.id = ra.receipt_id
      WHERE ".implode(' AND ', $where)."
      GROUP BY i.id
      ORDER BY i.issue_date, i.id
    ";
    $st = $conn->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    // Metrics for header boxes
    foreach ($rows as $r) {
      $open_balance += (float)$r['balance_due'];
    }
    $invoice_count = count($rows);

    // Paid in period + last payment date
    // (independent query, constrained by same filters)
    $paySql = "
      SELECT r.receipt_date, ra.amount_applied
      FROM receipts r
      INNER JOIN receipt_allocations ra ON ra.receipt_id = r.id
      INNER JOIN invoices i            ON i.id = ra.invoice_id
      WHERE i.client_id = ?
        ".($from !== '' ? "AND i.issue_date >= ? " : "")."
        ".($to   !== '' ? "AND i.issue_date <= ? " : "")."
        ".($view === 'open'
            ? ($include_fully_paid
                ? "AND i.status IN ('issued','partially_paid','paid') "
                : "AND i.status IN ('issued','partially_paid') "
              )
            : "AND i.status <> 'void' "
          )."
    ";
    $payArgs = [$client_id];
    if ($from !== '') $payArgs[] = $from;
    if ($to   !== '') $payArgs[] = $to;

    $pst = $conn->prepare($paySql);
    $pst->execute($payArgs);
    while ($p = $pst->fetch(PDO::FETCH_ASSOC)) {
      $paid_in_period += (float)$p['amount_applied'];
      $d = (string)$p['receipt_date'];
      if ($d && (!$last_payment_date || $d > $last_payment_date)) {
        $last_payment_date = $d;
      }
    }
  }

} catch (Throwable $e) {
  if ($DEBUG) { die('Query error: '.$e->getMessage()); }
  http_response_code(500); exit;
}

// ---- BUILD HTML -------------------------------------------------------------
$fmt = fn($n) => number_format((float)$n, 2, '.', ',');

ob_start();
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Statement — <?= htmlspecialchars($client_name, ENT_QUOTES, 'UTF-8') ?></title>
<style>
  :root { --ink:#0f172a; --muted:#64748b; --edge:#e5e7eb; }
  @page { margin: 16mm 14mm 18mm 14mm; }
  body { font-family: DejaVu Sans, Arial, Helvetica, sans-serif; font-size:12px; color:var(--ink); }
  h1 { margin:0 0 8px; font-size:18px; }
  .meta{ margin-bottom:10px; color:var(--muted); }
  .grid{ width:100%; border-collapse:separate; border-spacing:8px; margin:10px 0 12px; }
  .card{ border:1px solid var(--edge); border-radius:8px; padding:10px; }
  .label{ font-size:11px; color:var(--muted); }
  .value{ font-size:14px; font-weight:700; margin-top:2px; }
  table.tbl{ width:100%; border-collapse:collapse; }
  .tbl th, .tbl td{ padding:8px 8px; border-bottom:1px solid var(--edge); }
  .tbl thead th{ background:#fafafa; font-weight:600; }
  .num{ text-align:right; }
  .badge{ display:inline-block; padding:2px 8px; border-radius:999px; font-size:11px; background:#e9f7ef; }
</style>
</head>
<body>
  <h1>Statement</h1>
  <div class="meta">
    <?= htmlspecialchars($client_name, ENT_QUOTES, 'UTF-8') ?> &middot;
    Period: <?= htmlspecialchars(($from?:'—').' → '.($to?:'—'), ENT_QUOTES, 'UTF-8') ?> &middot;
    View: <?= htmlspecialchars(
      $view === 'ledger' ? 'Ledger (with running balance)' : 
      ($view === 'open' ? 'Open invoices' : 'All (except void)'), 
      ENT_QUOTES, 'UTF-8'
    ) ?>
    <?= $view==='open' && $include_fully_paid ? ' (incl. fully paid)' : '' ?>
  </div>

  <table class="grid">
    <tr>
      <td class="card">
        <div class="label">Open Balance</div>
        <div class="value">AED <?= $fmt($open_balance) ?></div>
      </td>
      <td class="card">
        <div class="label"><?= $view === 'ledger' ? 'Invoices in ledger' : 'Invoices in Range' ?></div>
        <div class="value"><?= (int)$invoice_count ?></div>
      </td>
      <td class="card">
        <div class="label">Last Payment</div>
        <div class="value"><?= htmlspecialchars($last_payment_date ?: '—', ENT_QUOTES, 'UTF-8') ?></div>
      </td>
      <td class="card">
        <div class="label">Paid in Period</div>
        <div class="value">AED <?= $fmt($paid_in_period) ?></div>
      </td>
    </tr>
  </table>

  <table class="tbl">
    <thead>
      <?php if ($view === 'ledger'): ?>
      <tr>
        <th style="width:90px;">Date</th>
        <th style="width:80px;">Type</th>
        <th>Ref</th>
        <th class="num" style="width:90px;">Debit</th>
        <th class="num" style="width:90px;">Credit</th>
        <th class="num" style="width:90px;">Running</th>
        <th>Note</th>
      </tr>
      <?php else: ?>
      <tr>
        <th style="width:90px;">Date</th>
        <th>Invoice</th>
        <th class="num" style="width:90px;">Total</th>
        <th class="num" style="width:90px;">Paid</th>
        <th class="num" style="width:90px;">Balance</th>
        <th>Payments</th>
      </tr>
      <?php endif; ?>
    </thead>
    <tbody>
      <?php if ($view === 'ledger'): ?>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= htmlspecialchars($r['row_date'], ENT_QUOTES, 'UTF-8') ?></td>
          <td><?= htmlspecialchars($r['kind'], ENT_QUOTES, 'UTF-8') ?></td>
          <td><?= htmlspecialchars($r['ref'], ENT_QUOTES, 'UTF-8') ?></td>
          <td class="num"><?= $fmt($r['debit']) ?></td>
          <td class="num"><?= $fmt($r['credit']) ?></td>
          <td class="num" style="font-weight:600;"><?= $fmt($r['running']) ?></td>
          <td><?= htmlspecialchars($r['note'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
        </tr>
        <?php endforeach; ?>
        <tr style="background:#fafafa; font-weight:600;">
          <td colspan="3" style="text-align:right;">Totals</td>
          <td class="num">AED <?= $fmt($totals['debit']) ?></td>
          <td class="num">AED <?= $fmt($totals['credit']) ?></td>
          <td class="num" style="font-weight:700;">AED <?= $fmt($totals['balance']) ?></td>
          <td></td>
        </tr>
      <?php else: ?>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= htmlspecialchars($r['issue_date'], ENT_QUOTES, 'UTF-8') ?></td>
          <td><?= htmlspecialchars($r['invoice_no'], ENT_QUOTES, 'UTF-8') ?></td>
          <td class="num"><?= $fmt($r['total']) ?></td>
          <td class="num"><?= $fmt($r['amount_paid']) ?></td>
          <td class="num"><?= $fmt($r['balance_due']) ?></td>
          <td><?= htmlspecialchars($r['payments'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
        </tr>
        <?php endforeach; if (!$rows): ?>
        <tr><td colspan="6" style="text-align:center; color:#94a3b8; padding:20px 0;">No invoices found for this selection.</td></tr>
        <?php endif; ?>
      <?php endif; ?>
    </tbody>
  </table>
</body>
</html>
<?php
$html = ob_get_clean();

// ---- RENDER PDF -------------------------------------------------------------
try {
  $tmpDir = __DIR__ . '/storage/dompdf_tmp';
  if (!is_dir($tmpDir)) { @mkdir($tmpDir, 0777, true); }

  $opt = new Options();
  $opt->set('isHtml5ParserEnabled', true);
  $opt->set('isRemoteEnabled', true);
  // safer temp/cache on macOS/XAMPP
  $opt->set('tempDir',  $tmpDir);
  $opt->set('fontCache', $tmpDir);

  $dompdf = new Dompdf($opt);
  $dompdf->loadHtml($html, 'UTF-8');
  $dompdf->setPaper('A4', 'portrait');
  $dompdf->render();

  // Stream inline
  $safeName = 'Statement-'.preg_replace('/[^A-Za-z0-9_-]+/','_', $client_name).'.pdf';
  $dompdf->stream($safeName, ['Attachment' => false]);
  exit;

} catch (Throwable $e) {
  if ($DEBUG) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "PDF error: ".$e->getMessage();
  } else {
    http_response_code(500);
  }
  exit;
}
