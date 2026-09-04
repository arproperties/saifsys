<?php
/**
 * Phase 3 invoice candidate / issuance endpoint.
 *
 * POST only.
 * - prepare_candidates: creates candidate rows, no invoice numbers.
 * - issue_candidate: issues one approved eligible candidate, assigning an
 *   official invoice number only at issuance time.
 */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/company_helper.php';
require_once __DIR__ . '/../../../includes/module_access.php';
require_once __DIR__ . '/../../../includes/rbac_department.php';
require_once __DIR__ . '/../includes/invoice_engine.php';
require_once __DIR__ . '/../includes/receipt_allocation_engine.php';

require_login();
if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_FINANCIAL, $conn)) {
    require_module_access($conn, MODULE_REALESTATE);
}

$leaseId = (int)($_POST['lease_id'] ?? 0);
$action = (string)($_POST['action'] ?? '');
$candidateId = (int)($_POST['candidate_id'] ?? 0);
$redirect = 'invoice_preview.php' . ($leaseId > 0 ? '?lease_id=' . $leaseId : '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['re_invoice_generation_flash'] = [
        'success' => false,
        'message' => 'Invoice candidate actions require a POST request.',
    ];
    header('Location: ' . $redirect);
    exit;
}

if (!csrf_verify(false)) {
    $_SESSION['re_invoice_generation_flash'] = [
        'success' => false,
        'message' => 'Invalid CSRF token. No candidates or invoices were changed.',
    ];
    header('Location: ' . $redirect);
    exit;
}

if ($leaseId <= 0) {
    $_SESSION['re_invoice_generation_flash'] = [
        'success' => false,
        'message' => 'Please select a valid lease first.',
    ];
    header('Location: ' . $redirect);
    exit;
}

$companyId = current_company_id($conn) ?: 1;

if ($action === 'prepare_candidates') {
    $result = re_invoice_engine_prepare_candidates_for_lease($conn, $companyId, $leaseId, current_user_id());
    if (!empty($result['success'])) {
        $stats = $result['stats'] ?? [];
        $_SESSION['re_invoice_generation_flash'] = [
            'success' => true,
            'message' => sprintf(
                'Invoice candidates prepared. Created: %d, already existed: %d. No official invoice numbers were assigned.',
                (int)($stats['created'] ?? 0),
                (int)($stats['already_exists'] ?? 0)
            ),
        ];
    } else {
        $_SESSION['re_invoice_generation_flash'] = [
            'success' => false,
            'message' => (string)($result['error'] ?? 'Could not prepare invoice candidates.'),
        ];
    }
} elseif ($action === 'issue_candidate') {
    if ($candidateId <= 0) {
        $_SESSION['re_invoice_generation_flash'] = [
            'success' => false,
            'message' => 'Please select one invoice candidate to issue.',
        ];
        header('Location: ' . $redirect);
        exit;
    }

    $result = re_invoice_engine_issue_candidate_for_lease($conn, $companyId, $leaseId, $candidateId, current_user_id());
    if (!empty($result['success'])) {
        $_SESSION['re_invoice_generation_flash'] = [
            'success' => true,
            'message' => 'Selected candidate issued successfully. One official invoice number was assigned.',
        ];
    } else {
        $_SESSION['re_invoice_generation_flash'] = [
            'success' => false,
            'message' => (string)($result['error'] ?? 'Could not issue the selected candidate.'),
        ];
    }
} elseif ($action === 'apply_tenant_credit') {
    $result = re_receipt_apply_available_tenant_credit($conn, $companyId, $leaseId, current_user_id());
    if (!empty($result['success'])) {
        $applied = (float)($result['applied'] ?? 0);
        if ($applied > 0.005) {
            $nums = $result['invoice_numbers'] ?? [];
            $numLabel = is_array($nums) && $nums !== []
                ? (' (' . implode(', ', array_map('strval', $nums)) . ')')
                : '';
            $_SESSION['re_invoice_generation_flash'] = [
                'success' => true,
                'message' => sprintf(
                    'Applied AED %.2f from tenant credit to fully clear %d open invoice(s)%s. No bank/cheque receipt was posted.',
                    $applied,
                    (int)($result['invoice_count'] ?? 0),
                    $numLabel
                ),
            ];
        } else {
            $remaining = (float)($result['remaining_credit'] ?? 0);
            $_SESSION['re_invoice_generation_flash'] = [
                'success' => true,
                'message' => $remaining > 0.005
                    ? sprintf(
                        'No invoices were fully cleared. Tenant credit AED %.2f remains parked until it can fully cover an open invoice.',
                        $remaining
                    )
                    : 'No tenant credit was available to apply.',
            ];
        }
    } else {
        $_SESSION['re_invoice_generation_flash'] = [
            'success' => false,
            'message' => (string)($result['error'] ?? 'Could not apply tenant credit.'),
        ];
    }
} else {
    $_SESSION['re_invoice_generation_flash'] = [
        'success' => false,
        'message' => 'Unknown invoice candidate action.',
    ];
}

header('Location: ' . $redirect);
exit;

