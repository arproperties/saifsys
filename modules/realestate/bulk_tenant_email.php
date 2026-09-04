<?php
/**
 * Bulk Tenant Email — compose & select recipients (Operations only).
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/includes/bulk_tenant_email_helper.php';

$companyId = re_bulk_email_require_access($conn);
$userId = (int)(current_user_id() ?: 0);
$brand = getBrandSettings($conn);

$reLayoutFluid = true;
$pageStyles = <<<'CSS'
.bte-hero {
  display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; flex-wrap: wrap;
  margin-bottom: 1.25rem;
}
.bte-hero h4 { font-weight: 700; letter-spacing: -0.02em; }
.bte-stat-row { display: flex; gap: .75rem; flex-wrap: wrap; margin-bottom: 1rem; }
.bte-stat {
  background: #fff; border: 1px solid rgba(0,0,0,.06); border-radius: 14px;
  padding: .75rem 1rem; min-width: 140px; box-shadow: var(--shadow-sm);
}
.bte-stat .lbl { font-size: .72rem; text-transform: uppercase; letter-spacing: .06em; color: #6c757d; }
.bte-stat .val { font-size: 1.35rem; font-weight: 700; color: var(--primary-dark); line-height: 1.2; }
.bte-panel {
  background: #fff; border: 1px solid rgba(0,0,0,.06); border-radius: 16px;
  box-shadow: var(--shadow-sm); overflow: hidden; height: 100%;
}
.bte-panel-h {
  display: flex; justify-content: space-between; align-items: center; gap: .75rem;
  padding: .85rem 1.1rem; border-bottom: 1px solid rgba(0,0,0,.06);
  background: linear-gradient(180deg, #fff, #f8fafc);
}
.bte-panel-h .title { font-weight: 650; margin: 0; font-size: .95rem; }
.bte-panel-b { padding: 1.1rem; }
.bte-filter {
  background: #fff; border: 1px solid rgba(0,0,0,.06); border-radius: 16px;
  box-shadow: var(--shadow-sm); padding: 1rem 1.1rem; margin-bottom: 1rem;
}
.bte-table thead th {
  font-size: .72rem; text-transform: uppercase; letter-spacing: .04em; color: #6c757d;
  background: #f8fafc !important; border-bottom-width: 1px; position: sticky; top: 0; z-index: 1;
}
.bte-table tbody tr { transition: background .12s ease; }
.bte-table tbody tr:hover { background: rgba(13,110,253,.04); }
.bte-chip {
  display: inline-flex; align-items: center; gap: .35rem;
  padding: .2rem .55rem; border-radius: 999px; font-size: .75rem; font-weight: 600;
  background: #eef2ff; color: var(--primary-dark);
}
.bte-chip.warn { background: #fff3cd; color: #856404; }
.bte-chip.ok { background: #d1e7dd; color: #0f5132; }
.bte-sticky-compose { position: sticky; top: 1rem; }
.bte-placeholder code {
  background: #f1f5f9; border-radius: 6px; padding: .1rem .35rem; font-size: .78rem;
}
.bte-footer-bar {
  display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: .5rem;
  padding: .75rem 1.1rem; border-top: 1px solid rgba(0,0,0,.06); background: #f8fafc; font-size: .875rem;
}
.bte-selected-pill {
  display: inline-flex; align-items: center; gap: .4rem;
  background: var(--primary); color: #fff; border-radius: 999px;
  padding: .25rem .75rem; font-weight: 650; font-size: .85rem;
}
@media (max-width: 991.98px) {
  .bte-sticky-compose { position: static; }
}
CSS;

if (!re_bulk_email_tables_ready($conn)) {
    $pageTitle = 'Bulk Tenant Email';
    require_once __DIR__ . '/includes/re_layout_header.php';
    echo '<div class="alert alert-warning">Run migration <code>migrations/re_bulk_tenant_email.sql</code> before using this feature.</div>';
    require_once __DIR__ . '/includes/re_layout_footer.php';
    exit;
}

function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

$buildingId = (int)($_GET['building_id'] ?? $_POST['building_id'] ?? 0);
$unitId = (int)($_GET['unit_id'] ?? $_POST['unit_id'] ?? 0);
$leaseStatus = (string)($_GET['lease_status'] ?? $_POST['lease_status'] ?? 'active');
$search = trim((string)($_GET['q'] ?? $_POST['q'] ?? ''));
$error = '';
$flash = '';

$buildings = $conn->prepare('SELECT id, name FROM re_buildings WHERE company_id = ? ORDER BY name');
$buildings->execute([$companyId]);
$buildings = $buildings->fetchAll(PDO::FETCH_ASSOC) ?: [];

$units = [];
if ($buildingId > 0) {
    $us = $conn->prepare('SELECT id, unit_number FROM re_units WHERE company_id = ? AND building_id = ? ORDER BY unit_number');
    $us->execute([$companyId, $buildingId]);
    $units = $us->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$templates = re_bulk_email_list_templates($conn, $companyId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save_template') {
        $res = re_bulk_email_save_template(
            $conn,
            $companyId,
            $userId,
            (string)($_POST['template_name'] ?? ''),
            (string)($_POST['subject'] ?? ''),
            (string)($_POST['body_html'] ?? '')
        );
        if (!empty($res['ok'])) {
            $flash = 'Template saved.';
            $templates = re_bulk_email_list_templates($conn, $companyId);
        } else {
            $error = $res['error'] ?? 'Could not save template.';
        }
    } elseif ($action === 'preview') {
        $leaseIds = array_map('intval', $_POST['lease_ids'] ?? []);
        $filters = [
            'building_id' => $buildingId,
            'unit_id' => $unitId,
            'lease_status' => $leaseStatus,
            'q' => $search,
        ];
        $validated = re_bulk_email_validate_selected_leases($conn, $companyId, $leaseIds, $filters);
        if (empty($validated['ok'])) {
            $error = $validated['error'] ?? 'Validation failed.';
        } else {
            $_SESSION['re_bulk_email_preview'] = [
                'company_id' => $companyId,
                'lease_ids' => array_values(array_unique(array_filter($leaseIds))),
                'filters' => $filters,
                'subject' => trim((string)($_POST['subject'] ?? '')),
                'body_html' => (string)($_POST['body_html'] ?? ''),
                'recipients' => $validated['recipients'],
                'selected_count' => (int)$validated['selected_count'],
                'excluded_count' => (int)$validated['excluded_count'],
            ];
            // Carry attachment in temp upload into session path after store
            $att = re_bulk_email_store_attachment($conn, $companyId, $_FILES['attachment'] ?? []);
            if (empty($att['ok'])) {
                $error = $att['error'] ?? 'Attachment error.';
                unset($_SESSION['re_bulk_email_preview']);
            } else {
                $_SESSION['re_bulk_email_preview']['attachment'] = !empty($att['path']) ? $att : null;
                header('Location: bulk_tenant_email_preview.php');
                exit;
            }
        }
    }
}

$list = re_bulk_email_list_candidates(
    $conn,
    $companyId,
    $buildingId > 0 ? $buildingId : null,
    $unitId > 0 ? $unitId : null,
    $leaseStatus,
    $search
);
$eligible = $list['eligible'];
$excluded = $list['excluded'];

$pageTitle = 'Bulk Tenant Email';
$pageHead = '<script src="https://cdn.jsdelivr.net/npm/tinymce@6.8.3/tinymce.min.js" referrerpolicy="origin"></script>';
require_once __DIR__ . '/includes/re_layout_header.php';
?>
<div class="bte-hero">
    <div>
        <h4 class="mb-1"><i class="bi bi-envelope-paper me-2" style="color:var(--primary)"></i>Bulk Tenant Email</h4>
        <div class="text-muted">Compose one notice, select tenants, preview, then send privately one-by-one.</div>
    </div>
    <a href="bulk_tenant_email_history.php" class="btn btn-outline-secondary"><i class="bi bi-clock-history me-1"></i> Campaign history</a>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
<?php if ($flash): ?><div class="alert alert-success"><?= h($flash) ?></div><?php endif; ?>

<div class="bte-stat-row">
    <div class="bte-stat"><div class="lbl">Eligible</div><div class="val"><?= count($eligible) ?></div></div>
    <div class="bte-stat"><div class="lbl">Excluded</div><div class="val"><?= count($excluded) ?></div></div>
    <div class="bte-stat"><div class="lbl">Selected</div><div class="val" id="selectedCountHero">0</div></div>
</div>

<form method="get" class="bte-filter">
    <div class="row g-2 align-items-end">
        <div class="col-lg-3 col-md-4">
            <label class="form-label small text-muted mb-1">Building</label>
            <select name="building_id" class="form-select" onchange="this.form.submit()">
                <option value="0">All buildings</option>
                <?php foreach ($buildings as $b): ?>
                    <option value="<?= (int)$b['id'] ?>" <?= $buildingId === (int)$b['id'] ? 'selected' : '' ?>><?= h($b['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-2 col-md-3">
            <label class="form-label small text-muted mb-1">Unit</label>
            <select name="unit_id" class="form-select">
                <option value="0">All units</option>
                <?php foreach ($units as $u): ?>
                    <option value="<?= (int)$u['id'] ?>" <?= $unitId === (int)$u['id'] ? 'selected' : '' ?>><?= h($u['unit_number']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-2 col-md-3">
            <label class="form-label small text-muted mb-1">Lease status</label>
            <select name="lease_status" class="form-select">
                <option value="all" <?= $leaseStatus === 'all' ? 'selected' : '' ?>>All</option>
                <?php foreach (re_bulk_email_lease_statuses() as $st): ?>
                    <option value="<?= h($st) ?>" <?= $leaseStatus === $st ? 'selected' : '' ?>><?= h(ucfirst($st)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-3 col-md-6">
            <label class="form-label small text-muted mb-1">Search</label>
            <input type="text" name="q" class="form-control" value="<?= h($search) ?>" placeholder="Name, email, unit, mobile">
        </div>
        <div class="col-lg-2 col-md-3">
            <button class="btn btn-primary w-100" type="submit"><i class="bi bi-funnel me-1"></i> Filter</button>
        </div>
    </div>
</form>

<form method="post" enctype="multipart/form-data" id="bulkComposeForm">
    <?php csrf_field(); ?>
    <input type="hidden" name="action" value="preview">
    <input type="hidden" name="building_id" value="<?= (int)$buildingId ?>">
    <input type="hidden" name="unit_id" value="<?= (int)$unitId ?>">
    <input type="hidden" name="lease_status" value="<?= h($leaseStatus) ?>">
    <input type="hidden" name="q" value="<?= h($search) ?>">

    <div class="row g-3 align-items-start">
        <div class="col-xl-8 col-lg-7">
            <div class="bte-panel mb-3">
                <div class="bte-panel-h">
                    <p class="title mb-0">Recipients with valid email <span class="bte-chip ok ms-1"><?= count($eligible) ?></span></p>
                    <div class="form-check mb-0">
                        <input class="form-check-input" type="checkbox" id="selectAllEligible">
                        <label class="form-check-label small" for="selectAllEligible">Select all filtered</label>
                    </div>
                </div>
                <div class="table-responsive" style="max-height:min(58vh,560px);overflow:auto;">
                    <table class="table table-sm table-hover mb-0 bte-table align-middle">
                        <thead>
                            <tr>
                                <th style="width:40px"></th>
                                <th>Tenant</th>
                                <th>Building / Unit</th>
                                <th>Email</th>
                                <th>Lease</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!$eligible): ?>
                            <tr><td colspan="5" class="text-center text-muted py-5">No eligible tenants for these filters.</td></tr>
                        <?php else: foreach ($eligible as $r): ?>
                            <tr>
                                <td><input type="checkbox" class="form-check-input lease-check" name="lease_ids[]" value="<?= (int)$r['lease_id'] ?>"></td>
                                <td>
                                    <div class="fw-semibold"><?= h($r['tenant_name']) ?></div>
                                    <div class="small text-muted"><?= h($r['phone'] ?? '') ?></div>
                                </td>
                                <td><?= h($r['building_name']) ?> <span class="text-muted">/</span> <?= h($r['unit_number']) ?></td>
                                <td class="small"><?= h($r['email']) ?></td>
                                <td>
                                    <span class="bte-chip"><?= h($r['lease_status']) ?></span>
                                    <div class="small text-muted mt-1"><?= h($r['lease_number'] ?? '') ?></div>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="bte-footer-bar">
                    <span class="bte-selected-pill"><i class="bi bi-check2-circle"></i> Selected: <span id="selectedCount">0</span></span>
                    <span class="text-muted">Duplicate emails merge to one send at confirmation.</span>
                </div>
            </div>

            <div class="bte-panel">
                <div class="bte-panel-h">
                    <p class="title mb-0">Excluded — missing or invalid email <span class="bte-chip warn ms-1"><?= count($excluded) ?></span></p>
                </div>
                <div class="table-responsive" style="max-height:220px;overflow:auto;">
                    <table class="table table-sm mb-0 bte-table align-middle">
                        <thead><tr><th>Tenant</th><th>Building / Unit</th><th>Reason</th></tr></thead>
                        <tbody>
                        <?php if (!$excluded): ?>
                            <tr><td colspan="3" class="text-muted text-center py-4">None excluded.</td></tr>
                        <?php else: foreach ($excluded as $r): ?>
                            <tr>
                                <td><?= h($r['tenant_name']) ?></td>
                                <td><?= h($r['building_name']) ?> / <?= h($r['unit_number']) ?></td>
                                <td><span class="bte-chip warn"><?= h($r['exclude_reason']) ?></span></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-xl-4 col-lg-5">
            <div class="bte-sticky-compose">
                <div class="bte-panel mb-3">
                    <div class="bte-panel-h"><p class="title mb-0"><i class="bi bi-pencil-square me-1"></i> Compose</p></div>
                    <div class="bte-panel-b">
                        <div class="mb-3">
                            <label class="form-label small text-muted mb-1">Template</label>
                            <select id="templatePick" class="form-select">
                                <option value="">— Custom —</option>
                                <?php foreach ($templates as $t): ?>
                                    <option value="<?= (int)$t['id'] ?>"
                                        data-subject="<?= h($t['subject']) ?>"
                                        data-body="<?= h($t['body_html']) ?>"><?= h($t['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small text-muted mb-1">Subject *</label>
                            <input type="text" name="subject" id="subject" class="form-control" required maxlength="255"
                                   value="<?= h($_POST['subject'] ?? '') ?>" placeholder="e.g. Pest control — {{building_name}}">
                        </div>
                        <div class="mb-3">
                            <label class="form-label small text-muted mb-1">Message *</label>
                            <textarea name="body_html" id="body_html" class="form-control" rows="12"><?= h($_POST['body_html'] ?? '') ?></textarea>
                            <div class="form-text bte-placeholder mt-2">
                                Placeholders:
                                <code>{{tenant_name}}</code>
                                <code>{{building_name}}</code>
                                <code>{{unit_number}}</code>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small text-muted mb-1">Attachment (optional)</label>
                            <input type="file" name="attachment" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                            <div class="form-text">Max 5MB · PDF, JPG, PNG, DOC, DOCX</div>
                        </div>
                        <button type="submit" class="btn btn-primary w-100 py-2" id="btnPreview">
                            <i class="bi bi-eye me-1"></i> Preview &amp; confirm
                        </button>
                    </div>
                </div>
                <div class="bte-panel">
                    <div class="bte-panel-h"><p class="title mb-0">Save as template</p></div>
                    <div class="bte-panel-b">
                        <input type="text" id="template_name" class="form-control mb-2" placeholder="Template name">
                        <button type="button" class="btn btn-outline-secondary w-100" id="btnSaveTemplate">Save current subject/body</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

<form method="post" id="saveTemplateForm" class="d-none">
    <?php csrf_field(); ?>
    <input type="hidden" name="action" value="save_template">
    <input type="hidden" name="template_name" id="st_name">
    <input type="hidden" name="subject" id="st_subject">
    <input type="hidden" name="body_html" id="st_body">
</form>

<script>
tinymce.init({
  selector: '#body_html',
  height: 280,
  menubar: false,
  plugins: 'lists link',
  toolbar: 'bold italic underline | bullist numlist | link | removeformat',
  branding: false,
  promotion: false
});
function syncSelected() {
  const n = document.querySelectorAll('.lease-check:checked').length;
  document.getElementById('selectedCount').textContent = String(n);
  const hero = document.getElementById('selectedCountHero');
  if (hero) hero.textContent = String(n);
}
document.getElementById('selectAllEligible')?.addEventListener('change', function() {
  document.querySelectorAll('.lease-check').forEach(cb => { cb.checked = this.checked; });
  syncSelected();
});
document.querySelectorAll('.lease-check').forEach(cb => cb.addEventListener('change', syncSelected));
document.getElementById('templatePick')?.addEventListener('change', function() {
  const opt = this.options[this.selectedIndex];
  if (!opt || !opt.value) return;
  document.getElementById('subject').value = opt.getAttribute('data-subject') || '';
  const body = opt.getAttribute('data-body') || '';
  if (tinymce.get('body_html')) tinymce.get('body_html').setContent(body);
  else document.getElementById('body_html').value = body;
});
document.getElementById('bulkComposeForm')?.addEventListener('submit', function(e) {
  if (tinymce.get('body_html')) tinymce.get('body_html').save();
  if (!document.querySelectorAll('.lease-check:checked').length) {
    e.preventDefault();
    alert('Select at least one recipient.');
  }
});
document.getElementById('btnSaveTemplate')?.addEventListener('click', function() {
  if (tinymce.get('body_html')) tinymce.get('body_html').save();
  document.getElementById('st_name').value = document.getElementById('template_name').value;
  document.getElementById('st_subject').value = document.getElementById('subject').value;
  document.getElementById('st_body').value = document.getElementById('body_html').value;
  document.getElementById('saveTemplateForm').submit();
});
syncSelected();
</script>
<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>
