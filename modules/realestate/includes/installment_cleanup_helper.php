<?php
/**
 * Installment cleanup helper.
 * Shared by CLI tool and admin UI.
 */

if (!function_exists('re_cleanup_non_rent_types')) {
    function re_cleanup_non_rent_types(): array {
        return ['security_deposit', 'chiller', 'ejari', 'admin', 'commission', 'amc', 'parking', 'store', 'vat', 'combined_fees'];
    }
}

if (!function_exists('re_cleanup_has_duplicate_neighbor_date')) {
    function re_cleanup_has_duplicate_neighbor_date(array $rows, int $idx): bool {
        $date = (string)$rows[$idx]['installment_date'];
        $prevSame = $idx > 0 && (string)$rows[$idx - 1]['installment_date'] === $date;
        $nextSame = $idx < count($rows) - 1 && (string)$rows[$idx + 1]['installment_date'] === $date;
        return $prevSame || $nextSame;
    }
}

if (!function_exists('re_cleanup_candidates_for_rent_rows')) {
    function re_cleanup_candidates_for_rent_rows(PDO $conn, int $leaseId, array $rentRows): array {
        $ids = array_map(fn($r) => (int)$r['id'], $rentRows);
        if (empty($ids)) return [];

        $ph = implode(',', array_fill(0, count($ids), '?'));
        $links = [];
        foreach ($ids as $id) {
            $links[$id] = [
                'has_payment_id' => false,
                'payments_count' => 0,
                'alloc_count' => 0,
                'pdc_count' => 0,
                'lc_count' => 0,
            ];
        }

        $stmt = $conn->prepare("SELECT id, payment_id FROM re_lease_installments WHERE id IN ($ph)");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $id = (int)$r['id'];
            $links[$id]['has_payment_id'] = !empty($r['payment_id']);
        }

        $stmt = $conn->prepare("SELECT installment_id, COUNT(*) c FROM re_payments WHERE installment_id IN ($ph) GROUP BY installment_id");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $links[(int)$r['installment_id']]['payments_count'] = (int)$r['c'];
        }

        try {
            $stmt = $conn->prepare("SELECT installment_id, COUNT(*) c FROM re_payment_allocations WHERE installment_id IN ($ph) GROUP BY installment_id");
            $stmt->execute($ids);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $links[(int)$r['installment_id']]['alloc_count'] = (int)$r['c'];
            }
        } catch (Throwable $e) {
            // Older environments may not have allocations table.
        }

        $stmt = $conn->prepare("SELECT installment_id, COUNT(*) c FROM re_post_dated_cheques WHERE lease_id = ? AND installment_id IN ($ph) GROUP BY installment_id");
        $stmt->execute(array_merge([$leaseId], $ids));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $links[(int)$r['installment_id']]['pdc_count'] = (int)$r['c'];
        }

        $stmt = $conn->prepare("SELECT installment_id, COUNT(*) c FROM re_lease_cheques WHERE lease_id = ? AND installment_id IN ($ph) GROUP BY installment_id");
        $stmt->execute(array_merge([$leaseId], $ids));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $links[(int)$r['installment_id']]['lc_count'] = (int)$r['c'];
        }

        $candidates = [];
        foreach ($rentRows as $idx => $row) {
            $id = (int)$row['id'];
            $isPaidLike = in_array((string)$row['status'], ['paid', 'partial'], true);
            $hasAnyLink =
                $links[$id]['has_payment_id'] ||
                $links[$id]['payments_count'] > 0 ||
                $links[$id]['alloc_count'] > 0 ||
                $links[$id]['pdc_count'] > 0 ||
                $links[$id]['lc_count'] > 0;

            if ($isPaidLike || $hasAnyLink) {
                continue;
            }

            $duplicateDate = re_cleanup_has_duplicate_neighbor_date($rentRows, $idx);
            $candidates[] = [
                'id' => $id,
                'installment_date' => $row['installment_date'],
                'amount' => (float)$row['amount'],
                'status' => (string)$row['status'],
                'duplicate_date' => $duplicateDate ? 1 : 0,
                'sort_weight' => $duplicateDate ? 0 : 1,
            ];
        }

        usort($candidates, function ($a, $b) {
            if ($a['sort_weight'] !== $b['sort_weight']) {
                return $a['sort_weight'] <=> $b['sort_weight'];
            }
            if ($a['installment_date'] !== $b['installment_date']) {
                return strcmp($b['installment_date'], $a['installment_date']);
            }
            return $b['id'] <=> $a['id'];
        });

        return $candidates;
    }
}

if (!function_exists('re_cleanup_orphan_installments')) {
    /**
     * @param array{lease_id?:int, company_id?:int, apply?:bool} $options
     * @return array<string,mixed>
     */
    function re_cleanup_orphan_installments(PDO $conn, array $options = []): array {
        $leaseFilter = !empty($options['lease_id']) ? (int)$options['lease_id'] : 0;
        $companyFilter = !empty($options['company_id']) ? (int)$options['company_id'] : 0;
        $apply = !empty($options['apply']);
        $nonRentTypes = re_cleanup_non_rent_types();

        $sql = "
            SELECT id, company_id, lease_number, number_of_installments
            FROM re_leases
            WHERE 1=1
        ";
        $params = [];
        if ($leaseFilter > 0) {
            $sql .= " AND id = ? ";
            $params[] = $leaseFilter;
        }
        if ($companyFilter > 0) {
            $sql .= " AND company_id = ? ";
            $params[] = $companyFilter;
        }
        $sql .= " ORDER BY id ASC ";

        $leasesStmt = $conn->prepare($sql);
        $leasesStmt->execute($params);
        $leases = $leasesStmt->fetchAll(PDO::FETCH_ASSOC);

        $report = [
            'mode' => $apply ? 'apply' : 'dry-run',
            'scanned' => 0,
            'with_extra' => 0,
            'fixed' => 0,
            'rows_deleted' => 0,
            'lines' => [],
            'manual_review' => [],
        ];

        foreach ($leases as $lease) {
            $report['scanned']++;
            $leaseId = (int)$lease['id'];
            $companyId = (int)$lease['company_id'];
            $expected = max(0, (int)$lease['number_of_installments']);

            if ($expected <= 0) {
                continue;
            }

            $stmt = $conn->prepare("
                SELECT id, installment_date, amount, status, installment_type
                FROM re_lease_installments
                WHERE lease_id = ? AND company_id = ?
                ORDER BY installment_date ASC, id ASC
            ");
            $stmt->execute([$leaseId, $companyId]);
            $allRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (empty($allRows)) continue;

            $rentRows = array_values(array_filter($allRows, function ($r) use ($nonRentTypes) {
                $t = (string)($r['installment_type'] ?? '');
                return $t === '' || !in_array($t, $nonRentTypes, true);
            }));

            $actual = count($rentRows);
            if ($actual <= $expected) {
                continue;
            }

            $report['with_extra']++;
            $extra = $actual - $expected;
            $candidates = re_cleanup_candidates_for_rent_rows($conn, $leaseId, $rentRows);

            if (count($candidates) < $extra) {
                $row = [
                    'lease_id' => $leaseId,
                    'lease_number' => (string)$lease['lease_number'],
                    'expected' => $expected,
                    'actual' => $actual,
                    'extra' => $extra,
                    'safe_candidates' => count($candidates),
                    'status' => 'manual_review',
                ];
                $report['manual_review'][] = $row;
                $report['lines'][] = $row;
                continue;
            }

            $toDelete = array_slice($candidates, 0, $extra);
            $idsToDelete = array_map(fn($r) => (int)$r['id'], $toDelete);

            $line = [
                'lease_id' => $leaseId,
                'lease_number' => (string)$lease['lease_number'],
                'expected' => $expected,
                'actual' => $actual,
                'extra' => $extra,
                'safe_candidates' => count($candidates),
                'delete_ids' => $idsToDelete,
                'status' => $apply ? 'pending_apply' : 'dry_run_ok',
            ];

            if (!$apply) {
                $report['lines'][] = $line;
                continue;
            }

            try {
                $conn->beginTransaction();
                foreach ($idsToDelete as $id) {
                    $conn->prepare("DELETE FROM re_post_dated_cheques WHERE lease_id = ? AND installment_id = ?")->execute([$leaseId, $id]);
                    $conn->prepare("DELETE FROM re_lease_cheques WHERE lease_id = ? AND installment_id = ?")->execute([$leaseId, $id]);
                    $conn->prepare("DELETE FROM re_lease_installments WHERE id = ? AND lease_id = ? AND company_id = ?")
                        ->execute([$id, $leaseId, $companyId]);
                    $report['rows_deleted']++;
                }
                $conn->commit();
                $report['fixed']++;
                $line['status'] = 'applied';
            } catch (Throwable $e) {
                if ($conn->inTransaction()) $conn->rollBack();
                $line['status'] = 'error';
                $line['error'] = $e->getMessage();
                $report['manual_review'][] = [
                    'lease_id' => $leaseId,
                    'lease_number' => (string)$lease['lease_number'],
                    'expected' => $expected,
                    'actual' => $actual,
                    'extra' => $extra,
                    'safe_candidates' => count($candidates),
                    'error' => $e->getMessage(),
                    'status' => 'error',
                ];
            }

            $report['lines'][] = $line;
        }

        return $report;
    }
}

