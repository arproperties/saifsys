<?php
/**
 * Post-dated cheques — the list: four numbers, filters, the table, change status, CSV.
 *   pdc.php?dir=issued&status=pending&month=2026-12&q=…
 *   pdc.php?csv=1 (same filters)   pdc.php?file=7 (the cheque's copy)
 * Owner, Admin and HR.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/url_helper.php';
require_once __DIR__ . '/includes/lt_pdc.php';

$companyId = lt_boot($conn);
$ltBase = get_application_web_root() . '/modules/loan_tracker';
$dirs = lt_pdc_directions();
$statuses = lt_pdc_statuses();
$today = date('Y-m-d');

// The cheque's copy
if (isset($_GET['file'])) {
    $p = lt_pdc_get($conn, (int)$_GET['file'], $companyId);
    $abs = $p && $p['file_path'] ? lt_doc_abs_path(['source' => 'upload', 'file_path' => $p['file_path']]) : '';
    $type = $abs !== '' && is_file($abs) ? (new finfo(FILEINFO_MIME_TYPE))->file($abs) : '';
    if (!isset(LT_FILE_TYPES[$type])) {
        http_response_code(404);
        exit('Not found');
    }
    header('Content-Type: ' . $type);
    header('Content-Length: ' . filesize($abs));
    header('Content-Disposition: inline; filename="cheque-' . (int)$p['id'] . '.' . LT_FILE_TYPES[$type] . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=600');
    readfile($abs);
    exit;
}

// Filters
$f = [
    'dir' => isset($dirs[$_GET['dir'] ?? '']) ? $_GET['dir'] : '',
    'status' => isset($statuses[$_GET['status'] ?? '']) || in_array($_GET['status'] ?? '', ['all', 'open'], true) || str_starts_with((string)($_GET['status'] ?? ''), 'custom:')
        ? mb_substr((string)$_GET['status'], 0, 70) : 'all',
    'month' => preg_match('/^\d{4}-\d{2}$/', (string)($_GET['month'] ?? '')) ? $_GET['month'] : '',
    'q' => mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100),
    'due' => ($_GET['due'] ?? '') === '7' ? '7' : '',
];
$qs = static function (array $over = []) use ($f): string {
    $a = array_filter(array_merge($f, $over), static fn($v) => $v !== '' && $v !== null);
    return $a ? '?' . http_build_query($a) : '';
};
$self = $ltBase . '/pdc.php' . $qs();

// Email alerts: the list of addresses, and "Check now"
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['do'] ?? '', ['alert_add', 'alert_remove', 'alert_set', 'alert_run'], true)) {
    csrf_verify();
    $do = $_POST['do'];
    if ($do === 'alert_add') {
        $added = 0; $bad = [];
        foreach (preg_split('/[\s,;]+/', (string)($_POST['emails'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $e) {
            if (!filter_var($e, FILTER_VALIDATE_EMAIL) || strlen($e) > 150) { $bad[] = $e; continue; }
            $ins = $conn->prepare("INSERT IGNORE INTO lt_pdc_alert_recipients (company_id, email, on_due, on_bounce, created_by) VALUES (?,?,?,?,?)");
            $ins->execute([$companyId, strtolower($e), !empty($_POST['on_due']) ? 1 : 0, !empty($_POST['on_bounce']) ? 1 : 0, current_user_id()]);
            $added += $ins->rowCount();
        }
        lt_flash($bad ? 'Not an email address: ' . implode(', ', $bad) . ($added ? ' (the others were added).' : '') : ($added ? $added . ' address' . ($added === 1 ? '' : 'es') . ' added to the alerts.' : 'Those addresses are already on the list.'), $bad ? 'warning' : 'success');
    } elseif ($do === 'alert_remove') {
        $conn->prepare("DELETE FROM lt_pdc_alert_recipients WHERE id = ? AND company_id = ?")->execute([(int)($_POST['rid'] ?? 0), $companyId]);
        lt_flash('Address removed from the alerts.');
    } elseif ($do === 'alert_set') {
        $col = ($_POST['what'] ?? '') === 'bounce' ? 'on_bounce' : 'on_due';
        $conn->prepare("UPDATE lt_pdc_alert_recipients SET $col = ? WHERE id = ? AND company_id = ?")->execute([!empty($_POST['on']) ? 1 : 0, (int)($_POST['rid'] ?? 0), $companyId]);
    } else {
        $res = lt_pdc_run_due_alerts($conn);
        $sent = count(array_filter($res, static fn($r) => $r['result'] === 'sent'));
        lt_flash($res ? $sent . ' alert' . ($sent === 1 ? '' : 's') . ' emailed' . (count($res) > $sent ? ', ' . (count($res) - $sent) . ' not sent (no address, or email failed)' : '') . '.' : 'Nothing new to send: every cheque due within 7 days has already been alerted.');
    }
    header('Location: ' . $ltBase . '/pdc.php#alerts');
    exit;
}

// Change status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'status') {
    csrf_verify();
    $back = (string)($_POST['back'] ?? '');
    $back = str_starts_with($back, $ltBase . '/pdc') ? $back : $ltBase . '/pdc.php';
    $p = lt_pdc_get($conn, (int)($_POST['id'] ?? 0), $companyId);
    $choice = lt_pdc_parse_status((string)($_POST['to'] ?? ''), (string)($_POST['to_other'] ?? ''));
    $on = (string)($_POST['on'] ?? '') ?: $today;
    $note = trim((string)($_POST['note'] ?? ''));
    if (!$p) {
        lt_flash('That cheque was not found.', 'warning');
    } elseif (!$choice) {
        lt_flash(($_POST['to'] ?? '') === 'other' ? 'Please type the name of the new status.' : 'Please choose the new status.', 'danger');
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $on) || $on > $today) {
        lt_flash('Please enter the date of the change (not in the future).', 'danger');
    } elseif ($choice[0] === $p['status'] && strcasecmp((string)$choice[1], (string)$p['status_other']) === 0) {
        lt_flash('The cheque is already ' . lt_pdc_label($choice[0], $choice[1]) . '.', 'info');
    } else {
        $conn->beginTransaction();
        $histId = lt_pdc_set_status($conn, $p, $choice[0], $choice[1], $on, $note);
        $conn->commit();
        $msg = 'Cheque ' . $p['cheque_no'] . ' is now ' . lt_pdc_label($choice[0], $choice[1]) . '.';
        if ($choice[0] === 'bounced') {
            try {
                $r = lt_pdc_send_alert($conn, lt_pdc_get($conn, (int)$p['id'], $companyId), 'bounced', 'h' . $histId, $note);
                $msg .= match ($r['result']) {
                    'sent' => ' Alert emailed to ' . implode(', ', $r['to']) . '.',
                    'no_recipients' => ' No alert sent: no email addresses are set up.',
                    'failed' => ' The alert email could not be sent.',
                    default => '',
                };
            } catch (Throwable $e) {
                error_log('pdc bounce alert: ' . $e->getMessage());
            }
        }
        lt_flash($msg);
    }
    header('Location: ' . $back);
    exit;
}

// The rows
$where = ['p.company_id = ?'];
$args = [$companyId];
if ($f['dir'] !== '') { $where[] = 'p.direction = ?'; $args[] = $f['dir']; }
if (str_starts_with($f['status'], 'custom:')) { $where[] = "p.status = 'other' AND p.status_other = ?"; $args[] = substr($f['status'], 7); }
elseif ($f['status'] === 'open') { $where[] = "p.status IN ('pending','deposited')"; }
elseif ($f['status'] !== 'all') { $where[] = 'p.status = ?'; $args[] = $f['status']; }
if ($f['month'] !== '') { $where[] = "DATE_FORMAT(p.cheque_date, '%Y-%m') = ?"; $args[] = $f['month']; }
if ($f['due'] === '7') { $where[] = 'p.cheque_date <= ?'; $args[] = date('Y-m-d', strtotime('+7 days')); }
if ($f['q'] !== '') {
    $where[] = '(p.party_name LIKE ? OR p.cheque_no LIKE ? OR p.bank LIKE ? OR p.purpose LIKE ? OR p.reference LIKE ? OR p.location LIKE ? OR p.issued_by LIKE ?)';
    array_push($args, ...array_fill(0, 7, '%' . $f['q'] . '%'));
}
$q = $conn->prepare("SELECT p.* FROM lt_pdc p WHERE " . implode(' AND ', $where) . " ORDER BY p.cheque_date, p.id");
$q->execute($args);
$rows = $q->fetchAll(PDO::FETCH_ASSOC);

if (isset($_GET['csv'])) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="post-dated-cheques-' . date('Y-m-d') . '.csv"');
    header('Cache-Control: private, no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Cheque date', 'Cheque no', 'Issued / received', 'Party', 'Bank', 'Amount (AED)', 'Status', 'Status date', 'Reason', 'Location', 'Reference', 'Issued by', 'Approved by', 'Notes']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['cheque_date'], $r['cheque_no'], $dirs[$r['direction']]['long'], $r['party_name'], $r['bank'],
            number_format((float)$r['amount'], 2, '.', ''), lt_pdc_label($r['status'], $r['status_other']), $r['status_date'],
            $r['purpose'], $r['location'], $r['reference'], $r['issued_by'], $r['approved_by'], $r['notes']]);
    }
    fclose($out);
    exit;
}

// The four numbers (all pending cheques, whatever the filter)
$s = $conn->prepare("SELECT
        COALESCE(SUM(CASE WHEN status = 'pending' AND direction = 'issued' THEN amount END), 0) AS out_amt,
        COUNT(CASE WHEN status = 'pending' AND direction = 'issued' THEN 1 END) AS out_n,
        COALESCE(SUM(CASE WHEN status IN ('pending','deposited') AND direction = 'received' THEN amount END), 0) AS in_amt,
        COUNT(CASE WHEN status IN ('pending','deposited') AND direction = 'received' THEN 1 END) AS in_n,
        COALESCE(SUM(CASE WHEN status = 'pending' AND cheque_date <= ? THEN amount END), 0) AS due_amt,
        COUNT(CASE WHEN status = 'pending' AND cheque_date <= ? THEN 1 END) AS due_n,
        COUNT(CASE WHEN status = 'bounced' THEN 1 END) AS bounced_n,
        COALESCE(SUM(CASE WHEN status = 'bounced' THEN amount END), 0) AS bounced_amt
      FROM lt_pdc WHERE company_id = ?");
$week = date('Y-m-d', strtotime('+7 days'));
$s->execute([$week, $week, $companyId]);
$sum = $s->fetch(PDO::FETCH_ASSOC);
$months = $conn->prepare("SELECT DISTINCT DATE_FORMAT(cheque_date, '%Y-%m') FROM lt_pdc WHERE company_id = ? ORDER BY 1 DESC");
$months->execute([$companyId]);
$months = $months->fetchAll(PDO::FETCH_COLUMN);
$customs = lt_pdc_custom_statuses($conn, $companyId);
$alertRcpt = $conn->prepare("SELECT * FROM lt_pdc_alert_recipients WHERE company_id = ? ORDER BY email");
$alertRcpt->execute([$companyId]);
$alertRcpt = $alertRcpt->fetchAll(PDO::FETCH_ASSOC);
$alertLog = $conn->prepare("SELECT a.*, p.cheque_no, p.party_name FROM lt_pdc_alert_log a JOIN lt_pdc p ON p.id = a.pdc_id WHERE p.company_id = ? ORDER BY a.id DESC LIMIT 8");
$alertLog->execute([$companyId]);
$alertLog = $alertLog->fetchAll(PDO::FETCH_ASSOC);
lt_pdc_daily_alerts_after_response($conn);
$total = array_sum(array_map(static fn($r) => (float)$r['amount'], $rows));

$pageTitle = 'Post Dated Cheques';
$pageStyles = '
  .lt-big{ border-radius:14px; padding:1rem 1.2rem; height:100%; display:block; text-decoration:none; transition:transform .15s, box-shadow .15s; }
  a.lt-big:hover{ transform:translateY(-2px); box-shadow:0 6px 16px rgba(0,0,0,.08); }
  a.lt-big{ border:2px solid transparent; cursor:pointer; position:relative; }
  a.lt-big.on{ border-color:currentColor; box-shadow:0 6px 16px rgba(0,0,0,.08); }
  a.lt-big .lt-go{ position:absolute; right:1rem; bottom:.8rem; font-size:.75rem; font-weight:600; opacity:.75; }
  a.lt-big.on .lt-go{ opacity:1; }
  .lt-big small{ display:block; font-size:.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; opacity:.85; }
  .lt-big b{ font-size:1.35rem; font-variant-numeric:tabular-nums; }
  .lt-when{ font-size:.72rem; font-weight:600; }
  .lt-when.soon{ color:#b45309; } .lt-when.late{ color:#b91c1c; }
  .lt-pdc-table td.num{ text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap; }
  .lt-pdc-table thead th{ position:sticky; top:0; background:#fff; z-index:1; }
';
require __DIR__ . '/includes/lt_layout_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
  <div>
    <h1 class="lt-title">Post Dated Cheques</h1>
    <p class="lt-sub">Cheques we give and cheques we receive, with their status.</p>
  </div>
  <div class="d-flex gap-2">
    <button type="button" class="btn btn-lt-ghost" data-bs-toggle="modal" data-bs-target="#alerts"><i class="bi bi-envelope me-1"></i> Email alerts<?= $alertRcpt ? ' (' . count($alertRcpt) . ')' : '' ?></button>
    <a href="<?= h($ltBase) ?>/pdc_form.php" class="btn btn-lt"><i class="bi bi-plus-lg me-1"></i> Add cheque</a>
  </div>
</div>

<div class="row g-3 mb-4">
  <?php
  // Each card is a filter; the one matching the current filter is outlined. Clicking it again clears it.
  $cardDefs = [
      ['f' => ['status' => 'pending', 'dir' => 'issued', 'month' => '', 'due' => ''], 'bg' => 'var(--lt-tint-2)', 'fg' => 'var(--primary-dark)', 'label' => 'To pay (issued, pending)', 'amt' => $sum['out_amt'], 'n' => $sum['out_n'], 'extra' => ''],
      ['f' => ['status' => 'open', 'dir' => 'received', 'month' => '', 'due' => ''], 'bg' => '#dcfce7', 'fg' => '#166534', 'label' => 'To collect (received, pending / deposited)', 'amt' => $sum['in_amt'], 'n' => $sum['in_n'], 'extra' => ''],
      ['f' => ['status' => 'pending', 'dir' => '', 'month' => '', 'due' => '7'], 'bg' => '#fef3c7', 'fg' => '#92400e', 'label' => 'Due in the next 7 days', 'amt' => $sum['due_amt'], 'n' => $sum['due_n'], 'extra' => ' (incl. overdue)'],
      ['f' => ['status' => 'bounced', 'dir' => '', 'month' => '', 'due' => ''], 'bg' => '#fee2e2', 'fg' => '#991b1b', 'label' => 'Bounced', 'amt' => $sum['bounced_amt'], 'n' => $sum['bounced_n'], 'extra' => ''],
  ];
  foreach ($cardDefs as $c):
      $on = $f['status'] === $c['f']['status'] && $f['dir'] === $c['f']['dir'] && $f['due'] === $c['f']['due'] && $f['month'] === '';
      $href = $on ? $qs(['status' => 'all', 'dir' => '', 'due' => '']) : $qs($c['f']); ?>
    <div class="col-6 col-lg-3"><a class="lt-big <?= $on ? 'on' : '' ?>" href="<?= h($href !== '' ? $href : '?') ?>" style="background:<?= $c['bg'] ?>;color:<?= $c['fg'] ?>" title="<?= $on ? 'Showing these · click to show all' : 'Click to show these cheques' ?>">
      <small><?= h($c['label']) ?></small><b>AED <?= lt_money($c['amt']) ?></b>
      <div class="small"><?= (int)$c['n'] ?> cheque<?= (int)$c['n'] === 1 ? '' : 's' ?><?= h($c['extra']) ?></div>
      <span class="lt-go"><?= $on ? '<i class="bi bi-check2"></i> Showing' : 'View <i class="bi bi-arrow-right"></i>' ?></span>
    </a></div>
  <?php endforeach; ?>
</div>

<div class="card card-round">
  <div class="card-body p-3 border-bottom">
    <form class="row g-2 align-items-end" method="get">
      <div class="col-6 col-md-2">
        <label class="form-label small fw-semibold mb-1">Type</label>
        <select class="form-select form-select-sm" name="dir" onchange="this.form.submit()">
          <option value="">All</option>
          <?php foreach ($dirs as $k => $d): ?><option value="<?= h($k) ?>" <?= $f['dir'] === $k ? 'selected' : '' ?>><?= h($d['long']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label small fw-semibold mb-1">Status</label>
        <select class="form-select form-select-sm" name="status" onchange="this.form.submit()">
          <option value="all" <?= $f['status'] === 'all' ? 'selected' : '' ?>>All</option>
          <option value="open" <?= $f['status'] === 'open' ? 'selected' : '' ?>>Pending or deposited</option>
          <?php foreach ($statuses as $k => $st): ?><option value="<?= h($k) ?>" <?= $f['status'] === $k ? 'selected' : '' ?>><?= h($k === 'other' ? 'Other (all)' : $st['label']) ?></option><?php endforeach; ?>
          <?php foreach ($customs as $c): ?><option value="custom:<?= h($c) ?>" <?= $f['status'] === 'custom:' . $c ? 'selected' : '' ?>>&nbsp;&nbsp;<?= h($c) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label small fw-semibold mb-1">Cheque month</label>
        <select class="form-select form-select-sm" name="month" onchange="this.form.submit()">
          <option value="">All months</option>
          <?php foreach ($months as $m): ?><option value="<?= h($m) ?>" <?= $f['month'] === $m ? 'selected' : '' ?>><?= h(date('M Y', strtotime($m . '-01'))) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label small fw-semibold mb-1">Search</label>
        <input type="search" class="form-control form-control-sm" name="q" value="<?= h($f['q']) ?>" placeholder="Name, cheque no., bank…">
      </div>
      <div class="col-12 col-md-3 d-flex gap-2 justify-content-md-end">
        <button class="btn btn-lt-ghost btn-sm"><i class="bi bi-search"></i></button>
        <a class="btn btn-lt-ghost btn-sm" href="<?= h($ltBase) ?>/pdc.php"><i class="bi bi-arrow-counterclockwise me-1"></i>Reset filters</a>
        <a class="btn btn-lt-ghost btn-sm" href="<?= h($ltBase . '/pdc.php' . $qs(['csv' => '1'])) ?>"><i class="bi bi-download me-1"></i>CSV</a>
      </div>
    </form>
  </div>
  <div class="table-responsive" style="max-height:70vh">
    <table class="table lt-table lt-pdc-table">
      <thead><tr>
        <th>Cheque date</th><th>Cheque no.</th><th>Type</th><th>Party</th><th>Bank</th><th class="text-end">Amount (AED)</th><th>Status</th><th class="text-end"></th>
      </tr></thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="8" class="text-center text-muted py-5">No cheques here. <a href="<?= h($ltBase) ?>/pdc_form.php">Add a cheque</a></td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $r): $w = $r['status'] === 'pending' ? lt_pdc_when($r['cheque_date']) : ['text' => '', 'class' => '']; ?>
        <tr>
          <td><?= h(lt_date($r['cheque_date'])) ?><?php if ($w['text'] !== ''): ?><div class="lt-when <?= h($w['class']) ?>"><?= h($w['text']) ?></div><?php endif; ?></td>
          <td class="fw-semibold"><?= h($r['cheque_no']) ?>
            <?php if ($r['file_path']): ?><a href="<?= h($ltBase) ?>/pdc.php?file=<?= (int)$r['id'] ?>" target="_blank" rel="noopener" title="Cheque copy"><i class="bi bi-paperclip"></i></a><?php endif; ?></td>
          <td><span class="small"><i class="bi <?= h($dirs[$r['direction']]['icon']) ?> me-1"></i><?= h($dirs[$r['direction']]['label']) ?></span></td>
          <td><?= h($r['party_name']) ?><?php if ($r['purpose'] || $r['location']): ?><div class="small text-muted"><?= h(implode(' · ', array_filter([$r['purpose'], $r['location'] ? '📍 ' . $r['location'] : '']))) ?></div><?php endif; ?><?php if ($r['issued_by'] || $r['approved_by']): ?><div class="small text-muted"><i class="bi bi-person-check me-1"></i><?= h(implode(' · ', array_filter([$r['issued_by'] ? 'Issued by ' . $r['issued_by'] : '', $r['approved_by'] ? 'Approved by ' . $r['approved_by'] : '']))) ?></div><?php endif; ?></td>
          <td class="small"><?= h($r['bank'] ?: '—') ?></td>
          <td class="num fw-semibold"><?= lt_money($r['amount']) ?></td>
          <td><?= lt_pdc_badge($r['status'], $r['status_other']) ?><?php if ($r['status_date'] && $r['status'] !== 'pending'): ?><div class="small text-muted"><?= h(lt_date($r['status_date'])) ?></div><?php endif; ?></td>
          <td class="text-end text-nowrap">
            <button type="button" class="btn btn-sm btn-lt-ghost" data-bs-toggle="modal" data-bs-target="#stModal"
                    data-id="<?= (int)$r['id'] ?>" data-no="<?= h($r['cheque_no']) ?>" data-status="<?= h($r['status']) ?>"><i class="bi bi-arrow-repeat me-1"></i>Status</button>
            <a class="btn btn-sm btn-lt-ghost" href="<?= h($ltBase) ?>/pdc_form.php?id=<?= (int)$r['id'] ?>"><i class="bi bi-pencil"></i></a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <?php if ($rows): ?>
        <tfoot><tr><td colspan="5" class="fw-semibold"><?= count($rows) ?> cheque<?= count($rows) === 1 ? '' : 's' ?></td><td class="text-end fw-bold" style="font-variant-numeric:tabular-nums"><?= lt_money($total) ?></td><td colspan="2"></td></tr></tfoot>
      <?php endif; ?>
    </table>
  </div>
</div>

<div class="modal fade" id="alerts" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title"><i class="bi bi-envelope me-1"></i>Email alerts · overdue / bounced</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body p-4">
  <div class="text-muted small mb-3">
    An email goes to <b>the person who added the cheque</b> and to the addresses below:
    when a pending cheque is <b>due within 7 days</b> (once), again if it becomes <b>overdue</b> (once), and when a cheque is set to <b>Bounced</b>.
    The check runs by itself once a day.
  </div>
  <form method="post" class="row g-2 align-items-end mb-3">
    <?php csrf_field(); ?><input type="hidden" name="do" value="alert_add">
    <div class="col-md-6">
      <label class="form-label small fw-semibold" for="alertEmails">Add email addresses</label>
      <input type="text" class="form-control" id="alertEmails" name="emails" placeholder="name@company.com, other@company.com" required>
    </div>
    <div class="col-md-4 small">
      <div class="form-check"><input class="form-check-input" type="checkbox" name="on_due" value="1" id="aDue" checked><label class="form-check-label" for="aDue">Due in 7 days / overdue</label></div>
      <div class="form-check"><input class="form-check-input" type="checkbox" name="on_bounce" value="1" id="aBounce" checked><label class="form-check-label" for="aBounce">Bounced</label></div>
    </div>
    <div class="col-md-2"><button class="btn btn-lt w-100"><i class="bi bi-plus-lg me-1"></i>Add</button></div>
  </form>

  <?php if ($alertRcpt): ?>
    <div class="table-responsive mb-3">
      <table class="table lt-table">
        <thead><tr><th>Email</th><th class="text-center">Due / overdue</th><th class="text-center">Bounced</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($alertRcpt as $a): ?>
          <tr>
            <td><?= h($a['email']) ?></td>
            <?php foreach (['due' => 'on_due', 'bounce' => 'on_bounce'] as $what => $col): ?>
              <td class="text-center">
                <form method="post" class="m-0"><?php csrf_field(); ?><input type="hidden" name="do" value="alert_set"><input type="hidden" name="rid" value="<?= (int)$a['id'] ?>"><input type="hidden" name="what" value="<?= $what ?>">
                  <input type="hidden" name="on" value="<?= (int)$a[$col] ? 0 : 1 ?>">
                  <button class="btn btn-sm <?= (int)$a[$col] ? 'btn-success' : 'btn-outline-secondary' ?> py-0" title="Click to switch"><?= (int)$a[$col] ? '<i class="bi bi-check-lg"></i> On' : 'Off' ?></button>
                </form>
              </td>
            <?php endforeach; ?>
            <td class="text-end">
              <form method="post" class="m-0" onsubmit="return confirm('Remove <?= h(addslashes($a['email'])) ?> from the alerts?');"><?php csrf_field(); ?><input type="hidden" name="do" value="alert_remove"><input type="hidden" name="rid" value="<?= (int)$a['id'] ?>">
                <button class="btn btn-sm btn-outline-danger py-0"><i class="bi bi-x-lg"></i></button></form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <div class="alert alert-light border small">No addresses yet. Alerts go only to the person who added each cheque.</div>
  <?php endif; ?>

  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
    <form method="post" class="m-0" onsubmit="return confirm('Email the alerts now for cheques due within 7 days or overdue that have not been alerted yet?');">
      <?php csrf_field(); ?><input type="hidden" name="do" value="alert_run">
      <button class="btn btn-lt-ghost btn-sm"><i class="bi bi-send me-1"></i>Check now and send</button>
    </form>
  </div>

  <?php if ($alertLog): ?>
    <div class="small fw-semibold mt-3 mb-1">Last alerts</div>
    <?php foreach ($alertLog as $l): ?>
      <div class="d-flex flex-wrap gap-2 py-1 border-top small">
        <span class="text-muted" style="min-width:120px"><?= h(date('d M Y H:i', strtotime($l['created_at']))) ?></span>
        <span><?= ['due_soon' => 'Due soon', 'overdue' => 'Overdue', 'bounced' => 'Bounced'][$l['kind']] ?> · cheque <?= h($l['cheque_no']) ?> (<?= h($l['party_name']) ?>)</span>
        <span class="flex-grow-1 text-muted"><?= $l['result'] === 'sent' ? 'to ' . h($l['sent_to']) : ($l['result'] === 'no_recipients' ? 'no address to send to' : 'not sent: ' . h($l['error'] ?? '')) ?></span>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
  </div>
  </div></div>
</div>
<div class="modal fade" id="stModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <form method="post">
      <?php csrf_field(); ?><input type="hidden" name="do" value="status"><input type="hidden" name="id" id="stId">
      <input type="hidden" name="back" value="<?= h($self) ?>">
      <div class="modal-header"><h5 class="modal-title">Change status · cheque <span id="stNo"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label small fw-semibold" for="stTo">New status</label>
          <select class="form-select" name="to" id="stTo" required>
            <?= lt_pdc_status_options($customs) ?>
          </select>
          <input type="text" class="form-control mt-2" name="to_other" id="stOther" maxlength="60" placeholder="Type the new status, e.g. With legal" hidden>
          <div class="form-text" id="stOtherHelp" hidden>It is added to the list, so it can be picked next time.</div>
        </div>
        <div class="mb-3">
          <label class="form-label small fw-semibold" for="stOn">Date</label>
          <input type="date" class="form-control" name="on" id="stOn" value="<?= h($today) ?>" max="<?= h($today) ?>" required>
        </div>
        <div>
          <label class="form-label small fw-semibold" for="stNote">Note <span class="text-muted fw-normal">(optional)</span></label>
          <input type="text" class="form-control" name="note" id="stNote" maxlength="255" placeholder="e.g. Deposited at ENBD, bounce reason…">
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-lt-ghost" data-bs-dismiss="modal">Cancel</button><button class="btn btn-lt"><i class="bi bi-check-lg me-1"></i>Save</button></div>
    </form>
  </div></div>
</div>

<?php
$pageScripts = <<<'JS'
<script>
// Back from saving in the Email alerts popup: open it again
if (location.hash === '#alerts') {
  bootstrap.Modal.getOrCreateInstance(document.getElementById('alerts')).show();
  history.replaceState(null, '', location.pathname + location.search);
}
document.getElementById('stTo').addEventListener('change', function () {
  var o = this.value === 'other';
  var box = document.getElementById('stOther');
  box.hidden = !o; box.required = o; document.getElementById('stOtherHelp').hidden = !o;
  if (o) { box.focus(); }
});
document.getElementById('stModal').addEventListener('show.bs.modal', function (e) {
  var b = e.relatedTarget;
  document.getElementById('stId').value = b.dataset.id;
  document.getElementById('stNo').textContent = b.dataset.no;
  var next = { pending: 'deposited', deposited: 'cleared', on_hold: 'deposited' }[b.dataset.status] || b.dataset.status;
  var sel = document.getElementById('stTo');
  sel.value = next === 'other' ? 'pending' : next;
  document.getElementById('stOther').value = '';
  sel.dispatchEvent(new Event('change'));
  document.getElementById('stNote').value = '';
});
</script>
JS;
require __DIR__ . '/includes/lt_layout_footer.php';
