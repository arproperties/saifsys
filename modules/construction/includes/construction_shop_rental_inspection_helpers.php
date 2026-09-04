<?php
/**
 * Phase 2A — Move-out inspection (ops only; never posts GL).
 * Suggested deductions are editable before deposit settlement finalize.
 */

function co_shop_inspection_checklist_keys(): array {
    return [
        'keys' => 'Keys',
        'walls' => 'Walls',
        'paint' => 'Paint',
        'utilities' => 'Utilities',
        'ac' => 'AC',
        'cleanliness' => 'Cleanliness',
        'damages' => 'Damages',
    ];
}

function co_shop_default_checklist(): array {
    $out = [];
    foreach (co_shop_inspection_checklist_keys() as $key => $label) {
        $out[$key] = ['label' => $label, 'result' => 'pass', 'note' => ''];
    }
    return $out;
}

/**
 * Build suggested deduction amounts from failed checklist items (fully editable later).
 * @return array{damage:float,cleaning:float,utility:float,missing_keys:float,lines:list<array>}
 */
function co_shop_suggest_deductions_from_checklist(PDO $conn, int $companyId, array $checklist): array {
    $settings = co_shop_get_settings($conn, $companyId);
    $damage = 0.0;
    $cleaning = 0.0;
    $utility = 0.0;
    $missingKeys = 0.0;
    $lines = [];
    foreach ($checklist as $key => $item) {
        $result = strtolower((string)($item['result'] ?? 'pass'));
        if ($result !== 'fail') {
            continue;
        }
        $note = trim((string)($item['note'] ?? ''));
        $label = (string)($item['label'] ?? $key);
        if ($key === 'keys') {
            $amt = (float)$settings['suggested_missing_keys_amount'];
            $missingKeys += $amt;
            $lines[] = ['type' => 'missing_keys', 'label' => $label, 'amount' => $amt, 'note' => $note];
        } elseif ($key === 'cleanliness') {
            $amt = (float)$settings['suggested_cleaning_amount'];
            $cleaning += $amt;
            $lines[] = ['type' => 'cleaning', 'label' => $label, 'amount' => $amt, 'note' => $note];
        } elseif ($key === 'utilities') {
            $amt = (float)$settings['suggested_utility_amount'];
            $utility += $amt;
            $lines[] = ['type' => 'utility', 'label' => $label, 'amount' => $amt, 'note' => $note];
        } else {
            // walls, paint, ac, damages
            $amt = (float)$settings['suggested_damage_amount'];
            $damage += $amt;
            $lines[] = ['type' => 'damage', 'label' => $label, 'amount' => $amt, 'note' => $note];
        }
    }
    return [
        'damage' => round($damage, 2),
        'cleaning' => round($cleaning, 2),
        'utility' => round($utility, 2),
        'missing_keys' => round($missingKeys, 2),
        'total' => round($damage + $cleaning + $utility + $missingKeys, 2),
        'lines' => $lines,
    ];
}

function co_shop_get_inspection(PDO $conn, int $companyId, int $inspectionId): ?array {
    $stmt = $conn->prepare("SELECT * FROM co_shop_move_out_inspections WHERE id = ? AND company_id = ?");
    $stmt->execute([$inspectionId, $companyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function co_shop_latest_completed_inspection(PDO $conn, int $companyId, int $contractId): ?array {
    $stmt = $conn->prepare("
        SELECT * FROM co_shop_move_out_inspections
        WHERE company_id = ? AND contract_id = ? AND status = 'completed'
        ORDER BY completed_at DESC, id DESC LIMIT 1
    ");
    $stmt->execute([$companyId, $contractId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function co_shop_save_inspection(
    PDO $conn,
    int $companyId,
    int $contractId,
    array $input,
    ?int $userId,
    ?int $inspectionId = null
): array {
    if (!co_shop_phase2a_schema_ready($conn)) {
        throw new RuntimeException('Run migrations/construction_shop_rental_phase2a.sql');
    }
    $c = $conn->prepare("SELECT id FROM co_shop_rental_contracts WHERE id = ? AND company_id = ?");
    $c->execute([$contractId, $companyId]);
    if (!$c->fetchColumn()) {
        throw new RuntimeException('Contract not found.');
    }

    $date = $input['inspection_date'] ?? date('Y-m-d');
    $inspector = trim((string)($input['inspector_name'] ?? ''));
    if ($inspector === '') {
        throw new RuntimeException('Inspector name is required.');
    }
    $checklist = $input['checklist'] ?? co_shop_default_checklist();
    if (!is_array($checklist)) {
        $checklist = co_shop_default_checklist();
    }
    // Normalize
    $normalized = co_shop_default_checklist();
    foreach ($normalized as $key => $def) {
        if (isset($checklist[$key]) && is_array($checklist[$key])) {
            $r = strtolower((string)($checklist[$key]['result'] ?? 'pass'));
            if (!in_array($r, ['pass', 'fail', 'na'], true)) {
                $r = 'pass';
            }
            $normalized[$key]['result'] = $r;
            $normalized[$key]['note'] = trim((string)($checklist[$key]['note'] ?? ''));
        }
    }
    $suggested = co_shop_suggest_deductions_from_checklist($conn, $companyId, $normalized);
    // Allow operator override of suggested amounts on save
    if (isset($input['suggested_damage'])) {
        $suggested['damage'] = max(0, round((float)$input['suggested_damage'], 2));
    }
    if (isset($input['suggested_cleaning'])) {
        $suggested['cleaning'] = max(0, round((float)$input['suggested_cleaning'], 2));
    }
    if (isset($input['suggested_utility'])) {
        $suggested['utility'] = max(0, round((float)$input['suggested_utility'], 2));
    }
    if (isset($input['suggested_missing_keys'])) {
        $suggested['missing_keys'] = max(0, round((float)$input['suggested_missing_keys'], 2));
    }
    $suggested['total'] = round(
        $suggested['damage'] + $suggested['cleaning'] + $suggested['utility'] + $suggested['missing_keys'],
        2
    );
    $remarks = trim((string)($input['remarks'] ?? ''));
    $complete = !empty($input['complete']);

    if ($inspectionId) {
        $ex = co_shop_get_inspection($conn, $companyId, $inspectionId);
        if (!$ex || (int)$ex['contract_id'] !== $contractId) {
            throw new RuntimeException('Inspection not found.');
        }
        if ($ex['status'] === 'completed' && !$complete) {
            throw new RuntimeException('Completed inspections cannot be reopened here.');
        }
        $conn->prepare("
            UPDATE co_shop_move_out_inspections SET
                inspection_date = ?, inspector_name = ?, inspector_user_id = ?,
                checklist_json = ?, suggested_deductions_json = ?, remarks = ?,
                status = ?, completed_at = ?
            WHERE id = ? AND company_id = ?
        ")->execute([
            $date,
            $inspector,
            $userId,
            json_encode($normalized, JSON_UNESCAPED_UNICODE),
            json_encode($suggested, JSON_UNESCAPED_UNICODE),
            $remarks ?: null,
            $complete ? 'completed' : 'draft',
            $complete ? date('Y-m-d H:i:s') : null,
            $inspectionId,
            $companyId,
        ]);
        $id = $inspectionId;
    } else {
        $conn->prepare("
            INSERT INTO co_shop_move_out_inspections
                (company_id, contract_id, inspection_date, inspector_name, inspector_user_id,
                 checklist_json, suggested_deductions_json, remarks, status, completed_at, created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)
        ")->execute([
            $companyId,
            $contractId,
            $date,
            $inspector,
            $userId,
            json_encode($normalized, JSON_UNESCAPED_UNICODE),
            json_encode($suggested, JSON_UNESCAPED_UNICODE),
            $remarks ?: null,
            $complete ? 'completed' : 'draft',
            $complete ? date('Y-m-d H:i:s') : null,
            $userId,
        ]);
        $id = (int)$conn->lastInsertId();
    }

    if ($complete) {
        co_shop_log_event($conn, $companyId, $contractId, 'inspection_completed', [
            'inspection_id' => $id,
            'suggested_total' => $suggested['total'],
        ], $userId);
    } else {
        co_shop_log_event($conn, $companyId, $contractId, 'inspection_saved', ['inspection_id' => $id], $userId);
    }

    return co_shop_get_inspection($conn, $companyId, $id);
}

function co_shop_add_inspection_file(
    PDO $conn,
    int $companyId,
    int $inspectionId,
    array $uploadResult,
    ?int $userId
): void {
    $insp = co_shop_get_inspection($conn, $companyId, $inspectionId);
    if (!$insp) {
        throw new RuntimeException('Inspection not found.');
    }
    if (!empty($uploadResult['error'])) {
        throw new RuntimeException($uploadResult['error']);
    }
    $path = $uploadResult['path'] ?? '';
    if ($path === '') {
        throw new RuntimeException('Upload path missing.');
    }
    $conn->prepare("
        INSERT INTO co_shop_move_out_inspection_files
            (company_id, inspection_id, file_path, original_name, mime_type, uploaded_by)
        VALUES (?,?,?,?,?,?)
    ")->execute([
        $companyId,
        $inspectionId,
        $path,
        $uploadResult['original_name'] ?? null,
        $uploadResult['mime'] ?? null,
        $userId,
    ]);
}

function co_shop_inspection_files(PDO $conn, int $companyId, int $inspectionId): array {
    $stmt = $conn->prepare("
        SELECT * FROM co_shop_move_out_inspection_files
        WHERE company_id = ? AND inspection_id = ? ORDER BY id
    ");
    $stmt->execute([$companyId, $inspectionId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
