<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/includes/construction_shop_rental_helpers.php';
require_login();
require_module_access($conn, MODULE_CONSTRUCTION);
$cid = co_shop_require_company_id($conn);
$userId = current_user_id();
$id = (int)($_GET['id'] ?? 0);
$inspId = (int)($_GET['inspection_id'] ?? 0);
$stmt = $conn->prepare("SELECT c.*, cl.client_name FROM co_shop_rental_contracts c JOIN co_clients cl ON cl.id = c.client_id WHERE c.id = ? AND c.company_id = ?");
$stmt->execute([$id, $cid]);
$contract = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$contract) { http_response_code(404); exit('Contract not found'); }
$err = ''; $msg = '';
if (!co_shop_phase2a_schema_ready($conn)) $err = 'Run migrations/construction_shop_rental_phase2a.sql';

$inspection = $inspId ? co_shop_get_inspection($conn, $cid, $inspId) : co_shop_latest_completed_inspection($conn, $cid, $id);
if ($inspection && (int)$inspection['contract_id'] !== $id) $inspection = null;
$checklist = $inspection ? (json_decode($inspection['checklist_json'] ?? '{}', true) ?: co_shop_default_checklist()) : co_shop_default_checklist();
$suggested = $inspection ? (json_decode($inspection['suggested_deductions_json'] ?? '{}', true) ?: []) : ['damage'=>0,'cleaning'=>0,'utility'=>0,'missing_keys'=>0];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$err) {
    csrf_verify();
    try {
        $postedChecklist = [];
        foreach (co_shop_inspection_checklist_keys() as $key => $label) {
            $postedChecklist[$key] = [
                'label' => $label,
                'result' => $_POST['check'][$key]['result'] ?? 'pass',
                'note' => $_POST['check'][$key]['note'] ?? '',
            ];
        }
        $saved = co_shop_save_inspection($conn, $cid, $id, [
            'inspection_date' => $_POST['inspection_date'] ?? date('Y-m-d'),
            'inspector_name' => $_POST['inspector_name'] ?? '',
            'checklist' => $postedChecklist,
            'suggested_damage' => $_POST['suggested_damage'] ?? null,
            'suggested_cleaning' => $_POST['suggested_cleaning'] ?? null,
            'suggested_utility' => $_POST['suggested_utility'] ?? null,
            'suggested_missing_keys' => $_POST['suggested_missing_keys'] ?? null,
            'remarks' => $_POST['remarks'] ?? '',
            'complete' => !empty($_POST['complete']),
        ], $userId, $inspection ? (int)$inspection['id'] : null);
        $inspection = $saved;
        $inspId = (int)$saved['id'];
        if (!empty($_FILES['photo']['name'])) {
            $upload = co_handle_document_upload('photo');
            if (empty($upload['error'])) {
                $upload['original_name'] = $_FILES['photo']['name'] ?? null;
                $upload['mime'] = $_FILES['photo']['type'] ?? null;
                co_shop_add_inspection_file($conn, $cid, $inspId, $upload, $userId);
            } elseif (($upload['error'] ?? '') !== 'No file selected.') {
                throw new RuntimeException($upload['error']);
            }
        }
        $msg = !empty($_POST['complete']) ? 'Inspection completed (no accounting posted). Proceed to Deposit Settlement.' : 'Inspection saved as draft.';
        $checklist = json_decode($saved['checklist_json'], true) ?: $checklist;
        $suggested = json_decode($saved['suggested_deductions_json'], true) ?: $suggested;
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}
$files = $inspection ? co_shop_inspection_files($conn, $cid, (int)$inspection['id']) : [];
$pageTitle = 'Move-Out Inspection';
require_once __DIR__ . '/includes/construction_layout_header.php';
?>
<div class="mb-4 d-flex justify-content-between flex-wrap gap-2">
    <div>
        <a href="shop_rental_contract_view.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm mb-2"><i class="bi bi-arrow-left"></i> Back</a>
        <h1 class="h4 mb-0">Move-Out Inspection</h1>
        <p class="text-muted mb-0"><?= h($contract['contract_number']) ?> · <?= h($contract['client_name']) ?> · Ops only — no GL until Deposit Settlement</p>
    </div>
    <?php if ($inspection && $inspection['status']==='completed'): ?>
    <a class="btn btn-success" href="shop_rental_deposit_settle.php?id=<?= $id ?>&inspection_id=<?= (int)$inspection['id'] ?>">Continue to Deposit Settlement</a>
    <?php endif; ?>
</div>
<?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>
<?php if ($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>
<form method="post" enctype="multipart/form-data" class="card card-round"><div class="card-body"><?php csrf_field(); ?>
    <div class="row g-3 mb-3">
        <div class="col-md-3"><label class="form-label">Inspection Date</label><input type="date" name="inspection_date" class="form-control" value="<?= h($inspection['inspection_date'] ?? date('Y-m-d')) ?>" required <?= ($inspection['status']??'')==='completed'?'readonly':'' ?>></div>
        <div class="col-md-4"><label class="form-label">Inspector</label><input type="text" name="inspector_name" class="form-control" value="<?= h($inspection['inspector_name'] ?? '') ?>" required <?= ($inspection['status']??'')==='completed'?'readonly':'' ?>></div>
        <div class="col-md-5"><label class="form-label">Photo / Document (optional)</label><input type="file" name="photo" class="form-control" <?= ($inspection['status']??'')==='completed'?'disabled':'' ?>></div>
    </div>
    <h6>Checklist</h6>
    <div class="table-responsive mb-3"><table class="table table-sm align-middle"><thead><tr><th>Item</th><th>Result</th><th>Note</th></tr></thead><tbody>
    <?php foreach (co_shop_inspection_checklist_keys() as $key => $label):
        $item = $checklist[$key] ?? ['result'=>'pass','note'=>''];
    ?>
        <tr>
            <td><?= h($label) ?></td>
            <td style="width:160px">
                <select name="check[<?= h($key) ?>][result]" class="form-select form-select-sm" <?= ($inspection['status']??'')==='completed'?'disabled':'' ?>>
                    <?php foreach (['pass'=>'Pass','fail'=>'Fail','na'=>'N/A'] as $v=>$t): ?>
                    <option value="<?= $v ?>" <?= ($item['result']??'')===$v?'selected':'' ?>><?= $t ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td><input type="text" name="check[<?= h($key) ?>][note]" class="form-control form-control-sm" value="<?= h($item['note'] ?? '') ?>" <?= ($inspection['status']??'')==='completed'?'readonly':'' ?>></td>
        </tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <h6>Suggested deductions <span class="text-muted fw-normal">(editable — not posted until settlement)</span></h6>
    <div class="row g-3 mb-3">
        <div class="col-md-3"><label class="form-label">Damage</label><input type="number" step="0.01" name="suggested_damage" class="form-control" value="<?= h($suggested['damage'] ?? 0) ?>" <?= ($inspection['status']??'')==='completed'?'readonly':'' ?>></div>
        <div class="col-md-3"><label class="form-label">Cleaning</label><input type="number" step="0.01" name="suggested_cleaning" class="form-control" value="<?= h($suggested['cleaning'] ?? 0) ?>" <?= ($inspection['status']??'')==='completed'?'readonly':'' ?>></div>
        <div class="col-md-3"><label class="form-label">Utilities</label><input type="number" step="0.01" name="suggested_utility" class="form-control" value="<?= h($suggested['utility'] ?? 0) ?>" <?= ($inspection['status']??'')==='completed'?'readonly':'' ?>></div>
        <div class="col-md-3"><label class="form-label">Missing keys</label><input type="number" step="0.01" name="suggested_missing_keys" class="form-control" value="<?= h($suggested['missing_keys'] ?? 0) ?>" <?= ($inspection['status']??'')==='completed'?'readonly':'' ?>></div>
    </div>
    <div class="mb-3"><label class="form-label">Remarks</label><textarea name="remarks" class="form-control" rows="2" <?= ($inspection['status']??'')==='completed'?'readonly':'' ?>><?= h($inspection['remarks'] ?? '') ?></textarea></div>
    <?php if ($files): ?>
    <div class="mb-3"><strong>Attachments:</strong>
        <?php foreach ($files as $f): ?>
            <a class="me-2" href="/<?= h(ltrim($f['file_path'],'/')) ?>" target="_blank"><?= h($f['original_name'] ?: basename($f['file_path'])) ?></a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php if (($inspection['status'] ?? '') !== 'completed'): ?>
    <div class="d-flex gap-2">
        <button class="btn btn-outline-primary" name="complete" value="0">Save Draft</button>
        <button class="btn btn-primary" name="complete" value="1">Complete Inspection</button>
    </div>
    <?php else: ?>
    <div class="alert alert-info mb-0">Completed <?= h($inspection['completed_at'] ?? '') ?>. Accounting starts only when Deposit Settlement is finalized.</div>
    <?php endif; ?>
</div></form>
<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
