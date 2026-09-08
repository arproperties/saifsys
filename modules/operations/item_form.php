<?php
/**
 * Operations — add or edit a stock item.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/url_helper.php';
require_once __DIR__ . '/includes/ops_helper.php';

require_login(get_application_web_root() . '/login');
ops_require_access($conn);

$appBase = get_application_web_root();
$opsBase = $appBase . '/modules/operations';
$companyId = ops_company_id($conn);
$userId = (int)current_user_id();

$id = (int)($_GET['id'] ?? 0);
$item = null;
if ($id > 0) {
    $stmt = $conn->prepare("SELECT * FROM ops_items WHERE id = ? AND company_id = ? LIMIT 1");
    $stmt->execute([$id, $companyId]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$item) {
        ops_flash('That item could not be found.', 'danger');
        header('Location: ' . $opsBase . '/stock.php');
        exit;
    }
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $data = [
        'name' => trim((string)($_POST['name'] ?? '')),
        'unit' => trim((string)($_POST['unit'] ?? '')),
        'min_qty' => (float)($_POST['min_qty'] ?? 0),
        'notes' => trim((string)($_POST['notes'] ?? '')),
        'opening_qty' => (float)($_POST['opening_qty'] ?? 0),
    ];

    if ($data['name'] === '') {
        $errors[] = 'Give the item a name, e.g. "Floor cleaner".';
    }
    if ($data['min_qty'] < 0) {
        $data['min_qty'] = 0;
    }

    if (!$errors) {
        if ($item) {
            // Quantity is never edited here — it only moves through ops_move_stock,
            // so the history always explains the number.
            $conn->prepare("
                UPDATE ops_items SET name = ?, unit = ?, min_qty = ?, notes = ?
                WHERE id = ? AND company_id = ?
            ")->execute([
                $data['name'], $data['unit'] ?: null, $data['min_qty'],
                $data['notes'] ?: null, $id, $companyId,
            ]);
            ops_flash('Item updated.');
        } else {
            $conn->prepare("
                INSERT INTO ops_items (company_id, name, unit, current_qty, min_qty, notes, created_by)
                VALUES (?, ?, ?, 0, ?, ?, ?)
            ")->execute([
                $companyId, $data['name'], $data['unit'] ?: null,
                $data['min_qty'], $data['notes'] ?: null, $userId,
            ]);
            $newId = (int)$conn->lastInsertId();

            if ($data['opening_qty'] > 0) {
                ops_move_stock(
                    $conn, $companyId, $newId, $data['opening_qty'], 'in',
                    null, 'Opening stock', $userId
                );
            }
            ops_flash('Item added.');
        }
        header('Location: ' . $opsBase . '/stock.php');
        exit;
    }

    $item = array_merge($item ?? [], $data);
}

$pageTitle = $id ? 'Edit item' : 'New item';
require __DIR__ . '/includes/ops_layout_header.php';
?>

<a href="<?= h($opsBase) ?>/stock.php" class="text-decoration-none small"><i class="bi bi-arrow-left"></i> Back to stock</a>
<div class="page-header-label mt-2 mb-4"><?= $id ? 'Edit item' : 'New item' ?></div>

<?php if ($errors): ?>
  <div class="alert alert-danger">
    <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>

<div class="card card-round" style="max-width:640px">
  <div class="card-body">
    <form method="post" class="row g-3">
      <?php csrf_field(); ?>

      <div class="col-12">
        <label class="form-label fw-semibold">What is it?</label>
        <input type="text" name="name" class="form-control form-control-lg"
               value="<?= h($item['name'] ?? '') ?>" placeholder="e.g. Floor cleaner" required autofocus>
      </div>

      <div class="col-md-6">
        <label class="form-label fw-semibold">Counted in</label>
        <input type="text" name="unit" class="form-control" value="<?= h($item['unit'] ?? '') ?>"
               placeholder="litre, pcs, box, roll">
      </div>

      <div class="col-md-6">
        <label class="form-label fw-semibold">Warn me at</label>
        <input type="number" step="0.001" min="0" name="min_qty" class="form-control"
               value="<?= h(ops_qty($item['min_qty'] ?? 0)) ?>">
        <div class="form-text">Shows a "Low" warning at or below this.</div>
      </div>

      <?php if (!$id): ?>
      <div class="col-md-6">
        <label class="form-label fw-semibold">How much do you have now?</label>
        <input type="number" step="0.001" min="0" name="opening_qty" class="form-control"
               value="<?= h(ops_qty($item['opening_qty'] ?? 0)) ?>">
        <div class="form-text">Recorded as opening stock. Leave 0 if none yet.</div>
      </div>
      <?php else: ?>
      <div class="col-md-6">
        <label class="form-label fw-semibold">In stock now</label>
        <div class="form-control-plaintext fs-5 fw-bold">
          <?= h(ops_qty($item['current_qty'] ?? 0)) ?> <span class="text-muted fs-6"><?= h($item['unit'] ?? '') ?></span>
        </div>
        <div class="form-text">Change this from the Stock page, so the history stays correct.</div>
      </div>
      <?php endif; ?>

      <div class="col-12">
        <label class="form-label fw-semibold">Notes <span class="text-muted fw-normal small">(optional)</span></label>
        <input type="text" name="notes" class="form-control" value="<?= h($item['notes'] ?? '') ?>"
               placeholder="Brand, supplier, where it is kept">
      </div>

      <div class="col-12 d-flex gap-2">
        <button class="btn btn-lg text-white" style="background:var(--primary)">
          <i class="bi bi-check-lg"></i> <?= $id ? 'Save changes' : 'Add item' ?>
        </button>
        <a href="<?= h($opsBase) ?>/stock.php" class="btn btn-lg btn-light">Cancel</a>
      </div>
    </form>
  </div>
</div>

<?php require __DIR__ . '/includes/ops_layout_footer.php'; ?>
