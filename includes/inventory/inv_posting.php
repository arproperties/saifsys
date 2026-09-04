<?php
/**
 * Inventory Engine - Posting (ledger + onhand + WAC)
 *
 * MVP rules:
 * - No negative stock allowed by default.
 * - WAC is company-wide per item (transfers do not change WAC).
 * - Lot/expiry and serial are supported (enforced per item flags).
 */

require_once __DIR__ . '/inv_helpers.php';
require_once __DIR__ . '/inv_queries.php';

/**
 * Resolve / create lot id from line (if applicable).
 */
/**
 * Resolved lot id for posting/on-hand: real lot id from inv_lots, or 0 when no lot.
 * (MySQL PK on inv_onhand cannot use NULL for lot_id; 0 = no lot.)
 */
function inv_resolve_lot_id(PDO $conn, int $companyId, int $itemId, ?int $lotId, ?string $lotNumber, ?string $expiryDate): int {
    if ($lotId) return (int)$lotId;
    $lotNumber = trim((string)$lotNumber);
    if ($lotNumber === '') return 0;

    $stmt = $conn->prepare("SELECT id FROM inv_lots WHERE company_id = ? AND item_id = ? AND lot_number = ? LIMIT 1");
    $stmt->execute([$companyId, $itemId, $lotNumber]);
    $existing = $stmt->fetchColumn();
    if ($existing) {
        if ($expiryDate) {
            $conn->prepare("UPDATE inv_lots SET expiry_date = COALESCE(expiry_date, ?) WHERE id = ?")
                 ->execute([$expiryDate, (int)$existing]);
        }
        return (int)$existing;
    }

    $conn->prepare("INSERT INTO inv_lots (company_id, item_id, lot_number, expiry_date) VALUES (?,?,?,?)")
         ->execute([$companyId, $itemId, $lotNumber, $expiryDate ?: null]);
    return (int)$conn->lastInsertId();
}

/**
 * Resolve / create serial id from line (if applicable).
 */
function inv_resolve_serial_id(PDO $conn, int $companyId, int $itemId, ?int $serialId, ?string $serialNumber): ?int {
    if ($serialId) return (int)$serialId;
    $serialNumber = trim((string)$serialNumber);
    if ($serialNumber === '') return null;

    $stmt = $conn->prepare("SELECT id FROM inv_serials WHERE company_id = ? AND serial_number = ? LIMIT 1");
    $stmt->execute([$companyId, $serialNumber]);
    $existing = $stmt->fetchColumn();
    if ($existing) return (int)$existing;

    $conn->prepare("INSERT INTO inv_serials (company_id, item_id, serial_number, status) VALUES (?,?,?,'in_stock')")
         ->execute([$companyId, $itemId, $serialNumber]);
    return (int)$conn->lastInsertId();
}

/**
 * Convert quantity to base UoM. If no conversion is found, assumes same.
 */
function inv_to_base_qty(PDO $conn, int $companyId, int $itemId, float $qty, int $fromUomId, int $baseUomId): float {
    if ($fromUomId === $baseUomId) return round($qty, 4);
    $stmt = $conn->prepare("
        SELECT factor
        FROM inv_uom_conversions
        WHERE company_id = ?
          AND is_active = 1
          AND (item_id IS NULL OR item_id = ?)
          AND from_uom_id = ?
          AND to_uom_id = ?
        ORDER BY item_id IS NULL ASC
        LIMIT 1
    ");
    $stmt->execute([$companyId, $itemId, $fromUomId, $baseUomId]);
    $factor = $stmt->fetchColumn();
    if ($factor === false) {
        return round($qty, 4);
    }
    return round($qty * (float)$factor, 4);
}

/**
 * Generate a new document number.
 * Format: INV-{TYPE}-{YYYY}-{SEQ5} (seq scoped per company+year+type using inv_doc_headers count).
 */
function inv_generate_doc_no(PDO $conn, int $companyId, string $docType, string $docDate): string {
    $typ = inv_doc_type_code($docType);
    $year = date('Y', strtotime($docDate));
    $prefix = 'INV-' . $typ . '-' . $year . '-';

    // Use sequences table for concurrency-safe numbering
    $seqKey = 'INV-' . $typ . '-' . $year;
    $conn->prepare("INSERT INTO inv_doc_sequences (company_id, doc_prefix, next_seq) VALUES (?,?,1) ON DUPLICATE KEY UPDATE next_seq = next_seq")
         ->execute([$companyId, $seqKey]);
    $stmt = $conn->prepare("SELECT next_seq FROM inv_doc_sequences WHERE company_id = ? AND doc_prefix = ? FOR UPDATE");
    $stmt->execute([$companyId, $seqKey]);
    $next = (int)$stmt->fetchColumn();
    if ($next < 1) $next = 1;
    $conn->prepare("UPDATE inv_doc_sequences SET next_seq = ?, updated_at = ? WHERE company_id = ? AND doc_prefix = ?")
         ->execute([$next + 1, inv_now(), $companyId, $seqKey]);

    $seq = str_pad((string)$next, 5, '0', STR_PAD_LEFT);
    return $prefix . $seq;
}

/**
 * Create a draft inventory document.
 */
function inv_create_doc(PDO $conn, array $header): int {
    $companyId = (int)($header['company_id'] ?? 0);
    if ($companyId <= 0) throw new RuntimeException('company_id is required');
    $docType = inv_normalize_doc_type((string)($header['doc_type'] ?? 'adjustment'));
    $docDate = $header['doc_date'] ?? date('Y-m-d');
    $status  = inv_normalize_status((string)($header['status'] ?? 'draft'));
    $docNo   = trim((string)($header['doc_no'] ?? ''));
    if ($docNo === '') {
        $started = false;
        if (!$conn->inTransaction()) {
            $conn->beginTransaction();
            $started = true;
        }
        $docNo = inv_generate_doc_no($conn, $companyId, $docType, $docDate);
        if ($started) {
            $conn->commit();
        }
    }

    $stmt = $conn->prepare("
        INSERT INTO inv_doc_headers
            (company_id, doc_type, doc_no, doc_date, status, location_from_id, location_to_id,
             vendor_id, customer_id, source_module, source_table, source_id, notes,
             context_building_id, context_unit_id, context_project_id, context_booking_id,
             context_work_order_id, context_housekeeping_id, context_cleaning_job_id,
             is_emergency_issue, created_by)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");
    $stmt->execute([
        $companyId,
        $docType,
        $docNo,
        $docDate,
        $status,
        $header['location_from_id'] ?? null,
        $header['location_to_id'] ?? null,
        $header['vendor_id'] ?? null,
        $header['customer_id'] ?? null,
        $header['source_module'] ?? null,
        $header['source_table'] ?? null,
        $header['source_id'] ?? null,
        $header['notes'] ?? null,
        $header['context_building_id'] ?? null,
        $header['context_unit_id'] ?? null,
        $header['context_project_id'] ?? null,
        $header['context_booking_id'] ?? null,
        $header['context_work_order_id'] ?? null,
        $header['context_housekeeping_id'] ?? null,
        $header['context_cleaning_job_id'] ?? null,
        !empty($header['is_emergency_issue']) ? 1 : 0,
        $header['created_by'] ?? null,
    ]);
    return (int)$conn->lastInsertId();
}

function inv_add_line(PDO $conn, int $docId, array $line): int {
    $stmt = $conn->prepare("SELECT company_id FROM inv_doc_headers WHERE id = ? LIMIT 1");
    $stmt->execute([$docId]);
    $companyId = (int)$stmt->fetchColumn();
    if ($companyId <= 0) throw new RuntimeException('Invalid document');

    $stmt = $conn->prepare("
        INSERT INTO inv_doc_lines
            (header_id, company_id, item_id, uom_id, qty, unit_cost, unit_price,
             lot_id, serial_id, lot_number, serial_number, expiry_date,
             location_from_id, location_to_id, source_table, source_line_id)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");
    $stmt->execute([
        $docId,
        $companyId,
        (int)$line['item_id'],
        (int)$line['uom_id'],
        (float)$line['qty'],
        array_key_exists('unit_cost', $line) ? $line['unit_cost'] : null,
        array_key_exists('unit_price', $line) ? $line['unit_price'] : null,
        $line['lot_id'] ?? null,
        $line['serial_id'] ?? null,
        $line['lot_number'] ?? null,
        $line['serial_number'] ?? null,
        $line['expiry_date'] ?? null,
        $line['location_from_id'] ?? null,
        $line['location_to_id'] ?? null,
        $line['source_table'] ?? null,
        $line['source_line_id'] ?? null,
    ]);
    return (int)$conn->lastInsertId();
}

/**
 * Post a draft document (creates stock moves, updates onhand + WAC).
 */
function inv_post_doc(PDO $conn, int $docId, int $userId, bool $allowNegativeOverride = false): array {
    $hdrStmt = $conn->prepare("SELECT * FROM inv_doc_headers WHERE id = ? LIMIT 1");
    $hdrStmt->execute([$docId]);
    $h = $hdrStmt->fetch(PDO::FETCH_ASSOC);
    if (!$h) return ['success' => false, 'error' => 'Document not found'];
    if (($h['status'] ?? '') !== 'draft') return ['success' => false, 'error' => 'Only draft documents can be posted'];

    $companyId = (int)$h['company_id'];
    $docType = inv_normalize_doc_type((string)$h['doc_type']);

    $linesStmt = $conn->prepare("SELECT * FROM inv_doc_lines WHERE header_id = ? ORDER BY id ASC");
    $linesStmt->execute([$docId]);
    $lines = $linesStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$lines) return ['success' => false, 'error' => 'Add at least one line'];

    $moveDate = inv_now();

    $startedInner = !$conn->inTransaction();
    try {
        if ($startedInner) {
            $conn->beginTransaction();
        }
        $conn->prepare("SELECT id FROM inv_doc_headers WHERE id = ? FOR UPDATE")->execute([$docId]);

        foreach ($lines as $ln) {
            $moves = [];
            $itemId = (int)$ln['item_id'];
            $item = inv_get_item($conn, $companyId, $itemId);
            if (!$item || empty($item['is_active'])) {
                throw new RuntimeException('Invalid/inactive item on line ' . (int)$ln['id']);
            }

            $qty = (float)$ln['qty'];
            if ($qty == 0.0) throw new RuntimeException('Qty must not be 0 on line ' . (int)$ln['id']);
            $qtySign = $qty < 0 ? -1 : 1;
            $qtyAbs = abs($qty);

            $baseUomId = (int)$item['base_uom_id'];
            $qtyBase = inv_to_base_qty($conn, $companyId, $itemId, $qtyAbs, (int)$ln['uom_id'], $baseUomId);

            $lotId = inv_resolve_lot_id($conn, $companyId, $itemId, $ln['lot_id'] ? (int)$ln['lot_id'] : null, $ln['lot_number'] ?? null, $ln['expiry_date'] ?? null);
            $serialId = inv_resolve_serial_id($conn, $companyId, $itemId, $ln['serial_id'] ? (int)$ln['serial_id'] : null, $ln['serial_number'] ?? null);

            if (!empty($item['track_lot']) && !$lotId) {
                throw new RuntimeException('Lot is required for item ' . $item['item_code'] . ' (line ' . (int)$ln['id'] . ')');
            }
            if (!empty($item['track_serial'])) {
                if ($qtyBase != 1.0) {
                    throw new RuntimeException('Serial-tracked items must have qty = 1 (line ' . (int)$ln['id'] . ')');
                }
                if (!$serialId) {
                    throw new RuntimeException('Serial is required for item ' . $item['item_code'] . ' (line ' . (int)$ln['id'] . ')');
                }
            }

            // Determine locations for the movement
            $fromLoc = $ln['location_from_id'] ?? $h['location_from_id'] ?? null;
            $toLoc   = $ln['location_to_id'] ?? $h['location_to_id'] ?? null;

            $applyOut = function (int $locId, float $q) use ($conn, $companyId, $itemId, $lotId, $allowNegativeOverride, $serialId, $moveDate) {
                $conn->prepare("
                    SELECT qty_on_hand FROM inv_onhand
                    WHERE company_id = ? AND item_id = ? AND location_id = ?
                      AND lot_id = ?
                    FOR UPDATE
                ")->execute([$companyId, $itemId, $locId, $lotId]);
                $cur = inv_get_onhand_qty($conn, $companyId, $itemId, $locId, $lotId);
                $new = round($cur - $q, 4);
                if (!$allowNegativeOverride && $new < -0.0001) {
                    throw new RuntimeException('Insufficient stock (would go negative).');
                }
                $conn->prepare("
                    INSERT INTO inv_onhand (company_id, item_id, location_id, lot_id, qty_on_hand, updated_at)
                    VALUES (?,?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE qty_on_hand = VALUES(qty_on_hand), updated_at = VALUES(updated_at)
                ")->execute([$companyId, $itemId, $locId, $lotId, $new, $moveDate]);

                if ($serialId) {
                    $stmt = $conn->prepare("SELECT status, location_id FROM inv_serials WHERE id = ? AND company_id = ? AND item_id = ? LIMIT 1 FOR UPDATE");
                    $stmt->execute([$serialId, $companyId, $itemId]);
                    $sr = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$sr) throw new RuntimeException('Serial not found');
                    if (($sr['status'] ?? '') !== 'in_stock') throw new RuntimeException('Serial not available');
                    if ((int)($sr['location_id'] ?? 0) !== (int)$locId) throw new RuntimeException('Serial not in selected location');
                }
            };
            $applyIn = function (int $locId, float $q) use ($conn, $companyId, $itemId, $lotId, $serialId, $moveDate) {
                $conn->prepare("
                    SELECT qty_on_hand FROM inv_onhand
                    WHERE company_id = ? AND item_id = ? AND location_id = ?
                      AND lot_id = ?
                    FOR UPDATE
                ")->execute([$companyId, $itemId, $locId, $lotId]);
                $cur = inv_get_onhand_qty($conn, $companyId, $itemId, $locId, $lotId);
                $new = round($cur + $q, 4);
                $conn->prepare("
                    INSERT INTO inv_onhand (company_id, item_id, location_id, lot_id, qty_on_hand, updated_at)
                    VALUES (?,?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE qty_on_hand = VALUES(qty_on_hand), updated_at = VALUES(updated_at)
                ")->execute([$companyId, $itemId, $locId, $lotId, $new, $moveDate]);

                if ($serialId) {
                    $conn->prepare("UPDATE inv_serials SET status='in_stock', location_id=?, item_id=? WHERE id=? AND company_id=?")
                         ->execute([$locId, $itemId, $serialId, $companyId]);
                }
            };

            if ($docType === 'stock_take') {
                $countLoc = (int)($ln['location_to_id'] ?? $h['location_to_id'] ?? 0);
                if (!$countLoc) {
                    throw new RuntimeException('Stock take requires a count location (line ' . (int)$ln['id'] . ')');
                }
                $wac = inv_get_wac($conn, $companyId, $itemId);
                $wacBefore = (float)$wac['avg_cost'];
                $counted = $qtyBase;
                $expected = inv_get_onhand_qty($conn, $companyId, $itemId, $countLoc, $lotId);
                $variance = round($counted - $expected, 4);
                if (abs($variance) < 0.00001) {
                    continue;
                }
                $moveQty = abs($variance);
                $wacAfter = $wacBefore;
                $unitCostBase = $wacBefore;
                $valueIn = 0.0;
                $valueOut = 0.0;

                if ($variance > 0) {
                    if ($ln['unit_cost'] !== null && (float)$ln['unit_cost'] >= 0) {
                        $unitCostBase = round((float)$ln['unit_cost'], 4);
                        $oldQty = (float)$wac['qty_valued'];
                        $oldAvg = $wacBefore;
                        $newQty = round($oldQty + $moveQty, 4);
                        $newAvg = $newQty > 0 ? round((($oldQty * $oldAvg) + ($moveQty * $unitCostBase)) / $newQty, 4) : 0.0;
                        $wacAfter = $newAvg;
                        $conn->prepare("
                            INSERT INTO inv_item_cost_state (company_id, item_id, avg_cost, qty_valued, updated_at)
                            VALUES (?,?,?,?,?)
                            ON DUPLICATE KEY UPDATE
                                avg_cost = VALUES(avg_cost),
                                qty_valued = VALUES(qty_valued),
                                updated_at = VALUES(updated_at)
                        ")->execute([$companyId, $itemId, $wacAfter, $newQty, $moveDate]);
                    } else {
                        inv_adjust_qty_valued($conn, $companyId, $itemId, $moveQty, $moveDate);
                    }
                    $applyIn($countLoc, $moveQty);
                    $valueIn = round($moveQty * $unitCostBase, 4);
                    $lineMoves = [['location_id' => $countLoc, 'qty_in' => $moveQty, 'qty_out' => 0.0]];
                } else {
                    $unitCostBase = $wacBefore;
                    $valueOut = round($moveQty * $unitCostBase, 4);
                    $applyOut($countLoc, $moveQty);
                    inv_adjust_qty_valued($conn, $companyId, $itemId, -$moveQty, $moveDate);
                    if ($serialId) {
                        $conn->prepare("UPDATE inv_serials SET status='issued', location_id=NULL WHERE id=? AND company_id=?")
                            ->execute([$serialId, $companyId]);
                    }
                    $lineMoves = [['location_id' => $countLoc, 'qty_in' => 0.0, 'qty_out' => $moveQty]];
                }
                foreach ($lineMoves as $m) {
                    $qin = (float)$m['qty_in'];
                    $qout = (float)$m['qty_out'];
                    $vin = $qin > 0 ? round($qin * $unitCostBase, 4) : 0.0;
                    $vout = $qout > 0 ? round($qout * $unitCostBase, 4) : 0.0;
                    $conn->prepare("
                        INSERT INTO inv_stock_moves
                            (company_id, move_date, doc_type, doc_id, doc_line_id,
                             item_id, location_id, qty_in, qty_out, base_uom_id, qty_base,
                             lot_id, serial_id, unit_cost_base, value_in, value_out, wac_before, wac_after,
                             source_module, source_table, source_id)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                    ")->execute([
                        $companyId,
                        $moveDate,
                        $docType,
                        $docId,
                        (int)$ln['id'],
                        $itemId,
                        (int)$m['location_id'],
                        $qin,
                        $qout,
                        $baseUomId,
                        $qin > 0 ? $qin : -$qout,
                        $lotId,
                        $serialId,
                        $unitCostBase,
                        $vin,
                        $vout,
                        $wacBefore,
                        $wacAfter,
                        $h['source_module'] ?? null,
                        $h['source_table'] ?? null,
                        $h['source_id'] ?? null,
                    ]);
                }
                continue;
            }

            // Validate locations per doc type
            if (in_array($docType, ['issue','wastage','sale','return'], true)) {
                if (!$fromLoc) throw new RuntimeException('From location required (line ' . (int)$ln['id'] . ')');
            }
            if (in_array($docType, ['receipt','opening_balance'], true)) {
                if (!$toLoc) throw new RuntimeException('To location required (line ' . (int)$ln['id'] . ')');
            }
            if ($docType === 'transfer') {
                if (!$fromLoc || !$toLoc) throw new RuntimeException('From and To locations required (line ' . (int)$ln['id'] . ')');
                if ((int)$fromLoc === (int)$toLoc) throw new RuntimeException('Transfer locations must differ (line ' . (int)$ln['id'] . ')');
            }
            if ($docType === 'adjustment') {
                // adjustment uses fromLoc as the location affected unless toLoc is set
                if (!$fromLoc && !$toLoc) throw new RuntimeException('Location required for adjustment (line ' . (int)$ln['id'] . ')');
            }

            if ($docType === 'opening_balance' && $qtySign < 0) {
                throw new RuntimeException('Opening balance cannot be negative (line ' . (int)$ln['id'] . ')');
            }

            // Negative receipt: stock out from To location (GRN / receipt reversal); value at WAC
            if ($docType === 'receipt' && $qtySign < 0) {
                if (!$toLoc) throw new RuntimeException('To location required (line ' . (int)$ln['id'] . ')');
                $wac = inv_get_wac($conn, $companyId, $itemId);
                $wacBefore = (float)$wac['avg_cost'];
                $unitCostBase = $wacBefore;
                $valueOut = round($qtyBase * $unitCostBase, 4);
                $wacAfter = $wacBefore;
                $applyOut((int)$toLoc, $qtyBase);
                inv_adjust_qty_valued($conn, $companyId, $itemId, -$qtyBase, $moveDate);
                if ($serialId) {
                    $conn->prepare("UPDATE inv_serials SET status='issued', location_id=NULL WHERE id=? AND company_id=?")
                        ->execute([$serialId, $companyId]);
                }
                $moves[] = ['location_id' => (int)$toLoc, 'qty_in' => 0.0, 'qty_out' => $qtyBase];
                $lineMoves = $moves;
                foreach ($lineMoves as $m) {
                    $qin = (float)$m['qty_in'];
                    $qout = (float)$m['qty_out'];
                    $vin = $qin > 0 ? round($qin * $unitCostBase, 4) : 0.0;
                    $vout = $qout > 0 ? round($qout * $unitCostBase, 4) : 0.0;
                    $conn->prepare("
                        INSERT INTO inv_stock_moves
                            (company_id, move_date, doc_type, doc_id, doc_line_id,
                             item_id, location_id, qty_in, qty_out, base_uom_id, qty_base,
                             lot_id, serial_id, unit_cost_base, value_in, value_out, wac_before, wac_after,
                             source_module, source_table, source_id)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                    ")->execute([
                        $companyId,
                        $moveDate,
                        $docType,
                        $docId,
                        (int)$ln['id'],
                        $itemId,
                        (int)$m['location_id'],
                        $qin,
                        $qout,
                        $baseUomId,
                        $qin > 0 ? $qin : -$qout,
                        $lotId,
                        $serialId,
                        $unitCostBase,
                        $vin,
                        $vout,
                        $wacBefore,
                        $wacAfter,
                        $h['source_module'] ?? null,
                        $h['source_table'] ?? null,
                        $h['source_id'] ?? null,
                    ]);
                }
                continue;
            }

            // Determine cost
            $wac = inv_get_wac($conn, $companyId, $itemId);
            $wacBefore = (float)$wac['avg_cost'];
            $unitCostBase = 0.0;
            $valueIn = 0.0;
            $valueOut = 0.0;
            $wacAfter = $wacBefore;

            if (in_array($docType, ['receipt','opening_balance'], true)) {
                $uc = $ln['unit_cost'];
                if ($uc === null || (float)$uc < 0) throw new RuntimeException('Unit cost required for receipt/opening (line ' . (int)$ln['id'] . ')');
                $unitCostBase = round((float)$uc, 4);
                $valueIn = round($qtyBase * $unitCostBase, 4);

                $oldQty = (float)$wac['qty_valued'];
                $oldAvg = $wacBefore;
                $newQty = round($oldQty + $qtyBase, 4);
                $newAvg = $newQty > 0 ? round((($oldQty * $oldAvg) + ($qtyBase * $unitCostBase)) / $newQty, 4) : 0.0;
                $wacAfter = $newAvg;

                // Update WAC state
                $conn->prepare("
                    INSERT INTO inv_item_cost_state (company_id, item_id, avg_cost, qty_valued, updated_at)
                    VALUES (?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE
                        avg_cost = VALUES(avg_cost),
                        qty_valued = VALUES(qty_valued),
                        updated_at = VALUES(updated_at)
                ")->execute([$companyId, $itemId, $wacAfter, $newQty, $moveDate]);
            } elseif ($docType === 'adjustment' && $qtySign > 0) {
                // Positive adjustment: if unit_cost provided, treat as receipt-like; otherwise use current WAC.
                if ($ln['unit_cost'] !== null && (float)$ln['unit_cost'] >= 0) {
                    $unitCostBase = round((float)$ln['unit_cost'], 4);

                    $oldQty = (float)$wac['qty_valued'];
                    $oldAvg = $wacBefore;
                    $newQty = round($oldQty + $qtyBase, 4);
                    $newAvg = $newQty > 0 ? round((($oldQty * $oldAvg) + ($qtyBase * $unitCostBase)) / $newQty, 4) : 0.0;
                    $wacAfter = $newAvg;
                    $conn->prepare("
                        INSERT INTO inv_item_cost_state (company_id, item_id, avg_cost, qty_valued, updated_at)
                        VALUES (?,?,?,?,?)
                        ON DUPLICATE KEY UPDATE
                            avg_cost = VALUES(avg_cost),
                            qty_valued = VALUES(qty_valued),
                            updated_at = VALUES(updated_at)
                    ")->execute([$companyId, $itemId, $wacAfter, $newQty, $moveDate]);
                } else {
                    $unitCostBase = $wacBefore;
                    inv_adjust_qty_valued($conn, $companyId, $itemId, $qtyBase, $moveDate);
                }
                $valueIn = round($qtyBase * $unitCostBase, 4);
            } else {
                // Outbound / non-receipt uses current WAC
                $unitCostBase = $wacBefore;
                $valueOut = round($qtyBase * $unitCostBase, 4);
            }

            if (in_array($docType, ['receipt','opening_balance'], true)) {
                $applyIn((int)$toLoc, $qtyBase);
                if ($serialId) {
                    $conn->prepare("UPDATE inv_serials SET status='in_stock', location_id=?, item_id=? WHERE id=? AND company_id=?")
                        ->execute([(int)$toLoc, $itemId, $serialId, $companyId]);
                }
                $moves[] = [
                    'location_id' => (int)$toLoc,
                    'qty_in' => $qtyBase,
                    'qty_out' => 0.0,
                ];
            } elseif ($docType === 'transfer') {
                $applyOut((int)$fromLoc, $qtyBase);
                $applyIn((int)$toLoc, $qtyBase);
                if ($serialId) {
                    $conn->prepare("UPDATE inv_serials SET status='in_stock', location_id=? WHERE id=? AND company_id=?")
                        ->execute([(int)$toLoc, $serialId, $companyId]);
                }
                $moves[] = ['location_id' => (int)$fromLoc, 'qty_in' => 0.0, 'qty_out' => $qtyBase];
                $moves[] = ['location_id' => (int)$toLoc, 'qty_in' => $qtyBase, 'qty_out' => 0.0];
            } elseif ($docType === 'adjustment') {
                $loc = (int)($toLoc ?: $fromLoc);
                if ($qtySign > 0) {
                    $applyIn($loc, $qtyBase);
                    $moves[] = ['location_id' => $loc, 'qty_in' => $qtyBase, 'qty_out' => 0.0];
                } else {
                    $applyOut($loc, $qtyBase);
                    inv_adjust_qty_valued($conn, $companyId, $itemId, -$qtyBase, $moveDate);
                    if ($serialId) {
                        $conn->prepare("UPDATE inv_serials SET status='scrapped', location_id=NULL WHERE id=? AND company_id=?")
                            ->execute([$serialId, $companyId]);
                    }
                    $moves[] = ['location_id' => $loc, 'qty_in' => 0.0, 'qty_out' => $qtyBase];
                }
            } else {
                // issue/wastage/sale/return (outbound from fromLoc)
                $applyOut((int)$fromLoc, $qtyBase);
                inv_adjust_qty_valued($conn, $companyId, $itemId, -$qtyBase, $moveDate);
                if ($serialId) {
                    $conn->prepare("UPDATE inv_serials SET status='issued', location_id=NULL WHERE id=? AND company_id=?")
                        ->execute([$serialId, $companyId]);
                }
                $moves[] = ['location_id' => (int)$fromLoc, 'qty_in' => 0.0, 'qty_out' => $qtyBase];
            }

            $lineMoves = $moves;
            foreach ($lineMoves as $m) {
                $qin = (float)$m['qty_in'];
                $qout = (float)$m['qty_out'];
                $vin = $qin > 0 ? round($qin * $unitCostBase, 4) : 0.0;
                $vout = $qout > 0 ? round($qout * $unitCostBase, 4) : 0.0;
                $conn->prepare("
                    INSERT INTO inv_stock_moves
                        (company_id, move_date, doc_type, doc_id, doc_line_id,
                         item_id, location_id, qty_in, qty_out, base_uom_id, qty_base,
                         lot_id, serial_id, unit_cost_base, value_in, value_out, wac_before, wac_after,
                         source_module, source_table, source_id)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                ")->execute([
                    $companyId,
                    $moveDate,
                    $docType,
                    $docId,
                    (int)$ln['id'],
                    $itemId,
                    (int)$m['location_id'],
                    $qin,
                    $qout,
                    $baseUomId,
                    $qin > 0 ? $qin : -$qout,
                    $lotId,
                    $serialId,
                    $unitCostBase,
                    $vin,
                    $vout,
                    $wacBefore,
                    $wacAfter,
                    $h['source_module'] ?? null,
                    $h['source_table'] ?? null,
                    $h['source_id'] ?? null,
                ]);
            }
        }

        $conn->prepare("
            UPDATE inv_doc_headers
            SET status='posted', posted_by=?, posted_at=?, updated_at=?
            WHERE id=?
        ")->execute([$userId, $moveDate, $moveDate, $docId]);

        if ($startedInner) {
            $conn->commit();
        }

        try {
            require_once __DIR__ . '/../AuditService.php';
            $docNo = (string)($h['doc_no'] ?? $docId);
            $companyId = isset($h['company_id']) ? (int)$h['company_id'] : null;
            AuditService::logEvent([
                'action' => 'inventory_movement',
                'module' => 'inventory',
                'company_id' => $companyId,
                'object_type' => 'inv_doc_headers',
                'object_id' => (string)$docId,
                'object_ref' => $docNo !== '' ? $docNo : ('Doc #' . $docId),
                'summary' => 'Posted inventory document ' . ($docNo !== '' ? $docNo : ('#' . $docId)),
                'user_id' => $userId,
                'source' => 'user',
                'success' => true,
            ]);
        } catch (Throwable $ignored) {
            // fail-soft
        }

        return ['success' => true, 'error' => null];
    } catch (Throwable $e) {
        if ($startedInner && $conn->inTransaction()) {
            $conn->rollBack();
        }
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

