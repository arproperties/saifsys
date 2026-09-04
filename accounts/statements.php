<?php
// accounts/statements.php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/ar_helpers.php';

/* ---------- Inputs ---------- */
$client_id     = (int)($_GET['client_id'] ?? 0);
$from          = $_GET['from'] ?? '';
$to            = $_GET['to'] ?? '';
$view          = $_GET['view'] ?? 'open';      // 'open' | 'ledger'
$include_paid  = !empty($_GET['include_paid']); // only for 'open' view
$export        = $_GET['export'] ?? '';        // '' | 'csv' | 'pdf'

/* ---------- Helpers (guard to avoid redeclare) ---------- */
if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('money')) {
  function money($n){ return number_format((float)$n, 2, '.', ','); }
}

/* ---------- Client list ---------- */
$clients = $conn->query("
  SELECT id, client_name
  FROM client
  ORDER BY client_name ASC
  LIMIT 10000
")->fetchAll(PDO::FETCH_ASSOC);

/* ---------- Date defaults (this month) ---------- */
if ($from==='' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-01');
if ($to  ==='' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = date('Y-m-t');

/* ---------- Data containers ---------- */
$cards = [
  'open_balance'  => 0.00,
  'invoice_count' => 0,
  'last_payment'  => null,
  'paid_in_range' => 0.00,
];
$rows  = [];      // table rows for the chosen view
$totals= ['debit'=>0.0,'credit'=>0.0,'balance'=>0.0]; // for ledger
$csv   = [];      // for export

if ($client_id > 0) {

  /* --- Common: open balance “now” (simple) --- */
  // Outstanding = invoices (non-void) - applied allocations
  $openBalanceSql = "
    SELECT
      ROUND(
        COALESCE((
          SELECT SUM(i.total) FROM invoices i
          WHERE i.client_id=? AND COALESCE(i.status,'') <> 'void'
            AND " . ar_collectible_invoice_sql('i') . "
        ),0)
        -
        COALESCE((
          SELECT SUM(ra.amount_applied)
          FROM receipt_allocations ra
          JOIN invoices ii ON ii.id = ra.invoice_id AND " . ar_collectible_invoice_sql('ii') . "
          WHERE ii.client_id = ?
        ),0), 2
      ) AS open_bal
  ";
  $s = $conn->prepare($openBalanceSql);
  $s->execute([$client_id,$client_id]);
  $cards['open_balance'] = (float)$s->fetchColumn();

  /* --- Last payment + amount in range --- */
  $lp = $conn->prepare("SELECT MAX(receipt_date) FROM receipts WHERE client_id=?");
  $lp->execute([$client_id]);
  $cards['last_payment'] = $lp->fetchColumn() ?: null;

  $pir = $conn->prepare("
    SELECT COALESCE(SUM(ra.amount_applied),0)
    FROM receipt_allocations ra
    JOIN receipts r ON r.id = ra.receipt_id
    WHERE r.client_id=? AND r.receipt_date BETWEEN ? AND ?
  ");
  $pir->execute([$client_id,$from,$to]);
  $cards['paid_in_range'] = (float)$pir->fetchColumn();

  /* ---------- VIEW A: Open Invoices (with optional include paid) ---------- */
  if ($view === 'open') {
    $statusList = $include_paid
      ? "('issued','partially_paid','paid')"
      : "('issued','partially_paid')";

    $sql = "
      SELECT
        i.id                       AS invoice_id,
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
      WHERE i.client_id = ?
        AND i.status IN {$statusList}
        AND " . ar_collectible_invoice_sql('i') . "
        AND i.issue_date BETWEEN ? AND ?
      GROUP BY i.id
      ORDER BY i.issue_date, i.id
    ";
    $st = $conn->prepare($sql);
    $st->execute([$client_id,$from,$to]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $cards['invoice_count'] = count($rows);

    // CSV rows
    foreach ($rows as $r) {
      $csv[] = [
        $r['issue_date'],
        $r['invoice_no'],
        money($r['total']),
        money($r['amount_paid']),
        money($r['balance_due']),
        (string)($r['payments'] ?? '')
      ];
    }

  } else {
    /* ---------- VIEW B: Ledger (Invoices + Receipts) with Running Balance ---------- */

    // Opening balance BEFORE the "from" date
    $openBefore = $conn->prepare("
      SELECT
        COALESCE((
          SELECT SUM(i.total)
          FROM invoices i
          WHERE i.client_id=? AND i.issue_date < ? AND COALESCE(i.status,'') <> 'void'
        ),0) -
        COALESCE((
          SELECT SUM(ra.amount_applied)
          FROM receipt_allocations ra
          JOIN receipts r ON r.id = ra.receipt_id
          WHERE r.client_id=? AND r.receipt_date < ?
        ),0) AS opening
    ");
    $openBefore->execute([$client_id,$from,$client_id,$from]);
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
    $st->execute([$client_id,$from,$to,$client_id,$from,$to]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    // Compute running balance and totals
    $cards['invoice_count'] = 0;
    $totals = ['debit'=>0.0,'credit'=>0.0,'balance'=>$running];

    // Prepend opening row
    $ledger = [[
      'row_date'=>$from,
      'ref'     =>'Opening',
      'kind'    =>'Opening',
      'debit'   =>0.00,
      'credit'  =>0.00,
      'note'    =>'Balance brought forward',
      'invoice_id'=>null,'receipt_id'=>null,
      'running' =>$running,
    ]];

    foreach ($rows as $r) {
      $d = (float)$r['debit'];
      $c = (float)$r['credit'];
      $totals['debit']  += $d;
      $totals['credit'] += $c;
      $running += ($d - $c);
      $r['running'] = $running;
      $ledger[] = $r;
      if ($r['kind']==='Invoice') $cards['invoice_count']++;
      $csv[] = [
        $r['row_date'], $r['kind'], $r['ref'],
        money($d), money($c), money($running), (string)$r['note']
      ];
    }
    $totals['balance'] = $running;

    // Swap rows for rendering
    $rows = $ledger;
  }

  /* ---------- Exports ---------- */
  if ($export==='csv') {
    $fn = 'Statement-' . $client_id . '-' . $from . '-to-' . $to . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"$fn\"");
    $out = fopen('php://output','w');

    if ($view==='open') {
      fputcsv($out, ['Date','Invoice','Total','Paid','Balance','Payments']);
    } else {
      fputcsv($out, ['Date','Type','Ref','Debit','Credit','Running Balance','Note']);
    }
    foreach ($csv as $line) fputcsv($out, $line);
    fclose($out); exit;
  }

  if ($export==='pdf') {
    require_once __DIR__ . '/../includes/composer_autoload_safe.php';
    herosysgro_composer_autoload_safe();
    // Simple PDF using Dompdf (if available)
    if (class_exists('\Dompdf\Dompdf')) {
      ob_start(); ?>
      <html>
      <head>
        <meta charset="utf-8">
        <style>
          body{font-family:DejaVu Sans,Arial,Helvetica,sans-serif;font-size:12px;color:#0f172a}
          h2{margin:0 0 6px}
          table{width:100%;border-collapse:collapse}
          th,td{padding:6px;border-bottom:1px solid #e5e7eb}
          thead th{border-bottom:2px solid #cbd5e1;text-align:left}
          .num{text-align:right}
        </style>
      </head>
      <body>
        <h2>Statement</h2>
        <div><?= h($from) ?> → <?= h($to) ?></div>
        <div>Open balance: <b><?= money($cards['open_balance']) ?></b></div>
        <br>
        <table>
          <thead>
          <?php if ($view==='open'): ?>
            <tr><th>Date</th><th>Invoice</th><th class="num">Total</th><th class="num">Paid</th><th class="num">Balance</th><th>Payments</th></tr>
          <?php else: ?>
            <tr><th>Date</th><th>Type</th><th>Ref</th><th class="num">Debit</th><th class="num">Credit</th><th class="num">Running</th><th>Note</th></tr>
          <?php endif; ?>
          </thead>
          <tbody>
          <?php if ($view==='open'): foreach ($rows as $r): ?>
            <tr>
              <td><?= h($r['issue_date']) ?></td>
              <td><?= h($r['invoice_no']) ?></td>
              <td class="num"><?= money($r['total']) ?></td>
              <td class="num"><?= money($r['amount_paid']) ?></td>
              <td class="num"><?= money($r['balance_due']) ?></td>
              <td><?= h($r['payments'] ?? '') ?></td>
            </tr>
          <?php endforeach; else: foreach ($rows as $r): ?>
            <tr>
              <td><?= h($r['row_date']) ?></td>
              <td><?= h($r['kind']) ?></td>
              <td><?= h($r['ref']) ?></td>
              <td class="num"><?= money($r['debit']) ?></td>
              <td class="num"><?= money($r['credit']) ?></td>
              <td class="num"><?= money($r['running']) ?></td>
              <td><?= h($r['note']) ?></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </body>
      </html>
      <?php
      $html = ob_get_clean();

      $tmpDir = __DIR__ . '/storage/dompdf_tmp';
      if (!is_dir($tmpDir)) @mkdir($tmpDir, 0777, true);

      $opt = new \Dompdf\Options();
      $opt->set('isHtml5ParserEnabled', true);
      $opt->set('isRemoteEnabled', true);
      $opt->set('tempDir', $tmpDir);
      $opt->set('fontCache', $tmpDir);

      $dom = new \Dompdf\Dompdf($opt);
      $dom->loadHtml($html, 'UTF-8');
      $dom->setPaper('A4', 'portrait');
      $dom->render();

      $pdf = $dom->output();
      $fn  = 'Statement-' . $client_id . '-' . $from . '-to-' . $to . '.pdf';
      header('Content-Type: application/pdf');
      header("Content-Disposition: attachment; filename=\"$fn\"");
      echo $pdf; exit;
    } else {
      // No Dompdf: fall back to CSV to ensure export still works
      header('Location: ' . strtok($_SERVER["REQUEST_URI"],'?') . '?' . http_build_query($_GET + ['export'=>'csv']));
      exit;
    }
  }
}
?>
<!-- ==================== UI ==================== -->

<form class="row g-2 mb-3">
  <input type="hidden" name="tab" value="statements">
  <div class="col-md-4">
    <select class="form-select" name="client_id" required>
      <option value="">Select client…</option>
      <?php foreach ($clients as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= $client_id===$c['id']?'selected':'' ?>>
          <?= h($c['client_name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-2"><input type="date" class="form-control" name="from" value="<?= h($from) ?>"></div>
  <div class="col-md-2"><input type="date" class="form-control" name="to"   value="<?= h($to) ?>"></div>

  <div class="col-md-2">
    <select class="form-select" name="view" onchange="this.form.submit()">
      <option value="open"   <?= $view==='open'?'selected':'' ?>>Open invoices</option>
      <option value="ledger" <?= $view==='ledger'?'selected':'' ?>>Ledger (with running balance)</option>
    </select>
  </div>

  <div class="col-md-2 d-flex gap-2">
    <button class="btn btn-primary flex-grow-1">Show</button>
    <?php if ($client_id): ?>
        <a class="btn btn-outline-secondary"
           href="accounts/statements_export.php?client_id=<?= (int)$client_id ?>&from=<?= h($from) ?>&to=<?= h($to) ?>&view=<?= h($view) ?><?= $include_paid ? '&incl_paid=1' : '' ?>"
           target="_blank">PDF</a>
        <a class="btn btn-outline-dark ms-2"
           href="accounts/soa_export.php?client_id=<?= (int)$client_id ?>&from=<?= h($from) ?>&to=<?= h($to) ?>&view=<?= h($view ?? 'open') ?>">
          SOA
        </a>
        <button class="btn btn-info ms-2" data-bs-toggle="modal" data-bs-target="#emailModal">
          <i class="bi bi-envelope"></i> Email
        </button>
    <?php endif; ?>
  </div>

  <?php if ($view==='open'): ?>
    <div class="col-12">
      <div class="form-check mt-1">
        <input class="form-check-input" type="checkbox" id="incPaid" name="include_paid" value="1" <?= $include_paid?'checked':'' ?>>
        <label for="incPaid" class="form-check-label">Include fully paid invoices</label>
      </div>
    </div>
  <?php endif; ?>
</form>

<?php if ($client_id): ?>
  <!-- Summary cards -->
  <div class="row g-3 mb-3">
    <div class="col-sm-6 col-md-3">
      <div class="card shadow-sm">
        <div class="card-body">
          <div class="text-muted small">Open Balance</div>
          <div class="fs-5 fw-bold">AED <?= money($cards['open_balance']) ?></div>
        </div>
      </div>
    </div>
    <div class="col-sm-6 col-md-3">
      <div class="card shadow-sm">
        <div class="card-body">
          <div class="text-muted small"><?= $view==='open' ? 'Invoices in range' : 'Invoices in ledger' ?></div>
          <div class="fs-5 fw-bold"><?= (int)$cards['invoice_count'] ?></div>
        </div>
      </div>
    </div>
    <div class="col-sm-6 col-md-3">
      <div class="card shadow-sm">
        <div class="card-body">
          <div class="text-muted small">Last Payment</div>
          <div class="fs-6 fw-semibold"><?= $cards['last_payment'] ? h($cards['last_payment']) : '—' ?></div>
        </div>
      </div>
    </div>
    <div class="col-sm-6 col-md-3">
      <div class="card shadow-sm">
        <div class="card-body">
          <div class="text-muted small">Paid in Period</div>
          <div class="fs-5 fw-bold">AED <?= money($cards['paid_in_range']) ?></div>
        </div>
      </div>
    </div>
  </div>

  <div class="table-responsive">
    <table class="table table-striped align-middle">
      <thead class="table-light">
      <?php if ($view==='open'): ?>
        <tr>
          <th>Date</th>
          <th>Invoice</th>
          <th class="text-end">Total</th>
          <th class="text-end">Paid</th>
          <th class="text-end">Balance</th>
          <th>Payments</th>
          <th></th>
        </tr>
      <?php else: ?>
        <tr>
          <th>Date</th>
          <th>Type</th>
          <th>Ref</th>
          <th class="text-end">Debit</th>
          <th class="text-end">Credit</th>
          <th class="text-end">Running</th>
          <th>Note</th>
        </tr>
      <?php endif; ?>
      </thead>
      <tbody>
      <?php if ($view==='open'): ?>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= h($r['issue_date']) ?></td>
            <td><?= h($r['invoice_no']) ?></td>
            <td class="text-end"><?= money($r['total']) ?></td>
            <td class="text-end"><?= money($r['amount_paid']) ?></td>
            <td class="text-end"><?= money($r['balance_due']) ?></td>
            <td>
              <?php if (!empty($r['payments'])): ?>
                <span class="badge text-bg-success">Paid</span>
                <span class="ms-2 small text-muted"><?= h($r['payments']) ?></span>
              <?php else: ?>
                <span class="badge text-bg-warning">No payments</span>
              <?php endif; ?>
            </td>
            <td class="text-end">
              <a class="btn btn-sm btn-outline-primary" href="accounts/invoice_view.php?id=<?= (int)$r['invoice_id'] ?>">Open</a>
            </td>
          </tr>
        <?php endforeach; if (!$rows): ?>
          <tr><td colspan="7" class="text-center text-muted">No invoices in this period.</td></tr>
        <?php endif; ?>
      <?php else: ?>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= h($r['row_date']) ?></td>
            <td><?= h($r['kind']) ?></td>
            <td><?= h($r['ref']) ?></td>
            <td class="text-end"><?= money($r['debit']) ?></td>
            <td class="text-end"><?= money($r['credit']) ?></td>
            <td class="text-end fw-semibold"><?= money($r['running']) ?></td>
            <td><?= h($r['note']) ?></td>
          </tr>
        <?php endforeach; ?>
        <tr class="table-light">
          <td colspan="3" class="text-end fw-semibold">Totals</td>
          <td class="text-end fw-semibold">AED <?= money($totals['debit']) ?></td>
          <td class="text-end fw-semibold">AED <?= money($totals['credit']) ?></td>
          <td class="text-end fw-bold">AED <?= money($totals['balance']) ?></td>
          <td></td>
        </tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<!-- Email Modal -->
<div class="modal fade" id="emailModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" id="emailForm">
      <div class="modal-header">
        <h5 class="modal-title">Send Statement Email</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="client_id" value="<?= (int)$client_id ?>">
        <input type="hidden" name="from_date" value="<?= h($from) ?>">
        <input type="hidden" name="to_date" value="<?= h($to) ?>">
        
        <div class="mb-3">
          <label class="form-label">Recipient Email</label>
          <input type="email" class="form-control" name="recipient_email" id="recipient_email" required>
          <div class="form-text">Enter the email address to send the statement to</div>
        </div>
        
        <div class="mb-3">
          <label class="form-label">Email Template</label>
          <select class="form-select" name="template_id" id="template_id">
            <option value="">Use Default Template</option>
            <!-- Templates will be loaded via AJAX -->
          </select>
        </div>
        
        <div class="mb-3">
          <label class="form-label">Custom Message (Optional)</label>
          <textarea class="form-control" name="custom_message" id="custom_message" rows="3" 
                    placeholder="Add a personal message to include with the statement..."></textarea>
        </div>
        
        <div class="text-danger small" id="emailErr" style="display:none;"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary">
          <i class="bi bi-envelope"></i> Send Statement
        </button>
      </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Email functionality
document.getElementById('emailForm')?.addEventListener('submit', function(e) {
  e.preventDefault();
  const fd = new FormData(this);
  document.getElementById('emailErr').style.display = 'none';

  fetch('ajax/send_statement_email.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(j => {
      if (j.success) {
        alert('Statement sent successfully!');
        bootstrap.Modal.getInstance(document.getElementById('emailModal')).hide();
      } else {
        document.getElementById('emailErr').textContent = j.error || 'Error sending statement';
        document.getElementById('emailErr').style.display = '';
      }
    })
    .catch(() => {
      document.getElementById('emailErr').textContent = 'Server error';
      document.getElementById('emailErr').style.display = '';
    });
});

// Load email templates when modal is shown
document.getElementById('emailModal')?.addEventListener('show.bs.modal', function() {
  const templateSelect = document.getElementById('template_id');
  if (templateSelect.children.length <= 1) { // Only has default option
    fetch('ajax/get_email_templates.php?type=statement')
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          data.templates.forEach(template => {
            const option = document.createElement('option');
            option.value = template.id;
            option.textContent = template.template_name;
            templateSelect.appendChild(option);
          });
        }
      })
      .catch(error => console.error('Error loading templates:', error));
  }
});
</script>
</body>
</html>
