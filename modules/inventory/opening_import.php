<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/inventory/inv_posting.php';
require_once __DIR__ . '/../../includes/inventory/inv_queries.php';

require_login();
require_module_access($conn, MODULE_INVENTORY);
require_department_access(MODULE_INVENTORY, DEPT_INVENTORY, $conn);
require_permission('inventory_docs.create', MODULE_INVENTORY, $conn);

$brand = getBrandSettings($conn);
$companyId = current_company_id($conn) ?: 0;
$message = $messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $companyId && ($_POST['action'] ?? '') === 'import') {
    csrf_verify();
    $fh = null;

    if (empty($_FILES['csv']['tmp_name']) || !is_uploaded_file($_FILES['csv']['tmp_name'])) {
        $message = 'Upload a CSV file.';
        $messageType = 'warning';
    } else {
        $fh = fopen($_FILES['csv']['tmp_name'], 'r');
        if (!$fh) {
            $message = 'Could not read file.';
            $messageType = 'warning';
        } else {
            $headerRow = fgetcsv($fh);
            $map = [];
            if ($headerRow) {
                foreach ($headerRow as $i => $h) {
                    $map[strtolower(trim((string)$h))] = $i;
                }
            }
            $need = ['item_code', 'location_code', 'qty', 'unit_cost'];
            foreach ($need as $k) {
                if (!isset($map[$k])) {
                    fclose($fh);
                    $message = 'CSV must have columns: item_code, location_code, qty, unit_cost (header row).';
                    $messageType = 'warning';
                    $fh = null;
                    break;
                }
            }

            if ($fh) {
                try {
                    $conn->beginTransaction();
                    $docId = inv_create_doc($conn, [
                        'company_id' => $companyId,
                        'doc_type' => 'opening_balance',
                        'doc_date' => $_POST['doc_date'] ?? date('Y-m-d'),
                        'status' => 'draft',
                        'notes' => 'Opening import',
                        'created_by' => current_user_id(),
                    ]);

                    $lineNo = 1;
                    $added = 0;
                    while (($row = fgetcsv($fh)) !== false) {
                        $lineNo++;
                        $itemCode = trim((string)($row[$map['item_code']] ?? ''));
                        $locCode = strtoupper(trim((string)($row[$map['location_code']] ?? '')));
                        $qty = (float)str_replace(',', '', (string)($row[$map['qty']] ?? '0'));
                        $uc = (float)str_replace(',', '', (string)($row[$map['unit_cost']] ?? ''));

                        if ($itemCode === '' && $locCode === '' && ($qty == 0.0)) {
                            continue;
                        }
                        if ($itemCode === '' || $locCode === '' || $qty == 0.0) {
                            throw new RuntimeException('Line ' . $lineNo . ': item_code, location_code, and non-zero qty are required.');
                        }
                        if ($uc < 0) {
                            throw new RuntimeException('Line ' . $lineNo . ': unit_cost must be >= 0.');
                        }

                        $item = inv_get_item_by_code($conn, $companyId, $itemCode);
                        if (!$item) {
                            throw new RuntimeException('Line ' . $lineNo . ': unknown item_code ' . $itemCode);
                        }
                        $stmt = $conn->prepare("SELECT id FROM inv_locations WHERE company_id = ? AND code = ? LIMIT 1");
                        $stmt->execute([$companyId, $locCode]);
                        $locId = (int)$stmt->fetchColumn();
                        if (!$locId) {
                            throw new RuntimeException('Line ' . $lineNo . ': unknown location_code ' . $locCode);
                        }

                        inv_add_line($conn, $docId, [
                            'item_id' => (int)$item['id'],
                            'uom_id' => (int)$item['base_uom_id'],
                            'qty' => $qty,
                            'unit_cost' => $uc,
                            'location_to_id' => $locId,
                        ]);
                        $added++;
                    }
                    if ($added === 0) {
                        throw new RuntimeException('No data rows in CSV.');
                    }
                    fclose($fh);
                    $conn->commit();
                    header('Location: document_edit.php?id=' . $docId);
                    exit;
                } catch (Throwable $e) {
                    if ($conn->inTransaction()) $conn->rollBack();
                    if ($fh && is_resource($fh)) fclose($fh);
                    $message = $e->getMessage();
                    $messageType = 'warning';
                }
            }
        }
    }
}

$pageTitle = 'Opening import';
require_once __DIR__ . '/includes/inv_layout_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div class="page-header-label">Opening balance import</div>
</div>

<p class="text-muted">Upload a CSV with header row: <code>item_code,location_code,qty,unit_cost</code>. Creates a <strong>draft</strong> opening-balance document; open it to review and post.</p>

<?php if ($message): ?>
  <div class="alert alert-<?= $messageType === 'success' ? 'success' : ($messageType === 'warning' ? 'warning' : 'info') ?>"><?= h($message) ?></div>
<?php endif; ?>

<?php if (!$companyId): ?>
  <div class="alert alert-warning">Select a company first.</div>
<?php else: ?>
<div class="card p-3">
  <form method="POST" enctype="multipart/form-data" class="row g-2">
    <?php csrf_field(); ?>
    <input type="hidden" name="action" value="import">
    <div class="col-md-3">
      <label class="form-label">Document date</label>
      <input class="form-control" type="date" name="doc_date" value="<?= h(date('Y-m-d')) ?>">
    </div>
    <div class="col-md-6">
      <label class="form-label">CSV file</label>
      <input class="form-control" type="file" name="csv" accept=".csv,text/csv" required>
    </div>
    <div class="col-12">
      <button class="btn btn-primary">Import and create draft</button>
    </div>
  </form>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/inv_layout_footer.php'; ?>
