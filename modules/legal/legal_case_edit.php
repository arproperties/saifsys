<?php
/**
 * Real Estate Module - Edit Legal Case
 * (Status is managed via the case view workflow, not here.)
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/includes/legal_helper.php';

require_login();
require_module_access($conn, MODULE_LEGAL);
legal_require_access($conn);

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;
$caseId = !empty($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = $conn->prepare("SELECT * FROM re_legal_cases WHERE id = ? AND company_id = ? AND deleted_at IS NULL");
$stmt->execute([$caseId, $currentCompanyId]);
$case = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$case) {
    $_SESSION['error'] = 'Legal case not found.';
    header('Location: legal_cases.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $title = trim($_POST['title'] ?? '');
    $caseType = $_POST['case_type'] ?? 'other';
    $caseSource = $_POST['case_source'] ?? 'manual';
    $priority = $_POST['priority'] ?? 'medium';
    $claimAmount = (float)($_POST['claim_amount'] ?? 0);
    $recoveredAmount = (float)($_POST['recovered_amount'] ?? 0);
    $jurisdiction = trim($_POST['jurisdiction'] ?? '');
    $externalRef = trim($_POST['external_reference'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $buildingId = !empty($_POST['building_id']) ? (int)$_POST['building_id'] : null;
    $unitId = !empty($_POST['unit_id']) ? (int)$_POST['unit_id'] : null;
    $tenantId = !empty($_POST['tenant_id']) ? (int)$_POST['tenant_id'] : null;
    $leaseId = !empty($_POST['lease_id']) ? (int)$_POST['lease_id'] : null;
    $ownerId = !empty($_POST['owner_id']) ? (int)$_POST['owner_id'] : null;
    $assignedTo = !empty($_POST['assigned_to']) ? (int)$_POST['assigned_to'] : null;
    $openedDate = $_POST['opened_date'] ?: null;
    $deadlineDate = $_POST['deadline_date'] ?: null;
    $nextActionDate = $_POST['next_action_date'] ?: null;
    $reminderDays = isset($_POST['reminder_days_before']) ? max(0, (int)$_POST['reminder_days_before']) : 3;

    if (!array_key_exists($caseType, legal_case_types())) $caseType = 'other';
    if (!array_key_exists($caseSource, legal_case_sources())) $caseSource = 'manual';
    if (!array_key_exists($priority, legal_priorities())) $priority = 'medium';

    if ($title === '') {
        $error = 'Case title is required.';
    } else {
        try {
            $stmt = $conn->prepare("
                UPDATE re_legal_cases SET
                    title = ?, case_type = ?, case_source = ?, priority = ?, claim_amount = ?, recovered_amount = ?,
                    jurisdiction = ?, external_reference = ?, description = ?, building_id = ?, unit_id = ?,
                    tenant_id = ?, lease_id = ?, owner_id = ?, assigned_to = ?, opened_date = ?, deadline_date = ?,
                    next_action_date = ?, reminder_days_before = ?, updated_at = NOW()
                WHERE id = ? AND company_id = ?
            ");
            $stmt->execute([
                $title, $caseType, $caseSource, $priority, $claimAmount, $recoveredAmount,
                $jurisdiction ?: null, $externalRef ?: null, $description, $buildingId, $unitId,
                $tenantId, $leaseId, $ownerId, $assignedTo, $openedDate, $deadlineDate,
                $nextActionDate, $reminderDays, $caseId, $currentCompanyId,
            ]);
            legal_log_event($conn, $caseId, 'note', 'Case details updated');
            legal_audit('update', 're_legal_case', $caseId, 'Updated legal case ' . $case['case_number'], $case, $_POST);
            $_SESSION['success'] = 'Case updated.';
            header('Location: legal_case_view.php?id=' . $caseId);
            exit;
        } catch (Throwable $e) {
            $error = 'Error updating case: ' . $e->getMessage();
        }
    }
}

$buildings = legal_fetch_buildings($conn, $currentCompanyId);
$owners = legal_fetch_owners($conn, $currentCompanyId);
$assignableUsers = legal_fetch_assignable_users($conn, $currentCompanyId);

$tenantsStmt = $conn->prepare("SELECT id, first_name, last_name, company_name, tenant_type FROM re_tenants WHERE company_id = ? AND is_active = 1 ORDER BY first_name, last_name");
$tenantsStmt->execute([$currentCompanyId]);
$tenants = $tenantsStmt->fetchAll(PDO::FETCH_ASSOC);

$leasesStmt = $conn->prepare("
    SELECT l.id, l.lease_number, u.unit_number, b.name AS building_name
    FROM re_leases l JOIN re_units u ON u.id = l.unit_id JOIN re_buildings b ON b.id = u.building_id
    WHERE l.company_id = ? AND l.deleted_at IS NULL ORDER BY l.created_at DESC
");
$leasesStmt->execute([$currentCompanyId]);
$leases = $leasesStmt->fetchAll(PDO::FETCH_ASSOC);

$v = function (string $k, $default = '') use ($case) {
    return $_POST[$k] ?? $case[$k] ?? $default;
};

$pageTitle = 'Edit Legal Case';
require_once __DIR__ . '/includes/legal_layout_header.php';
?>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1><i class="bi bi-pencil-square"></i> Edit Case <?= h($case['case_number']) ?></h1>
            <a href="legal_case_view.php?id=<?= (int)$caseId ?>" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Back</a>
        </div>

        <?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

        <form method="POST">
            <?= csrf_field() ?>
            <div class="card mb-4"><div class="card-header"><h5 class="mb-0">Case Details</h5></div><div class="card-body">
                <div class="row g-3">
                    <div class="col-md-8">
                        <label class="form-label">Title *</label>
                        <input type="text" name="title" class="form-control" required value="<?= h($v('title')) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Priority</label>
                        <select name="priority" class="form-select">
                            <?php foreach (legal_priorities() as $k => $vv): ?>
                                <option value="<?= h($k) ?>" <?= $v('priority') === $k ? 'selected' : '' ?>><?= h($vv) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Case Type</label>
                        <select name="case_type" class="form-select">
                            <?php foreach (legal_case_types() as $k => $vv): ?>
                                <option value="<?= h($k) ?>" <?= $v('case_type') === $k ? 'selected' : '' ?>><?= h($vv) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Source</label>
                        <select name="case_source" class="form-select">
                            <?php foreach (legal_case_sources() as $k => $vv): ?>
                                <option value="<?= h($k) ?>" <?= $v('case_source') === $k ? 'selected' : '' ?>><?= h($vv) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Claim Amount (AED)</label>
                        <input type="number" step="0.01" name="claim_amount" class="form-control" value="<?= h($v('claim_amount')) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Recovered Amount (AED)</label>
                        <input type="number" step="0.01" name="recovered_amount" class="form-control" value="<?= h($v('recovered_amount')) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Jurisdiction / Court</label>
                        <input type="text" name="jurisdiction" class="form-control" value="<?= h($v('jurisdiction')) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">External Reference</label>
                        <input type="text" name="external_reference" class="form-control" value="<?= h($v('external_reference')) ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="3"><?= h($v('description')) ?></textarea>
                    </div>
                </div>
            </div></div>

            <div class="card mb-4"><div class="card-header"><h5 class="mb-0">Linked Records</h5></div><div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Building</label>
                        <select name="building_id" id="buildingSel" class="form-select">
                            <option value="">-- None --</option>
                            <?php foreach ($buildings as $b): ?>
                                <option value="<?= (int)$b['id'] ?>" <?= (int)$v('building_id') === (int)$b['id'] ? 'selected' : '' ?>><?= h($b['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Unit</label>
                        <select name="unit_id" id="unitSel" class="form-select" data-selected="<?= (int)$v('unit_id') ?>">
                            <option value="">-- None --</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Owner</label>
                        <select name="owner_id" class="form-select">
                            <option value="">-- None --</option>
                            <?php foreach ($owners as $o): ?>
                                <option value="<?= (int)$o['id'] ?>" <?= (int)$v('owner_id') === (int)$o['id'] ? 'selected' : '' ?>><?= h($o['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Tenant</label>
                        <select name="tenant_id" class="form-select">
                            <option value="">-- None --</option>
                            <?php foreach ($tenants as $t): ?>
                                <option value="<?= (int)$t['id'] ?>" <?= (int)$v('tenant_id') === (int)$t['id'] ? 'selected' : '' ?>><?= h(legal_tenant_name($t)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Lease</label>
                        <select name="lease_id" class="form-select">
                            <option value="">-- None --</option>
                            <?php foreach ($leases as $l): ?>
                                <option value="<?= (int)$l['id'] ?>" <?= (int)$v('lease_id') === (int)$l['id'] ? 'selected' : '' ?>>
                                    <?= h($l['lease_number']) ?> (<?= h($l['building_name']) ?> - <?= h($l['unit_number']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div></div>

            <div class="card mb-4"><div class="card-header"><h5 class="mb-0">Assignment & Deadlines</h5></div><div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Assigned To</label>
                        <select name="assigned_to" class="form-select">
                            <option value="">-- Unassigned --</option>
                            <?php foreach ($assignableUsers as $usr): ?>
                                <option value="<?= (int)$usr['id'] ?>" <?= (int)$v('assigned_to') === (int)$usr['id'] ? 'selected' : '' ?>><?= h($usr['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Opened Date</label>
                        <input type="date" name="opened_date" class="form-control" value="<?= h($v('opened_date')) ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Next Action</label>
                        <input type="date" name="next_action_date" class="form-control" value="<?= h($v('next_action_date')) ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Deadline</label>
                        <input type="date" name="deadline_date" class="form-control" value="<?= h($v('deadline_date')) ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Remind (days before)</label>
                        <input type="number" name="reminder_days_before" class="form-control" min="0" value="<?= h($v('reminder_days_before','3')) ?>">
                    </div>
                </div>
            </div></div>

            <div class="d-flex gap-2 mb-5">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle"></i> Save Changes</button>
                <a href="legal_case_view.php?id=<?= (int)$caseId ?>" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>

<?php
$pageScripts = <<<'HTML'
<script>
(function () {
    var buildingSel = document.getElementById('buildingSel');
    var unitSel = document.getElementById('unitSel');
    if (!buildingSel || !unitSel) return;
    function loadUnits(buildingId, selected) {
        unitSel.innerHTML = '<option value="">-- None --</option>';
        if (!buildingId) return;
        fetch('../realestate/ajax_get_units.php?building_id=' + encodeURIComponent(buildingId))
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d && d.success && Array.isArray(d.units)) {
                    d.units.forEach(function (u) {
                        var opt = document.createElement('option');
                        opt.value = u.id; opt.textContent = u.unit_number;
                        if (selected && String(selected) === String(u.id)) opt.selected = true;
                        unitSel.appendChild(opt);
                    });
                }
            });
    }
    buildingSel.addEventListener('change', function () { loadUnits(this.value, ''); });
    if (buildingSel.value) { loadUnits(buildingSel.value, unitSel.getAttribute('data-selected') || ''); }
})();
</script>
HTML;
require_once __DIR__ . '/includes/legal_layout_footer.php';
?>
