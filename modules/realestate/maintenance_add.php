<?php
/**
 * Real Estate Module - Add Maintenance Request
 * Location: Unit OR Common Area (exactly one).
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/includes/maintenance_location_helper.php';
require_once __DIR__ . '/includes/maintenance_photo_helper.php';

require_login();
require_module_access($conn, MODULE_REALESTATE);

$brand = getBrandSettings($conn);
$currentCompanyId = re_maint_require_company($conn);
$userId = current_user_id();

$prefillUnitId = !empty($_GET['unit_id']) ? (int)$_GET['unit_id'] : 0;
$success = '';
$error = '';

$buildings = re_maint_load_buildings($conn, $currentCompanyId);

$tenants = $conn->prepare('SELECT id, first_name, last_name FROM re_tenants WHERE company_id = ? AND is_active = 1 ORDER BY last_name, first_name');
$tenants->execute([$currentCompanyId]);
$tenants = $tenants->fetchAll(PDO::FETCH_ASSOC);

$prefillBuildingId = 0;
if ($prefillUnitId > 0) {
    $ust = $conn->prepare('SELECT building_id FROM re_units WHERE id = ? AND company_id = ? LIMIT 1');
    $ust->execute([$prefillUnitId, $currentCompanyId]);
    $prefillBuildingId = (int)($ust->fetchColumn() ?: 0);
}

if (empty($_SESSION['maint_add_form_token']) || !is_string($_SESSION['maint_add_form_token'])) {
    $_SESSION['maint_add_form_token'] = bin2hex(random_bytes(16));
}
if (!isset($_SESSION['maint_add_last_id']) || !is_array($_SESSION['maint_add_last_id'])) {
    $_SESSION['maint_add_last_id'] = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $postedToken = (string)($_POST['form_token'] ?? '');
    $sessionToken = (string)($_SESSION['maint_add_form_token'] ?? '');
    $alreadyId = ($postedToken !== '' && !empty($_SESSION['maint_add_last_id'][$postedToken]))
        ? (int)$_SESSION['maint_add_last_id'][$postedToken]
        : 0;

    // Duplicate / double-click submit: send user to the already-created request
    if ($alreadyId > 0) {
        header('Location: maintenance_view.php?id=' . $alreadyId);
        exit;
    }

    if ($postedToken === '' || $sessionToken === '' || !hash_equals($sessionToken, $postedToken)) {
        $error = 'This form was already submitted or expired. Please refresh and try again.';
        $_SESSION['maint_add_form_token'] = bin2hex(random_bytes(16));
    } else {
        // Consume token immediately (PHP session lock serializes double-clicks)
        unset($_SESSION['maint_add_form_token']);

        $locationType = $_POST['location_type'] ?? 'unit';
        $buildingId = (int)($_POST['building_id'] ?? 0);
        $unitId = (int)($_POST['unit_id'] ?? 0);
        $commonAreaId = (int)($_POST['common_area_id'] ?? 0);
        $tenantId = !empty($_POST['tenant_id']) ? (int)$_POST['tenant_id'] : null;
        $leaseId = !empty($_POST['lease_id']) ? (int)$_POST['lease_id'] : null;
        $requestDate = $_POST['request_date'] ?? date('Y-m-d');
        $priority = $_POST['priority'] ?? 'medium';
        $category = trim($_POST['category'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $cost = !empty($_POST['cost']) ? (float)$_POST['cost'] : 0;
        $notes = trim($_POST['notes'] ?? '');

        $loc = re_maint_validate_location($conn, $currentCompanyId, $locationType, $buildingId, $unitId, $commonAreaId, false);

        if (!$loc['ok']) {
            $error = $loc['error'] ?? 'Invalid location.';
            $_SESSION['maint_add_form_token'] = bin2hex(random_bytes(16));
        } elseif ($description === '') {
            $error = 'Description is required.';
            $_SESSION['maint_add_form_token'] = bin2hex(random_bytes(16));
        } else {
            // Tenant/lease only meaningful for unit locations
            if ($loc['location_type'] !== 'unit') {
                $tenantId = null;
                $leaseId = null;
            }
            try {
                $stmt = $conn->prepare("
                    INSERT INTO re_maintenance_requests
                    (company_id, unit_id, location_type, building_id, common_area_id, tenant_id, lease_id,
                     request_date, priority, category, description, cost, notes, status, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)
                ");
                $requestDateTime = $requestDate . ' ' . date('H:i:s');
                $stmt->execute([
                    $currentCompanyId,
                    $loc['unit_id'],
                    $loc['location_type'],
                    $loc['building_id'],
                    $loc['common_area_id'],
                    $tenantId,
                    $leaseId,
                    $requestDate,
                    $priority,
                    $category,
                    $description,
                    $cost,
                    $notes,
                    $userId,
                ]);
                $requestId = (int)$conn->lastInsertId();
                $_SESSION['maint_add_last_id'][$postedToken] = $requestId;
                // Keep map small
                if (count($_SESSION['maint_add_last_id']) > 30) {
                    $_SESSION['maint_add_last_id'] = array_slice($_SESSION['maint_add_last_id'], -20, null, true);
                }

                try {
                    require_once __DIR__ . '/../../includes/AuditService.php';
                    AuditService::logEvent([
                        'action' => 'create',
                        'module' => 'realestate',
                        'company_id' => (int)$currentCompanyId,
                        'object_type' => 're_maintenance_requests',
                        'object_id' => (string)$requestId,
                        'object_ref' => 'Maintenance #' . $requestId,
                        'summary' => 'Created maintenance request #' . $requestId,
                        'source' => 'user',
                        'success' => true,
                    ]);
                } catch (Throwable $ignored) {
                }

                require_once __DIR__ . '/includes/sla_helper.php';
                create_sla_tracking($conn, $currentCompanyId, $requestId, $priority, $category ?: null, $requestDateTime);

                $photoUpload = ['saved' => 0, 'errors' => []];
                if (!empty($_FILES['photos']) && is_array($_FILES['photos']['name'] ?? null)) {
                    $photoUpload = re_maint_store_photos_from_files(
                        $conn,
                        $currentCompanyId,
                        $requestId,
                        $_FILES['photos'],
                        'before',
                        $userId,
                        8
                    );
                }

                require_once __DIR__ . '/includes/re_email_helper.php';
                $emailResult = send_maintenance_request_notification($conn, $requestId, $currentCompanyId);

                if ($emailResult['success']) {
                    $_SESSION['email_success'] = 'Maintenance request created. Email sent to: ' . implode(', ', $emailResult['sent_to']);
                } else {
                    $_SESSION['email_warning'] = 'Maintenance request created, but email notification failed: ' . implode('; ', $emailResult['errors'] ?? [(string)($emailResult['error'] ?? 'unknown')]);
                }
                if ($photoUpload['saved'] > 0) {
                    $_SESSION['email_success'] = ($_SESSION['email_success'] ?? 'Maintenance request created.')
                        . ' ' . $photoUpload['saved'] . ' photo(s) attached.';
                }
                if (!empty($photoUpload['errors'])) {
                    $_SESSION['email_warning'] = trim(($_SESSION['email_warning'] ?? '') . ' Photo issues: ' . implode('; ', $photoUpload['errors']));
                }

                header('Location: maintenance_view.php?id=' . $requestId);
                exit;
            } catch (Exception $e) {
                $error = 'Error: ' . $e->getMessage();
                $_SESSION['maint_add_form_token'] = bin2hex(random_bytes(16));
            }
        }
    }
}

$formToken = (string)($_SESSION['maint_add_form_token'] ?? '');
if ($formToken === '') {
    $formToken = bin2hex(random_bytes(16));
    $_SESSION['maint_add_form_token'] = $formToken;
}

function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

$pageTitle = 'New Maintenance Request';
require_once __DIR__ . '/includes/re_layout_header.php';
?>
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <div>
                <div class="page-header-label mb-0">New Maintenance Request</div>
                <div class="text-muted small">Capture location, issue details, and optional photos before assigning work.</div>
            </div>
            <a href="maintenance.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Back</a>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success"><?= h($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger"><?= h($error) ?></div>
        <?php endif; ?>

        <div class="card card-round">
            <div class="card-body">
                <form method="POST" id="maintenanceForm" enctype="multipart/form-data">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="form_token" value="<?= h($formToken) ?>">
                    <div id="createRequestBusy" class="alert alert-info d-none mb-3" role="status">
                        <div class="d-flex align-items-center gap-2">
                            <span class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                            <span>Creating request and sending email notification… Please wait. Do not click again.</span>
                        </div>
                    </div>

                    <h6 class="text-uppercase text-muted small mb-3">Location</h6>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Location Type *</label>
                            <select name="location_type" class="form-select" id="locationType" required>
                                <option value="unit" selected>Unit</option>
                                <option value="common_area">Common Area</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Building *</label>
                            <select name="building_id" class="form-select" id="buildingSelect" required>
                                <option value="">-- Select Building --</option>
                                <?php foreach ($buildings as $b): ?>
                                    <option value="<?= (int)$b['id'] ?>" <?= $prefillBuildingId === (int)$b['id'] ? 'selected' : '' ?>>
                                        <?= h($b['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Request Date *</label>
                            <input type="date" class="form-control" name="request_date" value="<?= date('Y-m-d') ?>" required>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3" id="unitFieldWrap">
                            <label class="form-label">Unit *</label>
                            <select name="unit_id" class="form-select" id="unitSelect">
                                <option value="">-- Select Unit --</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3" id="commonAreaFieldWrap" style="display:none">
                            <label class="form-label">Common Area *</label>
                            <select name="common_area_id" class="form-select" id="commonAreaSelect">
                                <option value="">-- Select Common Area --</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3" id="tenantFieldWrap">
                            <label class="form-label">Tenant (Optional)</label>
                            <select name="tenant_id" class="form-select" id="tenantSelect">
                                <option value="">-- Select Tenant --</option>
                                <?php foreach ($tenants as $t): ?>
                                    <option value="<?= (int)$t['id'] ?>">
                                        <?= h($t['first_name'] . ' ' . $t['last_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <hr class="my-3">
                    <h6 class="text-uppercase text-muted small mb-3">Issue details</h6>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Priority *</label>
                            <select name="priority" class="form-select" required>
                                <option value="low">Low</option>
                                <option value="medium" selected>Medium</option>
                                <option value="high">High</option>
                                <option value="urgent">Urgent</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Category</label>
                            <input type="text" class="form-control" name="category" placeholder="e.g., Plumbing, Electrical, HVAC">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Description *</label>
                        <textarea class="form-control" name="description" rows="4" required placeholder="Describe the maintenance issue..."></textarea>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Estimated Cost (AED)</label>
                            <input type="number" step="0.01" class="form-control" name="cost" value="0" min="0">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Photos (optional)</label>
                            <input type="file" name="photos[]" id="photosInput" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp" multiple>
                            <div class="form-text">Up to 8 images · JPG/PNG/GIF/WEBP · Max 10 MB each. Shown under <strong>Request Photos</strong> on the request view.</div>
                            <div id="photoPreview" class="d-flex flex-wrap gap-2 mt-2"></div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Internal notes</label>
                        <textarea class="form-control" name="notes" rows="2" placeholder="Optional notes for the maintenance team"></textarea>
                    </div>

                    <input type="hidden" name="lease_id" id="leaseId" value="">

                    <div class="mt-4 d-flex gap-2">
                        <button type="submit" class="btn btn-primary" id="createRequestBtn">
                            <span class="btn-label">Create Request</span>
                        </button>
                        <a href="maintenance.php" class="btn btn-outline-secondary" id="createRequestCancel">Cancel</a>
                    </div>
                </form>
            </div>
        </div>

    <script>
    (function() {
        const form = document.getElementById('maintenanceForm');
        const submitBtn = document.getElementById('createRequestBtn');
        const cancelBtn = document.getElementById('createRequestCancel');
        const busyBox = document.getElementById('createRequestBusy');
        let submitting = false;

        form?.addEventListener('submit', function(e) {
            if (submitting) {
                e.preventDefault();
                return false;
            }
            if (!form.checkValidity()) {
                return;
            }
            submitting = true;
            if (busyBox) busyBox.classList.remove('d-none');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Creating &amp; sending email…';
            }
            if (cancelBtn) {
                cancelBtn.classList.add('disabled');
                cancelBtn.setAttribute('aria-disabled', 'true');
                cancelBtn.addEventListener('click', function(ev) { ev.preventDefault(); }, { once: true });
            }
        });

        const locationType = document.getElementById('locationType');
        const buildingSelect = document.getElementById('buildingSelect');
        const unitSelect = document.getElementById('unitSelect');
        const commonAreaSelect = document.getElementById('commonAreaSelect');
        const unitWrap = document.getElementById('unitFieldWrap');
        const caWrap = document.getElementById('commonAreaFieldWrap');
        const tenantWrap = document.getElementById('tenantFieldWrap');
        const prefillUnit = <?= (int)$prefillUnitId ?>;

        function syncLocationUi() {
            const isUnit = locationType.value === 'unit';
            unitWrap.style.display = isUnit ? '' : 'none';
            caWrap.style.display = isUnit ? 'none' : '';
            tenantWrap.style.display = isUnit ? '' : 'none';
            unitSelect.required = isUnit;
            commonAreaSelect.required = !isUnit;
            if (!isUnit) {
                unitSelect.value = '';
                document.getElementById('leaseId').value = '';
                document.getElementById('tenantSelect').value = '';
            } else {
                commonAreaSelect.value = '';
            }
        }

        function loadUnits(selected) {
            const bid = buildingSelect.value;
            unitSelect.innerHTML = '<option value="">-- Select Unit --</option>';
            if (!bid) return;
            fetch('ajax_get_units.php?building_id=' + encodeURIComponent(bid))
                .then(r => r.json())
                .then(d => {
                    (d.units || []).forEach(u => {
                        const o = document.createElement('option');
                        o.value = u.id;
                        o.textContent = u.unit_number;
                        if (String(u.id) === String(selected)) o.selected = true;
                        unitSelect.appendChild(o);
                    });
                    if (selected) unitSelect.dispatchEvent(new Event('change'));
                }).catch(() => {});
        }

        function loadCommonAreas(selected) {
            const bid = buildingSelect.value;
            commonAreaSelect.innerHTML = '<option value="">-- Select Common Area --</option>';
            if (!bid) return;
            fetch('ajax_get_common_areas.php?building_id=' + encodeURIComponent(bid))
                .then(r => r.json())
                .then(d => {
                    (d.common_areas || []).forEach(a => {
                        const o = document.createElement('option');
                        o.value = a.id;
                        o.textContent = a.area_name + (a.area_type ? ' (' + a.area_type.replace(/_/g, ' ') + ')' : '');
                        if (String(a.id) === String(selected)) o.selected = true;
                        commonAreaSelect.appendChild(o);
                    });
                }).catch(() => {});
        }

        locationType.addEventListener('change', function() {
            syncLocationUi();
            if (locationType.value === 'unit') loadUnits('');
            else loadCommonAreas('');
        });
        buildingSelect.addEventListener('change', function() {
            if (locationType.value === 'unit') loadUnits('');
            else loadCommonAreas('');
        });
        unitSelect.addEventListener('change', function() {
            const unitId = this.value;
            document.getElementById('leaseId').value = '';
            if (!unitId) return;
            fetch('ajax_get_lease.php?unit_id=' + encodeURIComponent(unitId))
                .then(r => r.json())
                .then(data => {
                    if (data.lease_id) {
                        document.getElementById('leaseId').value = data.lease_id;
                        if (data.tenant_id) document.getElementById('tenantSelect').value = data.tenant_id;
                    }
                }).catch(() => {});
        });

        syncLocationUi();
        if (buildingSelect.value) {
            if (locationType.value === 'unit') loadUnits(prefillUnit);
            else loadCommonAreas('');
        }

        const photosInput = document.getElementById('photosInput');
        const photoPreview = document.getElementById('photoPreview');
        photosInput?.addEventListener('change', function() {
            if (!photoPreview) return;
            photoPreview.innerHTML = '';
            const files = Array.from(this.files || []).slice(0, 8);
            files.forEach(file => {
                const name = file.name || 'photo';
                const looksImage = (file.type && file.type.indexOf('image/') === 0)
                    || /\.(jpe?g|png|gif|webp)$/i.test(name);
                if (!looksImage) return;

                const wrap = document.createElement('div');
                wrap.className = 'border rounded overflow-hidden bg-light';
                wrap.style.width = '88px';

                const img = document.createElement('img');
                img.alt = name;
                img.style.display = 'block';
                img.style.width = '88px';
                img.style.height = '72px';
                img.style.objectFit = 'cover';
                img.style.background = '#e9ecef';

                const caption = document.createElement('div');
                caption.className = 'small text-truncate px-1 py-1';
                caption.title = name;
                caption.textContent = name;

                // FileReader data-URLs are more reliable than blob: URLs under some browser CSP/settings
                const reader = new FileReader();
                reader.onload = function(ev) {
                    img.src = String(ev.target?.result || '');
                };
                reader.onerror = function() {
                    img.alt = 'Preview unavailable';
                };
                reader.readAsDataURL(file);

                wrap.appendChild(img);
                wrap.appendChild(caption);
                photoPreview.appendChild(wrap);
            });
        });
    })();
    </script>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>
