<?php
/**
 * Real Estate Module - Move-In View & Workflow
 * Complete move-in process with checklist, meter readings, photos, and approval
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/includes/move_in_stages_helper.php';
require_once __DIR__ . '/includes/move_in_form_helper.php';
require_once __DIR__ . '/includes/move_void_helper.php';
require_once __DIR__ . '/includes/re_digital_signature.php';
require_once __DIR__ . '/includes/lease_lifecycle_guard.php';

require_login();
require_module_access($conn, MODULE_REALESTATE);

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;
$currentUserId = $_SESSION['user_id'] ?? null;

$moveInId = !empty($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$moveInId) {
    header('Location: move_in.php');
    exit;
}

// Get move-in details
$moveIn = $conn->prepare("
    SELECT 
        mi.*,
        l.lease_number,
        l.accounting_mode,
        l.start_date as lease_start,
        l.end_date as lease_end,
        l.annual_rent,
        l.monthly_rent,
        l.number_of_installments,
        l.security_deposit,
        u.unit_number,
        u.unit_type,
        u.area_sqm,
        b.name as building_name,
        b.address as building_address,
        t.first_name,
        t.last_name,
        t.phone,
        t.email,
        t.id_number,
        u2.username as created_by_name,
        u3.username as approved_by_name,
        uv1.username as contract_verified_by_name,
        uv2.username as payment_confirmed_by_name,
        uv3.username as keys_handed_over_by_name,
        uv4.username as inspection_completed_by_name
    FROM re_move_ins mi
    JOIN re_leases l ON l.id = mi.lease_id
    JOIN re_units u ON u.id = l.unit_id
    JOIN re_buildings b ON b.id = u.building_id
    JOIN re_tenants t ON t.id = l.tenant_id
    LEFT JOIN user u2 ON u2.id = mi.created_by
    LEFT JOIN user u3 ON u3.id = mi.approved_by
    LEFT JOIN user uv1 ON uv1.id = mi.contract_verified_by
    LEFT JOIN user uv2 ON uv2.id = mi.payment_confirmed_by
    LEFT JOIN user uv3 ON uv3.id = mi.keys_handed_over_by
    LEFT JOIN user uv4 ON uv4.id = mi.inspection_completed_by
    WHERE mi.id = ? AND mi.company_id = ?
");
$moveIn->execute([$moveInId, $currentCompanyId]);
$moveIn = $moveIn->fetch(PDO::FETCH_ASSOC);

// Get first installment amount
$firstInstallment = null;
if ($moveIn) {
    $stmt = $conn->prepare("
        SELECT amount 
        FROM re_lease_installments 
        WHERE lease_id = ? 
        ORDER BY installment_date ASC 
        LIMIT 1
    ");
    $stmt->execute([$moveIn['lease_id']]);
    $firstInstallmentRow = $stmt->fetch(PDO::FETCH_ASSOC);
    $firstInstallment = $firstInstallmentRow['amount'] ?? null;
}

// Calculate annual rent if not available
if ($moveIn && empty($moveIn['annual_rent'])) {
    $moveIn['annual_rent'] = $moveIn['monthly_rent'] * ($moveIn['number_of_installments'] ?? 12);
}

if (!$moveIn) {
    header('Location: move_in.php');
    exit;
}

// Quick Verifications and the checklist item(s) each one stands for.
// Kept in sync both ways: marking a verification ticks its item(s); ticking
// the item(s) marks the verification (Payment needs both items done).
// The ORDER of this array is the required handover sequence: a step can only
// be done after all earlier steps, and only undone when no later step is done.
const RM_VERIFY_LINKS = [
    'contract_verified'    => ['label' => 'Contract Verified',    'icon' => 'bi-file-earmark-check', 'items' => ['Contract signed and verified']],
    'payment_confirmed'    => ['label' => 'Payment Confirmed',    'icon' => 'bi-cash-coin',          'items' => ['Deposit received', 'First Installment rent received', 'First month rent received']],
    'inspection_completed' => ['label' => 'Inspection Completed', 'icon' => 'bi-clipboard-check',    'items' => ['Initial inspection completed']],
    'keys_handed_over'     => ['label' => 'Keys Handed Over',     'icon' => 'bi-key',                'items' => ['Keys handed over']],
];

/** Verification a checklist item belongs to, or null. */
function rm_verify_field_for_item(string $itemName): ?string
{
    foreach (RM_VERIFY_LINKS as $field => $link) {
        if (in_array(strtolower($itemName), array_map('strtolower', $link['items']), true)) {
            return $field;
        }
    }
    return null;
}

/** First earlier step not done yet (blocks Mark done), or null. */
function rm_verify_blocking_before(array $moveIn, string $field): ?string
{
    foreach (RM_VERIFY_LINKS as $f => $link) {
        if ($f === $field) {
            return null;
        }
        if (empty($moveIn[$f])) {
            return $f;
        }
    }
    return null;
}

/** First later step already done (blocks Undo), or null. */
function rm_verify_blocking_after(array $moveIn, string $field): ?string
{
    $after = false;
    foreach (RM_VERIFY_LINKS as $f => $link) {
        if ($after && !empty($moveIn[$f])) {
            return $f;
        }
        if ($f === $field) {
            $after = true;
        }
    }
    return null;
}

/** Why setting $field to $value breaks the sequence, or null when allowed. */
function rm_verify_order_error(array $moveIn, string $field, int $value): ?string
{
    if ($value) {
        $b = rm_verify_blocking_before($moveIn, $field);
        return $b ? 'Complete "' . RM_VERIFY_LINKS[$b]['label'] . '" first. Steps must be done in order.' : null;
    }
    $a = rm_verify_blocking_after($moveIn, $field);
    return $a ? 'Undo "' . RM_VERIFY_LINKS[$a]['label'] . '" first. Steps must be undone in reverse order.' : null;
}

/** After a linked checklist tick changes, set the verification to "all linked items done". */
function rm_sync_verification_from_checklist(PDO $conn, int $moveInId, int $companyId, string $itemName, $userId): void
{
    foreach (RM_VERIFY_LINKS as $field => $link) {
        $names = array_map('strtolower', $link['items']);
        if (!in_array(strtolower($itemName), $names, true)) {
            continue;
        }
        $ph = implode(',', array_fill(0, count($names), '?'));
        $st = $conn->prepare("
            SELECT COUNT(*) AS total, COALESCE(SUM(is_completed), 0) AS done
            FROM re_move_in_checklist_items
            WHERE move_in_id = ? AND company_id = ? AND LOWER(item_name) IN ($ph)
        ");
        $st->execute(array_merge([$moveInId, $companyId], $names));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $allDone = (int)$row['total'] > 0 && (int)$row['done'] === (int)$row['total'] ? 1 : 0;
        $conn->prepare("
            UPDATE re_move_ins SET {$field} = ?, {$field}_by = ?, {$field}_at = ?
            WHERE id = ? AND company_id = ? AND {$field} <> ?
        ")->execute([$allDone, $allDone ? $userId : null, $allDone ? date('Y-m-d H:i:s') : null, $moveInId, $companyId, $allDone]);
    }
}

/** Audit a stage event; never breaks the request. */
function re_lease_lifecycle_audit_safe(array $moveIn, string $action, string $summary): void
{
    try {
        require_once __DIR__ . '/../../includes/AuditService.php';
        AuditService::logEvent([
            'action' => $action,
            'module' => 'realestate',
            'company_id' => (int)$moveIn['company_id'],
            'object_type' => 're_move_ins',
            'object_id' => (string)$moveIn['id'],
            'object_ref' => 'Move-In #' . $moveIn['id'] . ' (' . ($moveIn['lease_number'] ?? '') . ')',
            'summary' => $summary,
            'success' => true,
        ]);
    } catch (Throwable $e) {
        error_log('Move-in audit failed: ' . $e->getMessage());
    }
}

// Upload over post_max_size: PHP drops the whole request body, so there is no action to read
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'The files are larger than the server accepts in one upload (' . ini_get('post_max_size') . '). Upload fewer or smaller files at a time.']);
    exit;
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    header('Content-Type: application/json');
    // Warnings go to the log only: printed into the reply they break the JSON
    ini_set('display_errors', '0');
    csrf_verify();
    
    $action = $_POST['action'];
    $response = ['success' => false, 'message' => ''];
    
    try {
        switch ($action) {
            case 'update_checklist_item':
                // Old checklist flow only: on the stage workflow these flags are set by
                // the stages (keys only through Key Handover), never directly
                if (rms_ready($conn) && (int)($moveIn['workflow_version'] ?? 1) >= 2) {
                    $response = ['success' => false, 'message' => 'This move-in uses the stage workflow: use the stage steps instead.'];
                    break;
                }
                $itemId = (int)$_POST['item_id'];
                $isCompleted = !empty($_POST['is_completed']) ? 1 : 0;
                $itemStmt = $conn->prepare("SELECT * FROM re_move_in_checklist_items WHERE id = ? AND move_in_id = ? AND company_id = ?");
                $itemStmt->execute([$itemId, $moveInId, $currentCompanyId]);
                $item = $itemStmt->fetch(PDO::FETCH_ASSOC);
                if (!$item) {
                    $response = ['success' => false, 'message' => 'Checklist item not found.'];
                    break;
                }

                // Linked items follow the verification sequence
                $linkedField = isset($_POST['is_completed']) ? rm_verify_field_for_item((string)$item['item_name']) : null;
                if ($linkedField && ($isCompleted || !empty($moveIn[$linkedField]))) {
                    $orderError = rm_verify_order_error($moveIn, $linkedField, $isCompleted);
                    if ($orderError) {
                        $response = ['success' => false, 'message' => $orderError];
                        break;
                    }
                }

                $conn->beginTransaction();
                // The tick and the notes are saved by separate requests; only touch what was sent
                if (isset($_POST['is_completed'])) {
                    $stmt = $conn->prepare("
                        UPDATE re_move_in_checklist_items
                        SET is_completed = ?,
                            completed_by = ?,
                            completed_at = ?,
                            updated_at = NOW()
                        WHERE id = ? AND move_in_id = ? AND company_id = ?
                    ");
                    $completedAt = $isCompleted ? date('Y-m-d H:i:s') : null;
                    $stmt->execute([$isCompleted, $isCompleted ? $currentUserId : null, $completedAt, $itemId, $moveInId, $currentCompanyId]);

                    // Keep the linked Quick Verification in sync
                    rm_sync_verification_from_checklist($conn, $moveInId, $currentCompanyId, (string)$item['item_name'], $currentUserId);
                }
                if (isset($_POST['notes'])) {
                    $stmt = $conn->prepare("
                        UPDATE re_move_in_checklist_items
                        SET notes = ?,
                            updated_at = NOW()
                        WHERE id = ? AND move_in_id = ? AND company_id = ?
                    ");
                    $stmt->execute([trim($_POST['notes']), $itemId, $moveInId, $currentCompanyId]);
                }

                // Update move-in status to in_progress if any item is completed
                if (isset($_POST['is_completed']) && $isCompleted) {
                    $conn->prepare("
                        UPDATE re_move_ins 
                        SET status = 'in_progress' 
                        WHERE id = ? AND status = 'pending'
                    ")->execute([$moveInId]);
                }
                $conn->commit();
                
                $response = ['success' => true, 'message' => 'Checklist item updated'];
                break;
                
            case 'update_verification':
                // Old checklist flow only: on the stage workflow these flags are set by
                // the stages (keys only through Key Handover), never directly
                if (rms_ready($conn) && (int)($moveIn['workflow_version'] ?? 1) >= 2) {
                    $response = ['success' => false, 'message' => 'This move-in uses the stage workflow: use the stage steps instead.'];
                    break;
                }
                $field = $_POST['field'] ?? '';
                $value = !empty($_POST['value']) ? 1 : 0;
                
                if (!isset(RM_VERIFY_LINKS[$field])) {
                    $response = ['success' => false, 'message' => 'Unknown verification.'];
                    break;
                }
                if ($moveIn['status'] === 'completed' || $moveIn['status'] === 'cancelled') {
                    $response = ['success' => false, 'message' => 'This move-in is ' . $moveIn['status'] . '. Verifications can no longer be changed.'];
                    break;
                }
                $orderError = rm_verify_order_error($moveIn, $field, $value);
                if ($orderError) {
                    $response = ['success' => false, 'message' => $orderError];
                    break;
                }

                $conn->beginTransaction();
                $conn->prepare("
                    UPDATE re_move_ins
                    SET {$field} = ?, {$field}_by = ?, {$field}_at = ?
                    WHERE id = ? AND company_id = ?
                ")->execute([$value, $value ? $currentUserId : null, $value ? date('Y-m-d H:i:s') : null, $moveInId, $currentCompanyId]);

                // Tick / untick the linked checklist item(s) too
                $names = array_map('strtolower', RM_VERIFY_LINKS[$field]['items']);
                $ph = implode(',', array_fill(0, count($names), '?'));
                $conn->prepare("
                    UPDATE re_move_in_checklist_items
                    SET is_completed = ?, completed_by = ?, completed_at = ?, updated_at = NOW()
                    WHERE move_in_id = ? AND company_id = ? AND LOWER(item_name) IN ($ph) AND is_completed <> ?
                ")->execute(array_merge([$value, $value ? $currentUserId : null, $value ? date('Y-m-d H:i:s') : null, $moveInId, $currentCompanyId], $names, [$value]));

                if ($value) {
                    $conn->prepare("UPDATE re_move_ins SET status = 'in_progress' WHERE id = ? AND status = 'pending'")
                        ->execute([$moveInId]);
                }
                $conn->commit();

                $response = ['success' => true, 'message' => RM_VERIFY_LINKS[$field]['label'] . ($value ? ' marked done' : ' set back to not done')];
                break;
                
            case 'save_meter_reading':
                $meterType = $_POST['meter_type'] ?? '';
                $readingValue = $_POST['reading_value'] ?? '';
                $meterNumber = $_POST['meter_number'] ?? '';
                $notes = $_POST['notes'] ?? '';
                
                if ($meterType && $readingValue) {
                    $stmt = $conn->prepare("
                        INSERT INTO re_meter_readings
                        (company_id, lease_id, reading_type, meter_type, reading_value, reading_date, meter_number, notes, taken_by)
                        VALUES (?, ?, 'move_in', ?, ?, CURDATE(), ?, ?, ?)
                    ");
                    $stmt->execute([$currentCompanyId, $moveIn['lease_id'], $meterType, $readingValue, $meterNumber, $notes, $currentUserId]);
                    $response = ['success' => true, 'message' => 'Meter reading saved'];
                }
                break;

            case 'save_remark':
                $field = $_POST['field'] ?? '';

                if (in_array($field, ['tenant_comments', 'additional_services'])) {
                    $value = trim($_POST['value'] ?? '');
                    $stmt = $conn->prepare("
                        UPDATE re_move_ins
                        SET {$field} = ?
                        WHERE id = ? AND company_id = ?
                    ");
                    $stmt->execute([$value !== '' ? $value : null, $moveInId, $currentCompanyId]);
                    $response = ['success' => true, 'message' => 'Remarks saved'];
                }
                break;

            case 'upload_files':
                $fileType = ($_POST['file_type'] ?? '') === 'inspection_report' ? 'inspection_report' : 'photo';
                $roomArea = trim($_POST['room_area'] ?? '');
                $allowedMimes = [
                    'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp',
                ];
                if ($fileType === 'inspection_report') {
                    $allowedMimes['pdf'] = 'application/pdf';
                }
                $maxSize = 10 * 1024 * 1024; // 10MB
                $uploadStageFlow = rms_ready($conn) && (int)($moveIn['workflow_version'] ?? 1) >= 2;
                $uploadState = $uploadStageFlow && $fileType === 'inspection_report'
                    ? rms_stage_state($conn, (int)$currentCompanyId, $moveIn, rms_items($conn, $moveInId)) : null;
                if ($uploadState && !$uploadState['form_open']) {
                    $response = ['success' => false, 'message' => $uploadState['key_mode'] === 'move_in'
                        ? 'Confirm the key handover (step 4) before uploading the signed inspection form.'
                        : 'Finish the inspection and list the keys (step 4) before uploading the signed inspection form.'];
                    break;
                }

                $files = $_FILES['files'] ?? null;
                if (!$files || !is_array($files['name'])) {
                    $response = ['success' => false, 'message' => 'Please choose a file to upload.'];
                    break;
                }

                $uploadDir = __DIR__ . '/../../uploads/realestate/move_ins/' . $moveInId;
                if (!is_dir($uploadDir)) {
                    @mkdir($uploadDir, 0777, true);
                }
                if (!is_dir($uploadDir) || !is_writable($uploadDir)) {
                    error_log('Move-in upload: folder not writable: ' . $uploadDir);
                    $response = ['success' => false, 'message' => 'The server cannot save files to uploads/realestate/move_ins. Ask your administrator to make that folder writable.'];
                    break;
                }

                $insert = $conn->prepare("
                    INSERT INTO re_move_in_photos
                    (company_id, move_in_id, file_type, photo_path, photo_description, room_area, uploaded_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");

                $saved = 0;
                $errors = [];
                foreach ($files['name'] as $i => $originalName) {
                    if ($files['error'][$i] === UPLOAD_ERR_NO_FILE) {
                        continue;
                    }
                    if ($files['error'][$i] !== UPLOAD_ERR_OK) {
                        $errors[] = $originalName . ': upload failed';
                        continue;
                    }
                    if ($files['size'][$i] > $maxSize) {
                        $errors[] = $originalName . ': file too large (max 10MB)';
                        continue;
                    }
                    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
                    $mime = function_exists('mime_content_type') ? mime_content_type($files['tmp_name'][$i]) : ($allowedMimes[$ext] ?? '');
                    if (!isset($allowedMimes[$ext]) || !in_array($mime, $allowedMimes, true)) {
                        $errors[] = $originalName . ': file type not allowed';
                        continue;
                    }

                    $fileName = uniqid() . '_' . time() . '.' . $ext;
                    if (!move_uploaded_file($files['tmp_name'][$i], $uploadDir . '/' . $fileName)) {
                        $errors[] = $originalName . ': could not be saved';
                        continue;
                    }
                    $insert->execute([
                        $currentCompanyId,
                        $moveInId,
                        $fileType,
                        'uploads/realestate/move_ins/' . $moveInId . '/' . $fileName,
                        mb_substr(basename($originalName), 0, 255),
                        $roomArea !== '' ? mb_substr($roomArea, 0, 100) : null,
                        $currentUserId
                    ]);
                    $saved++;
                }

                if ($saved > 0 && $uploadState) {
                    rms_sync_form_item($conn, $moveInId, $currentUserId ? (int)$currentUserId : null);
                    // Keys handed over when the tenant signs the form
                    if ($uploadState['key_mode'] === 'on_signing' && empty($moveIn['keys_handed_over'])) {
                        $conn->prepare("UPDATE re_move_ins SET keys_handed_over = 1, keys_handed_over_by = ?, keys_handed_over_at = NOW() WHERE id = ? AND company_id = ?")
                            ->execute([$currentUserId, $moveInId, $currentCompanyId]);
                    }
                }
                if ($saved > 0) {
                    $message = $saved . ' file(s) uploaded';
                    if ($errors) {
                        $message .= '. Skipped: ' . implode('; ', $errors);
                    }
                    $response = ['success' => true, 'message' => $message, 'has_errors' => !empty($errors)];
                } else {
                    $response = ['success' => false, 'message' => $errors ? implode('; ', $errors) : 'Please choose a file to upload.'];
                }
                break;

            case 'form_sign':
                // Tenant signs the inspection form on screen: the signature goes into
                // the form, and the signed PDF is filed as the signed copy (step 5)
                if (!re_sig_ready($conn, 're_move_ins')) { $response = ['success' => false, 'message' => 'Run migrations/re_tenant_signatures.sql first.']; break; }
                if ($moveIn['status'] === 'cancelled') { $response = ['success' => false, 'message' => 'This move-in is cancelled.']; break; }
                $signFlow = rms_ready($conn) && (int)($moveIn['workflow_version'] ?? 1) >= 2;
                $signState = $signFlow ? rms_stage_state($conn, (int)$currentCompanyId, $moveIn, rms_items($conn, $moveInId)) : null;
                if ($signState && !$signState['form_open']) {
                    $response = ['success' => false, 'message' => $signState['key_mode'] === 'move_in'
                        ? 'Confirm the key handover (step 4) before the tenant signs the inspection form.'
                        : 'Finish the inspection and list the keys (step 4) before the tenant signs the inspection form.'];
                    break;
                }
                try {
                    $sigPath = re_sig_save_png((string)($_POST['signature'] ?? ''), 'realestate/move_ins/' . $moveInId);
                    $signer = re_sig_name((string)($_POST['signer_name'] ?? ''), trim($moveIn['first_name'] . ' ' . $moveIn['last_name']));
                    $conn->prepare("UPDATE re_move_ins SET tenant_signature_path = ?, tenant_signed_name = ?, tenant_signed_at = NOW() WHERE id = ? AND company_id = ?")
                        ->execute([$sigPath, $signer, $moveInId, $currentCompanyId]);
                    $formData = rmf_load($conn, (int)$currentCompanyId, $moveInId);
                    $pdf = rmf_pdf($conn, $formData, !empty($_POST['include_photos']), [
                        'meters' => !empty($_POST['include_meters']),
                        'services' => !empty($_POST['include_services']),
                        'item_remarks' => !empty($_POST['include_item_remarks']),
                        'insp_remarks' => !empty($_POST['include_insp_remarks']),
                        'with_signature' => true,
                    ]);
                    $pdfRel = 'uploads/realestate/move_ins/' . $moveInId . '/signed_form_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.pdf';
                    if (file_put_contents(__DIR__ . '/../../' . $pdfRel, $pdf) === false) {
                        throw new RuntimeException('The signed form could not be saved.');
                    }
                    $conn->prepare("INSERT INTO re_move_in_photos (company_id, move_in_id, file_type, photo_path, photo_description, uploaded_by) VALUES (?, ?, 'inspection_report', ?, ?, ?)")
                        ->execute([$currentCompanyId, $moveInId, $pdfRel, mb_substr('Signed digitally by ' . $signer, 0, 255), $currentUserId]);
                    // Same as uploading a signed copy: ticks the form item, and keys handed over on signing
                    if ($signState) {
                        rms_sync_form_item($conn, $moveInId, $currentUserId ? (int)$currentUserId : null);
                        if ($signState['key_mode'] === 'on_signing' && empty($moveIn['keys_handed_over'])) {
                            $conn->prepare("UPDATE re_move_ins SET keys_handed_over = 1, keys_handed_over_by = ?, keys_handed_over_at = NOW() WHERE id = ? AND company_id = ?")
                                ->execute([$currentUserId, $moveInId, $currentCompanyId]);
                        }
                    }
                    $response = ['success' => true, 'message' => 'Signed by ' . $signer . '. The signed form is saved.'];
                } catch (Throwable $e) {
                    $response = ['success' => false, 'message' => $e->getMessage()];
                }
                break;

            case 'delete_file':
                if ($moveIn['status'] === 'completed') {
                    $response = ['success' => false, 'message' => 'Files cannot be removed after the move-in is completed.'];
                    break;
                }
                $fileId = (int)($_POST['file_id'] ?? 0);
                $stmt = $conn->prepare("
                    SELECT photo_path FROM re_move_in_photos
                    WHERE id = ? AND move_in_id = ? AND company_id = ?
                ");
                $stmt->execute([$fileId, $moveInId, $currentCompanyId]);
                $path = $stmt->fetchColumn();

                if ($path === false) {
                    $response = ['success' => false, 'message' => 'File not found.'];
                    break;
                }
                $conn->prepare("
                    DELETE FROM re_move_in_photos
                    WHERE id = ? AND move_in_id = ? AND company_id = ?
                ")->execute([$fileId, $moveInId, $currentCompanyId]);

                $fullPath = __DIR__ . '/../../' . $path;
                if (strpos($path, 'uploads/realestate/move_ins/' . $moveInId . '/') === 0 && is_file($fullPath)) {
                    @unlink($fullPath);
                }
                // Last signed form removed: the digital signature goes with it
                if (re_sig_ready($conn, 're_move_ins') && rms_file_counts($conn, $moveInId)['inspection_report'] === 0) {
                    $conn->prepare("UPDATE re_move_ins SET tenant_signature_path = NULL, tenant_signed_name = NULL, tenant_signed_at = NULL WHERE id = ? AND company_id = ?")
                        ->execute([$moveInId, $currentCompanyId]);
                }
                if (rms_ready($conn) && (int)($moveIn['workflow_version'] ?? 1) >= 2) {
                    rms_sync_form_item($conn, $moveInId, $currentUserId ? (int)$currentUserId : null);
                    // Handover confirmed by the signed form: undo it when the last signed form is removed
                    if (rms_key_mode($moveIn) === 'on_signing' && !empty($moveIn['keys_handed_over']) && rms_file_counts($conn, $moveInId)['inspection_report'] === 0) {
                        $conn->prepare("UPDATE re_move_ins SET keys_handed_over = 0, keys_handed_over_by = NULL, keys_handed_over_at = NULL WHERE id = ? AND company_id = ?")
                            ->execute([$moveInId, $currentCompanyId]);
                    }
                }
                $response = ['success' => true, 'message' => 'File removed'];
                break;

            // ----- Move-In stages (workflow_version 2) -----
            case 'stage_contract':
            case 'stage_payment_override':
            case 'insp_toggle':
            case 'insp_add_item':
            case 'insp_add_section':
            case 'insp_remove_item':
            case 'insp_save_remarks':
            case 'insp_item_remark':
            case 'insp_na':
            case 'insp_check_section':
            case 'insp_complete':
            case 'insp_reopen':
            case 'keys_save':
            case 'keys_confirm':
                if (!rms_ready($conn) || (int)($moveIn['workflow_version'] ?? 1) < 2) {
                    $response = ['success' => false, 'message' => 'This move-in does not use the stage workflow.'];
                    break;
                }
                if (in_array($moveIn['status'], ['completed', 'cancelled'], true)) {
                    $response = ['success' => false, 'message' => 'This move-in is ' . $moveIn['status'] . ' and can no longer be changed.'];
                    break;
                }
                $uid = $currentUserId ? (int)$currentUserId : null;
                $state = rms_stage_state($conn, (int)$currentCompanyId, $moveIn, rms_items($conn, $moveInId));
                $value = !empty($_POST['value']) ? 1 : 0;
                $isManager = has_role('Owner', $conn) || has_role('Admin', $conn);

                // A completed inspection is locked: Edit inspection reopens it
                $inspLockedNow = $state['done'][3];
                if ($inspLockedNow && in_array($action, ['insp_toggle', 'insp_na', 'insp_check_section', 'insp_add_item', 'insp_add_section', 'insp_remove_item', 'insp_item_remark', 'insp_save_remarks'], true)) {
                    $response = ['success' => false, 'message' => 'The inspection is completed. Click Edit inspection to change it.'];
                    break;
                }

                if ($action === 'insp_complete') {
                    if ($err = rms_stage_guard($state, 3)) { $response = ['success' => false, 'message' => $err]; break; }
                    if (!$state['insp_all_checked']) { $response = ['success' => false, 'message' => 'Check (or mark N/A) every inspection item first: ' . $state['items_checked'] . ' of ' . $state['items_total'] . ' done.']; break; }
                    $conn->prepare("UPDATE re_move_ins SET inspection_completed = 1, inspection_completed_by = ?, inspection_completed_at = NOW(), status = IF(status = 'pending', 'in_progress', status) WHERE id = ? AND company_id = ?")
                        ->execute([$uid, $moveInId, $currentCompanyId]);
                    $response = ['success' => true, 'message' => 'Inspection completed.'];
                    break;
                }

                if ($action === 'insp_reopen') {
                    if (!$state['done'][3]) { $response = ['success' => false, 'message' => 'The inspection is not completed.']; break; }
                    // The keys handover and the signed form record the inspection as it was
                    if ($err = rms_stage_guard($state, 3, true)) { $response = ['success' => false, 'message' => $err]; break; }
                    $reason = trim((string)($_POST['reason'] ?? ''));
                    if ($reason === '') { $response = ['success' => false, 'message' => 'Enter the reason for changing the inspection.']; break; }
                    $conn->prepare("UPDATE re_move_ins SET inspection_completed = 0, inspection_completed_by = NULL, inspection_completed_at = NULL WHERE id = ? AND company_id = ?")
                        ->execute([$moveInId, $currentCompanyId]);
                    re_lease_lifecycle_audit_safe($moveIn, 'move_in_inspection_reopened', 'Inspection of move-in #' . $moveInId . ' reopened: ' . $reason);
                    $response = ['success' => true, 'message' => 'The inspection is open for changes. Complete it again when done.'];
                    break;
                }

                if ($action === 'stage_contract') {
                    if ($err = rms_stage_guard($state, 1, !$value)) { $response = ['success' => false, 'message' => $err]; break; }
                    $conn->prepare("UPDATE re_move_ins SET contract_verified = ?, contract_verified_by = ?, contract_verified_at = ? WHERE id = ? AND company_id = ?")
                        ->execute([$value, $value ? $uid : null, $value ? date('Y-m-d H:i:s') : null, $moveInId, $currentCompanyId]);
                    if ($value) {
                        $conn->prepare("UPDATE re_move_ins SET status = 'in_progress' WHERE id = ? AND status = 'pending'")->execute([$moveInId]);
                    }
                    $response = ['success' => true, 'message' => $value ? 'Contract signed confirmed.' : 'Contract signed set back to not done.'];
                    break;
                }

                if ($action === 'stage_payment_override') {
                    if (!$isManager) { $response = ['success' => false, 'message' => 'Only Owner or Admin can confirm payment manually.']; break; }
                    if ($value) {
                        $reason = trim((string)($_POST['reason'] ?? ''));
                        if ($reason === '') { $response = ['success' => false, 'message' => 'Please enter a reason for confirming the payment manually.']; break; }
                        if ($err = rms_stage_guard($state, 2)) { $response = ['success' => false, 'message' => $err]; break; }
                        // Keys may go out before the deposit is recorded only when that is acknowledged
                        if (!$state['payment']['deposit']['done'] && empty($_POST['ack_deposit'])) {
                            $response = ['success' => false, 'message' => 'The security deposit is not recorded. Tick the confirmation that the keys may be released without it.']; break;
                        }
                        $conn->prepare("UPDATE re_move_ins SET payment_override = 1, payment_override_by = ?, payment_override_at = NOW(), payment_override_reason = ? WHERE id = ? AND company_id = ?")
                            ->execute([$uid, mb_substr($reason, 0, 500), $moveInId, $currentCompanyId]);
                    } else {
                        if (empty($moveIn['payment_override'])) { $response = ['success' => false, 'message' => 'Payment was not confirmed manually.']; break; }
                        if (!$state['payment']['auto_done'] && ($err = rms_stage_guard($state, 2, true))) { $response = ['success' => false, 'message' => $err]; break; }
                        $conn->prepare("UPDATE re_move_ins SET payment_override = 0, payment_override_by = NULL, payment_override_at = NULL, payment_override_reason = NULL WHERE id = ? AND company_id = ?")
                            ->execute([$moveInId, $currentCompanyId]);
                    }
                    rms_sync_flags($conn, $moveInId, (int)$currentCompanyId, $uid);
                    re_lease_lifecycle_audit_safe($moveIn, $value ? 'move_in_payment_override' : 'move_in_payment_override_removed',
                        $value ? ('Payment confirmed manually: ' . trim((string)($_POST['reason'] ?? ''))) : 'Manual payment confirmation removed');
                    $response = ['success' => true, 'message' => $value ? 'Payment confirmed manually.' : 'Manual payment confirmation removed.'];
                    break;
                }

                if ($action === 'insp_toggle') {
                    $itemId = (int)($_POST['item_id'] ?? 0);
                    $chk = $conn->prepare("SELECT id, is_checked, item_text FROM re_move_in_inspection_items WHERE id = ? AND move_in_id = ? AND company_id = ?");
                    $chk->execute([$itemId, $moveInId, $currentCompanyId]);
                    $chkRow = $chk->fetch(PDO::FETCH_ASSOC);
                    if (!$chkRow) { $response = ['success' => false, 'message' => 'Inspection item not found.']; break; }
                    $kind = rms_item_kind((string)$chkRow['item_text']);
                    if ($kind === 'form') { $response = ['success' => false, 'message' => 'This item is ticked automatically when the signed inspection form is uploaded in step 5.']; break; }
                    if ($kind === 'photos' && $value && $state['files']['photo'] === 0) { $response = ['success' => false, 'message' => 'Upload at least one photo in Move-In Photos first.']; break; }
                    if ($err = rms_stage_guard($state, 3, !$value)) { $response = ['success' => false, 'message' => $err]; break; }
                    $conn->beginTransaction();
                    $conn->prepare("UPDATE re_move_in_inspection_items SET is_checked = ?, checked_by = ?, checked_at = ?, note = NULL WHERE id = ?")
                        ->execute([$value, $value ? $uid : null, $value ? date('Y-m-d H:i:s') : null, $itemId]);
                    if (rms_na_ready($conn)) {
                        $conn->prepare("UPDATE re_move_in_inspection_items SET is_na = 0 WHERE id = ?")->execute([$itemId]);
                    }
                    rms_sync_flags($conn, $moveInId, (int)$currentCompanyId, $uid);
                    $conn->commit();
                    $response = ['success' => true, 'message' => $value ? 'Checked' : 'Unchecked'];
                    break;
                }

                if ($action === 'insp_add_item' || $action === 'insp_add_section') {
                    if ($state['done'][4]) { $response = ['success' => false, 'message' => 'Keys are already handed over. Undo the key handover to change the inspection.']; break; }
                    $section = trim((string)($_POST['section_name'] ?? ''));
                    $text = trim((string)($_POST['item_text'] ?? ''));
                    if ($section === '' || $text === '') { $response = ['success' => false, 'message' => $action === 'insp_add_section' ? 'Enter a section name and its first checklist item.' : 'Enter the checklist item.']; break; }
                    $ord = $conn->prepare("SELECT MAX(section_order) so, MAX(CASE WHEN section_name = ? THEN section_order END) this_so, MAX(CASE WHEN section_name = ? THEN item_order END) io FROM re_move_in_inspection_items WHERE move_in_id = ?");
                    $ord->execute([$section, $section, $moveInId]);
                    $o = $ord->fetch(PDO::FETCH_ASSOC);
                    if ($action === 'insp_add_section' && $o['this_so'] !== null) { $response = ['success' => false, 'message' => 'A section with this name already exists.']; break; }
                    $sectionOrder = $o['this_so'] !== null ? (int)$o['this_so'] : ((int)$o['so'] + 1);
                    $conn->prepare("INSERT INTO re_move_in_inspection_items (company_id, move_in_id, section_name, section_order, item_text, item_order, is_custom, created_by) VALUES (?, ?, ?, ?, ?, ?, 1, ?)")
                        ->execute([$currentCompanyId, $moveInId, mb_substr($section, 0, 150), $sectionOrder, mb_substr($text, 0, 255), (int)$o['io'] + 1, $uid]);
                    rms_sync_flags($conn, $moveInId, (int)$currentCompanyId, $uid);
                    $response = ['success' => true, 'message' => $action === 'insp_add_section' ? 'Section added.' : 'Item added.'];
                    break;
                }

                if ($action === 'insp_remove_item') {
                    $itemId = (int)($_POST['item_id'] ?? 0);
                    if ($state['done'][4]) { $response = ['success' => false, 'message' => 'Keys are already handed over. Undo the key handover to change the inspection.']; break; }
                    $del = $conn->prepare("DELETE FROM re_move_in_inspection_items WHERE id = ? AND move_in_id = ? AND company_id = ? AND is_checked = 0");
                    $del->execute([$itemId, $moveInId, $currentCompanyId]);
                    if (!$del->rowCount()) { $response = ['success' => false, 'message' => 'Only unchecked items can be removed.']; break; }
                    rms_sync_flags($conn, $moveInId, (int)$currentCompanyId, $uid);
                    $response = ['success' => true, 'message' => 'Item removed.'];
                    break;
                }

                // Not applicable (value 1) counts as done; value 0 sets the item back to open
                if ($action === 'insp_na') {
                    if (!rms_na_ready($conn)) { $response = ['success' => false, 'message' => 'Run migrations/re_move_in_item_na.sql to mark items as not applicable.']; break; }
                    $itemId = (int)($_POST['item_id'] ?? 0);
                    $chk = $conn->prepare("SELECT item_text FROM re_move_in_inspection_items WHERE id = ? AND move_in_id = ? AND company_id = ?");
                    $chk->execute([$itemId, $moveInId, $currentCompanyId]);
                    $text = $chk->fetchColumn();
                    if ($text === false) { $response = ['success' => false, 'message' => 'Inspection item not found.']; break; }
                    if (rms_item_kind((string)$text) === 'form') { $response = ['success' => false, 'message' => 'This item is handled in step 5.']; break; }
                    if ($err = rms_stage_guard($state, 3, !$value)) { $response = ['success' => false, 'message' => $err]; break; }
                    if ($state['done'][4]) { $response = ['success' => false, 'message' => 'Keys are already handed over. Undo the key handover to change the inspection.']; break; }
                    $conn->beginTransaction();
                    $conn->prepare("UPDATE re_move_in_inspection_items SET is_na = ?, is_checked = ?, checked_by = ?, checked_at = ?, note = NULL WHERE id = ?")
                        ->execute([$value, $value, $value ? $uid : null, $value ? date('Y-m-d H:i:s') : null, $itemId]);
                    rms_sync_flags($conn, $moveInId, (int)$currentCompanyId, $uid);
                    $conn->commit();
                    $response = ['success' => true, 'message' => $value ? 'Marked not applicable.' : 'Set back to open.'];
                    break;
                }

                if ($action === 'insp_item_remark') {
                    if (!rms_remark_ready($conn)) { $response = ['success' => false, 'message' => 'Run migrations/re_move_in_item_remarks.sql to add remarks to items.']; break; }
                    $remark = trim((string)($_POST['remark'] ?? ''));
                    $upd = $conn->prepare("UPDATE re_move_in_inspection_items SET remark = ? WHERE id = ? AND move_in_id = ? AND company_id = ?");
                    $upd->execute([$remark !== '' ? mb_substr($remark, 0, 500) : null, (int)($_POST['item_id'] ?? 0), $moveInId, $currentCompanyId]);
                    $response = ['success' => true, 'message' => $remark !== '' ? 'Remark saved.' : 'Remark removed.'];
                    break;
                }

                // Tick (value 1) or clear (value 0) every item of one section
                if ($action === 'insp_check_section') {
                    if ($err = rms_stage_guard($state, 3, !$value)) { $response = ['success' => false, 'message' => $err]; break; }
                    if ($state['done'][4] && !$value) { $response = ['success' => false, 'message' => 'Keys are already handed over. Undo the key handover to change the inspection.']; break; }
                    $sec = $conn->prepare("SELECT id, item_text FROM re_move_in_inspection_items WHERE move_in_id = ? AND company_id = ? AND section_name = ? AND is_checked <> ?");
                    $sec->execute([$moveInId, $currentCompanyId, (string)($_POST['section_name'] ?? ''), $value]);
                    $upd = $conn->prepare("UPDATE re_move_in_inspection_items SET is_checked = ?, checked_by = ?, checked_at = ?, note = NULL WHERE id = ?");
                    $changed = 0; $skippedPhotos = false;
                    $conn->beginTransaction();
                    foreach ($sec->fetchAll(PDO::FETCH_ASSOC) as $row) {
                        $kind = rms_item_kind((string)$row['item_text']);
                        if ($kind === 'form') { continue; }                 // ticked by step 5
                        if ($kind === 'photos' && $value && $state['files']['photo'] === 0) { $skippedPhotos = true; continue; }
                        $upd->execute([$value, $value ? $uid : null, $value ? date('Y-m-d H:i:s') : null, (int)$row['id']]);
                        $changed++;
                    }
                    if (!$value && rms_na_ready($conn)) {
                        $conn->prepare("UPDATE re_move_in_inspection_items SET is_na = 0 WHERE move_in_id = ? AND company_id = ? AND section_name = ?")
                            ->execute([$moveInId, $currentCompanyId, (string)($_POST['section_name'] ?? '')]);
                    }
                    rms_sync_flags($conn, $moveInId, (int)$currentCompanyId, $uid);
                    $conn->commit();
                    $msg = $value ? $changed . ' item(s) checked.' : $changed . ' item(s) cleared.';
                    if ($skippedPhotos) { $msg .= ' "Photos taken" was left: upload a photo first.'; }
                    $response = ['success' => true, 'message' => $msg];
                    break;
                }

                if ($action === 'insp_save_remarks') {
                    $conn->prepare("UPDATE re_move_ins SET inspection_notes = ? WHERE id = ? AND company_id = ?")
                        ->execute([trim((string)($_POST['remarks'] ?? '')) ?: null, $moveInId, $currentCompanyId]);
                    $response = ['success' => true, 'message' => 'Inspection remarks saved.'];
                    break;
                }

                if ($action === 'keys_save') {
                    if ($state['done'][4]) { $response = ['success' => false, 'message' => 'Keys are already handed over. Undo the handover to edit.']; break; }
                    if ($err = rms_stage_guard($state, 4)) { $response = ['success' => false, 'message' => $err]; break; }
                    $rows = $_POST['keys'] ?? [];
                    $upd = $conn->prepare("UPDATE re_move_in_keys SET key_label = ?, quantity = ?, card_numbers = ?, updated_by = ? WHERE id = ? AND move_in_id = ? AND company_id = ?");
                    foreach ((array)$rows as $keyId => $row) {
                        $label = trim((string)($row['label'] ?? ''));
                        $upd->execute([
                            mb_substr($label !== '' ? $label : 'Others', 0, 100),
                            max(0, min(99, (int)($row['quantity'] ?? 0))),
                            mb_substr(trim((string)($row['cards'] ?? '')), 0, 255) ?: null,
                            $uid, (int)$keyId, $moveInId, $currentCompanyId,
                        ]);
                    }
                    if (trim((string)($_POST['new_label'] ?? '')) !== '') {
                        $conn->prepare("INSERT INTO re_move_in_keys (company_id, move_in_id, key_label, quantity, card_numbers, display_order, updated_by) SELECT ?, ?, ?, ?, ?, COALESCE(MAX(display_order), 0) + 1, ? FROM re_move_in_keys WHERE move_in_id = ?")
                            ->execute([$currentCompanyId, $moveInId, mb_substr(trim($_POST['new_label']), 0, 100), max(0, min(99, (int)($_POST['new_quantity'] ?? 0))), mb_substr(trim((string)($_POST['new_cards'] ?? '')), 0, 255) ?: null, $uid, $moveInId]);
                    }
                    // Key handover date: the move-in date by default (keys given at move-in);
                    // a later date is a scheduled handover, confirmed on that day
                    if (rms_key_mode_ready($conn) && isset($_POST['key_handover_date'])) {
                        $moveInDay = (string)$moveIn['move_in_date'];
                        $day = trim((string)$_POST['key_handover_date']);
                        $d = $day === '' ? null : DateTime::createFromFormat('Y-m-d', $day);
                        if ($day !== '' && (!$d || $d->format('Y-m-d') !== $day)) { $response = ['success' => false, 'message' => 'Choose a valid key handover date.']; break; }
                        $day = $d ? $d->format('Y-m-d') : $moveInDay;
                        if ($day < $moveInDay) { $response = ['success' => false, 'message' => 'The key handover date cannot be before the move-in date (' . date('d M Y', strtotime($moveInDay)) . ').']; break; }
                        $later = $day > $moveInDay;
                        $conn->prepare("UPDATE re_move_ins SET key_handover_mode = ?, key_handover_scheduled_at = ? WHERE id = ? AND company_id = ?")
                            ->execute([$later ? 'scheduled' : 'move_in', $later ? $day . ' 00:00:00' : null, $moveInId, $currentCompanyId]);
                    }
                    $response = ['success' => true, 'message' => 'Key details saved.'];
                    break;
                }

                if ($action === 'keys_confirm') {
                    if (!$value && ($err = rms_stage_guard($state, 4, true))) { $response = ['success' => false, 'message' => $err]; break; }
                    if ($value) {
                        if ($err = rms_stage_guard($state, 4)) { $response = ['success' => false, 'message' => $err]; break; }
                        $cnt = $conn->prepare("SELECT COALESCE(SUM(quantity), 0) FROM re_move_in_keys WHERE move_in_id = ?");
                        $cnt->execute([$moveInId]);
                        if ((int)$cnt->fetchColumn() <= 0) { $response = ['success' => false, 'message' => 'Enter the quantity of at least one key or card, and save, before confirming the handover.']; break; }
                    }
                    $conn->prepare("UPDATE re_move_ins SET keys_handed_over = ?, keys_handed_over_by = ?, keys_handed_over_at = ? WHERE id = ? AND company_id = ?")
                        ->execute([$value, $value ? $uid : null, $value ? date('Y-m-d H:i:s') : null, $moveInId, $currentCompanyId]);
                    $response = ['success' => true, 'message' => $value ? 'Key handover confirmed.' : 'Key handover set back to not done.'];
                    break;
                }
                break;

            case 'form_send':
                // Allowed after completion too: the tenant may sign and return the form later
                if (!rms_ready($conn) || (int)($moveIn['workflow_version'] ?? 1) < 2) {
                    $response = ['success' => false, 'message' => 'This move-in does not use the stage workflow.'];
                    break;
                }
                if ($moveIn['status'] === 'cancelled') {
                    $response = ['success' => false, 'message' => 'This move-in is cancelled.'];
                    break;
                }
                $sendState = rms_stage_state($conn, (int)$currentCompanyId, $moveIn, rms_items($conn, $moveInId));
                if (!$sendState['form_open']) {
                    $response = ['success' => false, 'message' => $sendState['key_mode'] === 'move_in'
                        ? 'Confirm the key handover (step 4) first. The form lists the keys handed over.'
                        : 'Finish the inspection and list the keys (step 4) first. The form lists the keys to be handed over.'];
                    break;
                }
                $to = trim((string)($_POST['to'] ?? ''));
                if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                    $response = ['success' => false, 'message' => 'Enter a valid email address.'];
                    break;
                }
                $settings = $conn->query("SELECT * FROM app_email_settings WHERE id = 1 AND is_enabled = 1")->fetch(PDO::FETCH_ASSOC);
                if (!$settings || empty($settings['smtp_host'])) {
                    $response = ['success' => false, 'message' => 'Email is not set up. Configure SMTP in Email Settings first.'];
                    break;
                }
                $quotaFile = __DIR__ . '/../../includes/email_hourly_quota.php';
                $quotaKey = null;
                if (is_file($quotaFile)) {
                    require_once $quotaFile;
                    $quotaKey = email_quota_account_key($settings);
                    if (email_quota_remaining($conn, $quotaKey) <= 0) {
                        $response = ['success' => false, 'message' => 'The hourly email limit is reached. Try again after the top of the hour, or download the form and send it yourself.'];
                        break;
                    }
                }

                $formData = rmf_load($conn, (int)$currentCompanyId, $moveInId);
                $pdfName = rmf_file_name($formData);
                // Signed copy on file (digital signature or uploaded scan): send that one
                $signedRow = null;
                if (($_POST['send_what'] ?? 'signed') !== 'unsigned') {
                    $sr = $conn->prepare("SELECT photo_path, photo_description FROM re_move_in_photos WHERE move_in_id = ? AND company_id = ? AND file_type = 'inspection_report' ORDER BY uploaded_at DESC, id DESC LIMIT 1");
                    $sr->execute([$moveInId, $currentCompanyId]);
                    $signedRow = $sr->fetch(PDO::FETCH_ASSOC) ?: null;
                    if ($signedRow && !is_file(__DIR__ . '/../../' . $signedRow['photo_path'])) {
                        $response = ['success' => false, 'message' => 'The signed copy file is missing on the server. Sign or upload it again, or send the unsigned form.'];
                        break;
                    }
                }
                $tempPdf = null;
                if ($signedRow) {
                    $pdfPath = realpath(__DIR__ . '/../../' . $signedRow['photo_path']);
                    $pdfName = 'Signed_' . preg_replace('/\.pdf$/i', '', $pdfName) . '.' . strtolower(pathinfo($pdfPath, PATHINFO_EXTENSION) ?: 'pdf');
                } else {
                    $pdfPath = $tempPdf = rmf_temp_dir() . '/send_' . bin2hex(random_bytes(6)) . '.pdf';
                    file_put_contents($pdfPath, rmf_pdf($conn, $formData, !empty($_POST['include_photos']), [
                        'meters' => !empty($_POST['include_meters']),
                        'services' => !empty($_POST['include_services']),
                        'item_remarks' => !empty($_POST['include_item_remarks']),
                        'insp_remarks' => !empty($_POST['include_insp_remarks']),
                    ]));
                }

                require_once __DIR__ . '/../../includes/mailer.php';
                $tenantName = trim($moveIn['first_name'] . ' ' . $moveIn['last_name']);
                $companyName = (string)($formData['move_in']['company_name'] ?? '');
                $subject = ($signedRow ? 'Signed ' : '') . 'Move-In Inspection Report - ' . $moveIn['building_name'] . ' ' . $moveIn['unit_number'] . ' (' . $moveIn['lease_number'] . ')';
                $unitLine = '<strong>Unit ' . h($moveIn['unit_number']) . ', ' . h($moveIn['building_name']) . '</strong> (move-in date ' . date('d M Y', strtotime($moveIn['move_in_date'])) . ')';
                $body = '<p>Dear ' . h($tenantName) . ',</p>'
                    . ($signedRow
                        ? '<p>Thank you for signing. Please find attached your copy of the <strong>signed</strong> move-in inspection report for ' . $unitLine . '. Please keep it for your records.</p>'
                        : '<p>Please find attached the move-in inspection report for ' . $unitLine . '. It records the condition of the unit at move-in and the keys and access cards listed in section 2.</p>'
                            . '<p>' . h(rmf_key_email_line(rmf_key_handover($formData['move_in']))) . '</p>'
                            . '<p>Please review it, sign the declaration in section 4 and send the signed copy back to us by replying to this email.</p>')
                    . '<p>Best regards,<br>' . h($companyName) . '</p>';
                $sent = send_smtp_mail_with_attachments($settings, $to, $subject, $body, [['path' => $pdfPath, 'name' => $pdfName]]);
                if ($tempPdf) {
                    @unlink($tempPdf); // never the stored signed copy
                }

                if ($quotaKey && $sent['ok']) {
                    email_quota_record_send($conn, $quotaKey);
                } elseif ($quotaKey && email_quota_is_limit_error((string)$sent['error'])) {
                    email_quota_mark_exhausted($conn, $quotaKey);
                }
                try {
                    $conn->prepare("INSERT INTO re_email_logs (company_id, notification_type, recipient_email, subject, status, error_message, related_id, related_type, sent_at) VALUES (?, ?, ?, ?, ?, ?, ?, 're_move_ins', ?)")
                        ->execute([$currentCompanyId, $signedRow ? 'move_in_inspection_form_signed' : 'move_in_inspection_form', $to, $subject, $sent['ok'] ? 'sent' : 'failed', $sent['ok'] ? null : $sent['error'], $moveInId, $sent['ok'] ? date('Y-m-d H:i:s') : null]);
                } catch (Throwable $e) {
                    error_log('Move-in form email log failed: ' . $e->getMessage());
                }
                if (!$sent['ok']) {
                    $response = ['success' => false, 'message' => 'The email could not be sent: ' . $sent['error']];
                    break;
                }
                if (rms_form_ready($conn)) {
                    $conn->prepare("UPDATE re_move_ins SET form_sent_at = NOW(), form_sent_to = ?, form_sent_by = ? WHERE id = ? AND company_id = ?")
                        ->execute([mb_substr($to, 0, 255), $currentUserId, $moveInId, $currentCompanyId]);
                }
                re_lease_lifecycle_audit_safe($moveIn, 'move_in_form_sent', ($signedRow ? 'Signed move-in inspection form' : 'Move-in inspection form (unsigned)') . ' emailed to ' . $to);
                $response = ['success' => true, 'message' => ($signedRow ? 'Signed inspection form' : 'Inspection form') . ' sent to ' . $to . '.'];
                break;

            case 'void_move_in':
                if (!rmv_can_manage($conn)) {
                    $response = ['success' => false, 'message' => 'Only Owner or Admin can cancel a move-in.'];
                    break;
                }
                $vr = rmv_void_move_in($conn, (int)$currentCompanyId, $moveInId, $currentUserId ? (int)$currentUserId : null, (string)($_POST['reason'] ?? ''));
                $response = $vr['ok']
                    ? ['success' => true, 'message' => $moveIn['status'] === 'completed' ? 'Move-in voided.' : 'Move-in cancelled.']
                    : ['success' => false, 'message' => $vr['error']];
                break;

            case 'complete_move_in':
                // Stage workflow: all 4 stages must be done (the old checklist does not apply)
                $isStageFlow = rms_ready($conn) && (int)($moveIn['workflow_version'] ?? 1) >= 2;
                if ($isStageFlow) {
                    $state = rms_stage_state($conn, (int)$currentCompanyId, $moveIn, rms_items($conn, $moveInId));
                    if (!$state['ready_to_complete']) {
                        $response = ['success' => false, 'message' => 'Complete the first four stages first: Contract Signed, Payment Confirmed, Inspection and Key Handover.'];
                        break;
                    }
                    rms_sync_flags($conn, $moveInId, (int)$currentCompanyId, $currentUserId ? (int)$currentUserId : null);
                }
                // Check if all required items are completed
                $requiredCheck = $conn->prepare("
                    SELECT COUNT(*) as incomplete
                    FROM re_move_in_checklist_items
                    WHERE move_in_id = ? AND is_required = 1 AND is_completed = 0
                ");
                $requiredCheck->execute([$moveInId]);
                $incomplete = $requiredCheck->fetchColumn();
                
                if ($incomplete > 0 && !$isStageFlow) {
                    $response = ['success' => false, 'message' => 'Please complete all required checklist items before finalizing move-in.'];
                } else {
                    // Only an open move-in, on an active lease that is not deleted and
                    // has not been moved out of
                    $leaseChk = $conn->prepare("
                        SELECT l.status, " . (re_lease_column_exists($conn, 'deleted_at') ? 'l.deleted_at' : 'NULL') . " AS deleted_at,
                               EXISTS(SELECT 1 FROM re_move_outs mo WHERE mo.lease_id = l.id AND mo.status = 'completed') AS moved_out
                        FROM re_leases l WHERE l.id = ? AND l.company_id = ?
                    ");
                    $leaseChk->execute([(int)$moveIn['lease_id'], $currentCompanyId]);
                    $lc = $leaseChk->fetch(PDO::FETCH_ASSOC) ?: [];
                    if (!$lc || !empty($lc['deleted_at'])) { $response = ['success' => false, 'message' => 'The lease of this move-in was deleted.']; break; }
                    if ($lc['status'] !== 'active') { $response = ['success' => false, 'message' => 'The lease is ' . $lc['status'] . '. Only an active lease can be moved in.']; break; }
                    if ((int)$lc['moved_out'] === 1) { $response = ['success' => false, 'message' => 'The tenant has already moved out of this lease.']; break; }

                    $conn->beginTransaction();

                    // Update move-in status (once: a second click or a cancelled move-in changes nothing)
                    $stmt = $conn->prepare("
                        UPDATE re_move_ins
                        SET status = 'completed',
                            approved_by = ?,
                            approved_at = NOW()
                        WHERE id = ? AND company_id = ? AND status IN ('pending', 'in_progress')
                    ");
                    $stmt->execute([$currentUserId, $moveInId, $currentCompanyId]);
                    if ($stmt->rowCount() === 0) {
                        $conn->rollBack();
                        $response = ['success' => false, 'message' => 'This move-in is already ' . $moveIn['status'] . '.'];
                        break;
                    }
                    
                    // Update lease
                    $conn->prepare("
                        UPDATE re_leases
                        SET move_in_completed = 1
                        WHERE id = ?
                    ")->execute([$moveIn['lease_id']]);
                    
                    // Every unit of the lease (incl. multi-unit) is occupied
                    re_lease_units_set_occupied($conn, (int)$currentCompanyId, (int)$moveIn['lease_id']);
                    
                    $conn->commit();
                    $response = ['success' => true, 'message' => 'Move-in completed successfully!', 'redirect' => 'move_in.php'];
                }
                break;
        }
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        $response = ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
    }
    
    echo json_encode($response);
    exit;
}

// Get checklist items
$checklistItems = $conn->prepare("
    SELECT * FROM re_move_in_checklist_items
    WHERE move_in_id = ?
    ORDER BY display_order, id
");
$checklistItems->execute([$moveInId]);
$checklistItems = $checklistItems->fetchAll(PDO::FETCH_ASSOC);

// Get meter readings
$meterReadings = $conn->prepare("
    SELECT * FROM re_meter_readings
    WHERE lease_id = ? AND reading_type = 'move_in'
    ORDER BY meter_type, reading_date DESC
");
$meterReadings->execute([$moveIn['lease_id']]);
$meterReadings = $meterReadings->fetchAll(PDO::FETCH_ASSOC);

// Get photos
$photos = $conn->prepare("
    SELECT * FROM re_move_in_photos
    WHERE move_in_id = ?
    ORDER BY uploaded_at DESC
");
$photos->execute([$moveInId]);
$photos = $photos->fetchAll(PDO::FETCH_ASSOC);
$inspectionReports = array_values(array_filter($photos, fn($p) => ($p['file_type'] ?? 'photo') === 'inspection_report'));
$moveInPhotos = array_values(array_filter($photos, fn($p) => ($p['file_type'] ?? 'photo') === 'photo'));

// Calculate progress
$totalItems = count($checklistItems);
$completedItems = count(array_filter($checklistItems, fn($item) => $item['is_completed']));
$progressPercent = $totalItems > 0 ? round(($completedItems / $totalItems) * 100) : 0;

// Stage workflow (Contract -> Payment -> Inspection -> Keys)
$isStageFlow = rms_ready($conn) && (int)($moveIn['workflow_version'] ?? 1) >= 2;
if ($isStageFlow) {
    $rmsItems = rms_items($conn, $moveInId);
    $rmsKeys = rms_keys($conn, $moveInId);
    $rmsState = rms_stage_state($conn, (int)$currentCompanyId, $moveIn, $rmsItems);
    $rmsLocked = in_array($moveIn['status'], ['completed', 'cancelled'], true);
    $rmsManager = has_role('Owner', $conn) || has_role('Admin', $conn);
    $rmsUsers = [];
    $uids = array_filter([(int)($moveIn['payment_override_by'] ?? 0), (int)($moveIn['form_sent_by'] ?? 0)]);
    if ($uids) {
        $u = $conn->prepare("SELECT id, username FROM user WHERE id IN (" . implode(',', array_map('intval', $uids)) . ")");
        $u->execute();
        foreach ($u->fetchAll(PDO::FETCH_ASSOC) as $r) { $rmsUsers[(int)$r['id']] = $r['username']; }
    }
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Set page title and include layout
$pageTitle = 'Move-In Details';
$pageStyles = '
    .checklist-item { border-left: 4px solid #dee2e6; padding: 15px; margin-bottom: 10px; background: #f8f9fa; }
    .checklist-item.completed { border-left-color: #28a745; background: #d4edda; }
    .checklist-item.required { border-left-color: #ffc107; }
    .verification-badge { font-size: 0.9rem; }
    .move-in-photo img { width: 100%; height: 120px; object-fit: cover; border-radius: 10px; border: 1px solid #dee2e6; }
';
require_once __DIR__ . '/includes/re_move_ui.php';
$pageStyles .= re_move_ui_css() . re_milestones_css() . re_form_card_css();
$pageStyles .= '

    .rms-stage { border: 1px solid var(--rm-border); border-radius: var(--rm-radius); background: var(--rm-surface); box-shadow: var(--shadow-sm); margin-bottom: 1rem; overflow: hidden; }
    .rms-stage-h { display: flex; align-items: center; gap: .85rem; padding: .95rem 1.1rem; border-bottom: 1px solid var(--rm-border); background: linear-gradient(180deg, var(--rm-surface), var(--rm-surface-2)); }
    .rms-stage-num { width: 36px; height: 36px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-weight: 700; flex-shrink: 0; background: var(--rm-surface-2); border: 1px solid var(--rm-border); color: var(--rm-muted); }
    .rms-stage.is-done .rms-stage-num { background: #16a34a; border-color: #16a34a; color: #fff; }
    .rms-stage.is-current { border-color: var(--primary); box-shadow: 0 0 0 1px var(--primary), var(--shadow-sm); }
    .rms-stage.is-current .rms-stage-num { background: var(--primary); border-color: var(--primary); color: #fff; }
    .rms-stage.is-locked .rms-stage-b { opacity: .6; }
    .rms-stage-title { font-weight: 700; font-size: 1rem; line-height: 1.2; }
    .rms-stage-sub { font-size: .8rem; color: var(--rm-muted); }
    .rms-stage-b { padding: 1rem 1.1rem; }
    .rms-done-line { font-size: .85rem; color: #166534; }
    body.dark-mode .rms-done-line { color: #86efac; }
    .rms-lock-line { font-size: .85rem; color: var(--rm-muted); }

    .rms-pay-row { display: flex; align-items: center; gap: .85rem; padding: .7rem .85rem; border: 1px solid var(--rm-border); border-radius: 12px; margin-bottom: .6rem; }
    .rms-pay-row .ic { width: 34px; height: 34px; border-radius: 10px; display: inline-flex; align-items: center; justify-content: center; background: var(--rm-surface-2); color: var(--rm-muted); flex-shrink: 0; }
    .rms-pay-row.ok { background: #f0fdf4; border-color: #bbf7d0; }
    .rms-pay-row.ok .ic { background: #16a34a; color: #fff; }
    body.dark-mode .rms-pay-row.ok { background: rgba(22,163,74,.12); border-color: rgba(22,163,74,.4); }

    .rms-sec { border: 1px solid var(--rm-border); border-radius: 12px; margin-bottom: .65rem; background: var(--rm-surface); }
    .rms-sec > summary { list-style: none; cursor: pointer; display: flex; align-items: center; gap: .6rem; padding: .7rem .9rem; font-weight: 650; }
    .rms-sec > summary::-webkit-details-marker { display: none; }
    .rms-sec > summary .chev { margin-left: auto; transition: transform .15s ease; color: var(--rm-muted); }
    .rms-sec[open] > summary .chev { transform: rotate(90deg); }
    .rms-sec.complete > summary { color: #166534; }
    .rms-sec-body { padding: 0 .9rem .8rem; }
    .rms-item { display: flex; align-items: center; gap: .7rem; padding: .5rem .25rem; border-top: 1px dashed var(--rm-border); }
    .rms-check { width: 28px; height: 28px; border-radius: 8px; border: 2px solid #cbd5e1; background: var(--rm-surface); display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; color: transparent; transition: all .12s ease; padding: 0; }
    .rms-check:hover:not(:disabled) { border-color: #16a34a; }
    .rms-item.checked .rms-check { background: #16a34a; border-color: #16a34a; color: #fff; }
    .rms-check:disabled { cursor: not-allowed; opacity: .6; }
    .rms-item-text { flex: 1; min-width: 0; }
    .rms-item.checked .rms-item-text { color: var(--rm-muted); }
    .rms-item-meta { font-size: .72rem; color: var(--rm-muted); }
    .rms-add { display: flex; gap: .4rem; margin-top: .6rem; }
    .rms-keys input.form-control { min-width: 0; }
    .rms-key-when { margin-bottom: .85rem; }
    .rms-keys-due { display: flex; align-items: center; gap: .75rem; flex-wrap: wrap; padding: .75rem .9rem; margin-bottom: .9rem; border-radius: 12px; border: 1px solid #fde68a; background: #fffbeb; }
    .rms-keys-due > .bi { font-size: 1.3rem; color: #b45309; }
    body.dark-mode .rms-keys-due { background: rgba(245,158,11,.12); border-color: rgba(245,158,11,.4); }
    @media (max-width: 575.98px) { .rms-keys-due .btn { width: 100%; } }
    .rms-key-actions { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; width: 100%; }
    .rms-key-actions[hidden] { display: none; }
    .rms-key-hint { flex: 1 1 100%; font-size: .8rem; color: var(--rm-muted); }
    @media (max-width: 575.98px) { .rms-key-actions .btn { flex: 1 1 100%; } }
    .rms-key-when-t { display: block; font-weight: 650; margin-bottom: .45rem; }
    /* Side column hidden on this milestone: the main column takes the full width */
    @media (min-width: 992px) { [data-rmm-ready] .rmm-cols:has(> .rmm-side:not(.rmm-on)) > .rmm-main { flex: 0 0 100%; max-width: 100%; } }
    .rms-na-chip { width: 28px; height: 28px; border-radius: 8px; flex-shrink: 0; display: inline-flex; align-items: center; justify-content: center; font-size: .62rem; font-weight: 800; letter-spacing: .02em; background: #e2e8f0; color: #475569; border: 2px solid #cbd5e1; }
    body.dark-mode .rms-na-chip { background: #334155; color: #cbd5e1; border-color: #475569; }
    .rms-item.is-na .rms-item-text > div:first-child { text-decoration: line-through; text-decoration-color: rgba(100,116,139,.5); }
    .rms-na-btn { font-size: .72rem; padding: .1rem .45rem; flex-shrink: 0; }
    .rms-insp-finish { display: flex; align-items: center; gap: .8rem; flex-wrap: wrap; margin-top: 1rem; padding: .85rem 1rem; border-radius: 14px; border: 1px dashed var(--rm-border); background: var(--rm-surface-2); }
    .rms-insp-finish > .bi { font-size: 1.35rem; color: var(--rm-muted); }
    .rms-insp-finish.is-done { border-style: solid; border-color: #bbf7d0; background: #f0fdf4; }
    .rms-insp-finish.is-done > .bi { color: #16a34a; }
    body.dark-mode .rms-insp-finish.is-done { background: rgba(22,163,74,.12); border-color: rgba(22,163,74,.4); }
    @media (max-width: 575.98px) { .rms-insp-finish .btn { width: 100%; } }
    .rms-send-what { display: grid; grid-template-columns: 1fr 1fr; gap: .5rem; }
    .rms-send-opt { display: flex; gap: .6rem; align-items: flex-start; padding: .6rem .75rem; border: 1px solid var(--rm-border); border-radius: 12px; cursor: pointer; background: var(--rm-surface); }
    .rms-send-opt:has(input:checked) { border-color: var(--primary); box-shadow: 0 0 0 1px var(--primary); }
    .rms-send-opt input { margin-top: .25rem; }
    .rms-send-opt span { display: flex; flex-direction: column; min-width: 0; }
    .rms-send-opt small { color: var(--rm-muted); overflow-wrap: anywhere; }
    @media (max-width: 575.98px) { .rms-send-what { grid-template-columns: 1fr; } }
    .rms-sec-tools { display: flex; justify-content: flex-end; padding: .1rem 0 .35rem; }
    .rms-remark { margin-top: .2rem; }
    .rms-remark [hidden] { display: none !important; }
    .rms-remark-text { font-size: .8rem; color: var(--rm-text); background: var(--rm-surface-2); border-radius: 8px; padding: .25rem .5rem; display: inline-block; }
    .rms-remark-text .bi { color: var(--rm-muted); }
    .rms-remark-add { font-size: .78rem; text-decoration: none; }
    .rms-remark-form { display: flex; gap: .35rem; margin-top: .3rem; }
    .rms-remark-form[hidden] { display: none; }
    @media (max-width: 575.98px) { .rms-remark-form { flex-wrap: wrap; } .rms-remark-form input { flex: 1 1 100%; } }
    .rms-item-link { font-size: .78rem; text-decoration: none; display: inline-block; margin-top: .15rem; }
    .rms-form-step { padding: .85rem 0; border-top: 1px dashed var(--rm-border); }
    .rms-form-step:first-child { border-top: 0; padding-top: 0; }
    .rms-form-step:last-child { padding-bottom: 0; }
    .rms-form-step-t { font-weight: 650; margin-bottom: .55rem; display: flex; align-items: center; gap: .5rem; }
    .rms-form-step-t span { width: 22px; height: 22px; border-radius: 50%; background: var(--rm-surface-2); border: 1px solid var(--rm-border); display: inline-flex; align-items: center; justify-content: center; font-size: .72rem; color: var(--rm-muted); }
    .rms-form-step .btn { min-height: 38px; }
    @media (max-width: 575.98px) { .rms-form-step .d-flex > .btn { flex: 1 1 100%; } }
    .rms-stage-h > div { flex: 1; min-width: 0; }
    @media (max-width: 575.98px) {
        .rms-add { flex-direction: column; }
        .rms-stage-h { padding: .8rem .9rem; gap: .65rem; }
        .rms-stage-num { width: 30px; height: 30px; font-size: .85rem; }
        .rms-pay-row { flex-wrap: wrap; row-gap: .35rem; }
        .rms-pay-row > .flex-grow-1 { flex: 1 1 calc(100% - 34px - .85rem); min-width: 0; }
        .rms-pay-row > .rm-pill { margin-left: calc(34px + .85rem); }
    }
';
$pageStyles .= '
    .rm-verify-grid { display: grid; gap: .75rem; grid-template-columns: repeat(2, minmax(0, 1fr)); }
    @media (min-width: 1600px) { .rm-verify-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); } }
    @media (max-width: 575.98px) { .rm-verify-grid { grid-template-columns: 1fr; } }
    .rm-verify { display: flex; flex-direction: column; gap: .7rem; padding: .9rem 1rem; border: 1px solid var(--rm-border); border-radius: 14px; background: var(--rm-surface); transition: border-color .15s ease, background .15s ease; }
    .rm-verify:hover { border-color: rgba(13,110,253,.25); }
    .rm-verify.is-done { background: #f0fdf4; border-color: #bbf7d0; }
    body.dark-mode .rm-verify.is-done { background: rgba(22,163,74,.12); border-color: rgba(22,163,74,.4); }
    .rm-verify-top { display: flex; align-items: flex-start; gap: .75rem; }
    .rm-verify-icon { width: 40px; height: 40px; border-radius: 12px; flex-shrink: 0; display: inline-flex; align-items: center; justify-content: center; font-size: 1.15rem; background: var(--rm-surface-2); color: var(--rm-muted); border: 1px solid var(--rm-border); }
    .rm-verify.is-done .rm-verify-icon { background: #16a34a; border-color: #16a34a; color: #fff; }
    .rm-verify-body { flex: 1; min-width: 0; }
    .rm-verify-title { font-weight: 650; line-height: 1.3; word-break: normal; overflow-wrap: normal; }
    .rm-verify-meta { font-size: .8rem; color: var(--rm-muted); margin-top: .15rem; }
    .rm-verify.is-done .rm-verify-meta { color: #166534; }
    body.dark-mode .rm-verify.is-done .rm-verify-meta { color: #86efac; }
    .rm-verify-link { font-size: .74rem; color: var(--rm-muted); margin-top: .3rem; }
    .rm-verify-action { margin-top: auto; padding-top: .6rem; border-top: 1px dashed var(--rm-border); display: flex; justify-content: flex-end; }
    .rm-verify-action .btn { white-space: nowrap; }
    .rm-verify-foot { font-size: .78rem; color: var(--rm-muted); margin-top: .85rem; }
    .rm-verify-step { font-size: .68rem; text-transform: uppercase; letter-spacing: .06em; color: var(--rm-muted); font-weight: 600; margin-bottom: .1rem; }
    .rm-verify.is-next { border-color: var(--primary); box-shadow: 0 0 0 1px var(--primary) inset; }
    .rm-verify.is-next .rm-verify-step { color: var(--primary); }
    .rm-verify.is-next .rm-verify-icon { background: rgba(13,110,253,.08); color: var(--primary); border-color: rgba(13,110,253,.25); }
    .rm-verify.is-locked { background: var(--rm-surface-2); }
    .rm-verify.is-locked .rm-verify-title { color: var(--rm-muted); }
    .rm-verify.is-locked .rm-verify-icon { opacity: .7; }
    .rm-verify-hint { font-size: .8rem; color: #166534; font-weight: 600; }
    body.dark-mode .rm-verify-hint { color: #86efac; }
    .rm-page .checklist-item .form-check-input:disabled ~ .form-check-label { opacity: .85; }
    @media (max-width: 575.98px) { .rm-verify-action .btn { width: 100%; } }
';
require_once __DIR__ . '/includes/re_layout_header.php';
?>
<div class="rm-page">
        <!-- Header -->
        <a href="move_in.php" class="rm-back"><i class="bi bi-arrow-left"></i> Move-In Management</a>
        <div class="rm-hero">
            <div>
                <h1><i class="bi bi-box-arrow-in-right"></i>Move-In #<?= $moveInId ?></h1>
                <div class="rm-meta">
                    <?= re_move_ui_pill(ucfirst(str_replace('_', ' ', $moveIn['status'])), re_move_ui_tone($moveIn['status'])) ?>
                    <?php if (!empty($moveIn['is_historical'])): ?>
                        <span class="rm-pill rm-pill-secondary" title="Recorded with Mark as Moved In — no checklist, inspection or meter readings">Historical entry: no checklist</span>
                    <?php endif; ?>
                    <span><i class="bi bi-calendar-event"></i> <?= date('M d, Y', strtotime($moveIn['move_in_date'])) ?></span>
                    <span><i class="bi bi-building"></i> <?= h($moveIn['building_name']) ?> · <?= h($moveIn['unit_number']) ?></span>
                </div>
            </div>
            <div class="rm-actions">
                <a href="lease_view.php?id=<?= $moveIn['lease_id'] ?>" class="btn btn-outline-primary">
                    <i class="bi bi-file-text me-1"></i> View Lease
                </a>
                <?php
                $voidCheck = ($moveIn['status'] !== 'cancelled' && rmv_can_manage($conn)) ? rmv_move_in_check($conn, (int)$currentCompanyId, $moveIn) : null;
                $voidLabel = $moveIn['status'] === 'completed' ? 'Void Move-In' : 'Cancel Move-In';
                if ($voidCheck): ?>
                    <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#voidMoveInModal">
                        <i class="bi bi-x-octagon me-1"></i> <?= $voidLabel ?>
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($moveIn['status'] === 'cancelled'):
            $cby = '';
            if (!empty($moveIn['cancelled_by'])) {
                $cu = $conn->prepare("SELECT username FROM user WHERE id = ?");
                $cu->execute([(int)$moveIn['cancelled_by']]);
                $cby = (string)$cu->fetchColumn();
            } ?>
            <div class="alert alert-danger d-flex gap-2 align-items-start">
                <i class="bi bi-x-octagon-fill fs-5"></i>
                <div><strong><?= ($moveIn['cancelled_from_status'] ?? '') === 'completed' ? 'This completed move-in was voided' : 'This move-in was cancelled' ?></strong>
                    <?= !empty($moveIn['cancelled_at']) ? ' on ' . date('M d, Y H:i', strtotime($moveIn['cancelled_at'])) : '' ?><?= $cby !== '' ? ' by ' . h($cby) : '' ?>.
                    <?php if (!empty($moveIn['cancel_reason'])): ?><div class="small">Reason: <?= h($moveIn['cancel_reason']) ?></div><?php endif; ?>
                    <div class="small">Kept for the record. Start a new move-in (or Mark as Moved In) from the lease.</div>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($voidCheck)): ?>
        <div class="modal fade" id="voidMoveInModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <form class="modal-content" onsubmit="event.preventDefault(); rmsVoid(this, 'void_move_in');">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-x-octagon text-danger"></i> <?= $voidLabel ?> #<?= $moveInId ?></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <?php if ($voidCheck['blockers']): ?>
                            <div class="alert alert-warning small mb-0"><b>It cannot be cancelled yet:</b>
                                <ul class="mb-0 ps-3"><?php foreach ($voidCheck['blockers'] as $b): ?><li><?= h($b) ?></li><?php endforeach; ?></ul></div>
                        <?php else: ?>
                            <p class="small mb-2">Use this when the move-in was made by mistake. It will:</p>
                            <ul class="small ps-3"><?php foreach ($voidCheck['effects'] as $ef): ?><li><?= h($ef) ?></li><?php endforeach; ?></ul>
                            <label class="form-label" for="voidMiReason">Reason <span class="text-danger">*</span></label>
                            <textarea id="voidMiReason" name="reason" class="form-control" rows="2" maxlength="500" required placeholder="e.g. Created for the wrong lease"></textarea>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <?php if (!$voidCheck['blockers']): ?>
                            <button type="submit" class="btn btn-danger"><i class="bi bi-x-octagon"></i> <?= $voidLabel ?></button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($isStageFlow):
            // Milestones: Started -> stages 1-5 -> Completed. Sections carry data-ms="<index>".
            $msDone = $moveIn['status'] === 'completed';
            $msState = static function (int $n) use ($rmsState, $msDone) {
                if ($rmsState['done'][$n] || ($n === 4 && $rmsState['keys_ready'])) return 'done';
                if ($n === 5 && $msDone) return 'warn';           // completed without the signed form
                if ($n === 5 && $rmsState['form_open']) return 'current';
                return $rmsState['current'] === $n && !$msDone ? 'current' : 'todo';
            };
            $msItems = [
                ['label' => 'Process Initiated', 'icon' => 'bi-flag', 'state' => 'done', 'sub' => date('M d', strtotime($moveIn['created_at']))],
                ['label' => 'Contract Signed', 'icon' => 'bi-file-earmark-check', 'state' => $msState(1), 'sub' => ''],
                ['label' => 'Payment', 'icon' => 'bi-cash-coin', 'state' => $msState(2), 'sub' => ''],
                ['label' => 'Inspection', 'icon' => 'bi-clipboard-check', 'state' => $msState(3), 'sub' => $rmsState['items_checked'] . '/' . $rmsState['items_total']],
                ['label' => 'Key Handover', 'icon' => 'bi-key', 'state' => $msState(4), 'sub' => $rmsState['done'][4] ? 'Handed over' : ($rmsState['key_mode'] === 'scheduled' && !empty($moveIn['key_handover_scheduled_at']) ? 'Due ' . date('M d', strtotime($moveIn['key_handover_scheduled_at'])) : ($rmsState['key_mode'] === 'on_signing' ? 'On signing' : ''))],
                ['label' => 'Inspection Report', 'icon' => 'bi-pen', 'state' => $msState(5), 'optional' => true, 'sub' => $rmsState['done'][5] ? 'Signed' : (!empty($moveIn['form_sent_at']) ? 'Sent' : '')],
                ['label' => 'Completed', 'icon' => 'bi-house-check', 'state' => $msDone ? 'done' : (($rmsState['ready_to_complete'] || $rmsState['current'] === 6) ? 'current' : 'todo'), 'sub' => $msDone && !empty($moveIn['approved_at']) ? date('M d', strtotime($moveIn['approved_at'])) : ''],
            ];
            if ($moveIn['status'] === 'cancelled') {
                $msSelected = 0;
            } elseif ($msDone) {
                $msSelected = 6;
            } else {
                $msSelected = $rmsState['current'] <= 4 ? $rmsState['current'] : ($rmsState['done'][5] ? 6 : 5);
            }
            $msHeads = [
                0 => ['bi-flag', 'Process Initiated', 'Lease and tenant details for this move-in.'],
                1 => ['bi-file-earmark-check', 'Contract Signed', 'Confirm the tenancy contract is signed by both parties.'],
                2 => ['bi-cash-coin', 'Payment Confirmed', 'Deposit and first installment, read from the lease payment schedule.'],
                3 => ['bi-clipboard-check', 'Inspection', 'Remarks, checklist, meter readings and photos of the unit.'],
                4 => ['bi-key', 'Key Handover', 'Keys and access cards, and when they are handed over.'],
                5 => ['bi-pen', 'Inspection Report', 'Generate the report, have the tenant sign it (on screen or on paper) and keep the signed copy.'],
                6 => ['bi-house-check', 'Completed', 'Finish the move-in: the unit becomes occupied.'],
            ];
            echo re_milestones_html($msItems, $msSelected, 'Move-in milestones');
            // Next only once the step is finished (the signed form is optional)
            $msReach = re_milestones_reach($msItems);
            $msHints = [
                1 => 'Mark the contract signed, or upload the Tenancy Contract, to continue',
                2 => 'The deposit and first installment must be received to continue',
                3 => 'Check (or mark N/A) every item, then Complete inspection, to continue',
                4 => $rmsState['key_mode'] === 'move_in' ? 'Hand over the keys & access to continue' : 'Save the keys to be handed over to continue',
            ];
            foreach ($msHeads as $i => [$icon, $title, $text]) {
                echo re_milestone_head($i, count($msHeads), $icon, $title, $text, $msItems[$i]['state'], $msReach, $msHints[$i] ?? 'Finish this step to continue');
            }
        ?>
        <?php else: ?>
        <!-- Progress Bar (first .progress-bar on the page: updated live by updateProgressBar()) -->
        <div class="card mb-4">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <p class="rm-section-title mb-0">Move-in progress</p>
                    <span class="rm-cell-sub"><?= $completedItems ?>/<?= $totalItems ?> checklist items</span>
                </div>
                <div class="progress rm-progress-main" style="height: 22px;">
                    <div class="progress-bar <?= $progressPercent == 100 ? 'bg-success' : ($progressPercent >= 50 ? 'bg-info' : 'bg-warning') ?>" 
                         role="progressbar" style="width: <?= $progressPercent ?>%">
                        <?= $progressPercent ?>% Complete (<?= $completedItems ?>/<?= $totalItems ?> items)
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="row g-4 rmm-cols">
            <!-- Left Column: Checklist & Details -->
            <div class="col-lg-8 rmm-main">
                <!-- Lease Information -->
                <div class="card mb-4" <?= $isStageFlow ? 'data-ms="0"' : '' ?>>
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-file-text"></i> Lease Information</h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <dl class="rm-dl">
                                    <dt>Lease Number</dt><dd><?= h($moveIn['lease_number']) ?></dd>
                                    <dt>Building</dt><dd><?= h($moveIn['building_name']) ?></dd>
                                    <dt>Unit</dt><dd><?= h($moveIn['unit_number']) ?> <span class="rm-cell-sub">(<?= h($moveIn['unit_type']) ?>)</span></dd>
                                    <dt>Annual Rent</dt><dd><?= number_format($moveIn['annual_rent'], 2) ?> AED</dd>
                                    <dt>Installments</dt><dd><?= h($moveIn['number_of_installments'] ?? '-') ?></dd>
                                    <dt>First Installment</dt><dd><?= $firstInstallment ? number_format($firstInstallment, 2) . ' AED' : '-' ?></dd>
                                </dl>
                            </div>
                            <div class="col-md-6">
                                <dl class="rm-dl">
                                    <dt>Tenant</dt><dd><?= h($moveIn['first_name'] . ' ' . $moveIn['last_name']) ?></dd>
                                    <dt>Phone</dt><dd><?= h($moveIn['phone']) ?></dd>
                                    <dt>Email</dt><dd><?= h($moveIn['email']) ?></dd>
                                    <dt>Security Deposit</dt><dd><?= number_format($moveIn['security_deposit'], 2) ?> AED</dd>
                                </dl>
                            </div>
                        </div>
                    </div>
                </div>

                <?php
                // Remarks card: under the Inspection stage in the stage workflow, after the checklist otherwise
                $renderRemarks = static function () use ($moveIn, $isStageFlow) { ?>
                <!-- Remarks -->
                <div class="card mb-4" <?= $isStageFlow ? 'data-ms="3"' : '' ?>>
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-chat-left-text"></i> Remarks</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label for="tenant_comments" class="form-label"><strong>Tenant Comments &amp; Requests</strong></label>
                            <textarea class="form-control" id="tenant_comments" rows="3"
                                      placeholder="Any comments or requests from the tenant..."
                                      onblur="saveRemark('tenant_comments', this)"><?= h($moveIn['tenant_comments'] ?? '') ?></textarea>
                        </div>
                        <div>
                            <label for="additional_services" class="form-label"><strong>Additional Services &amp; Details</strong></label>
                            <textarea class="form-control" id="additional_services" rows="3"
                                      placeholder="Any additional services provided or requested, with details..."
                                      onblur="saveRemark('additional_services', this)"><?= h($moveIn['additional_services'] ?? '') ?></textarea>
                        </div>
                        <div class="form-text">Saved automatically when you click outside the box.</div>
                    </div>
                </div>
                <?php }; ?>

                <?php if ($isStageFlow):
                    $st = $rmsState;
                    $stageCls = static fn(int $n) => ($st['done'][$n] || ($n === 4 && $st['keys_ready'])) ? 'is-done'
                        : (($st['current'] === $n || ($n === 5 && $st['form_open'])) ? 'is-current' : 'is-locked');
                    $stageNum = static fn(int $n) => $st['done'][$n] ? '<i class="bi bi-check-lg"></i>' : (($n === 4 && $st['keys_ready']) ? '<i class="bi bi-clock"></i>' : $n);
                    $canWork = static fn(int $n) => !$rmsLocked && $st['current'] === $n;
                    $pay = $st['payment'];
                    $money = static fn($v) => number_format((float)$v, 2) . ' AED';
                ?>
                <!-- Stage 1: Contract Signed -->
                <div class="rms-stage <?= $stageCls(1) ?>" data-ms="1">
                    <div class="rms-stage-h">
                        <span class="rms-stage-num"><?= $stageNum(1) ?></span>
                        <div><div class="rms-stage-title">Contract Signed</div><div class="rms-stage-sub">Tenancy contract signed by both parties</div></div>
                        <span class="ms-auto"><?= re_move_ui_pill($st['done'][1] ? 'Done' : ($st['current'] === 1 ? 'To do' : 'Waiting'), $st['done'][1] ? 'success' : ($st['current'] === 1 ? 'primary' : 'secondary')) ?></span>
                    </div>
                    <div class="rms-stage-b d-flex flex-wrap align-items-center gap-2">
                        <?php if ($st['done'][1] && empty($moveIn['contract_verified']) && $st['contract_doc']): $cd = $st['contract_doc']; ?>
                            <span class="rms-done-line"><i class="bi bi-check-circle-fill"></i> Confirmed automatically: Tenancy Contract uploaded to the lease
                                · <a href="../../<?= h($cd['file_path']) ?>" target="_blank" rel="noopener"><?= h($cd['file_name']) ?></a>
                                · <?= date('M d, Y', strtotime($cd['created_at'])) ?><?= !empty($cd['uploaded_by_name']) ? ' by ' . h($cd['uploaded_by_name']) : '' ?></span>
                            <span class="rm-cell-sub ms-auto" title="Remove the document from the lease to undo">From lease documents</span>
                        <?php elseif ($st['done'][1]): ?>
                            <span class="rms-done-line"><i class="bi bi-check-circle-fill"></i> Confirmed<?= !empty($moveIn['contract_verified_by_name']) ? ' by ' . h($moveIn['contract_verified_by_name']) : '' ?><?= !empty($moveIn['contract_verified_at']) ? ' · ' . date('M d, Y H:i', strtotime($moveIn['contract_verified_at'])) : '' ?></span>
                            <?php if (!$rmsLocked && !rms_stage_guard($st, 1, true)): ?>
                                <button type="button" class="btn btn-sm btn-link text-muted text-decoration-none ms-auto" onclick="rmsPost('stage_contract', {value: 0}, this)"><i class="bi bi-arrow-counterclockwise"></i> Undo</button>
                            <?php endif; ?>
                        <?php elseif ($canWork(1)): ?>
                            <span class="rms-lock-line">Confirm once the contract is signed by the tenant and the landlord. Uploading the signed <b>Tenancy Contract</b> in the lease documents confirms this automatically.
                                <a href="lease_view.php?id=<?= (int)$moveIn['lease_id'] ?>#lease-documents" class="text-decoration-none">Lease documents</a></span>
                            <button type="button" class="btn btn-success ms-auto" onclick="rmsPost('stage_contract', {value: 1}, this)"><i class="bi bi-check2"></i> Mark contract signed</button>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Stage 2: Payment Confirmed -->
                <div class="rms-stage <?= $stageCls(2) ?>" data-ms="2">
                    <div class="rms-stage-h">
                        <span class="rms-stage-num"><?= $stageNum(2) ?></span>
                        <div><div class="rms-stage-title">Payment Confirmed</div><div class="rms-stage-sub">Read automatically from the lease payments</div></div>
                        <span class="ms-auto"><?= re_move_ui_pill($st['done'][2] ? 'Done' : ($st['current'] === 2 ? 'To do' : 'Waiting'), $st['done'][2] ? 'success' : ($st['current'] === 2 ? 'primary' : 'secondary')) ?></span>
                    </div>
                    <div class="rms-stage-b">
                        <div class="rms-pay-row <?= $pay['deposit']['done'] ? 'ok' : '' ?>">
                            <span class="ic"><i class="bi <?= $pay['deposit']['done'] ? 'bi-check-lg' : 'bi-shield-lock' ?>"></i></span>
                            <div class="flex-grow-1">
                                <div class="fw-semibold">Deposit received</div>
                                <div class="rm-cell-sub">
                                    <?php if (!$pay['deposit']['required']): ?>No security deposit on this lease
                                    <?php else: ?>Received <?= $money($pay['deposit']['received']) ?> of <?= $money($pay['deposit']['expected']) ?><?php endif; ?>
                                </div>
                            </div>
                            <?= re_move_ui_pill($pay['deposit']['done'] ? ($pay['deposit']['required'] ? 'Received' : 'Not required') : 'Not received', $pay['deposit']['done'] ? 'success' : 'warning') ?>
                        </div>
                        <div class="rms-pay-row <?= $pay['first']['done'] ? 'ok' : '' ?>">
                            <span class="ic"><i class="bi <?= $pay['first']['done'] ? 'bi-check-lg' : 'bi-cash-coin' ?>"></i></span>
                            <div class="flex-grow-1">
                                <div class="fw-semibold">First installment</div>
                                <div class="rm-cell-sub">
                                    <?php if (!$pay['first']['found']): ?>No rent schedule found on this lease
                                    <?php else: ?>Due <?= date('M d, Y', strtotime($pay['first']['due'])) ?> · received <?= $money($pay['first']['received']) ?> of <?= $money($pay['first']['amount']) ?><?= $pay['first']['source'] === 'schedule' ? ' · first row of the payment schedule' : ' · first rent invoice' ?><?php endif; ?>
                                </div>
                            </div>
                            <?= re_move_ui_pill($pay['first']['done'] ? 'Received' : 'Not received', $pay['first']['done'] ? 'success' : 'warning') ?>
                        </div>
                        <?php if (!empty($moveIn['payment_override']) && !$pay['deposit']['done']): $depShort = max(0, $pay['deposit']['expected'] - $pay['deposit']['received']); ?>
                            <div class="alert alert-danger small mb-2 d-flex gap-2 align-items-start">
                                <i class="bi bi-exclamation-octagon-fill fs-5"></i>
                                <div><strong>Security deposit outstanding: <?= $money($depShort) ?>.</strong> Payment was confirmed manually, so the keys can be released without the deposit.
                                    Collect it and allocate the receipt to the Security Deposit; until then this tenancy is listed in the
                                    <a href="reports_deposit_outstanding.php?override=1" target="_blank">Security Deposit Outstanding</a> report.</div>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($moveIn['payment_override'])): ?>
                            <div class="alert alert-info small mb-2">
                                <i class="bi bi-person-check"></i> Confirmed manually<?= isset($rmsUsers[(int)$moveIn['payment_override_by']]) ? ' by ' . h($rmsUsers[(int)$moveIn['payment_override_by']]) : '' ?><?= !empty($moveIn['payment_override_at']) ? ' · ' . date('M d, Y H:i', strtotime($moveIn['payment_override_at'])) : '' ?>.
                                Reason: <?= h($moveIn['payment_override_reason']) ?>
                                <?php if ($rmsManager && !$rmsLocked && ($pay['auto_done'] || !rms_stage_guard($st, 2, true))): ?>
                                    <button type="button" class="btn btn-sm btn-link p-0 ms-1" onclick="rmsPost('stage_payment_override', {value: 0}, this)">Remove</button>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <?php if ($pay['auto_done']): ?>
                                <span class="rms-done-line"><i class="bi bi-check-circle-fill"></i> Both payments are recorded on the lease.</span>
                            <?php elseif (empty($moveIn['payment_override'])): ?>
                                <?php if ($rmsManager && !$rmsLocked && !rms_stage_guard($st, 2)): ?>
                                    <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="collapse" data-bs-target="#rmsOverride"><i class="bi bi-person-check"></i> Confirm manually</button>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                        <?php if ($rmsManager && !$pay['auto_done'] && empty($moveIn['payment_override']) && !$rmsLocked): ?>
                            <form class="collapse mt-2" id="rmsOverride" onsubmit="event.preventDefault(); rmsPost('stage_payment_override', {value: 1, reason: this.reason.value, ack_deposit: this.ack_deposit && this.ack_deposit.checked ? 1 : 0}, this.querySelector('button'));">
                                <?php if (!$pay['deposit']['done']): ?>
                                    <div class="alert alert-warning small py-2 mb-2">
                                        <i class="bi bi-exclamation-triangle-fill"></i> <strong>The security deposit (<?= $money(max(0, $pay['deposit']['expected'] - $pay['deposit']['received'])) ?>) is not recorded.</strong>
                                        Once keys are handed over, the deposit is much harder to collect, and nothing would cover damages at move-out.
                                        <div class="form-check mt-1">
                                            <input class="form-check-input" type="checkbox" name="ack_deposit" value="1" id="rmsAckDeposit" required>
                                            <label class="form-check-label" for="rmsAckDeposit">I confirm the keys may be released before the deposit is received.</label>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                <div class="input-group">
                                    <input type="text" name="reason" class="form-control" maxlength="500" required placeholder="Reason, e.g. Paid by cheque, receipt not entered yet">
                                    <button class="btn btn-primary" type="submit">Confirm payment</button>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Stage 3: Inspection -->
                <div class="rms-stage <?= $stageCls(3) ?>" data-ms="3">
                    <div class="rms-stage-h">
                        <span class="rms-stage-num"><?= $stageNum(3) ?></span>
                        <div><div class="rms-stage-title">Inspection</div><div class="rms-stage-sub">Check every point physically in the unit · <?= $st['items_checked'] ?> of <?= $st['items_total'] ?> checked</div></div>
                        <span class="ms-auto"><?= re_move_ui_pill($st['done'][3] ? 'Done' : ($st['current'] === 3 ? 'In progress' : 'Waiting'), $st['done'][3] ? 'success' : ($st['current'] === 3 ? 'primary' : 'secondary')) ?></span>
                    </div>
                    <div class="rms-stage-b">
                        <?php
                        // A completed inspection is read-only until Edit inspection reopens it
                        $inspDone = $st['done'][3];
                        $inspEditable = !$rmsLocked && !$st['done'][4] && !$inspDone;
                        $remarkReady = rms_remark_ready($conn);
                        $naReady = rms_na_ready($conn);
                        $inspTickable = !$rmsLocked && !rms_stage_guard($st, 3) && !$st['done'][4] && !$inspDone;
                        $inspRemarkEditable = !$rmsLocked && !$inspDone;
                        if (!$inspTickable && !$st['done'][3] && !$rmsLocked && $st['current'] < 3): ?>
                            <p class="rms-lock-line"><i class="bi bi-lock"></i> Complete the earlier stages first. You can still prepare the checklist.</p>
                        <?php endif; ?>
                        <?php $secIx = 0; foreach ($rmsItems as $secName => $sec):
                            $secIx++;
                            // The linked "form signed" item is done in step 5, not counted here
                            $secCounted = array_filter($sec['items'], fn($i) => rms_item_kind((string)$i['item_text']) !== 'form');
                            $secTotal = count($secCounted);
                            $secDone = count(array_filter($secCounted, fn($i) => (int)$i['is_checked'] === 1));
                            $secComplete = $secTotal > 0 && $secDone === $secTotal; ?>
                            <details class="rms-sec <?= $secComplete ? 'complete' : '' ?>" id="rmsSec<?= $secIx ?>" <?= (!$secComplete && $st['current'] === 3) ? 'open' : '' ?>>
                                <summary>
                                    <i class="bi <?= $secComplete ? 'bi-check-circle-fill text-success' : 'bi-clipboard-check' ?>"></i>
                                    <span><?= h($secName) ?></span>
                                    <?= re_move_ui_pill($secDone . '/' . $secTotal, $secComplete ? 'success' : ($secDone ? 'info' : 'secondary')) ?>
                                    <i class="bi bi-chevron-right chev"></i>
                                </summary>
                                <div class="rms-sec-body">
                                    <?php
                                    // Select all / Clear all for the section (the step 5 form item is never included)
                                    $secOpen = array_filter($secCounted, fn($x) => (int)$x['is_checked'] === 0);
                                    if ($inspTickable && $secTotal > 0): ?>
                                        <div class="rms-sec-tools">
                                            <?php if ($secOpen): ?>
                                                <button type="button" class="btn btn-sm btn-outline-success" onclick="rmsPost('insp_check_section', {section_name: <?= h(json_encode($secName)) ?>, value: 1}, this)"><i class="bi bi-check2-all"></i> Select all</button>
                                            <?php else: ?>
                                                <button type="button" class="btn btn-sm btn-link text-muted text-decoration-none" onclick="if (confirm('Clear all items in <?= h(addslashes($secName)) ?>?')) rmsPost('insp_check_section', {section_name: <?= h(json_encode($secName)) ?>, value: 0}, this)"><i class="bi bi-x-square"></i> Clear all</button>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php foreach ($sec['items'] as $it): $on = (int)$it['is_checked'] === 1; $kind = rms_item_kind((string)$it['item_text']);
                                        if ($kind === 'form') continue; // step 5 handles the signed form
                                        $na = $on && !empty($it['is_na']); ?>
                                        <div class="rms-item <?= $na ? 'checked is-na' : ($on ? 'checked' : '') ?>">
                                            <?php if ($na): ?>
                                                <span class="rms-na-chip" title="Not applicable to this unit">N/A</span>
                                            <?php else: ?>
                                            <button type="button" class="rms-check" aria-pressed="<?= $on ? 'true' : 'false' ?>" aria-label="<?= h($it['item_text']) ?>"
                                                <?= $inspTickable && $kind !== 'form' ? '' : 'disabled' ?>
                                                <?= $kind === 'form' ? 'title="Ticked automatically when the signed form is uploaded in step 5"' : '' ?>
                                                onclick="rmsPost('insp_toggle', {item_id: <?= (int)$it['id'] ?>, value: <?= $on ? 0 : 1 ?>}, this)"><i class="bi bi-check-lg"></i></button>
                                            <?php endif; ?>
                                            <div class="rms-item-text">
                                                <div><?= h($it['item_text']) ?><?php if ((int)$it['is_custom'] === 1): ?> <span class="rm-pill rm-pill-secondary">Added</span><?php endif; ?></div>
                                                <?php if ($on): ?><div class="rms-item-meta"><?= $na ? 'Not applicable · ' : '' ?><?= !empty($it['checked_by_name']) ? h($it['checked_by_name']) . ' · ' : '' ?><?= $it['checked_at'] ? date('M d, Y H:i', strtotime($it['checked_at'])) : '' ?><?= $it['note'] ? ' · ' . h($it['note']) : '' ?></div><?php endif; ?>
                                                <?php if ($kind === 'photos'): ?>
                                                    <a class="rms-item-link" href="#rmsPhotos"><i class="bi bi-camera"></i> <?= $st['files']['photo'] ? $st['files']['photo'] . ' photo(s) uploaded · view or add more' : 'Upload photos first' ?></a>
                                                <?php elseif ($kind === 'form'): ?>
                                                    <a class="rms-item-link" href="#rmsStage5"><i class="bi bi-file-earmark-text"></i> <?= $on ? 'Signed form uploaded · step 5' : 'Done in step 5: generate the form, then upload the signed copy' ?></a>
                                                <?php endif; ?>
                                                <?php if ($remarkReady && $kind !== 'form'):
                                                    $rem = (string)($it['remark'] ?? ''); ?>
                                                    <div class="rms-remark" data-item="<?= (int)$it['id'] ?>">
                                                        <?php if ($rem !== ''): ?>
                                                            <div class="rms-remark-text"><i class="bi bi-chat-left-text"></i> <?= h($rem) ?>
                                                                <?php if ($inspRemarkEditable): ?><button type="button" class="btn btn-link btn-sm p-0 ms-1" onclick="rmsRemarkEdit(this)">Edit</button><?php endif; ?>
                                                            </div>
                                                        <?php elseif ($inspRemarkEditable): ?>
                                                            <button type="button" class="btn btn-link btn-sm p-0 rms-remark-add" onclick="rmsRemarkEdit(this)"><i class="bi bi-plus"></i> Remark</button>
                                                        <?php endif; ?>
                                                        <?php if ($inspRemarkEditable): ?>
                                                            <form class="rms-remark-form" hidden onsubmit="event.preventDefault(); rmsPost('insp_item_remark', {item_id: <?= (int)$it['id'] ?>, remark: this.remark.value}, this.querySelector('button'));">
                                                                <input type="text" name="remark" class="form-control form-control-sm" maxlength="500" value="<?= h($rem) ?>" placeholder="Remark (optional), e.g. small scratch near the window">
                                                                <button class="btn btn-sm btn-primary" type="submit">Save</button>
                                                                <button class="btn btn-sm btn-link text-muted" type="button" onclick="rmsRemarkCancel(this)">Cancel</button>
                                                            </form>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <?php if ($naReady && $inspTickable): ?>
                                                <?php if ($na): ?>
                                                    <button type="button" class="btn btn-sm btn-link text-muted text-decoration-none p-0 rms-na-btn" title="Set back to open" onclick="rmsPost('insp_na', {item_id: <?= (int)$it['id'] ?>, value: 0}, this)"><i class="bi bi-arrow-counterclockwise"></i> Undo</button>
                                                <?php elseif (!$on): ?>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary rms-na-btn" title="Not applicable to this unit: counts as done and shows N/A on the form" onclick="rmsPost('insp_na', {item_id: <?= (int)$it['id'] ?>, value: 1}, this)">N/A</button>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            <?php if (!$on && $inspEditable): ?>
                                                <button type="button" class="btn btn-sm btn-link text-muted p-0" title="Remove this item" onclick="if (confirm('Remove this checklist item from this move-in?')) rmsPost('insp_remove_item', {item_id: <?= (int)$it['id'] ?>}, this)"><i class="bi bi-x-lg"></i></button>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php if ($inspEditable): ?>
                                        <form class="rms-add" onsubmit="event.preventDefault(); rmsPost('insp_add_item', {section_name: <?= h(json_encode($secName)) ?>, item_text: this.item_text.value}, this.querySelector('button'));">
                                            <input type="text" name="item_text" class="form-control form-control-sm" maxlength="255" placeholder="Add a checklist item to <?= h($secName) ?>" required>
                                            <button class="btn btn-sm btn-outline-primary text-nowrap" type="submit"><i class="bi bi-plus"></i> Add item</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </details>
                        <?php endforeach; ?>

                        <?php if ($inspEditable): ?>
                            <details class="rms-sec">
                                <summary><i class="bi bi-plus-circle"></i><span>Add a section</span><i class="bi bi-chevron-right chev"></i></summary>
                                <div class="rms-sec-body">
                                    <form class="row g-2" onsubmit="event.preventDefault(); rmsPost('insp_add_section', {section_name: this.section_name.value, item_text: this.item_text.value}, this.querySelector('button'));">
                                        <div class="col-md-4"><input type="text" name="section_name" class="form-control form-control-sm" maxlength="150" placeholder="Section name, e.g. Kitchen" required></div>
                                        <div class="col-md-6"><input type="text" name="item_text" class="form-control form-control-sm" maxlength="255" placeholder="First checklist item" required></div>
                                        <div class="col-md-2"><button class="btn btn-sm btn-primary w-100" type="submit">Add</button></div>
                                    </form>
                                    <div class="form-text">Only for this move-in. To change the default list for all move-ins, use the <a href="move_in_inspection_template.php">Inspection Template</a>.</div>
                                </div>
                            </details>
                        <?php endif; ?>

                        <div class="mt-3">
                            <label class="form-label" for="rmsRemarks">Inspection Remarks</label>
                            <textarea id="rmsRemarks" class="form-control" rows="3" placeholder="Any observations from the inspection..." <?= $inspRemarkEditable ? '' : 'disabled' ?>
                                onblur="rmsSaveRemarks(this)"><?= h($moveIn['inspection_notes'] ?? '') ?></textarea>
                            <?php if ($inspRemarkEditable): ?><div class="form-text">Saved automatically when you click outside the box.</div><?php endif; ?>
                        </div>

                        <?php if (!$rmsLocked): ?>
                        <div class="rms-insp-finish <?= $inspDone ? 'is-done' : '' ?>">
                            <?php if ($inspDone): ?>
                                <i class="bi bi-check-circle-fill"></i>
                                <div class="flex-grow-1">
                                    <b>Inspection completed</b><?= !empty($moveIn['inspection_completed_by_name']) ? ' by ' . h($moveIn['inspection_completed_by_name']) : '' ?><?= !empty($moveIn['inspection_completed_at']) ? ' · ' . date('M d, Y H:i', strtotime($moveIn['inspection_completed_at'])) : '' ?>
                                    <div class="rm-cell-sub">The checklist and remarks are locked.</div>
                                </div>
                                <?php if ($reopenBlock = rms_stage_guard($st, 3, true)): ?>
                                    <span class="rm-cell-sub" title="<?= h($reopenBlock) ?>"><i class="bi bi-lock"></i> <?= $st['done'][5] ? 'Locked: signed form uploaded' : 'Locked: keys handed over' ?></span>
                                <?php else: ?>
                                    <button type="button" class="btn btn-outline-primary" onclick="rmsInspReopen(this)"><i class="bi bi-pencil-square"></i> Edit inspection</button>
                                <?php endif; ?>
                            <?php elseif ($st['insp_all_checked'] && $inspTickable): ?>
                                <i class="bi bi-clipboard-check"></i>
                                <div class="flex-grow-1"><b>All <?= (int)$st['items_total'] ?> items checked</b><div class="rm-cell-sub">Complete the inspection to lock the checklist and continue to Key Handover.</div></div>
                                <button type="button" class="btn btn-success" onclick="rmsPost('insp_complete', {}, this)"><i class="bi bi-check2-circle"></i> Complete inspection</button>
                            <?php else: ?>
                                <i class="bi bi-hourglass-split"></i>
                                <div class="flex-grow-1"><b><?= (int)$st['items_checked'] ?> of <?= (int)$st['items_total'] ?> items checked</b><div class="rm-cell-sub">Check (or mark N/A) every item, then complete the inspection.</div></div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php $renderRemarks(); // Remarks under the Inspection card ?>

                <!-- Stage 4: Key Handover -->
                <div class="rms-stage <?= $stageCls(4) ?>" data-ms="4">
                    <div class="rms-stage-h">
                        <span class="rms-stage-num"><?= $stageNum(4) ?></span>
                        <div><div class="rms-stage-title">Key Handover</div><div class="rms-stage-sub">Keys and access cards granted to the tenant</div></div>
                        <span class="ms-auto"><?= $st['done'][4] ? re_move_ui_pill('Handed over', 'success')
                            : ($st['keys_ready'] ? re_move_ui_pill($st['key_mode'] === 'on_signing' ? 'On signing' : 'Scheduled', 'info')
                            : re_move_ui_pill($st['current'] === 4 ? 'To do' : 'Waiting', $st['current'] === 4 ? 'primary' : 'secondary')) ?></span>
                    </div>
                    <div class="rms-stage-b">
                        <?php $keysEditable = !$rmsLocked && !$st['done'][4] && !rms_stage_guard($st, 4); ?>
                        <?php if (!$keysEditable && !$st['done'][4] && !$rmsLocked): ?>
                            <p class="rms-lock-line"><i class="bi bi-lock"></i> Complete the inspection first.</p>
                        <?php endif; ?>
                        <?php
                        $keyMode = $st['key_mode'];
                        $keyAt = $moveIn['key_handover_scheduled_at'] ?? null;
                        $keyModeReady = rms_key_mode_ready($conn);
                        ?>
                        <form id="rmsKeysForm" class="rms-keys" onsubmit="event.preventDefault(); rmsSaveKeys(this);">
                            <?php if ($keyModeReady): ?>
                                <?php
                                $moveInDay = date('Y-m-d', strtotime($moveIn['move_in_date']));
                                $keyDay = $keyMode === 'scheduled' && $keyAt ? date('Y-m-d', strtotime($keyAt)) : $moveInDay;
                                ?>
                                <div class="rms-key-when">
                                    <label class="rms-key-when-t" for="rmsKeyDate">Key handover date</label>
                                    <?php if ($keysEditable): ?>
                                        <input type="date" id="rmsKeyDate" name="key_handover_date" class="form-control" style="max-width:220px"
                                               value="<?= h($keyDay) ?>" min="<?= h($moveInDay) ?>" data-move-in="<?= h($moveInDay) ?>" onchange="rmsKeyDate(this)">
                                        <div class="form-text">The move-in date (<?= date('M d, Y', strtotime($moveInDay)) ?>) by default. Choose a later date if the keys are handed over after move-in.</div>
                                    <?php else: ?>
                                        <div class="rm-cell-sub"><i class="bi bi-calendar-event"></i> <?= $keyMode === 'on_signing' ? 'When the tenant signs the inspection form' : date('M d, Y', strtotime($keyDay)) ?></div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle rm-table-stack">
                                    <thead><tr><th>Description</th><th style="width:110px">Quantity</th><th>Card No.</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($rmsKeys as $k): $isOther = !in_array($k['key_label'], RMS_DEFAULT_KEYS, true) || $k['key_label'] === 'Others'; ?>
                                        <tr>
                                            <td data-label="Description">
                                                <?php if ($isOther && $keysEditable): ?>
                                                    <input type="text" class="form-control form-control-sm" name="keys[<?= (int)$k['id'] ?>][label]" value="<?= h($k['key_label']) ?>" maxlength="100" placeholder="Others (please specify)">
                                                <?php else: ?>
                                                    <span class="rm-cell-main"><?= h($k['key_label']) ?></span>
                                                    <input type="hidden" name="keys[<?= (int)$k['id'] ?>][label]" value="<?= h($k['key_label']) ?>">
                                                <?php endif; ?>
                                            </td>
                                            <td data-label="Quantity"><input type="number" min="0" max="99" class="form-control form-control-sm" name="keys[<?= (int)$k['id'] ?>][quantity]" value="<?= (int)$k['quantity'] ?>" <?= $keysEditable ? '' : 'disabled' ?>></td>
                                            <td data-label="Card No."><input type="text" class="form-control form-control-sm" name="keys[<?= (int)$k['id'] ?>][cards]" value="<?= h($k['card_numbers'] ?? '') ?>" maxlength="255" placeholder="e.g. 0012, 0013" <?= $keysEditable ? '' : 'disabled' ?>></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if ($keysEditable): ?>
                                        <tr>
                                            <td data-label="Add"><input type="text" class="form-control form-control-sm" name="new_label" maxlength="100" placeholder="+ Add another (e.g. Mailbox Key)"></td>
                                            <td data-label="Quantity"><input type="number" min="0" max="99" class="form-control form-control-sm" name="new_quantity" value="0"></td>
                                            <td data-label="Card No."><input type="text" class="form-control form-control-sm" name="new_cards" maxlength="255"></td>
                                        </tr>
                                    <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="d-flex flex-wrap align-items-center gap-2 mt-2">
                                <?php if ($st['done'][4]): ?>
                                    <span class="rms-done-line"><i class="bi bi-check-circle-fill"></i> Handed over<?= !empty($moveIn['keys_handed_over_by_name']) ? ' by ' . h($moveIn['keys_handed_over_by_name']) : '' ?><?= !empty($moveIn['keys_handed_over_at']) ? ' · ' . date('M d, Y H:i', strtotime($moveIn['keys_handed_over_at'])) : '' ?></span>
                                    <?php if (!$rmsLocked && !$st['done'][5]): ?>
                                        <button type="button" class="btn btn-sm btn-link text-muted text-decoration-none ms-auto" onclick="rmsPost('keys_confirm', {value: 0}, this)"><i class="bi bi-arrow-counterclockwise"></i> Undo</button>
                                    <?php endif; ?>
                                <?php elseif ($keysEditable): ?>
                                    <?php
                                    // One set of buttons per timing; switching the timing above shows the matching set
                                    $keyAtLabel = $keyAt ? date('M d, Y', strtotime($keyAt)) : 'the chosen date';
                                    $keyDue = $keyAt && strtotime($keyAt) <= time();
                                    ?>
                                    <div class="rms-key-actions" data-key-actions="move_in" <?= $keyMode === 'move_in' ? '' : 'hidden' ?>>
                                        <button type="submit" class="btn btn-outline-primary"><i class="bi bi-save"></i> Save draft</button>
                                        <button type="button" class="btn btn-success" onclick="rmsConfirmKeys(this)"><i class="bi bi-key"></i> Keys &amp; Access Handed Over</button>
                                        <div class="rms-key-hint"><b>Save draft</b> keeps the list editable. Click <b>Keys &amp; Access Handed Over</b> once the tenant has the keys: step 4 is done and the list is locked.</div>
                                    </div>
                                    <div class="rms-key-actions" data-key-actions="scheduled" <?= $keyMode === 'scheduled' ? '' : 'hidden' ?>>
                                        <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Save</button>
                                        <button type="button" class="btn <?= $keyDue ? 'btn-success' : 'btn-outline-success' ?>" onclick="rmsConfirmKeys(this)"><i class="bi bi-key"></i> Keys &amp; Access Handed Over</button>
                                        <div class="rms-key-hint"><b>Save</b> now: the inspection form can then be prepared. On the handover day (<?= h($keyAtLabel) ?>), click <b>Keys &amp; Access Handed Over</b>.</div>
                                    </div>
                                    <div class="rms-key-actions" data-key-actions="on_signing" <?= $keyMode === 'on_signing' ? '' : 'hidden' ?>>
                                        <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Save keys</button>
                                        <div class="rms-key-hint"><b>Save keys</b> now: the inspection form can then be prepared. The handover is recorded automatically when the signed form is uploaded in step 5.</div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>

                <?php
                // Keys still to hand over (later handover): shown on the form step and the finish step
                $keysPrompt = static function () use ($st, $moveIn, $rmsLocked) {
                    if ($st['done'][4] || !$st['keys_ready'] || $rmsLocked) return;
                    if ($st['key_mode'] === 'scheduled'):
                        $at = $moveIn['key_handover_scheduled_at'] ?? null; ?>
                        <div class="rms-keys-due">
                            <i class="bi bi-key"></i>
                            <div class="flex-grow-1"><b>Keys still to hand over</b><div class="rm-cell-sub">Scheduled for <?= $at ? date('M d, Y', strtotime($at)) : 'the agreed date' ?>. Confirm here once the tenant has them.</div></div>
                            <button type="button" class="btn btn-success" onclick="if (confirm('Has the tenant received the keys and cards listed in Key Handover?')) rmsPost('keys_confirm', {value: 1}, this)"><i class="bi bi-key"></i> Keys &amp; Access Handed Over</button>
                        </div>
                    <?php else: ?>
                        <div class="rms-keys-due">
                            <i class="bi bi-pen"></i>
                            <div class="flex-grow-1"><b>Keys handed over on signing</b><div class="rm-cell-sub">Recorded automatically when the signed form is uploaded.</div></div>
                        </div>
                    <?php endif;
                };
                ?>
                <!-- Stage 5: Inspection Report -->
                <?php
                $formOpen = $st['form_open'] && $moveIn['status'] !== 'cancelled';
                $formSent = !empty($moveIn['form_sent_at']);
                $signedForms = $inspectionReports;
                $photoCount = $st['files']['photo'];
                $formUrl = 'move_in_form.php?id=' . $moveInId;
                ?>
                <div class="rms-stage <?= $stageCls(5) ?>" id="rmsStage5" data-ms="5">
                    <div class="rms-stage-h">
                        <span class="rms-stage-num"><?= $stageNum(5) ?></span>
                        <div><div class="rms-stage-title">Inspection Report</div><div class="rms-stage-sub">Move-in details, keys, checklist and photos, signed by the tenant</div></div>
                        <span class="ms-auto"><?= re_move_ui_pill(
                            $st['done'][5] ? 'Signed' : ($formSent ? 'Awaiting signature' : ($st['current'] === 5 ? 'To do' : 'Waiting')),
                            $st['done'][5] ? 'success' : ($formSent ? 'warning' : ($st['current'] === 5 ? 'primary' : 'secondary'))) ?></span>
                    </div>
                    <div class="rms-stage-b">
                        <?php if (!$formOpen): ?>
                            <p class="rms-lock-line mb-0"><i class="bi bi-lock"></i> <?= $st['key_mode'] === 'move_in'
                                ? 'Confirm the key handover first. The form lists the keys handed over.'
                                : 'Finish the inspection and save the keys to be handed over (step 4) first.' ?></p>
                        <?php else: ?>
                            <?php $keysPrompt(); ?>
                            <?php
                            $meterCount = count($meterReadings);
                            $hasServices = trim((string)($moveIn['additional_services'] ?? '')) !== '';
                            $itemRemarkCount = 0;
                            foreach ($rmsItems as $secX) { foreach ($secX['items'] as $itX) { $itemRemarkCount += trim((string)($itX['remark'] ?? '')) !== '' ? 1 : 0; } }
                            $hasInspRemarks = trim((string)($moveIn['inspection_notes'] ?? '')) !== '';
                            // [label, count text, ticked by default, available]
                            $formOpts = [
                                'photos' => ['Photos', $photoCount ?: '', $photoCount > 0, $photoCount > 0],
                                'meters' => ['Meter readings', $meterCount ?: '', false, $meterCount > 0],
                                'services' => ['Additional services', '', false, $hasServices],
                                'item_remarks' => ['Item remarks', $itemRemarkCount ?: '', false, $itemRemarkCount > 0],
                                'insp_remarks' => ['Inspection remarks', '', false, $hasInspRemarks],
                            ];
                            $optQuery = static function () use ($formOpts) {
                                $q = '';
                                foreach ($formOpts as $k => $o) { $q .= '&' . $k . '=' . ($o[2] ? 1 : 0); }
                                return $q;
                            };
                            $latestSigned = $signedForms[0] ?? null;
                            $sigReady = re_sig_ready($conn, 're_move_ins');
                            $sentLine = $formSent
                                ? 'Last sent to ' . h($moveIn['form_sent_to']) . ' · ' . date('M d, Y H:i', strtotime($moveIn['form_sent_at'])) . (isset($rmsUsers[(int)$moveIn['form_sent_by']]) ? ' by ' . h($rmsUsers[(int)$moveIn['form_sent_by']]) : '')
                                : '';

                            // Include chips + Preview / Download (used before signing, and under "Change the form")
                            $renderPrepare = static function () use ($formOpts, $optQuery, $formUrl, $moveInId) { ?>
                                <div class="rmf-chips" aria-label="Include in the form">
                                    <?php foreach ($formOpts as $k => [$label, $count, $on, $available]): ?>
                                        <label class="rmf-chip <?= $available ? '' : 'is-off' ?>" title="<?= $available ? 'Include in the form' : 'Nothing to include' ?>">
                                            <input type="checkbox" class="rms-form-opt" data-opt="<?= $k ?>" <?= $on ? 'checked' : '' ?> <?= $available ? '' : 'disabled' ?> onchange="rmsFormLinks()">
                                            <i class="bi bi-check-lg"></i><?= h($label) ?><?= $count !== '' ? ' <em>' . (int)$count . '</em>' : '' ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <div class="rmf-row">
                                    <a class="btn btn-sm btn-outline-primary" id="rmsFormPreview" data-base="<?= h($formUrl) ?>" href="<?= h($formUrl . $optQuery()) ?>" target="_blank" rel="noopener"><i class="bi bi-eye"></i> Preview</a>
                                    <a class="btn btn-sm btn-outline-primary" id="rmsFormDownload" data-base="<?= h($formUrl) ?>&download=1" href="<?= h($formUrl . '&download=1' . $optQuery()) ?>"><i class="bi bi-download"></i> Download</a>
                                    <a href="form_letterhead.php?back=<?= urlencode('move_in_view.php?id=' . $moveInId) ?>" class="rmf-link ms-auto"><i class="bi bi-layout-text-window"></i> Header &amp; footer</a>
                                </div>
                            <?php };

                            // Paper-signed scan upload (collapsed)
                            $renderUpload = static function () use ($sigReady) { ?>
                                <form class="move-in-upload-form <?= $sigReady ? 'collapse' : '' ?>" id="rmsSignedUpload" data-file-type="inspection_report">
                                    <div class="rms-add mt-2">
                                        <input type="file" name="files[]" class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png,.webp" multiple required>
                                        <button type="submit" class="btn btn-sm btn-outline-primary text-nowrap"><i class="bi bi-upload"></i> Upload scan</button>
                                    </div>
                                    <div class="form-text">PDF or photo of the paper-signed form, up to 10MB each.</div>
                                </form>
                            <?php };
                            ?>

                            <form id="rmsSendFormEl" onsubmit="event.preventDefault(); rmsSendForm(this);">
                                <input type="hidden" name="send_what" id="rmsSendWhat" value="<?= $latestSigned ? 'signed' : 'unsigned' ?>">
                            </form>

                            <?php if ($latestSigned): ?>
                                <!-- Signed: the signed copy first, everything else folded away -->
                                <div class="rmf-signed">
                                    <div class="rmf-signed-ic"><i class="bi bi-patch-check-fill"></i></div>
                                    <div class="rmf-signed-txt">
                                        <div class="rmf-signed-t"><?= h($latestSigned['photo_description'] ?: 'Signed copy') ?></div>
                                        <div class="rm-cell-sub"><?= date('M d, Y · H:i', strtotime($latestSigned['uploaded_at'])) ?></div>
                                    </div>
                                    <div class="rmf-row ms-auto">
                                        <a class="btn btn-sm btn-success" href="../../<?= h($latestSigned['photo_path']) ?>" target="_blank" rel="noopener"><i class="bi bi-eye"></i> View</a>
                                        <a class="btn btn-sm btn-outline-success" href="../../<?= h($latestSigned['photo_path']) ?>" download><i class="bi bi-download"></i></a>
                                    </div>
                                </div>

                                <div class="rmf-send">
                                    <div class="rmf-send-t">Email the signed copy to the tenant</div>
                                    <div class="rms-add mt-0">
                                        <input type="email" name="to" form="rmsSendFormEl" class="form-control" value="<?= h($moveIn['email'] ?? '') ?>" placeholder="Tenant email" required>
                                        <button class="btn btn-primary text-nowrap" type="submit" form="rmsSendFormEl" onclick="document.getElementById('rmsSendWhat').value = 'signed';"><i class="bi bi-send"></i> Send</button>
                                    </div>
                                    <?php if ($sentLine): ?><div class="rms-item-meta mt-1"><i class="bi bi-check2"></i> <?= $sentLine ?></div><?php endif; ?>
                                </div>

                                <details class="rmf-more">
                                    <summary><i class="bi bi-sliders"></i> Change the form or sign again <i class="bi bi-chevron-down ms-auto"></i></summary>
                                    <div class="rmf-more-b">
                                        <div class="rmf-sub">Form contents</div>
                                        <?php $renderPrepare(); ?>
                                        <div class="rmf-sub">Signature</div>
                                        <div class="rmf-row">
                                            <?php if ($sigReady): ?>
                                                <button type="button" class="btn btn-sm btn-outline-success" onclick="rmsSign()"><i class="bi bi-pen"></i> Sign again</button>
                                            <?php endif; ?>
                                            <a class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" href="#rmsSignedUpload" role="button"><i class="bi bi-upload"></i> Upload paper scan</a>
                                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="document.getElementById('rmsSendWhat').value = 'unsigned'; rmsSendForm(document.getElementById('rmsSendFormEl')); document.getElementById('rmsSendWhat').value = 'signed';"><i class="bi bi-send"></i> Email unsigned form</button>
                                        </div>
                                        <?php $renderUpload(); ?>
                                        <div class="rmf-sub">Signed copies</div>
                                        <?php foreach ($signedForms as $report): ?>
                                            <div class="rmf-file">
                                                <i class="bi bi-file-earmark-check"></i>
                                                <a href="../../<?= h($report['photo_path']) ?>" target="_blank" rel="noopener"><?= h($report['photo_description'] ?: basename($report['photo_path'])) ?></a>
                                                <span class="rm-cell-sub"><?= date('M d, Y H:i', strtotime($report['uploaded_at'])) ?></span>
                                                <?php if ($moveIn['status'] !== 'completed'): ?>
                                                    <button type="button" class="btn btn-sm btn-link text-danger p-0 ms-auto" title="Remove" onclick="deleteMoveInFile(<?= (int)$report['id'] ?>)"><i class="bi bi-trash"></i></button>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </details>
                            <?php else: ?>
                                <!-- Not signed yet: prepare -> sign -> (or) email for signing -->
                                <div class="rmf-step">
                                    <div class="rmf-step-n">1</div>
                                    <div class="rmf-step-b">
                                        <div class="rmf-step-t">Prepare the form</div>
                                        <?php $renderPrepare(); ?>
                                    </div>
                                </div>
                                <div class="rmf-step">
                                    <div class="rmf-step-n">2</div>
                                    <div class="rmf-step-b">
                                        <div class="rmf-step-t">Tenant signs</div>
                                        <div class="rmf-row">
                                            <?php if ($sigReady): ?>
                                                <button type="button" class="btn btn-success" onclick="rmsSign()"><i class="bi bi-pen"></i> Digital Sign</button>
                                            <?php else: ?>
                                                <span class="rm-cell-sub">Run <code>migrations/re_tenant_signatures.sql</code> to sign digitally.</span>
                                            <?php endif; ?>
                                            <a class="rmf-link" data-bs-toggle="collapse" href="#rmsSignedUpload" role="button"><i class="bi bi-upload"></i> Signed on paper? Upload the scan</a>
                                        </div>
                                        <?php $renderUpload(); ?>
                                    </div>
                                </div>
                                <div class="rmf-step">
                                    <div class="rmf-step-n is-or">or</div>
                                    <div class="rmf-step-b">
                                        <div class="rmf-step-t">Email it to the tenant for signing</div>
                                        <div class="rms-add mt-0">
                                            <input type="email" name="to" form="rmsSendFormEl" class="form-control" value="<?= h($moveIn['email'] ?? '') ?>" placeholder="Tenant email" required>
                                            <button class="btn btn-outline-primary text-nowrap" type="submit" form="rmsSendFormEl"><i class="bi bi-send"></i> Send</button>
                                        </div>
                                        <?php if ($sentLine): ?><div class="rms-item-meta mt-1"><i class="bi bi-check2"></i> <?= $sentLine ?></div><?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <!-- Milestone: Move-In Completed -->
                <div class="card mb-4 rmm-finish" data-ms="6">
                    <div class="card-body">
                        <?php if ($moveIn['status'] === 'completed'): ?>
                            <div class="rmm-finish-hero">
                                <div class="rmm-finish-art" aria-hidden="true"><i class="bi bi-house-heart"></i><span></span><span></span><span></span></div>
                                <div>
                                    <div class="rmm-head-title">The tenant has moved in</div>
                                    <div class="rm-cell-sub">Completed<?= !empty($moveIn['approved_at']) ? ' on ' . date('M d, Y H:i', strtotime($moveIn['approved_at'])) : '' ?><?= !empty($moveIn['approved_by_name']) ? ' by ' . h($moveIn['approved_by_name']) : '' ?>. The unit is marked occupied.</div>
                                </div>
                            </div>
                        <?php endif; ?>
                        <?php $keysPrompt(); ?>
                        <div class="rmm-finish-list">
                            <?php foreach ([1 => 'Contract Signed', 2 => 'Payment Confirmed', 3 => 'Inspection', 4 => 'Key Handover', 5 => 'Signed Inspection Report'] as $n => $label):
                                $ok = $rmsState['done'][$n]; ?>
                                <a href="#step-<?= $n ?>" class="rmm-finish-row <?= $ok ? 'ok' : '' ?>" onclick="event.preventDefault(); rmmSelect(<?= $n ?>); window.scrollTo({top: 0, behavior: 'smooth'});">
                                    <i class="bi <?= $ok ? 'bi-check-circle-fill' : ($n === 5 ? 'bi-circle-half' : 'bi-circle') ?>"></i>
                                    <span><?= h($label) ?><?= $n === 5 ? ' <span class="rm-cell-sub">(optional to complete)</span>' : '' ?></span>
                                    <i class="bi bi-chevron-right ms-auto"></i>
                                </a>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($moveIn['status'] !== 'completed' && $moveIn['status'] !== 'cancelled'): ?>
                            <button class="btn btn-success btn-lg w-100 mt-3" <?= $rmsState['ready_to_complete'] ? 'onclick="completeMoveIn()"' : 'disabled' ?>>
                                <i class="bi bi-check-circle"></i> Complete Move-In
                            </button>
                            <?php if (!$rmsState['ready_to_complete']): ?><div class="form-text text-center"><?= $rmsState['keys_ready'] && !$rmsState['done'][4] && $rmsState['done'][3] ? 'Available once the keys &amp; access are handed over.' : 'Available when steps 1 to 4 are done.' ?></div><?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php else: ?>
                <!-- Quick Verifications -->
                <?php
                $vDone = 0;
                foreach (RM_VERIFY_LINKS as $vf => $vl) { if (!empty($moveIn[$vf])) $vDone++; }
                $vLocked = in_array($moveIn['status'], ['completed', 'cancelled'], true);
                ?>
                <div class="card mb-4">
                    <div class="card-header">
                        <h5><i class="bi bi-patch-check me-1"></i> Quick Verifications</h5>
                        <span class="rm-pill rm-pill-<?= $vDone === 4 ? 'success' : ($vDone > 0 ? 'info' : 'secondary') ?>"><?= $vDone ?> of 4 done</span>
                    </div>
                    <div class="card-body">
                        <div class="rm-verify-grid">
                            <?php $vStep = 0; foreach (RM_VERIFY_LINKS as $vf => $vl):
                                $vStep++;
                                $isDone = !empty($moveIn[$vf]);
                                $by = $moveIn[$vf . '_by_name'] ?? '';
                                $at = $moveIn[$vf . '_at'] ?? '';
                                $waitFor = $isDone ? null : rm_verify_blocking_before($moveIn, $vf);   // earlier step still open
                                $laterDone = $isDone ? rm_verify_blocking_after($moveIn, $vf) : null;  // later step done: no undo
                                $isNext = !$isDone && !$waitFor;
                                $state = $isDone ? 'is-done' : ($waitFor ? 'is-locked' : 'is-next');
                            ?>
                                <div class="rm-verify <?= $state ?>">
                                  <div class="rm-verify-top">
                                    <div class="rm-verify-icon"><i class="bi <?= $isDone ? 'bi-check-lg' : ($waitFor ? 'bi-lock' : $vl['icon']) ?>"></i></div>
                                    <div class="rm-verify-body">
                                        <div class="rm-verify-step">Step <?= $vStep ?> of <?= count(RM_VERIFY_LINKS) ?><?= $isNext && !$vLocked ? ' · Next' : '' ?></div>
                                        <div class="rm-verify-title"><?= h($vl['label']) ?></div>
                                        <?php if ($isDone): ?>
                                            <div class="rm-verify-meta">
                                                <i class="bi bi-check-circle-fill text-success"></i> Done
                                                <?php if ($by): ?> · <?= h($by) ?><?php endif; ?>
                                                <?php if ($at): ?> · <?= date('M d, Y H:i', strtotime($at)) ?><?php endif; ?>
                                            </div>
                                        <?php elseif ($waitFor): ?>
                                            <div class="rm-verify-meta"><i class="bi bi-lock-fill"></i> Complete <strong><?= h(RM_VERIFY_LINKS[$waitFor]['label']) ?></strong> first</div>
                                        <?php else: ?>
                                            <div class="rm-verify-meta">Ready to complete</div>
                                        <?php endif; ?>
                                        <div class="rm-verify-link" title="Kept in sync with the checklist">
                                            <i class="bi bi-link-45deg"></i> <?= h(implode(' + ', array_slice($vl['items'], 0, 2))) ?>
                                        </div>
                                    </div>
                                  </div>
                                    <?php if (!$vLocked): ?>
                                        <div class="rm-verify-action">
                                            <?php if ($isDone && $laterDone): ?>
                                                <span class="rm-verify-hint" title="Undo <?= h(RM_VERIFY_LINKS[$laterDone]['label']) ?> first"><i class="bi bi-check2-all"></i> Confirmed</span>
                                            <?php elseif ($isDone): ?>
                                                <button type="button" class="btn btn-sm btn-link text-muted text-decoration-none px-1" onclick="toggleVerification('<?= $vf ?>')" title="Set back to not done">
                                                    <i class="bi bi-arrow-counterclockwise"></i> Undo
                                                </button>
                                            <?php elseif ($waitFor): ?>
                                                <button type="button" class="btn btn-sm btn-outline-secondary" disabled title="Complete <?= h(RM_VERIFY_LINKS[$waitFor]['label']) ?> first">
                                                    <i class="bi bi-lock"></i> Locked
                                                </button>
                                            <?php else: ?>
                                                <button type="button" class="btn btn-sm btn-success" onclick="toggleVerification('<?= $vf ?>')">
                                                    <i class="bi bi-check2"></i> Mark done
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="rm-verify-foot">
                            <?php if ($vLocked): ?>
                                <i class="bi bi-lock"></i> This move-in is <?= h($moveIn['status']) ?>, so verifications are locked.
                            <?php else: ?>
                                <i class="bi bi-info-circle"></i> Complete the steps in order. Each step is linked to its checklist item: marking one updates the other.
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Checklist -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-list-check"></i> Move-In Checklist</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($checklistItems) && !empty($moveIn['is_historical'])): ?>
                            <p class="text-muted mb-0">Recorded with <strong>Mark as Moved In</strong> — the tenant moved in before the Move-In module was used, so no checklist was completed.</p>
                        <?php elseif (empty($checklistItems)): ?>
                            <p class="text-muted">No checklist items found.</p>
                        <?php else: ?>
                            <?php foreach ($checklistItems as $item):
                                // Items linked to a verification step follow the step order
                                $itemField = rm_verify_field_for_item((string)$item['item_name']);
                                $itemBlock = null;
                                if ($itemField) {
                                    if (!$item['is_completed']) {
                                        $b = rm_verify_blocking_before($moveIn, $itemField);
                                        if ($b) $itemBlock = 'Complete "' . RM_VERIFY_LINKS[$b]['label'] . '" first';
                                    } elseif (!empty($moveIn[$itemField])) {
                                        $a = rm_verify_blocking_after($moveIn, $itemField);
                                        if ($a) $itemBlock = 'Undo "' . RM_VERIFY_LINKS[$a]['label'] . '" first';
                                    }
                                }
                            ?>
                                <div class="checklist-item <?= $item['is_completed'] ? 'completed' : '' ?> <?= $item['is_required'] ? 'required' : '' ?>">
                                    <div class="form-check">
                                        <input class="form-check-input checklist-checkbox" 
                                               type="checkbox" 
                                               data-item-id="<?= $item['id'] ?>"
                                               <?= $itemBlock ? 'disabled title="' . h($itemBlock) . '"' : '' ?>
                                               <?= $item['is_completed'] ? 'checked' : '' ?>
                                               onchange="updateChecklistItem(<?= $item['id'] ?>, this.checked)">
                                        <label class="form-check-label w-100">
                                            <strong><?= h(str_replace('First month rent', 'First Installment rent', $item['item_name'])) ?></strong>
                                            <?php if ($item['is_required']): ?>
                                                <span class="badge bg-warning ms-2">Required</span>
                                            <?php endif; ?>
                                            <?php if ($item['item_description']): ?>
                                                <br><small class="text-muted"><?= h(str_replace('first month', 'first installment', $item['item_description'])) ?></small>
                                            <?php endif; ?>
                                        </label>
                                    </div>
                                    <?php if ($itemBlock): ?>
                                        <small class="text-muted d-block mb-1"><i class="bi bi-lock"></i> <?= h($itemBlock) ?> (step order)</small>
                                    <?php endif; ?>
                                    <?php if ($item['is_completed'] && $item['completed_at']): ?>
                                        <small class="text-success">
                                            <i class="bi bi-check-circle"></i> Completed on <?= date('M d, Y H:i', strtotime($item['completed_at'])) ?>
                                        </small>
                                    <?php endif; ?>
                                    <div class="mt-2">
                                        <textarea class="form-control form-control-sm notes-field" 
                                                  data-item-id="<?= $item['id'] ?>"
                                                  placeholder="Add notes..."
                                                  onblur="saveNotes(<?= $item['id'] ?>, this.value)"><?= h($item['notes'] ?? '') ?></textarea>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <?php endif; ?>

                <?php if (!$isStageFlow) $renderRemarks(); ?>

                <!-- Meter Readings -->
                <div class="card mb-4" <?= $isStageFlow ? 'data-ms="3"' : '' ?>>
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-speedometer"></i> Meter Readings</h5>
                    </div>
                    <div class="card-body">
                        <form id="meterReadingForm" class="mb-3">
                            <div class="row g-2">
                                <div class="col-12 col-sm-6 col-lg-3">
                                    <select name="meter_type" class="form-select form-select-sm" required>
                                        <option value="">Meter Type</option>
                                        <option value="electricity">Electricity</option>
                                        <option value="water">Water</option>
                                        <option value="gas">Gas</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>
                                <div class="col-6 col-sm-6 col-lg-2">
                                    <input type="number" step="0.01" name="reading_value" class="form-control form-control-sm" placeholder="Reading" required>
                                </div>
                                <div class="col-6 col-sm-6 col-lg-2">
                                    <input type="text" name="meter_number" class="form-control form-control-sm" placeholder="Meter #">
                                </div>
                                <div class="col-12 col-sm-6 col-lg-3">
                                    <input type="text" name="notes" class="form-control form-control-sm" placeholder="Notes">
                                </div>
                                <div class="col-12 col-sm-6 col-lg-2">
                                    <button type="submit" class="btn btn-primary btn-sm w-100">
                                        <i class="bi bi-plus"></i> Add
                                    </button>
                                </div>
                            </div>
                        </form>
                        
                        <div id="meterReadingsList">
                            <?php if (empty($meterReadings)): ?>
                                <p class="text-muted">No meter readings recorded yet.</p>
                            <?php else: ?>
                                <div class="table-responsive">
                                <table class="table table-sm rm-table-stack">
                                    <thead>
                                        <tr>
                                            <th>Type</th>
                                            <th>Reading</th>
                                            <th>Meter #</th>
                                            <th>Date</th>
                                            <th>Notes</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($meterReadings as $reading): ?>
                                            <tr>
                                                <td data-label="Type"><span class="rm-cell-main"><?= ucfirst($reading['meter_type']) ?></span></td>
                                                <td data-label="Reading"><?= number_format($reading['reading_value'], 2) ?></td>
                                                <td data-label="Meter #"><?= h($reading['meter_number'] ?: '-') ?></td>
                                                <td data-label="Date"><?= date('M d, Y', strtotime($reading['reading_date'])) ?></td>
                                                <td data-label="Notes"><?= h($reading['notes'] ?: '-') ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Move-In Photos & Inspection Report (stage flow: the signed report is uploaded in step 5) -->
                <div class="card mb-4" id="rmsPhotos" <?= $isStageFlow ? 'data-ms="3 5"' : '' ?>>
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-camera"></i> Move-In Photos<?= $isStageFlow ? '' : ' &amp; Inspection Report' ?></h5>
                    </div>
                    <div class="card-body">
                        <h6>Move-In Photos</h6>
                        <form class="move-in-upload-form mb-3" data-file-type="photo">
                            <div class="row g-3">
                                <div class="col-12 col-md-6">
                                    <input type="file" name="files[]" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.webp" multiple required>
                                </div>
                                <div class="col-sm-8 col-md-4">
                                    <input type="text" name="room_area" class="form-control form-control-sm" placeholder="Room / area (optional)" maxlength="100">
                                </div>
                                <div class="col-sm-4 col-md-2">
                                    <button type="submit" class="btn btn-primary btn-sm w-100">
                                        <i class="bi bi-upload"></i> Upload
                                    </button>
                                </div>
                            </div>
                            <div class="form-text">JPG, PNG or WEBP, up to 10MB each. Several photos can be selected at once.</div>
                        </form>

                        <?php if (empty($moveInPhotos)): ?>
                            <p class="text-muted">No photos uploaded yet.</p>
                        <?php else: ?>
                            <div class="row g-2 mb-3">
                                <?php foreach ($moveInPhotos as $photo): ?>
                                    <div class="col-6 col-md-3">
                                        <div class="move-in-photo">
                                            <a href="../../<?= h($photo['photo_path']) ?>" target="_blank">
                                                <img src="../../<?= h($photo['photo_path']) ?>" alt="<?= h($photo['photo_description'] ?? 'Move-in photo') ?>">
                                            </a>
                                            <div class="d-flex justify-content-between align-items-center mt-1">
                                                <small class="text-muted text-truncate"><?= h($photo['room_area'] ?: date('M d, Y', strtotime($photo['uploaded_at']))) ?></small>
                                                <?php if ($moveIn['status'] !== 'completed'): ?>
                                                    <button type="button" class="btn btn-sm btn-link text-danger p-0" title="Remove" onclick="deleteMoveInFile(<?= (int)$photo['id'] ?>)">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!$isStageFlow): ?>
                        <hr>

                        <h6>Inspection Report</h6>
                        <form class="move-in-upload-form mb-3" data-file-type="inspection_report">
                            <div class="row g-3">
                                <div class="col-sm-8 col-md-10">
                                    <input type="file" name="files[]" class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png,.webp" multiple required>
                                </div>
                                <div class="col-sm-4 col-md-2">
                                    <button type="submit" class="btn btn-primary btn-sm w-100">
                                        <i class="bi bi-upload"></i> Upload
                                    </button>
                                </div>
                            </div>
                            <div class="form-text">PDF or image, up to 10MB each.</div>
                        </form>

                        <?php if (empty($inspectionReports)): ?>
                            <p class="text-muted mb-0">No inspection report uploaded yet.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                            <table class="table table-sm mb-0 rm-table-stack">
                                <thead>
                                    <tr>
                                        <th>File</th>
                                        <th>Uploaded</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($inspectionReports as $report): ?>
                                        <tr>
                                            <td>
                                                <a href="../../<?= h($report['photo_path']) ?>" target="_blank">
                                                    <i class="bi bi-file-earmark-text"></i> <?= h($report['photo_description'] ?: basename($report['photo_path'])) ?>
                                                </a>
                                            </td>
                                            <td data-label="Uploaded"><?= date('M d, Y H:i', strtotime($report['uploaded_at'])) ?></td>
                                            <td class="text-end">
                                                <?php if ($moveIn['status'] !== 'completed'): ?>
                                                    <button type="button" class="btn btn-sm btn-link text-danger p-0" title="Remove" onclick="deleteMoveInFile(<?= (int)$report['id'] ?>)">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            </div>
                        <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Right Column: Actions & Summary -->
            <div class="col-lg-4 rmm-side" <?= $isStageFlow ? 'data-ms="0 5 6"' : '' ?>>
              <div class="rm-sticky">
                <!-- Actions -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-gear"></i> Actions</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($moveIn['status'] !== 'completed' && $isStageFlow && !$rmsState['ready_to_complete']): ?>
                            <button class="btn btn-success w-100 mb-1" disabled><i class="bi bi-check-circle"></i> Complete Move-In</button>
                            <div class="form-text mb-2 text-center"><?= $rmsState['keys_ready'] && !$rmsState['done'][4] && $rmsState['done'][3] ? 'Available once the keys &amp; access are handed over.' : 'Available when stages 1 to 4 are done.' ?></div>
                        <?php elseif ($moveIn['status'] !== 'completed'): ?>
                            <button class="btn btn-success w-100 mb-2" onclick="completeMoveIn()">
                                <i class="bi bi-check-circle"></i> Complete Move-In
                            </button>
                        <?php endif; ?>
                        <?php if ($isStageFlow && $rmsState['keys_ready'] && !$rmsState['done'][5] && $moveIn['status'] !== 'cancelled'): ?>
                            <a href="#rmsStage5" class="btn btn-outline-warning w-100 mb-2"><i class="bi bi-pen"></i> Signed form not uploaded yet</a>
                        <?php endif; ?>
                        <a href="lease_view.php?id=<?= $moveIn['lease_id'] ?>" class="btn btn-outline-primary w-100 mb-2">
                            <i class="bi bi-file-text"></i> View Lease
                        </a>
                        <a href="move_in.php" class="btn btn-outline-secondary w-100">
                            <i class="bi bi-arrow-left"></i> Back to List
                        </a>
                    </div>
                </div>

                <!-- Summary -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-info-circle"></i> Summary</h5>
                    </div>
                    <div class="card-body">
                        <dl class="rm-dl">
                            <dt>Created</dt><dd><?= date('M d, Y H:i', strtotime($moveIn['created_at'])) ?></dd>
                            <?php if ($moveIn['created_by_name']): ?>
                                <dt>Created By</dt><dd><?= h($moveIn['created_by_name']) ?></dd>
                            <?php endif; ?>
                            <?php if ($moveIn['approved_by_name']): ?>
                                <dt>Approved By</dt><dd><?= h($moveIn['approved_by_name']) ?></dd>
                                <dt>Approved At</dt><dd><?= date('M d, Y H:i', strtotime($moveIn['approved_at'])) ?></dd>
                            <?php endif; ?>
                        </dl>
                        <?php if ($moveIn['notes']): ?>
                            <hr>
                            <p class="rm-section-title">Notes</p>
                            <p class="mb-0"><?= nl2br(h($moveIn['notes'])) ?></p>
                        <?php endif; ?>
                    </div>
                </div>
              </div>
            </div>
        </div>
</div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <?= $isStageFlow ? re_milestones_js() : '' ?>
    <?= re_sig_modal_html() ?>
    <script>
        const csrfToken = '<?= csrf_token() ?>';

        // ----- Move-In stages -----
        function rmsPost(action, data, btn) {
            const fd = data instanceof FormData ? data : new FormData();
            if (!(data instanceof FormData)) Object.entries(data || {}).forEach(([k, v]) => fd.append(k, v));
            fd.append('action', action);
            fd.append('_csrf', csrfToken);
            if (btn) btn.disabled = true;
            return fetch('', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(r => r.text().then(t => { try { return JSON.parse(t); } catch (e) { throw new Error(t.trim().slice(0, 200) || ('HTTP ' + r.status)); } }))
                .then(res => {
                    if (res.success) {
                        try { sessionStorage.setItem('rmsScroll', String(window.scrollY)); } catch (e) {}
                        location.reload();
                    } else {
                        if (btn) btn.disabled = false;
                        alert(res.message || 'Could not save.');
                    }
                    return res;
                })
                .catch(err => { if (btn) btn.disabled = false; alert('Could not save: ' + err.message); });
        }
        function rmsSaveRemarks(el) {
            if (el.dataset.saved === el.value) return;
            const fd = new FormData(); fd.append('action', 'insp_save_remarks'); fd.append('remarks', el.value); fd.append('_csrf', csrfToken);
            fetch('', { method: 'POST', body: fd, credentials: 'same-origin' }).then(r => r.json()).then(res => {
                if (res.success) { el.dataset.saved = el.value; el.classList.add('is-valid'); setTimeout(() => el.classList.remove('is-valid'), 1200); }
                else alert(res.message || 'Could not save remarks.');
            }).catch(() => alert('Could not save remarks.'));
        }
        // Include choices for the form: {photos: 1, meters: 0, services: 0}
        function rmsFormOpts() {
            const o = {};
            document.querySelectorAll('.rms-form-opt').forEach(cb => { o[cb.dataset.opt] = cb.checked ? 1 : 0; });
            return o;
        }
        function rmsFormLinks() {
            const q = Object.entries(rmsFormOpts()).map(([k, v]) => '&' + k + '=' + v).join('');
            ['rmsFormPreview', 'rmsFormDownload'].forEach(id => {
                const a = document.getElementById(id);
                if (a) a.href = a.dataset.base + q;
            });
        }
        // Digital Sign (step 5): the tenant signs in the pop-up; the signed form is generated and saved
        function rmsSign() {
            reSigOpen({
                title: 'Tenant signature · Move-In Inspection Report',
                name: <?= json_encode(trim($moveIn['first_name'] . ' ' . $moveIn['last_name'])) ?>,
                onSave: (dataUrl, name, btn) => {
                    const fd = new FormData();
                    fd.append('signature', dataUrl);
                    fd.append('signer_name', name);
                    Object.entries(rmsFormOpts()).forEach(([k, v]) => fd.append('include_' + k, v));
                    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Saving';
                    rmsPost('form_sign', fd, btn).finally(() => { btn.innerHTML = '<i class="bi bi-check-circle"></i> Sign &amp; save'; });
                }
            });
        }
        function rmsSendForm(form) {
            const opts = rmsFormOpts();
            const to = form.to.value.trim();
            const what = (form.querySelector('[name=send_what]:checked') || form.querySelector('[name=send_what]') || {}).value || 'unsigned';
            if (!to) { alert('Enter the tenant email.'); return; }
            if (!confirm('Email the ' + (what === 'signed' ? 'SIGNED' : 'unsigned') + ' move-in inspection form to ' + to + '?')) return;
            // The Send button sits outside the <form> (form="rmsSendFormEl")
            const btn = Array.from(form.elements).find(el => el.tagName === 'BUTTON') || form.querySelector('button');
            const label = btn.innerHTML;
            const done = msg => { btn.disabled = false; btn.innerHTML = label; alert(msg); };
            const fd = new FormData();
            fd.append('action', 'form_send'); fd.append('to', to); fd.append('include_photos', opts.photos || 0); fd.append('include_meters', opts.meters || 0); fd.append('include_services', opts.services || 0); fd.append('include_item_remarks', opts.item_remarks || 0); fd.append('include_insp_remarks', opts.insp_remarks || 0); fd.append('send_what', what); fd.append('_csrf', csrfToken);
            btn.disabled = true;
            btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Sending...';
            fetch('', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(r => r.text().then(t => { try { return JSON.parse(t); } catch (e) { throw new Error(t.trim().slice(0, 200) || ('HTTP ' + r.status)); } }))
                .then(res => {
                    if (!res.success) { done(res.message || 'Could not send the form.'); return; }
                    alert(res.message);
                    try { sessionStorage.setItem('rmsScroll', String(window.scrollY)); } catch (e) {}
                    location.reload();
                })
                .catch(err => done('Could not send the form: ' + err.message));
        }
        function rmsVoid(form, action) {
            if (!confirm('This cannot be undone. Continue?')) return;
            rmsPost(action, {reason: form.reason.value}, form.querySelector('button[type=submit]'));
        }
        function rmsInspReopen(btn) {
            const reason = prompt('Reopen the inspection to change it?\n\nReason for the change:', '');
            if (reason === null) return;
            if (!reason.trim()) { alert('Enter the reason for changing the inspection.'); return; }
            rmsPost('insp_reopen', {reason: reason.trim()}, btn);
        }
        function rmsRemarkEdit(btn) {
            const box = btn.closest('.rms-remark');
            const f = box.querySelector('.rms-remark-form');
            box.querySelectorAll('.rms-remark-text, .rms-remark-add').forEach(el => { el.hidden = true; });
            f.hidden = false;
            f.remark.focus();
        }
        function rmsRemarkCancel(btn) {
            const box = btn.closest('.rms-remark');
            box.querySelector('.rms-remark-form').hidden = true;
            box.querySelectorAll('.rms-remark-text, .rms-remark-add').forEach(el => { el.hidden = false; });
        }
        // Later than the move-in date = scheduled handover: show its buttons
        function rmsKeyDate(input) {
            const mode = input.value && input.value > input.dataset.moveIn ? 'scheduled' : 'move_in';
            document.querySelectorAll('[data-key-actions]').forEach(el => { el.hidden = el.dataset.keyActions !== mode; });
        }
        function rmsSaveKeys(form) {
            return rmsPost('keys_save', new FormData(form), form.querySelector('button[type=submit]'));
        }
        function rmsConfirmKeys(btn) {
            // Save the table first, then confirm the handover
            const form = document.getElementById('rmsKeysForm');
            const fd = new FormData(form); fd.append('action', 'keys_save'); fd.append('_csrf', csrfToken);
            btn.disabled = true;
            fetch('', { method: 'POST', body: fd, credentials: 'same-origin' }).then(r => r.json()).then(res => {
                if (!res.success) { btn.disabled = false; alert(res.message || 'Could not save keys.'); return; }
                if (!confirm('Has the tenant received these keys and cards?\n\nStep 4 will be marked done and the key list locked (you can still Undo).')) { btn.disabled = false; return; }
                rmsPost('keys_confirm', {value: 1}, btn);
            }).catch(() => { btn.disabled = false; alert('Could not save keys.'); });
        }
        (function () {
            try {
                const y = sessionStorage.getItem('rmsScroll');
                if (y !== null) { sessionStorage.removeItem('rmsScroll'); window.addEventListener('load', () => window.scrollTo(0, parseInt(y, 10))); }
            } catch (e) {}
            const r = document.getElementById('rmsRemarks'); if (r) r.dataset.saved = r.value;
        })();
        
        function updateChecklistItem(itemId, isCompleted) {
            const checkbox = event.target;
            checkbox.disabled = true;
            
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'update_checklist_item',
                    item_id: itemId,
                    is_completed: isCompleted ? 1 : 0,
                    _csrf: csrfToken
                })
            })
            .then(r => {
                if (!r.ok) {
                    throw new Error('Network response was not ok');
                }
                return r.json();
            })
            .then(data => {
                if (data.success) {
                    // Update progress bar immediately
                    updateProgressBar();
                    // Reload after a short delay to show the change
                    setTimeout(() => location.reload(), 300);
                } else {
                    checkbox.checked = !isCompleted; // Revert checkbox
                    checkbox.disabled = false;
                    alert(data.message || 'Error updating checklist item');
                }
            })
            .catch(error => {
                checkbox.checked = !isCompleted; // Revert checkbox
                checkbox.disabled = false;
                console.error('Error:', error);
                alert('Error updating checklist item: ' + error.message);
            });
        }
        
        function saveNotes(itemId, notes) {
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'update_checklist_item',
                    item_id: itemId,
                    notes: notes,
                    _csrf: csrfToken
                })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    // Show a subtle success indicator
                    const textarea = event.target;
                    const originalBg = textarea.style.backgroundColor;
                    textarea.style.backgroundColor = '#d4edda';
                    setTimeout(() => {
                        textarea.style.backgroundColor = originalBg;
                    }, 1000);
                }
            })
            .catch(error => {
                console.error('Error saving notes:', error);
            });
        }
        
        function saveRemark(field, textarea) {
            if (textarea.value === textarea.defaultValue) {
                return;
            }

            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'save_remark',
                    field: field,
                    value: textarea.value,
                    _csrf: csrfToken
                })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    textarea.defaultValue = textarea.value;
                    const originalBg = textarea.style.backgroundColor;
                    textarea.style.backgroundColor = '#d4edda';
                    setTimeout(() => {
                        textarea.style.backgroundColor = originalBg;
                    }, 1000);
                } else {
                    alert(data.message || 'Error saving remarks');
                }
            })
            .catch(error => {
                console.error('Error saving remarks:', error);
                alert('Error saving remarks: ' + error.message);
            });
        }

        function toggleVerification(field) {
            const button = event.target.closest('button');
            button.disabled = true;
            
            const currentValue = <?= json_encode([
                'contract_verified' => $moveIn['contract_verified'],
                'payment_confirmed' => $moveIn['payment_confirmed'],
                'keys_handed_over' => $moveIn['keys_handed_over'],
                'inspection_completed' => $moveIn['inspection_completed']
            ]) ?>[field];
            
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'update_verification',
                    field: field,
                    value: currentValue ? 0 : 1,
                    _csrf: csrfToken
                })
            })
            .then(r => {
                if (!r.ok) {
                    throw new Error('Network response was not ok');
                }
                return r.json();
            })
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    button.disabled = false;
                    alert(data.message || 'Error updating verification');
                }
            })
            .catch(error => {
                button.disabled = false;
                console.error('Error:', error);
                alert('Error updating verification: ' + error.message);
            });
        }
        
        function updateProgressBar() {
            // Count completed items
            const checkboxes = document.querySelectorAll('.checklist-checkbox');
            const total = checkboxes.length;
            const completed = Array.from(checkboxes).filter(cb => cb.checked).length;
            const percent = total > 0 ? Math.round((completed / total) * 100) : 0;
            
            // Update progress bar
            const progressBar = document.querySelector('.progress-bar');
            if (progressBar) {
                progressBar.style.width = percent + '%';
                progressBar.textContent = percent + '% Complete (' + completed + '/' + total + ' items)';
                progressBar.className = 'progress-bar ' + (percent == 100 ? 'bg-success' : (percent >= 50 ? 'bg-info' : 'bg-warning'));
            }
        }
        
        const meterReadingForm = document.getElementById('meterReadingForm');
        if (meterReadingForm) {
            meterReadingForm.addEventListener('submit', function(e) {
                e.preventDefault();
                const submitBtn = this.querySelector('button[type="submit"]');
                const originalText = submitBtn.innerHTML;
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Saving...';
                
                const formData = new FormData(this);
                formData.append('action', 'save_meter_reading');
                formData.append('_csrf', csrfToken);
                
                fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(r => {
                    if (!r.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return r.json();
                })
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = originalText;
                        alert(data.message || 'Error saving meter reading');
                    }
                })
                .catch(error => {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalText;
                    console.error('Error:', error);
                    alert('Error saving meter reading: ' + error.message);
                });
            });
        }
        
        document.querySelectorAll('.move-in-upload-form').forEach(function(form) {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                const submitBtn = this.querySelector('button[type="submit"]');
                const originalText = submitBtn.innerHTML;
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Uploading...';

                const formData = new FormData(this);
                formData.append('action', 'upload_files');
                formData.append('file_type', this.dataset.fileType);
                formData.append('_csrf', csrfToken);

                fetch('', {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                })
                .then(r => r.text().then(t => {
                    try { return JSON.parse(t); } catch (e) {
                        // Not JSON: session expired, file over the server limit, or a server error
                        if (r.status === 413) throw new Error('The files are larger than the server accepts. Upload fewer or smaller files.');
                        if (r.status === 419) throw new Error('Your session expired. Reload the page and try again.');
                        throw new Error((t.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 200)) || ('Server error ' + r.status));
                    }
                }))
                .then(data => {
                    if (data.success) {
                        if (data.has_errors) {
                            alert(data.message);
                        }
                        location.reload();
                    } else {
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = originalText;
                        alert(data.message || 'Error uploading file');
                    }
                })
                .catch(error => {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalText;
                    console.error('Error:', error);
                    alert('Error uploading file: ' + error.message);
                });
            });
        });

        function deleteMoveInFile(fileId) {
            if (!confirm('Remove this file?')) {
                return;
            }

            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'delete_file',
                    file_id: fileId,
                    _csrf: csrfToken
                })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert(data.message || 'Error removing file');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error removing file: ' + error.message);
            });
        }

        function completeMoveIn() {
            if (!confirm('Are you sure you want to complete this move-in? This will mark the unit as occupied.')) {
                return;
            }
            
            const btn = event.target.closest('button');
            const originalText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Processing...';
            
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'complete_move_in',
                    _csrf: csrfToken
                })
            })
            .then(r => {
                if (!r.ok) {
                    throw new Error('Network response was not ok');
                }
                return r.json();
            })
            .then(data => {
                if (data.success) {
                    alert(data.message || 'Move-in completed successfully!');
                    if (data.redirect) {
                        window.location.href = data.redirect;
                    } else {
                        location.reload();
                    }
                } else {
                    btn.disabled = false;
                    btn.innerHTML = originalText;
                    alert(data.message || 'Error completing move-in');
                }
            })
            .catch(error => {
                btn.disabled = false;
                btn.innerHTML = originalText;
                console.error('Error:', error);
                alert('Error completing move-in: ' + error.message);
            });
        }
        
        // Initialize progress bar on page load
        document.addEventListener('DOMContentLoaded', function() {
            updateProgressBar();
        });
    </script>

<?= re_datepicker_assets() ?>
<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>



