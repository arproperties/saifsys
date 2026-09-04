<?php
// hr/org_units.php


require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/audit_bridge.php';
require_role(['Owner','Admin','HR'], $conn);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

function v($arr,$key,$default=null){ return isset($arr[$key]) ? trim((string)$arr[$key]) : $default; }
function flash($msg,$type='success'){ $_SESSION['flash']=['msg'=>$msg,'type'=>$type]; }

// ---------- column detection helpers ----------
function has_column(PDO $conn, $table, $col){
    static $cache=[];
    $k="$table::$col";
    if(isset($cache[$k])) return $cache[$k];
    $stmt=$conn->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$table,$col]);
    return $cache[$k] = ($stmt->fetchColumn()>0);
}

// ---------- which tab? ----------
    $tab = $_GET['tab'] ?? 'department';
    if (!in_array($tab, ['department','location','reminders','company'], true)) {
        $tab = 'department';
    }
    
/* =========================================================================
   POST handlers for Departments / Locations
   ========================================================================= */
if ($_SERVER['REQUEST_METHOD']==='POST') {
    csrf_verify();
    $kind   = v($_POST,'kind');      // only dept/location forms set this
    $action = v($_POST,'action');

    try {
        if ($kind==='department') {
            $have = [
                'code'      => has_column($conn,'departments','code'),
                'parent_id' => has_column($conn,'departments','parent_id'),
                'status'    => has_column($conn,'departments','status'),
            ];

            if ($action==='create') {
                $name = v($_POST,'name');
                if ($name==='') throw new Exception("Department name is required.");

                $fields = ['name']; $params = [$name];
                if ($have['code'])      { $fields[]='code';      $params[] = v($_POST,'code') ?: null; }
                if ($have['parent_id']) { $fields[]='parent_id'; $params[] = v($_POST,'parent_id')!=='' ? (int)$_POST['parent_id'] : null; }
                if ($have['status'])    { $fields[]='status';    $params[] = v($_POST,'status','active'); }

                $sql = "INSERT INTO departments (".implode(',',$fields).") VALUES (".implode(',',array_fill(0,count($fields),'?')).")";
                $conn->prepare($sql)->execute($params);
                $newDeptId = (int)$conn->lastInsertId();
                flash("Department added.");
                $uid = $_SESSION['user']['id'] ?? null;
                audit_bridge_hr_ops(
                    'department_created',
                    'departments',
                    $newDeptId > 0 ? $newDeptId : 0,
                    'Created department ' . $name,
                    null,
                    ['name' => $name],
                    'Department #' . ($newDeptId > 0 ? $newDeptId : '?'),
                    $uid ? (int)$uid : null
                );
            }
            elseif ($action==='update') {
                $id   = (int)v($_POST,'id',0);
                $name = v($_POST,'name');
                if ($name==='') throw new Exception("Department name is required.");

                $sets  = ['name=?']; $params=[$name];
                if ($have['code'])      { $sets[]='code=?';      $params[] = v($_POST,'code') ?: null; }
                if ($have['parent_id']) { $sets[]='parent_id=?'; $params[] = v($_POST,'parent_id')!=='' ? (int)$_POST['parent_id'] : null; }
                if ($have['status'])    { $sets[]='status=?';    $params[] = v($_POST,'status','active'); }
                $params[]=$id;

                $sql = "UPDATE departments SET ".implode(',',$sets)." WHERE id=?";
                $conn->prepare($sql)->execute($params);
                flash("Department updated.");
                $uid = $_SESSION['user']['id'] ?? null;
                audit_bridge_hr_ops(
                    'department_updated',
                    'departments',
                    $id,
                    'Updated department ' . $name,
                    null,
                    ['name' => $name],
                    'Department #' . $id,
                    $uid ? (int)$uid : null
                );
            }
            elseif ($action==='delete') {
                $delId = (int)v($_POST,'id',0);
                $dn = '';
                try {
                    $st = $conn->prepare("SELECT name FROM departments WHERE id=? LIMIT 1");
                    $st->execute([$delId]);
                    $dn = (string)($st->fetchColumn() ?: '');
                } catch (Throwable $e) {
                    $dn = '';
                }
                $conn->prepare("DELETE FROM departments WHERE id=?")->execute([$delId]);
                flash("Department removed.","warning");
                $uid = $_SESSION['user']['id'] ?? null;
                audit_bridge_hr_ops(
                    'department_deleted',
                    'departments',
                    $delId,
                    'Deleted department' . ($dn !== '' ? (' ' . $dn) : ''),
                    null,
                    ['name' => $dn !== '' ? $dn : null],
                    'Department #' . $delId,
                    $uid ? (int)$uid : null
                );
            }
        }
        elseif ($kind==='location') {
            $have = [
                'code'    => has_column($conn,'locations','code'),
                'address' => has_column($conn,'locations','address'),
                'city'    => has_column($conn,'locations','city'),
                'country' => has_column($conn,'locations','country'),
                'status'  => has_column($conn,'locations','status'),
            ];

            if ($action==='create') {
                $name = v($_POST,'name');
                if ($name==='') throw new Exception("Location name is required.");

                $fields=['name']; $params=[$name];
                if ($have['code'])    { $fields[]='code';    $params[]=v($_POST,'code') ?: null; }
                if ($have['address']) { $fields[]='address'; $params[]=v($_POST,'address') ?: null; }
                if ($have['city'])    { $fields[]='city';    $params[]=v($_POST,'city') ?: null; }
                if ($have['country']) { $fields[]='country'; $params[]=v($_POST,'country') ?: null; }
                if ($have['status'])  { $fields[]='status';  $params[]=v($_POST,'status','active'); }

                $sql="INSERT INTO locations (".implode(',',$fields).") VALUES (".implode(',',array_fill(0,count($fields),'?')).")";
                $conn->prepare($sql)->execute($params);
                $newLocId = (int)$conn->lastInsertId();
                flash("Location added.");
                $uid = $_SESSION['user']['id'] ?? null;
                audit_bridge_hr_ops(
                    'location_created',
                    'locations',
                    $newLocId > 0 ? $newLocId : 0,
                    'Created location ' . $name,
                    null,
                    ['name' => $name],
                    'Location #' . ($newLocId > 0 ? $newLocId : '?'),
                    $uid ? (int)$uid : null
                );
            }
            elseif ($action==='update') {
                $id = (int)v($_POST,'id',0);
                $name = v($_POST,'name');
                if ($name==='') throw new Exception("Location name is required.");

                $sets=['name=?']; $params=[$name];
                if ($have['code'])    { $sets[]='code=?';    $params[]=v($_POST,'code') ?: null; }
                if ($have['address']) { $sets[]='address=?'; $params[]=v($_POST,'address') ?: null; }
                if ($have['city'])    { $sets[]='city=?';    $params[]=v($_POST,'city') ?: null; }
                if ($have['country']) { $sets[]='country=?'; $params[]=v($_POST,'country') ?: null; }
                if ($have['status'])  { $sets[]='status=?';  $params[]=v($_POST,'status','active'); }
                $params[]=$id;

                $sql="UPDATE locations SET ".implode(',',$sets)." WHERE id=?";
                $conn->prepare($sql)->execute($params);
                flash("Location updated.");
                $uid = $_SESSION['user']['id'] ?? null;
                audit_bridge_hr_ops(
                    'location_updated',
                    'locations',
                    $id,
                    'Updated location ' . $name,
                    null,
                    ['name' => $name],
                    'Location #' . $id,
                    $uid ? (int)$uid : null
                );
            }
            elseif ($action==='delete') {
                $delId = (int)v($_POST,'id',0);
                $ln = '';
                try {
                    $st = $conn->prepare("SELECT name FROM locations WHERE id=? LIMIT 1");
                    $st->execute([$delId]);
                    $ln = (string)($st->fetchColumn() ?: '');
                } catch (Throwable $e) {
                    $ln = '';
                }
                $conn->prepare("DELETE FROM locations WHERE id=?")->execute([$delId]);
                flash("Location removed.","warning");
                $uid = $_SESSION['user']['id'] ?? null;
                audit_bridge_hr_ops(
                    'location_deleted',
                    'locations',
                    $delId,
                    'Deleted location' . ($ln !== '' ? (' ' . $ln) : ''),
                    null,
                    ['name' => $ln !== '' ? $ln : null],
                    'Location #' . $delId,
                    $uid ? (int)$uid : null
                );
            }
        }
        
        elseif ($kind==='company') {
            // one-row table, upsert id=1
            $cols = [
                'legal_name','trade_name','trn','currency_code',
                'address_line1','address_line2','city','state_region','postcode','country',
                'phone','email','website','logo_path',
                'bank_name','bank_account_no','bank_iban','bank_swift'
            ];
            // keep only existing columns (your helper already exists)
            $cols = array_values(array_filter($cols, fn($c)=>has_column($conn,'company_settings',$c)));

            // collect values
            $vals = [];
            foreach ($cols as $c) { $vals[$c] = trim($_POST[$c] ?? ''); }

            // ensure row exists
            $exists = $conn->query("SELECT id FROM company_settings WHERE id=1")->fetchColumn();
            if ($exists) {
                $sets = [];
                $params = [];
                foreach ($cols as $c) { $sets[] = "$c=?"; $params[] = ($vals[$c] !== '' ? $vals[$c] : null); }
                $params[] = 1;
                $sql = "UPDATE company_settings SET ".implode(',', $sets).", updated_at=NOW() WHERE id=?";
                $conn->prepare($sql)->execute($params);
                flash("Company info updated.");
            } else {
                $fields = $cols;
                $place  = array_fill(0, count($cols), '?');
                $params = [];
                foreach ($cols as $c) { $params[] = ($vals[$c] !== '' ? $vals[$c] : null); }
                $sql = "INSERT INTO company_settings (id,".implode(',', $fields).") VALUES (1,".implode(',', $place).")";
                $conn->prepare($sql)->execute($params);
                flash("Company info saved.");
            }

            // redirect back to company tab
            header("Location: ".$_SERVER['PHP_SELF']."?tab=company");
            exit;
        }
        
    } catch (Exception $ex) {
        flash($ex->getMessage(),'danger');
    }

    // Redirect ONLY for dept/location forms
    if (in_array($kind, ['department','location'], true)) {
        header("Location: ".$_SERVER['PHP_SELF']."?tab=".$kind);
        exit;
    }
}

/* =========================================================================
   Load data for Departments & Locations (for their tabs)
   ========================================================================= */
$deptCols = array_merge(['id','name'], array_filter([
    has_column($conn,'departments','code')      ? 'code'      : null,
    has_column($conn,'departments','parent_id') ? 'parent_id' : null,
    has_column($conn,'departments','status')    ? 'status'    : null,
    has_column($conn,'departments','created_at')? 'created_at': null,
]));
$departments = $conn->query("SELECT ".implode(',',$deptCols)." FROM departments ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

$dept_map=[];
if (in_array('id',$deptCols,true) && in_array('name',$deptCols,true)) {
    foreach ($departments as $d) $dept_map[$d['id']] = $d['name'];
}

$locCols = array_merge(['id','name'], array_filter([
    has_column($conn,'locations','code')       ? 'code'       : null,
    has_column($conn,'locations','address')    ? 'address'    : null,
    has_column($conn,'locations','city')       ? 'city'       : null,
    has_column($conn,'locations','country')    ? 'country'    : null,
    has_column($conn,'locations','status')     ? 'status'     : null,
    has_column($conn,'locations','created_at') ? 'created_at' : null,
]));
$locations = $conn->query("SELECT ".implode(',',$locCols)." FROM locations ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
    
    /* =========================================================================
       Company info (load only when needed)
       ========================================================================= */
    $company = [];
    if ($tab==='company' || (($_POST['kind'] ?? null) === 'company')) {
        // pick only columns that exist
        $allCols = [
            'id','legal_name','trade_name','trn','currency_code',
            'address_line1','address_line2','city','state_region','postcode','country',
            'phone','email','website','logo_path',
            'bank_name','bank_account_no','bank_iban','bank_swift',
            'created_at','updated_at'
        ];
        $haveCols = array_values(array_filter($allCols, fn($c)=>has_column($conn,'company_settings',$c)));
        $sel = implode(',', $haveCols);
        $row = $conn->query("SELECT $sel FROM company_settings WHERE id=1")->fetch(PDO::FETCH_ASSOC);
        $company = $row ?: [];
    }

/* =========================================================================
   Reminders tab handlers + data  (process when on tab OR when a reminders form posts)
   ========================================================================= */
$rem_post = ($_SERVER['REQUEST_METHOD']==='POST') && (
       
    isset($_POST['save_email_settings']) || isset($_POST['add_recipient']) || isset($_POST['toggle_recipient']) ||
    isset($_POST['delete_recipient']) || isset($_POST['add_schedule']) || isset($_POST['toggle_schedule']) ||
    isset($_POST['delete_schedule']) || isset($_POST['send_test_email']) || isset($_POST['run_now'])
);
$r_msg = $r_err = '';

if ($tab==='reminders' || $rem_post) {
    // SMTP is canonical in Settings Center — do not save here
    if (isset($_POST['save_email_settings'])) {
        $r_err = 'SMTP is managed in System Settings → Email. This page only manages reminder recipients and schedules.';
        $tab = 'reminders';
    }

    // Recipients
    if (isset($_POST['add_recipient'])) {
        $name = trim($_POST['name'] ?? '');
        $email= trim($_POST['email'] ?? '');
        if ($email!=='') {
            $ins = $conn->prepare("INSERT INTO app_reminder_recipients (name,email,is_active) VALUES (?,?,1)");
            $ins->execute([$name ?: null,$email]);
            $r_msg = 'Recipient added.';
        }
        $tab = 'reminders';
    }
    if (isset($_POST['toggle_recipient'])) {
        $id = (int)$_POST['toggle_recipient'];
        $conn->prepare("UPDATE app_reminder_recipients SET is_active=1-is_active WHERE id=?")->execute([$id]);
        $tab = 'reminders';
    }
    if (isset($_POST['delete_recipient'])) {
        $id = (int)$_POST['delete_recipient'];
        $conn->prepare("DELETE FROM app_reminder_recipients WHERE id=?")->execute([$id]);
        $tab = 'reminders';
    }

    // Schedules
    if (isset($_POST['add_schedule'])) {
        $name = trim($_POST['name'] ?? '');
        $direction = $_POST['direction'] ?? 'before';
        $days = (int)($_POST['days_offset'] ?? 0);
        if ($name!=='' && $days>=0) {
            $ins = $conn->prepare("INSERT INTO app_reminder_schedules (name,direction,days_offset,is_active) VALUES (?,?,?,1)");
            $ins->execute([$name,$direction,$days]);
            $r_msg = 'Schedule added.';
        }
        $tab = 'reminders';
    }
    if (isset($_POST['toggle_schedule'])) {
        $id = (int)$_POST['toggle_schedule'];
        $conn->prepare("UPDATE app_reminder_schedules SET is_active=1-is_active WHERE id=?")->execute([$id]);
        $tab = 'reminders';
    }
    if (isset($_POST['delete_schedule'])) {
        $id = (int)$_POST['delete_schedule'];
        $conn->prepare("DELETE FROM app_reminder_schedules WHERE id=?")->execute([$id]);
        $tab = 'reminders';
    }

    // Send test email
    if (isset($_POST['send_test_email'])) {
        require_once __DIR__.'/../includes/mailer.php';
        $s = $conn->query("SELECT * FROM app_email_settings WHERE id=1")->fetch(PDO::FETCH_ASSOC) ?: [];
        $to = trim($_POST['test_to'] ?? '');
        if ($to) {
            $res = send_smtp_mail($s, $to, '[HR] Test email', '<p>If you received this, SMTP works.</p>');
            if ($res['ok']) {
                $r_msg = 'Test email sent.';
            } else {
                $r_err = 'Failed to send: ' . $res['error'];
            }
        } else {
            $r_err = 'Please enter a valid test email address.';
        }
        $tab = 'reminders';
    }

    // ---- Save email template
    if (isset($_POST['save_template'])) {
        $subj = trim($_POST['tpl_subject'] ?? '');
        $body = trim($_POST['tpl_body'] ?? '');
        if ($subj === '' || $body === '') {
            $r_err = 'Subject and body are required.';
        } else {
            $row = $conn->query("SELECT id FROM app_email_templates WHERE code='doc_expiry_reminder'")->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $up = $conn->prepare("UPDATE app_email_templates SET subject=?, body_html=? WHERE code='doc_expiry_reminder'");
                $up->execute([$subj,$body]);
            } else {
                $ins = $conn->prepare("INSERT INTO app_email_templates (code,subject,body_html) VALUES ('doc_expiry_reminder',?,?)");
                $ins->execute([$subj,$body]);
            }
            $r_msg = 'Template saved.';
        }
    }

    // ---- Purge logs older than N days
    if (isset($_POST['purge_logs'])) {
        $days = max(1, (int)($_POST['days'] ?? 90));
        $del  = $conn->prepare("DELETE FROM app_reminder_logs WHERE sent_at < (NOW() - INTERVAL ? DAY)");
        $del->execute([$days]);
        $r_msg = "Logs older than {$days} days purged.";
    }
    
    // Run now (manual)
    if (isset($_POST['run_now'])) {
        require_once __DIR__.'/../cron/document_reminders.php';
        $res = run_document_reminders($conn, false, null);
        $r_msg = "Run complete. Found: ".(int)$res['found']
            . " · Eligible: ".(int)$res['eligible']
            . " · Sent: ".(int)$res['sent']
            . " · Skipped: ".(int)($res['skipped'] ?? 0)
            . " · Failed: ".(int)($res['failed'] ?? 0);
        $tab = 'reminders';
    }

    // Load for UI
    
    // Template row
    $tpl = $conn->prepare("SELECT subject, body_html FROM app_email_templates WHERE code='doc_expiry_reminder' LIMIT 1");
    $tpl->execute();
    $template = $tpl->fetch(PDO::FETCH_ASSOC) ?: ['subject'=>'','body_html'=>''];

    // Recent logs
    $logs = $conn->query("
      SELECT l.*, ars.name AS schedule_name, ars.direction, ars.days_offset
      FROM app_reminder_logs l
      LEFT JOIN app_reminder_schedules ars ON ars.id=l.schedule_id
      ORDER BY l.sent_at DESC
      LIMIT 200
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    
    $email = $conn->query("SELECT * FROM app_email_settings WHERE id=1")->fetch(PDO::FETCH_ASSOC) ?: [
        'smtp_host'=>'','smtp_port'=>587,'smtp_secure'=>'tls','smtp_username'=>'','smtp_password'=>'',
        'from_name'=>'','from_email'=>'','is_enabled'=>0
    ];
    $recipients = $conn->query("SELECT * FROM app_reminder_recipients ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
    $schedules  = $conn->query("SELECT * FROM app_reminder_schedules ORDER BY direction, days_offset")->fetchAll(PDO::FETCH_ASSOC);
}

// Page settings for shared layout
$pageTitle = 'Organization';
?>
<?php require_once __DIR__ . '/includes/hr_layout_header.php'; ?>

<?php
echo hr_ui_page_header(
    'Organization Setup',
    'Departments, locations, reminders, and company profile.',
    [
        ['label' => 'HR', 'href' => $hrBase . '/dashboard'],
        ['label' => 'Organization'],
    ],
    '<a href="../operation.php" class="btn btn-outline-secondary">Back</a>'
);
?>

  <?php if(!empty($_SESSION['flash'])): $f=$_SESSION['flash']; unset($_SESSION['flash']); ?>
    <div class="alert alert-<?= htmlspecialchars($f['type']) ?>"><?= htmlspecialchars($f['msg']) ?></div>
  <?php endif; ?>

      <ul class="nav nav-tabs mb-3">
        <li class="nav-item"><a class="nav-link <?= $tab==='department'?'active':'' ?>" href="?tab=department">Departments</a></li>
        <li class="nav-item"><a class="nav-link <?= $tab==='location'?'active':'' ?>" href="?tab=location">Locations</a></li>
        <li class="nav-item"><a class="nav-link <?= $tab==='reminders'?'active':'' ?>" href="?tab=reminders">Reminders</a></li>
        <li class="nav-item"><a class="nav-link <?= $tab==='company'?'active':'' ?>" href="?tab=company">Company info</a></li>
      </ul>

  <?php if ($tab==='department'): ?>

    <div class="hr-settings-card mb-4">
      <div class="settings-header">Add Department</div>
      <div class="card-body">
        <form method="post" class="row g-3">
      <?php csrf_field(); ?>
          <input type="hidden" name="kind" value="department">
          <input type="hidden" name="action" value="create">
          <div class="col-md-4">
            <label class="form-label">Name *</label>
            <input type="text" name="name" class="form-control" required>
          </div>
          <?php if (has_column($conn,'departments','code')): ?>
          <div class="col-md-3">
            <label class="form-label">Code</label>
            <input type="text" name="code" class="form-control">
          </div>
          <?php endif; ?>
          <?php if (has_column($conn,'departments','parent_id')): ?>
          <div class="col-md-3">
            <label class="form-label">Parent</label>
            <select name="parent_id" class="form-select">
              <option value="">— None —</option>
              <?php foreach($departments as $d): ?>
                <option value="<?= (int)$d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>
          <?php if (has_column($conn,'departments','status')): ?>
          <div class="col-md-2">
            <label class="form-label">Status</label>
            <select name="status" class="form-select">
              <option value="active">Active</option>
              <option value="archived">Archived</option>
            </select>
          </div>
          <?php endif; ?>
          <div class="col-12 text-end">
            <button class="btn btn-success">Save</button>
          </div>
        </form>
      </div>
    </div>

    <div class="hr-settings-card">
      <div class="settings-header">Departments</div>
      <div class="card-body p-0">
        <div class="hr-table-shell border-0 shadow-none rounded-0">
          <table class="table table-striped mb-0">
            <thead class="table-light">
              <tr>
                <th>Name</th>
                <?php if (has_column($conn,'departments','code')): ?><th>Code</th><?php endif; ?>
                <?php if (has_column($conn,'departments','parent_id')): ?><th>Parent</th><?php endif; ?>
                <?php if (has_column($conn,'departments','status')): ?><th>Status</th><?php endif; ?>
                <?php if (has_column($conn,'departments','created_at')): ?><th>Created</th><?php endif; ?>
                <th class="text-end">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach($departments as $d): ?>
              <tr>
                <td><?= htmlspecialchars($d['name']) ?></td>
                <?php if (isset($d['code'])): ?><td><?= htmlspecialchars($d['code']) ?></td><?php endif; ?>
                <?php if (isset($d['parent_id'])): ?><td><?= isset($dept_map[$d['parent_id']]) ? htmlspecialchars($dept_map[$d['parent_id']]) : '—' ?></td><?php endif; ?>
                <?php if (isset($d['status'])): ?><td><span class="badge bg-<?= $d['status']==='active'?'success':'secondary' ?>"><?= htmlspecialchars($d['status']) ?></span></td><?php endif; ?>
                <?php if (isset($d['created_at'])): ?><td><?= htmlspecialchars($d['created_at']) ?></td><?php endif; ?>
                <td class="text-end">
                  <button class="btn btn-sm btn-outline-primary" onclick="fillDept(<?= (int)$d['id'] ?>)">Edit</button>
                  <form method="post" class="d-inline" onsubmit="return confirm('Delete this department?')">
                    <input type="hidden" name="kind" value="department">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                    <button class="btn btn-sm btn-outline-danger">Delete</button>
                  </form>
                </td>
              </tr>
              <?php endforeach; if(!$departments): ?>
              <tr><td colspan="6" class="text-center text-muted py-4">No departments yet.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <div id="deptEdit" class="card mt-4 d-none">
      <div class="card-header bg-warning">Edit Department</div>
      <div class="card-body">
        <form method="post" class="row g-3">
                    <?php csrf_field(); ?>
          <input type="hidden" name="kind" value="department">
          <input type="hidden" name="action" value="update">
          <input type="hidden" name="id" id="dep_id">
          <div class="col-md-4">
            <label class="form-label">Name *</label>
            <input type="text" name="name" id="dep_name" class="form-control" required>
          </div>
          <?php if (has_column($conn,'departments','code')): ?>
          <div class="col-md-3">
            <label class="form-label">Code</label>
            <input type="text" name="code" id="dep_code" class="form-control">
          </div>
          <?php endif; ?>
          <?php if (has_column($conn,'departments','parent_id')): ?>
          <div class="col-md-3">
            <label class="form-label">Parent</label>
            <select name="parent_id" id="dep_parent" class="form-select">
              <option value="">— None —</option>
              <?php foreach($departments as $d): ?>
                <option value="<?= (int)$d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>
          <?php if (has_column($conn,'departments','status')): ?>
          <div class="col-md-2">
            <label class="form-label">Status</label>
            <select name="status" id="dep_status" class="form-select">
              <option value="active">Active</option>
              <option value="archived">Archived</option>
            </select>
          </div>
          <?php endif; ?>
          <div class="col-12 text-end">
            <button class="btn btn-primary">Update</button>
            <button type="button" class="btn btn-outline-secondary" onclick="hideEdit('deptEdit')">Cancel</button>
          </div>
        </form>
      </div>
    </div>

  <?php elseif ($tab==='location'): ?>

    <div class="hr-settings-card mb-4">
      <div class="settings-header">Add Location</div>
      <div class="card-body">
        <form method="post" class="row g-3">
                    <?php csrf_field(); ?>
          <input type="hidden" name="kind" value="location">
          <input type="hidden" name="action" value="create">
          <div class="col-md-4">
            <label class="form-label">Name *</label>
            <input type="text" name="name" class="form-control" required>
          </div>
          <?php if (has_column($conn,'locations','code')): ?>
          <div class="col-md-3">
            <label class="form-label">Code</label>
            <input type="text" name="code" class="form-control">
          </div>
          <?php endif; ?>
          <?php if (has_column($conn,'locations','address')): ?>
          <div class="col-md-5">
            <label class="form-label">Address</label>
            <input type="text" name="address" class="form-control">
          </div>
          <?php endif; ?>
          <?php if (has_column($conn,'locations','city')): ?>
          <div class="col-md-3">
            <label class="form-label">City</label>
            <input type="text" name="city" class="form-control">
          </div>
          <?php endif; ?>
          <?php if (has_column($conn,'locations','country')): ?>
          <div class="col-md-3">
            <label class="form-label">Country</label>
            <input type="text" name="country" class="form-control">
          </div>
          <?php endif; ?>
          <?php if (has_column($conn,'locations','status')): ?>
          <div class="col-md-3">
            <label class="form-label">Status</label>
            <select name="status" class="form-select">
              <option value="active">Active</option>
              <option value="archived">Archived</option>
            </select>
          </div>
          <?php endif; ?>
          <div class="col-12 text-end">
            <button class="btn btn-success">Save</button>
          </div>
        </form>
      </div>
    </div>

    <div class="hr-settings-card">
      <div class="settings-header">Locations</div>
      <div class="card-body p-0">
        <div class="hr-table-shell border-0 shadow-none rounded-0">
          <table class="table table-striped mb-0">
            <thead class="table-light">
              <tr>
                <th>Name</th>
                <?php if (has_column($conn,'locations','code')): ?><th>Code</th><?php endif; ?>
                <?php if (has_column($conn,'locations','address')): ?><th>Address</th><?php endif; ?>
                <?php if (has_column($conn,'locations','city')): ?><th>City</th><?php endif; ?>
                <?php if (has_column($conn,'locations','country')): ?><th>Country</th><?php endif; ?>
                <?php if (has_column($conn,'locations','status')): ?><th>Status</th><?php endif; ?>
                <?php if (has_column($conn,'locations','created_at')): ?><th>Created</th><?php endif; ?>
                <th class="text-end">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach($locations as $l): ?>
              <tr>
                <td><?= htmlspecialchars($l['name']) ?></td>
                <?php if (isset($l['code'])): ?><td><?= htmlspecialchars($l['code']) ?></td><?php endif; ?>
                <?php if (isset($l['address'])): ?><td><?= htmlspecialchars($l['address']) ?></td><?php endif; ?>
                <?php if (isset($l['city'])): ?><td><?= htmlspecialchars($l['city']) ?></td><?php endif; ?>
                <?php if (isset($l['country'])): ?><td><?= htmlspecialchars($l['country']) ?></td><?php endif; ?>
                <?php if (isset($l['status'])): ?><td><span class="badge bg-<?= $l['status']==='active'?'success':'secondary' ?>"><?= htmlspecialchars($l['status']) ?></span></td><?php endif; ?>
                <?php if (isset($l['created_at'])): ?><td><?= htmlspecialchars($l['created_at']) ?></td><?php endif; ?>
                <td class="text-end">
                  <button class="btn btn-sm btn-outline-primary" onclick="fillLoc(<?= (int)$l['id'] ?>)">Edit</button>
                  <form method="post" class="d-inline" onsubmit="return confirm('Delete this location?')">
                    <input type="hidden" name="kind" value="location">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
                    <button class="btn btn-sm btn-outline-danger">Delete</button>
                  </form>
                </td>
              </tr>
              <?php endforeach; if(!$locations): ?>
              <tr><td colspan="8" class="text-center text-muted py-4">No locations yet.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <div id="locEdit" class="card mt-4 d-none">
      <div class="card-header bg-warning">Edit Location</div>
      <div class="card-body">
        <form method="post" class="row g-3">
          <input type="hidden" name="kind" value="location">
          <input type="hidden" name="action" value="update">
          <input type="hidden" name="id" id="loc_id">
          <div class="col-md-4">
            <label class="form-label">Name *</label>
            <input type="text" name="name" id="loc_name" class="form-control" required>
          </div>
          <?php if (has_column($conn,'locations','code')): ?>
          <div class="col-md-3">
            <label class="form-label">Code</label>
            <input type="text" name="code" id="loc_code" class="form-control">
          </div>
          <?php endif; ?>
          <?php if (has_column($conn,'locations','address')): ?>
          <div class="col-md-5">
            <label class="form-label">Address</label>
            <input type="text" name="address" id="loc_address" class="form-control">
          </div>
          <?php endif; ?>
          <?php if (has_column($conn,'locations','city')): ?>
          <div class="col-md-3">
            <label class="form-label">City</label>
            <input type="text" name="city" id="loc_city" class="form-control">
          </div>
          <?php endif; ?>
          <?php if (has_column($conn,'locations','country')): ?>
          <div class="col-md-3">
            <label class="form-label">Country</label>
            <input type="text" name="country" id="loc_country" class="form-control">
          </div>
          <?php endif; ?>
          <?php if (has_column($conn,'locations','status')): ?>
          <div class="col-md-3">
            <label class="form-label">Status</label>
            <select name="status" id="loc_status" class="form-select">
              <option value="active">Active</option>
              <option value="archived">Archived</option>
            </select>
          </div>
          <?php endif; ?>
          <div class="col-12 text-end">
            <button class="btn btn-primary">Update</button>
            <button type="button" class="btn btn-outline-secondary" onclick="hideEdit('locEdit')">Cancel</button>
          </div>
        </form>
      </div>
    </div>

  <?php elseif ($tab==='reminders'): ?>

    <div class="card mt-3">
      <div class="card-body">
        <div class="d-flex">
          <h5 class="mb-0">Document Expiry Reminders</h5>
          <form method="post" class="ms-auto">
            <?php csrf_field(); ?>
            <button class="btn btn-outline-primary btn-sm" name="run_now" value="1">Run now</button>
          </form>
        </div>

        <?php if ($r_msg): ?><div class="alert alert-success mt-3"><?= htmlspecialchars($r_msg) ?></div><?php endif; ?>
        <?php if ($r_err): ?><div class="alert alert-danger  mt-3"><?= htmlspecialchars($r_err) ?></div><?php endif; ?>

        <!-- Sender / SMTP (canonical in Settings Center) -->
        <h6 class="mt-4">Sender Email Settings</h6>
        <div class="alert alert-light border small">
          SMTP is configured in
          <a href="<?= htmlspecialchars((function_exists('get_base_path') ? get_base_path() : '') . '/settings.php?tab=email') ?>">
            System Settings → Email
          </a>
          (canonical). Current status:
          <strong><?= !empty($email['is_enabled']) ? 'Enabled' : 'Disabled' ?></strong>
          <?php if (!empty($email['from_email'])): ?>
            · from <?= htmlspecialchars($email['from_email']) ?>
          <?php endif; ?>.
          Use the test form below after SMTP is configured there.
        </div>

        <form method="post" class="row g-2 mt-3">
          <?php csrf_field(); ?>
          <input type="hidden" name="send_test_email" value="1">
          <div class="col-md-4">
            <label class="form-label">Send test to</label>
            <input class="form-control" type="email" name="test_to" placeholder="name@example.com">
          </div>
          <div class="col-md-2 align-self-end">
            <button class="btn btn-outline-secondary">Send Test</button>
          </div>
        </form>

        <!-- Schedules -->
        <h6 class="mt-4">Reminder Schedules</h6>
        <form method="post" class="row g-2 mb-2">
          <?php csrf_field(); ?>
          <input type="hidden" name="add_schedule" value="1">
          <div class="col-md-4">
            <label class="form-label">Name</label>
            <input class="form-control" name="name" placeholder="e.g. 60 days before">
          </div>
          <div class="col-md-3">
            <label class="form-label">Direction</label>
            <select class="form-select" name="direction">
              <option value="before">Before expiry</option>
              <option value="after">After expiry</option>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label">Days offset</label>
            <input class="form-control" type="number" min="0" name="days_offset" placeholder="e.g. 60">
          </div>
          <div class="col-md-2 align-self-end">
            <button class="btn btn-primary">Add</button>
          </div>
        </form>

        <div class="table-responsive">
          <table class="table table-bordered align-middle">
            <thead class="table-light">
              <tr><th>Name</th><th>Direction</th><th>Days</th><th>Status</th><th class="text-end">Actions</th></tr>
            </thead>
            <tbody>
              <?php if (empty($schedules)): ?>
                <tr><td colspan="5" class="text-center text-muted">No schedules yet. Add one above.</td></tr>
              <?php else: foreach ($schedules as $sc): ?>
                <tr>
                  <td><?= htmlspecialchars($sc['name']) ?></td>
                  <td><?= htmlspecialchars(ucfirst($sc['direction'])) ?></td>
                  <td><?= (int)$sc['days_offset'] ?></td>
                  <td><span class="badge text-bg-<?= $sc['is_active']?'success':'secondary' ?>"><?= $sc['is_active']?'active':'inactive' ?></span></td>
                  <td class="text-end">
                    <form method="post" class="d-inline">
                      <?php csrf_field(); ?>
                      <button class="btn btn-sm btn-outline-secondary" name="toggle_schedule" value="<?= (int)$sc['id'] ?>"><?= $sc['is_active']?'Disable':'Enable' ?></button>
                    </form>
                    <form method="post" class="d-inline" onsubmit="return confirm('Delete schedule?')">
                      <?php csrf_field(); ?>
                      <button class="btn btn-sm btn-outline-danger" name="delete_schedule" value="<?= (int)$sc['id'] ?>">Delete</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>

        <!-- Recipients -->
        <h6 class="mt-4">Recipients</h6>
        <form method="post" class="row g-2 mb-2">
          <?php csrf_field(); ?>
          <input type="hidden" name="add_recipient" value="1">
          <div class="col-md-4">
            <label class="form-label">Name</label>
            <input class="form-control" name="name" placeholder="e.g. HR Manager">
          </div>
          <div class="col-md-4">
            <label class="form-label">Email *</label>
            <input class="form-control" name="email" type="email" required placeholder="hr@example.com">
          </div>
          <div class="col-md-2 align-self-end">
            <button class="btn btn-primary">Add</button>
          </div>
        </form>

        <div class="table-responsive">
          <table class="table table-bordered align-middle">
            <thead class="table-light">
              <tr><th>Name</th><th>Email</th><th>Status</th><th class="text-end">Actions</th></tr>
            </thead>
            <tbody>
              <?php if (empty($recipients)): ?>
                <tr><td colspan="4" class="text-center text-muted">No recipients yet.</td></tr>
              <?php else: foreach ($recipients as $rc): ?>
                <tr>
                  <td><?= htmlspecialchars($rc['name'] ?: '—') ?></td>
                  <td><?= htmlspecialchars($rc['email']) ?></td>
                  <td><span class="badge text-bg-<?= $rc['is_active']?'success':'secondary' ?>"><?= $rc['is_active']?'active':'inactive' ?></span></td>
                  <td class="text-end">
                    <form method="post" class="d-inline">
                      <?php csrf_field(); ?>
                      <button class="btn btn-sm btn-outline-secondary" name="toggle_recipient" value="<?= (int)$rc['id'] ?>"><?= $rc['is_active']?'Disable':'Enable' ?></button>
                    </form>
                    <form method="post" class="d-inline" onsubmit="return confirm('Delete recipient?')">
                      <?php csrf_field(); ?>
                      <button class="btn btn-sm btn-outline-danger" name="delete_recipient" value="<?= (int)$rc['id'] ?>">Delete</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
                    
                    <!-- Email Template -->
                    <h6 class="mt-4">Email Template</h6>
                    <form method="post" class="row g-2">
                      <?php csrf_field(); ?>
                      <input type="hidden" name="save_template" value="1">
                      <div class="col-12">
                        <label class="form-label">Subject</label>
                        <input class="form-control" name="tpl_subject" value="<?= htmlspecialchars($template['subject']) ?>" placeholder="Subject">
                      </div>
                      <div class="col-12">
                        <label class="form-label">Body (HTML)</label>
                        <textarea class="form-control" name="tpl_body" rows="8" spellcheck="false"
                          placeholder="HTML with placeholders like {employee_name}, {doc_type}, {when_phrase}"><?= htmlspecialchars($template['body_html']) ?></textarea>
                      </div>
                      <div class="col-12 d-flex">
                        <div class="small text-muted">
                          Placeholders: {recipient_name_or_team}, {employee_name}, {employee_code}, {employee_company}, {doc_type}, {expiry_date}, {when_phrase}, {company_name}
                        </div>
                        <button class="btn btn-success ms-auto">Save Template</button>
                      </div>
                    </form>
      
      <!-- Logs -->
      <h6 class="mt-4">Reminder Logs (latest 200)</h6>
      <form method="post" class="d-flex align-items-center mb-2">
        <?php csrf_field(); ?>
        <input type="hidden" name="purge_logs" value="1">
        <span class="me-2 small text-muted">Purge older than</span>
        <input type="number" class="form-control form-control-sm me-2" name="days" value="90" style="width:100px">
        <span class="me-3 small text-muted">days</span>
        <button class="btn btn-sm btn-outline-danger">Purge</button>
      </form>

      <div class="table-responsive">
        <table class="table table-bordered table-sm align-middle">
          <thead class="table-light">
            <tr>
              <th style="width:130px;">Sent at</th>
              <th>Recipient</th>
              <th>Subject</th>
              <th style="width:120px;">Schedule</th>
              <th style="width:90px;">Status</th>
              <th>Note</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$logs): ?>
              <tr><td colspan="6" class="text-center text-muted py-3">No logs yet.</td></tr>
            <?php else: foreach ($logs as $lg): ?>
              <tr>
                <td><?= htmlspecialchars($lg['sent_at']) ?></td>
                <td><?= htmlspecialchars($lg['recipient_email']) ?></td>
                <td><?= htmlspecialchars($lg['subject']) ?></td>
                <td>
                  <?php
                    $dn = $lg['direction'] ? ucfirst($lg['direction']) : '—';
                    $do = isset($lg['days_offset']) ? (int)$lg['days_offset'] : null;
                    echo $lg['schedule_name'] ? htmlspecialchars($lg['schedule_name']) : "{$dn} ".($do!==null?$do:'')."d";
                  ?>
                </td>
                <td><span class="badge text-bg-<?= $lg['status']==='sent'?'success':($lg['status']==='failed'?'danger':'secondary') ?>">
                  <?= htmlspecialchars($lg['status']) ?></span>
                </td>
                <td class="text-muted small"><?= htmlspecialchars($lg['error_text'] ?: '') ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
                    
        <div class="small text-muted mt-3">
          <strong>How it works:</strong> the cron script <code>cron/document_reminders.php</code> should run daily. For each active
          schedule, it finds current employees' documents whose expiry matches the “days before/after” rule and emails all active
          recipients. Each daily run logs recipient + document + schedule combinations to avoid duplicates.
        </div>
      </div>
    </div>

                <?php elseif ($tab==='company'): ?>

                  <div class="card">
                    <div class="card-header">Company info</div>
                    <div class="card-body">
                      <form method="post" class="row g-3">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="kind" value="company">
                        <input type="hidden" name="action" value="save">

                        <h6 class="mt-2">Identity</h6>
                        <div class="col-md-6">
                          <label class="form-label">Legal name</label>
                          <input class="form-control" name="legal_name" value="<?= htmlspecialchars($company['legal_name'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                          <label class="form-label">Trade name</label>
                          <input class="form-control" name="trade_name" value="<?= htmlspecialchars($company['trade_name'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                          <label class="form-label">TRN</label>
                          <input class="form-control" name="trn" value="<?= htmlspecialchars($company['trn'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                          <label class="form-label">Currency code</label>
                          <input class="form-control" name="currency_code" placeholder="AED" value="<?= htmlspecialchars($company['currency_code'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                          <label class="form-label">Phone</label>
                          <input class="form-control" name="phone" value="<?= htmlspecialchars($company['phone'] ?? '') ?>">
                        </div>

                        <h6 class="mt-4">Address</h6>
                        <div class="col-12">
                          <label class="form-label">Address line 1</label>
                          <input class="form-control" name="address_line1" value="<?= htmlspecialchars($company['address_line1'] ?? '') ?>">
                        </div>
                        <div class="col-12">
                          <label class="form-label">Address line 2</label>
                          <input class="form-control" name="address_line2" value="<?= htmlspecialchars($company['address_line2'] ?? '') ?>">
                        </div>
                        <div class="col-md-3">
                          <label class="form-label">City</label>
                          <input class="form-control" name="city" value="<?= htmlspecialchars($company['city'] ?? '') ?>">
                        </div>
                        <div class="col-md-3">
                          <label class="form-label">State/Region</label>
                          <input class="form-control" name="state_region" value="<?= htmlspecialchars($company['state_region'] ?? '') ?>">
                        </div>
                        <div class="col-md-3">
                          <label class="form-label">Postcode</label>
                          <input class="form-control" name="postcode" value="<?= htmlspecialchars($company['postcode'] ?? '') ?>">
                        </div>
                        <div class="col-md-3">
                          <label class="form-label">Country</label>
                          <input class="form-control" name="country" value="<?= htmlspecialchars($company['country'] ?? '') ?>">
                        </div>

                        <h6 class="mt-4">Contacts / Branding</h6>
                        <div class="col-md-4">
                          <label class="form-label">Email</label>
                          <input class="form-control" type="email" name="email" value="<?= htmlspecialchars($company['email'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                          <label class="form-label">Website</label>
                          <input class="form-control" name="website" value="<?= htmlspecialchars($company['website'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                          <label class="form-label">Logo path (URL)</label>
                          <input class="form-control" name="logo_path" placeholder="/assets/logo.png" value="<?= htmlspecialchars($company['logo_path'] ?? '') ?>">
                        </div>

                        <h6 class="mt-4">Banking</h6>
                        <div class="col-md-4">
                          <label class="form-label">Bank name</label>
                          <input class="form-control" name="bank_name" value="<?= htmlspecialchars($company['bank_name'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                          <label class="form-label">Account no.</label>
                          <input class="form-control" name="bank_account_no" value="<?= htmlspecialchars($company['bank_account_no'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                          <label class="form-label">IBAN</label>
                          <input class="form-control" name="bank_iban" value="<?= htmlspecialchars($company['bank_iban'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                          <label class="form-label">SWIFT</label>
                          <input class="form-control" name="bank_swift" value="<?= htmlspecialchars($company['bank_swift'] ?? '') ?>">
                        </div>

                        <div class="col-12 text-end mt-2">
                          <button class="btn btn-success">Save Company Info</button>
                        </div>

                        <?php if (!empty($company['updated_at'])): ?>
                          <div class="text-muted small mt-1">Last updated: <?= htmlspecialchars($company['updated_at']) ?></div>
                        <?php endif; ?>
                      </form>
                    </div>
                  </div>

                
                
  <?php endif; ?>
</div>

<script>
  const depts = <?= json_encode($departments, JSON_UNESCAPED_UNICODE) ?>;
  const locs  = <?= json_encode($locations, JSON_UNESCAPED_UNICODE) ?>;
  function fillDept(id){
    const d = depts.find(x=>+x.id===+id); if(!d) return;
    document.getElementById('deptEdit').classList.remove('d-none');
    document.getElementById('dep_id').value   = d.id;
    document.getElementById('dep_name').value = d.name ?? '';
    const code = document.getElementById('dep_code'); if(code) code.value = d.code ?? '';
    const parent = document.getElementById('dep_parent'); if(parent) parent.value = d.parent_id ?? '';
    const status = document.getElementById('dep_status'); if(status) status.value = d.status ?? 'active';
    window.scrollTo({top:document.getElementById('deptEdit').offsetTop-80,behavior:'smooth'});
  }
  function fillLoc(id){
    const l = locs.find(x=>+x.id===+id); if(!l) return;
    document.getElementById('locEdit').classList.remove('d-none');
    document.getElementById('loc_id').value   = l.id;
    document.getElementById('loc_name').value = l.name ?? '';
    const code = document.getElementById('loc_code'); if(code) code.value = l.code ?? '';
    const addr = document.getElementById('loc_address'); if(addr) addr.value = l.address ?? '';
    const city = document.getElementById('loc_city'); if(city) city.value = l.city ?? '';
    const country = document.getElementById('loc_country'); if(country) country.value = l.country ?? '';
    const status = document.getElementById('loc_status'); if(status) status.value = l.status ?? 'active';
    window.scrollTo({top:document.getElementById('locEdit').offsetTop-80,behavior:'smooth'});
  }
  function hideEdit(id){ document.getElementById(id).classList.add('d-none'); }
</script>

<?php require_once __DIR__ . '/includes/hr_layout_footer.php'; ?>
