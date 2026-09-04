<?php
// hr/holidays.php


require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/audit_bridge.php';
require_role(['Owner','Admin','HR'], $conn);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

// Helpers
function ymd($s){ return $s ? date('Y-m-d', strtotime($s)) : null; }
function project_date_for_year(DateTime $stored, int $year): string {
  // If repeats_annually => replace year; handle Feb 29 (project to Feb 28)
  $m = (int)$stored->format('m');
  $d = (int)$stored->format('d');
  if ($m === 2 && $d === 29 && !date('L', strtotime("$year-01-01"))) $d = 28;
  return sprintf('%04d-%02d-%02d', $year, $m, $d);
}

// Lookups
$locations = $conn->query("SELECT id, name FROM locations ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Filters
$year      = (int)($_GET['year'] ?? date('Y'));
$loc_id    = ($_GET['location_id'] ?? '') === '' ? '' : (int)$_GET['location_id'];
$show_all  = isset($_GET['all']) ? (int)$_GET['all'] : 0;

// Create / Update / Delete
$msg = $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
  if (isset($_POST['create'])) {
    $name = trim($_POST['name'] ?? '');
    $date = ymd($_POST['holiday_date'] ?? '');
    $rep  = isset($_POST['repeats_annually']) ? 1 : 0;
    $lid  = ($_POST['location_id'] ?? '') === '' ? null : (int)$_POST['location_id'];
    $notes= trim($_POST['notes'] ?? '');
    if (!$name || !$date) {
      $err = 'Name and date are required.';
    } else {
      $stmt = $conn->prepare("INSERT INTO holidays (name, holiday_date, repeats_annually, location_id, notes, created_by)
                              VALUES (?,?,?,?,?,?)");
      $stmt->execute([$name,$date,$rep,$lid,$notes, $_SESSION['user']['id'] ?? null]);
      $hid = (int)$conn->lastInsertId();
      $msg = 'Holiday added.';
      $uid = $_SESSION['user']['id'] ?? null;
      audit_bridge_hr_ops(
        'holiday_created',
        'holidays',
        $hid > 0 ? $hid : 0,
        'Created holiday ' . $name . ' on ' . $date,
        null,
        ['name' => $name, 'holiday_date' => $date, 'repeats_annually' => $rep, 'location_id' => $lid],
        'Holiday #' . ($hid > 0 ? $hid : '?'),
        $uid ? (int)$uid : null
      );
    }
  }

  if (isset($_POST['update'])) {
    $id   = (int)$_POST['id'];
    $name = trim($_POST['name'] ?? '');
    $date = ymd($_POST['holiday_date'] ?? '');
    $rep  = isset($_POST['repeats_annually']) ? 1 : 0;
    $lid  = ($_POST['location_id'] ?? '') === '' ? null : (int)$_POST['location_id'];
    $notes= trim($_POST['notes'] ?? '');
    if (!$name || !$date) {
      $err = 'Name and date are required.';
    } else {
      $stmt = $conn->prepare("UPDATE holidays
                              SET name=?, holiday_date=?, repeats_annually=?, location_id=?, notes=?
                              WHERE id=?");
      $stmt->execute([$name,$date,$rep,$lid,$notes,$id]);
      $msg = 'Holiday updated.';
      $uid = $_SESSION['user']['id'] ?? null;
      audit_bridge_hr_ops(
        'holiday_updated',
        'holidays',
        $id,
        'Updated holiday ' . $name . ' on ' . $date,
        null,
        ['name' => $name, 'holiday_date' => $date, 'repeats_annually' => $rep, 'location_id' => $lid],
        'Holiday #' . $id,
        $uid ? (int)$uid : null
      );
    }
  }

  if (isset($_POST['delete'])) {
    $id = (int)$_POST['id'];
    $prev = $conn->prepare("SELECT name, holiday_date FROM holidays WHERE id=? LIMIT 1");
    $prev->execute([$id]);
    $prevRow = $prev->fetch(PDO::FETCH_ASSOC);
    $conn->prepare("DELETE FROM holidays WHERE id=?")->execute([$id]);
    $msg = 'Holiday deleted.';
    $uid = $_SESSION['user']['id'] ?? null;
    audit_bridge_hr_ops(
      'holiday_deleted',
      'holidays',
      $id,
      'Deleted holiday'
        . (!empty($prevRow['name']) ? (' ' . $prevRow['name']) : '')
        . (!empty($prevRow['holiday_date']) ? (' on ' . $prevRow['holiday_date']) : ''),
      null,
      ['name' => $prevRow['name'] ?? null, 'holiday_date' => $prevRow['holiday_date'] ?? null],
      'Holiday #' . $id,
      $uid ? (int)$uid : null
    );
  }
}

// Fetch rows
// We fetch raw rows once, then compute "shown_date" depending on repeats_annually and selected year.
$params = [];
$where  = [];
if ($loc_id !== '') { $where[] = "(h.location_id IS NULL OR h.location_id=?)"; $params[] = $loc_id; } // company-wide or specific
$sql = "SELECT h.*, l.name AS location_name
        FROM holidays h
        LEFT JOIN locations l ON l.id=h.location_id " . ($where ? "WHERE ".implode(' AND ',$where) : "") . "
        ORDER BY MONTH(h.holiday_date), DAY(h.holiday_date), h.name";
$stmt = $conn->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Build display rows with projected dates
$display = [];
foreach ($rows as $r) {
  $stored = new DateTime($r['holiday_date']);
  $shown  = $r['repeats_annually'] ? project_date_for_year($stored, $year) : $stored->format('Y-m-d');
  $display[] = $r + ['shown_date' => $shown];
}
// If not "show all", limit to selected year only (for non-repeating, keep those in that year)
if (!$show_all) {
  $display = array_values(array_filter($display, function($r) use ($year){
    return (int)substr($r['shown_date'],0,4) === $year;
  }));
}
usort($display, fn($a,$b) => strcmp($a['shown_date'], $b['shown_date']));

// Page settings for shared layout
$pageTitle = 'Holidays';
?>
<?php require_once __DIR__ . '/includes/hr_layout_header.php'; ?>

<?php
echo hr_ui_page_header(
    'Holiday Calendar',
    'Company-wide and location-specific public holidays.',
    [
        ['label' => 'HR', 'href' => $hrBase . '/dashboard'],
        ['label' => 'Holidays'],
    ],
    '<a class="btn btn-outline-secondary" href="../operation.php">Back</a>'
);
?>

  <?php if ($err): ?><div class="alert alert-danger"><?= htmlspecialchars($err) ?></div><?php endif; ?>
  <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

  <div class="hr-settings-card mb-4">
    <div class="settings-header">Add / Edit Holiday</div>
    <div class="card-body">
      <form method="post" class="row g-3" id="form-holiday">
    <?php csrf_field(); ?>
        <input type="hidden" name="id" id="h_id">
        <div class="col-md-4">
          <label class="form-label">Name *</label>
          <input class="form-control" name="name" id="h_name" required>
        </div>
        <div class="col-md-3">
          <label class="form-label">Date *</label>
          <input type="date" class="form-control" name="holiday_date" id="h_date" required>
          <div class="form-text">If “Repeats annually”, only month/day matters.</div>
        </div>
        <div class="col-md-3">
          <label class="form-label">Location</label>
          <select class="form-select" name="location_id" id="h_loc">
            <option value="">Company-wide</option>
            <?php foreach ($locations as $l): ?>
              <option value="<?= (int)$l['id'] ?>"><?= htmlspecialchars($l['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2 d-flex align-items-end">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="h_repeats" name="repeats_annually" checked>
            <label class="form-check-label" for="h_repeats">Repeats annually</label>
          </div>
        </div>
        <div class="col-12">
          <label class="form-label">Notes</label>
          <input class="form-control" name="notes" id="h_notes">
        </div>
        <div class="col-12">
          <button class="btn btn-success me-2" name="create" value="1" id="btnCreate">Add Holiday</button>
          <button class="btn btn-primary d-none" name="update" value="1" id="btnUpdate">Update Holiday</button>
          <button type="button" class="btn btn-secondary d-none" id="btnCancelEdit" onclick="cancelEdit()">Cancel</button>
        </div>
      </form>
    </div>
  </div>

  <div class="hr-settings-card">
    <div class="settings-header">
      <form class="row g-2 align-items-center">
        <div class="col-auto">
          <label class="col-form-label">Year</label>
        </div>
        <div class="col-auto">
          <select name="year" class="form-select" onchange="this.form.submit()">
            <?php for ($y=date('Y')-1; $y<=date('Y')+3; $y++): ?>
              <option value="<?= $y ?>" <?= $y==$year?'selected':'' ?>><?= $y ?></option>
            <?php endfor; ?>
          </select>
        </div>
        <div class="col-auto">
          <label class="col-form-label">Location</label>
        </div>
        <div class="col-auto">
          <select name="location_id" class="form-select" onchange="this.form.submit()">
            <option value="" <?= $loc_id===''?'selected':'' ?>>Company-wide + all locations</option>
            <?php foreach ($locations as $l): ?>
              <option value="<?= (int)$l['id'] ?>" <?= ($loc_id!=='' && (int)$loc_id===$l['id'])?'selected':'' ?>>
                <?= htmlspecialchars($l['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-auto">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="all" name="all" value="1" <?= $show_all?'checked':'' ?> onchange="this.form.submit()">
            <label class="form-check-label" for="all">Show all years</label>
          </div>
        </div>
      </form>
    </div>

    <div class="card-body p-0">
    <div class="hr-table-shell border-0 shadow-none rounded-0">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th style="width:130px;">Date</th>
            <th>Name</th>
            <th style="width:180px;">Location</th>
            <th style="width:140px;">Repeats</th>
            <th>Notes</th>
            <th class="text-end" style="width:160px;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$display): ?>
            <tr><td colspan="6" class="text-center text-muted py-4">No holidays found.</td></tr>
          <?php else: foreach ($display as $r): ?>
            <tr>
              <td><?= htmlspecialchars($r['shown_date']) ?></td>
              <td><?= htmlspecialchars($r['name']) ?></td>
              <td><?= $r['location_name'] ? htmlspecialchars($r['location_name']) : 'Company-wide' ?></td>
              <td><?= $r['repeats_annually'] ? 'Annually' : 'One-time' ?></td>
              <td><?= htmlspecialchars($r['notes'] ?? '') ?></td>
              <td class="text-end">
                <button class="btn btn-sm btn-outline-primary"
                        onclick='editRow(<?= json_encode([
                          'id'=>$r['id'],
                          'name'=>$r['name'],
                          'holiday_date'=>$r['holiday_date'],
                          'repeats_annually'=>$r['repeats_annually'],
                          'location_id'=>$r['location_id'],
                          'notes'=>$r['notes']
                        ], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>)'>
                  Edit
                </button>
                <form method="post" class="d-inline" onsubmit="return confirm('Delete this holiday?')">
                  <?php csrf_field(); ?>
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger" name="delete" value="1">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    </div>
  </div>

<script>
function editRow(r){
  document.getElementById('h_id').value = r.id;
  document.getElementById('h_name').value = r.name || '';
  document.getElementById('h_date').value = r.holiday_date || '';
  document.getElementById('h_loc').value = r.location_id ?? '';
  document.getElementById('h_repeats').checked = !!Number(r.repeats_annually);
  document.getElementById('h_notes').value = r.notes || '';
  // toggle buttons
  document.getElementById('btnCreate').classList.add('d-none');
  document.getElementById('btnUpdate').classList.remove('d-none');
  document.getElementById('btnCancelEdit').classList.remove('d-none');
  window.scrollTo({top:0, behavior:'smooth'});
}
function cancelEdit(){
  document.getElementById('form-holiday').reset();
  document.getElementById('h_id').value = '';
  document.getElementById('btnCreate').classList.remove('d-none');
  document.getElementById('btnUpdate').classList.add('d-none');
  document.getElementById('btnCancelEdit').classList.add('d-none');
}
</script>

<?php require_once __DIR__ . '/includes/hr_layout_footer.php'; ?>
