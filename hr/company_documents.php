<?php
/**
 * HR - Company Documents
 *
 * Company-level statutory documents: trade licence, MOA, Ejari, establishment card
 * and power of attorney. One live record per document; renewing archives the previous
 * issuance into hr_company_document_versions. Files are only ever linked through
 * company_document_file.php.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/audit_bridge.php';
require_once __DIR__ . '/../includes/company_helper.php';
require_once __DIR__ . '/includes/hr_company_scope.php';
require_once __DIR__ . '/includes/hr_company_documents.php';
require_role(['Owner', 'Admin', 'HR'], $conn);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$uid = $_SESSION['user']['id'] ?? null;
$uid = $uid ? (int)$uid : null;
$docTypes = hr_company_document_types();

/** Preserve the current filters across a post-redirect-get. */
function company_docs_redirect_qs(): string
{
    $keep = [];
    foreach (['company_id', 'doc_type', 'status', 'q', 'page'] as $k) {
        if (isset($_POST[$k]) && $_POST[$k] !== '') {
            $keep[$k] = $_POST[$k];
        }
    }
    return $keep ? '?' . http_build_query($keep) : '';
}

// --------- Handle POST (save / renew / delete) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    $back = 'company_documents.php' . company_docs_redirect_qs();

    if ($action === 'save') {
        $docId      = (int)($_POST['doc_id'] ?? 0);
        $companyId  = (int)($_POST['doc_company_id'] ?? 0);
        $docType    = trim($_POST['doc_type_code'] ?? '');
        $title      = trim($_POST['title'] ?? '');
        $docNumber  = trim($_POST['doc_number'] ?? '');
        $authority  = trim($_POST['issuing_authority'] ?? '');
        $issueDate  = trim($_POST['issue_date'] ?? '');
        $expiryDate = trim($_POST['expiry_date'] ?? '');
        $notes      = trim($_POST['notes'] ?? '');

        if ($companyId <= 0 || !hr_company_document_type_is_valid($docType)) {
            $_SESSION['flash_err'] = 'Company and document type are required.';
            header('Location: ' . $back);
            exit;
        }

        // The company must be one that actually exists and is active.
        $chk = $conn->prepare('SELECT name FROM companies WHERE id = ? AND is_active = 1 LIMIT 1');
        $chk->execute([$companyId]);
        $companyName = $chk->fetchColumn();
        if ($companyName === false) {
            $_SESSION['flash_err'] = 'Selected company was not found.';
            header('Location: ' . $back);
            exit;
        }

        $existing = null;
        if ($docId > 0) {
            $stmt = $conn->prepare('SELECT * FROM hr_company_documents WHERE id = ?');
            $stmt->execute([$docId]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$existing) {
                $_SESSION['flash_err'] = 'Document not found.';
                header('Location: ' . $back);
                exit;
            }
        }

        $upload = hr_company_document_store_upload($_FILES['file_upload'] ?? [], $companyId, $docType);
        if (!$upload['ok']) {
            $_SESSION['flash_err'] = $upload['error'];
            header('Location: ' . $back);
            exit;
        }

        if ($title === '') {
            $title = hr_company_document_type_label($docType);
        }

        try {
            if ($docId > 0) {
                if ($upload['file_path'] !== null) {
                    $sql = "UPDATE hr_company_documents SET
                                company_id = ?, doc_type = ?, title = ?, doc_number = ?, issuing_authority = ?,
                                issue_date = ?, expiry_date = ?, notes = ?,
                                file_path = ?, file_name = ?, file_size = ?, mime_type = ?,
                                updated_by = ?
                            WHERE id = ?";
                    $params = [
                        $companyId, $docType, $title, ($docNumber ?: null), ($authority ?: null),
                        ($issueDate ?: null), ($expiryDate ?: null), ($notes ?: null),
                        $upload['file_path'], $upload['file_name'], $upload['file_size'], $upload['mime_type'],
                        $uid, $docId,
                    ];
                } else {
                    $sql = "UPDATE hr_company_documents SET
                                company_id = ?, doc_type = ?, title = ?, doc_number = ?, issuing_authority = ?,
                                issue_date = ?, expiry_date = ?, notes = ?, updated_by = ?
                            WHERE id = ?";
                    $params = [
                        $companyId, $docType, $title, ($docNumber ?: null), ($authority ?: null),
                        ($issueDate ?: null), ($expiryDate ?: null), ($notes ?: null), $uid, $docId,
                    ];
                }
                $conn->prepare($sql)->execute($params);

                // Replacing the live file orphans the old one unless a version row still
                // points at it, so only unlink when nothing else references it.
                if ($upload['file_path'] !== null && !empty($existing['file_path'])) {
                    $ref = $conn->prepare('SELECT COUNT(*) FROM hr_company_document_versions WHERE file_path = ?');
                    $ref->execute([$existing['file_path']]);
                    if ((int)$ref->fetchColumn() === 0) {
                        hr_company_document_unlink($existing['file_path']);
                    }
                }

                $_SESSION['flash_ok'] = 'Document updated.';
                audit_bridge_hr_ops(
                    'company_document_updated',
                    'hr_company_documents',
                    $docId,
                    'Updated ' . hr_company_document_type_label($docType) . ' for ' . $companyName,
                    $companyId,
                    ['doc_type' => $docType, 'doc_number' => $docNumber, 'expiry_date' => $expiryDate],
                    'Company document #' . $docId,
                    $uid
                );
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO hr_company_documents
                        (company_id, doc_type, title, doc_number, issuing_authority,
                         issue_date, expiry_date, notes, file_path, file_name, file_size, mime_type,
                         status, created_by, updated_by)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,'active',?,?)
                ");
                $stmt->execute([
                    $companyId, $docType, $title, ($docNumber ?: null), ($authority ?: null),
                    ($issueDate ?: null), ($expiryDate ?: null), ($notes ?: null),
                    $upload['file_path'], $upload['file_name'], $upload['file_size'], $upload['mime_type'],
                    $uid, $uid,
                ]);
                $newId = (int)$conn->lastInsertId();

                $_SESSION['flash_ok'] = 'Document added.';
                audit_bridge_hr_ops(
                    'company_document_created',
                    'hr_company_documents',
                    $newId,
                    'Added ' . hr_company_document_type_label($docType) . ' for ' . $companyName,
                    $companyId,
                    ['doc_type' => $docType, 'doc_number' => $docNumber, 'expiry_date' => $expiryDate],
                    'Company document #' . $newId,
                    $uid
                );
            }
        } catch (PDOException $e) {
            if ($upload['file_path'] !== null) {
                hr_company_document_unlink($upload['file_path']);
            }
            $_SESSION['flash_err'] = 'Could not save the document. ' . $e->getMessage();
        }

        header('Location: ' . $back);
        exit;
    }

    if ($action === 'renew') {
        $docId      = (int)($_POST['doc_id'] ?? 0);
        $docNumber  = trim($_POST['doc_number'] ?? '');
        $authority  = trim($_POST['issuing_authority'] ?? '');
        $issueDate  = trim($_POST['issue_date'] ?? '');
        $expiryDate = trim($_POST['expiry_date'] ?? '');
        $notes      = trim($_POST['notes'] ?? '');

        $stmt = $conn->prepare('SELECT * FROM hr_company_documents WHERE id = ?');
        $stmt->execute([$docId]);
        $current = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$current) {
            $_SESSION['flash_err'] = 'Document not found.';
            header('Location: ' . $back);
            exit;
        }

        $companyId = (int)$current['company_id'];
        $upload = hr_company_document_store_upload($_FILES['file_upload'] ?? [], $companyId, $current['doc_type']);
        if (!$upload['ok']) {
            $_SESSION['flash_err'] = $upload['error'];
            header('Location: ' . $back);
            exit;
        }

        try {
            $conn->beginTransaction();

            // Re-read under a lock so two concurrent renewals cannot claim the same version_no.
            $lock = $conn->prepare('SELECT * FROM hr_company_documents WHERE id = ? FOR UPDATE');
            $lock->execute([$docId]);
            $current = $lock->fetch(PDO::FETCH_ASSOC);

            $vStmt = $conn->prepare('SELECT COALESCE(MAX(version_no), 0) + 1 FROM hr_company_document_versions WHERE document_id = ?');
            $vStmt->execute([$docId]);
            $versionNo = (int)$vStmt->fetchColumn();

            $archive = $conn->prepare("
                INSERT INTO hr_company_document_versions
                    (document_id, version_no, doc_number, issuing_authority, issue_date, expiry_date,
                     file_path, file_name, file_size, mime_type, notes, archived_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            $archive->execute([
                $docId, $versionNo, $current['doc_number'], $current['issuing_authority'],
                $current['issue_date'], $current['expiry_date'],
                $current['file_path'], $current['file_name'], $current['file_size'], $current['mime_type'],
                $current['notes'], $uid,
            ]);

            if ($upload['file_path'] !== null) {
                $sql = "UPDATE hr_company_documents SET
                            doc_number = ?, issuing_authority = ?, issue_date = ?, expiry_date = ?, notes = ?,
                            file_path = ?, file_name = ?, file_size = ?, mime_type = ?,
                            status = 'active', updated_by = ?
                        WHERE id = ?";
                $params = [
                    ($docNumber ?: null), ($authority ?: null), ($issueDate ?: null), ($expiryDate ?: null),
                    ($notes ?: null),
                    $upload['file_path'], $upload['file_name'], $upload['file_size'], $upload['mime_type'],
                    $uid, $docId,
                ];
            } else {
                // No new scan: the live row keeps pointing at the same file as the archived version.
                $sql = "UPDATE hr_company_documents SET
                            doc_number = ?, issuing_authority = ?, issue_date = ?, expiry_date = ?, notes = ?,
                            status = 'active', updated_by = ?
                        WHERE id = ?";
                $params = [
                    ($docNumber ?: null), ($authority ?: null), ($issueDate ?: null), ($expiryDate ?: null),
                    ($notes ?: null), $uid, $docId,
                ];
            }
            $conn->prepare($sql)->execute($params);

            $conn->commit();

            $_SESSION['flash_ok'] = 'Document renewed. Version ' . $versionNo . ' archived.';
            audit_bridge_hr_ops(
                'company_document_renewed',
                'hr_company_documents',
                $docId,
                'Renewed ' . hr_company_document_type_label($current['doc_type'])
                    . ' (archived version ' . $versionNo . ')',
                $companyId,
                ['doc_number' => $docNumber, 'expiry_date' => $expiryDate, 'archived_version' => $versionNo],
                'Company document #' . $docId,
                $uid
            );
        } catch (PDOException $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            if ($upload['file_path'] !== null) {
                hr_company_document_unlink($upload['file_path']);
            }
            $_SESSION['flash_err'] = 'Could not renew the document. ' . $e->getMessage();
        }

        header('Location: ' . $back);
        exit;
    }

    if ($action === 'delete') {
        $docId = (int)($_POST['doc_id'] ?? 0);

        $stmt = $conn->prepare('SELECT * FROM hr_company_documents WHERE id = ?');
        $stmt->execute([$docId]);
        $doc = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$doc) {
            $_SESSION['flash_err'] = 'Document not found.';
            header('Location: ' . $back);
            exit;
        }

        $vStmt = $conn->prepare('SELECT file_path FROM hr_company_document_versions WHERE document_id = ?');
        $vStmt->execute([$docId]);
        $paths = $vStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $paths[] = $doc['file_path'];

        try {
            // Versions cascade on delete; remove the files afterwards so a failed
            // delete never leaves rows pointing at missing files.
            $conn->prepare('DELETE FROM hr_company_documents WHERE id = ?')->execute([$docId]);

            foreach (array_unique(array_filter($paths)) as $p) {
                hr_company_document_unlink($p);
            }

            $_SESSION['flash_ok'] = 'Document deleted.';
            audit_bridge_hr_ops(
                'company_document_deleted',
                'hr_company_documents',
                $docId,
                'Deleted ' . hr_company_document_type_label($doc['doc_type'])
                    . ($doc['doc_number'] ? ' (' . $doc['doc_number'] . ')' : ''),
                (int)$doc['company_id'],
                ['doc_type' => $doc['doc_type'], 'doc_number' => $doc['doc_number']],
                'Company document #' . $docId,
                $uid
            );
        } catch (PDOException $e) {
            $_SESSION['flash_err'] = 'Could not delete the document. ' . $e->getMessage();
        }

        header('Location: ' . $back);
        exit;
    }

    header('Location: ' . $back);
    exit;
}

// --------- Filters ----------
$companies = hr_active_companies($conn);
$selectedCompanyId = hr_selected_company_id($conn, $companies);

$q         = trim($_GET['q'] ?? '');
$typeFilter = trim($_GET['doc_type'] ?? '');
$status    = trim($_GET['status'] ?? '');
$page      = max(1, (int)($_GET['page'] ?? 1));
$perPage   = 25;
$offset    = ($page - 1) * $perPage;

if ($typeFilter !== '' && !hr_company_document_type_is_valid($typeFilter)) {
    $typeFilter = '';
}

$where = ['1=1'];
$params = [];
hr_add_company_where($where, $params, $selectedCompanyId, 'd.company_id');

if ($q !== '') {
    $where[] = '(d.title LIKE ? OR d.doc_number LIKE ? OR d.issuing_authority LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like);
}
if ($typeFilter !== '') {
    $where[] = 'd.doc_type = ?';
    $params[] = $typeFilter;
}
if ($status === 'expired') {
    $where[] = "d.expiry_date IS NOT NULL AND d.expiry_date != '0000-00-00' AND d.expiry_date < CURDATE()";
} elseif ($status === 'soon') {
    $where[] = "d.expiry_date IS NOT NULL AND d.expiry_date != '0000-00-00' AND d.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)";
} elseif ($status === 'missing_file') {
    $where[] = "(d.file_path IS NULL OR d.file_path = '')";
}
$whereSql = implode(' AND ', $where);

// The tables may not exist yet on a server where the migration has not been applied.
$schemaReady = true;
$rows = [];
$totalRows = 0;
$stats = ['total' => 0, 'expired' => 0, 'soon' => 0];
$missingTypes = [];

try {
    $countStmt = $conn->prepare("SELECT COUNT(*) FROM hr_company_documents d WHERE $whereSql");
    $countStmt->execute($params);
    $totalRows = (int)$countStmt->fetchColumn();

    $sql = "SELECT d.*, c.name AS company_name,
                   (SELECT COUNT(*) FROM hr_company_document_versions v WHERE v.document_id = d.id) AS version_count
            FROM hr_company_documents d
            LEFT JOIN companies c ON c.id = d.company_id
            WHERE $whereSql
            ORDER BY d.expiry_date IS NULL, d.expiry_date ASC, d.id DESC
            LIMIT $perPage OFFSET $offset";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // KPIs are counted in SQL, not over the paginated page, so they stay correct.
    $scopeWhere = ['1=1'];
    $scopeParams = [];
    hr_add_company_where($scopeWhere, $scopeParams, $selectedCompanyId, 'd.company_id');
    $scopeSql = implode(' AND ', $scopeWhere);

    $kpi = $conn->prepare("
        SELECT COUNT(*) AS total,
               SUM(CASE WHEN d.expiry_date IS NOT NULL AND d.expiry_date != '0000-00-00'
                         AND d.expiry_date < CURDATE() THEN 1 ELSE 0 END) AS expired,
               SUM(CASE WHEN d.expiry_date IS NOT NULL AND d.expiry_date != '0000-00-00'
                         AND d.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                        THEN 1 ELSE 0 END) AS soon
        FROM hr_company_documents d
        WHERE $scopeSql
    ");
    $kpi->execute($scopeParams);
    $kpiRow = $kpi->fetch(PDO::FETCH_ASSOC) ?: [];
    $stats = [
        'total'   => (int)($kpiRow['total'] ?? 0),
        'expired' => (int)($kpiRow['expired'] ?? 0),
        'soon'    => (int)($kpiRow['soon'] ?? 0),
    ];

    // "Missing" only means something when a single company is in scope.
    if ($selectedCompanyId > 0) {
        $have = $conn->prepare('SELECT DISTINCT doc_type FROM hr_company_documents WHERE company_id = ?');
        $have->execute([$selectedCompanyId]);
        $haveTypes = $have->fetchAll(PDO::FETCH_COLUMN) ?: [];
        foreach ($docTypes as $code => $meta) {
            if ($code !== 'other' && !in_array($code, $haveTypes, true)) {
                $missingTypes[] = $meta['label'];
            }
        }
    }
} catch (PDOException $e) {
    $schemaReady = false;
}

$totalPages = $totalRows > 0 ? (int)ceil($totalRows / $perPage) : 1;

// If editing
$editDoc = null;
if (isset($_GET['edit']) && ctype_digit($_GET['edit']) && $schemaReady) {
    $stmt = $conn->prepare('SELECT * FROM hr_company_documents WHERE id = ?');
    $stmt->execute([$_GET['edit']]);
    $editDoc = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Version history for the rows on this page, so the history modal has data.
$versionsByDoc = [];
if ($rows && $schemaReady) {
    $ids = array_map(static fn($r) => (int)$r['id'], $rows);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $vStmt = $conn->prepare("SELECT * FROM hr_company_document_versions WHERE document_id IN ($in) ORDER BY version_no DESC");
    $vStmt->execute($ids);
    foreach ($vStmt->fetchAll(PDO::FETCH_ASSOC) as $v) {
        $versionsByDoc[(int)$v['document_id']][] = $v;
    }
}

// Company contact details come straight from company_settings - the same record the
// Settings > Company screen edits, so there is only ever one company phone number.
$companySettings = null;
if ($selectedCompanyId > 0) {
    $companySettings = get_company_settings($conn, $selectedCompanyId);
}

$filterQs = array_filter([
    'company_id' => $selectedCompanyId ?: null,
    'doc_type'   => $typeFilter ?: null,
    'status'     => $status ?: null,
    'q'          => $q ?: null,
]);

$pageTitle = 'Company Documents';
$hrScopeLabel = hr_company_scope_label($companies, $selectedCompanyId);
$pageStyles = '
    .cdoc-kpi { border:0; box-shadow:0 12px 28px rgba(16,24,40,.06); border-radius:18px; }
    .cdoc-kpi .kpi { font-size:1.35rem; font-weight:700; }
    .cdoc-kpi .sub { color:#6b7280; font-size:.8rem; }
    .cdoc-notes { max-width: 200px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display:inline-block; vertical-align:bottom; }
    .cdoc-actions .btn, .cdoc-actions form { margin-left:.25rem; }
';
require_once __DIR__ . '/includes/hr_layout_header.php';

$pageActions = '<button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#companyDocModal">+ Add Document</button>';
echo hr_ui_page_header(
    'Company Documents',
    'Trade licence, MOA, Ejari, establishment card and power of attorney for each company.',
    [
        ['label' => 'HR', 'href' => $hrBase . '/dashboard'],
        ['label' => 'Company Documents'],
    ],
    $pageActions
);
?>

  <?php if (!empty($_SESSION['flash_ok'])): ?>
    <div class="alert alert-success"><?= h($_SESSION['flash_ok']); unset($_SESSION['flash_ok']); ?></div>
  <?php endif; ?>
  <?php if (!empty($_SESSION['flash_err'])): ?>
    <div class="alert alert-danger"><?= h($_SESSION['flash_err']); unset($_SESSION['flash_err']); ?></div>
  <?php endif; ?>

  <?php if (!$schemaReady): ?>
    <?= hr_ui_alert('<strong>Company documents storage is not installed yet.</strong> Apply <code>migrations/20260904_create_hr_company_documents.sql</code> to enable this screen.', 'warning') ?>
  <?php endif; ?>

  <?php if ($companySettings): ?>
    <div class="hr-settings-card mb-3">
      <div class="settings-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span>Company details</span>
        <a class="btn btn-sm btn-outline-secondary"
           href="<?= h($appBase) ?>/settings.php?tab=company&settings_company_id=<?= (int)$selectedCompanyId ?>">
          Edit in Settings
        </a>
      </div>
      <div class="card-body">
        <div class="row g-3 small">
          <div class="col-md-4">
            <div class="text-muted">Legal name</div>
            <div class="fw-semibold"><?= h($companySettings['legal_name'] ?: '—') ?></div>
          </div>
          <div class="col-md-4">
            <div class="text-muted">Trade name</div>
            <div class="fw-semibold"><?= h($companySettings['trade_name'] ?: '—') ?></div>
          </div>
          <div class="col-md-4">
            <div class="text-muted">TRN</div>
            <div class="fw-semibold"><?= h($companySettings['trn'] ?: '—') ?></div>
          </div>
          <div class="col-md-4">
            <div class="text-muted">Phone</div>
            <div class="fw-semibold"><?= h($companySettings['phone'] ?: '—') ?></div>
          </div>
          <div class="col-md-4">
            <div class="text-muted">WhatsApp</div>
            <div class="fw-semibold"><?= h($companySettings['whatsapp'] ?? '' ?: '—') ?></div>
          </div>
          <div class="col-md-4">
            <div class="text-muted">Email</div>
            <div class="fw-semibold"><?= h($companySettings['email'] ?: '—') ?></div>
          </div>
          <div class="col-md-4">
            <div class="text-muted">Website</div>
            <div class="fw-semibold"><?= h($companySettings['website'] ?: '—') ?></div>
          </div>
          <div class="col-md-8">
            <div class="text-muted">Address</div>
            <div class="fw-semibold">
              <?php
                $addr = array_filter([
                    $companySettings['address_line1'] ?? '',
                    $companySettings['address_line2'] ?? '',
                    $companySettings['city'] ?? '',
                    $companySettings['state_region'] ?? '',
                    $companySettings['country'] ?? '',
                ]);
                echo h($addr ? implode(', ', $addr) : '—');
              ?>
            </div>
          </div>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <div class="row g-3 mb-3">
    <div class="col-6 col-md-3">
      <div class="card cdoc-kpi p-3">
        <div class="sub">Documents</div>
        <div class="kpi"><?= number_format($stats['total']) ?></div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card cdoc-kpi p-3">
        <div class="sub">Expired</div>
        <div class="kpi text-danger"><?= number_format($stats['expired']) ?></div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card cdoc-kpi p-3">
        <div class="sub">Expiring &le; 30d</div>
        <div class="kpi text-warning"><?= number_format($stats['soon']) ?></div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card cdoc-kpi p-3">
        <div class="sub">Missing types</div>
        <div class="kpi<?= $missingTypes ? ' text-warning' : '' ?>">
          <?= $selectedCompanyId > 0 ? count($missingTypes) : '—' ?>
        </div>
        <?php if ($selectedCompanyId > 0 && $missingTypes): ?>
          <div class="sub" title="<?= h(implode(', ', $missingTypes)) ?>"><?= h(implode(', ', $missingTypes)) ?></div>
        <?php elseif ($selectedCompanyId <= 0): ?>
          <div class="sub">Select a company</div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="hr-filter-bar mb-3">
    <form method="get" action="company_documents">
      <div class="row g-3 align-items-end">
        <div class="col-md-3">
          <label class="form-label">Search</label>
          <input type="text" name="q" class="form-control" value="<?= h($q) ?>" placeholder="Title, number, authority...">
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
        <div class="col-md-2">
          <label class="form-label">Type</label>
          <select name="doc_type" class="form-select">
            <option value="">All</option>
            <?php foreach ($docTypes as $code => $meta): ?>
              <option value="<?= h($code) ?>" <?= $typeFilter === $code ? 'selected' : '' ?>><?= h($meta['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">Status</label>
          <select name="status" class="form-select">
            <option value="" <?= $status === '' ? 'selected' : '' ?>>All</option>
            <option value="soon" <?= $status === 'soon' ? 'selected' : '' ?>>Expiring &le; 30d</option>
            <option value="expired" <?= $status === 'expired' ? 'selected' : '' ?>>Expired</option>
            <option value="missing_file" <?= $status === 'missing_file' ? 'selected' : '' ?>>No file</option>
          </select>
        </div>
        <div class="col-md-1">
          <button class="btn btn-primary w-100">Apply</button>
        </div>
        <div class="col-md-1">
          <a class="btn btn-outline-secondary w-100" href="company_documents">Reset</a>
        </div>
      </div>
    </form>
  </div>

  <div class="hr-settings-card">
    <div class="settings-header d-flex justify-content-between align-items-center flex-wrap gap-2">
      <span>Company document registry</span>
      <span class="small text-muted"><?= number_format($totalRows) ?> record<?= $totalRows === 1 ? '' : 's' ?></span>
    </div>
    <div class="card-body p-0">
      <div class="hr-table-shell border-0 shadow-none rounded-0">
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead>
              <tr>
                <th>Company</th>
                <th>Document</th>
                <th>Number</th>
                <th>Authority</th>
                <th>Issued</th>
                <th>Expires</th>
                <th>File</th>
                <th>History</th>
                <th class="text-end" style="width:220px;">Actions</th>
              </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
              <tr><td colspan="9" class="text-center py-5 text-muted">No company documents found for the selected filters.</td></tr>
            <?php else: foreach ($rows as $r): ?>
              <tr>
                <td><?= h($r['company_name'] ?: '—') ?></td>
                <td>
                  <div class="fw-semibold"><?= h(hr_company_document_type_label($r['doc_type'])) ?></div>
                  <?php if (!empty($r['title']) && $r['title'] !== hr_company_document_type_label($r['doc_type'])): ?>
                    <div class="small text-muted"><?= h($r['title']) ?></div>
                  <?php endif; ?>
                  <?php if (!empty($r['notes'])): ?>
                    <div class="small text-muted cdoc-notes" title="<?= h($r['notes']) ?>"><?= h($r['notes']) ?></div>
                  <?php endif; ?>
                </td>
                <td><?= h($r['doc_number'] ?: '—') ?></td>
                <td><?= h($r['issuing_authority'] ?: '—') ?></td>
                <td class="text-nowrap"><?= h($r['issue_date'] ?: '—') ?></td>
                <td class="text-nowrap">
                  <?= h($r['expiry_date'] ?: '—') ?>
                  <?php if (!empty($r['expiry_date'])): ?>
                    <div class="mt-1"><?= hr_company_document_expiry_badge($r['expiry_date']) ?></div>
                  <?php endif; ?>
                </td>
                <td class="text-nowrap">
                  <?php if (!empty($r['file_path'])): ?>
                    <a class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener"
                       href="company_document_file.php?id=<?= (int)$r['id'] ?>&mode=view">Open</a>
                    <a class="btn btn-sm btn-outline-secondary"
                       href="company_document_file.php?id=<?= (int)$r['id'] ?>&mode=download">Download</a>
                  <?php else: ?>
                    <span class="text-muted">—</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ((int)$r['version_count'] > 0): ?>
                    <button type="button" class="btn btn-sm btn-outline-secondary"
                            data-bs-toggle="modal" data-bs-target="#historyModal<?= (int)$r['id'] ?>">
                      <?= (int)$r['version_count'] ?> version<?= (int)$r['version_count'] === 1 ? '' : 's' ?>
                    </button>
                  <?php else: ?>
                    <span class="text-muted">—</span>
                  <?php endif; ?>
                </td>
                <td class="text-end cdoc-actions">
                  <a class="btn btn-sm btn-outline-primary"
                     href="company_documents?<?= h(http_build_query(array_merge($filterQs, ['edit' => (int)$r['id']]))) ?>">Edit</a>
                  <button type="button" class="btn btn-sm btn-outline-success"
                          data-bs-toggle="modal" data-bs-target="#renewModal<?= (int)$r['id'] ?>">Renew</button>
                  <form method="post" class="d-inline" onsubmit="return confirm('Delete this document and all its archived versions?')">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="doc_id" value="<?= (int)$r['id'] ?>">
                    <?php foreach ($filterQs as $k => $v): ?>
                      <input type="hidden" name="<?= h($k) ?>" value="<?= h($v) ?>">
                    <?php endforeach; ?>
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
    <?php if ($totalPages > 1): ?>
      <div class="card-body border-top d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span class="small text-muted">Page <?= $page ?> of <?= $totalPages ?></span>
        <div class="btn-group">
          <?php if ($page > 1): ?>
            <a class="btn btn-sm btn-outline-secondary"
               href="company_documents?<?= h(http_build_query(array_merge($filterQs, ['page' => $page - 1]))) ?>">Previous</a>
          <?php endif; ?>
          <?php if ($page < $totalPages): ?>
            <a class="btn btn-sm btn-outline-secondary"
               href="company_documents?<?= h(http_build_query(array_merge($filterQs, ['page' => $page + 1]))) ?>">Next</a>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>

<!-- Add / Edit Document Modal -->
<div class="modal fade" id="companyDocModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <form class="modal-content" method="post" enctype="multipart/form-data">
      <?php csrf_field(); ?>
      <div class="modal-header">
        <h5 class="modal-title"><?= $editDoc ? 'Edit Company Document' : 'Add Company Document' ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="doc_id" value="<?= (int)($editDoc['id'] ?? 0) ?>">
        <?php foreach ($filterQs as $k => $v): ?>
          <input type="hidden" name="<?= h($k) ?>" value="<?= h($v) ?>">
        <?php endforeach; ?>
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Company *</label>
            <select name="doc_company_id" class="form-select" required>
              <option value="">Select company</option>
              <?php
                $modalCompanyId = (int)($editDoc['company_id'] ?? $selectedCompanyId);
                foreach ($companies as $company):
              ?>
                <option value="<?= (int)$company['id'] ?>" <?= $modalCompanyId === (int)$company['id'] ? 'selected' : '' ?>>
                  <?= h($company['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label">Document type *</label>
            <select name="doc_type_code" class="form-select" required>
              <option value="">Select type</option>
              <?php foreach ($docTypes as $code => $meta): ?>
                <option value="<?= h($code) ?>" <?= ($editDoc['doc_type'] ?? '') === $code ? 'selected' : '' ?>>
                  <?= h($meta['label']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label">Title / reference label</label>
            <input type="text" name="title" class="form-control" value="<?= h($editDoc['title'] ?? '') ?>"
                   placeholder="Defaults to the document type">
          </div>
          <div class="col-md-6">
            <label class="form-label">Document number</label>
            <input type="text" name="doc_number" class="form-control" value="<?= h($editDoc['doc_number'] ?? '') ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label">Issuing authority</label>
            <input type="text" name="issuing_authority" class="form-control"
                   value="<?= h($editDoc['issuing_authority'] ?? '') ?>" placeholder="e.g. DED, MOHRE, DLD">
          </div>
          <div class="col-md-3">
            <label class="form-label">Issue date</label>
            <input type="date" name="issue_date" class="form-control" value="<?= h($editDoc['issue_date'] ?? '') ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label">Expiry date</label>
            <input type="date" name="expiry_date" class="form-control" value="<?= h($editDoc['expiry_date'] ?? '') ?>">
            <div class="form-text">Leave blank if it does not expire.</div>
          </div>
          <div class="col-md-6">
            <label class="form-label">Attachment</label>
            <input type="file" name="file_upload" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
            <div class="form-text">PDF, JPG or PNG, up to <?= (int)(HR_COMPANY_DOC_MAX_BYTES / 1024 / 1024) ?> MB.</div>
            <?php if (!empty($editDoc['file_path'])): ?>
              <div class="small mt-1">
                Current file:
                <a target="_blank" rel="noopener"
                   href="company_document_file.php?id=<?= (int)$editDoc['id'] ?>&mode=view"><?= h($editDoc['file_name'] ?: 'view') ?></a>
              </div>
            <?php endif; ?>
          </div>
          <div class="col-md-6">
            <label class="form-label">Notes</label>
            <input type="text" name="notes" class="form-control" value="<?= h($editDoc['notes'] ?? '') ?>">
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-success"><?= $editDoc ? 'Update' : 'Save' ?></button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </form>
  </div>
</div>

<?php foreach ($rows as $r): ?>
<!-- Renew Modal -->
<div class="modal fade" id="renewModal<?= (int)$r['id'] ?>" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="post" enctype="multipart/form-data">
      <?php csrf_field(); ?>
      <div class="modal-header">
        <h5 class="modal-title">Renew <?= h(hr_company_document_type_label($r['doc_type'])) ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="action" value="renew">
        <input type="hidden" name="doc_id" value="<?= (int)$r['id'] ?>">
        <?php foreach ($filterQs as $k => $v): ?>
          <input type="hidden" name="<?= h($k) ?>" value="<?= h($v) ?>">
        <?php endforeach; ?>
        <p class="small text-muted">
          The current issuance (<?= h($r['doc_number'] ?: 'no number') ?>,
          expiring <?= h($r['expiry_date'] ?: 'n/a') ?>) will be archived to the version history.
        </p>
        <div class="row g-3">
          <div class="col-12">
            <label class="form-label">New document number</label>
            <input type="text" name="doc_number" class="form-control" value="<?= h($r['doc_number']) ?>">
          </div>
          <div class="col-12">
            <label class="form-label">Issuing authority</label>
            <input type="text" name="issuing_authority" class="form-control" value="<?= h($r['issuing_authority']) ?>">
          </div>
          <div class="col-6">
            <label class="form-label">New issue date</label>
            <input type="date" name="issue_date" class="form-control">
          </div>
          <div class="col-6">
            <label class="form-label">New expiry date</label>
            <input type="date" name="expiry_date" class="form-control">
          </div>
          <div class="col-12">
            <label class="form-label">New attachment</label>
            <input type="file" name="file_upload" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
            <div class="form-text">Leave blank to keep the existing file.</div>
          </div>
          <div class="col-12">
            <label class="form-label">Notes</label>
            <input type="text" name="notes" class="form-control" value="<?= h($r['notes']) ?>">
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-success">Renew</button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </form>
  </div>
</div>

<?php if (!empty($versionsByDoc[(int)$r['id']])): ?>
<!-- Version History Modal -->
<div class="modal fade" id="historyModal<?= (int)$r['id'] ?>" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">History — <?= h(hr_company_document_type_label($r['doc_type'])) ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-0">
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th>#</th>
                <th>Number</th>
                <th>Authority</th>
                <th>Issued</th>
                <th>Expired</th>
                <th>Archived</th>
                <th>File</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($versionsByDoc[(int)$r['id']] as $v): ?>
                <tr>
                  <td><?= (int)$v['version_no'] ?></td>
                  <td><?= h($v['doc_number'] ?: '—') ?></td>
                  <td><?= h($v['issuing_authority'] ?: '—') ?></td>
                  <td class="text-nowrap"><?= h($v['issue_date'] ?: '—') ?></td>
                  <td class="text-nowrap"><?= h($v['expiry_date'] ?: '—') ?></td>
                  <td class="text-nowrap"><?= h($v['archived_at']) ?></td>
                  <td>
                    <?php if (!empty($v['file_path'])): ?>
                      <a class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener"
                         href="company_document_file.php?id=<?= (int)$r['id'] ?>&version=<?= (int)$v['id'] ?>&mode=view">Open</a>
                    <?php else: ?>
                      <span class="text-muted">—</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>
<?php endforeach; ?>

<?php
// bootstrap is only defined after the footer loads the bundle, so re-opening the
// edit modal has to run from $pageScripts.
if ($editDoc) {
    $pageScripts = '<script>bootstrap.Modal.getOrCreateInstance(document.getElementById("companyDocModal")).show();</script>';
}
require_once __DIR__ . '/includes/hr_layout_footer.php';
