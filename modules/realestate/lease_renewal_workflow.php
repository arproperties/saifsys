<?php
/**
 * Real Estate Module - Lease Renewal Workflow
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/includes/lease_vat_calculator.php';
require_once __DIR__ . '/includes/lease_number_sequence.php';
require_once __DIR__ . '/includes/lease_lifecycle_guard.php';
require_once __DIR__ . '/includes/renewal_service.php';
require_once __DIR__ . '/../../includes/table_sort.php';

require_login();
require_module_access($conn, MODULE_REALESTATE);

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;
$userId = current_user_id();
re_lease_activate_due_renewals($conn, $currentCompanyId, $userId);

$success = '';
$error = '';
$reopenInitiateModal = false;
$initiateForm = [
    'lease_id' => '',
    'target_renewal_date' => '',
    'assigned_to' => '',
    'increase_type' => 'percentage',
    'increase_value' => '5.00',
    'proposed_rent' => '',
    'chiller_charges' => '0.00',
    'admin_fees' => '',
    'number_of_cheques' => '4',
    'proposed_start_date' => '',
    'proposed_end_date' => '',
    'additional_parking_enabled' => '0',
    'additional_parking_fee' => '',
    'small_store_enabled' => '0',
    'small_store_fee' => '',
    'big_store_enabled' => '0',
    'big_store_fee' => '',
    'terms_text' => '',
    'required_documents_text' => ''
];

function handleRenewalStampUpload(string $inputName, string $targetBaseDir): ?string {
    if (empty($_FILES[$inputName]['tmp_name'])) {
        return null;
    }
    $f = $_FILES[$inputName];
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }
    $ext = strtolower(pathinfo((string)($f['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp'], true)) {
        return null;
    }
    if (!is_dir($targetBaseDir)) {
        mkdir($targetBaseDir, 0755, true);
    }
    $filename = 'renewal_stamp_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $abs = rtrim($targetBaseDir, '/') . '/' . $filename;
    if (!move_uploaded_file($f['tmp_name'], $abs)) {
        return null;
    }
    return str_replace(__DIR__ . '/../../', '', $abs);
}

function renewalExpiringWindowSettingKey(int $companyId): string {
    return 're_renewal_expiring_window_days_company_' . $companyId;
}

function renewalClampExpiringWindowDays(int $days): int {
    return max(1, min(730, $days));
}

function renewalGetExpiringWindowDays(PDO $conn, int $companyId): int {
    try {
        $stmt = $conn->prepare("SELECT `value` FROM settings WHERE `key` = ? LIMIT 1");
        $stmt->execute([renewalExpiringWindowSettingKey($companyId)]);
        $value = $stmt->fetchColumn();
        if ($value !== false && $value !== null && trim((string)$value) !== '') {
            return renewalClampExpiringWindowDays((int)$value);
        }
    } catch (Throwable $e) {
        error_log('renewalGetExpiringWindowDays failed: ' . $e->getMessage());
    }
    return 120;
}

function renewalSaveExpiringWindowDays(PDO $conn, int $companyId, int $days): void {
    $days = renewalClampExpiringWindowDays($days);
    $stmt = $conn->prepare("
        INSERT INTO settings (`key`, `value`)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)
    ");
    $stmt->execute([renewalExpiringWindowSettingKey($companyId), (string)$days]);
}

$defaultTermsText = re_renewal_default_terms();
$defaultDocumentsText = re_renewal_default_documents();

$renewalExpiringWindowDays = renewalGetExpiringWindowDays($conn, $currentCompanyId);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    
    $action = $_POST['action'] ?? '';
    $workflowId = !empty($_POST['workflow_id']) ? (int)$_POST['workflow_id'] : null;
    $leaseId = !empty($_POST['lease_id']) ? (int)$_POST['lease_id'] : null;
    
    if ($action === 'update_expiring_window') {
        try {
            $days = renewalClampExpiringWindowDays((int)($_POST['renewal_expiring_window_days'] ?? 120));
            renewalSaveExpiringWindowDays($conn, $currentCompanyId, $days);
            $renewalExpiringWindowDays = $days;
            $success = 'Renewal lease selection window updated to ' . $days . ' day(s).';
        } catch (Throwable $e) {
            $error = 'Could not update renewal window: ' . $e->getMessage();
        }
    } elseif ($action === 'initiate' && $leaseId) {
        foreach ($initiateForm as $k => $v) {
            if (isset($_POST[$k])) {
                $initiateForm[$k] = is_string($_POST[$k]) ? trim($_POST[$k]) : (string)$_POST[$k];
            }
        }
        $initiateForm['additional_parking_enabled'] = !empty($_POST['additional_parking_enabled']) ? '1' : '0';
        $initiateForm['small_store_enabled'] = !empty($_POST['small_store_enabled']) ? '1' : '0';
        $initiateForm['big_store_enabled'] = !empty($_POST['big_store_enabled']) ? '1' : '0';

        // A lease Reem (Jarvis) is already renewing with the tenant by email is left to Reem.
        $reemOpen = $conn->prepare("SELECT rw.id FROM re_lease_renewal_workflows rw
            WHERE rw.lease_id = ? AND rw.status NOT IN ('rejected', 'converted')
              AND EXISTS (SELECT 1 FROM re_renewal_portal_events e WHERE e.workflow_id = rw.id AND e.event_type = 'reem_started')
            LIMIT 1");
        $reemOpen->execute([(int)$leaseId]);
        if ($reemOpen->fetchColumn()) {
            $error = 'Reem is already renewing this lease with the tenant by email. Open Lease renewals in the Reem app.';
        } else
        try {
            $made = null;
            $conn->beginTransaction();
            $made = re_renewal_create($conn, (int)$currentCompanyId, (int)$userId, (int)$leaseId, [
                'target_renewal_date' => $_POST['target_renewal_date'] ?? null, // optional (kept for backward compatibility)
                'increase_type' => $_POST['increase_type'] ?? 'percentage',
                'increase_value' => $_POST['increase_value'] ?? '',
                'proposed_rent' => $_POST['proposed_rent'] ?? '',
                'admin_fees' => $_POST['admin_fees'] ?? '',
                'chiller_charges' => $_POST['chiller_charges'] ?? '',
                'number_of_cheques' => $_POST['number_of_cheques'] ?? '',
                'additional_parking_enabled' => !empty($_POST['additional_parking_enabled']),
                'additional_parking_fee' => $_POST['additional_parking_fee'] ?? '',
                'small_store_enabled' => !empty($_POST['small_store_enabled']),
                'small_store_fee' => $_POST['small_store_fee'] ?? '',
                'big_store_enabled' => !empty($_POST['big_store_enabled']),
                'big_store_fee' => $_POST['big_store_fee'] ?? '',
                'terms_text' => $_POST['terms_text'] ?? '',
                'required_documents_text' => $_POST['required_documents_text'] ?? '',
                'proposed_start_date' => $_POST['proposed_start_date'] ?? '',
                'proposed_end_date' => $_POST['proposed_end_date'] ?? '',
                'assigned_to' => $_POST['assigned_to'] ?? '',
                'stamp_image_path' => handleRenewalStampUpload('stamp_image', __DIR__ . '/../../uploads/renewal_notices/stamps'),
            ]);
            $conn->commit();
            $workflowNewId = (int)$made['workflow_id'];
            $newLeaseId = (int)$made['new_lease_id'];

            try {
                require_once __DIR__ . '/../../includes/audit_bridge.php';
                audit_bridge_re_ops(
                    $conn,
                    (int)$currentCompanyId,
                    'renewal_initiated',
                    're_lease_renewal_workflows',
                    (int)$workflowNewId,
                    'Renewal #' . (int)$workflowNewId,
                    'Started renewal workflow #' . (int)$workflowNewId
                        . ' for lease #' . (int)$leaseId
                        . ($newLeaseId ? ('; draft lease #' . (int)$newLeaseId) : ''),
                    null,
                    [
                        'old_lease_id' => (int)$leaseId,
                        'new_lease_id' => (int)$newLeaseId,
                    ],
                    (int)($_SESSION['user']['id'] ?? 0) ?: null
                );
            } catch (Throwable $e) {
                error_log('renewal_initiate audit: ' . $e->getMessage());
            }

            $success = "Renewal workflow initiated. Draft lease created.";
        } catch (Exception $e) {
            if ($conn && $conn->inTransaction()) {
                $conn->rollBack();
            }
            $reopenInitiateModal = true;
            $error = "Error: " . $e->getMessage();
        }
    } elseif ($action === 'update_step' && $workflowId) {
        $workflowStep = $_POST['workflow_step'] ?? '';
        $tenantResponse = trim($_POST['tenant_response'] ?? '');
        $negotiationNotes = trim($_POST['negotiation_notes'] ?? '');
        $newLeaseId = !empty($_POST['new_lease_id']) ? (int)$_POST['new_lease_id'] : null;
        
        try {
            $updateFields = ["workflow_step = ?"];
            $params = [$workflowStep, $workflowId];
            
            if ($tenantResponse) {
                $updateFields[] = "tenant_response = ?";
                $updateFields[] = "tenant_response_date = CURDATE()";
                $params = array_merge([$workflowStep, $tenantResponse], array_slice($params, 1));
            }
            
            if ($negotiationNotes) {
                $updateFields[] = "negotiation_notes = ?";
                $params[] = $negotiationNotes;
            }
            
            if ($newLeaseId) {
                $updateFields[] = "new_lease_id = ?";
                $params[] = $newLeaseId;
            }
            
            $stmt = $conn->prepare("
                UPDATE re_lease_renewal_workflows 
                SET " . implode(', ', $updateFields) . "
                WHERE id = ?
            ");
            $stmt->execute($params);
            $success = "Workflow updated successfully";
            try {
                require_once __DIR__ . '/../../includes/audit_bridge.php';
                audit_bridge_re_ops(
                    $conn,
                    (int)$currentCompanyId,
                    'renewal_updated',
                    're_lease_renewal_workflows',
                    (int)$workflowId,
                    'Renewal #' . (int)$workflowId,
                    'Updated renewal workflow #' . (int)$workflowId
                        . ($workflowStep !== '' ? (' — step: ' . $workflowStep) : ''),
                    null,
                    [
                        'workflow_step' => $workflowStep,
                        'new_lease_id' => $newLeaseId,
                    ],
                    (int)($_SESSION['user']['id'] ?? 0) ?: null
                );
            } catch (Throwable $e) {
                error_log('renewal_update_step audit: ' . $e->getMessage());
            }
        } catch (Exception $e) {
            $error = "Error: " . $e->getMessage();
        }
    }
}

// Filters for renewal workflow list
$filterSearch = trim((string)($_GET['q'] ?? ''));
$filterStatus = trim((string)($_GET['status'] ?? ''));
$filterAssignee = trim((string)($_GET['assigned_to'] ?? ''));
$filterExpiry = trim((string)($_GET['expiry'] ?? ''));

$allowedStatusFilters = [
    '',
    'open',
    'initiated',
    'notice_sent',
    'pending_response',
    'negotiation',
    'approved',
    'contract_ready',
    'converted',
    'rejected',
    'completed',
    'cancelled',
];
if (!in_array($filterStatus, $allowedStatusFilters, true)) {
    $filterStatus = '';
}
$allowedExpiryFilters = ['', 'expired', '30', '60', '90', 'future'];
if (!in_array($filterExpiry, $allowedExpiryFilters, true)) {
    $filterExpiry = '';
}

$workflowWhere = ["l.company_id = ?"];
$workflowParams = [$currentCompanyId];

if ($filterSearch !== '') {
    $searchLike = '%' . $filterSearch . '%';
    $workflowWhere[] = "(
        l.lease_number LIKE ?
        OR u.unit_number LIKE ?
        OR b.name LIKE ?
        OR t.first_name LIKE ?
        OR t.last_name LIKE ?
        OR t.email LIKE ?
        OR u2.username LIKE ?
    )";
    array_push($workflowParams, $searchLike, $searchLike, $searchLike, $searchLike, $searchLike, $searchLike, $searchLike);
}

if ($filterStatus === 'open') {
    $workflowWhere[] = "COALESCE(rw.status, rw.workflow_step, 'initiated') NOT IN ('converted', 'rejected', 'completed', 'cancelled')";
} elseif ($filterStatus !== '') {
    $workflowWhere[] = "COALESCE(rw.status, rw.workflow_step, 'initiated') = ?";
    $workflowParams[] = $filterStatus;
}

if ($filterAssignee === 'unassigned') {
    $workflowWhere[] = "rw.assigned_to IS NULL";
} elseif ($filterAssignee !== '' && ctype_digit($filterAssignee)) {
    $workflowWhere[] = "rw.assigned_to = ?";
    $workflowParams[] = (int)$filterAssignee;
}

if ($filterExpiry === 'expired') {
    $workflowWhere[] = "l.end_date < CURDATE()";
} elseif (in_array($filterExpiry, ['30', '60', '90'], true)) {
    $workflowWhere[] = "l.end_date >= CURDATE() AND l.end_date <= DATE_ADD(CURDATE(), INTERVAL " . (int)$filterExpiry . " DAY)";
} elseif ($filterExpiry === 'future') {
    $workflowWhere[] = "l.end_date > DATE_ADD(CURDATE(), INTERVAL 90 DAY)";
}

$workflowWhereSql = implode("\n      AND ", $workflowWhere);

// Summary cards use all renewal workflows for this company, not only the filtered view.
$workflowStatsStmt = $conn->prepare("
    SELECT
        COUNT(*) AS total_workflows,
        SUM(CASE WHEN COALESCE(rw.status, rw.workflow_step, 'initiated') NOT IN ('converted', 'rejected', 'completed', 'cancelled') THEN 1 ELSE 0 END) AS open_workflows,
        SUM(CASE WHEN COALESCE(rw.status, rw.workflow_step, 'initiated') = 'notice_sent' THEN 1 ELSE 0 END) AS notice_sent,
        SUM(CASE WHEN COALESCE(rw.status, rw.workflow_step, 'initiated') IN ('pending_response', 'negotiation') THEN 1 ELSE 0 END) AS needs_followup,
        SUM(CASE WHEN COALESCE(rw.status, rw.workflow_step, 'initiated') = 'converted' THEN 1 ELSE 0 END) AS converted,
        SUM(CASE WHEN rw.assigned_to IS NULL AND COALESCE(rw.status, rw.workflow_step, 'initiated') NOT IN ('converted', 'rejected', 'completed', 'cancelled') THEN 1 ELSE 0 END) AS unassigned_open,
        SUM(CASE WHEN l.end_date >= CURDATE() AND l.end_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                  AND COALESCE(rw.status, rw.workflow_step, 'initiated') NOT IN ('converted', 'rejected', 'completed', 'cancelled')
                 THEN 1 ELSE 0 END) AS expiring_30
    FROM re_lease_renewal_workflows rw
    JOIN re_leases l ON l.id = rw.lease_id
    WHERE l.company_id = ?
");
$workflowStatsStmt->execute([$currentCompanyId]);
$workflowStats = $workflowStatsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

// Get renewal workflows
$workflows = $conn->prepare("
    SELECT rw.*, 
           l.lease_number, l.end_date as lease_end_date,
           u.unit_number, u.unit_type,
           b.name as building_name,
           t.first_name, t.last_name, t.email,
           u2.username as assigned_to_name,
           DATEDIFF(l.end_date, CURDATE()) AS days_to_expiry
    FROM re_lease_renewal_workflows rw
    JOIN re_leases l ON l.id = rw.lease_id
    JOIN re_units u ON u.id = l.unit_id
    JOIN re_buildings b ON b.id = u.building_id
    JOIN re_tenants t ON t.id = l.tenant_id
    LEFT JOIN user u2 ON u2.id = rw.assigned_to
    WHERE $workflowWhereSql
    ORDER BY
        CASE WHEN COALESCE(rw.status, rw.workflow_step, 'initiated') NOT IN ('converted', 'rejected', 'completed', 'cancelled') THEN 0 ELSE 1 END ASC,
        l.end_date ASC,
        rw.initiated_date DESC
");
$workflows->execute($workflowParams);
$workflows = $workflows->fetchAll(PDO::FETCH_ASSOC);

$renewalPriorityWindowDays = min(60, $renewalExpiringWindowDays);

// Get leases expiring soon for initiation
$expiringLeases = $conn->prepare("
    SELECT l.*, 
           u.unit_number, u.unit_type,
           u.building_id,
           b.name as building_name,
           t.first_name, t.last_name
           ,COALESCE(bs.template_code, 'renewal_notice_unified_v1') AS setting_template_code
           ,COALESCE(bs.show_chiller_row, CASE WHEN b.name LIKE '%Park Place%' THEN 1 ELSE 0 END) AS setting_show_chiller_row
           ,COALESCE(bs.show_chiller_term, CASE WHEN b.name LIKE '%Park Place%' THEN 1 ELSE 0 END) AS setting_show_chiller_term
           ,COALESCE(bs.admin_fee_label, CASE WHEN b.name LIKE '%Park Place%' THEN 'Administrative Charges' ELSE 'Lease Renewal Charges' END) AS setting_admin_fee_label
           ,COALESCE(bs.default_parking_fee, 0) AS setting_default_parking_fee
           ,COALESCE(bs.default_small_store_fee, 0) AS setting_default_small_store_fee
           ,COALESCE(bs.default_big_store_fee, 0) AS setting_default_big_store_fee
           ,COALESCE(bs.vat_enabled, 1) AS setting_vat_enabled
           ,COALESCE(bs.vat_rate, 5.00) AS setting_vat_rate
           ,COALESCE(bs.terms_text, ?) AS setting_terms_text
           ,COALESCE(bs.required_documents_text, ?) AS setting_required_documents_text
           ,COALESCE(bs.show_grand_total_row, 0) AS setting_show_grand_total_row
    FROM re_leases l
    JOIN re_units u ON u.id = l.unit_id
    JOIN re_buildings b ON b.id = u.building_id
    JOIN re_tenants t ON t.id = l.tenant_id
    LEFT JOIN re_renewal_notice_building_settings bs ON bs.company_id = l.company_id AND bs.building_id = u.building_id
    WHERE l.company_id = ?
    AND l.status = 'active'
    AND l.end_date >= CURDATE()
    AND l.end_date <= DATE_ADD(CURDATE(), INTERVAL {$renewalExpiringWindowDays} DAY)
    AND NOT EXISTS (
        SELECT 1 FROM re_lease_renewal_workflows rw 
        WHERE rw.lease_id = l.id 
        AND (rw.status NOT IN ('converted', 'rejected') OR rw.status IS NULL)
    )
    ORDER BY 
        CASE WHEN l.end_date <= DATE_ADD(CURDATE(), INTERVAL {$renewalPriorityWindowDays} DAY) THEN 0 ELSE 1 END ASC,
        l.end_date ASC
");
$expiringLeases->execute([$defaultTermsText, $defaultDocumentsText, $currentCompanyId]);
$expiringLeases = $expiringLeases->fetchAll(PDO::FETCH_ASSOC);

// Get users for assignment
$users = $conn->prepare("
    SELECT DISTINCT u.id, u.username, u.email
    FROM user u
    JOIN user_companies uc ON uc.user_id = u.id
    JOIN user_roles ur ON ur.user_id = u.id
    JOIN roles r ON r.id = ur.role_id
    WHERE uc.company_id = ? 
    AND (r.module = 'realestate' OR r.name IN ('Owner', 'Admin'))
    ORDER BY u.username
");
$users->execute([$currentCompanyId]);
$users = $users->fetchAll(PDO::FETCH_ASSOC);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$renewalStatusLabels = [
    'initiated' => 'Initiated',
    'notice_sent' => 'Notice Sent',
    'pending_response' => 'Pending Response',
    'negotiation' => 'Negotiation',
    'approved' => 'Approved',
    'contract_ready' => 'Contract Ready',
    'rejected' => 'Rejected',
    'converted' => 'Converted',

    // Backward compatibility (old workflow_step values)
    'terms_reviewed' => 'Terms Reviewed',
    'tenant_notified' => 'Tenant Notified',
    'tenant_response' => 'Tenant Responded',
    'terms_negotiated' => 'Terms Negotiated',
    'renewal_approved' => 'Renewal Approved',
    'new_lease_created' => 'New Lease Created',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled'
];
$renewalStatusColors = [
    'initiated' => 'info',
    'notice_sent' => 'warning',
    'pending_response' => 'primary',
    'negotiation' => 'info',
    'approved' => 'success',
    'contract_ready' => 'primary',
    'rejected' => 'danger',
    'converted' => 'success',

    // Backward compatibility
    'terms_reviewed' => 'primary',
    'tenant_notified' => 'warning',
    'tenant_response' => 'success',
    'terms_negotiated' => 'info',
    'renewal_approved' => 'success',
    'new_lease_created' => 'success',
    'completed' => 'success',
    'cancelled' => 'danger'
];

$exportQuery = $_GET;
$exportQuery['export'] = 'csv';
$exportUrl = 'lease_renewal_workflow.php?' . http_build_query($exportQuery);

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="lease-renewals-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Lease', 'Building', 'Unit', 'Tenant', 'Email', 'Lease Expiry', 'Days to Expiry', 'Status', 'Initiated', 'Assigned To', 'Current Rent', 'Proposed Rent', 'Proposed Start', 'Proposed End', 'Tenant Response']);
    foreach ($workflows as $wf) {
        $wfStatus = $wf['status'] ?? $wf['workflow_step'] ?? 'initiated';
        fputcsv($out, [
            $wf['lease_number'] ?: 'L-' . $wf['lease_id'],
            $wf['building_name'],
            $wf['unit_number'],
            trim($wf['first_name'] . ' ' . $wf['last_name']),
            $wf['email'],
            !empty($wf['lease_end_date']) ? date('Y-m-d', strtotime($wf['lease_end_date'])) : '',
            $wf['days_to_expiry'],
            $renewalStatusLabels[$wfStatus] ?? ucfirst(str_replace('_', ' ', $wfStatus)),
            !empty($wf['initiated_date']) ? date('Y-m-d', strtotime($wf['initiated_date'])) : '',
            $wf['assigned_to_name'] ?? '',
            $wf['current_rent'],
            $wf['proposed_rent'],
            $wf['proposed_start_date'],
            $wf['proposed_end_date'],
            $wf['tenant_response'],
        ]);
    }
    exit;
}

// Set page title and include layout
$pageTitle = 'Lease Renewal Workflow';
require_once __DIR__ . '/includes/re_layout_header.php';
?>

        <style>
            .renewal-stat-card {
                border: 0;
                border-radius: 14px;
                box-shadow: 0 10px 25px rgba(15, 23, 42, .07);
            }
            .renewal-stat-label {
                color: #64748b;
                font-size: .78rem;
                font-weight: 700;
                letter-spacing: .04em;
                text-transform: uppercase;
            }
            .renewal-stat-value {
                color: #0f172a;
                font-size: 1.45rem;
                font-weight: 800;
            }
            .renewal-filter-card {
                border: 1px solid rgba(148, 163, 184, .28);
                border-radius: 14px;
                box-shadow: 0 8px 22px rgba(15, 23, 42, .05);
            }
            .renewal-table td,
            .renewal-table th {
                vertical-align: middle;
            }
            .renewal-lease-link {
                color: #0f4c81;
                font-weight: 800;
                text-decoration: none;
            }
            .renewal-lease-link:hover {
                text-decoration: underline;
            }
        </style>

        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
            <div>
                <div class="page-header-label">Lease Renewal Workflow</div>
                <div class="text-muted small">Track renewals, notices, tenant responses, and converted leases.</div>
            </div>
            <div class="d-flex align-items-end gap-2 flex-wrap justify-content-end">
                <form method="POST" class="d-flex align-items-end gap-2 flex-wrap">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="update_expiring_window">
                    <div>
                        <label class="form-label small mb-1">Lease selection window</label>
                        <div class="input-group input-group-sm" style="width: 160px;">
                            <input
                                type="number"
                                min="1"
                                max="730"
                                name="renewal_expiring_window_days"
                                class="form-control"
                                value="<?= (int)$renewalExpiringWindowDays ?>"
                            >
                            <span class="input-group-text">days</span>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-sm btn-outline-primary">Save</button>
                </form>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#initiateModal">
                    <i class="bi bi-plus-circle"></i> Initiate Renewal
                </button>
            </div>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <?= h($success) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <?= h($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row g-3 mb-4">
            <div class="col-sm-6 col-xl-3">
                <div class="card renewal-stat-card h-100">
                    <div class="card-body">
                        <div class="renewal-stat-label">Open Workflows</div>
                        <div class="renewal-stat-value"><?= number_format((int)($workflowStats['open_workflows'] ?? 0)) ?></div>
                        <div class="text-muted small">Total workflows: <?= number_format((int)($workflowStats['total_workflows'] ?? 0)) ?></div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="card renewal-stat-card h-100">
                    <div class="card-body">
                        <div class="renewal-stat-label">Notice Sent</div>
                        <div class="renewal-stat-value text-warning"><?= number_format((int)($workflowStats['notice_sent'] ?? 0)) ?></div>
                        <div class="text-muted small">Waiting for tenant follow-up.</div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="card renewal-stat-card h-100">
                    <div class="card-body">
                        <div class="renewal-stat-label">Needs Follow-Up</div>
                        <div class="renewal-stat-value text-primary"><?= number_format((int)($workflowStats['needs_followup'] ?? 0)) ?></div>
                        <div class="text-muted small">Pending response or negotiation.</div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="card renewal-stat-card h-100">
                    <div class="card-body">
                        <div class="renewal-stat-label">Converted</div>
                        <div class="renewal-stat-value text-success"><?= number_format((int)($workflowStats['converted'] ?? 0)) ?></div>
                        <div class="text-muted small"><?= number_format((int)($workflowStats['expiring_30'] ?? 0)) ?> open lease(s) expire within 30 days.</div>
                    </div>
                </div>
            </div>
        </div>

        <?php if ((int)($workflowStats['unassigned_open'] ?? 0) > 0): ?>
            <div class="alert alert-warning d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <i class="bi bi-person-exclamation"></i>
                    <strong><?= (int)$workflowStats['unassigned_open'] ?> open renewal workflow(s)</strong> are unassigned.
                </div>
                <a class="btn btn-sm btn-outline-dark" href="lease_renewal_workflow.php?assigned_to=unassigned&status=open">
                    View unassigned
                </a>
            </div>
        <?php endif; ?>

        <div class="card renewal-filter-card mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3 align-items-end">
                    <div class="col-lg-4">
                        <label class="form-label">Search</label>
                        <input type="search" name="q" class="form-control" value="<?= h($filterSearch) ?>" placeholder="Lease, tenant, unit, building, assignee...">
                    </div>
                    <div class="col-md-4 col-lg-2">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="">All statuses</option>
                            <option value="open" <?= $filterStatus === 'open' ? 'selected' : '' ?>>Open only</option>
                            <?php foreach (['initiated', 'notice_sent', 'pending_response', 'negotiation', 'approved', 'contract_ready', 'converted', 'rejected'] as $statusOption): ?>
                                <option value="<?= h($statusOption) ?>" <?= $filterStatus === $statusOption ? 'selected' : '' ?>>
                                    <?= h($renewalStatusLabels[$statusOption] ?? ucfirst(str_replace('_', ' ', $statusOption))) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4 col-lg-2">
                        <label class="form-label">Assigned To</label>
                        <select name="assigned_to" class="form-select">
                            <option value="">Anyone</option>
                            <option value="unassigned" <?= $filterAssignee === 'unassigned' ? 'selected' : '' ?>>Unassigned</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?= (int)$user['id'] ?>" <?= $filterAssignee === (string)$user['id'] ? 'selected' : '' ?>>
                                    <?= h($user['username']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4 col-lg-2">
                        <label class="form-label">Lease Expiry</label>
                        <select name="expiry" class="form-select">
                            <option value="">Any date</option>
                            <option value="expired" <?= $filterExpiry === 'expired' ? 'selected' : '' ?>>Expired</option>
                            <option value="30" <?= $filterExpiry === '30' ? 'selected' : '' ?>>Next 30 days</option>
                            <option value="60" <?= $filterExpiry === '60' ? 'selected' : '' ?>>Next 60 days</option>
                            <option value="90" <?= $filterExpiry === '90' ? 'selected' : '' ?>>Next 90 days</option>
                            <option value="future" <?= $filterExpiry === 'future' ? 'selected' : '' ?>>After 90 days</option>
                        </select>
                    </div>
                    <div class="col-lg-2 d-flex gap-2">
                        <button type="submit" class="btn btn-primary flex-fill">
                            <i class="bi bi-funnel"></i> Filter
                        </button>
                        <a href="lease_renewal_workflow.php" class="btn btn-outline-secondary">Reset</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                    <div>
                        <h5 class="mb-0">Renewal Workflows</h5>
                        <small class="text-muted">Showing <?= number_format(count($workflows)) ?> workflow(s)</small>
                    </div>
                    <a href="<?= h($exportUrl) ?>" class="btn btn-sm btn-outline-success">
                        <i class="bi bi-file-earmark-spreadsheet"></i> Export CSV
                    </a>
                </div>
                <?= table_sort_assets() ?>
                <div class="table-responsive">
                    <table class="table table-hover renewal-table" data-sortable>
                        <thead>
                            <tr>
                                <th>Lease</th>
                                <th>Unit</th>
                                <th>Tenant</th>
                                <th data-sort="date">Expiry</th>
                                <th data-sort="number">Current Status</th>
                                <th data-sort="date">Initiated</th>
                                <th>Assigned To</th>
                                <th data-sort="none">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($workflows)): ?>
                                <tr data-no-sort>
                                    <td colspan="8" class="text-center text-muted py-4">
                                        <i class="bi bi-search"></i> No renewal workflows match the selected filters.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($workflows as $wf):
                                    $wfStatus = $wf['status'] ?? $wf['workflow_step'] ?? 'initiated';
                                    // Sort the status column by where it sits in the workflow, not alphabetically.
                                    $statusRank = array_search($wfStatus, array_keys($renewalStatusLabels), true);
                                    $statusRank = $statusRank === false ? 99 : $statusRank;
                                ?>
                                    <tr>
                                        <td data-sort-value="<?= h($wf['lease_number'] ?: 'L-' . $wf['lease_id']) ?>">
                                            <a href="lease_view.php?id=<?= $wf['lease_id'] ?>" class="renewal-lease-link">
                                                <strong><?= h($wf['lease_number'] ?: 'L-' . $wf['lease_id']) ?></strong>
                                            </a>
                                        </td>
                                        <td><?= h($wf['building_name'] . ' - ' . $wf['unit_number']) ?></td>
                                        <td data-sort-value="<?= h(trim($wf['first_name'] . ' ' . $wf['last_name'])) ?>">
                                            <strong><?= h($wf['first_name'] . ' ' . $wf['last_name']) ?></strong>
                                            <?php if (!empty($wf['email'])): ?>
                                                <br><small class="text-muted"><?= h($wf['email']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td data-sort-value="<?= h(date('Y-m-d', strtotime($wf['lease_end_date']))) ?>">
                                            <?= date('Y-m-d', strtotime($wf['lease_end_date'])) ?>
                                            <?php
                                                $daysToExpiry = isset($wf['days_to_expiry']) ? (int)$wf['days_to_expiry'] : null;
                                                if ($daysToExpiry !== null) {
                                                    if ($daysToExpiry < 0) {
                                                        echo '<br><span class="badge bg-danger">Expired</span>';
                                                    } elseif ($daysToExpiry <= 30) {
                                                        echo '<br><span class="badge bg-warning text-dark">' . $daysToExpiry . ' day(s)</span>';
                                                    } else {
                                                        echo '<br><small class="text-muted">' . $daysToExpiry . ' day(s)</small>';
                                                    }
                                                }
                                            ?>
                                        </td>
                                        <td data-sort-value="<?= (int)$statusRank ?>">
                                            <?php
                                            $color = $renewalStatusColors[$wfStatus] ?? 'secondary';
                                            ?>
                                            <span class="badge bg-<?= $color ?>"><?= h($renewalStatusLabels[$wfStatus] ?? ucfirst(str_replace('_', ' ', $wfStatus))) ?></span>
                                        </td>
                                        <td><?= date('Y-m-d', strtotime($wf['initiated_date'])) ?></td>
                                        <td data-sort-value="<?= h($wf['assigned_to_name'] ?? '') ?>"><?= h($wf['assigned_to_name'] ?? '-') ?></td>
                                        <td>
                                            <a href="lease_renewal_workflow_view.php?id=<?= $wf['id'] ?>" class="btn btn-sm btn-outline-primary">
                                                <i class="bi bi-eye"></i> View
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Initiate Renewal Modal -->
        <div class="modal fade" id="initiateModal" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Initiate Renewal Workflow</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form method="POST" enctype="multipart/form-data">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="action" value="initiate">
                        
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label">Lease *</label>
                                <select name="lease_id" class="form-select" required>
                                    <option value="">-- Select Lease --</option>
                                    <?php foreach ($expiringLeases as $lease): ?>
                                        <?php
                                            $currentRentTpl = $lease['annual_rent'] !== null && $lease['annual_rent'] !== ''
                                                ? (float)$lease['annual_rent']
                                                : (float)($lease['monthly_rent'] ?? 0) * 12;
                                            $chillerFeesTpl = !empty($lease['chiller_fees']) ? (float)$lease['chiller_fees'] : 0.0;
                                            $adminFeesTpl = !empty($lease['admin_fees']) ? (float)$lease['admin_fees'] : 0.0;
                                        ?>
                                        <option value="<?= (int)$lease['id'] ?>"
                                            <?= ((string)$initiateForm['lease_id'] === (string)$lease['id']) ? 'selected' : '' ?>
                                            data-building-name="<?= h($lease['building_name'] ?? '') ?>"
                                            data-current-rent="<?= number_format((float)$currentRentTpl, 2, '.', '') ?>"
                                            data-chiller-fees="<?= number_format((float)$chillerFeesTpl, 2, '.', '') ?>"
                                            data-admin-fees="<?= number_format((float)$adminFeesTpl, 2, '.', '') ?>"
                                            data-number-of-cheques="<?= (int)($lease['number_of_installments'] ?? 4) ?>"
                                            data-show-chiller-row="<?= (int)($lease['setting_show_chiller_row'] ?? 0) ?>"
                                            data-show-chiller-term="<?= (int)($lease['setting_show_chiller_term'] ?? 0) ?>"
                                            data-admin-fee-label="<?= h($lease['setting_admin_fee_label'] ?? 'Lease Renewal Charges') ?>"
                                            data-default-parking-fee="<?= number_format((float)($lease['setting_default_parking_fee'] ?? 0), 2, '.', '') ?>"
                                            data-default-small-store-fee="<?= number_format((float)($lease['setting_default_small_store_fee'] ?? 0), 2, '.', '') ?>"
                                            data-default-big-store-fee="<?= number_format((float)($lease['setting_default_big_store_fee'] ?? 0), 2, '.', '') ?>"
                                            data-vat-enabled="<?= (int)($lease['setting_vat_enabled'] ?? 1) ?>"
                                            data-vat-rate="<?= number_format((float)($lease['setting_vat_rate'] ?? 5), 2, '.', '') ?>"
                                            data-default-terms="<?= h((string)($lease['setting_terms_text'] ?? '')) ?>"
                                            data-default-docs="<?= h((string)($lease['setting_required_documents_text'] ?? '')) ?>"
                                            data-show-grand-total-row="<?= (int)($lease['setting_show_grand_total_row'] ?? 0) ?>">
                                            <?= h($lease['lease_number'] ?: 'L-' . $lease['id']) ?> - 
                                            <?= h($lease['building_name'] . ' - ' . $lease['unit_number']) ?> 
                                            (Expires: <?= date('Y-m-d', strtotime($lease['end_date'])) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="form-text text-muted">
                                    Showing leases expiring within <?= (int)$renewalExpiringWindowDays ?> days
                                    (<?= (int)$renewalPriorityWindowDays ?>-day priority window shown first).
                                    Change this period from the page header.
                                </small>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Target Renewal Date</label>
                                    <input type="date" class="form-control" name="target_renewal_date" value="<?= h($initiateForm['target_renewal_date']) ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Assigned To</label>
                                    <select name="assigned_to" class="form-select">
                                        <?php
                                            $defaultAssignedTo = (int)($userId ?? 0);
                                            $defaultUserFound = false;
                                            foreach ($users as $uRow) {
                                                if ((int)($uRow['id'] ?? 0) === $defaultAssignedTo) {
                                                    $defaultUserFound = true;
                                                    break;
                                                }
                                            }
                                        ?>
                                        <?php $assignedPref = $initiateForm['assigned_to'] !== '' ? (int)$initiateForm['assigned_to'] : $defaultAssignedTo; ?>
                                        <option value="" <?= $assignedPref > 0 ? '' : 'selected' ?>>-- Unassigned --</option>
                                        <?php foreach ($users as $user): ?>
                                            <option value="<?= (int)$user['id'] ?>" <?= ((int)$user['id'] === $assignedPref) ? 'selected' : '' ?>>
                                                <?= h($user['username']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                        <?php if ($assignedPref > 0 && !$defaultUserFound): ?>
                                            <option value="<?= $assignedPref ?>" selected><?= h('Current user') ?></option>
                                        <?php endif; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <hr>
                            <h6>Renewal Pricing & Proposed Lease Period</h6>
                            
                            <div class="row">
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Current Annual Rent (AED)</label>
                                    <input type="text" class="form-control" id="renewCurrentRent" value="0.00" readonly>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Increase Type</label>
                                    <select name="increase_type" id="renewIncreaseType" class="form-select">
                                        <option value="percentage" <?= $initiateForm['increase_type'] === 'percentage' ? 'selected' : '' ?>>percentage (%)</option>
                                        <option value="fixed" <?= $initiateForm['increase_type'] === 'fixed' ? 'selected' : '' ?>>fixed (AED)</option>
                                    </select>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Increase Value</label>
                                    <input type="number" step="0.01" class="form-control" id="renewIncreaseValue" name="increase_value" value="<?= h($initiateForm['increase_value']) ?>">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Calculated New Rent (AED)</label>
                                    <input type="text" class="form-control" id="renewCalculatedNewRent" value="0.00" readonly>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Proposed New Rent (Override) (AED)</label>
                                    <input type="number" step="0.01" class="form-control" name="proposed_rent" id="renewProposedRent" value="<?= h($initiateForm['proposed_rent']) ?>">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Chiller Charges (if Park Place)</label>
                                    <input type="number" step="0.01" class="form-control" id="renewChillerCharges" name="chiller_charges" value="<?= h($initiateForm['chiller_charges']) ?>">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label" id="renewAdminFeeLabel">Admin Fees (AED)</label>
                                    <input type="number" step="0.01" class="form-control" name="admin_fees" id="renewAdminFees" value="<?= h($initiateForm['admin_fees']) ?>">
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Number of Cheques</label>
                                    <input type="number" min="1" class="form-control" name="number_of_cheques" id="renewNumberOfCheques" value="<?= h($initiateForm['number_of_cheques']) ?>">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">5% VAT on Extra Charges (AED)</label>
                                    <input type="text" class="form-control" id="renewVatExtraCharges" value="0.00" readonly>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Proposed Start Date</label>
                                    <input type="date" class="form-control" name="proposed_start_date" id="renewProposedStart" value="<?= h($initiateForm['proposed_start_date']) ?>">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Proposed End Date</label>
                                    <input type="date" class="form-control" name="proposed_end_date" id="renewProposedEnd" value="<?= h($initiateForm['proposed_end_date']) ?>">
                                </div>
                            </div>

                            <hr>
                            <h6>Optional Extra Services</h6>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="checkbox" id="renewParkingEnabled" name="additional_parking_enabled" value="1" <?= $initiateForm['additional_parking_enabled'] === '1' ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="renewParkingEnabled">Additional Parking Slot</label>
                                    </div>
                                    <input type="number" step="0.01" class="form-control" id="renewParkingFee" name="additional_parking_fee" placeholder="Parking Fee AED" value="<?= h($initiateForm['additional_parking_fee']) ?>">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="checkbox" id="renewSmallStoreEnabled" name="small_store_enabled" value="1" <?= $initiateForm['small_store_enabled'] === '1' ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="renewSmallStoreEnabled">Small Storeroom</label>
                                    </div>
                                    <input type="number" step="0.01" class="form-control" id="renewSmallStoreFee" name="small_store_fee" placeholder="Small Store Fee AED" value="<?= h($initiateForm['small_store_fee']) ?>">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="checkbox" id="renewBigStoreEnabled" name="big_store_enabled" value="1" <?= $initiateForm['big_store_enabled'] === '1' ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="renewBigStoreEnabled">Big Storeroom</label>
                                    </div>
                                    <input type="number" step="0.01" class="form-control" id="renewBigStoreFee" name="big_store_fee" placeholder="Big Store Fee AED" value="<?= h($initiateForm['big_store_fee']) ?>">
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Terms & Conditions (editable)</label>
                                    <textarea class="form-control" id="renewTermsText" name="terms_text" rows="7"><?= h($initiateForm['terms_text']) ?></textarea>
                                    <small class="form-text text-muted">One line per bullet item (editable per workflow)</small>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Required Documents (editable)</label>
                                    <textarea class="form-control" id="renewRequiredDocs" name="required_documents_text" rows="7"><?= h($initiateForm['required_documents_text']) ?></textarea>
                                    <small class="form-text text-muted">One line per document item</small>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Stamp Image (optional)</label>
                                <input type="file" class="form-control" name="stamp_image" accept=".png,.jpg,.jpeg,.webp">
                                <small class="form-text text-muted">If uploaded, this stamp appears on page 2 of the renewal notice.</small>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary">Initiate Renewal</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script>
        (function () {
            const sel = document.querySelector('select[name="lease_id"]');
            if (!sel) return;

            const currentRentEl = document.getElementById('renewCurrentRent');
            const incTypeEl = document.getElementById('renewIncreaseType');
            const incValueEl = document.getElementById('renewIncreaseValue');
            const calcNewRentEl = document.getElementById('renewCalculatedNewRent');
            const proposedRentEl = document.getElementById('renewProposedRent');
            const chillerEl = document.getElementById('renewChillerCharges');
            const adminFeesEl = document.getElementById('renewAdminFees');
            const adminFeeLabelEl = document.getElementById('renewAdminFeeLabel');
            const vatEl = document.getElementById('renewVatExtraCharges');
            const chequesEl = document.getElementById('renewNumberOfCheques');
            const parkingEnabledEl = document.getElementById('renewParkingEnabled');
            const parkingFeeEl = document.getElementById('renewParkingFee');
            const smallStoreEnabledEl = document.getElementById('renewSmallStoreEnabled');
            const smallStoreFeeEl = document.getElementById('renewSmallStoreFee');
            const bigStoreEnabledEl = document.getElementById('renewBigStoreEnabled');
            const bigStoreFeeEl = document.getElementById('renewBigStoreFee');
            const termsEl = document.getElementById('renewTermsText');
            const docsEl = document.getElementById('renewRequiredDocs');

            function parseNum(v) {
                const n = parseFloat(v);
                return Number.isFinite(n) ? n : 0;
            }

            function getSelectedMeta() {
                const opt = sel.selectedOptions && sel.selectedOptions[0] ? sel.selectedOptions[0] : null;
                if (!opt) return null;
                return {
                    buildingName: opt.dataset.buildingName || '',
                    currentRent: parseNum(opt.dataset.currentRent),
                    chillerFees: parseNum(opt.dataset.chillerFees),
                    adminFees: parseNum(opt.dataset.adminFees),
                    numberOfCheques: parseInt(opt.dataset.numberOfCheques || '4', 10) || 4,
                    showChillerRow: (opt.dataset.showChillerRow || '0') === '1',
                    adminFeeLabel: opt.dataset.adminFeeLabel || 'Lease Renewal Charges',
                    defaultParkingFee: parseNum(opt.dataset.defaultParkingFee),
                    defaultSmallStoreFee: parseNum(opt.dataset.defaultSmallStoreFee),
                    defaultBigStoreFee: parseNum(opt.dataset.defaultBigStoreFee),
                    vatEnabled: (opt.dataset.vatEnabled || '1') === '1',
                    vatRate: parseNum(opt.dataset.vatRate || '5'),
                    defaultTerms: opt.dataset.defaultTerms || '',
                    defaultDocs: opt.dataset.defaultDocs || '',
                    showGrandTotalRow: (opt.dataset.showGrandTotalRow || '0') === '1'
                };
            }

            function recalc() {
                const meta = getSelectedMeta();
                if (!meta) return;

                const currentRent = parseNum(currentRentEl.value);
                const increaseType = incTypeEl.value;
                const increaseValue = parseNum(incValueEl.value);

                let calculated = currentRent;
                if (increaseType === 'percentage') {
                    calculated = currentRent + (currentRent * (increaseValue / 100));
                } else {
                    calculated = currentRent + increaseValue;
                }
                calculated = Math.round(calculated * 100) / 100;
                calcNewRentEl.value = calculated.toFixed(2);

                const chillerCharges = meta.showChillerRow ? parseNum(chillerEl.value || meta.chillerFees) : 0;
                chillerEl.value = chillerCharges.toFixed(2);
                chillerEl.readOnly = !meta.showChillerRow;
                chillerEl.closest('.col-md-4').style.display = meta.showChillerRow ? '' : 'none';

                const adminFees = parseNum(adminFeesEl.value);
                const parking = parkingEnabledEl.checked ? parseNum(parkingFeeEl.value) : 0;
                const smallStore = smallStoreEnabledEl.checked ? parseNum(smallStoreFeeEl.value) : 0;
                const bigStore = bigStoreEnabledEl.checked ? parseNum(bigStoreFeeEl.value) : 0;
                const vatBase = chillerCharges + adminFees + parking + smallStore + bigStore;
                const vatExtra = meta.vatEnabled ? (vatBase * (meta.vatRate / 100)) : 0;
                vatEl.value = (Math.round(vatExtra * 100) / 100).toFixed(2);

                // Only auto-fill override if user hasn't manually edited it yet
                const userEdited = proposedRentEl.dataset.userEdited === '1';
                if (!userEdited) {
                    proposedRentEl.value = calculated.toFixed(2);
                }
            }

            function applyDefaultsFromSelected() {
                const meta = getSelectedMeta();
                if (!meta) return;
                currentRentEl.value = meta.currentRent.toFixed(2);
                adminFeesEl.value = meta.adminFees.toFixed(2);
                adminFeeLabelEl.textContent = meta.adminFeeLabel + ' (AED)';
                chequesEl.value = meta.numberOfCheques;
                chillerEl.value = meta.chillerFees.toFixed(2);
                parkingEnabledEl.checked = false;
                smallStoreEnabledEl.checked = false;
                bigStoreEnabledEl.checked = false;
                parkingFeeEl.value = meta.defaultParkingFee.toFixed(2);
                smallStoreFeeEl.value = meta.defaultSmallStoreFee.toFixed(2);
                bigStoreFeeEl.value = meta.defaultBigStoreFee.toFixed(2);
                termsEl.value = meta.defaultTerms;
                docsEl.value = meta.defaultDocs;
                proposedRentEl.dataset.userEdited = '0';
                recalc();
            }

            sel.addEventListener('change', applyDefaultsFromSelected);
            incTypeEl.addEventListener('change', recalc);
            incValueEl.addEventListener('input', recalc);
            adminFeesEl.addEventListener('input', recalc);
            chillerEl.addEventListener('input', recalc);
            parkingEnabledEl.addEventListener('change', recalc);
            parkingFeeEl.addEventListener('input', recalc);
            smallStoreEnabledEl.addEventListener('change', recalc);
            smallStoreFeeEl.addEventListener('input', recalc);
            bigStoreEnabledEl.addEventListener('change', recalc);
            bigStoreFeeEl.addEventListener('input', recalc);
            proposedRentEl.addEventListener('input', function () {
                proposedRentEl.dataset.userEdited = '1';
            });
            applyDefaultsFromSelected();

            <?php if ($reopenInitiateModal): ?>
            const modalEl = document.getElementById('initiateModal');
            if (modalEl && window.bootstrap && bootstrap.Modal) {
                const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
                modal.show();
            }
            <?php endif; ?>
        })();
        </script>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>

