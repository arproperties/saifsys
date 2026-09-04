<?php
/**
 * Shared controller for material request create (all modules).
 */
require_once dirname(__DIR__) . '/branding.php';
require_once __DIR__ . '/inv_requests.php';
require_once __DIR__ . '/inv_request_links.php';
require_once __DIR__ . '/inv_request_create_helpers.php';

/**
 * Process POST; returns ['redirect_id' => int|null, 'message' => string, 'messageType' => string].
 *
 * @param callable $formVal
 * @param array<int,int> $itemBaseUom
 * @return array{redirect_id:?int,message:string,messageType:string}
 */
function inv_request_create_handle_post(PDO $conn, int $companyId, callable $formVal, array $itemBaseUom): array {
    $out = ['redirect_id' => null, 'message' => '', 'messageType' => ''];
    csrf_verify();
    $requestDate = trim($formVal('request_date', date('Y-m-d')));
    $sourceModule = strtolower(trim($formVal('source_module', 'inventory')));
    $sourceTable = trim($formVal('source_table')) ?: null;
    $sourceId = trim($formVal('source_id')) !== '' ? (int)$formVal('source_id') : null;
    $locationRaw = trim($formVal('location_from_id', ''));
    $locationFrom = $locationRaw !== '' ? (int)$locationRaw : 0;
    $notes = trim($formVal('notes')) ?: null;

    $ctx = [
        'context_building_id' => trim($formVal('context_building_id')) !== '' ? (int)$formVal('context_building_id') : null,
        'context_unit_id' => trim($formVal('context_unit_id')) !== '' ? (int)$formVal('context_unit_id') : null,
        'context_project_id' => trim($formVal('context_project_id')) !== '' ? (int)$formVal('context_project_id') : null,
        'context_booking_id' => trim($formVal('context_booking_id')) !== '' ? (int)$formVal('context_booking_id') : null,
        'context_work_order_id' => trim($formVal('context_work_order_id')) !== '' ? (int)$formVal('context_work_order_id') : null,
        'context_housekeeping_id' => trim($formVal('context_housekeeping_id')) !== '' ? (int)$formVal('context_housekeeping_id') : null,
        'context_cleaning_job_id' => trim($formVal('context_cleaning_job_id')) !== '' ? (int)$formVal('context_cleaning_job_id') : null,
    ];

    $lineItemIds = $_POST['line_item_id'] ?? [];
    $lineUomIds = $_POST['line_uom_id'] ?? [];
    $lineQtys = $_POST['line_qty'] ?? [];
    $lineLots = $_POST['line_lot'] ?? [];
    $lineExps = $_POST['line_expiry'] ?? [];
    $lineSers = $_POST['line_serial'] ?? [];

    $lines = [];
    $n = max(count($lineItemIds), count($lineQtys));
    for ($i = 0; $i < $n; $i++) {
        $itemId = isset($lineItemIds[$i]) ? (int)$lineItemIds[$i] : 0;
        $qty = isset($lineQtys[$i]) ? (float)$lineQtys[$i] : 0;
        if ($itemId <= 0 || $qty <= 0) {
            continue;
        }
        $uomId = isset($lineUomIds[$i]) ? (int)$lineUomIds[$i] : 0;
        if (!$uomId && isset($itemBaseUom[$itemId])) {
            $uomId = $itemBaseUom[$itemId];
        }
        if (!$uomId) {
            return ['redirect_id' => null, 'message' => 'Each line needs a UoM (or set item base UoM).', 'messageType' => 'warning'];
        }
        $lines[] = [
            'item_id' => $itemId,
            'uom_id' => $uomId,
            'requested_qty' => $qty,
            'lot_number' => isset($lineLots[$i]) ? trim((string)$lineLots[$i]) : '',
            'expiry_date' => isset($lineExps[$i]) && trim((string)$lineExps[$i]) !== '' ? trim((string)$lineExps[$i]) : null,
            'serial_number' => isset($lineSers[$i]) ? trim((string)$lineSers[$i]) : '',
        ];
    }

    if (!$lines) {
        return ['redirect_id' => null, 'message' => 'Add at least one line with item and quantity.', 'messageType' => 'warning'];
    }

    $header = array_merge([
        'company_id' => $companyId,
        'request_date' => $requestDate,
        'request_type' => 'issue',
        'source_module' => $sourceModule,
        'source_table' => $sourceTable,
        'source_id' => $sourceId,
        'location_from_id' => $locationFrom > 0 ? $locationFrom : null,
        'requested_by' => current_user_id(),
        'notes' => $notes,
    ], $ctx);

    try {
        $newId = inv_request_create($conn, $header, $lines);
        $notifyModule = strtolower(trim((string)($header['source_module'] ?? '')));
        if ($notifyModule !== '' && $notifyModule !== 'inventory') {
            $emailHelper = dirname(__DIR__, 2) . '/modules/realestate/includes/re_email_helper.php';
            if (is_readable($emailHelper)) {
                require_once $emailHelper;
                if (function_exists('send_inventory_material_request_notification')) {
                    try {
                        send_inventory_material_request_notification($conn, $newId, $companyId);
                    } catch (Throwable $mailEx) {
                        error_log('send_inventory_material_request_notification: ' . $mailEx->getMessage());
                    }
                }
            }
        }
        return ['redirect_id' => $newId, 'message' => '', 'messageType' => 'success'];
    } catch (Throwable $e) {
        return ['redirect_id' => null, 'message' => $e->getMessage(), 'messageType' => 'warning'];
    }
}

/**
 * @return array<string,mixed>
 */
function inv_request_create_bootstrap(PDO $conn, string $embedMode): array {
    inv_require_material_request_create_page($conn);

    $brand = getBrandSettings($conn);
    $companyId = current_company_id($conn) ?: 0;
    $message = $messageType = '';

    $prefill = inv_request_parse_prefill_from_get();

    $formVal = function (string $key, $default = '') use ($prefill): string {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $v = $_POST[$key] ?? null;
            return $v !== null && $v !== '' ? (string)$v : (string)$default;
        }
        if ($key === 'notes' && !empty($prefill['notes_hint'])) {
            return (string)$prefill['notes_hint'];
        }
        if (isset($prefill[$key]) && $prefill[$key] !== '' && $prefill[$key] !== null) {
            return (string)$prefill[$key];
        }
        return (string)$default;
    };

    $items = [];
    $uoms = [];
    $itemBaseUom = [];

    if ($companyId) {
        $stmt = $conn->prepare("SELECT id, item_code, name, base_uom_id FROM inv_items WHERE company_id = ? AND is_active = 1 ORDER BY name LIMIT 500");
        $stmt->execute([$companyId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $uoms = $conn->query("SELECT id, code, name FROM inv_uoms WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($items as $it) {
            $itemBaseUom[(int)$it['id']] = (int)($it['base_uom_id'] ?? 0);
        }
    }

    require_once dirname(__DIR__) . '/url_helper.php';
    $appRoot = get_application_web_root();
    $base = $appRoot !== '' ? $appRoot : '';
    $myMaterialRequestsUrlMap = [
        'realestate' => $base . '/modules/realestate/my_material_requests.php',
        'construction' => $base . '/modules/construction/my_material_requests.php',
        'ars' => $base . '/modules/ars/my_material_requests.php',
        'cleaning' => $base . '/operation/my_material_requests.php',
        'inventory' => $base . '/modules/inventory/my_material_requests.php',
    ];
    $myMaterialRequestsUrl = $myMaterialRequestsUrlMap[$embedMode] ?? null;

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $companyId) {
        $res = inv_request_create_handle_post($conn, $companyId, $formVal, $itemBaseUom);
        if (!empty($res['redirect_id'])) {
            require_once dirname(__DIR__) . '/url_helper.php';
            $root = get_application_web_root();
            header('Location: ' . ($root !== '' ? $root : '') . '/modules/inventory/request_view.php?id=' . (int)$res['redirect_id']);
            exit;
        }
        $message = $res['message'] ?? '';
        $messageType = $res['messageType'] ?? 'warning';
    }

    $sm = strtolower(trim($formVal('source_module', $prefill['source_module'] ?? 'inventory')));
    $fromIntegration = $sm !== '' && $sm !== 'inventory';

    $ctxIds = [
        'context_building_id' => trim($formVal('context_building_id')) !== '' ? (int)$formVal('context_building_id') : null,
        'context_unit_id' => trim($formVal('context_unit_id')) !== '' ? (int)$formVal('context_unit_id') : null,
        'context_project_id' => trim($formVal('context_project_id')) !== '' ? (int)$formVal('context_project_id') : null,
        'context_booking_id' => trim($formVal('context_booking_id')) !== '' ? (int)$formVal('context_booking_id') : null,
        'context_work_order_id' => trim($formVal('context_work_order_id')) !== '' ? (int)$formVal('context_work_order_id') : null,
        'context_housekeeping_id' => trim($formVal('context_housekeeping_id')) !== '' ? (int)$formVal('context_housekeeping_id') : null,
        'context_cleaning_job_id' => trim($formVal('context_cleaning_job_id')) !== '' ? (int)$formVal('context_cleaning_job_id') : null,
    ];
    $contextLabels = $companyId ? inv_request_resolve_context_labels($conn, $companyId, $ctxIds) : [];

    $visibleContextKeys = inv_material_request_visible_context_keys($sm);
    if ($embedMode !== 'inventory') {
        $visibleContextKeys = array_values(array_filter($visibleContextKeys, function ($k) use ($formVal) {
            return trim($formVal($k)) !== '';
        }));
    }

    $contextHelp = [
        'realestate' => 'Real Estate requests require a building (and unit where applicable).',
        'construction' => 'Construction requests require a project.',
        'ars' => 'ARS requests require building and unit; booking/housekeeping when applicable.',
        'cleaning' => 'Optional: link to a work order, or leave blank for a general stock request.',
        'inventory' => 'Optional operational IDs for reporting and traceability.',
    ];
    $contextHelpText = $contextHelp[$sm] ?? $contextHelp['inventory'];

    return [
        'brand' => $brand,
        'companyId' => $companyId,
        'message' => $message,
        'messageType' => $messageType,
        'formVal' => $formVal,
        'prefill' => $prefill,
        'prefillModule' => $sm,
        'fromIntegration' => $fromIntegration,
        'locations' => [],
        'items' => $items,
        'uoms' => $uoms,
        'embedMode' => $embedMode,
        'showSourceModulePicker' => $embedMode === 'inventory',
        'showSourceReference' => $embedMode === 'inventory',
        'visibleContextKeys' => $visibleContextKeys,
        'contextLabels' => $contextLabels,
        'contextHelp' => $contextHelpText,
        'initialLineRows' => 4,
        'myMaterialRequestsUrl' => $myMaterialRequestsUrl,
    ];
}
