<?php
/**
 * Inventory Phase 2 — Purchase orders & goods receipts (GRN).
 */

require_once __DIR__ . '/inv_helpers.php';
require_once __DIR__ . '/inv_queries.php';
require_once __DIR__ . '/inv_posting.php';

function inv_purchasing_next_seq(PDO $conn, int $companyId, string $seqKey): int {
    $conn->prepare("INSERT INTO inv_doc_sequences (company_id, doc_prefix, next_seq) VALUES (?,?,1) ON DUPLICATE KEY UPDATE next_seq = next_seq")
         ->execute([$companyId, $seqKey]);
    $stmt = $conn->prepare("SELECT next_seq FROM inv_doc_sequences WHERE company_id = ? AND doc_prefix = ? FOR UPDATE");
    $stmt->execute([$companyId, $seqKey]);
    $next = (int)$stmt->fetchColumn();
    if ($next < 1) {
        $next = 1;
    }
    $conn->prepare("UPDATE inv_doc_sequences SET next_seq = ?, updated_at = ? WHERE company_id = ? AND doc_prefix = ?")
         ->execute([$next + 1, inv_now(), $companyId, $seqKey]);
    return $next;
}

function inv_generate_po_no(PDO $conn, int $companyId, string $poDate): string {
    $year = date('Y', strtotime($poDate));
    $seqKey = 'INV-PO-' . $year;
    $n = inv_purchasing_next_seq($conn, $companyId, $seqKey);
    return 'INV-PO-' . $year . '-' . str_pad((string)$n, 5, '0', STR_PAD_LEFT);
}

function inv_generate_grn_no(PDO $conn, int $companyId, string $receiptDate): string {
    $year = date('Y', strtotime($receiptDate));
    $seqKey = 'INV-GRN-' . $year;
    $n = inv_purchasing_next_seq($conn, $companyId, $seqKey);
    return 'INV-GRN-' . $year . '-' . str_pad((string)$n, 5, '0', STR_PAD_LEFT);
}

function inv_po_create(PDO $conn, array $header): int {
    $companyId = (int)($header['company_id'] ?? 0);
    if ($companyId <= 0) {
        throw new RuntimeException('company_id is required');
    }
    $vendorId = (int)($header['vendor_id'] ?? 0);
    if ($vendorId <= 0) {
        throw new RuntimeException('vendor_id is required');
    }
    $poDate = $header['po_date'] ?? date('Y-m-d');
    $poNo = trim((string)($header['po_no'] ?? ''));
    if ($poNo === '') {
        $started = !$conn->inTransaction();
        if ($started) {
            $conn->beginTransaction();
        }
        $poNo = inv_generate_po_no($conn, $companyId, $poDate);
        if ($started) {
            $conn->commit();
        }
    }

    $stmt = $conn->prepare("
        INSERT INTO inv_purchase_orders
            (company_id, vendor_id, po_no, po_date, expected_date, status, notes, created_by)
        VALUES (?,?,?,?,?,?,?,?)
    ");
    $stmt->execute([
        $companyId,
        $vendorId,
        $poNo,
        $poDate,
        $header['expected_date'] ?? null,
        $header['status'] ?? 'draft',
        $header['notes'] ?? null,
        $header['created_by'] ?? null,
    ]);
    return (int)$conn->lastInsertId();
}

function inv_po_add_line(PDO $conn, int $poId, array $line): int {
    $stmt = $conn->prepare("SELECT company_id FROM inv_purchase_orders WHERE id = ? LIMIT 1");
    $stmt->execute([$poId]);
    $companyId = (int)$stmt->fetchColumn();
    if ($companyId <= 0) {
        throw new RuntimeException('Invalid purchase order');
    }
    $stmt = $conn->prepare("
        INSERT INTO inv_purchase_order_lines
            (header_id, company_id, item_id, uom_id, qty_ordered, unit_price, qty_received, sort_order)
        VALUES (?,?,?,?,?,?,0,?)
    ");
    $stmt->execute([
        $poId,
        $companyId,
        (int)$line['item_id'],
        (int)$line['uom_id'],
        (float)$line['qty_ordered'],
        (float)($line['unit_price'] ?? 0),
        (int)($line['sort_order'] ?? 0),
    ]);
    return (int)$conn->lastInsertId();
}

function inv_grn_create(PDO $conn, array $header): int {
    $companyId = (int)($header['company_id'] ?? 0);
    if ($companyId <= 0) {
        throw new RuntimeException('company_id is required');
    }
    $vendorId = (int)($header['vendor_id'] ?? 0);
    $locTo = (int)($header['location_to_id'] ?? 0);
    if ($vendorId <= 0 || !$locTo) {
        throw new RuntimeException('vendor_id and location_to_id are required');
    }
    $receiptDate = $header['receipt_date'] ?? date('Y-m-d');
    $grnNo = trim((string)($header['grn_no'] ?? ''));
    if ($grnNo === '') {
        $started = !$conn->inTransaction();
        if ($started) {
            $conn->beginTransaction();
        }
        $grnNo = inv_generate_grn_no($conn, $companyId, $receiptDate);
        if ($started) {
            $conn->commit();
        }
    }

    $stmt = $conn->prepare("
        INSERT INTO inv_goods_receipts
            (company_id, vendor_id, po_id, grn_no, reference_no, receipt_date, location_to_id,
             status, allow_over_receipt, notes, created_by)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)
    ");
    $stmt->execute([
        $companyId,
        $vendorId,
        !empty($header['po_id']) ? (int)$header['po_id'] : null,
        $grnNo,
        isset($header['reference_no']) && $header['reference_no'] !== '' ? trim((string)$header['reference_no']) : null,
        $receiptDate,
        $locTo,
        $header['status'] ?? 'draft',
        !empty($header['allow_over_receipt']) ? 1 : 0,
        $header['notes'] ?? null,
        $header['created_by'] ?? null,
    ]);
    return (int)$conn->lastInsertId();
}

function inv_grn_add_line(PDO $conn, int $grnId, array $line): int {
    $stmt = $conn->prepare("SELECT company_id FROM inv_goods_receipts WHERE id = ? LIMIT 1");
    $stmt->execute([$grnId]);
    $companyId = (int)$stmt->fetchColumn();
    if ($companyId <= 0) {
        throw new RuntimeException('Invalid goods receipt');
    }
    $stmt = $conn->prepare("
        INSERT INTO inv_goods_receipt_lines
            (header_id, company_id, po_line_id, item_id, uom_id, qty_received, unit_cost,
             lot_number, expiry_date, serial_number, location_to_id)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)
    ");
    $stmt->execute([
        $grnId,
        $companyId,
        !empty($line['po_line_id']) ? (int)$line['po_line_id'] : null,
        (int)$line['item_id'],
        (int)$line['uom_id'],
        (float)$line['qty_received'],
        (float)$line['unit_cost'],
        $line['lot_number'] ?? null,
        $line['expiry_date'] ?? null,
        $line['serial_number'] ?? null,
        !empty($line['location_to_id']) ? (int)$line['location_to_id'] : null,
    ]);
    return (int)$conn->lastInsertId();
}

/**
 * Post GRN: creates inventory receipt and posts it; updates PO received qty.
 */
function inv_grn_post(PDO $conn, int $grnId, int $userId, bool $allowNegativeInventory = false): array {
    $stmt = $conn->prepare("SELECT * FROM inv_goods_receipts WHERE id = ? LIMIT 1 FOR UPDATE");
    $stmt->execute([$grnId]);
    $grn = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$grn) {
        return ['success' => false, 'error' => 'Goods receipt not found'];
    }
    if (($grn['status'] ?? '') !== 'draft') {
        return ['success' => false, 'error' => 'Only draft goods receipts can be posted'];
    }

    $companyId = (int)$grn['company_id'];
    $lineStmt = $conn->prepare("SELECT * FROM inv_goods_receipt_lines WHERE header_id = ? ORDER BY id ASC");
    $lineStmt->execute([$grnId]);
    $lines = $lineStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$lines) {
        return ['success' => false, 'error' => 'Add at least one line'];
    }

    $allowOver = !empty($grn['allow_over_receipt']);
    $poIdGrn = !empty($grn['po_id']) ? (int)$grn['po_id'] : 0;
    if ($poIdGrn) {
        $ps = $conn->prepare("SELECT id, status FROM inv_purchase_orders WHERE id = ? AND company_id = ? LIMIT 1");
        $ps->execute([$poIdGrn, $companyId]);
        $poH = $ps->fetch(PDO::FETCH_ASSOC);
        if (!$poH || ($poH['status'] ?? '') !== 'open') {
            return ['success' => false, 'error' => 'Purchase order must be Open to receive against it'];
        }
    }

    try {
        $conn->beginTransaction();

        foreach ($lines as $ln) {
            $q = (float)$ln['qty_received'];
            if ($q <= 0) {
                throw new RuntimeException('Line qty must be positive (line ' . (int)$ln['id'] . ')');
            }
            $polId = $ln['po_line_id'] ? (int)$ln['po_line_id'] : null;
            if ($polId) {
                $ps = $conn->prepare("SELECT * FROM inv_purchase_order_lines WHERE id = ? AND company_id = ? LIMIT 1 FOR UPDATE");
                $ps->execute([$polId, $companyId]);
                $pol = $ps->fetch(PDO::FETCH_ASSOC);
                if (!$pol) {
                    throw new RuntimeException('Invalid PO line reference');
                }
                if ($poIdGrn && (int)$pol['header_id'] !== $poIdGrn) {
                    throw new RuntimeException('PO line does not belong to this GRN purchase order');
                }
                $ordered = (float)$pol['qty_ordered'];
                $already = (float)$pol['qty_received'];
                $remaining = round($ordered - $already, 4);
                if ($q > $remaining + 0.0001 && !$allowOver) {
                    throw new RuntimeException('Receive qty exceeds open PO qty for item line ' . (int)$ln['id']);
                }
            }
        }

        $notes = 'GRN ' . ($grn['grn_no'] ?? '');
        if (!empty($grn['reference_no'])) {
            $notes .= ' · Ref: ' . $grn['reference_no'];
        }
        if (!empty($grn['notes'])) {
            $notes .= ' · ' . $grn['notes'];
        }

        $docId = inv_create_doc($conn, [
            'company_id' => $companyId,
            'doc_type' => 'receipt',
            'doc_date' => $grn['receipt_date'],
            'status' => 'draft',
            'location_to_id' => (int)$grn['location_to_id'],
            'vendor_id' => (int)$grn['vendor_id'],
            'source_module' => 'inventory',
            'source_table' => 'inv_goods_receipts',
            'source_id' => $grnId,
            'notes' => $notes,
            'created_by' => $userId ?: null,
        ]);

        foreach ($lines as $ln) {
            $locTo = $ln['location_to_id'] ? (int)$ln['location_to_id'] : (int)$grn['location_to_id'];
            inv_add_line($conn, $docId, [
                'item_id' => (int)$ln['item_id'],
                'uom_id' => (int)$ln['uom_id'],
                'qty' => (float)$ln['qty_received'],
                'unit_cost' => (float)$ln['unit_cost'],
                'lot_number' => $ln['lot_number'] ?? null,
                'expiry_date' => $ln['expiry_date'] ?? null,
                'serial_number' => $ln['serial_number'] ?? null,
                'location_to_id' => $locTo,
            ]);
        }

        $post = inv_post_doc($conn, $docId, $userId, $allowNegativeInventory);
        if (empty($post['success'])) {
            throw new RuntimeException($post['error'] ?? 'Post failed');
        }

        $conn->prepare("
            UPDATE inv_goods_receipts
            SET status='posted', inventory_doc_id=?, posted_by=?, posted_at=?, updated_at=?
            WHERE id=?
        ")->execute([$docId, $userId ?: null, inv_now(), inv_now(), $grnId]);

        foreach ($lines as $ln) {
            $polId = $ln['po_line_id'] ? (int)$ln['po_line_id'] : null;
            if ($polId) {
                $q = (float)$ln['qty_received'];
                $conn->prepare("
                    UPDATE inv_purchase_order_lines
                    SET qty_received = ROUND(qty_received + ?, 4)
                    WHERE id = ? AND company_id = ?
                ")->execute([$q, $polId, $companyId]);
            }
        }

        if (!empty($grn['po_id'])) {
            inv_po_refresh_status($conn, (int)$grn['po_id'], $companyId);
        }

        $conn->commit();
        return ['success' => true, 'error' => null, 'inventory_doc_id' => $docId];
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function inv_po_refresh_status(PDO $conn, int $poId, int $companyId): void {
    $stmt = $conn->prepare("SELECT id, status FROM inv_purchase_orders WHERE id = ? AND company_id = ? LIMIT 1");
    $stmt->execute([$poId, $companyId]);
    $po = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$po || ($po['status'] ?? '') !== 'open') {
        return;
    }
    $ls = $conn->prepare("SELECT qty_ordered, qty_received FROM inv_purchase_order_lines WHERE header_id = ?");
    $ls->execute([$poId]);
    $rows = $ls->fetchAll(PDO::FETCH_ASSOC);
    $allClosed = true;
    foreach ($rows as $r) {
        if (round((float)$r['qty_received'], 4) < round((float)$r['qty_ordered'], 4) - 0.0001) {
            $allClosed = false;
            break;
        }
    }
    if ($allClosed && $rows) {
        $conn->prepare("UPDATE inv_purchase_orders SET status='closed', updated_at=? WHERE id=? AND company_id=?")
             ->execute([inv_now(), $poId, $companyId]);
    }
}

/**
 * Draft receipt with negative lines to reverse a posted GRN (post from Documents).
 */
function inv_create_reversal_receipt_for_grn(PDO $conn, int $grnId, int $userId): int {
    $stmt = $conn->prepare("SELECT * FROM inv_goods_receipts WHERE id = ? LIMIT 1");
    $stmt->execute([$grnId]);
    $grn = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$grn || ($grn['status'] ?? '') !== 'posted') {
        throw new RuntimeException('Posted GRN required');
    }
    $invDocId = (int)($grn['inventory_doc_id'] ?? 0);
    if ($invDocId <= 0) {
        throw new RuntimeException('GRN has no linked inventory receipt');
    }

    $companyId = (int)$grn['company_id'];
    $lineStmt = $conn->prepare("SELECT * FROM inv_doc_lines WHERE header_id = ? ORDER BY id ASC");
    $lineStmt->execute([$invDocId]);
    $docLines = $lineStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$docLines) {
        throw new RuntimeException('Original receipt has no lines');
    }

    $notes = 'Reversal of GRN ' . ($grn['grn_no'] ?? '#' . $grnId);
    $docId = inv_create_doc($conn, [
        'company_id' => $companyId,
        'doc_type' => 'receipt',
        'doc_date' => date('Y-m-d'),
        'status' => 'draft',
        'location_to_id' => (int)$grn['location_to_id'],
        'vendor_id' => (int)$grn['vendor_id'],
        'source_module' => 'inventory',
        'source_table' => 'inv_goods_receipts',
        'source_id' => $grnId,
        'notes' => $notes,
        'created_by' => $userId ?: null,
    ]);

    foreach ($docLines as $dl) {
        $q = (float)$dl['qty'];
        if ($q == 0.0) {
            continue;
        }
        inv_add_line($conn, $docId, [
            'item_id' => (int)$dl['item_id'],
            'uom_id' => (int)$dl['uom_id'],
            'qty' => $q > 0 ? -abs($q) : $q,
            'unit_cost' => null,
            'lot_number' => $dl['lot_number'] ?? null,
            'expiry_date' => $dl['expiry_date'] ?? null,
            'serial_number' => $dl['serial_number'] ?? null,
            'location_to_id' => $dl['location_to_id'] ? (int)$dl['location_to_id'] : (int)$grn['location_to_id'],
        ]);
    }

    return $docId;
}
