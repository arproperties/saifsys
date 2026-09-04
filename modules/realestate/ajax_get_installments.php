<?php
/**
 * AJAX endpoint to get installments for a lease (with outstanding and tenant credit for allocation UI)
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/includes/payment_allocation_helper.php';

header('Content-Type: application/json');

$leaseId          = !empty($_GET['lease_id'])       ? (int)$_GET['lease_id']       : 0;
$targetInstId     = !empty($_GET['installment_id']) ? (int)$_GET['installment_id'] : 0;
$targetBillingItemId = !empty($_GET['billing_item_id']) ? (int)$_GET['billing_item_id'] : 0;
$collectBalance   = !empty($_GET['collect_balance']);
$currentCompanyId = current_company_id($conn) ?: 1;

if (!$leaseId) {
    echo json_encode([]);
    exit;
}

// Fetch ALL installments for this lease — no status filter.
// The DB status column can be wrong (e.g., marked 'paid' even with outstanding).
// We compute the real outstanding from allocations and filter in PHP.
$stmt = $conn->prepare("
    SELECT li.id, li.installment_date, li.amount, li.status,
           COALESCE(c.cheque_number, lc.cheque_number, '') AS cheque_number
    FROM re_lease_installments li
    LEFT JOIN re_post_dated_cheques c  ON c.installment_id  = li.id AND c.lease_id  = li.lease_id
    LEFT JOIN re_lease_cheques      lc ON lc.installment_id = li.id AND lc.lease_id = li.lease_id
    WHERE li.lease_id = ?
    ORDER BY li.installment_date ASC
");
$stmt->execute([$leaseId]);
$installments = $stmt->fetchAll(PDO::FETCH_ASSOC);

$useAllocation = payment_allocation_tables_exist($conn);
$totalOutstanding = 0.0;

foreach ($installments as &$inst) {
    $inst['amount'] = (float)$inst['amount'];
    if (($inst['status'] ?? '') === 'cancelled') {
        $inst['total_paid'] = 0.0;
        $inst['outstanding'] = 0.0;
        $inst['is_target'] = false;
        $inst['display_status'] = 'cancelled';
        continue;
    }
    if ($useAllocation) {
        $totalPaid = get_installment_total_paid($conn, (int)$inst['id']);
    } else {
        // Fallback: sum from re_payments directly
        $ps = $conn->prepare("SELECT COALESCE(SUM(amount),0) FROM re_payments WHERE installment_id = ?");
        $ps->execute([$inst['id']]);
        $totalPaid = (float)$ps->fetchColumn();
    }
    $outstanding = max(0, $inst['amount'] - $totalPaid);

    $inst['total_paid']  = round($totalPaid, 2);
    $inst['outstanding'] = round($outstanding, 2);
    $inst['is_target']   = ($targetInstId && (int)$inst['id'] === $targetInstId);

    // Override the displayed status to reflect actual payment state
    if ($outstanding <= 0) {
        $inst['display_status'] = 'paid';
    } elseif ($totalPaid > 0) {
        $inst['display_status'] = 'partial';
    } else {
        // Keep DB status (overdue / pending)
        $inst['display_status'] = $inst['status'];
    }

    $totalOutstanding += $outstanding;
}
unset($inst);

// Keep only installments with actual outstanding balance, PLUS the explicit target
$installments = array_values(array_filter($installments, function($i) use ($targetInstId) {
    if (($i['status'] ?? '') === 'cancelled') {
        return false;
    }
    return $i['outstanding'] > 0.005 || ($targetInstId && (int)$i['id'] === $targetInstId);
}));

// Tenant credit + lease info (multi-unit breakdown)
$tenantCreditBalance = 0.0;
$leaseInfo = [];
if ($useAllocation) {
    $stmt = $conn->prepare("
        SELECT l.tenant_id, l.is_multi_unit, l.annual_rent,
               t.first_name, t.last_name, t.company_name, t.tenant_type
        FROM re_leases l
        JOIN re_tenants t ON t.id = l.tenant_id
        WHERE l.id = ? AND l.company_id = ?
    ");
    $stmt->execute([$leaseId, $currentCompanyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $tenantCreditBalance = get_tenant_credit_balance($conn, (int)$row['tenant_id'], $currentCompanyId);
        $leaseInfo = [
            'is_multi_unit' => !empty($row['is_multi_unit']),
            'annual_rent'   => (float)$row['annual_rent'],
            'units'         => [],
        ];
        // Load per-unit breakdown for multi-unit leases
        if (!empty($row['is_multi_unit'])) {
            $uStmt = $conn->prepare("
                SELECT lu.unit_id, lu.annual_rent,
                       u.unit_number, u.unit_type, b.name AS building_name
                FROM re_lease_units lu
                JOIN re_units u ON u.id = lu.unit_id
                JOIN re_buildings b ON b.id = u.building_id
                WHERE lu.lease_id = ?
                ORDER BY lu.sort_order ASC, lu.id ASC
            ");
            $uStmt->execute([$leaseId]);
            $leaseInfo['units'] = $uStmt->fetchAll(PDO::FETCH_ASSOC);
            // Convert annual_rent to float for JS
            foreach ($leaseInfo['units'] as &$u) {
                $u['annual_rent'] = (float)$u['annual_rent'];
                $u['monthly_rent'] = round($u['annual_rent'] / 12, 2);
            }
            unset($u);
        }
    }
}

// Load unpaid / non-waived billing items for this lease (service charges + penalties)
$billingItems = [];
try {
    $ps = $conn->prepare("
        SELECT bi.id, bi.item_type, bi.item_name, bi.item_description, bi.due_date,
               bi.total_amount, bi.amount, bi.status, bi.service_charge_id, pr.penalty_type
        FROM re_billing_items bi
        LEFT JOIN re_penalty_rules pr ON pr.id = bi.penalty_rule_id
        WHERE bi.lease_id = ? AND bi.company_id = ?
          AND bi.item_type IN ('service_charge', 'parking_fee', 'penalty', 'other')
          AND bi.status <> 'waived'
        ORDER BY bi.due_date ASC
    ");
    $ps->execute([$leaseId, $currentCompanyId]);
    $billingItems = $ps->fetchAll(PDO::FETCH_ASSOC);
    foreach ($billingItems as &$pi) {
        $pi['total_amount'] = (float)$pi['total_amount'];
        $paid = get_billing_item_total_paid($conn, (int)$pi['id']);
        $outstanding = max(0, (float)$pi['total_amount'] - $paid);
        $pi['paid_amount'] = round($paid, 2);
        $pi['outstanding']  = round($outstanding, 2);
        $pi['is_target'] = ($targetBillingItemId && (int)$pi['id'] === $targetBillingItemId);
    }
    unset($pi);
    $billingItems = array_values(array_filter($billingItems, function ($item) use ($targetBillingItemId) {
        return ((float)$item['outstanding'] > 0.005) || ($targetBillingItemId && (int)$item['id'] === $targetBillingItemId);
    }));
} catch (Exception $e) {
    // Billing items table may not exist on older installs — ignore
}

echo json_encode([
    'installments'         => $installments,
    'penalty_items'        => $billingItems,
    'billing_items'        => $billingItems,
    'tenant_credit_balance'=> round($tenantCreditBalance, 2),
    'allocation_supported' => $useAllocation,
    'total_outstanding'    => round($totalOutstanding, 2),
    'target_installment_id'=> $targetInstId,
    'target_billing_item_id'=> $targetBillingItemId,
    'collect_balance'      => $collectBalance,
    'lease_info'           => $leaseInfo,
]);
