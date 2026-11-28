<?php
/**
 * Company's post-dated cheques — the list: four numbers, filters, the table, change status, CSV.
 *   pdc.php?dir=issued&status=pending&month=2026-12&q=…
 *   pdc.php?csv=1 (same filters)   pdc.php?file=7 (the cheque's copy)
 * Owner, Admin and HR. The cheques are kept in Reem; this page shows them and passes every
 * change on (see includes/lt_pdc.php).
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/url_helper.php';
require_once __DIR__ . '/includes/lt_pdc.php';

$who = lt_pdc_boot($conn);
$ltBase = get_application_web_root() . '/modules/loan_tracker';
$dirs = lt_pdc_directions();
$statuses = lt_pdc_statuses();
$today = lt_pdc_today();

// The cheque's copy: kept in Reem, passed on from here as the person logged in
if (isset($_GET['file'])) {
    $copy = lt_pdc_file($who, (int)$_GET['file']);
    if (!$copy) {
        http_response_code(404);
        exit('Not found');
    }
    [$bytes, $type] = $copy;
    header('Content-Type: ' . $type);
    header('Content-Length: ' . strlen($bytes));
    header('Content-Disposition: inline; filename="cheque-' . (int)$_GET['file'] . '.' . LT_FILE_TYPES[$type] . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=600');
    echo $bytes;
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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['do'] ?? '', ['alert_add', 'alert_remove', 'alert_set', 'alert_run', 'alert_settings'], true)) {
    csrf_verify();
    $do = $_POST['do'];
    $rid = (int)($_POST['rid'] ?? 0);
    try {
        if ($do === 'alert_settings') {
            // When the emails go out. Reem checks what was typed and says what is wrong with it.
            if (!empty($_POST['reset'])) {
                lt_pdc_reem($who, 'PUT', '/alerts/settings', ['reset' => true]);
                lt_flash('The alert timings are back to the usual ones.');
            } else {
                lt_pdc_reem($who, 'PUT', '/alerts/settings', [
                    'enabled' => !empty($_POST['enabled']), 'hour' => (string)($_POST['hour'] ?? ''),
                    'due_days' => (string)($_POST['due_days'] ?? ''), 'overdue_every_days' => (string)($_POST['overdue_every_days'] ?? ''),
                    'deposited_days' => (string)($_POST['deposited_days'] ?? ''), 'bounce_now' => !empty($_POST['bounce_now'])]);
                lt_flash('Alert timings saved.');
            }
        } elseif ($do === 'alert_add') {
            $r = lt_pdc_reem($who, 'POST', '/alerts/recipients', ['emails' => (string)($_POST['emails'] ?? ''),
                'on_due' => !empty($_POST['on_due']), 'on_bounce' => !empty($_POST['on_bounce'])]);
            $added = (int)($r['added'] ?? 0);
            $bad = (array)($r['bad'] ?? []);
            lt_flash($bad ? 'Not an email address: ' . implode(', ', $bad) . ($added ? ' (the others were added).' : '') : ($added ? $added . ' address' . ($added === 1 ? '' : 'es') . ' added to the alerts.' : 'Those addresses are already on the list.'), $bad ? 'warning' : 'success');
        } elseif ($do === 'alert_remove') {
            lt_pdc_reem($who, 'DELETE', '/alerts/recipients/' . $rid);
            lt_flash('Address removed from the alerts.');
        } elseif ($do === 'alert_set') {
            lt_pdc_reem($who, 'PUT', '/alerts/recipients/' . $rid, ['what' => ($_POST['what'] ?? '') === 'bounce' ? 'bounce' : 'due', 'on' => !empty($_POST['on'])]);
        } else {
            $res = (array)(lt_pdc_reem($who, 'POST', '/alerts/run', [], [], 90)['results'] ?? []);
            $sent = count(array_filter($res, static fn($r) => ($r['result'] ?? '') === 'sent'));
            lt_flash($res ? $sent . ' alert' . ($sent === 1 ? '' : 's') . ' emailed' . (count($res) > $sent ? ', ' . (count($res) - $sent) . ' not sent (no address, or email failed)' : '') . '.' : 'Nothing new to send: every reminder that is due has already been emailed.', $res && count($res) > $sent ? 'warning' : 'success');
        }
    } catch (BinvError $e) {
        lt_flash($e->getMessage(), 'danger');
    }
    header('Location: ' . $ltBase . '/pdc.php#alerts');
    exit;
}

// Change status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'status') {
    csrf_verify();
    $back = (string)($_POST['back'] ?? '');
    $back = str_starts_with($back, $ltBase . '/pdc') ? $back : $ltBase . '/pdc.php';
    try {
        $r = lt_pdc_reem($who, 'POST', '/cheques/' . (int)($_POST['id'] ?? 0) . '/status', [
            'to' => (string)($_POST['to'] ?? ''), 'to_other' => (string)($_POST['to_other'] ?? ''),
            'on' => (string)($_POST['on'] ?? ''), 'note' => trim((string)($_POST['note'] ?? '')),
        ]);
        $msg = 'Cheque ' . ($r['cheque']['cheque_no'] ?? '') . ' is now ' . ($r['cheque']['status_label'] ?? '') . '.';
        // A bounced cheque is emailed about at once; Reem says how that went.
        $alert = $r['alert'] ?? null;
        $owed = false; // the status is saved, but somebody has not been told yet
        if (is_array($alert)) {
            $reached = (array)($alert['got'] ?? []);
            $owed = ($alert['result'] ?? '') === 'failed';
            $msg .= match ($alert['result'] ?? '') {
                'sent' => ' Alert emailed to ' . implode(', ', (array)($alert['to'] ?? [])) . '.',
                'no_recipients' => ' No alert sent: no email addresses are set up.',
                'failed' => $reached
                    ? ' The alert email reached ' . implode(', ', $reached) . ' but not everybody. Reem will try the others again.'
                    : ' The alert email could not be sent just now. Reem will try again within the hour.',
                default => '',
            };
        }
        lt_flash($msg, $owed ? 'warning' : 'success');
    } catch (BinvError $e) {
        lt_flash($e->getMessage(), $e->status === 409 ? 'info' : ($e->status === 404 ? 'warning' : 'danger'));
    }
    header('Location: ' . $back);
    exit;
}

// The rows, the four numbers (all pending cheques, whatever the filter) and the Email alerts box
try {
    $data = lt_pdc_reem($who, 'GET', '', null, array_filter(['dir' => $f['dir'], 'status' => $f['status'] === 'all' ? '' : $f['status'],
        'month' => $f['month'], 'q' => $f['q'], 'due' => $f['due']], static fn($v) => $v !== ''));
    $alerts = isset($_GET['csv']) ? [] : lt_pdc_reem($who, 'GET', '/alerts');
} catch (BinvError $e) {
    lt_pdc_stop($e);
}
$rows = (array)($data['cheques'] ?? []);
$sum = (array)($data['summary'] ?? []) + ['out_amt' => 0, 'out_n' => 0, 'in_amt' => 0, 'in_n' => 0, 'due_amt' => 0, 'due_n' => 0, 'bounced_amt' => 0, 'bounced_n' => 0];
$months = (array)($data['months'] ?? []);
$customs = (array)($data['customs'] ?? []);
$total = (float)($data['total'] ?? 0);
$alertRcpt = (array)($alerts['recipients'] ?? []);
$alertLog = (array)($alerts['log'] ?? []);
// When the emails go out: this company's own timings, or the usual ones
$alertSet = (array)($alerts['settings'] ?? []) + (array)($alerts['defaults'] ?? [])
    + ['enabled' => true, 'hour' => 8, 'due_days' => [7, 3, 1, 0], 'overdue_every_days' => 7, 'deposited_days' => [3, 7, 14], 'bounce_now' => true];
$alertDue = array_map('intval', (array)$alertSet['due_days']);
$alertDep = array_map('intval', (array)$alertSet['deposited_days']);
$alertHour = static fn(int $h): string => date('g:00 A', mktime($h, 0, 0, 1, 1, 2000));

if (isset($_GET['csv'])) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="company-post-dated-cheques-' . date('Y-m-d') . '.csv"');
    header('Cache-Control: private, no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Cheque date', 'Cheque no', 'Issued / received', 'Party', 'Bank', 'Amount (AED)', 'Status', 'Status date', 'Reason', 'Location', 'Reference', 'Issued by', 'Approved by', 'Notes']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['cheque_date'], $r['cheque_no'], $dirs[$r['direction']]['long'] ?? $r['direction'], $r['party_name'], $r['bank'],
            number_format((float)$r['amount'], 2, '.', ''), lt_pdc_label((string)$r['status'], $r['status_other'] ?? null), $r['status_date'],
            $r['purpose'], $r['location'], $r['reference'], $r['issued_by'], $r['approved_by'], $r['notes']]);
    }
    fclose($out);
    exit;
}

$pageTitle = LT_PDC_NAME;
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
    <h1 class="lt-title"><?= h(LT_PDC_NAME) ?></h1>
    <p class="lt-sub">The company's own cheques: the ones we give and the ones we receive, with their status.<?= $who['company_name'] !== '' ? ' ' . h($who['company_name']) . '.' : '' ?></p>
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
      // The 7-day card stays lit for as long as that cut is on, also with a type or a search added, so it is never on unseen.
      $on = $c['f']['due'] !== '' ? $f['due'] === $c['f']['due']
          : ($f['status'] === $c['f']['status'] && $f['dir'] === $c['f']['dir'] && $f['due'] === '' && $f['month'] === '');
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
      <?php if ($f['due'] !== ''): // "due in the next 7 days" stays on while the type or the search is changed; picking a status or a month ends it ?><input type="hidden" name="due" value="<?= h($f['due']) ?>"><?php endif; ?>
      <div class="col-6 col-md-2">
        <label class="form-label small fw-semibold mb-1">Type</label>
        <select class="form-select form-select-sm" name="dir" onchange="this.form.submit()">
          <option value="">All</option>
          <?php foreach ($dirs as $k => $d): ?><option value="<?= h($k) ?>" <?= $f['dir'] === $k ? 'selected' : '' ?>><?= h($d['long']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label small fw-semibold mb-1">Status</label>
        <select class="form-select form-select-sm" name="status" onchange="if (this.form.due) { this.form.due.value = ''; } this.form.submit()">
          <option value="all" <?= $f['status'] === 'all' ? 'selected' : '' ?>>All</option>
          <option value="open" <?= $f['status'] === 'open' ? 'selected' : '' ?>>Pending or deposited</option>
          <?php foreach ($statuses as $k => $st): ?><option value="<?= h($k) ?>" <?= $f['status'] === $k ? 'selected' : '' ?>><?= h($k === 'other' ? 'Other (all)' : $st['label']) ?></option><?php endforeach; ?>
          <?php foreach ($customs as $c): ?><option value="custom:<?= h($c) ?>" <?= $f['status'] === 'custom:' . $c ? 'selected' : '' ?>>&nbsp;&nbsp;<?= h($c) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label small fw-semibold mb-1">Cheque month</label>
        <select class="form-select form-select-sm" name="month" onchange="if (this.form.due) { this.form.due.value = ''; } this.form.submit()">
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
      <?php foreach ($rows as $r): $w = $r['status'] === 'pending' ? lt_pdc_when((string)$r['cheque_date']) : ['text' => '', 'class' => '']; ?>
        <tr>
          <td><?= h(lt_date($r['cheque_date'])) ?><?php if ($w['text'] !== ''): ?><div class="lt-when <?= h($w['class']) ?>"><?= h($w['text']) ?></div><?php endif; ?></td>
          <td class="fw-semibold"><?= h($r['cheque_no']) ?>
            <?php if (!empty($r['attachment'])): ?><a href="<?= h($ltBase) ?>/pdc.php?file=<?= (int)$r['id'] ?>" target="_blank" rel="noopener" title="Cheque copy"><i class="bi bi-paperclip"></i></a><?php endif; ?></td>
          <td><span class="small"><i class="bi <?= h($dirs[$r['direction']]['icon'] ?? '') ?> me-1"></i><?= h($dirs[$r['direction']]['label'] ?? $r['direction']) ?></span></td>
          <td><?= h($r['party_name']) ?><?php if (!empty($r['purpose']) || !empty($r['location'])): ?><div class="small text-muted"><?= h(implode(' · ', array_filter([$r['purpose'] ?? '', !empty($r['location']) ? '📍 ' . $r['location'] : '']))) ?></div><?php endif; ?><?php if (!empty($r['issued_by']) || !empty($r['approved_by'])): ?><div class="small text-muted"><i class="bi bi-person-check me-1"></i><?= h(implode(' · ', array_filter([!empty($r['issued_by']) ? 'Issued by ' . $r['issued_by'] : '', !empty($r['approved_by']) ? 'Approved by ' . $r['approved_by'] : '']))) ?></div><?php endif; ?></td>
          <td class="small"><?= h(($r['bank'] ?? '') ?: '—') ?></td>
          <td class="num fw-semibold"><?= lt_money($r['amount']) ?></td>
          <td><?= lt_pdc_badge((string)$r['status'], $r['status_other'] ?? null) ?><?php if (!empty($r['status_date']) && $r['status'] !== 'pending'): ?><div class="small text-muted"><?= h(lt_date($r['status_date'])) ?></div><?php endif; ?></td>
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
  <div class="modal-header"><h5 class="modal-title"><i class="bi bi-envelope me-1"></i>Email alerts</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body p-4">
  <div class="text-muted small mb-3">
    Emails go to <b>the person who added the cheque</b> and to the addresses below.
    <?php if (empty($alertSet['enabled'])): ?><b class="text-danger">The daily email is switched off.</b><?php else: ?>The daily email goes out by itself at <b><?= h($alertHour((int)$alertSet['hour'])) ?></b> (Dubai time).<?php endif; ?>
  </div>

  <form method="post" class="border rounded p-3 mb-3">
    <?php csrf_field(); ?><input type="hidden" name="do" value="alert_settings">
    <div class="fw-semibold small mb-2"><i class="bi bi-clock me-1"></i>When emails are sent</div>
    <div class="row g-3 small">
      <div class="col-md-6">
        <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="enabled" value="1" id="asOn" <?= !empty($alertSet['enabled']) ? 'checked' : '' ?>><label class="form-check-label" for="asOn">Send the daily email</label></div>
      </div>
      <div class="col-md-6">
        <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="bounce_now" value="1" id="asBounce" <?= !empty($alertSet['bounce_now']) ? 'checked' : '' ?>><label class="form-check-label" for="asBounce">Email at once when a cheque bounces</label></div>
      </div>
      <div class="col-md-6">
        <label class="form-label fw-semibold mb-1" for="asHour">Time of the daily email (Dubai)</label>
        <select class="form-select form-select-sm" name="hour" id="asHour">
          <?php for ($hr = 0; $hr < 24; $hr++): ?><option value="<?= $hr ?>" <?= (int)$alertSet['hour'] === $hr ? 'selected' : '' ?>><?= h($alertHour($hr)) ?></option><?php endfor; ?>
        </select>
      </div>
      <div class="col-md-6">
        <label class="form-label fw-semibold mb-1" for="asDue">Days before the cheque date</label>
        <input type="text" class="form-control form-control-sm" name="due_days" id="asDue" value="<?= h(implode(', ', $alertDue)) ?>" placeholder="7, 3, 1, 0">
        <div class="form-text">One reminder on each. 0 = on the cheque date. Empty = none.</div>
      </div>
      <div class="col-md-6">
        <label class="form-label fw-semibold mb-1" for="asOver">Overdue: remind again every (days)</label>
        <input type="number" min="0" max="90" class="form-control form-control-sm" name="overdue_every_days" id="asOver" value="<?= (int)$alertSet['overdue_every_days'] ?>">
        <div class="form-text">0 = only once, when it becomes overdue.</div>
      </div>
      <div class="col-md-6">
        <label class="form-label fw-semibold mb-1" for="asDep">Deposited but not cleared: days after the deposit</label>
        <input type="text" class="form-control form-control-sm" name="deposited_days" id="asDep" value="<?= h(implode(', ', $alertDep)) ?>" placeholder="3, 7, 14">
        <div class="form-text">One reminder on each. Empty = none.</div>
      </div>
    </div>
    <div class="d-flex gap-2 mt-3">
      <button class="btn btn-lt btn-sm"><i class="bi bi-check-lg me-1"></i>Save timings</button>
      <button class="btn btn-lt-ghost btn-sm" name="reset" value="1" onclick="return confirm('Put the timings back to the usual ones (7, 3, 1 and 0 days before; overdue every 7 days; 3, 7 and 14 days after a deposit; 8:00 AM)?');">Back to defaults</button>
    </div>
  </form>
  <form method="post" class="row g-2 align-items-end mb-3">
    <?php csrf_field(); ?><input type="hidden" name="do" value="alert_add">
    <div class="col-md-6">
      <label class="form-label small fw-semibold" for="alertEmails">Add email addresses</label>
      <input type="text" class="form-control" id="alertEmails" name="emails" placeholder="name@company.com, other@company.com" required>
    </div>
    <div class="col-md-4 small">
      <div class="form-check"><input class="form-check-input" type="checkbox" name="on_due" value="1" id="aDue" checked><label class="form-check-label" for="aDue">Due / overdue / not cleared</label></div>
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
                  <input type="hidden" name="on" value="<?= !empty($a[$col]) ? 0 : 1 ?>">
                  <button class="btn btn-sm <?= !empty($a[$col]) ? 'btn-success' : 'btn-outline-secondary' ?> py-0" title="Click to switch"><?= !empty($a[$col]) ? '<i class="bi bi-check-lg"></i> On' : 'Off' ?></button>
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
    <form method="post" class="m-0" onsubmit="return confirm('Email now every reminder that is due and has not been sent yet?');">
      <?php csrf_field(); ?><input type="hidden" name="do" value="alert_run">
      <button class="btn btn-lt-ghost btn-sm"><i class="bi bi-send me-1"></i>Check now and send</button>
    </form>
  </div>

  <?php if ($alertLog): ?>
    <div class="small fw-semibold mt-3 mb-1">Last alerts</div>
    <?php foreach ($alertLog as $l): ?>
      <div class="d-flex flex-wrap gap-2 py-1 border-top small">
        <span class="text-muted" style="min-width:120px"><?= h(binv_when($l['at'] ?? 0)) ?></span>
        <span><?= h(['due_soon' => 'Due soon', 'overdue' => 'Overdue', 'not_cleared' => 'Not cleared', 'bounced' => 'Bounced'][$l['kind']] ?? $l['kind']) ?> · cheque <?= h($l['cheque_no']) ?> (<?= h($l['party_name']) ?>)</span>
        <span class="flex-grow-1 text-muted"><?= $l['result'] === 'sent' ? 'to ' . h($l['sent_to']) : ($l['result'] === 'no_recipients' ? 'no address to send to' : ($l['result'] === 'sending' ? 'being sent' : ($l['result'] === 'gave_up' ? 'could not be delivered after several tries' . (!empty($l['sent_to']) ? ' (it did reach ' . h($l['sent_to']) . ')' : '') . (($l['error'] ?? '') !== '' ? ': ' . h($l['error']) : '') : (!empty($l['sent_to']) ? 'sent to ' . h($l['sent_to']) . ', not yet to the others' : 'not sent yet') . (($l['error'] ?? '') !== '' ? ': ' . h($l['error']) : '')))) ?></span>
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
