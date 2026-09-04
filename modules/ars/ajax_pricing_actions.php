<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/includes/ars_helpers.php';

header('Content-Type: application/json');

require_once __DIR__ . '/includes/ars_permissions.php';
$arsCompanyId = arsPageAuth($conn);
ars_ajax_csrf_verify();
$userId = current_user_id();
$action = $_POST['action'] ?? '';

try {
    switch ($action) {

        case 'add_rule': {
            $name        = trim($_POST['name'] ?? '');
            $ruleType    = $_POST['rule_type'] ?? '';
            $unitId      = !empty($_POST['unit_id']) ? (int)$_POST['unit_id'] : null;
            $startDate   = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
            $endDate     = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
            $rateAmount  = ($_POST['rate_amount'] ?? '') !== '' ? (float)$_POST['rate_amount'] : null;
            $rateMod     = ($_POST['rate_modifier'] ?? '') !== '' ? (float)$_POST['rate_modifier'] : null;
            $minNights   = ($_POST['min_nights'] ?? '') !== '' ? (int)$_POST['min_nights'] : null;
            $discountPct = ($_POST['discount_percent'] ?? '') !== '' ? (float)$_POST['discount_percent'] : null;
            $priority    = (int)($_POST['priority'] ?? 0);

            if (!$name) { echo json_encode(['success' => false, 'error' => 'Rule name is required.']); exit; }
            if (!in_array($ruleType, ['seasonal','weekend','length_discount','minimum_stay'])) {
                echo json_encode(['success' => false, 'error' => 'Invalid rule type.']); exit;
            }

            if (in_array($ruleType, ['seasonal']) && (!$startDate || !$endDate)) {
                echo json_encode(['success' => false, 'error' => 'Seasonal rules require start and end dates.']); exit;
            }
            if (in_array($ruleType, ['length_discount','minimum_stay']) && (!$minNights || $minNights < 1)) {
                echo json_encode(['success' => false, 'error' => 'Minimum nights is required for this rule type.']); exit;
            }
            if ($ruleType === 'length_discount' && (!$discountPct || $discountPct <= 0)) {
                echo json_encode(['success' => false, 'error' => 'Discount percentage must be greater than 0.']); exit;
            }

            $conn->prepare("
                INSERT INTO ars_pricing_rules
                    (company_id, unit_id, rule_type, name, start_date, end_date,
                     rate_amount, rate_modifier, min_nights, discount_percent, priority, created_by)
                VALUES (?,?,?,?,?,?, ?,?,?,?,?,?)
            ")->execute([
                $arsCompanyId, $unitId, $ruleType, $name, $startDate, $endDate,
                $rateAmount, $rateMod, $minNights, $discountPct, $priority, $userId
            ]);
            echo json_encode(['success' => true, 'id' => (int)$conn->lastInsertId()]);
            break;
        }

        case 'edit_rule': {
            $ruleId      = (int)($_POST['rule_id'] ?? 0);
            $name        = trim($_POST['name'] ?? '');
            $ruleType    = $_POST['rule_type'] ?? '';
            $unitId      = !empty($_POST['unit_id']) ? (int)$_POST['unit_id'] : null;
            $startDate   = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
            $endDate     = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
            $rateAmount  = ($_POST['rate_amount'] ?? '') !== '' ? (float)$_POST['rate_amount'] : null;
            $rateMod     = ($_POST['rate_modifier'] ?? '') !== '' ? (float)$_POST['rate_modifier'] : null;
            $minNights   = ($_POST['min_nights'] ?? '') !== '' ? (int)$_POST['min_nights'] : null;
            $discountPct = ($_POST['discount_percent'] ?? '') !== '' ? (float)$_POST['discount_percent'] : null;
            $priority    = (int)($_POST['priority'] ?? 0);

            if (!$ruleId || !$name) { echo json_encode(['success' => false, 'error' => 'Rule ID and name are required.']); exit; }

            $conn->prepare("
                UPDATE ars_pricing_rules SET
                    name = ?, rule_type = ?, unit_id = ?, start_date = ?, end_date = ?,
                    rate_amount = ?, rate_modifier = ?, min_nights = ?, discount_percent = ?,
                    priority = ?, updated_at = NOW()
                WHERE id = ? AND company_id = ?
            ")->execute([
                $name, $ruleType, $unitId, $startDate, $endDate,
                $rateAmount, $rateMod, $minNights, $discountPct,
                $priority, $ruleId, $arsCompanyId
            ]);
            echo json_encode(['success' => true]);
            break;
        }

        case 'delete_rule': {
            $ruleId = (int)($_POST['rule_id'] ?? 0);
            if (!$ruleId) { echo json_encode(['success' => false, 'error' => 'Invalid rule ID.']); exit; }
            $conn->prepare("DELETE FROM ars_pricing_rules WHERE id = ? AND company_id = ?")->execute([$ruleId, $arsCompanyId]);
            echo json_encode(['success' => true]);
            break;
        }

        case 'toggle_rule': {
            $ruleId = (int)($_POST['rule_id'] ?? 0);
            if (!$ruleId) { echo json_encode(['success' => false, 'error' => 'Invalid rule ID.']); exit; }
            $conn->prepare("UPDATE ars_pricing_rules SET is_active = NOT is_active, updated_at = NOW() WHERE id = ? AND company_id = ?")->execute([$ruleId, $arsCompanyId]);
            echo json_encode(['success' => true]);
            break;
        }

        case 'add_promo': {
            $code        = strtoupper(trim($_POST['code'] ?? ''));
            $description = trim($_POST['description'] ?? '');
            $discType    = $_POST['discount_type'] ?? '';
            $discValue   = (float)($_POST['discount_value'] ?? 0);
            $maxDiscAmt  = ($_POST['max_discount_amount'] ?? '') !== '' ? (float)$_POST['max_discount_amount'] : null;
            $maxUses     = ($_POST['max_uses'] ?? '') !== '' ? (int)$_POST['max_uses'] : null;
            $validFrom   = !empty($_POST['valid_from']) ? $_POST['valid_from'] : null;
            $validTo     = !empty($_POST['valid_to']) ? $_POST['valid_to'] : null;
            $minNights   = ($_POST['min_nights'] ?? '') !== '' ? (int)$_POST['min_nights'] : null;
            $minAmount   = ($_POST['min_amount'] ?? '') !== '' ? (float)$_POST['min_amount'] : null;

            if (!$code) { echo json_encode(['success' => false, 'error' => 'Promo code is required.']); exit; }
            if (!in_array($discType, ['percentage','fixed'])) { echo json_encode(['success' => false, 'error' => 'Invalid discount type.']); exit; }
            if ($discValue <= 0) { echo json_encode(['success' => false, 'error' => 'Discount value must be greater than 0.']); exit; }
            if ($discType === 'percentage' && $discValue > 100) { echo json_encode(['success' => false, 'error' => 'Percentage cannot exceed 100%.']); exit; }

            $existing = $conn->prepare("SELECT id FROM ars_promo_codes WHERE company_id = ? AND code = ?");
            $existing->execute([$arsCompanyId, $code]);
            if ($existing->fetch()) { echo json_encode(['success' => false, 'error' => 'Promo code "' . $code . '" already exists.']); exit; }

            $conn->prepare("
                INSERT INTO ars_promo_codes
                    (company_id, code, description, discount_type, discount_value, max_discount_amount,
                     max_uses, valid_from, valid_to, min_nights, min_amount, created_by)
                VALUES (?,?,?,?,?,?, ?,?,?,?,?,?)
            ")->execute([
                $arsCompanyId, $code, $description ?: null, $discType, $discValue, $maxDiscAmt,
                $maxUses, $validFrom, $validTo, $minNights, $minAmount, $userId
            ]);
            echo json_encode(['success' => true, 'id' => (int)$conn->lastInsertId()]);
            break;
        }

        case 'edit_promo': {
            $promoId     = (int)($_POST['promo_id'] ?? 0);
            $code        = strtoupper(trim($_POST['code'] ?? ''));
            $description = trim($_POST['description'] ?? '');
            $discType    = $_POST['discount_type'] ?? '';
            $discValue   = (float)($_POST['discount_value'] ?? 0);
            $maxDiscAmt  = ($_POST['max_discount_amount'] ?? '') !== '' ? (float)$_POST['max_discount_amount'] : null;
            $maxUses     = ($_POST['max_uses'] ?? '') !== '' ? (int)$_POST['max_uses'] : null;
            $validFrom   = !empty($_POST['valid_from']) ? $_POST['valid_from'] : null;
            $validTo     = !empty($_POST['valid_to']) ? $_POST['valid_to'] : null;
            $minNights   = ($_POST['min_nights'] ?? '') !== '' ? (int)$_POST['min_nights'] : null;
            $minAmount   = ($_POST['min_amount'] ?? '') !== '' ? (float)$_POST['min_amount'] : null;

            if (!$promoId || !$code) { echo json_encode(['success' => false, 'error' => 'Promo ID and code are required.']); exit; }

            $existing = $conn->prepare("SELECT id FROM ars_promo_codes WHERE company_id = ? AND code = ? AND id != ?");
            $existing->execute([$arsCompanyId, $code, $promoId]);
            if ($existing->fetch()) { echo json_encode(['success' => false, 'error' => 'Another promo with code "' . $code . '" exists.']); exit; }

            $conn->prepare("
                UPDATE ars_promo_codes SET
                    code = ?, description = ?, discount_type = ?, discount_value = ?,
                    max_discount_amount = ?, max_uses = ?, valid_from = ?, valid_to = ?,
                    min_nights = ?, min_amount = ?
                WHERE id = ? AND company_id = ?
            ")->execute([
                $code, $description ?: null, $discType, $discValue,
                $maxDiscAmt, $maxUses, $validFrom, $validTo,
                $minNights, $minAmount,
                $promoId, $arsCompanyId
            ]);
            echo json_encode(['success' => true]);
            break;
        }

        case 'delete_promo': {
            $promoId = (int)($_POST['promo_id'] ?? 0);
            if (!$promoId) { echo json_encode(['success' => false, 'error' => 'Invalid promo ID.']); exit; }
            $conn->prepare("DELETE FROM ars_promo_codes WHERE id = ? AND company_id = ?")->execute([$promoId, $arsCompanyId]);
            echo json_encode(['success' => true]);
            break;
        }

        case 'toggle_promo': {
            $promoId = (int)($_POST['promo_id'] ?? 0);
            if (!$promoId) { echo json_encode(['success' => false, 'error' => 'Invalid promo ID.']); exit; }
            $conn->prepare("UPDATE ars_promo_codes SET is_active = NOT is_active WHERE id = ? AND company_id = ?")->execute([$promoId, $arsCompanyId]);
            echo json_encode(['success' => true]);
            break;
        }

        case 'validate_promo': {
            require_once __DIR__ . '/includes/ars_pricing.php';
            $code     = strtoupper(trim($_POST['code'] ?? ''));
            $nights   = (int)($_POST['nights'] ?? 0);
            $subtotal = (float)($_POST['subtotal'] ?? 0);
            if (!$code) { echo json_encode(['success' => false, 'error' => 'Code is required.']); exit; }
            $result = ars_validate_promo_code($conn, $arsCompanyId, $code, $nights, $subtotal);
            if ($result['valid']) {
                $p = $result['promo'];
                echo json_encode(['success' => true, 'promo' => [
                    'id'                  => (int)$p['id'],
                    'code'                => $p['code'],
                    'discount_type'       => $p['discount_type'],
                    'discount_value'      => (float)$p['discount_value'],
                    'max_discount_amount' => $p['max_discount_amount'] !== null ? (float)$p['max_discount_amount'] : null,
                    'description'         => $p['description'],
                ]]);
            } else {
                echo json_encode(['success' => false, 'error' => $result['error']]);
            }
            break;
        }

        case 'get_price_preview': {
            require_once __DIR__ . '/includes/ars_pricing.php';
            $unitId    = (int)($_POST['unit_id'] ?? 0);
            $checkIn   = $_POST['check_in'] ?? '';
            $checkOut  = $_POST['check_out'] ?? '';
            $overrideVal = ($_POST['rate_override'] ?? '');
            $rateOverride = ($overrideVal !== '' && (float)$overrideVal > 0) ? (float)$overrideVal : null;

            $pricingMode = $_POST['pricing_mode'] ?? 'nightly';
            if (!in_array($pricingMode, ['nightly', 'monthly_package', 'manual_total'], true)) {
                $pricingMode = 'nightly';
            }
            $vatMode = ars_normalize_vat_mode($_POST['vat_mode'] ?? 'exclusive');
            $manualTotalAmt = max(0, (float)($_POST['manual_total_amount'] ?? 0));

            if (!$unitId || !$checkIn || !$checkOut) {
                echo json_encode(['success' => false, 'error' => 'Unit and dates required.']); exit;
            }
            $nights = (int)((new DateTime($checkOut))->diff(new DateTime($checkIn))->days);
            if ($nights < 1) { echo json_encode(['success' => false, 'error' => 'Invalid dates.']); exit; }

            $stmt = $conn->prepare("SELECT nightly_rate, COALESCE(monthly_rate, 0) AS monthly_rate FROM re_units WHERE id = ?");
            $stmt->execute([$unitId]);
            $ur = $stmt->fetch(PDO::FETCH_ASSOC);
            $baseRate = (float)($ur['nightly_rate'] ?? 0);
            $monthlyRt = (float)($ur['monthly_rate'] ?? 0);

            $settings = getArsSettings($conn, $arsCompanyId);
            $vatRate = (float)$settings['default_vat_rate'];

            $minStayErr = ars_validate_minimum_stay($conn, $arsCompanyId, $unitId, $checkIn, $checkOut, $nights);
            if ($minStayErr) {
                echo json_encode(['success' => false, 'error' => $minStayErr]); exit;
            }

            $discountParam = [];
            $promoCode = trim($_POST['promo_code'] ?? '');
            $manualDiscType = $_POST['manual_discount_type'] ?? '';
            $manualDiscValue = (float)($_POST['manual_discount_value'] ?? 0);

            if ($promoCode) {
                $baseSubtotal = 0.0;
                if ($pricingMode === 'manual_total' && $manualTotalAmt > 0) {
                    $baseSubtotal = $manualTotalAmt;
                } elseif ($pricingMode === 'monthly_package' && $monthlyRt > 0) {
                    $baseSubtotal = round($monthlyRt * ($nights / 30.0), 2);
                } elseif ($pricingMode === 'nightly' && !$rateOverride) {
                    $nb = ars_get_nightly_breakdown($conn, $arsCompanyId, $unitId, $checkIn, $checkOut, $baseRate);
                    foreach ($nb as $n) {
                        $baseSubtotal += $n['rate'];
                    }
                } else {
                    $baseSubtotal = ($rateOverride ?? $baseRate) * $nights;
                }
                $baseSubtotal = round($baseSubtotal, 2);
                $promoResult = ars_validate_promo_code($conn, $arsCompanyId, $promoCode, $nights, $baseSubtotal);
                if ($promoResult['valid']) {
                    $p = $promoResult['promo'];
                    $discountParam = [
                        'type'                => 'promo',
                        'discount_type'       => $p['discount_type'],
                        'discount_value'      => (float)$p['discount_value'],
                        'max_discount_amount' => $p['max_discount_amount'] !== null ? (float)$p['max_discount_amount'] : null,
                        'promo_id'            => (int)$p['id'],
                        'label'               => $p['code'] . ' (' . ($p['discount_type'] === 'percentage' ? $p['discount_value'] . '%' : 'AED ' . number_format((float)$p['discount_value'], 2)) . ($p['max_discount_amount'] !== null ? ', max AED ' . number_format((float)$p['max_discount_amount'], 2) : '') . ')',
                    ];
                }
            } elseif ($manualDiscType && $manualDiscValue > 0) {
                $discountParam = [
                    'type'           => 'manual',
                    'discount_type'  => $manualDiscType,
                    'discount_value' => $manualDiscValue,
                    'label'          => 'Manual ' . ($manualDiscType === 'percentage' ? $manualDiscValue . '%' : 'AED ' . number_format($manualDiscValue, 2)),
                ];
            }

            $pricing = ars_calculate_booking_price_v3(
                $conn,
                $arsCompanyId,
                $unitId,
                $baseRate,
                $monthlyRt,
                $nights,
                $checkIn,
                $checkOut,
                $pricingMode === 'nightly' ? $rateOverride : null,
                [],
                $vatRate,
                $discountParam,
                [
                    'pricing_mode'   => $pricingMode,
                    'vat_mode'       => $vatMode,
                    'entered_amount' => $manualTotalAmt,
                ]
            );
            if (!empty($pricing['calc_error'])) {
                echo json_encode(['success' => false, 'error' => $pricing['calc_error']]); exit;
            }
            echo json_encode(['success' => true, 'pricing' => $pricing]);
            break;
        }

        default:
            echo json_encode(['success' => false, 'error' => 'Unknown action.']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}
