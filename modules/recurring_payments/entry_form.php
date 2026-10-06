<?php
/**
 * Recurring Payments — add or edit one entry.
 * The form only collects; Reem checks every field and keeps the entry.
 *
 *   entry_form.php          a new entry
 *   entry_form.php?id=7     edit entry 7
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/url_helper.php';
require_once __DIR__ . '/includes/rpay_helper.php';

require_login(get_application_web_root() . '/login');
$code = rpay_boot($conn);

$rpayBase = get_application_web_root() . '/modules/recurring_payments';
$id = (int)($_GET['id'] ?? 0);
$backUrl = $rpayBase . '/entries.php';
$selfUrl = $rpayBase . '/entry_form.php' . ($id ? '?id=' . $id : '');

try {
    $data = rpay_reem($code, 'GET', '/entries');
} catch (BinvError $e) {
    rpay_stop($e);
}
$buildings = $data['buildings'] ?? [];

$entry = null;
if ($id > 0) {
    foreach ($data['entries'] ?? [] as $row) {
        if ((int)$row['id'] === $id) {
            $entry = $row;
            break;
        }
    }
    if (!$entry) {
        binv_flash('That entry could not be found.', 'danger');
        header('Location: ' . $backUrl);
        exit;
    }
}

$errors = [];
// What the form shows: the saved entry, or what was just typed if Reem refused it.
$form = [
    'title' => $entry['title'] ?? '',
    'building_id' => $entry ? (string)($entry['building']['id'] ?? '') : '',
    'new_building' => '',
    'unit' => $entry['unit'] ?? '',
    'amount' => $entry ? number_format((float)($entry['amount'] ?? 0), 2, '.', '') : '',
    'day' => $entry ? (string)($entry['day'] ?? 1) : '1',
    'auto_create' => $entry ? !empty($entry['auto_create']) : true,
    'first_month' => $entry['first_month'] ?? ($data['month'] ?? date('Y-m')),
    'notes' => $entry['notes'] ?? '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    foreach (['title', 'building_id', 'new_building', 'unit', 'amount', 'day', 'first_month', 'notes'] as $k) {
        $form[$k] = is_string($_POST[$k] ?? null) ? $_POST[$k] : '';
    }
    $form['auto_create'] = !empty($_POST['auto_create']);

    try {
        $saved = rpay_reem($code, $entry ? 'PUT' : 'POST', '/entries' . ($entry ? '/' . $id : ''), $form);
        binv_flash('"' . $saved['title'] . '" ' . ($entry ? 'saved.' : 'added.'));
        header('Location: ' . $backUrl);
        exit;
    } catch (BinvError $e) {
        $errors[] = $e->getMessage();
    }
}

$pageTitle = $entry ? 'Edit entry' : 'Add entry';
require __DIR__ . '/includes/rpay_layout_header.php';
?>

<a href="<?= h($backUrl) ?>" class="text-decoration-none small"><i class="bi bi-arrow-left"></i> Back to entries</a>
<div class="page-header-label mt-2 mb-4"><?= $entry ? 'Edit entry' : 'Add entry' ?></div>

<?php if ($errors): ?>
  <div class="alert alert-danger">
    <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>

<div class="card card-round mb-4" style="max-width:680px">
  <div class="card-body">
    <form method="post" class="row g-3" action="<?= h($selfUrl) ?>">
      <?php csrf_field(); ?>

      <div class="col-12">
        <label class="form-label fw-semibold">Title</label>
        <input type="text" name="title" class="form-control form-control-lg" maxlength="<?= RPAY_TITLE_MAX ?>"
               value="<?= h($form['title']) ?>" placeholder="e.g. Washing machine rent" required <?= $entry ? '' : 'autofocus' ?>>
      </div>

      <div class="col-md-6">
        <label class="form-label fw-semibold">Building</label>
        <select name="building_id" class="form-select" data-search data-placeholder="Pick a building">
          <option value=""></option>
          <?php foreach ($buildings as $b): ?>
            <option value="<?= (int)$b['id'] ?>"<?= (string)$form['building_id'] === (string)$b['id'] ? ' selected' : '' ?>><?= h($b['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-6">
        <label class="form-label fw-semibold">Or a new building <span class="text-muted fw-normal small">(optional)</span></label>
        <input type="text" name="new_building" class="form-control" maxlength="<?= RPAY_NAME_MAX ?>"
               value="<?= h($form['new_building']) ?>" placeholder="Type its name to add it">
      </div>

      <div class="col-md-6">
        <label class="form-label fw-semibold">Shop / unit / other <span class="text-muted fw-normal small">(optional)</span></label>
        <input type="text" name="unit" class="form-control" maxlength="<?= RPAY_UNIT_MAX ?>"
               value="<?= h($form['unit']) ?>" placeholder="e.g. Shop 3, Laundry room">
      </div>

      <div class="col-md-6">
        <label class="form-label fw-semibold">Amount</label>
        <div class="input-group">
          <span class="input-group-text">AED</span>
          <input type="number" name="amount" class="form-control" step="0.01" min="0.01" max="100000000"
                 value="<?= h($form['amount']) ?>" required>
        </div>
      </div>

      <div class="col-md-6">
        <label class="form-label fw-semibold">Day of the month</label>
        <input type="number" name="day" class="form-control" step="1" min="1" max="31" value="<?= h($form['day']) ?>" required>
        <div class="form-text">29, 30 or 31 means the last day in a shorter month.</div>
      </div>

      <div class="col-md-6">
        <label class="form-label fw-semibold">First month</label>
        <input type="month" name="first_month" class="form-control" value="<?= h($form['first_month']) ?>" required>
        <div class="form-text">Payments start from this month.</div>
      </div>

      <div class="col-md-6 d-flex align-items-center">
        <div class="form-check form-switch mt-md-3">
          <input class="form-check-input" type="checkbox" role="switch" name="auto_create" value="1" id="rpayAuto"<?= $form['auto_create'] ? ' checked' : '' ?>>
          <label class="form-check-label fw-semibold" for="rpayAuto">Create the payment automatically</label>
          <div class="form-text">Off: nothing is created until you press "Create now".</div>
        </div>
      </div>

      <div class="col-12">
        <label class="form-label fw-semibold">Notes <span class="text-muted fw-normal small">(optional)</span></label>
        <textarea name="notes" class="form-control" rows="2" maxlength="<?= RPAY_NOTES_MAX ?>"
                  placeholder="Who pays, how they pay"><?= h($form['notes']) ?></textarea>
      </div>

      <div class="col-12">
        <div class="form-text mb-2">Every new payment starts as <strong>Pending</strong>. Changing the amount here changes the coming payments only.</div>
        <div class="d-flex gap-2">
          <button class="btn btn-lg text-white" style="background:var(--primary)">
            <i class="bi bi-check-lg"></i> <?= $entry ? 'Save changes' : 'Add entry' ?>
          </button>
          <a href="<?= h($backUrl) ?>" class="btn btn-lg btn-light">Cancel</a>
        </div>
      </div>
    </form>
  </div>
</div>

<?php require __DIR__ . '/../building_inventory/includes/binv_layout_footer.php'; ?>
