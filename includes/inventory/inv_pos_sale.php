<?php
/**
 * POS sale posting: pos_sales / pos_sale_lines; stock items post via inv sale doc; services — financial lines only.
 */

require_once __DIR__ . '/inv_helpers.php';
require_once __DIR__ . '/inv_queries.php';
require_once __DIR__ . '/inv_pricing.php';
require_once __DIR__ . '/inv_item_sale_pricing.php';
require_once __DIR__ . '/inv_item_pos_location_settings.php';
require_once __DIR__ . '/inv_posting.php';
require_once __DIR__ . '/inv_purchasing.php';

function pos_generate_sale_no(PDO $conn, int $companyId, string $docDateYmd): string {
    $year = date('Y', strtotime($docDateYmd));
    $seqKey = 'POS-SAL-' . $year;
    $n = inv_purchasing_next_seq($conn, $companyId, $seqKey);
    return 'POS-SAL-' . $year . '-' . str_pad((string)$n, 5, '0', STR_PAD_LEFT);
}

/**
 * Whether item is a service (no inventory movement for POS).
 */
function inv_item_is_pos_service(array $item): bool {
    if (!empty($item['is_service'])) {
        return (int)$item['is_service'] === 1;
    }
    return strtolower((string)($item['item_type'] ?? '')) === 'service';
}

/**
 * Build priced lines from raw cart [{item_id, qty, uom_id?}].
 *
 * @return array{lines: list<array>, error?: string}
 */
function pos_cart_expand_lines(PDO $conn, int $companyId, array $cartLines, ?int $locationId = null): array {
    $locId = $locationId !== null && $locationId > 0 ? (int)$locationId : 0;
    $out = [];
    foreach ($cartLines as $idx => $cl) {
        $itemId = (int)($cl['item_id'] ?? 0);
        $qty = (float)($cl['qty'] ?? 0);
        if ($itemId <= 0 || $qty <= 0) {
            return ['lines' => [], 'error' => 'Invalid line at index ' . $idx];
        }
        $item = inv_get_item($conn, $companyId, $itemId);
        if (!$item || empty($item['is_active'])) {
            return ['lines' => [], 'error' => 'Item not found or inactive: ' . $itemId];
        }
        if (empty($item['is_sellable'])) {
            return ['lines' => [], 'error' => 'Item not sellable: ' . ($item['item_code'] ?? $itemId)];
        }
        if ($locId > 0) {
            $pls = inv_item_pos_location_settings_get($conn, $companyId, $itemId, $locId);
            $item = inv_item_merge_pos_location_into_item($item, $pls);
        }
        $uomId = isset($cl['uom_id']) ? (int)$cl['uom_id'] : (int)$item['base_uom_id'];
        if ($uomId <= 0) {
            return ['lines' => [], 'error' => 'Invalid UoM for item ' . $itemId];
        }
        $unitExcl = inv_item_effective_unit_price_excl($item);
        $vatRate = (float)($item['vat_rate'] ?? 0);
        $isService = inv_item_is_pos_service($item);
        $amt = inv_calc_line_vat($qty, $unitExcl, $vatRate);
        $out[] = [
            'item_id' => $itemId,
            'item' => $item,
            'uom_id' => $uomId,
            'qty' => $qty,
            'unit_price_excl' => $unitExcl,
            'vat_rate' => $vatRate,
            'is_service' => $isService,
            'net_excl' => $amt['net_excl'],
            'tax' => $amt['tax'],
            'gross_incl' => $amt['gross_incl'],
        ];
    }
    return ['lines' => $out];
}

/**
 * @param list<array> $expanded from pos_cart_expand_lines
 */
function pos_sum_expanded_lines(array $expanded): array {
    $sum = inv_sum_pos_lines(array_map(function ($e) {
        return ['net_excl' => $e['net_excl'], 'tax' => $e['tax']];
    }, $expanded));
    return $sum;
}

/**
 * Post POS sale from expanded lines.
 *
 * @param list<array> $expandedLines
 * @param string|null $paymentMethod 'cash'|'card' or null
 * @param string $sourceModule e.g. pos_api, pos_retail
 * @return array{success: bool, error?: string, pos_sale_id?: int, inv_doc_id?: int|null}
 */
function pos_post_sale(PDO $conn, int $companyId, int $userId, ?int $locationId, array $expandedLines, ?string $notes = null, ?string $paymentMethod = null, string $sourceModule = 'pos_api'): array {
    if (!$expandedLines) {
        return ['success' => false, 'error' => 'Cart is empty'];
    }
    $hasStock = false;
    foreach ($expandedLines as $e) {
        if (empty($e['is_service'])) {
            $hasStock = true;
            break;
        }
    }
    if ($hasStock) {
        if (!$locationId || $locationId <= 0) {
            return ['success' => false, 'error' => 'location_id is required when selling stock items'];
        }
        $vloc = $conn->prepare("SELECT id FROM inv_locations WHERE id = ? AND company_id = ? AND is_active = 1 LIMIT 1");
        $vloc->execute([$locationId, $companyId]);
        if (!$vloc->fetchColumn()) {
            return ['success' => false, 'error' => 'Invalid or inactive location'];
        }
    }

    $totals = pos_sum_expanded_lines($expandedLines);
    $docDate = date('Y-m-d');
    $moveDate = inv_now();

    $pay = $paymentMethod !== null && $paymentMethod !== '' ? $paymentMethod : null;
    if ($pay !== null && !in_array(strtolower($pay), ['cash', 'card'], true)) {
        return ['success' => false, 'error' => 'Invalid payment method'];
    }
    $payNorm = $pay !== null ? strtolower($pay) : null;

    try {
        $conn->beginTransaction();

        $saleNo = pos_generate_sale_no($conn, $companyId, $docDate);
        $st = $conn->prepare("
            INSERT INTO pos_sales (company_id, sale_no, status, location_id, subtotal_excl, tax_total, discount_total, grand_total_incl, payment_method, source_module, notes, posted_by, posted_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        $st->execute([
            $companyId,
            $saleNo,
            'posted',
            $locationId ?: null,
            $totals['subtotal_excl'],
            $totals['tax_total'],
            0,
            $totals['grand_incl'],
            $payNorm,
            $sourceModule,
            $notes !== null && $notes !== '' ? $notes : null,
            $userId ?: null,
            $moveDate,
        ]);
        $posHeaderId = (int)$conn->lastInsertId();

        $stockForDoc = [];
        $lineNo = 0;
        foreach ($expandedLines as $e) {
            $lineNo++;
            $pst = $conn->prepare("
                INSERT INTO pos_sale_lines
                    (company_id, header_id, line_no, item_id, uom_id, qty, unit_price_excl, vat_rate, line_net_excl, line_tax, line_total_incl, is_service)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            $pst->execute([
                $companyId,
                $posHeaderId,
                $lineNo,
                $e['item_id'],
                $e['uom_id'],
                $e['qty'],
                $e['unit_price_excl'],
                $e['vat_rate'],
                $e['net_excl'],
                $e['tax'],
                $e['gross_incl'],
                $e['is_service'] ? 1 : 0,
            ]);
            $posLineId = (int)$conn->lastInsertId();
            if (!$e['is_service']) {
                $stockForDoc[] = ['pos_line_id' => $posLineId, 'line' => $e];
            }
        }

        $invDocId = null;
        if ($stockForDoc) {
            $hdrNotes = 'POS sale ' . $saleNo;
            $invDocId = inv_create_doc($conn, [
                'company_id' => $companyId,
                'doc_type' => 'sale',
                'doc_date' => $docDate,
                'status' => 'draft',
                'location_from_id' => $locationId,
                'location_to_id' => null,
                'source_module' => 'pos',
                'source_table' => 'pos_sales',
                'source_id' => $posHeaderId,
                'notes' => $hdrNotes,
                'context_building_id' => null,
                'context_unit_id' => null,
                'context_project_id' => null,
                'context_booking_id' => null,
                'context_work_order_id' => null,
                'context_housekeeping_id' => null,
                'context_cleaning_job_id' => null,
                'is_emergency_issue' => 0,
                'created_by' => $userId ?: null,
            ]);

            foreach ($stockForDoc as $sf) {
                $ln = $sf['line'];
                inv_add_line($conn, $invDocId, [
                    'item_id' => $ln['item_id'],
                    'uom_id' => $ln['uom_id'],
                    'qty' => $ln['qty'],
                    'location_from_id' => $locationId,
                    'lot_number' => null,
                    'expiry_date' => null,
                    'serial_number' => null,
                    'source_table' => 'pos_sale_lines',
                    'source_line_id' => $sf['pos_line_id'],
                ]);
            }

            $post = inv_post_doc($conn, $invDocId, $userId, false);
            if (empty($post['success'])) {
                throw new RuntimeException($post['error'] ?? 'Post failed');
            }

            $dl = $conn->prepare("SELECT id FROM inv_doc_lines WHERE header_id = ? ORDER BY id ASC");
            $dl->execute([$invDocId]);
            $docLineIds = $dl->fetchAll(PDO::FETCH_COLUMN);
            foreach ($stockForDoc as $i => $sf) {
                $idl = isset($docLineIds[$i]) ? (int)$docLineIds[$i] : null;
                if ($idl) {
                    $conn->prepare("UPDATE pos_sale_lines SET inv_doc_line_id = ? WHERE id = ? AND company_id = ?")
                        ->execute([$idl, $sf['pos_line_id'], $companyId]);
                }
            }

            $conn->prepare("UPDATE pos_sales SET inv_doc_id = ? WHERE id = ? AND company_id = ?")
                ->execute([$invDocId, $posHeaderId, $companyId]);
        }

        $conn->commit();
        return ['success' => true, 'pos_sale_id' => $posHeaderId, 'inv_doc_id' => $invDocId];
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        return ['success' => false, 'error' => $e->getMessage()];
    }
}
