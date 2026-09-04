<?php
// hr/leave_types.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/audit_bridge.php';
require_role(['Owner','Admin','HR'], $conn);

if (session_status() !== PHP_SESSION_ACTIVE) session_start(); // only these can access

$err = $ok = '';
// add / update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $id   = intval($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $code = trim($_POST['code'] ?? '') ?: null;
    $is_paid = isset($_POST['is_paid']) ? 1 : 0;
    $quota   = strlen($_POST['annual_quota_days'] ?? '') ? (float)$_POST['annual_quota_days'] : null;
    $attach  = isset($_POST['requires_attachment']) ? 1 : 0;
    $carry   = strlen($_POST['carryover_limit_days'] ?? '') ? (float)$_POST['carryover_limit_days'] : null;
    $color   = trim($_POST['color_hex'] ?? '') ?: null;

    if ($name === '') {
        $err = 'Name is required.';
    } else {
        if ($id > 0) {
            // Get the old quota before updating
            $oldQuotaStmt = $conn->prepare("SELECT annual_quota_days FROM leave_types WHERE id=?");
            $oldQuotaStmt->execute([$id]);
            $oldQuota = $oldQuotaStmt->fetchColumn();
            $oldQuota = $oldQuota !== false ? (float)$oldQuota : null;
            $newQuota = $quota !== null ? (float)$quota : 0.0;
            
            // Update the leave type
            $stmt = $conn->prepare("UPDATE leave_types
               SET name=?, code=?, is_paid=?, annual_quota_days=?, requires_attachment=?, carryover_limit_days=?, color_hex=?
             WHERE id=?");
            $stmt->execute([$name,$code,$is_paid,$quota,$attach,$carry,$color,$id]);
            
            // Always sync balances for current year when quota is set (even if unchanged, to fix out-of-sync balances)
            // Update opening and closing for balances where no leave has been taken/accrued/carried
            $currentYear = (int)date('Y');
            $updateBalances = $conn->prepare("
                UPDATE leave_balances 
                SET opening = ?,
                    closing = ?
                WHERE leave_type_id = ? 
                  AND year = ?
                  AND taken = 0
                  AND accrued = 0
                  AND carried = 0
            ");
            $updateBalances->execute([$newQuota, $newQuota, $id, $currentYear]);
            
            // Recalculate closing for all other balances of this type for current year (where leave has been taken/accrued/carried)
            $recalcBalances = $conn->prepare("
                UPDATE leave_balances 
                SET closing = (opening + accrued + carried) - taken
                WHERE leave_type_id = ? 
                  AND year = ?
                  AND (taken > 0 OR accrued > 0 OR carried > 0)
            ");
            $recalcBalances->execute([$id, $currentYear]);
            
            $ok = 'Leave type updated' . ($oldQuota !== $newQuota ? ' and balances synced.' : '.');
            $uid = $_SESSION['user']['id'] ?? null;
            audit_bridge_hr_ops(
                'leave_type_updated',
                'leave_types',
                $id,
                'Updated leave type ' . $name
                    . ($oldQuota !== $newQuota ? (' (quota ' . $oldQuota . ' → ' . $newQuota . ')') : ''),
                null,
                [
                    'name' => $name,
                    'code' => $code,
                    'is_paid' => $is_paid,
                    'annual_quota_days' => $quota,
                    'old_quota' => $oldQuota,
                ],
                'Leave type #' . $id,
                $uid ? (int)$uid : null
            );
        } else {
            $stmt = $conn->prepare("INSERT INTO leave_types
              (name, code, is_paid, annual_quota_days, requires_attachment, carryover_limit_days, color_hex)
              VALUES (?,?,?,?,?,?,?)");
            $stmt->execute([$name,$code,$is_paid,$quota,$attach,$carry,$color]);
            $newTypeId = (int)$conn->lastInsertId();
            $ok = 'Leave type added.';
            $uid = $_SESSION['user']['id'] ?? null;
            audit_bridge_hr_ops(
                'leave_type_created',
                'leave_types',
                $newTypeId > 0 ? $newTypeId : 0,
                'Created leave type ' . $name,
                null,
                [
                    'name' => $name,
                    'code' => $code,
                    'is_paid' => $is_paid,
                    'annual_quota_days' => $quota,
                ],
                'Leave type #' . ($newTypeId > 0 ? $newTypeId : '?'),
                $uid ? (int)$uid : null
            );
        }
    }
}

// delete (soft guard: only if not referenced)
if (($_GET['action'] ?? '') === 'delete' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    try {
        // prevent delete if used by requests or balances
        $inUse = $conn->prepare("SELECT 1 FROM leave_requests WHERE leave_type_id=? LIMIT 1");
        $inUse->execute([$id]);
        if ($inUse->fetch()) throw new Exception('Type is in use by requests.');

        $inUse = $conn->prepare("SELECT 1 FROM leave_balances WHERE leave_type_id=? LIMIT 1");
        $inUse->execute([$id]);
        if ($inUse->fetch()) throw new Exception('Type is in use by balances.');

        $nameSt = $conn->prepare("SELECT name FROM leave_types WHERE id=? LIMIT 1");
        $nameSt->execute([$id]);
        $typeName = (string)($nameSt->fetchColumn() ?: '');
        $conn->prepare("DELETE FROM leave_types WHERE id=?")->execute([$id]);
        $ok = 'Leave type deleted.';
        $uid = $_SESSION['user']['id'] ?? null;
        audit_bridge_hr_ops(
            'leave_type_deleted',
            'leave_types',
            $id,
            'Deleted leave type' . ($typeName !== '' ? (' ' . $typeName) : ''),
            null,
            ['name' => $typeName !== '' ? $typeName : null],
            'Leave type #' . $id,
            $uid ? (int)$uid : null
        );
    } catch (Exception $e) {
        $err = $e->getMessage();
    }
}

// list
$types = $conn->query("SELECT * FROM leave_types ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$edit  = null;
if (($_GET['action'] ?? '') === 'edit' && isset($_GET['id'])) {
    $stmt = $conn->prepare("SELECT * FROM leave_types WHERE id=?");
    $stmt->execute([(int)$_GET['id']]);
    $edit = $stmt->fetch(PDO::FETCH_ASSOC);
}

$pageTitle = 'Leave Types';
require_once __DIR__ . '/includes/hr_layout_header.php';

echo hr_ui_page_header(
    'Leave Types',
    'Configure leave categories, quotas, and carry-over rules.',
    [
        ['label' => 'HR', 'href' => $hrBase . '/dashboard'],
        ['label' => 'Leave Types'],
    ]
);
?>

  <?php if ($err): ?><div class="alert alert-danger"><?= htmlspecialchars($err) ?></div><?php endif; ?>
  <?php if ($ok):  ?><div class="alert alert-success"><?= htmlspecialchars($ok) ?></div><?php endif; ?>

  <div class="hr-settings-card mb-4">
    <div class="settings-header"><?= $edit ? 'Edit Type' : 'Add Type' ?></div>
    <div class="card-body">
      <form method="post">
        <?php csrf_field(); ?>
        <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label">Name *</label>
            <input class="form-control" name="name" required value="<?= htmlspecialchars($edit['name'] ?? '') ?>">
          </div>
          <div class="col-md-2">
            <label class="form-label">Code</label>
            <input class="form-control" name="code" value="<?= htmlspecialchars($edit['code'] ?? '') ?>">
          </div>
          <div class="col-md-2">
            <label class="form-label">Annual quota (days)</label>
            <input type="number" step="0.5" class="form-control" name="annual_quota_days" value="<?= htmlspecialchars($edit['annual_quota_days'] ?? '') ?>">
          </div>
          <div class="col-md-2">
            <label class="form-label">Carry‑over limit</label>
            <input type="number" step="0.5" class="form-control" name="carryover_limit_days" value="<?= htmlspecialchars($edit['carryover_limit_days'] ?? '') ?>">
          </div>
          <div class="col-md-2">
            <label class="form-label">Color</label>
            <input class="form-control" name="color_hex" placeholder="#16a34a" value="<?= htmlspecialchars($edit['color_hex'] ?? '') ?>">
          </div>
          <div class="col-12 d-flex gap-4">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="is_paid" id="is_paid" <?= !empty($edit) ? ($edit['is_paid']?'checked':'') : 'checked' ?>>
              <label for="is_paid" class="form-check-label">Paid</label>
            </div>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="requires_attachment" id="requires_attachment" <?= !empty($edit) && $edit['requires_attachment']?'checked':'' ?>>
              <label for="requires_attachment" class="form-check-label">Requires attachment</label>
            </div>
          </div>
        </div>
        <div class="mt-3">
          <button class="btn btn-primary"><?= $edit ? 'Update' : 'Add' ?></button>
          <?php if ($edit): ?><a class="btn btn-secondary ms-2" href="leave_types.php">Cancel</a><?php endif; ?>
        </div>
      </form>
    </div>
  </div>

  <div class="hr-settings-card">
    <div class="settings-header">All Types</div>
    <div class="hr-table-shell border-0 shadow-none rounded-0">
      <table class="table table-hover mb-0 align-middle">
        <thead class="table-light">
          <tr>
            <th>Name</th><th>Code</th><th>Paid</th><th>Quota</th><th>Carry</th><th>Attach?</th><th>Color</th><th></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($types as $t): ?>
          <tr>
            <td><?= htmlspecialchars($t['name']) ?></td>
            <td><?= htmlspecialchars($t['code']) ?></td>
            <td><?= $t['is_paid'] ? 'Yes' : 'No' ?></td>
            <td><?= $t['annual_quota_days'] ?? '—' ?></td>
            <td><?= $t['carryover_limit_days'] ?? '—' ?></td>
            <td><?= $t['requires_attachment'] ? 'Yes' : 'No' ?></td>
            <td><?= htmlspecialchars($t['color_hex'] ?? '') ?></td>
            <td class="text-end">
              <a class="btn btn-sm btn-outline-primary" href="?action=edit&id=<?= (int)$t['id'] ?>">Edit</a>
              <a class="btn btn-sm btn-outline-danger" href="?action=delete&id=<?= (int)$t['id'] ?>" onclick="return confirm('Delete this type?')">Delete</a>
            </td>
          </tr>
        <?php endforeach; if (!$types): ?>
          <tr><td colspan="8" class="text-center text-muted py-4">No types yet.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

<?php require_once __DIR__ . '/includes/hr_layout_footer.php'; ?>
