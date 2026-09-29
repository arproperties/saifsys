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

/** 2025-11-30 -> 30 Nov 2025; blank and zero dates become a dash. */
function cdoc_date(?string $d): string
{
    $d = trim((string)$d);
    if ($d === '' || $d === '0000-00-00' || ($ts = strtotime($d)) === false) {
        return '—';
    }
    return date('j M Y', $ts);
}

/**
 * Expiry state for colouring a row or tile, with a plain-words countdown.
 * @return array{state:string,text:string}  state: expired | soon | ok | none
 */
function cdoc_expiry(?string $d): array
{
    $d = trim((string)$d);
    if ($d === '' || $d === '0000-00-00' || ($ts = strtotime($d)) === false) {
        return ['state' => 'none', 'text' => 'No expiry'];
    }
    $days = (int)floor(($ts - strtotime(date('Y-m-d'))) / 86400);
    if ($days < 0) {
        $n = -$days;
        return ['state' => 'expired', 'text' => 'Expired ' . $n . ' day' . ($n === 1 ? '' : 's') . ' ago'];
    }
    if ($days === 0) {
        return ['state' => 'soon', 'text' => 'Expires today'];
    }
    return [
        'state' => $days <= 30 ? 'soon' : 'ok',
        'text'  => $days . ' day' . ($days === 1 ? '' : 's') . ' left',
    ];
}

$uid = $_SESSION['user']['id'] ?? null;
$uid = $uid ? (int)$uid : null;
$docTypes = hr_company_document_types();

/** Preserve the current filters across a post-redirect-get. */
function company_docs_redirect_qs(): string
{
    $keep = [];
    foreach (['company_id', 'doc_type', 'status', 'q', 'page', 'view'] as $k) {
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

        // Leave title empty when it adds nothing: the list falls back to the type
        // label. Storing a copy of the label here would snapshot it, so renaming a
        // type later would leave every old row showing a stale duplicate subtitle.
        if ($title !== '' && $title === hr_company_document_type_label($docType)) {
            $title = '';
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
// By company is the default. A link carrying list filters without a view still means the list.
$view      = $_GET['view'] ?? '';
if ($view !== 'list' && $view !== 'company') {
    $view = (trim($_GET['q'] ?? '') !== '' || trim($_GET['doc_type'] ?? '') !== '' || trim($_GET['status'] ?? '') !== '')
        ? 'list' : 'company';
}
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
$companiesMissing = 0;
$companyCards = [];

$expectedTypes = [];
foreach ($docTypes as $code => $meta) {
    if (!empty($meta['expected'])) {
        $expectedTypes[] = $code;
    }
}

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

    // Which expected types each company holds: drives the "missing" card and the
    // By company view.
    $haveByCompany = [];
    $haveStmt = $conn->query('SELECT DISTINCT company_id, doc_type FROM hr_company_documents');
    foreach ($haveStmt->fetchAll(PDO::FETCH_ASSOC) as $hv) {
        $haveByCompany[(int)$hv['company_id']][$hv['doc_type']] = true;
    }

    if ($selectedCompanyId > 0) {
        foreach ($expectedTypes as $code) {
            if (empty($haveByCompany[$selectedCompanyId][$code])) {
                $missingTypes[] = $docTypes[$code]['label'];
            }
        }
    } else {
        foreach ($companies as $company) {
            foreach ($expectedTypes as $code) {
                if (empty($haveByCompany[(int)$company['id']][$code])) {
                    $companiesMissing++;
                    break;
                }
            }
        }
    }

    // By company view: every document in the company scope, grouped per company,
    // with a placeholder for each expected type the company does not hold.
    if ($view === 'company') {
        $cStmt = $conn->prepare("SELECT d.*,
                       (SELECT COUNT(*) FROM hr_company_document_versions v WHERE v.document_id = d.id) AS version_count
                FROM hr_company_documents d
                WHERE $scopeSql
                ORDER BY d.expiry_date IS NULL, d.expiry_date ASC, d.id DESC");
        $cStmt->execute($scopeParams);
        $docsByCompany = [];
        foreach ($cStmt->fetchAll(PDO::FETCH_ASSOC) as $d) {
            $docsByCompany[(int)$d['company_id']][] = $d;
        }

        foreach ($companies as $company) {
            $cid = (int)$company['id'];
            if ($selectedCompanyId > 0 && $cid !== $selectedCompanyId) {
                continue;
            }
            $docs = $docsByCompany[$cid] ?? [];
            $byType = [];
            foreach ($docs as $d) {
                $byType[$d['doc_type']][] = $d;
            }

            $card = [
                'id' => $cid, 'name' => $company['name'], 'docs' => $docs,
                'cells' => [], 'other' => count($byType['other'] ?? []),
                'missing' => 0, 'expired' => 0, 'soon' => 0, 'on_file' => 0,
            ];
            foreach ($expectedTypes as $code) {
                $list = $byType[$code] ?? [];
                // Several records of one type are usually an old issuance added as new
                // instead of renewed: show the one that runs longest, count the rest.
                $main = null;
                foreach ($list as $d) {
                    $key = cdoc_expiry($d['expiry_date'])['state'] === 'none' ? '9999-12-31' : $d['expiry_date'];
                    $mainKey = $main === null ? '' : (cdoc_expiry($main['expiry_date'])['state'] === 'none' ? '9999-12-31' : $main['expiry_date']);
                    if ($main === null || $key > $mainKey) {
                        $main = $d;
                    }
                }
                $card['cells'][$code] = ['doc' => $main, 'count' => count($list)];
                if ($main === null) {
                    $card['missing']++;
                    continue;
                }
                $card['on_file']++;
                $st = cdoc_expiry($main['expiry_date'])['state'];
                if ($st === 'expired') { $card['expired']++; }
                if ($st === 'soon') { $card['soon']++; }
            }
            $companyCards[] = $card;
        }

        // Companies needing attention first.
        usort($companyCards, static function ($a, $b) {
            return [$b['expired'], $b['soon'], $b['missing']] <=> [$a['expired'], $a['soon'], $a['missing']];
        });
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

// The documents shown on screen, which each need a renew and history modal.
$modalRows = $rows;
if ($view === 'company') {
    $modalRows = [];
    foreach ($companyCards as $card) {
        foreach ($card['docs'] as $d) {
            $modalRows[] = $d;
        }
    }
}

// Version history for the documents on screen, so the history modal has data.
$versionsByDoc = [];
if ($modalRows && $schemaReady) {
    $ids = array_map(static fn($r) => (int)$r['id'], $modalRows);
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
    'view'       => $view === 'list' ? 'list' : null,
]);

/** Link to this page keeping the current filters, with some overridden (null drops one). */
function cdoc_url(array $filterQs, array $override = []): string
{
    $qs = array_filter(array_merge($filterQs, $override), static fn($v) => $v !== null && $v !== '');
    return 'company_documents' . ($qs ? '?' . http_build_query($qs) : '');
}

$pageTitle = 'Company Documents';
$hrScopeLabel = hr_company_scope_label($companies, $selectedCompanyId);
$pageStyles = '
    .cdoc-kpi { display:block; border:1px solid transparent; box-shadow:0 12px 28px rgba(16,24,40,.06); border-radius:18px; color:inherit; text-decoration:none; height:100%; transition:border-color .15s, transform .15s; }
    .cdoc-kpi:hover { border-color:#d1d5db; transform:translateY(-1px); color:inherit; }
    .cdoc-kpi.is-active { border-color:#b8860b; box-shadow:0 0 0 3px rgba(184,134,11,.15); }
    .cdoc-kpi .kpi { font-size:1.6rem; font-weight:700; line-height:1.2; }
    .cdoc-kpi .sub { color:#6b7280; font-size:.8rem; }
    .cdoc-kpi .hint { color:#9ca3af; font-size:.75rem; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .cdoc-notes { max-width: 220px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display:block; }

    /* Coloured left edge per expiry state. */
    .cdoc-row > td:first-child { box-shadow: inset 4px 0 0 var(--cdoc-edge, transparent); }
    .cdoc-expired { --cdoc-edge:#dc3545; }
    .cdoc-soon    { --cdoc-edge:#f0ad00; }
    .cdoc-ok      { --cdoc-edge:#22a06b; }
    .cdoc-none    { --cdoc-edge:#d1d5db; }
    .cdoc-exp { font-size:.78rem; font-weight:600; }
    .cdoc-exp-expired { color:#dc3545; }
    .cdoc-exp-soon    { color:#b7791f; }
    .cdoc-exp-ok      { color:#22a06b; }
    .cdoc-exp-none    { color:#9ca3af; font-weight:400; }
    .cdoc-ver { font-size:.7rem; font-weight:600; vertical-align:middle; cursor:pointer; }

    /* Keep row actions on one line. */
    .cdoc-actions { display:flex; justify-content:flex-end; align-items:center; gap:.375rem; flex-wrap:nowrap; }
    .cdoc-actions form { margin:0; }
    .cdoc-actions .btn { white-space:nowrap; }

    /* By company view: compact card per company, one line per document type. */
    .cdoc-legend { display:flex; flex-wrap:wrap; gap:.4rem 1.1rem; align-items:center; }
    .cdoc-legend span { display:inline-flex; align-items:center; gap:.35rem; }
    .cdoc-dot { display:inline-block; width:9px; height:9px; border-radius:50%; background:var(--cdoc-edge); }
    .cdoc-ok      { --cdoc-bg:#e8f6ef; --cdoc-fg:#17754a; }
    .cdoc-soon    { --cdoc-bg:#fff4d6; --cdoc-fg:#8a5a00; }
    .cdoc-expired { --cdoc-bg:#fde8ea; --cdoc-fg:#b42331; }
    .cdoc-none    { --cdoc-bg:#eef2f7; --cdoc-fg:#475467; --cdoc-edge:#98a2b3; }
    .cdoc-missing { --cdoc-bg:#fff; --cdoc-fg:#98a2b3; --cdoc-edge:#d0d5dd; }
    .cdoc-grid { background:#f2f4f7; border-top:1px solid #e4e7ec; }
    .cdoc-ccard { display:flex; flex-direction:column; background:#fff; border:1px solid #cfd4dc; border-top:4px solid var(--cdoc-edge, #cfd4dc);
                  border-radius:14px; box-shadow:0 1px 2px rgba(16,24,40,.06), 0 4px 12px rgba(16,24,40,.06); overflow:hidden; }
    .cdoc-ccard.cdoc-missing { --cdoc-edge:#cfd4dc; }
    .cdoc-ccard-head { padding:.85rem 1rem .75rem; background:#f9fafb; border-bottom:1px solid #e4e7ec; }
    .cdoc-ccard-name { font-size:.95rem; line-height:1.25; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .cdoc-ccard-body { padding:.35rem .5rem; flex:1; }
    .cdoc-ccard-foot { display:flex; justify-content:space-between; gap:.5rem; padding:.55rem 1rem; border-top:1px solid #e4e7ec;
                       background:#fcfcfd; font-size:.8rem; }
    .cdoc-ccard-foot a { text-decoration:none; }
    .cdoc-chip { font-size:.7rem; font-weight:700; border-radius:999px; padding:.15rem .5rem; background:var(--cdoc-bg); color:var(--cdoc-fg); white-space:nowrap; }
    .cdoc-line { display:flex; align-items:center; gap:.55rem; padding:.3rem .5rem; border-radius:8px; min-height:34px; }
    .cdoc-line + .cdoc-line { border-top:1px solid #f2f4f7; border-radius:0; }
    .cdoc-line:hover { background:#f9fafb; }
    .cdoc-line-dot { width:8px; height:8px; border-radius:50%; background:var(--cdoc-edge); flex-shrink:0; }
    .cdoc-line.cdoc-missing .cdoc-line-dot { background:transparent; border:1.5px dashed var(--cdoc-edge); }
    .cdoc-line-label { flex:1; font-size:.85rem; font-weight:500; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .cdoc-line.cdoc-missing .cdoc-line-label { color:#98a2b3; font-weight:400; }
    .cdoc-pill { border:0; border-radius:999px; padding:.18rem .6rem; font-size:.74rem; font-weight:600; white-space:nowrap;
                 background:var(--cdoc-bg); color:var(--cdoc-fg); cursor:pointer; }
    .cdoc-pill .bi { font-size:.6rem; margin-left:.15rem; opacity:.7; }
    .cdoc-pill:hover { filter:brightness(.96); }
    .cdoc-pill-add { background:transparent; color:#2563eb; border:1px dashed #b6c8f5; }
    .cdoc-pill-add:hover { background:#f5f8ff; filter:none; }
    .cdoc-dup { font-size:.62rem; font-weight:700; background:#344054; color:#fff; border-radius:999px; padding:0 .35rem; vertical-align:middle; }
';
require_once __DIR__ . '/includes/hr_layout_header.php';

$pageActions = '<button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#companyDocModal">+ Add Document</button>';
echo hr_ui_page_header(
    'Company Documents',
    'Trade licence, MOA, Ejari, establishment card, power of attorney and tax certificates for each company.',
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

<?php
  // Clicking a card filters the list. Status filters only apply to the list view.
  $kpiCards = [
      [
          'label' => 'Documents', 'value' => number_format($stats['total']), 'cls' => '',
          'hint' => 'Show all',
          'href' => cdoc_url($filterQs, ['status' => null, 'page' => null, 'view' => 'list']),
          'active' => $status === '' && $view === 'list',
      ],
      [
          'label' => 'Expired', 'value' => number_format($stats['expired']), 'cls' => 'text-danger',
          'hint' => 'Need renewal now',
          'href' => cdoc_url($filterQs, ['status' => 'expired', 'page' => null, 'view' => 'list']),
          'active' => $status === 'expired' && $view === 'list',
      ],
      [
          'label' => 'Expiring ≤ 30 days', 'value' => number_format($stats['soon']), 'cls' => 'text-warning',
          'hint' => 'Renew soon',
          'href' => cdoc_url($filterQs, ['status' => 'soon', 'page' => null, 'view' => 'list']),
          'active' => $status === 'soon' && $view === 'list',
      ],
  ];
  if ($selectedCompanyId > 0) {
      $kpiCards[] = [
          'label' => 'Missing types', 'value' => number_format(count($missingTypes)),
          'cls' => $missingTypes ? 'text-warning' : 'text-success',
          'hint' => $missingTypes ? implode(', ', $missingTypes) : 'All documents on file',
          'href' => cdoc_url(['company_id' => $selectedCompanyId ?: null]),
          'active' => $view === 'company',
      ];
  } else {
      $kpiCards[] = [
          'label' => 'Companies missing documents', 'value' => number_format($companiesMissing),
          'cls' => $companiesMissing ? 'text-warning' : 'text-success',
          'hint' => $companiesMissing ? 'See which ones' : 'Every company is complete',
          'href' => cdoc_url(['company_id' => $selectedCompanyId ?: null]),
          'active' => $view === 'company',
      ];
  }

  /** File button, Renew and the "more" menu for one document. */
  function cdoc_actions(array $r, array $filterQs, bool $compact = false): void
  {
      $id = (int)$r['id'];
      $vc = (int)$r['version_count'];
      $hasFile = !empty($r['file_path']);
      ?>
      <div class="cdoc-actions">
        <?php if ($hasFile): ?>
          <a class="btn btn-sm btn-light border" target="_blank" rel="noopener" title="Open file"
             href="company_document_file.php?id=<?= $id ?>&mode=view"><i class="bi bi-file-earmark-text"></i><?= $compact ? '' : ' Open' ?></a>
        <?php else: ?>
          <span class="badge text-bg-light border text-muted fw-normal">No file</span>
        <?php endif; ?>
        <button type="button" class="btn btn-sm btn-outline-success"
                data-bs-toggle="modal" data-bs-target="#renewModal<?= $id ?>">Renew</button>
        <div class="dropdown">
          <button type="button" class="btn btn-sm btn-light border" data-bs-toggle="dropdown"
                  data-bs-popper-config='{"strategy":"fixed"}' aria-expanded="false" aria-label="More actions">
            <i class="bi bi-three-dots"></i>
          </button>
          <ul class="dropdown-menu dropdown-menu-end shadow-sm">
            <li><a class="dropdown-item" href="<?= h(cdoc_url($filterQs, ['edit' => $id])) ?>"><i class="bi bi-pencil me-2"></i>Edit</a></li>
            <?php if ($hasFile): ?>
              <li><a class="dropdown-item" href="company_document_file.php?id=<?= $id ?>&mode=download"><i class="bi bi-download me-2"></i>Download</a></li>
            <?php endif; ?>
            <?php if ($vc > 0): ?>
              <li><button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#historyModal<?= $id ?>">
                <i class="bi bi-clock-history me-2"></i>History (<?= $vc ?> old version<?= $vc === 1 ? '' : 's' ?>)</button></li>
            <?php endif; ?>
            <li><hr class="dropdown-divider"></li>
            <li>
              <form method="post" onsubmit="return confirm('Delete this document and all its archived versions?')">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="doc_id" value="<?= $id ?>">
                <?php foreach ($filterQs as $k => $v): ?>
                  <input type="hidden" name="<?= h($k) ?>" value="<?= h($v) ?>">
                <?php endforeach; ?>
                <button type="submit" class="dropdown-item text-danger"><i class="bi bi-trash me-2"></i>Delete</button>
              </form>
            </li>
          </ul>
        </div>
      </div>
      <?php
  }
?>

  <div class="row g-3 mb-3">
    <?php foreach ($kpiCards as $card): ?>
      <div class="col-6 col-md-3">
        <a class="card cdoc-kpi p-3<?= $card['active'] ? ' is-active' : '' ?>" href="<?= h($card['href']) ?>">
          <div class="sub"><?= h($card['label']) ?></div>
          <div class="kpi <?= h($card['cls']) ?>"><?= h($card['value']) ?></div>
          <div class="hint" title="<?= h($card['hint']) ?>"><?= h($card['hint']) ?> &rarr;</div>
        </a>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="hr-filter-bar mb-3">
    <form method="get" action="company_documents">
      <?php if ($view === 'list'): ?>
        <input type="hidden" name="view" value="list">
      <?php endif; ?>
      <div class="row g-3 align-items-end">
        <?php if ($view === 'list'): ?>
          <div class="col-md-3">
            <label class="form-label">Search</label>
            <input type="text" name="q" class="form-control" value="<?= h($q) ?>" placeholder="Title, number, authority...">
          </div>
        <?php endif; ?>
        <div class="<?= $view === 'list' ? 'col-md-3' : 'col-md-6' ?>">
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
        <?php if ($view === 'list'): ?>
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
        <?php endif; ?>
        <div class="col-md-1">
          <button class="btn btn-primary w-100">Apply</button>
        </div>
        <div class="col-md-1">
          <a class="btn btn-outline-secondary w-100" href="<?= $view === 'list' ? 'company_documents?view=list' : 'company_documents' ?>">Reset</a>
        </div>
      </div>
    </form>
  </div>

  <div class="hr-settings-card">
    <div class="settings-header d-flex justify-content-between align-items-center flex-wrap gap-2">
      <span><?= $view === 'company' ? 'Documents by company' : 'Company document registry' ?></span>
      <div class="d-flex align-items-center gap-3">
        <?php if ($view === 'list'): ?>
          <span class="small text-muted"><?= number_format($totalRows) ?> record<?= $totalRows === 1 ? '' : 's' ?></span>
        <?php else: ?>
          <span class="small text-muted"><?= number_format(count($companyCards)) ?> compan<?= count($companyCards) === 1 ? 'y' : 'ies' ?></span>
        <?php endif; ?>
        <div class="btn-group btn-group-sm" role="group" aria-label="View">
          <a class="btn <?= $view === 'list' ? 'btn-secondary' : 'btn-outline-secondary' ?>"
             href="<?= h(cdoc_url($filterQs, ['view' => 'list', 'page' => null])) ?>"><i class="bi bi-list-ul me-1"></i>List</a>
          <a class="btn <?= $view === 'company' ? 'btn-secondary' : 'btn-outline-secondary' ?>"
             href="<?= h(cdoc_url(['company_id' => $selectedCompanyId ?: null])) ?>"><i class="bi bi-grid me-1"></i>By company</a>
        </div>
      </div>
    </div>

    <?php if ($view === 'list'): ?>
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
                <th class="text-end" style="width:1%; white-space:nowrap;"></th>
              </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
              <tr><td colspan="7" class="text-center py-5 text-muted">No company documents found for the selected filters.</td></tr>
            <?php else: foreach ($rows as $r): ?>
              <?php $ex = cdoc_expiry($r['expiry_date']); $vc = (int)$r['version_count']; ?>
              <tr class="cdoc-row cdoc-<?= h($ex['state']) ?>">
                <td class="fw-medium"><?= h($r['company_name'] ?: '—') ?></td>
                <td>
                  <div class="fw-semibold">
                    <?= h(hr_company_document_type_label($r['doc_type'])) ?>
                    <?php if ($vc > 0): ?>
                      <span class="badge rounded-pill text-bg-light border cdoc-ver" role="button" title="View history"
                            data-bs-toggle="modal" data-bs-target="#historyModal<?= (int)$r['id'] ?>">v<?= $vc + 1 ?></span>
                    <?php endif; ?>
                  </div>
                  <?php if (!empty($r['title']) && $r['title'] !== hr_company_document_type_label($r['doc_type'])): ?>
                    <div class="small text-muted"><?= h($r['title']) ?></div>
                  <?php endif; ?>
                  <?php if (!empty($r['notes'])): ?>
                    <div class="small text-muted cdoc-notes" title="<?= h($r['notes']) ?>"><?= h($r['notes']) ?></div>
                  <?php endif; ?>
                </td>
                <td><?= h($r['doc_number'] ?: '—') ?></td>
                <td><?= h($r['issuing_authority'] ?: '—') ?></td>
                <td class="text-nowrap"><?= h(cdoc_date($r['issue_date'])) ?></td>
                <td class="text-nowrap">
                  <div><?= h(cdoc_date($r['expiry_date'])) ?></div>
                  <?php if ($ex['state'] !== 'none'): ?>
                    <div class="cdoc-exp cdoc-exp-<?= h($ex['state']) ?>"><?= h($ex['text']) ?></div>
                  <?php endif; ?>
                </td>
                <td><?php cdoc_actions($r, $filterQs); ?></td>
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
               href="<?= h(cdoc_url($filterQs, ['page' => $page - 1])) ?>">Previous</a>
          <?php endif; ?>
          <?php if ($page < $totalPages): ?>
            <a class="btn btn-sm btn-outline-secondary"
               href="<?= h(cdoc_url($filterQs, ['page' => $page + 1])) ?>">Next</a>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <?php else: ?>
    <?php
      $shortLabels = [
          'moa' => 'MOA', 'ejari' => 'Ejari / Tenancy', 'corporate_tax_certificate' => 'Corporate Tax Certificate',
      ];
      $expectedCount = count($expectedTypes);
    ?>
    <div class="cdoc-legend small text-muted px-3 pt-3 pb-2">
      <span><i class="cdoc-dot cdoc-ok"></i>Valid</span>
      <span><i class="cdoc-dot cdoc-soon"></i>Expires within 30 days</span>
      <span><i class="cdoc-dot cdoc-expired"></i>Expired</span>
      <span><i class="cdoc-dot cdoc-none"></i>On file, no expiry</span>
      <span><i class="cdoc-dot cdoc-missing" style="background:transparent;border:1.5px dashed #d0d5dd"></i>Missing</span>
      <span class="ms-auto">Click a status to open, renew or edit.</span>
    </div>
    <?php if (!$companyCards): ?>
      <div class="text-center py-5 text-muted">No companies to show.</div>
    <?php else: ?>
    <div class="card-body cdoc-grid">
      <div class="row g-3">
        <?php foreach ($companyCards as $card): ?>
          <?php $pct = $expectedCount ? (int)round($card['on_file'] / $expectedCount * 100) : 0; ?>
          <div class="col-12 col-md-6 col-xl-4">
            <?php $cardState = $card['expired'] ? 'expired' : ($card['soon'] ? 'soon' : ($card['missing'] ? 'missing' : 'ok')); ?>
            <div class="cdoc-ccard cdoc-<?= $cardState ?> h-100">
              <div class="cdoc-ccard-head">
                <div class="d-flex justify-content-between align-items-start gap-2">
                  <div class="fw-semibold cdoc-ccard-name" title="<?= h($card['name']) ?>"><?= h($card['name']) ?></div>
                  <div class="d-flex gap-1 flex-shrink-0">
                    <?php if ($card['expired']): ?><span class="cdoc-chip cdoc-expired"><?= $card['expired'] ?> expired</span><?php endif; ?>
                    <?php if ($card['soon']): ?><span class="cdoc-chip cdoc-soon"><?= $card['soon'] ?> due</span><?php endif; ?>
                    <?php if (!$card['expired'] && !$card['soon'] && !$card['missing']): ?><span class="cdoc-chip cdoc-ok">All good</span><?php endif; ?>
                  </div>
                </div>
                <div class="d-flex align-items-center gap-2 mt-2">
                  <div class="progress flex-grow-1" style="height:6px;">
                    <div class="progress-bar <?= $card['expired'] ? 'bg-danger' : ($pct === 100 ? 'bg-success' : 'bg-warning') ?>" style="width:<?= $pct ?>%"></div>
                  </div>
                  <span class="small text-muted text-nowrap"><?= $card['on_file'] ?> of <?= $expectedCount ?> on file</span>
                </div>
              </div>

              <div class="cdoc-ccard-body">
                <?php foreach ($expectedTypes as $code): ?>
                  <?php $cell = $card['cells'][$code]; $d = $cell['doc']; ?>
                  <?php if ($d === null): ?>
                    <div class="cdoc-line cdoc-missing">
                      <span class="cdoc-line-dot"></span>
                      <span class="cdoc-line-label"><?= h($shortLabels[$code] ?? $docTypes[$code]['label']) ?></span>
                      <button type="button" class="cdoc-pill cdoc-pill-add" title="Add <?= h($docTypes[$code]['label']) ?>"
                              data-cdoc-add data-company="<?= (int)$card['id'] ?>" data-type="<?= h($code) ?>">+ Add</button>
                    </div>
                  <?php else: ?>
                    <?php
                      $ex = cdoc_expiry($d['expiry_date']);
                      $id = (int)$d['id'];
                      if ($ex['state'] === 'none') {
                          $pill = 'On file';
                      } elseif ($ex['state'] === 'expired') {
                          $pill = 'Expired ' . cdoc_date($d['expiry_date']);
                      } elseif ($ex['state'] === 'soon') {
                          $pill = $ex['text'];
                      } else {
                          $pill = 'Until ' . cdoc_date($d['expiry_date']);
                      }
                    ?>
                    <div class="cdoc-line cdoc-<?= h($ex['state']) ?>">
                      <span class="cdoc-line-dot"></span>
                      <span class="cdoc-line-label">
                        <?= h($shortLabels[$code] ?? $docTypes[$code]['label']) ?>
                        <?php if ($cell['count'] > 1): ?><span class="cdoc-dup" title="<?= $cell['count'] ?> records of this type">&times;<?= $cell['count'] ?></span><?php endif; ?>
                        <?php if (empty($d['file_path'])): ?><i class="bi bi-paperclip text-danger small" title="No file attached"></i><?php endif; ?>
                      </span>
                      <div class="dropdown">
                        <button type="button" class="cdoc-pill" data-bs-toggle="dropdown"
                                data-bs-popper-config='{"strategy":"fixed"}' aria-expanded="false">
                          <?= h($pill) ?> <i class="bi bi-chevron-down"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                          <li><h6 class="dropdown-header">
                            <?= h($docTypes[$code]['label']) ?>
                            <?php if (!empty($d['doc_number'])): ?><br><span class="fw-normal">No. <?= h($d['doc_number']) ?></span><?php endif; ?>
                          </h6></li>
                          <?php if (!empty($d['file_path'])): ?>
                            <li><a class="dropdown-item" target="_blank" rel="noopener" href="company_document_file.php?id=<?= $id ?>&mode=view"><i class="bi bi-file-earmark-text me-2"></i>Open file</a></li>
                            <li><a class="dropdown-item" href="company_document_file.php?id=<?= $id ?>&mode=download"><i class="bi bi-download me-2"></i>Download</a></li>
                          <?php else: ?>
                            <li><span class="dropdown-item-text small text-muted"><i class="bi bi-exclamation-circle me-2"></i>No file attached</span></li>
                          <?php endif; ?>
                          <li><button type="button" class="dropdown-item text-success" data-bs-toggle="modal" data-bs-target="#renewModal<?= $id ?>"><i class="bi bi-arrow-repeat me-2"></i>Renew</button></li>
                          <li><a class="dropdown-item" href="<?= h(cdoc_url($filterQs, ['edit' => $id])) ?>"><i class="bi bi-pencil me-2"></i>Edit</a></li>
                          <?php if ((int)$d['version_count'] > 0): ?>
                            <li><button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#historyModal<?= $id ?>"><i class="bi bi-clock-history me-2"></i>History</button></li>
                          <?php endif; ?>
                          <?php if ($cell['count'] > 1): ?>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item small" href="<?= h(cdoc_url([], ['company_id' => $card['id'], 'doc_type' => $code, 'view' => 'list'])) ?>"><i class="bi bi-files me-2"></i>See all <?= $cell['count'] ?> records</a></li>
                          <?php endif; ?>
                        </ul>
                      </div>
                    </div>
                  <?php endif; ?>
                <?php endforeach; ?>
              </div>

              <div class="cdoc-ccard-foot">
                <?php if ($card['other'] > 0): ?>
                  <a href="<?= h(cdoc_url([], ['company_id' => $card['id'], 'doc_type' => 'other', 'view' => 'list'])) ?>">
                    <i class="bi bi-folder2 me-1"></i><?= $card['other'] ?> other document<?= $card['other'] === 1 ? '' : 's' ?>
                  </a>
                <?php else: ?>
                  <span class="text-muted">No other documents</span>
                <?php endif; ?>
                <a href="<?= h(cdoc_url([], ['company_id' => $card['id'], 'view' => 'list'])) ?>">View all &rarr;</a>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
  </div>

<!-- Add / Edit Document Modal -->
<div class="modal fade" id="companyDocModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <form class="modal-content" method="post" enctype="multipart/form-data">
      <?php csrf_field(); ?>
      <div class="modal-header">
        <h5 class="modal-title" id="cdocModalTitle"><?= $editDoc ? 'Edit Company Document' : 'Add Company Document' ?></h5>
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
              <div class="small mt-1" id="cdocCurrentFile">
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
        <button class="btn btn-success" id="cdocModalSubmit"><?= $editDoc ? 'Update' : 'Save' ?></button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </form>
  </div>
</div>

<?php foreach ($modalRows as $r): ?>
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
                  <td class="text-nowrap"><?= h(cdoc_date($v['issue_date'])) ?></td>
                  <td class="text-nowrap"><?= h(cdoc_date($v['expiry_date'])) ?></td>
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
// "+ Add" on a missing document in the By company view opens a blank form with the
// company and type already picked.
$pageScripts = <<<'JS'
<script>
document.querySelectorAll('[data-cdoc-add]').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var modal = document.getElementById('companyDocModal');
    var form = modal.querySelector('form');
    form.querySelector('[name="doc_id"]').value = '0';
    ['title', 'doc_number', 'issuing_authority', 'issue_date', 'expiry_date', 'notes', 'file_upload'].forEach(function (n) {
      var el = form.querySelector('[name="' + n + '"]');
      if (el) { el.value = ''; }
    });
    form.querySelector('[name="doc_company_id"]').value = btn.dataset.company;
    form.querySelector('[name="doc_type_code"]').value = btn.dataset.type;
    var current = document.getElementById('cdocCurrentFile');
    if (current) { current.remove(); }
    document.getElementById('cdocModalTitle').textContent = 'Add Company Document';
    document.getElementById('cdocModalSubmit').textContent = 'Save';
    bootstrap.Modal.getOrCreateInstance(modal).show();
  });
});
</script>
JS;
if ($editDoc) {
    $pageScripts .= '<script>bootstrap.Modal.getOrCreateInstance(document.getElementById("companyDocModal")).show();</script>';
}
require_once __DIR__ . '/includes/hr_layout_footer.php';
