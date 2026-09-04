<?php
// operation/admin_settings.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $gap = max(0, (int)($_POST['min_gap_minutes'] ?? 0));
  $st = $conn->prepare("
    INSERT INTO settings(`key`,`value`)
    VALUES('min_gap_minutes', ?)
    ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)
  ");
  $st->execute([$gap]);
  $saved = true;
}
$cur = (int)($conn->query("SELECT `value` FROM settings WHERE `key`='min_gap_minutes'")->fetchColumn() ?: 0);
?>
<!doctype html><meta charset="utf-8"><title>Settings</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<div class="container py-4" style="max-width:640px">
  <h4>System Settings</h4>
  <?php if(!empty($saved)): ?><div class="alert alert-success">Saved.</div><?php endif; ?>
  <form method="post" class="vstack gap-3">
    <div>
      <label class="form-label">Global travel gap (minutes)</label>
      <input class="form-control" type="number" min="0" max="240" name="min_gap_minutes" value="<?= htmlspecialchars($cur) ?>">
      <div class="form-text">Applies to all bookings (before/after a job).</div>
    </div>
    <button class="btn btn-primary">Save</button>
  </form>
</div>
