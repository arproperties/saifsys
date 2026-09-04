<?php


require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db_connect.php';
require_once __DIR__.'/../includes/audit_bridge.php';
require_once __DIR__ . '/includes/hr_company_scope.php';
require_once __DIR__ . '/includes/hr_employee_lifecycle.php';
require_once __DIR__ . '/includes/hr_document_file_helper.php';
require_role(['Owner','Admin','HR'], $conn);

// --------- Helpers ----------
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function badgeDate($date){
    if(!$date) return '';
    $d = strtotime($date);
    $today = strtotime(date('Y-m-d'));
    $diff = (int)floor(($d - $today)/86400);
    if ($diff < 0) return '<span class="badge bg-danger">Expired</span>';
    if ($diff <= 30) return '<span class="badge bg-warning text-dark">'.$diff.'d</span>';
    return '<span class="badge bg-success">'.$diff.'d</span>';
}

// Ensure upload dir
$uploads_root = __DIR__ . '/../uploads/docs';
if (!is_dir($uploads_root)) {
    @mkdir($uploads_root, 0775, true);
}

// --------- Handle POST (save / delete / add-type) ----------
if ($_SERVER['REQUEST_METHOD']==='POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $doc_id       = isset($_POST['doc_id']) ? (int)$_POST['doc_id'] : 0;
        $employee_id  = (int)($_POST['employee_id'] ?? 0);
        $doc_type_id  = (int)($_POST['doc_type_id'] ?? 0);
        $doc_number   = trim($_POST['doc_number'] ?? '');
        $issued_at    = trim($_POST['issued_at'] ?? '');
        $expires_at   = trim($_POST['expires_at'] ?? '');
        $notes        = trim($_POST['notes'] ?? '');
        $file_path    = null;

        // Basic validation
        if (!$employee_id || !$doc_type_id) {
            $_SESSION['flash_err'] = "Employee and document type are required.";
            header("Location: documents.php");
            exit;
        }

        // Pull employee_code for directory naming
        $stmt = $conn->prepare("SELECT employee_code, full_name, company_id FROM employees WHERE id=?");
        $stmt->execute([$employee_id]);
        $emp = $stmt->fetch(PDO::FETCH_ASSOC);
        $emp_code = $emp ? preg_replace('/[^A-Za-z0-9_\-]/','_', $emp['employee_code']) : 'EMP';
        $empLabel = $emp
            ? trim(($emp['full_name'] ?? '') . ' (' . ($emp['employee_code'] ?? ('#' . $employee_id)) . ')')
            : ('Employee #' . $employee_id);
        $typeName = '';
        try {
            $tSt = $conn->prepare("SELECT name FROM document_types WHERE id=? LIMIT 1");
            $tSt->execute([$doc_type_id]);
            $typeName = (string)($tSt->fetchColumn() ?: '');
        } catch (Throwable $e) {
            $typeName = '';
        }
        $uid = $_SESSION['user']['id'] ?? null;

        // Handle upload (optional)
        if (!empty($_FILES['file_upload']['name']) && is_uploaded_file($_FILES['file_upload']['tmp_name'])) {
            $allowed = ['pdf','jpg','jpeg','png'];
            $fn      = $_FILES['file_upload']['name'];
            $ext     = strtolower(pathinfo($fn, PATHINFO_EXTENSION));
            if (!in_array($ext,$allowed)) {
                $_SESSION['flash_err'] = "File type not allowed. Use PDF/JPG/PNG.";
                header("Location: documents.php");
                exit;
            }
            $emp_dir = $uploads_root . "/{$emp_code}";
            if (!is_dir($emp_dir)) @mkdir($emp_dir, 0775, true);
            $safeBase = preg_replace('/[^A-Za-z0-9_\-\.]/','_', pathinfo($fn, PATHINFO_FILENAME));
            $newname  = $safeBase . '_' . date('Ymd_His') . '.' . $ext;
            $dest     = $emp_dir . '/' . $newname;
            if (!move_uploaded_file($_FILES['file_upload']['tmp_name'], $dest)) {
                $_SESSION['flash_err'] = "Upload failed: cannot write to folder.";
                header("Location: documents.php");
                exit;
            }
            // Web path
            $file_path = "uploads/docs/{$emp_code}/{$newname}";
        }

        if ($doc_id > 0) {
            // Update
            if ($file_path) {
                $sql = "UPDATE employee_documents
                        SET employee_id=?, doc_type_id=?, doc_number=?, issued_at=?, expires_at=?, notes=?, file_path=?, uploaded_by=?
                        WHERE id=?";
                $params = [$employee_id,$doc_type_id,$doc_number ?: null,
                           $issued_at ?: null,$expires_at ?: null,$notes ?: null,
                           $file_path,$_SESSION['user']['id'] ?? null,$doc_id];
            } else {
                $sql = "UPDATE employee_documents
                        SET employee_id=?, doc_type_id=?, doc_number=?, issued_at=?, expires_at=?, notes=?, uploaded_by=?
                        WHERE id=?";
                $params = [$employee_id,$doc_type_id,$doc_number ?: null,
                           $issued_at ?: null,$expires_at ?: null,$notes ?: null,
                           $_SESSION['user']['id'] ?? null,$doc_id];
            }
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $_SESSION['flash_ok'] = "Document updated.";
            audit_bridge_hr_ops(
                'document_updated',
                'employee_documents',
                $doc_id,
                'Updated document' . ($typeName !== '' ? (' ' . $typeName) : '')
                    . ($doc_number !== '' ? (' #' . $doc_number) : '')
                    . ' for ' . $empLabel,
                isset($emp['company_id']) ? (int)$emp['company_id'] : null,
                [
                    'employee_id' => $employee_id,
                    'doc_type_id' => $doc_type_id,
                    'doc_type' => $typeName !== '' ? $typeName : null,
                    'doc_number' => $doc_number !== '' ? $doc_number : null,
                    'expires_at' => $expires_at !== '' ? $expires_at : null,
                    'file_replaced' => $file_path ? true : false,
                ],
                'Doc #' . $doc_id . ' — ' . $empLabel,
                $uid ? (int)$uid : null
            );
        } else {
            // Insert
            $sql = "INSERT INTO employee_documents
                    (employee_id, doc_type_id, doc_number, issued_at, expires_at, notes, file_path, uploaded_by)
                    VALUES (?,?,?,?,?,?,?,?)";
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                $employee_id, $doc_type_id, ($doc_number ?: null),
                ($issued_at ?: null), ($expires_at ?: null), ($notes ?: null),
                $file_path, ($_SESSION['user']['id'] ?? null)
            ]);
            $newDocId = (int)$conn->lastInsertId();
            $_SESSION['flash_ok'] = "Document added.";
            audit_bridge_hr_ops(
                'document_uploaded',
                'employee_documents',
                $newDocId > 0 ? $newDocId : $employee_id,
                'Uploaded document' . ($typeName !== '' ? (' ' . $typeName) : '')
                    . ($doc_number !== '' ? (' #' . $doc_number) : '')
                    . ' for ' . $empLabel,
                isset($emp['company_id']) ? (int)$emp['company_id'] : null,
                [
                    'employee_id' => $employee_id,
                    'doc_type_id' => $doc_type_id,
                    'doc_type' => $typeName !== '' ? $typeName : null,
                    'doc_number' => $doc_number !== '' ? $doc_number : null,
                    'expires_at' => $expires_at !== '' ? $expires_at : null,
                    'has_file' => $file_path ? true : false,
                ],
                'Doc #' . ($newDocId > 0 ? $newDocId : '?') . ' — ' . $empLabel,
                $uid ? (int)$uid : null
            );
        }
        header("Location: documents.php");
        exit;
    }

    if ($action === 'delete') {
        $doc_id = (int)($_POST['doc_id'] ?? 0);
        if ($doc_id) {
            // optionally unlink file
            $stmt = $conn->prepare("
                SELECT d.file_path, d.doc_number, d.employee_id, e.full_name, e.employee_code, e.company_id,
                       dt.name AS type_name
                FROM employee_documents d
                LEFT JOIN employees e ON e.id = d.employee_id
                LEFT JOIN document_types dt ON dt.id = d.doc_type_id
                WHERE d.id=?
            ");
            $stmt->execute([$doc_id]);
            $docRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            $fp = $docRow['file_path'] ?? null;
            $absolutePath = hr_document_file_absolute_path($fp);
            if ($absolutePath !== '' && is_file($absolutePath)) {
                @unlink($absolutePath);
            }
            $conn->prepare("DELETE FROM employee_documents WHERE id=?")->execute([$doc_id]);
            $_SESSION['flash_ok'] = "Document deleted.";
            if ($docRow) {
                $empLabel = trim(($docRow['full_name'] ?? '') . ' (' . ($docRow['employee_code'] ?? '') . ')');
                $uid = $_SESSION['user']['id'] ?? null;
                audit_bridge_hr_ops(
                    'document_deleted',
                    'employee_documents',
                    $doc_id,
                    'Deleted document'
                        . (!empty($docRow['type_name']) ? (' ' . $docRow['type_name']) : '')
                        . (!empty($docRow['doc_number']) ? (' #' . $docRow['doc_number']) : '')
                        . ' for ' . $empLabel,
                    isset($docRow['company_id']) ? (int)$docRow['company_id'] : null,
                    [
                        'employee_id' => (int)($docRow['employee_id'] ?? 0),
                        'doc_type' => $docRow['type_name'] ?? null,
                        'doc_number' => $docRow['doc_number'] ?? null,
                    ],
                    'Doc #' . $doc_id . ' — ' . $empLabel,
                    $uid ? (int)$uid : null
                );
            }
        }
        header("Location: documents.php");
        exit;
    }

    if ($action === 'add_type') {
        $name = trim($_POST['type_name'] ?? '');
        if ($name !== '') {
            $stmt = $conn->prepare("INSERT INTO document_types (name) VALUES (?)");
            try {
                $stmt->execute([$name]);
                $typeId = (int)$conn->lastInsertId();
                $_SESSION['flash_ok']="Type added.";
                $uid = $_SESSION['user']['id'] ?? null;
                audit_bridge_hr_ops(
                    'document_type_created',
                    'document_types',
                    $typeId > 0 ? $typeId : 0,
                    'Created document type ' . $name,
                    null,
                    ['name' => $name],
                    'Doc type #' . ($typeId > 0 ? $typeId : '?'),
                    $uid ? (int)$uid : null
                );
            }
            catch(Exception $e){ $_SESSION['flash_err']="Type exists or invalid."; }
        }
        header("Location: documents.php");
        exit;
    }
}

// --------- Filters / Listing ----------
$q           = trim($_GET['q'] ?? '');
$employee_id = (int)($_GET['employee_id'] ?? 0);
$type_id     = (int)($_GET['type_id'] ?? 0);
$exp_from    = trim($_GET['exp_from'] ?? '');
$exp_to      = trim($_GET['exp_to'] ?? '');
$status      = trim($_GET['status'] ?? ''); // '', 'expired', 'soon'
$companies = hr_active_companies($conn);
$selectedCompanyId = hr_selected_company_id($conn, $companies);

$where = ["1=1"];
$params = [];
hr_add_company_where($where, $params, $selectedCompanyId, 'e.company_id');

if ($q !== '') {
    $where[] = "(e.full_name LIKE ? OR e.employee_code LIKE ? OR d.doc_number LIKE ? OR dt.name LIKE ?)";
    $params[] = "%$q%"; $params[] = "%$q%"; $params[] = "%$q%"; $params[] = "%$q%";
}
if ($employee_id) {
    $where[] = "d.employee_id = ?";
    $params[] = $employee_id;
}
if ($type_id) {
    $where[] = "d.doc_type_id = ?";
    $params[] = $type_id;
}
if ($exp_from !== '') { $where[] = "d.expires_at >= ?"; $params[] = $exp_from; }
if ($exp_to   !== '') { $where[] = "d.expires_at <= ?"; $params[] = $exp_to; }

if ($status === 'expired') {
    $where[] = "d.expires_at IS NOT NULL AND d.expires_at < CURDATE()";
} elseif ($status === 'soon') {
    $where[] = "d.expires_at IS NOT NULL AND d.expires_at >= CURDATE() AND d.expires_at <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)";
}

$sql = "SELECT d.*, e.employee_code, e.full_name, c.name AS company_name, dt.name AS type_name
        FROM employee_documents d
        JOIN employees e   ON e.id = d.employee_id
        LEFT JOIN companies c ON c.id = e.company_id
        LEFT JOIN document_types dt ON dt.id = d.doc_type_id
        WHERE ".implode(" AND ", $where)."
        ORDER BY d.expires_at IS NULL, d.expires_at ASC, d.id DESC";
$stmt = $conn->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// dropdown data
$types = $conn->query("SELECT id,name FROM document_types WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$empSql = "SELECT e.id, e.employee_code, e.full_name, c.name AS company_name
           FROM employees e
           LEFT JOIN companies c ON c.id = e.company_id";
$empWhere = ["e.status IN (" . hr_employee_status_in_sql(hr_employee_current_statuses()) . ")"];
$empParams = hr_employee_current_statuses();
if ($selectedCompanyId > 0) {
    $empWhere[] = "e.company_id = ?";
    $empParams[] = $selectedCompanyId;
}
$empSql .= " WHERE " . implode(' AND ', $empWhere);
$empSql .= " ORDER BY e.full_name";
$empStmt = $conn->prepare($empSql);
$empStmt->execute($empParams);
$emps = $empStmt->fetchAll(PDO::FETCH_ASSOC);

// If editing
$editDoc = null;
if (isset($_GET['edit']) && ctype_digit($_GET['edit'])) {
    $stmt = $conn->prepare("SELECT * FROM employee_documents WHERE id=?");
    $stmt->execute([$_GET['edit']]);
    $editDoc = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Summary counts for current result set
$todayTs = strtotime(date('Y-m-d'));
$docStats = ['total' => count($rows), 'expired' => 0, 'soon' => 0, 'with_file' => 0];
foreach ($rows as $r) {
    if (!empty($r['file_path'])) {
        $docStats['with_file']++;
    }
    if (empty($r['expires_at'])) {
        continue;
    }
    $diff = (int)floor((strtotime($r['expires_at']) - $todayTs) / 86400);
    if ($diff < 0) {
        $docStats['expired']++;
    } elseif ($diff <= 30) {
        $docStats['soon']++;
    }
}

// Page settings for shared layout
$pageTitle = 'Documents';
$hrScopeLabel = hr_company_scope_label($companies, $selectedCompanyId);
$pageStyles = '
    .docs-kpi { border:0; box-shadow:0 12px 28px rgba(16,24,40,.06); border-radius:18px; }
    .docs-kpi .kpi { font-size:1.35rem; font-weight:700; }
    .docs-kpi .sub { color:#6b7280; font-size:.8rem; }
    .docs-notes { max-width: 220px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .docs-actions { white-space: nowrap; }
';
if ($editDoc) {
    $pageScripts = '<script>bootstrap.Modal.getOrCreateInstance(document.getElementById("docModal")).show();</script>';
}
require_once __DIR__ . '/includes/hr_layout_header.php';

$docActions = '<button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#docModal">+ Add Document</button>'
    . '<button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#typeModal">+ Add Type</button>';
echo hr_ui_page_header(
    'Documents',
    'Employee document registry, expiries, and attachments.',
    [
        ['label' => 'HR', 'href' => $hrBase . '/dashboard'],
        ['label' => 'Documents'],
    ],
    $docActions
);
?>

  <?php if (!empty($_SESSION['flash_ok'])): ?>
    <div class="alert alert-success"><?= h($_SESSION['flash_ok']); unset($_SESSION['flash_ok']); ?></div>
  <?php endif; ?>
  <?php if (!empty($_SESSION['flash_err'])): ?>
    <div class="alert alert-danger"><?= h($_SESSION['flash_err']); unset($_SESSION['flash_err']); ?></div>
  <?php endif; ?>

  <div class="row g-3 mb-3">
    <div class="col-6 col-md-3">
      <div class="card docs-kpi p-3">
        <div class="sub">In view</div>
        <div class="kpi"><?= number_format($docStats['total']) ?></div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card docs-kpi p-3">
        <div class="sub">Expired</div>
        <div class="kpi text-danger"><?= number_format($docStats['expired']) ?></div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card docs-kpi p-3">
        <div class="sub">Expiring ≤ 30d</div>
        <div class="kpi text-warning"><?= number_format($docStats['soon']) ?></div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card docs-kpi p-3">
        <div class="sub">With file</div>
        <div class="kpi"><?= number_format($docStats['with_file']) ?></div>
      </div>
    </div>
  </div>

  <div class="hr-filter-bar mb-3">
    <form method="get" action="documents">
      <div class="row g-3 align-items-end">
        <div class="col-md-3">
          <label class="form-label">Search</label>
          <input type="text" name="q" class="form-control" value="<?= h($q) ?>" placeholder="Employee, number, type...">
        </div>
        <div class="col-md-3">
          <label class="form-label">Company</label>
          <select name="company_id" class="form-select">
            <option value="0">All companies</option>
            <?php foreach ($companies as $company): ?>
              <option value="<?= (int)$company['id'] ?>" <?= $selectedCompanyId === (int)$company['id'] ? 'selected' : '' ?>>
                <?= h($company['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Employee</label>
          <select name="employee_id" class="form-select">
            <option value="">All</option>
            <?php foreach ($emps as $e): ?>
              <option value="<?= (int)$e['id'] ?>" <?= $employee_id === (int)$e['id'] ? 'selected' : '' ?>>
                <?= h($e['full_name']) ?> (<?= h($e['employee_code']) ?>)<?= !empty($e['company_name']) ? ' — ' . h($e['company_name']) : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Type</label>
          <select name="type_id" class="form-select">
            <option value="">All</option>
            <?php foreach ($types as $t): ?>
              <option value="<?= (int)$t['id'] ?>" <?= $type_id === (int)$t['id'] ? 'selected' : '' ?>><?= h($t['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">Expiry from</label>
          <input type="date" name="exp_from" class="form-control" value="<?= h($exp_from) ?>">
        </div>
        <div class="col-md-2">
          <label class="form-label">Expiry to</label>
          <input type="date" name="exp_to" class="form-control" value="<?= h($exp_to) ?>">
        </div>
        <div class="col-md-2">
          <label class="form-label">Status</label>
          <select name="status" class="form-select">
            <option value="" <?= $status === '' ? 'selected' : '' ?>>All</option>
            <option value="soon" <?= $status === 'soon' ? 'selected' : '' ?>>Expiring ≤ 30d</option>
            <option value="expired" <?= $status === 'expired' ? 'selected' : '' ?>>Expired</option>
          </select>
        </div>
        <div class="col-md-2">
          <button class="btn btn-primary w-100">Apply</button>
        </div>
        <div class="col-md-2">
          <a class="btn btn-outline-secondary w-100" href="documents">Reset</a>
        </div>
      </div>
    </form>
  </div>

  <div class="hr-settings-card">
    <div class="settings-header d-flex justify-content-between align-items-center flex-wrap gap-2">
      <span>Document registry</span>
      <span class="small text-muted"><?= number_format($docStats['total']) ?> record<?= $docStats['total'] === 1 ? '' : 's' ?></span>
    </div>
    <div class="card-body p-0">
      <div class="hr-table-shell border-0 shadow-none rounded-0">
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead>
              <tr>
                <th>Employee</th>
                <th>Company</th>
                <th>Type</th>
                <th>Number</th>
                <th>Issued</th>
                <th>Expires</th>
                <th>File</th>
                <th>Notes</th>
                <th class="text-end" style="width:160px;">Actions</th>
              </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
              <tr><td colspan="9" class="text-center py-5 text-muted">No documents found for the selected filters.</td></tr>
            <?php else: foreach ($rows as $r): ?>
              <tr>
                <td>
                  <a href="employee_view?id=<?= (int)$r['employee_id'] ?>"><?= h($r['full_name']) ?></a>
                  <div class="small text-muted"><?= h($r['employee_code']) ?></div>
                </td>
                <td><?= h($r['company_name'] ?: '—') ?></td>
                <td><?= h($r['type_name'] ?: ($r['doc_type'] ?? '—')) ?></td>
                <td><?= h($r['doc_number'] ?: '—') ?></td>
                <td class="text-nowrap"><?= h($r['issued_at'] ?: '—') ?></td>
                <td class="text-nowrap">
                  <?= h($r['expires_at'] ?: '—') ?>
                  <?php if ($r['expires_at']): ?>
                    <div class="mt-1"><?= badgeDate($r['expires_at']) ?></div>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if (!empty($r['file_path'])): ?>
                    <a class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener" href="<?= h(hr_document_file_url($r['file_path'])) ?>">Open</a>
                  <?php else: ?>
                    <span class="text-muted">—</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if (!empty($r['notes'])): ?>
                    <span class="docs-notes" title="<?= h($r['notes']) ?>"><?= h($r['notes']) ?></span>
                  <?php else: ?>
                    <span class="text-muted">—</span>
                  <?php endif; ?>
                </td>
                <td class="text-end docs-actions">
                  <a class="btn btn-sm btn-outline-primary" href="documents?edit=<?= (int)$r['id'] ?>">Edit</a>
                  <form method="post" class="d-inline" onsubmit="return confirm('Delete this document?')">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="doc_id" value="<?= (int)$r['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

<!-- Add/Edit Document Modal -->
<div class="modal fade" id="docModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <form class="modal-content" method="post" enctype="multipart/form-data">
      <?php csrf_field(); ?>
      <div class="modal-header">
        <h5 class="modal-title"><?= $editDoc?'Edit Document':'Add Document' ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="doc_id" value="<?= $editDoc['id'] ?? 0 ?>">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Employee *</label>
            <select name="employee_id" class="form-select" required>
              <option value="">-- Choose --</option>
              <?php foreach($emps as $e): ?>
                <option value="<?=$e['id']?>" <?= isset($editDoc['employee_id']) && $editDoc['employee_id']==$e['id']?'selected':'' ?>>
                  <?=h($e['full_name'])?> (<?=h($e['employee_code'])?>)<?= !empty($e['company_name']) ? ' - ' . h($e['company_name']) : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label">Type *</label>
            <select name="doc_type_id" class="form-select" required>
              <option value="">-- Choose --</option>
              <?php foreach($types as $t): ?>
                <option value="<?=$t['id']?>" <?= isset($editDoc['doc_type_id']) && $editDoc['doc_type_id']==$t['id']?'selected':'' ?>>
                  <?=h($t['name'])?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label">Document Number</label>
            <input type="text" name="doc_number" class="form-control" value="<?=h($editDoc['doc_number'] ?? '')?>">
          </div>
          <div class="col-md-3">
            <label class="form-label">Issued At</label>
            <input type="date" name="issued_at" class="form-control" value="<?=h($editDoc['issued_at'] ?? '')?>">
          </div>
          <div class="col-md-3">
            <label class="form-label">Expires At</label>
            <input type="date" name="expires_at" class="form-control" value="<?=h($editDoc['expires_at'] ?? '')?>">
          </div>
          <div class="col-12">
            <label class="form-label">Notes</label>
            <textarea name="notes" class="form-control" rows="2"><?=h($editDoc['notes'] ?? '')?></textarea>
          </div>
          <div class="col-12">
            <label class="form-label">Upload (PDF / JPG / PNG)</label>
            <input type="file" name="file_upload" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
            <?php if(!empty($editDoc['file_path'])): ?>
              <div class="form-text">Current: <a target="_blank" href="<?=h(hr_document_file_url($editDoc['file_path']))?>"><?=h(basename($editDoc['file_path']))?></a></div>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-success">Save</button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </form>
  </div>
</div>

<!-- Add Type Modal -->
<div class="modal fade" id="typeModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="post">
      <?php csrf_field(); ?>
      <div class="modal-header">
        <h5 class="modal-title">Add Document Type</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="action" value="add_type">
        <label class="form-label">Name</label>
        <input type="text" name="type_name" class="form-control" required placeholder="e.g. Medical Insurance">
      </div>
      <div class="modal-footer">
        <button class="btn btn-success">Save</button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </form>
  </div>
</div>

<?php require_once __DIR__ . '/includes/hr_layout_footer.php'; ?>
