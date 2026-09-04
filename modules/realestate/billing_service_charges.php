<?php
/**
 * Real Estate Module - Service Charges Management
 * Configure and manage service charge types and applied charges
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/includes/payment_allocation_helper.php';
require_once __DIR__ . '/includes/service_charge_schedule_helper.php';
require_once __DIR__ . '/includes/service_charge_payment_plan_helper.php';

require_login();
require_module_access($conn, MODULE_REALESTATE);

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn);
if (!$currentCompanyId) {
    $_SESSION['error'] = 'Company context is required.';
    header('Location: /modules/realestate/');
    exit;
}
$currentCompanyId = (int)$currentCompanyId;
$currentUserId = $_SESSION['user_id'] ?? null;
$selectedLeaseId = !empty($_GET['lease_id']) ? (int)$_GET['lease_id'] : 0;
$scDefaultStandardVatRate = (float)re_sc_setting($conn, 're_service_charge_company_vat_default_rate', '5');
if ($scDefaultStandardVatRate <= 0.005) {
    $scDefaultStandardVatRate = 5.0; // UAE standard when company default rate is unset
}

try {
    re_service_charge_ensure_apply_mode_column($conn);
} catch (Throwable $e) {
    // Column will be created on first apply if ALTER is unavailable here.
}

function re_service_charge_interval_spec(string $recurrenceType): string {
    return match ($recurrenceType) {
        'quarterly' => '+3 months',
        'annually' => '+12 months',
        default => '+1 month',
    };
}

/**
 * Period billing schedule (revenue recognition). VAT snapshot from charge; never driven by payment-plan line count.
 */
function re_generate_service_charge_schedule(PDO $conn, int $companyId, int $serviceChargeId, ?int $createdBy = null): int {
    $stmt = $conn->prepare("
        SELECT sc.*, l.end_date AS lease_end_date
        FROM re_service_charges sc
        JOIN re_leases l ON l.id = sc.lease_id AND l.company_id = sc.company_id
        WHERE sc.id = ? AND sc.company_id = ?
        LIMIT 1
    ");
    $stmt->execute([$serviceChargeId, $companyId]);
    $charge = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$charge) {
        return 0;
    }

    $periodNet = round((float)$charge['amount'], 2);
    if ($periodNet <= 0) {
        return 0;
    }

    $taxRate = round((float)($charge['vat_rate'] ?? 0), 2);
    $vatTreatment = (string)($charge['vat_treatment'] ?? 'exempt');
    if ($vatTreatment !== 'standard') {
        $taxRate = 0.0;
    }

    $start = new DateTime((string)$charge['start_date']);
    $end = !empty($charge['end_date'])
        ? new DateTime((string)$charge['end_date'])
        : (!empty($charge['lease_end_date']) ? new DateTime((string)$charge['lease_end_date']) : clone $start);
    if ($end < $start) {
        $end = clone $start;
    }

    $intervalSpec = re_service_charge_interval_spec((string)$charge['recurrence_type']);
    $isRecurring = !empty($charge['is_recurring']) && ($charge['recurrence_type'] ?? '') !== 'one_time';

    // Equal period nets/taxes from contracted face value + amount_basis (last period absorbs fils).
    $periodNets = null;
    $periodTaxes = null;
    $contracted = isset($charge['contracted_amount']) && $charge['contracted_amount'] !== null
        ? round((float)$charge['contracted_amount'], 2)
        : 0.0;
    $amountBasis = function_exists('re_sc_normalize_amount_basis')
        ? re_sc_normalize_amount_basis((string)($charge['amount_basis'] ?? 'vat_exclusive'))
        : 'vat_exclusive';
    if ($contracted > 0.005 && $isRecurring && function_exists('re_sc_period_amounts_from_contract')) {
        $periodCount = re_service_charge_count_billing_periods($charge, $charge['lease_end_date'] ?? null);
        $split = re_sc_period_amounts_from_contract($contracted, $amountBasis, $vatTreatment, $taxRate, $periodCount);
        $periodNets = $split['period_nets'];
        $periodTaxes = $split['period_taxes'];
    } elseif ($contracted > 0.005 && $isRecurring) {
        $netContracted = function_exists('re_sc_net_from_contract')
            ? re_sc_net_from_contract($contracted, $amountBasis, $vatTreatment, $taxRate)
            : $contracted;
        $periodCount = re_service_charge_count_billing_periods($charge, $charge['lease_end_date'] ?? null);
        if ($periodCount > 1) {
            $base = floor(($netContracted / $periodCount) * 100) / 100;
            $periodNets = array_fill(0, $periodCount, $base);
            $periodNets[$periodCount - 1] = round($netContracted - ($base * ($periodCount - 1)), 2);
        } elseif ($periodCount === 1) {
            $periodNets = [$netContracted];
        }
    }

    $insert = $conn->prepare("
        INSERT INTO re_billing_items
            (company_id, lease_id, item_type, item_name, item_description, amount,
             quantity, unit_price, tax_rate, tax_amount, total_amount,
             billing_period_start, billing_period_end,
             billing_date, due_date, service_charge_id, status, created_by)
        VALUES (?, ?, 'service_charge', ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)
    ");

    $created = 0;
    $periodIndex = 0;
    $due = clone $start;
    do {
        $next = (clone $due)->modify($intervalSpec);
        $periodEnd = (clone $next)->modify('-1 day');
        if ($periodEnd > $end) {
            $periodEnd = clone $end;
        }
        $exists = $conn->prepare("
            SELECT id
            FROM re_billing_items
            WHERE company_id = ?
              AND service_charge_id = ?
              AND due_date = ?
              AND item_type = 'service_charge'
            LIMIT 1
        ");
        $exists->execute([$companyId, $serviceChargeId, $due->format('Y-m-d')]);
        if ($exists->fetchColumn()) {
            if (!$isRecurring) {
                break;
            }
            $due = $next;
            $periodIndex++;
            continue;
        }

        $net = ($periodNets !== null && isset($periodNets[$periodIndex]))
            ? (float)$periodNets[$periodIndex]
            : $periodNet;
        $taxAmount = ($periodTaxes !== null && isset($periodTaxes[$periodIndex]))
            ? (float)$periodTaxes[$periodIndex]
            : ($taxRate > 0 ? round($net * ($taxRate / 100), 2) : 0.0);
        $total = round($net + $taxAmount, 2);

        $insert->execute([
            $companyId,
            (int)$charge['lease_id'],
            $charge['charge_name'],
            $charge['charge_description'] ?? null,
            $net,
            $net,
            $taxRate,
            $taxAmount,
            $total,
            $due->format('Y-m-d'),
            $periodEnd->format('Y-m-d'),
            $due->format('Y-m-d'),
            $due->format('Y-m-d'),
            $serviceChargeId,
            $createdBy,
        ]);
        $created++;
        $periodIndex++;

        if (!$isRecurring) {
            break;
        }
        $due = $next;
    } while ($due <= $end);

    return $created;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create_type') {
        $chargeName = $_POST['charge_name'] ?? '';
        $chargeDescription = $_POST['charge_description'] ?? '';
        $chargeType = $_POST['charge_type'] ?? 'fixed';
        $defaultAmount = $_POST['default_amount'] ?? 0;
        $isRecurring = !empty($_POST['is_recurring']) ? 1 : 0;
        $recurrenceType = $_POST['recurrence_type'] ?? 'monthly';
        $vatIn = (string)($_POST['vat_treatment'] ?? 'company_default');
        $vatHint = isset($_POST['vat_rate']) && $_POST['vat_rate'] !== '' ? (float)$_POST['vat_rate'] : null;
        $vat = re_sc_resolve_vat($conn, $vatIn, $vatHint);

        if ($chargeName) {
            $hasTypeVat = false;
            try {
                $c = $conn->query("SHOW COLUMNS FROM re_service_charge_types LIKE 'vat_treatment'");
                $hasTypeVat = (bool)$c->fetch(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                $hasTypeVat = false;
            }
            if ($hasTypeVat) {
                $stmt = $conn->prepare("
                    INSERT INTO re_service_charge_types
                    (company_id, charge_name, charge_description, charge_type, default_amount, is_recurring, recurrence_type, vat_treatment, vat_rate)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $currentCompanyId, $chargeName, $chargeDescription, $chargeType,
                    $defaultAmount, $isRecurring, $recurrenceType, $vat['treatment'], $vat['rate']
                ]);
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO re_service_charge_types
                    (company_id, charge_name, charge_description, charge_type, default_amount, is_recurring, recurrence_type)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $currentCompanyId, $chargeName, $chargeDescription, $chargeType,
                    $defaultAmount, $isRecurring, $recurrenceType
                ]);
            }
            $_SESSION['success'] = 'Service charge type created successfully.';
        }
    } elseif ($action === 'apply_charge') {
        $leaseId = (int)$_POST['lease_id'];
        $chargeTypeId = !empty($_POST['charge_type_id']) ? (int)$_POST['charge_type_id'] : null;
        $chargeName = $_POST['charge_name'] ?? '';
        $chargeType = $_POST['charge_type'] ?? 'fixed';
        $isRecurring = !empty($_POST['is_recurring']) ? 1 : 0;
        $recurrenceType = $_POST['recurrence_type'] ?? 'monthly';
        $startDate = $_POST['start_date'] ?? date('Y-m-d');
        $endDate = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
        $applyMode = re_service_charge_normalize_apply_mode((string)($_POST['apply_mode'] ?? 'standalone'));
        $expectedMethod = trim((string)($_POST['expected_payment_method'] ?? ''));
        if ($expectedMethod === '') {
            $expectedMethod = null;
        }

        // Period amount (legacy) and/or contracted total (preferred for standalone).
        $periodAmount = round((float)($_POST['amount'] ?? 0), 2);
        $contractedInput = isset($_POST['contracted_amount']) && $_POST['contracted_amount'] !== ''
            ? round((float)$_POST['contracted_amount'], 2)
            : 0.0;

        $vatIn = (string)($_POST['vat_treatment'] ?? 'company_default');
        $vatHint = isset($_POST['vat_rate']) && $_POST['vat_rate'] !== '' ? (float)$_POST['vat_rate'] : null;
        $vat = re_sc_resolve_vat($conn, $vatIn, $vatHint);
        $amountBasis = re_sc_normalize_amount_basis((string)($_POST['amount_basis'] ?? 'vat_exclusive'));

        $enablePaymentPlan = $applyMode === 'standalone' && !empty($_POST['enable_payment_plan']);
        $planCount = max(0, (int)($_POST['payment_plan_count'] ?? 0));
        $planLinesPosted = $_POST['plan_due'] ?? null;

        if ($leaseId && $chargeName && ($periodAmount > 0 || $contractedInput > 0)) {
            $leaseCheck = $conn->prepare("
                SELECT id, status, end_date
                FROM re_leases
                WHERE id = ? AND company_id = ?
                  AND status IN ('active', 'expired', 'terminated')
                LIMIT 1
            ");
            $leaseCheck->execute([$leaseId, $currentCompanyId]);
            $leaseRow = $leaseCheck->fetch(PDO::FETCH_ASSOC);
            if (!$leaseRow) {
                $_SESSION['error'] = 'Service charge was not applied because the selected lease is missing or not bookable (draft leases are blocked).';
                $redirect = 'billing_service_charges.php';
                if ($leaseId > 0) {
                    $redirect .= '?lease_id=' . $leaseId . '&apply=1';
                }
                header('Location: ' . $redirect);
                exit;
            }

            try {
                $conn->beginTransaction();
                re_service_charge_ensure_apply_mode_column($conn);
                if ($applyMode === 'merge_first_installment') {
                    $isRecurring = 0;
                    $recurrenceType = 'one_time';
                }

                // Derive period vs contracted without changing merge/split amount semantics.
                $chargeProbe = [
                    'amount' => $periodAmount > 0 ? $periodAmount : $contractedInput,
                    'is_recurring' => $isRecurring,
                    'recurrence_type' => $recurrenceType,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                ];
                $periods = re_service_charge_count_billing_periods($chargeProbe, $leaseRow['end_date'] ?? null);

                if ($applyMode === 'standalone') {
                    if ($contractedInput > 0.005) {
                        $contractedAmount = $contractedInput; // face value (net if exclusive, gross if inclusive)
                        $netContracted = re_sc_net_from_contract(
                            $contractedAmount,
                            $amountBasis,
                            $vat['treatment'],
                            (float)$vat['rate']
                        );
                        $base = floor(($netContracted / max(1, $periods)) * 100) / 100;
                        $periodAmount = $periods > 1 ? $base : $netContracted;
                    } else {
                        // Legacy period entry: treat amount as net period; contracted face = net × periods (exclusive)
                        $amountBasis = 'vat_exclusive';
                        $contractedAmount = round($periodAmount * max(1, $periods), 2);
                    }
                } else {
                    // merge/split: amount remains the entered figure (period or one-time as today)
                    if ($periodAmount <= 0 && $contractedInput > 0) {
                        $periodAmount = $contractedInput;
                    }
                    $amountBasis = 'vat_exclusive';
                    $contractedAmount = $applyMode === 'split_all_installments'
                        ? re_service_charge_total_for_cheque_split(
                            array_merge($chargeProbe, ['amount' => $periodAmount]),
                            $leaseRow['end_date'] ?? null
                        )
                        : $periodAmount;
                }

                $hasLifecycle = re_service_charge_table_has_column($conn, 'lifecycle_status');
                $hasContracted = re_service_charge_table_has_column($conn, 'contracted_amount');
                $hasVat = re_service_charge_table_has_column($conn, 'vat_treatment');
                $hasBasis = re_service_charge_table_has_column($conn, 'amount_basis');

                if ($hasLifecycle && $hasContracted && $hasVat && $hasBasis) {
                    $stmt = $conn->prepare("
                        INSERT INTO re_service_charges
                        (company_id, lease_id, charge_type_id, charge_name, charge_description, amount,
                         contracted_amount, charge_type, is_recurring, recurrence_type, apply_mode,
                         start_date, end_date, lifecycle_status, vat_treatment, vat_rate, amount_basis,
                         expected_payment_method, is_active)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?, ?, ?, 1)
                    ");
                    $stmt->execute([
                        $currentCompanyId, $leaseId, $chargeTypeId, $chargeName,
                        $_POST['charge_description'] ?? '', $periodAmount, $contractedAmount, $chargeType,
                        $isRecurring, $recurrenceType, $applyMode, $startDate, $endDate,
                        $vat['treatment'], $vat['rate'], $amountBasis, $expectedMethod
                    ]);
                } elseif ($hasLifecycle && $hasContracted && $hasVat) {
                    $stmt = $conn->prepare("
                        INSERT INTO re_service_charges
                        (company_id, lease_id, charge_type_id, charge_name, charge_description, amount,
                         contracted_amount, charge_type, is_recurring, recurrence_type, apply_mode,
                         start_date, end_date, lifecycle_status, vat_treatment, vat_rate,
                         expected_payment_method, is_active)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?, ?, 1)
                    ");
                    $stmt->execute([
                        $currentCompanyId, $leaseId, $chargeTypeId, $chargeName,
                        $_POST['charge_description'] ?? '', $periodAmount, $contractedAmount, $chargeType,
                        $isRecurring, $recurrenceType, $applyMode, $startDate, $endDate,
                        $vat['treatment'], $vat['rate'], $expectedMethod
                    ]);
                } else {
                    $stmt = $conn->prepare("
                        INSERT INTO re_service_charges
                        (company_id, lease_id, charge_type_id, charge_name, charge_description, amount, charge_type, is_recurring, recurrence_type, apply_mode, start_date, end_date)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $currentCompanyId, $leaseId, $chargeTypeId, $chargeName,
                        $_POST['charge_description'] ?? '', $periodAmount, $chargeType,
                        $isRecurring, $recurrenceType, $applyMode, $startDate, $endDate
                    ]);
                }
                $serviceChargeId = (int)$conn->lastInsertId();
                $chargeRow = [
                    'charge_name' => $chargeName,
                    'charge_description' => $_POST['charge_description'] ?? '',
                    'amount' => (float)$periodAmount,
                    'is_recurring' => $isRecurring,
                    'recurrence_type' => $recurrenceType,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                ];
                if ($applyMode === 'merge_first_installment') {
                    $merge = re_service_charge_merge_into_first_installment(
                        $conn,
                        $currentCompanyId,
                        $leaseId,
                        $serviceChargeId,
                        $chargeRow,
                        $currentUserId ? (int)$currentUserId : null
                    );
                    if (empty($merge['success'])) {
                        throw new RuntimeException($merge['error'] ?? 'Could not merge charge into first cheque.');
                    }
                    re_service_charge_sync_invoice_mode_obligations(
                        $conn,
                        $currentCompanyId,
                        $leaseId,
                        $currentUserId ? (int)$currentUserId : null
                    );
                    $conn->commit();
                    $chequeLabel = !empty($merge['cheque_number']) ? (string)$merge['cheque_number'] : ('installment #' . (int)$merge['installment_id']);
                    $_SESSION['success'] = sprintf(
                        'Service charge applied and merged into %s. New row total: %s AED.',
                        $chequeLabel,
                        number_format((float)($merge['new_installment_amount'] ?? 0), 2)
                    );
                } elseif ($applyMode === 'split_all_installments') {
                    $split = re_service_charge_split_across_installments(
                        $conn,
                        $currentCompanyId,
                        $leaseId,
                        $serviceChargeId,
                        $chargeRow,
                        $currentUserId ? (int)$currentUserId : null
                    );
                    if (empty($split['success'])) {
                        throw new RuntimeException($split['error'] ?? 'Could not split charge across cheques.');
                    }
                    re_service_charge_sync_invoice_mode_obligations(
                        $conn,
                        $currentCompanyId,
                        $leaseId,
                        $currentUserId ? (int)$currentUserId : null
                    );
                    $conn->commit();
                    $_SESSION['success'] = sprintf(
                        'Service charge split across %d open cheque(s). Total %s AED (~%s AED per cheque).',
                        (int)($split['installment_count'] ?? 0),
                        number_format((float)($split['total_amount'] ?? 0), 2),
                        number_format((float)($split['average_slice'] ?? 0), 2)
                    );
                } else {
                    $scheduleCount = re_generate_service_charge_schedule(
                        $conn,
                        $currentCompanyId,
                        $serviceChargeId,
                        $currentUserId ? (int)$currentUserId : null
                    );

                    // Payment plan is collection-only; total = invoice collectible (net+VAT).
                    $planNote = '';
                    if ($enablePaymentPlan && $planCount > 0) {
                        $planTotal = re_sc_payment_plan_total_from_contract(
                            $contractedAmount,
                            $amountBasis,
                            (string)($vat['treatment'] ?? 'exempt'),
                            (float)($vat['rate'] ?? 0)
                        );
                        $lines = [];
                        if (is_array($planLinesPosted) && is_array($_POST['plan_amount'] ?? null)) {
                            foreach ($planLinesPosted as $i => $due) {
                                $lines[] = [
                                    'due_date' => (string)$due,
                                    'amount' => (float)($_POST['plan_amount'][$i] ?? 0),
                                ];
                            }
                        } else {
                            $lines = re_sc_build_equal_payment_plan(
                                $planTotal,
                                $planCount,
                                $startDate,
                                $endDate ?: ($leaseRow['end_date'] ?? null)
                            );
                        }
                        $planSave = re_sc_save_payment_plan(
                            $conn,
                            $currentCompanyId,
                            $serviceChargeId,
                            $leaseId,
                            $planTotal,
                            $lines,
                            $expectedMethod,
                            $currentUserId ? (int)$currentUserId : null
                        );
                        if (empty($planSave['success'])) {
                            throw new RuntimeException($planSave['error'] ?? 'Could not save payment plan.');
                        }
                        $planNote = ' Payment plan: ' . (int)($planSave['line_count'] ?? 0) . ' collection line(s).';
                    }

                    re_service_charge_sync_invoice_mode_obligations(
                        $conn,
                        $currentCompanyId,
                        $leaseId,
                        $currentUserId ? (int)$currentUserId : null
                    );
                    $conn->commit();
                    $_SESSION['success'] = 'Service charge applied successfully. Created ' . $scheduleCount
                        . ' monthly billing row(s).' . $planNote;
                }
            } catch (Throwable $e) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                $_SESSION['error'] = 'Could not apply service charge: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'generate_schedule') {
        $serviceChargeId = !empty($_POST['service_charge_id']) ? (int)$_POST['service_charge_id'] : 0;
        if ($serviceChargeId) {
            $exists = $conn->prepare("SELECT id, apply_mode FROM re_service_charges WHERE id = ? AND company_id = ? LIMIT 1");
            $exists->execute([$serviceChargeId, $currentCompanyId]);
            $chargeRow = $exists->fetch(PDO::FETCH_ASSOC);
            if ($chargeRow) {
                try {
                    if (re_service_charge_apply_mode_on_schedule((string)($chargeRow['apply_mode'] ?? 'standalone'))) {
                        $_SESSION['error'] = 'This charge is applied on rent cheques and does not use a separate billing schedule.';
                    } else {
                        $scheduleCount = re_generate_service_charge_schedule($conn, $currentCompanyId, $serviceChargeId, $currentUserId ? (int)$currentUserId : null);
                        $_SESSION['success'] = 'Created ' . $scheduleCount . ' missing schedule row(s).';
                    }
                } catch (Throwable $e) {
                    $_SESSION['error'] = 'Could not generate schedule: ' . $e->getMessage();
                }
            }
        }
    }
    
    header('Location: billing_service_charges.php');
    exit;
}

// Get service charge types
$chargeTypes = $conn->prepare("
    SELECT * FROM re_service_charge_types
    WHERE company_id = ? AND is_active = 1
    ORDER BY charge_name
");
$chargeTypes->execute([$currentCompanyId]);
$chargeTypes = $chargeTypes->fetchAll(PDO::FETCH_ASSOC);

// Get applied service charges
$appliedCharges = $conn->prepare("
    SELECT 
        sc.*,
        l.lease_number,
        u.unit_number,
        b.name as building_name,
        t.first_name,
        t.last_name,
        sct.charge_name as type_name,
        (SELECT COUNT(*) FROM re_billing_items bi WHERE bi.service_charge_id = sc.id AND bi.company_id = sc.company_id) AS schedule_count
    FROM re_service_charges sc
    JOIN re_leases l ON l.id = sc.lease_id
    JOIN re_units u ON u.id = l.unit_id
    JOIN re_buildings b ON b.id = u.building_id
    JOIN re_tenants t ON t.id = l.tenant_id
    LEFT JOIN re_service_charge_types sct ON sct.id = sc.charge_type_id
    WHERE sc.company_id = ?
    ORDER BY sc.start_date DESC, sc.created_at DESC
");
$appliedCharges->execute([$currentCompanyId]);
$appliedCharges = $appliedCharges->fetchAll(PDO::FETCH_ASSOC);

// Bookable leases for applying charges: active plus ended contracts
// (expired/terminated) so Extra Service can still be posted for history cleanup.
$leases = $conn->prepare("
    SELECT 
        l.id,
        l.lease_number,
        l.status AS lease_status,
        u.unit_number,
        b.name as building_name,
        t.first_name,
        t.last_name
    FROM re_leases l
    JOIN re_units u ON u.id = l.unit_id
    JOIN re_buildings b ON b.id = u.building_id
    JOIN re_tenants t ON t.id = l.tenant_id
    WHERE l.company_id = ?
      AND l.status IN ('active', 'expired', 'terminated')
    ORDER BY
        CASE l.status WHEN 'active' THEN 0 WHEN 'expired' THEN 1 ELSE 2 END,
        b.name, u.unit_number
");
$leases->execute([$currentCompanyId]);
$leases = $leases->fetchAll(PDO::FETCH_ASSOC);

// If opened from Lease View with lease_id=… ensure that row is present/selected
// even if status filtering missed an edge case.
if ($selectedLeaseId > 0) {
    $foundSelected = false;
    foreach ($leases as $leaseRow) {
        if ((int)$leaseRow['id'] === $selectedLeaseId) {
            $foundSelected = true;
            break;
        }
    }
    if (!$foundSelected) {
        $extraLease = $conn->prepare("
            SELECT
                l.id,
                l.lease_number,
                l.status AS lease_status,
                u.unit_number,
                b.name as building_name,
                t.first_name,
                t.last_name
            FROM re_leases l
            JOIN re_units u ON u.id = l.unit_id
            JOIN re_buildings b ON b.id = u.building_id
            JOIN re_tenants t ON t.id = l.tenant_id
            WHERE l.company_id = ? AND l.id = ?
              AND l.status IN ('active', 'expired', 'terminated')
            LIMIT 1
        ");
        $extraLease->execute([$currentCompanyId, $selectedLeaseId]);
        $extraRow = $extraLease->fetch(PDO::FETCH_ASSOC);
        if ($extraRow) {
            array_unshift($leases, $extraRow);
        }
    }
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Set page title and include layout
$pageTitle = 'Service Charges';
require_once __DIR__ . '/includes/re_layout_header.php';
?>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1><i class="bi bi-tag"></i> Service Charges Management</h1>
            <div>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createTypeModal">
                    <i class="bi bi-plus-circle"></i> Create Charge Type
                </button>
                <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#applyChargeModal">
                    <i class="bi bi-check-circle"></i> Apply Charge
                </button>
            </div>
        </div>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success"><?= h($_SESSION['success']) ?></div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger"><?= h($_SESSION['error']) ?></div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <div class="row">
            <!-- Service Charge Types -->
            <div class="col-md-6 mb-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-list"></i> Service Charge Types</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($chargeTypes)): ?>
                            <p class="text-muted">No service charge types configured. Create one to get started.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>Name</th>
                                            <th>Type</th>
                                            <th>Amount</th>
                                            <th>Recurring</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($chargeTypes as $type): ?>
                                            <tr>
                                                <td>
                                                    <strong><?= h($type['charge_name']) ?></strong>
                                                    <?php if ($type['charge_description']): ?>
                                                        <br><small class="text-muted"><?= h($type['charge_description']) ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= ucfirst($type['charge_type']) ?></td>
                                                <td><?= number_format($type['default_amount'], 2) ?> AED</td>
                                                <td>
                                                    <?php if ($type['is_recurring']): ?>
                                                        <span class="badge bg-info"><?= ucfirst($type['recurrence_type']) ?></span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary">One-time</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Applied Service Charges -->
            <div class="col-md-6 mb-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-check-circle"></i> Applied Charges</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($appliedCharges)): ?>
                            <p class="text-muted">No service charges applied yet.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>Charge</th>
                                            <th>Lease</th>
                                            <th>Amount</th>
                                            <th>Period</th>
                                            <th>Schedule</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($appliedCharges as $charge): ?>
                                            <tr>
                                                <td>
                                                    <strong><?= h($charge['charge_name']) ?></strong>
                                                    <?php if ($charge['type_name']): ?>
                                                        <br><small class="text-muted">Type: <?= h($charge['type_name']) ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?= h($charge['building_name']) ?> - <?= h($charge['unit_number']) ?><br>
                                                    <small class="text-muted"><?= h($charge['lease_number']) ?></small>
                                                </td>
                                                <td><?= number_format($charge['amount'], 2) ?> AED</td>
                                                <td>
                                                    <?= date('M d, Y', strtotime($charge['start_date'])) ?>
                                                    <?php if ($charge['end_date']): ?>
                                                        <br>to <?= date('M d, Y', strtotime($charge['end_date'])) ?>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if (($charge['apply_mode'] ?? 'standalone') === 'merge_first_installment'): ?>
                                                        <span class="badge bg-success">1st cheque</span>
                                                    <?php elseif (($charge['apply_mode'] ?? 'standalone') === 'split_all_installments'): ?>
                                                        <span class="badge bg-success">Split: <?= (int)$charge['schedule_count'] ?> cheque(s)</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-<?= ((int)$charge['schedule_count'] > 0) ? 'primary' : 'warning text-dark' ?>">
                                                            <?= (int)$charge['schedule_count'] ?> row(s)
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?= $charge['is_active'] ? 'success' : 'secondary' ?>">
                                                        <?= $charge['is_active'] ? 'Active' : 'Inactive' ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if (re_service_charge_apply_mode_on_schedule((string)($charge['apply_mode'] ?? 'standalone'))): ?>
                                                        <a href="lease_view.php?id=<?= (int)$charge['lease_id'] ?>" class="btn btn-sm btn-outline-secondary">
                                                            View Lease
                                                        </a>
                                                    <?php elseif ((int)$charge['schedule_count'] === 0): ?>
                                                        <form method="POST" class="d-inline">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="action" value="generate_schedule">
                                                            <input type="hidden" name="service_charge_id" value="<?= (int)$charge['id'] ?>">
                                                            <button type="submit" class="btn btn-sm btn-outline-primary">
                                                                Generate Schedule
                                                            </button>
                                                        </form>
                                                    <?php else: ?>
                                                        <a href="lease_view.php?id=<?= (int)$charge['lease_id'] ?>" class="btn btn-sm btn-outline-secondary">
                                                            View Lease
                                                        </a>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Create Type Modal -->
    <div class="modal fade" id="createTypeModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Create Service Charge Type</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create_type">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Charge Name *</label>
                            <input type="text" name="charge_name" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea name="charge_description" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Charge Type *</label>
                            <select name="charge_type" class="form-select" required>
                                <option value="fixed">Fixed Amount</option>
                                <option value="per_unit">Per Unit</option>
                                <option value="percentage">Percentage</option>
                                <option value="per_sqm">Per Square Meter</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Default Amount (AED) *</label>
                            <input type="number" step="0.01" name="default_amount" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_recurring" id="is_recurring" checked>
                                <label class="form-check-label" for="is_recurring">Recurring Charge</label>
                            </div>
                        </div>
                        <div class="mb-3" id="recurrenceTypeDiv">
                            <label class="form-label">Recurrence Type</label>
                            <select name="recurrence_type" class="form-select">
                                <option value="monthly">Monthly</option>
                                <option value="quarterly">Quarterly</option>
                                <option value="annually">Annually</option>
                                <option value="one_time">One Time</option>
                            </select>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Default VAT treatment</label>
                                <select name="vat_treatment" id="create_vat_treatment" class="form-select">
                                    <option value="company_default">Company default</option>
                                    <option value="exempt">Exempt</option>
                                    <option value="zero_rated">Zero rated</option>
                                    <option value="out_of_scope">Out of scope</option>
                                    <option value="standard">Standard rated</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Default VAT rate %</label>
                                <input type="number" step="0.01" name="vat_rate" id="create_vat_rate" class="form-control"
                                       placeholder="e.g. <?= h(number_format($scDefaultStandardVatRate, 2, '.', '')) ?>"
                                       data-default-standard-rate="<?= h(number_format($scDefaultStandardVatRate, 2, '.', '')) ?>">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Create</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Apply Charge Modal -->
    <div class="modal fade" id="applyChargeModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Apply Service Charge</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="apply_charge">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Select Lease *</label>
                            <select name="lease_id" class="form-select" required>
                                <option value="">-- Select Lease --</option>
                                <?php foreach ($leases as $lease):
                                    $leaseStatus = (string)($lease['lease_status'] ?? 'active');
                                    $statusSuffix = $leaseStatus !== 'active'
                                        ? ' [' . ucfirst($leaseStatus) . ']'
                                        : '';
                                    ?>
                                    <option value="<?= (int)$lease['id'] ?>" <?= (int)$lease['id'] === $selectedLeaseId ? 'selected' : '' ?>>
                                        <?= h($lease['lease_number']) ?> -
                                        <?= h($lease['building_name']) ?> -
                                        <?= h($lease['unit_number']) ?> -
                                        <?= h(trim(($lease['first_name'] ?? '') . ' ' . ($lease['last_name'] ?? ''))) ?>
                                        <?= h($statusSuffix) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($selectedLeaseId > 0): ?>
                                <div class="form-text">Opened from lease #<?= (int)$selectedLeaseId ?>; ended leases can still receive Extra Service bookings.</div>
                            <?php endif; ?>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Charge Type (Optional)</label>
                            <select name="charge_type_id" class="form-select">
                                <option value="">-- Select Type (or create custom) --</option>
                                <?php foreach ($chargeTypes as $type): ?>
                                    <option value="<?= $type['id'] ?>" 
                                            data-name="<?= h($type['charge_name']) ?>"
                                            data-amount="<?= $type['default_amount'] ?>"
                                            data-charge-type="<?= $type['charge_type'] ?>"
                                            data-recurring="<?= $type['is_recurring'] ?>"
                                            data-recurrence="<?= $type['recurrence_type'] ?>"
                                            data-vat-treatment="<?= h($type['vat_treatment'] ?? 'company_default') ?>"
                                            data-vat-rate="<?= h((string)($type['vat_rate'] ?? '')) ?>">
                                        <?= h($type['charge_name']) ?> (<?= number_format($type['default_amount'], 2) ?> AED)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Charge Name *</label>
                            <input type="text" name="charge_name" id="charge_name" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea name="charge_description" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Charge Type *</label>
                                <select name="charge_type" id="charge_type" class="form-select" required>
                                    <option value="fixed">Fixed Amount</option>
                                    <option value="per_unit">Per Unit</option>
                                    <option value="percentage">Percentage</option>
                                    <option value="per_sqm">Per Square Meter</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3" id="periodAmountWrap">
                                <label class="form-label">Amount per period (AED)</label>
                                <input type="number" step="0.01" name="amount" id="amount" class="form-control"
                                       title="Used for merge/split, or when contracted total is blank">
                                <div class="form-text">For merge/split: the charge amount as today. For standalone: optional if contracted total is set.</div>
                            </div>
                        </div>
                        <div class="row" id="contractedAmountWrap">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Contracted total (AED)</label>
                                <input type="number" step="0.01" name="contracted_amount" id="contracted_amount" class="form-control"
                                       placeholder="e.g. 3000 for 12 months">
                                <div class="form-text">Agreement face value. Does not set cheque count.</div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Amount basis</label>
                                <select name="amount_basis" id="amount_basis" class="form-select">
                                    <option value="vat_exclusive" selected>VAT Exclusive (contract is net)</option>
                                    <option value="vat_inclusive">VAT Inclusive (contract is gross)</option>
                                </select>
                                <div class="form-text">Snapshotted on the agreement. Exclusive: plan = net. Inclusive: plan = gross; net is derived for revenue.</div>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Expected payment method</label>
                                <select name="expected_payment_method" class="form-select">
                                    <option value="">—</option>
                                    <option value="cheque">Cheque</option>
                                    <option value="bank_transfer">Bank transfer</option>
                                    <option value="cash">Cash</option>
                                    <option value="card">Card</option>
                                    <option value="mixed">Mixed</option>
                                </select>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">VAT treatment</label>
                                <select name="vat_treatment" id="vat_treatment" class="form-select">
                                    <option value="company_default">Company default</option>
                                    <option value="exempt">Exempt</option>
                                    <option value="zero_rated">Zero rated</option>
                                    <option value="out_of_scope">Out of scope</option>
                                    <option value="standard">Standard rated</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">VAT rate % (standard only)</label>
                                <input type="number" step="0.01" name="vat_rate" id="vat_rate" class="form-control"
                                       value="" placeholder="e.g. <?= h(number_format($scDefaultStandardVatRate, 2, '.', '')) ?>"
                                       data-default-standard-rate="<?= h(number_format($scDefaultStandardVatRate, 2, '.', '')) ?>">
                            </div>
                        </div>
                        <div class="mb-3 border rounded p-3 bg-light">
                            <label class="form-label fw-semibold">How should this charge be collected?</label>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="apply_mode" id="applyModeStandalone" value="standalone" checked>
                                <label class="form-check-label" for="applyModeStandalone">
                                    <strong>Separate billing schedule</strong>
                                    <div class="small text-muted">Creates monthly billing item row(s) for revenue recognition (current default).</div>
                                </label>
                            </div>
                            <div class="form-check mt-2">
                                <input class="form-check-input" type="radio" name="apply_mode" id="applyModeFirstCheque" value="merge_first_installment">
                                <label class="form-check-label" for="applyModeFirstCheque">
                                    <strong>Add to 1st open cheque / 1st installment</strong>
                                    <div class="small text-muted">One-time charges only (e.g. AMC 800 on first cheque: 16,300 + 800 = 17,100).</div>
                                </label>
                            </div>
                            <div class="form-check mt-2">
                                <input class="form-check-input" type="radio" name="apply_mode" id="applyModeSplitCheques" value="split_all_installments">
                                <label class="form-check-label" for="applyModeSplitCheques">
                                    <strong>Split across all open cheques</strong>
                                    <div class="small text-muted">Spread evenly on pending cheques. One-time: full amount ÷ cheques. Recurring: lease-period total (amount × periods) ÷ cheques.</div>
                                </label>
                            </div>
                        </div>
                        <div class="alert alert-info small py-2 d-none" id="splitChequesHint">
                            Recurring split uses Start/End dates (or lease end) to calculate the total before dividing across open cheques.
                        </div>
                        <div class="mb-3 border rounded p-3 d-none" id="customPaymentPlanWrap">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="enable_payment_plan" id="enable_payment_plan" value="1">
                                <label class="form-check-label" for="enable_payment_plan">
                                    <strong>Custom Payment Plan</strong> (collection only — does not reduce monthly billing rows)
                                </label>
                            </div>
                            <div id="paymentPlanFields" class="d-none">
                                <div class="row">
                                    <div class="col-md-6 mb-2">
                                        <label class="form-label">Number of collections</label>
                                        <select name="payment_plan_count" id="payment_plan_count" class="form-select">
                                            <?php foreach ([1, 2, 3, 4, 6, 12] as $n): ?>
                                                <option value="<?= $n ?>" <?= $n === 6 ? 'selected' : '' ?>><?= $n ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6 mb-2 d-flex align-items-end">
                                        <button type="button" class="btn btn-outline-secondary btn-sm" id="btnPreviewPaymentPlan">Preview equal plan</button>
                                    </div>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-sm" id="paymentPlanPreviewTable">
                                        <thead><tr><th>#</th><th>Due date</th><th class="text-end">Amount</th></tr></thead>
                                        <tbody></tbody>
                                    </table>
                                </div>
                <div class="form-text">Plan total = what the tenant pays (includes VAT when standard-rated). Exclusive 3,000 + 5% → 3,150 (e.g. 6 × 525). Inclusive contracted is already gross. No cheques until Register Cheque later.</div>
                            </div>
                        </div>
                        <div class="mb-3" id="recurringApplyWrap">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_recurring" id="is_recurring_apply" checked>
                                <label class="form-check-label" for="is_recurring_apply">Recurring Charge</label>
                            </div>
                        </div>
                        <div class="mb-3" id="recurrenceTypeApplyDiv">
                            <label class="form-label">Recurrence Type</label>
                            <select name="recurrence_type" id="recurrence_type" class="form-select">
                                <option value="monthly">Monthly</option>
                                <option value="quarterly">Quarterly</option>
                                <option value="annually">Annually</option>
                                <option value="one_time">One Time</option>
                            </select>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Start Date *</label>
                                <input type="date" name="start_date" id="sc_start_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">End Date (Optional)</label>
                                <input type="date" name="end_date" id="sc_end_date" class="form-control">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">Apply Charge</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-fill form when charge type is selected
        document.getElementById('applyChargeModal').addEventListener('show.bs.modal', function() {
            const select = document.querySelector('#applyChargeModal select[name="charge_type_id"]');
            const applyModeFirst = document.getElementById('applyModeFirstCheque');
            const applyModeStandalone = document.getElementById('applyModeStandalone');
            const applyModeSplit = document.getElementById('applyModeSplitCheques');
            const recurringWrap = document.getElementById('recurringApplyWrap');
            const recurringChk = document.getElementById('is_recurring_apply');
            const recurrenceSel = document.getElementById('recurrence_type');
            const splitHint = document.getElementById('splitChequesHint');
            const planWrap = document.getElementById('customPaymentPlanWrap');
            const enablePlan = document.getElementById('enable_payment_plan');
            const planFields = document.getElementById('paymentPlanFields');

            function syncApplyModeUi() {
                const mergeFirst = applyModeFirst && applyModeFirst.checked;
                const splitAll = applyModeSplit && applyModeSplit.checked;
                const standalone = applyModeStandalone && applyModeStandalone.checked;
                if (recurringWrap) {
                    recurringWrap.style.display = mergeFirst ? 'none' : '';
                }
                if (splitHint) {
                    splitHint.classList.toggle('d-none', !splitAll);
                }
                if (planWrap) {
                    planWrap.classList.toggle('d-none', !standalone);
                }
                if (mergeFirst) {
                    if (recurringChk) recurringChk.checked = false;
                    if (recurrenceSel) recurrenceSel.value = 'one_time';
                }
            }
            function syncPlanFields() {
                if (planFields && enablePlan) {
                    planFields.classList.toggle('d-none', !enablePlan.checked);
                }
            }
            if (applyModeFirst) applyModeFirst.addEventListener('change', syncApplyModeUi);
            if (applyModeStandalone) applyModeStandalone.addEventListener('change', syncApplyModeUi);
            if (applyModeSplit) applyModeSplit.addEventListener('change', syncApplyModeUi);
            if (enablePlan) enablePlan.addEventListener('change', syncPlanFields);
            syncApplyModeUi();
            syncPlanFields();

            const vatTreatmentEl = document.getElementById('vat_treatment');
            const vatRateEl = document.getElementById('vat_rate');
            const defaultStandardVatRate = parseFloat(vatRateEl?.dataset.defaultStandardRate || '5') || 5;
            function syncVatRateForTreatment() {
                if (!vatTreatmentEl || !vatRateEl) return;
                const treatment = vatTreatmentEl.value;
                if (treatment === 'standard') {
                    vatRateEl.readOnly = false;
                    const current = parseFloat(vatRateEl.value || '0') || 0;
                    if (current <= 0.005) {
                        vatRateEl.value = defaultStandardVatRate.toFixed(2);
                    }
                } else {
                    // Non-standard treatments do not use a rate; clear for clarity.
                    vatRateEl.value = '';
                    vatRateEl.readOnly = true;
                }
            }
            if (vatTreatmentEl && !vatTreatmentEl._vatRateBound) {
                vatTreatmentEl._vatRateBound = true;
                vatTreatmentEl.addEventListener('change', syncVatRateForTreatment);
                syncVatRateForTreatment();
            }

            const btnPreview = document.getElementById('btnPreviewPaymentPlan');
            if (btnPreview && !btnPreview._bound) {
                btnPreview._bound = true;
                btnPreview.addEventListener('click', function() {
                    const contracted = parseFloat(document.getElementById('contracted_amount').value)
                        || parseFloat(document.getElementById('amount').value) || 0;
                    const basis = (document.getElementById('amount_basis')?.value || 'vat_exclusive');
                    const vatTreatment = (document.getElementById('vat_treatment')?.value || 'company_default');
                    const vatRate = parseFloat(document.getElementById('vat_rate')?.value || '0') || 0;
                    // Collectible total must cover invoices (net + VAT). Inclusive face is already gross.
                    let planTotal = Math.round(contracted * 100) / 100;
                    const isStandard = vatTreatment === 'standard'
                        || (vatTreatment === 'company_default' && vatRate > 0.005);
                    if (basis === 'vat_exclusive' && isStandard && vatRate > 0.005) {
                        const tax = Math.round(contracted * (vatRate / 100) * 100) / 100;
                        planTotal = Math.round((contracted + tax) * 100) / 100;
                    }
                    const count = parseInt(document.getElementById('payment_plan_count').value, 10) || 1;
                    const start = document.getElementById('sc_start_date').value || new Date().toISOString().slice(0, 10);
                    const end = document.getElementById('sc_end_date').value || start;
                    const base = Math.floor((planTotal / count) * 100) / 100;
                    const amounts = Array(count).fill(base);
                    amounts[count - 1] = Math.round((planTotal - base * (count - 1)) * 100) / 100;
                    const startD = new Date(start + 'T00:00:00');
                    const endD = new Date(end + 'T00:00:00');
                    const span = Math.max(0, (endD - startD) / 86400000);
                    const step = count > 1 ? span / (count - 1) : 0;
                    const tbody = document.querySelector('#paymentPlanPreviewTable tbody');
                    tbody.innerHTML = '';
                    for (let i = 0; i < count; i++) {
                        const d = new Date(startD.getTime());
                        d.setDate(d.getDate() + Math.round(step * i));
                        const due = d.toISOString().slice(0, 10);
                        const tr = document.createElement('tr');
                        tr.innerHTML = '<td>' + (i + 1) + '</td>'
                            + '<td><input type="date" class="form-control form-control-sm" name="plan_due[]" value="' + due + '"></td>'
                            + '<td><input type="number" step="0.01" class="form-control form-control-sm text-end" name="plan_amount[]" value="' + amounts[i].toFixed(2) + '"></td>';
                        tbody.appendChild(tr);
                    }
                });
            }

            select.addEventListener('change', function() {
                const option = this.options[this.selectedIndex];
                if (option.value && option.dataset.name) {
                    document.getElementById('charge_name').value = option.dataset.name;
                    document.getElementById('amount').value = option.dataset.amount;
                    document.getElementById('charge_type').value = option.dataset.chargeType;
                    document.getElementById('is_recurring_apply').checked = option.dataset.recurring === '1';
                    document.getElementById('recurrence_type').value = option.dataset.recurrence;
                    if (option.dataset.vatTreatment) {
                        document.getElementById('vat_treatment').value = option.dataset.vatTreatment;
                    }
                    if (option.dataset.vatRate !== undefined) {
                        document.getElementById('vat_rate').value = option.dataset.vatRate;
                    }
                    syncVatRateForTreatment();
                }
            });
        });

        (function bindCreateChargeVatDefaults() {
            const treatmentEl = document.getElementById('create_vat_treatment');
            const rateEl = document.getElementById('create_vat_rate');
            if (!treatmentEl || !rateEl || treatmentEl._vatRateBound) return;
            treatmentEl._vatRateBound = true;
            const defaultRate = parseFloat(rateEl.dataset.defaultStandardRate || '5') || 5;
            function sync() {
                if (treatmentEl.value === 'standard') {
                    rateEl.readOnly = false;
                    if ((parseFloat(rateEl.value || '0') || 0) <= 0.005) {
                        rateEl.value = defaultRate.toFixed(2);
                    }
                } else {
                    rateEl.value = '';
                    rateEl.readOnly = true;
                }
            }
            treatmentEl.addEventListener('change', sync);
            sync();
        })();

        <?php if ($selectedLeaseId > 0 && !empty($_GET['apply'])): ?>
        window.addEventListener('DOMContentLoaded', function() {
            const modalEl = document.getElementById('applyChargeModal');
            if (modalEl && window.bootstrap) {
                new bootstrap.Modal(modalEl).show();
            }
        });
        <?php endif; ?>
    </script>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>

