<?php
/**
 * Operations — Store Camera: what a store camera saw, as a daily report. Two tabs:
 *
 *   Report  (?tab=report, the first)  pick a camera and a day: every event in the hours
 *           before the camera's report time (e.g. 04:00-16:00), each with its time, one
 *           line on what happened and a snapshot; the quiet hours as "No events".
 *   Cameras (?tab=cameras)  the cameras, entered as on HR -> Attendance Cameras: room /
 *           name, IP address, verification code, RTSP port, stream path, in use; plus the
 *           report time and the hours it covers.
 *
 * The events come from the laptop program (tools/attendance_camera/store_camera.py),
 * which watches the cameras live. Owner and Admin only (camera pictures).
 * Tables: migrations/store_camera.sql.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/url_helper.php';
require_once __DIR__ . '/includes/ops_helper.php';
require_once __DIR__ . '/includes/ops_store_camera.php';

require_login(get_application_web_root() . '/login');
ops_require_access($conn);
if (!array_intersect(current_user_roles($conn), ['Owner', 'Admin'])) {
    http_response_code(403);
    exit('Only the Owner or an Admin can see the store camera.');
}

$appBase = get_application_web_root();
$opsBase = $appBase . '/modules/operations';
$self = $opsBase . '/store_camera.php';
$ready = sc_ready($conn);

if ($ready && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!$_POST && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        ops_flash('That picture is bigger than the server takes. Nothing was saved.', 'danger');
        header('Location: ' . $self . '?tab=live' . (isset($_GET['camera']) ? '&camera=' . (int)$_GET['camera'] : '') . '#sc-past');
        exit;
    }
    csrf_verify();
    $do = (string)($_POST['do'] ?? '');
    try {
        if ($do === 'save') {
            $id = (int)($_POST['id'] ?? 0) ?: null;
            sc_save_camera($conn, $id, $_POST, (int)current_user_id());
            ops_flash($id ? 'Camera saved.' : 'Camera added. Start store_camera.bat on the laptop to watch it.');
        } elseif ($do === 'delete') {
            sc_delete_camera($conn, (int)($_POST['id'] ?? 0));
            ops_flash('Camera deleted, with its events and snapshots.');
        } elseif ($do === 'ref_stock') {
            // The initial stock: Reem counts the reference picture.
            $refCam = sc_camera($conn, (int)($_POST['camera_id'] ?? 0));
            if (!$refCam) {
                throw new StoreCamError('No such camera.');
            }
            try {
                $got = sc_ref_stock_make($conn, $refCam, (int)current_user_id());
                ops_flash('Reem counted the initial stock: ' . count($got['items']) . ' kinds of item.');
            } catch (BinvError $e) {
                ops_flash('Reem could not count the initial stock: ' . $e->getMessage(), 'danger');
            }
            header('Location: ' . $self . '?tab=live&camera=' . (int)$refCam['id'] . '#sc-ref-stock');
            exit;
        } elseif ($do === 'ref_stock_item') {
            // Initial stock: an item added or changed by hand, or a hand-added one removed.
            $refCam = sc_camera($conn, (int)($_POST['camera_id'] ?? 0));
            if (!$refCam) {
                throw new StoreCamError('No such camera.');
            }
            $remove = !empty($_POST['remove']);
            $qty = (string)($_POST['qty'] ?? '');
            if (!$remove && (!preg_match('/^\d{1,5}$/', $qty))) {
                throw new StoreCamError('Give the count as a whole number.');
            }
            sc_ref_stock_set_item($refCam, (string)($_POST['item'] ?? ''), $remove ? null : (int)$qty, isset($_POST['old']) ? (string)$_POST['old'] : null);
            ops_flash($remove ? 'Item removed from the initial stock.' : 'Initial stock saved.');
            header('Location: ' . $self . '?tab=live&camera=' . (int)$refCam['id'] . '#sc-ref-stock');
            exit;
        } elseif ($do === 'reem_report') {
            // "Generate report now" on the Report tab: Reem writes the day's report.
            $repCam = sc_camera($conn, (int)($_POST['camera_id'] ?? 0));
            $repDay = (string)($_POST['day'] ?? '');
            if (!$repCam || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $repDay)) {
                throw new StoreCamError('Pick a camera and a day.');
            }
            try {
                sc_reem_report_make($conn, $repCam, $repDay, (int)current_user_id());
                ops_flash('Reem wrote the report.');
            } catch (BinvError $e) {
                ops_flash('Reem could not write the report: ' . $e->getMessage(), 'danger');
            }
            header('Location: ' . $self . '?camera=' . (int)$repCam['id'] . '&day=' . $repDay . '#sc-reem-report');
            exit;
        } elseif ($do === 'reference_live') {
            // The live picture on screen now becomes a kept picture and the reference.
            $refCam = (int)($_POST['camera_id'] ?? 0);
            sc_keep_live_as_reference($conn, $refCam);
            ops_flash('Live picture kept and set as the reference picture.');
            header('Location: ' . $self . '?tab=live&camera=' . $refCam . '#sc-past');
            exit;
        } elseif ($do === 'count') {
            // "Count now": a check made by hand. The laptop program sends the picture; Reem counts it against the reference.
            $countCam = (int)($_POST['camera_id'] ?? 0);
            if (!sc_checks_ready($conn)) {
                throw new StoreCamError('Checks are not set up yet: run the last parts of migrations/store_camera.sql.');
            }
            if (!sc_camera($conn, $countCam)) {
                throw new StoreCamError('That camera is not there any more.');
            }
            sc_request_check($conn, $countCam, (int)current_user_id());
            header('Location: ' . $self . '?tab=live&camera=' . $countCam . '#sc-count');
            exit;
        } elseif ($do === 'reference_upload') {
            // A picture chosen on the computer becomes the reference.
            $refCam = (int)($_POST['camera_id'] ?? 0);
            if (!sc_camera($conn, $refCam)) {
                throw new StoreCamError('That camera is not there any more.');
            }
            $at = trim((string)($_POST['taken_at'] ?? ''));
            $atDt = $at !== '' ? DateTime::createFromFormat('Y-m-d\TH:i', $at) : null;
            if ($at !== '' && (!$atDt || $atDt->format('Y-m-d\TH:i') !== $at)) {
                throw new StoreCamError('Pick when the picture was taken, or leave it empty.');
            }
            [, $warn] = sc_reference_from_upload($conn, $refCam, $_FILES['picture'] ?? null, $atDt ? $atDt->format('Y-m-d H:i:00') : null);
            ops_flash('Uploaded picture set as the reference. Reem counts its stock when you open the Live tab.' . ($warn ? ' ' . $warn : ''), $warn ? 'warning' : 'success');
            header('Location: ' . $self . '?tab=live&camera=' . $refCam . '#sc-past');
            exit;
        } elseif ($do === 'reference_past') {
            // A check picture or a movement snapshot from the Report tab becomes the reference (a past time).
            $refCam = (int)($_POST['camera_id'] ?? 0);
            sc_reference_from_past($conn, $refCam, (string)($_POST['kind'] ?? '') === 'check' ? 'check' : 'event', (int)($_POST['id'] ?? 0));
            ops_flash('Reference picture changed. Reem counts its stock when you open the Live tab.');
            header('Location: ' . $self . '?tab=live&camera=' . $refCam . '#sc-past');
            exit;
        } elseif ($do === 'ask_pin') {
            // "Ask how many" on a pin: Reem counts that item in the cut-out round the pin, with its smallest model.
            $chkRow = sc_count($conn, (int)($_POST['check_id'] ?? 0));
            if (!$chkRow) {
                throw new StoreCamError('That check is not there any more.');
            }
            @set_time_limit(120);
            $pinNo = (int)($_POST['pin'] ?? 0);
            $n = sc_ask_pin($conn, (int)current_user_id(), $chkRow, $pinNo);
            ops_flash('Pin ' . $pinNo . ': Reem counts ' . $n . '.');
            header('Location: ' . $self . '?tab=live&camera=' . (int)$chkRow['camera_id'] . '#sc-count');
            exit;
        } elseif ($do === 'reference_items') {
            // The starting list from the Live tab: rows of item and count, each with its pin on the picture when one was pointed.
            $refCam = (int)($_POST['camera_id'] ?? 0);
            $n = sc_save_reference_rows($conn, $refCam, (array)($_POST['item'] ?? []), (array)($_POST['qty'] ?? []),
                (array)($_POST['x'] ?? []), (array)($_POST['y'] ?? []));
            ops_flash('Starting list saved: ' . $n . ' item' . ($n === 1 ? '' : 's') . '.');
            header('Location: ' . $self . '?tab=live&camera=' . $refCam . '#sc-past');
            exit;
        } elseif ($do === 'reference_list') {
            // Reem lists the items in the reference picture, to correct and save (billed to the person who pressed).
            $refCam = (int)($_POST['camera_id'] ?? 0);
            $rc = sc_camera($conn, $refCam);
            $rp = $rc && !empty($rc['reference_photo_id']) ? sc_photo($conn, (int)$rc['reference_photo_id']) : null;
            if (!$rp) {
                throw new StoreCamError('Take the reference picture first.');
            }
            @set_time_limit(200);
            try {
                $got = sc_reem_count($conn, (int)current_user_id(), sc_abs((string)$rp['path']), []);
            } catch (BinvError $e) {
                throw new StoreCamError('Reem could not list the items: ' . $e->getMessage());
            }
            // Reem's list replaces the rows (without pins: point each item on the picture, then save).
            $conn->prepare('UPDATE store_cameras SET reference_items = ?, reference_points = ? WHERE id = ?')
                ->execute([json_encode($got['items'], JSON_UNESCAPED_UNICODE), json_encode($got['items'], JSON_UNESCAPED_UNICODE), $refCam]);
            ops_flash('Reem listed ' . count($got['items']) . ' kinds of item. Check the counts, point each item on the picture, and save.');
            header('Location: ' . $self . '?tab=live&camera=' . $refCam . '#sc-past');
            exit;
        }
    } catch (StoreCamError $e) {
        ops_flash($e->getMessage(), 'danger');
        if (in_array($do, ['ref_stock', 'ref_stock_item', 'count', 'ask_pin', 'reference_live', 'reference_past', 'reference_upload', 'reference_items', 'reference_list'], true)) {
            header('Location: ' . $self . '?tab=live&camera=' . (int)($_POST['camera_id'] ?? 0));
            exit;
        }
        $back = (int)($_POST['id'] ?? 0);
        header('Location: ' . $self . '?tab=cameras' . ($back ? '&edit=' . $back : '') . '#camera-form');
        exit;
    }
    header('Location: ' . $self . '?tab=cameras');
    exit;
}

$tab = in_array($_GET['tab'] ?? '', ['live', 'cameras'], true) ? $_GET['tab'] : 'report';
$cameras = $ready ? sc_cameras($conn) : [];
$camId = (int)($_GET['camera'] ?? ($cameras[0]['id'] ?? 0));
$cam = null;
foreach ($cameras as $c) {
    if ((int)$c['id'] === $camId) {
        $cam = $c;
    }
}
$day = (string)($_GET['day'] ?? '');
if ($cam && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
    $day = date('Y-m-d');
}
$report = ($cam && $tab === 'report') ? sc_report($conn, $cam, $day) : null;
$edit = null;
foreach ($cameras as $c) {
    if ((int)$c['id'] === (int)($_GET['edit'] ?? 0)) {
        $edit = $c;
    }
}

$pageTitle = 'Store Camera';
$pageStyles = '
  .sc-table > :not(caption) > * > * { padding: .55rem .65rem; vertical-align: middle; }
  .sc-table thead th { background: #f4f6f8; font-weight: 600; white-space: nowrap; }
  .sc-time { white-space: nowrap; font-variant-numeric: tabular-nums; width: 1%; }
  .sc-quiet td { color: #6c757d; background: #fafbfc; }
  .sc-thumb { width: 120px; height: 68px; object-fit: cover; border-radius: .375rem; border: 1px solid #dee2e6; }';
require __DIR__ . '/includes/ops_layout_header.php';
?>

<div class="mb-3">
  <div class="page-header-label">Store Camera</div>
  <p class="text-muted mb-0">What the store camera saw, as a report each day at its report time.</p>
</div>

<?php if (!$ready): ?>
  <div class="alert alert-warning">Store Camera is not set up yet: run <code>migrations/store_camera.sql</code> on the database.</div>
<?php else: ?>

<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><a class="nav-link<?= $tab === 'report' ? ' active' : '' ?>" href="<?= h($self . ($camId ? '?camera=' . $camId : '')) ?>"><i class="bi bi-list-check"></i> Report</a></li>
  <li class="nav-item"><a class="nav-link<?= $tab === 'live' ? ' active' : '' ?>" href="<?= h($self . '?tab=live' . ($camId ? '&camera=' . $camId : '')) ?>"><i class="bi bi-broadcast"></i> Live</a></li>
  <li class="nav-item"><a class="nav-link<?= $tab === 'cameras' ? ' active' : '' ?>" href="<?= h($self . '?tab=cameras') ?>"><i class="bi bi-camera-video"></i> Camera settings <span class="badge text-bg-light border"><?= count($cameras) ?></span></a></li>
</ul>

<?php if ($tab === 'report'): ?>

  <?php if (!$cam): ?>
    <div class="alert alert-info">No camera yet. Add one on the <a href="<?= h($self . '?tab=cameras') ?>">Camera settings</a> tab.</div>
  <?php else: ?>
    <form method="get" class="row g-2 align-items-end mb-3">
      <div class="col-auto">
        <label class="form-label small mb-1">Camera</label>
        <select name="camera" class="form-select" onchange="this.form.submit()">
          <?php foreach ($cameras as $c): ?>
            <option value="<?= (int)$c['id'] ?>"<?= (int)$c['id'] === $camId ? ' selected' : '' ?>><?= h($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto">
        <label class="form-label small mb-1">Day</label>
        <input type="date" name="day" class="form-control" value="<?= h($day) ?>" max="<?= h(date('Y-m-d')) ?>" onchange="this.form.submit()">
      </div>
      <div class="col-auto">
        <a class="btn btn-outline-secondary" href="<?= h($self . '?camera=' . $camId) ?>">Today</a>
      </div>
    </form>

    <?php if (!sc_program_running($cam) && $cam['active']): ?>
      <div class="alert alert-warning py-2">
        The laptop program is not watching this camera<?= $cam['last_seen_at'] ? ' (last seen ' . h(date('d M H:i', strtotime($cam['last_seen_at']))) . ')' : '' ?>.
        Start <strong>store_camera.bat</strong> on the laptop, or nothing new comes in.
      </div>
    <?php endif; ?>

    <?php $reemRep = sc_reem_report_get($camId, $day);
      [$repStart, $repEnd] = sc_window($cam, $day);
      $repOver = strtotime($repEnd) <= time(); ?>
    <div class="card mb-4" id="sc-reem-report">
      <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
          <strong><?= h($cam['name']) ?></strong> · Daily report ·
          <?= h(date('D d M Y', strtotime($repStart))) ?> <?= h(substr($repStart, 11, 5)) ?>
          – <?= h(date('D d M', strtotime($repEnd))) ?> <?= h(substr($repEnd, 11, 5)) ?>
        </div>
        <form method="post" class="m-0" id="sc-reem-make" onsubmit="var b=this.querySelector('button'); b.disabled=true; b.innerHTML='<span class=&quot;spinner-border spinner-border-sm&quot;></span> Reem is writing the report…';">
          <?php csrf_field(); ?>
          <input type="hidden" name="do" value="reem_report">
          <input type="hidden" name="camera_id" value="<?= (int)$camId ?>">
          <input type="hidden" name="day" value="<?= h($day) ?>">
          <?php if (empty($reemRep['pictures_deleted'])): ?>
            <button class="btn btn-sm btn-primary"><i class="bi bi-stars"></i> <?= $reemRep ? 'Generate again' : 'Generate report now' ?></button>
          <?php endif; ?>
        </form>
      </div>
      <?php if ($reemRep):
        // A snapshot of the report: its own kept copy, else (until the day's pictures go) the original.
        $repKept = $reemRep['kept'] ?? [];
        $repPic = function (string $name, ?string $orig) use ($repKept, $reemRep, $opsBase, $camId, $day) {
            if (in_array($name, $repKept, true)) {
                return $opsBase . '/store_camera_file.php?report=' . (int)$camId . '&day=' . rawurlencode($day) . '&pic=' . $name;
            }
            return $orig !== null && empty($reemRep['pictures_deleted']) ? $opsBase . '/store_camera_file.php?' . $orig : null;
        }; ?>
        <div class="card-body pb-2">
          <?php if (isset($reemRep['movements'])): $sh = (int)($reemRep['stock_handled'] ?? 0); ?>
            <div class="d-flex flex-wrap gap-2 mb-2">
              <span class="badge rounded-pill text-bg-light border fs-6 fw-normal"><i class="bi bi-person-walking"></i> <?= (int)$reemRep['movements'] ?> movement<?= (int)$reemRep['movements'] === 1 ? '' : 's' ?></span>
              <span class="badge rounded-pill fs-6 fw-normal <?= $sh ? 'text-bg-danger' : 'text-bg-light border' ?>"><i class="bi bi-box-seam"></i> Stock handled: <?= $sh ?></span>
              <?php if (($reemRep['closing'] ?? '') !== ''): ?>
                <span class="badge rounded-pill text-bg-light border fs-6 fw-normal"><i class="bi bi-door-closed"></i> At close: <?= h($reemRep['closing']) ?></span>
              <?php endif; ?>
            </div>
          <?php endif; ?>
          <?= h($reemRep['summary']) ?>
        </div>
        <?php if (!empty($reemRep['stock'])): ?>
          <div class="px-3 pb-3">
            <div class="fw-semibold mb-1"><i class="bi bi-box-seam"></i> Stock count</div>
            <table class="table table-sm table-bordered sc-table mb-1" style="max-width:640px">
              <thead><tr><th>Item</th><th class="text-end">Start of day</th><th class="text-end">End of day</th><th class="text-end">Change</th></tr></thead>
              <tbody>
                <?php foreach ($reemRep['stock'] as $sk): $chg = $sk['end'] - $sk['start']; ?>
                  <tr>
                    <td><?= h($sk['item']) ?></td>
                    <td class="text-end"><?= (int)$sk['start'] ?></td>
                    <td class="text-end"><?= (int)$sk['end'] ?></td>
                    <td class="text-end fw-semibold <?= $chg < 0 ? 'text-danger' : ($chg > 0 ? 'text-success' : 'text-muted') ?>"><?= $chg > 0 ? '+' . $chg : ($chg < 0 ? (string)$chg : '–') ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            <div class="small text-muted">Counted by Reem from the first and last pictures of the day. Items hidden behind others cannot be seen, so treat it as a guide.</div>
            <?php
              $changed = array_values(array_filter($reemRep['stock'], function ($sk) { return $sk['end'] !== $sk['start']; }));
              $rpics = $reemRep['pics'] ?? [];
              $psrcs = [];
              foreach (['start', 'end'] as $side) {
                  if (!empty($rpics[$side]) && ($u = $repPic($side, $rpics[$side]['src']))) {
                      $psrcs[$side] = $u;
                  }
              }
              if ($psrcs): ?>
              <div class="row g-3 mt-1">
                <?php foreach (['start' => 'Start of day', 'end' => 'End of day'] as $side => $sideName): if (empty($psrcs[$side])) { continue; } ?>
                  <div class="col-md-6">
                    <div class="small fw-semibold mb-1"><?= $sideName ?> <span class="text-muted fw-normal">(<?= h($rpics[$side]['at']) ?>)</span></div>
                    <div class="position-relative">
                      <?php $psrc = $psrcs[$side]; ?>
                      <a href="<?= h($psrc) ?>" target="_blank" rel="noopener"><img src="<?= h($psrc) ?>" class="img-fluid rounded border d-block w-100" alt="<?= $sideName ?>" loading="lazy"
                        onerror="this.closest('.col-md-6').innerHTML='<div class=&quot;small text-muted&quot;><?= $sideName ?>: the picture is no longer kept.</div>';"></a>
                      <?php foreach ($changed as $sk): foreach ($sk[$side . '_boxes'] ?? [] as $b): ?>
                        <div style="position:absolute;pointer-events:none;border:3px solid #dc3545;border-radius:4px;box-shadow:0 0 0 1px #fff;left:<?= $b[0] ?>%;top:<?= $b[1] ?>%;width:<?= $b[2] - $b[0] ?>%;height:<?= $b[3] - $b[1] ?>%">
                          <span style="position:absolute;left:-3px;bottom:100%;background:#dc3545;color:#fff;font-size:11px;line-height:1.3;padding:0 4px;border-radius:3px 3px 0 0;white-space:nowrap"><?= h($sk['item']) ?></span>
                        </div>
                      <?php endforeach; endforeach; ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
              <?php if ($changed): ?><div class="small text-muted mt-1">Red boxes: the items whose count changed during the day.</div><?php endif; ?>
            <?php endif; ?>
          </div>
        <?php endif; ?>
        <div class="table-responsive">
          <table class="table sc-table mb-0">
            <?php
              $rowPics = $rowMore = [];
              foreach (($reemRep['rows'] ?? []) as $i => $rr) {
                  if (!empty($rr['event']) && ($u = $repPic('e' . (int)$rr['event'], 'id=' . (int)$rr['event']))) {
                      $rowPics[$i] = $u;
                  }
                  // The extra small snapshots kept round a stock change.
                  foreach (($rr['more'] ?? []) as $m) {
                      if ($u = $repPic((string)$m['pic'], null)) {
                          $rowMore[$i][] = ['src' => $u, 'at' => (string)$m['at']];
                      }
                  }
              }
              $repPics = $rowPics || $rowMore; ?>
            <thead><tr><th>Time</th><th>What happened</th><th>Stock</th><?= $repPics ? '<th>Snapshot</th>' : '' ?></tr></thead>
            <tbody>
              <?php foreach ($reemRep['rows'] ?? array_map(function ($p) { return ['time' => '', 'what' => (string)$p]; }, $reemRep['points'] ?? []) as $ri => $rr): ?>
                <tr>
                  <td class="sc-time"><?= h($rr['time']) ?></td>
                  <td><?= h($rr['what']) ?></td>
                  <td class="small<?= ($rr['stock'] ?? '') !== '' && strpos((string)$rr['stock'], '→') !== false ? ' fw-semibold text-danger' : ' text-muted' ?>"><?= h((string)($rr['stock'] ?? '')) ?></td>
                  <?php if ($repPics): ?><td><?php if (!empty($rowPics[$ri])): $esrc = $rowPics[$ri]; ?>
                    <a href="<?= h($esrc) ?>" target="_blank" rel="noopener"><img class="sc-thumb" src="<?= h($esrc) ?>" alt="Snapshot <?= h($rr['time']) ?>" loading="lazy"></a>
                  <?php endif; ?>
                  <?php if (!empty($rowMore[$ri])): ?>
                    <div class="d-flex flex-wrap gap-1 mt-1">
                      <?php foreach ($rowMore[$ri] as $m): ?>
                        <a href="<?= h($m['src']) ?>" target="_blank" rel="noopener" title="<?= h($m['at']) ?>" class="text-decoration-none text-center small text-muted">
                          <img src="<?= h($m['src']) ?>" alt="Snapshot <?= h($m['at']) ?>" loading="lazy" class="rounded border d-block" style="width:64px;height:48px;object-fit:cover"><?= h($m['at']) ?>
                        </a>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?></td><?php endif; ?>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="card-footer small text-muted">Written by Reem <?= h(date('d M Y, H:i', strtotime($reemRep['made_at']))) ?>.</div>
      <?php elseif (strtotime($repStart) > time()): ?>
        <div class="card-body text-muted">This report starts at <?= h(substr($repStart, 11, 5)) ?>.</div>
      <?php else: ?>
        <div class="card-body text-muted"><?= $repOver ? 'Reem is writing the report…' : 'The day is not over yet. Press <strong>Generate report now</strong> for the report so far.' ?></div>
        <?php if ($repOver): ?>
          <script>(function () { var f = document.getElementById('sc-reem-make'); if (f) { f.onsubmit(); f.submit(); } })();</script>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  <?php endif; ?>

<?php elseif ($tab === 'live'): ?>

  <?php if (!$cam): ?>
    <div class="alert alert-info">No camera yet. Add one on the <a href="<?= h($self . '?tab=cameras') ?>">Camera settings</a> tab.</div>
  <?php else: ?>
    <form method="get" class="row g-2 align-items-end mb-3">
      <input type="hidden" name="tab" value="live">
      <div class="col-auto">
        <label class="form-label small mb-1">Camera</label>
        <select name="camera" class="form-select" onchange="this.form.submit()">
          <?php foreach ($cameras as $c): ?>
            <option value="<?= (int)$c['id'] ?>"<?= (int)$c['id'] === $camId ? ' selected' : '' ?>><?= h($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </form>
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <strong><?= h($cam['name']) ?></strong>
        <span id="scLiveState" class="small text-muted">Connecting…</span>
      </div>
      <div class="card-body p-2 text-center bg-dark-subtle">
        <img id="scLiveImg" alt="Live picture of <?= h($cam['name']) ?>" class="img-fluid rounded d-none" style="max-height:75vh">
        <div id="scLiveWait" class="text-muted py-5">
          Waiting for a picture from the laptop program…<br>
          <span class="small">It must be running (store_camera.bat) on the camera's network. The first picture comes within a few seconds.</span>
        </div>
      </div>
    </div>
    <script>
    (function () {
      var url = <?= json_encode($opsBase . '/store_camera_file.php?live=' . $camId) ?>;
      var img = document.getElementById('scLiveImg'), wait = document.getElementById('scLiveWait'), state = document.getElementById('scLiveState');
      var last = null, busy = false, misses = 0;
      function tick() {
        if (busy || document.hidden) { return; }
        busy = true;
        fetch(url + '&t=' + Date.now(), { credentials: 'same-origin', cache: 'no-store' }).then(function (r) {
          if (r.status === 200) { return r.blob(); }
          misses++;
          if (misses > 3) { state.textContent = 'No picture: is the laptop program running?'; state.className = 'small text-warning'; }
          return null;
        }).then(function (b) {
          if (!b) { return; }
          misses = 0;
          var u = URL.createObjectURL(b);
          img.onload = function () { if (last) { URL.revokeObjectURL(last); } last = u; };
          img.src = u;
          img.classList.remove('d-none'); wait.classList.add('d-none');
          state.textContent = 'Live · ' + new Date().toLocaleTimeString(); state.className = 'small text-success';
        }).catch(function () {
          state.textContent = 'Connection lost, retrying…'; state.className = 'small text-danger';
        }).finally(function () { busy = false; });
      }
      tick();
      setInterval(tick, 1000);
    })();
    </script>

    <?php $now = sc_live_status($conn, $cam); ?>
    <!-- What is happening: the program, the last movement, the checks. -->
    <div class="card mt-2">
      <div class="card-body py-2 small d-flex flex-wrap gap-4">
        <div>
          <?php if ($now['running']): ?>
            <span class="text-success"><i class="bi bi-circle-fill"></i> Program watching</span>
          <?php else: ?>
            <span class="text-danger"><i class="bi bi-exclamation-triangle"></i> Program not running</span><?= $now['seen'] ? ' · last seen ' . h(date('d M H:i', strtotime((string)$now['seen']))) : '' ?>
          <?php endif; ?>
        </div>
        <div>
          <i class="bi bi-person-walking"></i>
          <?php if ($now['event']): ?>
            Last movement <strong><?= h(date('H:i', strtotime((string)$now['event']['started_at']))) ?></strong><?= date('Y-m-d', strtotime((string)$now['event']['started_at'])) !== date('Y-m-d') ? ' (' . h(date('d M', strtotime((string)$now['event']['started_at']))) . ')' : '' ?>: <?= h(sc_event_text((string)$now['event']['description'])) ?>
          <?php else: ?>
            No movement recorded yet
          <?php endif; ?>
        </div>
        <?php if (sc_checks_ready($conn)): ?>
          <div>
            <i class="bi bi-boxes"></i>
            <?php if ($now['check'] && in_array($now['check']['status'], ['waiting', 'counting'], true)): ?>
              Checking now: <?= $now['check']['status'] === 'waiting' ? 'getting the picture' : 'comparing' ?>
            <?php elseif ($now['next']): ?>
              Next check <strong><?= h(date('H:i', $now['next'])) ?></strong><?= date('Y-m-d', $now['next']) !== date('Y-m-d') ? ' tomorrow' : '' ?><?= $cam['check_only_on_movement'] ? ' (if something moved)' : '' ?>
            <?php else: ?>
              Checks off (Camera settings tab)
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <?php if (sc_photos_ready($conn) && sc_checks_ready($conn)): ?>
      <?php
        $ref = !empty($cam['reference_photo_id']) ? sc_photo($conn, (int)$cam['reference_photo_id']) : null;
        $refSrc = $ref ? $opsBase . '/store_camera_file.php?photo=' . (int)$ref['id'] : '';
      ?>
      <div class="card mt-3" id="sc-past">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
          <strong><i class="bi bi-pin-angle"></i> Reference (starting stock)</strong>
          <form method="post" class="m-0" onsubmit="return confirm('Keep the live picture shown above now as the reference picture?');">
            <?php csrf_field(); ?>
            <input type="hidden" name="do" value="reference_live">
            <input type="hidden" name="camera_id" value="<?= (int)$camId ?>">
            <button class="btn btn-sm btn-primary"><i class="bi bi-camera"></i> Use live picture as reference</button>
          </form>
        </div>
        <?php
          // Pick a time: the camera's picture saifsys kept nearest it (check pictures and movement snapshots).
          $pickAt = (string)($_GET['pick'] ?? '');
          $pickDt = DateTime::createFromFormat('Y-m-d\TH:i', $pickAt) ?: null;
          $picked = null;
          if (isset($_GET['pk'], $_GET['pid'])) {
              $picked = sc_kept_picture_get($conn, $camId, $_GET['pk'] === 'check' ? 'check' : 'event', (int)$_GET['pid']);
          } elseif ($pickDt) {
              $picked = sc_kept_picture($conn, $camId, $pickDt->format('Y-m-d H:i:00'));
          }
          $pPrev = $picked ? sc_kept_picture($conn, $camId, null, $picked, -1) : null;
          $pNext = $picked ? sc_kept_picture($conn, $camId, null, $picked, 1) : null;
          $pUrl = function (array $kp) use ($self, $camId) { return $self . '?tab=live&camera=' . $camId . '&pk=' . $kp['kind'] . '&pid=' . $kp['id'] . '#sc-pick'; };
        ?>
        <div class="px-3 pt-3" id="sc-pick">
          <form method="get" action="<?= h($self) ?>#sc-pick" class="row g-2 align-items-end">
            <input type="hidden" name="tab" value="live">
            <input type="hidden" name="camera" value="<?= (int)$camId ?>">
            <div class="col-md-5">
              <label class="form-label small mb-1">Take it from the camera at a time</label>
              <input type="datetime-local" name="pick" class="form-control form-control-sm" required max="<?= h(date('Y-m-d\TH:i')) ?>"
                     value="<?= h($picked ? date('Y-m-d\TH:i', strtotime($picked['at'])) : ($pickDt ? $pickDt->format('Y-m-d\TH:i') : '')) ?>">
            </div>
            <div class="col-md-3"><button class="btn btn-sm btn-outline-primary w-100"><i class="bi bi-clock-history"></i> Show the picture</button></div>
          </form>
          <?php if ($picked): ?>
            <div class="mt-2 p-2 border rounded bg-body-tertiary">
              <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                <div>
                  <strong><?= h(date('d M Y, H:i', strtotime($picked['at']))) ?></strong>
                  <span class="small text-muted">(<?= $picked['kind'] === 'check' ? 'check picture' : 'movement snapshot' ?><?php
                    if ($pickDt && abs(strtotime($picked['at']) - $pickDt->getTimestamp()) > 60) { echo ', the nearest kept to ' . h($pickDt->format('H:i')); } ?>)</span>
                </div>
                <div class="d-flex gap-1 align-items-center">
                  <?php if ($pPrev): ?><a class="btn btn-sm btn-light border" href="<?= h($pUrl($pPrev)) ?>" title="Earlier: <?= h(date('d M H:i', strtotime($pPrev['at']))) ?>"><i class="bi bi-chevron-left"></i></a><?php endif; ?>
                  <?php if ($pNext): ?><a class="btn btn-sm btn-light border" href="<?= h($pUrl($pNext)) ?>" title="Later: <?= h(date('d M H:i', strtotime($pNext['at']))) ?>"><i class="bi bi-chevron-right"></i></a><?php endif; ?>
                  <form method="post" class="m-0" onsubmit="return confirm('Make this picture (<?= h(date('d M H:i', strtotime($picked['at']))) ?>) the reference? The current reference is replaced.');">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="do" value="reference_past">
                    <input type="hidden" name="camera_id" value="<?= (int)$camId ?>">
                    <input type="hidden" name="kind" value="<?= h($picked['kind']) ?>">
                    <input type="hidden" name="id" value="<?= (int)$picked['id'] ?>">
                    <button class="btn btn-sm btn-primary"><i class="bi bi-pin-angle"></i> Use as reference</button>
                  </form>
                </div>
              </div>
              <?php $ksrc = $opsBase . '/store_camera_file.php?' . $picked['src']; ?>
              <a href="<?= h($ksrc) ?>" target="_blank" rel="noopener"><img src="<?= h($ksrc) ?>" class="img-fluid rounded border" style="max-height:45vh" alt="Camera picture at <?= h($picked['at']) ?>"></a>
            </div>
          <?php elseif ($pickDt || isset($_GET['pid'])): ?>
            <div class="small text-muted mt-2">No camera picture is kept yet for this camera.</div>
          <?php endif; ?>
          <div class="form-text">The camera does not hand out its own recording, so this shows the pictures saifsys kept from it: one at each check and one at each movement. For a picture every hour, set checks every 1 hour on the Camera settings tab with "Only check when something moved" off (checks are free).</div>
        </div>
        <div class="px-3 pt-3">
          <form method="post" enctype="multipart/form-data" action="<?= h($self . '?tab=live&camera=' . (int)$camId) ?>" class="row g-2 align-items-end"
                onsubmit="return confirm('Make the chosen picture the reference picture? The current reference is replaced.');">
            <?php csrf_field(); ?>
            <input type="hidden" name="do" value="reference_upload">
            <input type="hidden" name="camera_id" value="<?= (int)$camId ?>">
            <div class="col-md-5">
              <label class="form-label small mb-1">Or upload a picture</label>
              <input type="file" name="picture" class="form-control form-control-sm" accept="image/jpeg,image/png,image/webp" required>
            </div>
            <div class="col-md-4">
              <label class="form-label small mb-1">Taken at <span class="text-muted">(optional)</span></label>
              <input type="datetime-local" name="taken_at" class="form-control form-control-sm" max="<?= h(date('Y-m-d\TH:i')) ?>">
            </div>
            <div class="col-md-3"><button class="btn btn-sm btn-outline-primary w-100"><i class="bi bi-upload"></i> Upload as reference</button></div>
            <div class="col-12 form-text mt-0">A picture of this camera's own view (e.g. saved from the EZVIZ app), so it lines up with the live picture. JPEG, PNG or WebP, 8 MB at most.</div>
          </form>
        </div>
        <div class="card-body">
          <?php if (!$ref): ?>
            <div class="text-muted">None yet. When the store is set (start of month, after restocking), press <strong>Use live picture as reference</strong>, pick a time, or upload a picture.</div>
          <?php else: ?>
            <div class="small text-muted mb-1">Taken <?= h(date('d M Y, H:i', strtotime($ref['taken_at']))) ?>. Setting another one replaces it. The checks and the daily report compare the store with this picture.</div>
            <div class="row g-3">
              <div class="col-lg-7">
                <div class="position-relative d-inline-block" id="sc-ref-pic">
                  <a href="<?= h($refSrc) ?>" target="_blank" rel="noopener"><img src="<?= h($refSrc) ?>" class="img-fluid rounded border d-block" style="max-height:55vh" alt="Reference picture"></a>
                </div>
              </div>
              <div class="col-lg-5" id="sc-ref-stock">
                <?php $refStock = sc_ref_stock_get($cam); ?>
                <div class="d-flex justify-content-between align-items-center mb-2">
                  <strong><i class="bi bi-box-seam"></i> Initial stock</strong>
                  <form method="post" class="m-0" id="sc-ref-stock-make" onsubmit="var b=this.querySelector('button'); b.disabled=true; b.innerHTML='<span class=&quot;spinner-border spinner-border-sm&quot;></span> Reem is counting…';">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="do" value="ref_stock">
                    <input type="hidden" name="camera_id" value="<?= (int)$cam['id'] ?>">
                    <button class="btn btn-sm btn-outline-primary"><i class="bi bi-stars"></i> <?= $refStock ? 'Count again' : 'Count with Reem' ?></button>
                  </form>
                </div>
                <?php if ($refStock): ?>
                  <table class="table table-sm table-bordered sc-table mb-1">
                    <thead><tr><th>Item</th><th class="text-end">Count</th></tr></thead>
                    <tbody>
                      <?php foreach ($refStock['items'] as $it): ?>
                        <tr class="sc-ref-item" data-boxes="<?= h(json_encode($it['boxes'] ?? [])) ?>" style="cursor:pointer" title="Show on the picture">
                          <td>
                            <?= h($it['item']) ?><?= !empty($it['boxes']) ? ' <i class="bi bi-eye text-muted small"></i>' : '' ?>
                            <?php if (!empty($it['manual'])): ?><span class="badge text-bg-light border fw-normal ms-1"><?= !empty($it['was']) ? 'corrected' : 'added by hand' ?></span><?php endif; ?>
                          </td>
                          <td class="text-end text-nowrap">
                            <?= (int)$it['count'] ?>
                            <button type="button" class="btn btn-link btn-sm p-0 ms-1 sc-ref-edit" title="Change"><i class="bi bi-pencil"></i></button>
                              <form method="post" class="d-inline" onsubmit="event.stopPropagation(); return confirm('Remove <?= h(addslashes($it['item'])) ?> from the initial stock?');" onclick="event.stopPropagation();">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="do" value="ref_stock_item">
                                <input type="hidden" name="camera_id" value="<?= (int)$cam['id'] ?>">
                                <input type="hidden" name="item" value="<?= h($it['item']) ?>">
                                <input type="hidden" name="remove" value="1">
                                <button class="btn btn-link btn-sm p-0 ms-1 text-danger" title="Remove"><i class="bi bi-x-circle"></i></button>
                              </form>
                          </td>
                        </tr>
                        <tr class="d-none sc-ref-edit-row">
                          <td colspan="2">
                            <form method="post" class="d-flex gap-1">
                              <?php csrf_field(); ?>
                              <input type="hidden" name="do" value="ref_stock_item">
                              <input type="hidden" name="camera_id" value="<?= (int)$cam['id'] ?>">
                              <input type="hidden" name="old" value="<?= h($it['item']) ?>">
                              <input type="text" name="item" class="form-control form-control-sm" value="<?= h($it['item']) ?>" maxlength="80" required>
                              <input type="number" name="qty" class="form-control form-control-sm" style="max-width:90px" value="<?= (int)$it['count'] ?>" min="0" max="99999" required>
                              <button class="btn btn-sm btn-primary">Save</button>
                              <button type="button" class="btn btn-sm btn-light border sc-ref-edit-cancel">Cancel</button>
                            </form>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                      <?php if (!$refStock['items']): ?><tr><td colspan="2" class="text-muted">No stock seen.</td></tr><?php endif; ?>
                    </tbody>
                  </table>
                  <form method="post" class="d-flex gap-1 mb-2">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="do" value="ref_stock_item">
                    <input type="hidden" name="camera_id" value="<?= (int)$cam['id'] ?>">
                    <input type="text" name="item" class="form-control form-control-sm" placeholder="Add an item the camera missed, e.g. Trays" maxlength="80" required list="sc-ref-names">
                    <input type="number" name="qty" class="form-control form-control-sm" style="max-width:90px" placeholder="Count" min="0" max="99999" required>
                    <button class="btn btn-sm btn-outline-secondary text-nowrap"><i class="bi bi-plus-lg"></i> Add</button>
                    <datalist id="sc-ref-names"><?php foreach ($refStock['items'] as $it): ?><option value="<?= h($it['item']) ?>"><?php endforeach; ?></datalist>
                  </form>
                  <div class="small text-muted mb-1"><i class="bi bi-pencil"></i> changes a wrong count or name, <i class="bi bi-x-circle"></i> removes a wrong item. Your changes stay when Reem counts again.</div>
                  <?php if ($refStock['note'] !== ''): ?><div class="small text-muted mb-1"><?= h($refStock['note']) ?></div><?php endif; ?>
                  <div class="small text-muted">Click an item to see it on the picture. Counted by Reem from this picture, <?= h(date('d M Y, H:i', strtotime($refStock['made_at']))) ?>. The daily report counts against it.</div>
                  <script>
                  (function () {
                    var pic = document.getElementById('sc-ref-pic');
                    var rows = document.querySelectorAll('.sc-ref-item');
                    // ✎ opens the row's change form under it.
                    document.querySelectorAll('.sc-ref-edit').forEach(function (b) {
                      b.addEventListener('click', function (e) {
                        e.stopPropagation();
                        var form = b.closest('tr').nextElementSibling;
                        form.classList.toggle('d-none');
                        if (!form.classList.contains('d-none')) { form.querySelector('input[name=qty]').select(); }
                      });
                    });
                    document.querySelectorAll('.sc-ref-edit-cancel').forEach(function (b) {
                      b.addEventListener('click', function () { b.closest('tr').classList.add('d-none'); });
                    });
                    function clear() {
                      pic.querySelectorAll('.sc-ref-box').forEach(function (b) { b.remove(); });
                      rows.forEach(function (r) { r.classList.remove('table-warning'); });
                    }
                    rows.forEach(function (row) {
                      row.addEventListener('click', function () {
                        var on = row.classList.contains('table-warning');
                        clear();
                        if (on) { return; }
                        row.classList.add('table-warning');
                        var boxes = [];
                        try { boxes = JSON.parse(row.dataset.boxes || '[]'); } catch (e) {}
                        boxes.forEach(function (b) {
                          var d = document.createElement('div');
                          d.className = 'sc-ref-box';
                          d.style.cssText = 'position:absolute;pointer-events:none;border:3px solid #ffc107;box-shadow:0 0 0 2px rgba(0,0,0,.55);border-radius:4px;'
                            + 'left:' + b[0] + '%;top:' + b[1] + '%;width:' + (b[2] - b[0]) + '%;height:' + (b[3] - b[1]) + '%';
                          pic.appendChild(d);
                        });
                        if (!boxes.length) { row.title = 'Reem did not mark where this is: press Count again'; }
                        pic.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                      });
                    });
                  })();
                  </script>
                <?php else: ?>
                  <div class="text-muted small">Reem is counting the stock in this picture…</div>
                  <script>(function () { var f = document.getElementById('sc-ref-stock-make'); if (f) { f.onsubmit(); f.submit(); } })();</script>
                <?php endif; ?>
              </div>
            </div>
          <?php endif; ?>
        </div>
      </div>

    <?php else: ?>
      <div class="alert alert-warning mt-3">Checks are not set up yet: run the last parts of <code>migrations/store_camera.sql</code>.</div>
    <?php endif; ?>
  <?php endif; ?>

<?php else: ?>

  <?php if ($cameras): ?>
    <div class="card mb-3">
      <div class="table-responsive">
        <table class="table align-middle mb-0">
          <thead class="table-light"><tr><th>Room / name</th><th>IP address</th><th>Report at</th><th>Covers</th><th>Checks</th><th>Watching</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($cameras as $c): ?>
            <tr>
              <td><?= h($c['name']) ?><?= $c['active'] ? '' : ' <span class="badge text-bg-secondary">Not in use</span>' ?></td>
              <td class="small text-muted"><?= h($c['ip_address']) ?>:<?= (int)$c['rtsp_port'] ?><?= h($c['stream_path']) ?></td>
              <td><?= h(date('g:i A', strtotime($c['report_time']))) ?></td>
              <td><?= (int)$c['hours_back'] ?> hours before</td>
              <td class="small"><?php if (!sc_checks_ready($conn) || (int)$c['check_every_hours'] === 0): ?><span class="text-muted">Off</span><?php else: ?>
                Every <?= (int)$c['check_every_hours'] ?> h<?= $c['check_from'] !== null ? ', ' . h(substr($c['check_from'], 0, 5)) . '–' . h(substr($c['check_to'], 0, 5)) : '' ?><?= $c['check_only_on_movement'] ? ', if moved' : '' ?>
              <?php endif; ?></td>
              <td><?= sc_program_running($c) ? '<span class="text-success"><i class="bi bi-circle-fill small"></i> Yes</span>' : '<span class="text-muted">No</span>' ?></td>
              <td class="text-end text-nowrap">
                <a class="btn btn-sm btn-light border" href="<?= h($self . '?camera=' . (int)$c['id']) ?>">Report</a>
                <a class="btn btn-sm btn-light border" href="<?= h($self . '?tab=cameras&edit=' . (int)$c['id']) ?>#camera-form">Edit</a>
                <form method="post" class="d-inline" onsubmit="return confirm('Delete <?= h($c['name']) ?>, with every event and snapshot it has?');">
                  <?php csrf_field(); ?>
                  <input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

  <div class="card" id="camera-form">
    <div class="card-body">
      <h2 class="h6 mb-3"><?= $edit ? 'Edit ' . h($edit['name']) : 'Add a camera' ?></h2>
      <form method="post" class="row g-3" autocomplete="off">
        <?php csrf_field(); ?>
        <input type="hidden" name="do" value="save">
        <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : 0 ?>">
        <div class="col-md-4">
          <label class="form-label">Room / name</label>
          <input type="text" name="name" class="form-control" maxlength="100" required placeholder="e.g. Store room" value="<?= h($edit['name'] ?? '') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">IP address or network name</label>
          <input type="text" name="ip_address" class="form-control" maxlength="64" required placeholder="e.g. 192.168.70.124 or C8c_BH5929142_EZVIZ.mshome.net" value="<?= h($edit['ip_address'] ?? '') ?>">
          <div class="form-text">EZVIZ app → camera → Settings → Device Information.</div>
        </div>
        <div class="col-md-4">
          <label class="form-label">Verification code</label>
          <input type="password" name="verification_code" class="form-control" maxlength="64" autocomplete="new-password"
                 <?= $edit ? '' : 'required' ?> placeholder="<?= $edit ? 'Leave empty to keep the saved code' : '6 capital letters on the sticker' ?>">
          <div class="form-text">The 6 letters on the camera's sticker, or the password set for RTSP.</div>
        </div>
        <div class="col-md-2">
          <label class="form-label">RTSP port</label>
          <input type="number" name="rtsp_port" class="form-control" min="1" max="65535" value="<?= (int)($edit['rtsp_port'] ?? 554) ?>">
        </div>
        <div class="col-md-2">
          <label class="form-label">Stream path</label>
          <input type="text" name="stream_path" class="form-control" maxlength="100" value="<?= h($edit['stream_path'] ?? '/H.264') ?>">
        </div>
        <div class="col-md-2">
          <label class="form-label">Report at</label>
          <input type="time" name="report_time" class="form-control" required value="<?= h(substr($edit['report_time'] ?? '16:00:00', 0, 5)) ?>">
        </div>
        <div class="col-md-2">
          <label class="form-label">Hours covered</label>
          <input type="number" name="hours_back" class="form-control" min="1" max="<?= SC_MAX_HOURS ?>" required value="<?= (int)($edit['hours_back'] ?? 12) ?>">
        </div>
        <?php if (sc_checks_ready($conn)): $ck = function ($k, $d) use ($edit) { return $edit === null ? $d : (int)$edit[$k]; }; ?>
          <div class="col-12"><hr class="my-1"><div class="fw-semibold small text-muted">Checks (a free comparison of the picture with the reference) and bell alerts</div></div>
          <div class="col-md-2">
            <label class="form-label">Check every</label>
            <div class="input-group">
              <input type="number" name="check_every_hours" class="form-control" min="0" max="<?= SC_CHECK_MAX_HOURS ?>" required value="<?= $ck('check_every_hours', 2) ?>">
              <span class="input-group-text">hours</span>
            </div>
            <div class="form-text">0 = no checks.</div>
          </div>
          <div class="col-md-3">
            <label class="form-label">Only between</label>
            <div class="input-group">
              <input type="time" name="check_from" class="form-control" value="<?= h(substr((string)($edit['check_from'] ?? ''), 0, 5)) ?>">
              <span class="input-group-text">and</span>
              <input type="time" name="check_to" class="form-control" value="<?= h(substr((string)($edit['check_to'] ?? ''), 0, 5)) ?>">
            </div>
            <div class="form-text">Both empty = all day.</div>
          </div>
          <div class="col-md-2">
            <label class="form-label" title="Movement and check pictures older than this are deleted. Times, descriptions and daily reports stay.">Keep pictures</label>
            <div class="input-group">
              <input type="number" name="check_keep_days" class="form-control" min="1" max="<?= SC_KEEP_MAX_DAYS ?>" required value="<?= $ck('check_keep_days', 30) ?>">
              <span class="input-group-text">days</span>
            </div>
          </div>
          <div class="col-md-5 d-flex align-items-center">
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" role="switch" id="scOnlyMoved" name="check_only_on_movement" value="1"<?= $ck('check_only_on_movement', 1) ? ' checked' : '' ?>>
              <label class="form-check-label" for="scOnlyMoved">Only check when something moved since the last check (saves storage)</label>
            </div>
          </div>
          <input type="hidden" name="alert_movement" value="1">
          <input type="hidden" name="alert_count" value="1">
          <input type="hidden" name="alert_stopped" value="1">
          <input type="hidden" name="stopped_after_minutes" value="<?= $ck('stopped_after_minutes', 15) ?>">
        <?php endif; ?>
        <div class="col-md-2 d-flex align-items-end">
          <div class="form-check form-switch mb-2">
            <input class="form-check-input" type="checkbox" role="switch" id="scActive" name="active" value="1" <?= ($edit === null || (int)$edit['active']) ? 'checked' : '' ?>>
            <label class="form-check-label" for="scActive">In use</label>
          </div>
        </div>
        <div class="col-md-2 d-flex align-items-end justify-content-end gap-2">
          <?php if ($edit): ?><a class="btn btn-outline-secondary" href="<?= h($self . '?tab=cameras') ?>">Cancel</a><?php endif; ?>
          <button class="btn btn-primary"><?= $edit ? 'Save' : 'Add camera' ?></button>
        </div>
        <div class="col-12 form-text mt-0">
          Example: report at 4:00 PM covering 12 hours shows 4:00 AM to 4:00 PM. The verification code is kept here and never shown again.
        </div>
      </form>
    </div>
  </div>

<?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/includes/ops_layout_footer.php'; ?>
