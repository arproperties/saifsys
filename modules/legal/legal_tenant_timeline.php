<?php
/**
 * Legal Module - Tenant Timeline
 * The same timeline as the tenant page, plus the "document vs saifsys"
 * differences, which can be added and resolved here.
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/includes/legal_helper.php';
require_once __DIR__ . '/../realestate/includes/tenant_timeline.php';

require_login();
require_module_access($conn, MODULE_LEGAL);
legal_require_access($conn);

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;
$tenantId = (int)($_GET['tenant_id'] ?? $_POST['tenant_id'] ?? 0);
$schemaReady = legal_timeline_schema_ready($conn);

$tenant = null;
if ($tenantId) {
    $st = $conn->prepare("SELECT * FROM re_tenants WHERE id = ? AND company_id = ?");
    $st->execute([$tenantId, $currentCompanyId]);
    $tenant = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$tenant) { $tenantId = 0; }
}

// ---- POST: differences (document vs saifsys) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tenant && $schemaReady) {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    $back = 'legal_tenant_timeline.php?tenant_id=' . $tenantId . '#tt-checks';
    if ($action === 'add_check') {
        $topic = trim($_POST['topic'] ?? '');
        $leaseId = (int)($_POST['lease_id'] ?? 0);
        if ($leaseId) {
            $ok = $conn->prepare("SELECT COUNT(*) FROM re_leases WHERE id = ? AND tenant_id = ? AND company_id = ?");
            $ok->execute([$leaseId, $tenantId, $currentCompanyId]);
            if (!$ok->fetchColumn()) { $leaseId = 0; }
        }
        $caseId = (int)($_POST['case_id'] ?? 0);
        if ($caseId) {
            $ok = $conn->prepare("SELECT COUNT(*) FROM re_legal_cases WHERE id = ? AND company_id = ? AND deleted_at IS NULL");
            $ok->execute([$caseId, $currentCompanyId]);
            if (!$ok->fetchColumn()) { $caseId = 0; }
        }
        if ($topic === '') {
            $_SESSION['error'] = 'Topic is required.';
        } else {
            $conn->prepare("
                INSERT INTO re_legal_data_checks (company_id, tenant_id, lease_id, case_id, topic, source_label, source_value, system_value, created_by)
                VALUES (?,?,?,?,?,?,?,?,?)
            ")->execute([$currentCompanyId, $tenantId, $leaseId ?: null, $caseId ?: null, $topic,
                trim($_POST['source_label'] ?? '') ?: null, trim($_POST['source_value'] ?? '') ?: null,
                trim($_POST['system_value'] ?? '') ?: null, current_user_id()]);
            legal_audit('create', 're_legal_data_check', (int)$conn->lastInsertId(), 'Difference recorded for tenant #' . $tenantId . ': ' . $topic);
            $_SESSION['success'] = 'Difference recorded.';
        }
        header('Location: ' . $back); exit;
    }
    if ($action === 'resolve_check' || $action === 'reopen_check') {
        $checkId = (int)($_POST['check_id'] ?? 0);
        if ($action === 'resolve_check') {
            $res = trim($_POST['resolution'] ?? '');
            $conn->prepare("UPDATE re_legal_data_checks SET status = 'resolved', resolution = ?, resolved_by = ?, resolved_at = NOW() WHERE id = ? AND company_id = ? AND tenant_id = ?")
                 ->execute([$res ?: null, current_user_id(), $checkId, $currentCompanyId, $tenantId]);
            $_SESSION['success'] = 'Marked resolved.';
        } else {
            $conn->prepare("UPDATE re_legal_data_checks SET status = 'open', resolved_by = NULL, resolved_at = NULL WHERE id = ? AND company_id = ? AND tenant_id = ?")
                 ->execute([$checkId, $currentCompanyId, $tenantId]);
            $_SESSION['success'] = 'Reopened.';
        }
        legal_audit('update', 're_legal_data_check', $checkId, ($action === 'resolve_check' ? 'Resolved' : 'Reopened') . ' difference #' . $checkId);
        header('Location: ' . $back); exit;
    }
}

// Tenants with legal records first (for the picker)
$legalTenants = [];
try {
    $st = $conn->prepare("
        SELECT t.id, t.first_name, t.last_name, t.company_name, t.tenant_type, COUNT(DISTINCT c.id) AS cases
        FROM re_tenants t
        LEFT JOIN re_leases l ON l.tenant_id = t.id AND l.company_id = t.company_id
        LEFT JOIN re_legal_cases c ON c.company_id = t.company_id AND c.deleted_at IS NULL AND (c.tenant_id = t.id OR c.lease_id = l.id)
        WHERE t.company_id = ?
        GROUP BY t.id
        HAVING cases > 0
        ORDER BY t.company_name, t.first_name
    ");
    $st->execute([$currentCompanyId]);
    $legalTenants = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('legal_tenant_timeline picker: ' . $e->getMessage());
}
$q = trim($_GET['q'] ?? '');
$searchResults = [];
if ($q !== '') {
    $st = $conn->prepare("
        SELECT id, first_name, last_name, company_name, tenant_type FROM re_tenants
        WHERE company_id = ? AND (company_name LIKE ? OR CONCAT_WS(' ', first_name, last_name) LIKE ? OR email LIKE ? OR phone LIKE ?)
        ORDER BY company_name, first_name LIMIT 30
    ");
    $like = '%' . $q . '%';
    $st->execute([$currentCompanyId, $like, $like, $like, $like]);
    $searchResults = $st->fetchAll(PDO::FETCH_ASSOC);
}

$tl = $tenant ? tenant_timeline_build($conn, $currentCompanyId, $tenantId, true, '../realestate/', '') : null;
$tenantCases = [];
if ($tenant) {
    $ids = array_map(fn($l) => (int)$l['id'], $tl['leases']);
    $in = $ids ? implode(',', $ids) : '0';
    $st = $conn->prepare("SELECT id, case_number, external_reference, title FROM re_legal_cases WHERE company_id = ? AND deleted_at IS NULL AND (tenant_id = ? OR lease_id IN ($in)) ORDER BY id");
    $st->execute([$currentCompanyId, $tenantId]);
    $tenantCases = $st->fetchAll(PDO::FETCH_ASSOC);
}

$pageTitle = $tenant ? 'Timeline — ' . legal_tenant_name($tenant) : 'Tenant Timeline';
require_once __DIR__ . '/includes/legal_layout_header.php';
?>
        <div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
            <div>
                <h1 class="mb-1"><i class="bi bi-clock-history"></i> Tenant Timeline</h1>
                <?php if ($tenant): ?>
                    <div class="fs-5"><?= h(legal_tenant_name($tenant)) ?>
                        <a href="../realestate/tenant_view.php?id=<?= (int)$tenantId ?>" class="btn btn-sm btn-outline-secondary ms-2"><i class="bi bi-person"></i> Tenant page</a>
                    </div>
                <?php else: ?>
                    <div class="text-muted">Pick a tenant to see leases, renewals, notices, cases, hearings and offers in one list.</div>
                <?php endif; ?>
            </div>
            <form method="GET" class="d-flex gap-2 flex-wrap">
                <select name="tenant_id" class="form-select form-select-sm" style="max-width:320px" onchange="this.form.submit()">
                    <option value="">Tenants with legal cases (<?= count($legalTenants) ?>)</option>
                    <?php foreach ($legalTenants as $lt): ?>
                        <option value="<?= (int)$lt['id'] ?>" <?= (int)$lt['id'] === $tenantId ? 'selected' : '' ?>><?= h(legal_tenant_name($lt)) ?> · <?= (int)$lt['cases'] ?> case(s)</option>
                    <?php endforeach; ?>
                </select>
            </form>
            <form method="GET" class="d-flex gap-2">
                <input type="text" name="q" class="form-control form-control-sm" placeholder="Search any tenant" value="<?= h($q) ?>">
                <button class="btn btn-sm btn-outline-primary"><i class="bi bi-search"></i></button>
            </form>
        </div>

        <?php if (!empty($_SESSION['success'])): ?><div class="alert alert-success"><?= h($_SESSION['success']) ?></div><?php unset($_SESSION['success']); endif; ?>
        <?php if (!empty($_SESSION['error'])): ?><div class="alert alert-danger"><?= h($_SESSION['error']) ?></div><?php unset($_SESSION['error']); endif; ?>
        <?php if (!$schemaReady): ?><div class="alert alert-warning">Run <code>migrations/legal_tenant_timeline.sql</code> to enable stages, offers and differences.</div><?php endif; ?>

        <?php if ($q !== ''): ?>
            <div class="card mb-3"><div class="card-body">
                <?php if (!$searchResults): ?><span class="text-muted">No tenant matches “<?= h($q) ?>”.</span>
                <?php else: foreach ($searchResults as $r): ?>
                    <a class="btn btn-sm btn-outline-secondary mb-1" href="?tenant_id=<?= (int)$r['id'] ?>"><?= h(legal_tenant_name($r)) ?> <small class="text-muted">#<?= (int)$r['id'] ?></small></a>
                <?php endforeach; endif; ?>
            </div></div>
        <?php endif; ?>

        <?php if ($tenant): ?>
            <?php tenant_timeline_render($tl, ['rePath' => '../realestate/', 'legalPath' => '', 'checksEditable' => $schemaReady, 'csrf' => '<input type="hidden" name="_csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="tenant_id" value="' . (int)$tenantId . '">']); ?>

            <?php if ($schemaReady): ?>
            <details class="mb-4">
                <summary class="btn btn-sm btn-outline-primary"><i class="bi bi-plus-lg"></i> Record a difference (document vs saifsys)</summary>
                <div class="card mt-2"><div class="card-body">
                <form method="POST" class="row g-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add_check">
                    <input type="hidden" name="tenant_id" value="<?= (int)$tenantId ?>">
                    <div class="col-md-4"><label class="form-label small">Topic</label><input type="text" name="topic" class="form-control form-control-sm" required placeholder="e.g. Number of rooms"></div>
                    <div class="col-md-4"><label class="form-label small">Lease</label>
                        <select name="lease_id" class="form-select form-select-sm"><option value="">— Whole tenant —</option>
                            <?php foreach ($tl['leases'] as $l): ?><option value="<?= (int)$l['id'] ?>"><?= h($l['label'] . ' · ' . $l['rooms_label']) ?></option><?php endforeach; ?>
                        </select></div>
                    <div class="col-md-4"><label class="form-label small">Case</label>
                        <select name="case_id" class="form-select form-select-sm"><option value="">— None —</option>
                            <?php foreach ($tenantCases as $c): ?><option value="<?= (int)$c['id'] ?>"><?= h(($c['external_reference'] ?: $c['case_number']) . ' · ' . $c['title']) ?></option><?php endforeach; ?>
                        </select></div>
                    <div class="col-md-3"><label class="form-label small">Document</label><input type="text" name="source_label" class="form-control form-control-sm" placeholder="e.g. Hani email 04/11"></div>
                    <div class="col-md-4"><label class="form-label small">Document says</label><textarea name="source_value" rows="2" class="form-control form-control-sm"></textarea></div>
                    <div class="col-md-4"><label class="form-label small">saifsys says</label><textarea name="system_value" rows="2" class="form-control form-control-sm"></textarea></div>
                    <div class="col-md-1 d-flex align-items-end"><button class="btn btn-sm btn-primary w-100">Add</button></div>
                </form>
                </div></div>
            </details>
            <?php endif; ?>
        <?php endif; ?>
<?php
require_once __DIR__ . '/includes/legal_layout_footer.php';
?>
