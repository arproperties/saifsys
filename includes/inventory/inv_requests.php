<?php
/**
 * Phase 3 — Material requests: create, reject, approve + issue (single transaction).
 */

require_once __DIR__ . '/inv_helpers.php';
require_once __DIR__ . '/inv_queries.php';
require_once __DIR__ . '/inv_posting.php';
require_once __DIR__ . '/inv_purchasing.php';

function inv_generate_request_no(PDO $conn, int $companyId, string $date): string {
    $year = date('Y', strtotime($date));
    $seqKey = 'INV-REQ-' . $year;
    $n = inv_purchasing_next_seq($conn, $companyId, $seqKey);
    return 'INV-REQ-' . $year . '-' . str_pad((string)$n, 5, '0', STR_PAD_LEFT);
}

/**
 * @param array $h Request header row or create payload
 */
function inv_validate_request_context(array $h): void {
    $m = strtolower(trim((string)($h['source_module'] ?? '')));
    if ($m === 'realestate' || $m === 'real_estate') {
        if (empty($h['context_building_id'])) {
            throw new RuntimeException('Building is required for Real Estate requests');
        }
    }
    if ($m === 'construction') {
        if (empty($h['context_project_id'])) {
            throw new RuntimeException('Project is required for Construction requests');
        }
    }
    if ($m === 'ars') {
        if (empty($h['context_building_id']) || empty($h['context_unit_id'])) {
            throw new RuntimeException('Building and unit are required for ARS requests');
        }
    }
}

function inv_request_create(PDO $conn, array $header, array $lines): int {
    $companyId = (int)($header['company_id'] ?? 0);
    if ($companyId <= 0) {
        throw new RuntimeException('company_id is required');
    }
    inv_validate_request_context($header);

    $reqDate = $header['request_date'] ?? date('Y-m-d');
    $reqNo = trim((string)($header['request_no'] ?? ''));
    if ($reqNo === '') {
        $started = !$conn->inTransaction();
        if ($started) {
            $conn->beginTransaction();
        }
        $reqNo = inv_generate_request_no($conn, $companyId, $reqDate);
        if ($started) {
            $conn->commit();
        }
    }

    $ctxJson = $header['context_json'] ?? null;
    if (is_array($ctxJson)) {
        $ctxJson = json_encode($ctxJson, JSON_UNESCAPED_UNICODE);
    }

    $stmt = $conn->prepare("
        INSERT INTO inv_request_headers
            (company_id, request_no, request_date, request_type, source_module, source_table, source_id, location_from_id,
             status, requested_by, notes,
             context_building_id, context_unit_id, context_project_id, context_booking_id,
             context_work_order_id, context_housekeeping_id, context_cleaning_job_id, context_json)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");
    $stmt->execute([
        $companyId,
        $reqNo,
        $reqDate,
        $header['request_type'] ?? 'issue',
        $header['source_module'],
        $header['source_table'] ?? null,
        $header['source_id'] ?? null,
        isset($header['location_from_id']) && $header['location_from_id'] !== '' && (int)$header['location_from_id'] > 0
            ? (int)$header['location_from_id']
            : null,
        'pending',
        $header['requested_by'] ?? null,
        $header['notes'] ?? null,
        $header['context_building_id'] ?? null,
        $header['context_unit_id'] ?? null,
        $header['context_project_id'] ?? null,
        $header['context_booking_id'] ?? null,
        $header['context_work_order_id'] ?? null,
        $header['context_housekeeping_id'] ?? null,
        $header['context_cleaning_job_id'] ?? null,
        $ctxJson,
    ]);
    $hid = (int)$conn->lastInsertId();

    $sort = 0;
    foreach ($lines as $ln) {
        $sort++;
        $stmt = $conn->prepare("
            INSERT INTO inv_request_lines
                (header_id, company_id, item_id, uom_id, requested_qty, lot_number, expiry_date, serial_number, sort_order, line_notes)
            VALUES (?,?,?,?,?,?,?,?,?,?)
        ");
        $stmt->execute([
            $hid,
            $companyId,
            (int)$ln['item_id'],
            (int)$ln['uom_id'],
            (float)$ln['requested_qty'],
            isset($ln['lot_number']) && $ln['lot_number'] !== '' ? trim((string)$ln['lot_number']) : null,
            $ln['expiry_date'] ?? null,
            isset($ln['serial_number']) && $ln['serial_number'] !== '' ? trim((string)$ln['serial_number']) : null,
            (int)($ln['sort_order'] ?? $sort),
            $ln['line_notes'] ?? null,
        ]);
    }

    return $hid;
}

function inv_request_reject(PDO $conn, int $requestId, int $companyId, int $userId, ?string $reason): array {
    try {
        $conn->beginTransaction();
        $stmt = $conn->prepare("SELECT id, status FROM inv_request_headers WHERE id = ? AND company_id = ? FOR UPDATE");
        $stmt->execute([$requestId, $companyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('Request not found');
        }
        if (($row['status'] ?? '') !== 'pending') {
            throw new RuntimeException('Only pending requests can be rejected');
        }
        $conn->prepare("
            UPDATE inv_request_headers
            SET status = 'rejected', rejected_by = ?, rejected_at = ?, rejection_reason = ?, updated_at = ?
            WHERE id = ? AND company_id = ?
        ")->execute([
            $userId ?: null,
            inv_now(),
            $reason !== null && $reason !== '' ? $reason : null,
            inv_now(),
            $requestId,
            $companyId,
        ]);
        $conn->commit();
        return ['success' => true, 'error' => null];
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Approve quantities (per line id) and post issue in one transaction.
 * If anything fails, rollback — request stays pending with approved_qty untouched.
 *
 * @param array<int,float> $approvedByLineId
 * @param int|null $issueFromLocationId Warehouse chosen at approval (required if header has no location)
 */
function inv_request_approve_and_issue(
    PDO $conn,
    int $requestId,
    int $companyId,
    int $userId,
    array $approvedByLineId,
    bool $allowNegativeOverride = false,
    ?int $issueFromLocationId = null
): array {
    $moveDate = inv_now();
    try {
        $conn->beginTransaction();

        $stmt = $conn->prepare("SELECT * FROM inv_request_headers WHERE id = ? AND company_id = ? FOR UPDATE");
        $stmt->execute([$requestId, $companyId]);
        $req = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$req) {
            throw new RuntimeException('Request not found');
        }
        if (($req['status'] ?? '') !== 'pending') {
            throw new RuntimeException('Only pending requests can be approved');
        }
        if (($req['request_type'] ?? 'issue') !== 'issue') {
            throw new RuntimeException('Only issue requests are supported for execution');
        }

        inv_validate_request_context($req);

        $storedLoc = isset($req['location_from_id']) && $req['location_from_id'] !== '' ? (int)$req['location_from_id'] : 0;
        $locId = ($issueFromLocationId !== null && $issueFromLocationId > 0) ? $issueFromLocationId : $storedLoc;
        if ($locId <= 0) {
            throw new RuntimeException('Issue-from location is required to approve and post.');
        }
        $vloc = $conn->prepare("SELECT id FROM inv_locations WHERE id = ? AND company_id = ? AND is_active = 1 LIMIT 1");
        $vloc->execute([$locId, $companyId]);
        if (!$vloc->fetchColumn()) {
            throw new RuntimeException('Invalid or inactive issue-from location.');
        }

        $lstmt = $conn->prepare("SELECT * FROM inv_request_lines WHERE header_id = ? AND company_id = ? ORDER BY id ASC FOR UPDATE");
        $lstmt->execute([$requestId, $companyId]);
        $lines = $lstmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$lines) {
            throw new RuntimeException('Request has no lines');
        }

        foreach ($lines as $ln) {
            $lid = (int)$ln['id'];
            $ap = array_key_exists($lid, $approvedByLineId)
                ? (float)$approvedByLineId[$lid]
                : (float)$ln['requested_qty'];
            if ($ap < -0.0001) {
                throw new RuntimeException('Approved qty cannot be negative (line ' . $lid . ')');
            }
            if ($ap > (float)$ln['requested_qty'] + 0.0001) {
                throw new RuntimeException('Approved qty cannot exceed requested (line ' . $lid . ')');
            }
        }

        $hasPositive = false;
        foreach ($lines as $ln) {
            $lid = (int)$ln['id'];
            $ap = array_key_exists($lid, $approvedByLineId)
                ? (float)$approvedByLineId[$lid]
                : (float)$ln['requested_qty'];
            if ($ap > 0.0001) {
                $hasPositive = true;
                break;
            }
        }
        if (!$hasPositive) {
            throw new RuntimeException('At least one line must have approved quantity > 0');
        }

        foreach ($lines as $ln) {
            $lid = (int)$ln['id'];
            $ap = array_key_exists($lid, $approvedByLineId)
                ? (float)$approvedByLineId[$lid]
                : (float)$ln['requested_qty'];
            $conn->prepare("UPDATE inv_request_lines SET approved_qty = ? WHERE id = ? AND company_id = ?")
                 ->execute([$ap, $lid, $companyId]);
        }

        $conn->prepare("UPDATE inv_request_headers SET location_from_id = ?, approved_by = ?, approved_at = ?, updated_at = ? WHERE id = ? AND company_id = ?")
             ->execute([$locId, $userId ?: null, $moveDate, $moveDate, $requestId, $companyId]);

        $notes = 'Material request ' . ($req['request_no'] ?? '#' . $requestId);
        if (!empty($req['notes'])) {
            $notes .= ' · ' . $req['notes'];
        }

        $docId = inv_create_doc($conn, [
            'company_id' => $companyId,
            'doc_type' => 'issue',
            'doc_date' => date('Y-m-d'),
            'status' => 'draft',
            'location_from_id' => $locId,
            'source_module' => $req['source_module'],
            'source_table' => 'inv_request_headers',
            'source_id' => $requestId,
            'notes' => $notes,
            'context_building_id' => $req['context_building_id'] ?? null,
            'context_unit_id' => $req['context_unit_id'] ?? null,
            'context_project_id' => $req['context_project_id'] ?? null,
            'context_booking_id' => $req['context_booking_id'] ?? null,
            'context_work_order_id' => $req['context_work_order_id'] ?? null,
            'context_housekeeping_id' => $req['context_housekeeping_id'] ?? null,
            'context_cleaning_job_id' => $req['context_cleaning_job_id'] ?? null,
            'is_emergency_issue' => 0,
            'created_by' => $userId ?: null,
        ]);

        foreach ($lines as $ln) {
            $lid = (int)$ln['id'];
            $ap = array_key_exists($lid, $approvedByLineId)
                ? (float)$approvedByLineId[$lid]
                : (float)$ln['requested_qty'];
            if ($ap <= 0.0001) {
                continue;
            }
            $item = inv_get_item($conn, $companyId, (int)$ln['item_id']);
            if (!$item || empty($item['is_active'])) {
                throw new RuntimeException('Invalid item on line ' . $lid);
            }
            inv_add_line($conn, $docId, [
                'item_id' => (int)$ln['item_id'],
                'uom_id' => (int)$ln['uom_id'],
                'qty' => $ap,
                'location_from_id' => $locId,
                'lot_number' => $ln['lot_number'] ?? null,
                'expiry_date' => $ln['expiry_date'] ?? null,
                'serial_number' => $ln['serial_number'] ?? null,
                'source_table' => 'inv_request_lines',
                'source_line_id' => $lid,
            ]);
        }

        $post = inv_post_doc($conn, $docId, $userId, $allowNegativeOverride);
        if (empty($post['success'])) {
            throw new RuntimeException($post['error'] ?? 'Inventory post failed');
        }

        $conn->prepare("
            UPDATE inv_request_headers
            SET status = 'completed', issue_doc_id = ?, updated_at = ?
            WHERE id = ? AND company_id = ?
        ")->execute([(int)$docId, $moveDate, $requestId, $companyId]);

        $conn->commit();
        return ['success' => true, 'error' => null, 'issue_doc_id' => (int)$docId];
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        return ['success' => false, 'error' => $e->getMessage()];
    }
}
