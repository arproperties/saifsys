<?php
/**
 * Real Estate bank reconciliation — matching engine (Phase 2).
 */
declare(strict_types=1);

require_once __DIR__ . '/re_bank_reco_core.php';

/** @return array{score:float,reasons:list<string>} */
function re_bank_reco_score_candidate(
    string $lineDate,
    string $lineDesc,
    string $lineRef,
    float $lineAbs,
    string $candidateDate,
    string $candidateRef,
    float $candidateAmount,
    string $candidateContact = '',
    string $candidateExtra = ''
): array {
    $score = 0.0;
    $reasons = [];
    $desc = mb_strtolower(trim($lineDesc . ' ' . $lineRef . ' ' . $candidateExtra), 'UTF-8');

    if (abs($candidateAmount - $lineAbs) < 0.02) {
        $score += 40;
        $reasons[] = 'Same amount';
    } elseif (abs($candidateAmount - $lineAbs) < 1.01) {
        $score += 25;
        $reasons[] = 'Similar amount';
    }

    if ($lineDate !== '' && $candidateDate !== '') {
        $days = abs((strtotime($lineDate) - strtotime($candidateDate)) / 86400);
        if ($days <= 3) {
            $score += 25;
            $reasons[] = 'Similar date';
        } elseif ($days <= 10) {
            $score += 15;
            $reasons[] = 'Date within 10 days';
        } elseif ($days <= 30) {
            $score += 5;
        }
    }

    $ref = mb_strtolower(trim($candidateRef), 'UTF-8');
    if ($ref !== '' && str_contains($desc, $ref)) {
        $score += 20;
        $reasons[] = 'Reference match';
    }

    $contact = mb_strtolower(trim($candidateContact), 'UTF-8');
    if ($contact !== '' && mb_strlen($contact) >= 3 && str_contains($desc, $contact)) {
        $score += 15;
        $reasons[] = 'Contact in description';
    }

    return ['score' => min(100, $score), 'reasons' => array_values(array_unique($reasons))];
}

function re_bank_reco_source_label(PDO $conn, int $companyId, string $refType, int $refId): string
{
    try {
        return match ($refType) {
            'bank_reconciliation', 're_bank_reconciliation' => 'Bank reconciliation',
            're_payments' => (function () use ($conn, $companyId, $refId) {
                $st = $conn->prepare("
                    SELECT COALESCE(NULLIF(t.company_name,''), CONCAT(t.first_name,' ',t.last_name))
                    FROM re_payments p
                    JOIN re_leases l ON l.id = p.lease_id
                    JOIN re_tenants t ON t.id = l.tenant_id
                    WHERE p.id = ? AND p.company_id = ?
                ");
                $st->execute([$refId, $companyId]);
                return (string) ($st->fetchColumn() ?: '');
            })(),
            're_vendor_payments' => (function () use ($conn, $companyId, $refId) {
                $st = $conn->prepare('
                    SELECT v.vendor_name FROM re_vendor_payments vp
                    JOIN re_vendors v ON v.id = vp.vendor_id
                    WHERE vp.id = ? AND vp.company_id = ?
                ');
                $st->execute([$refId, $companyId]);
                return (string) ($st->fetchColumn() ?: '');
            })(),
            default => '',
        };
    } catch (Throwable $e) {
        return '';
    }
}

function re_bank_reco_transaction_type_label(string $sourceTable): string
{
    return match ($sourceTable) {
        're_payments' => 'Tenant receipt',
        're_vendor_payments' => 'Vendor payment',
        'bank_reconciliation', 're_bank_reconciliation' => 'Created from reconciliation',
        default => 'Journal / GL',
    };
}

/** @return array<string,mixed>|null */
function re_bank_reco_gl_row_to_candidate(PDO $conn, int $companyId, array $line, array $gl): ?array
{
    $gid = (int) $gl['id'];
    $entryAmt = re_gl_entry_amount($gl);
    $sysRem = re_gl_entry_remaining($conn, $gid, $companyId);
    if ($sysRem <= 0.009) {
        return null;
    }

    $lineAbs = round(abs((float) ($line['net_amount'] ?? $line['amount'] ?? 0)), 2);
    $lineDate = (string) ($line['statement_date'] ?? $line['txn_date'] ?? '');
    $lineDesc = (string) ($line['description'] ?? '');
    $lineRef = (string) ($line['reference'] ?? '');

    $sourceLabel = '';
    $sourceTable = 're_general_ledger';
    $sourceId = $gid;
    $refType = (string) ($gl['reference_type'] ?? '');
    $refId = (int) ($gl['reference_id'] ?? 0);
    if ($refType !== '' && $refId > 0) {
        $sourceTable = $refType;
        $sourceId = $refId;
        $sourceLabel = re_bank_reco_source_label($conn, $companyId, $refType, $refId);
    }

    $scored = re_bank_reco_score_candidate(
        $lineDate,
        $lineDesc,
        $lineRef,
        $lineAbs,
        (string) ($gl['entry_date'] ?? ''),
        (string) ($gl['reference'] ?? ''),
        $entryAmt,
        $sourceLabel,
        (string) ($gl['journal_description'] ?? $gl['description'] ?? '')
    );

    $label = trim(($gl['journal_number'] ?? 'GL') . ' — ' . ($gl['description'] ?: $gl['reference'] ?: ('#' . $gid)));
    if ($sourceLabel !== '') {
        $label .= ' (' . $sourceLabel . ')';
    }

    return [
        'system_type' => 're_general_ledger',
        'system_id' => $gid,
        'match_type' => 'journal_line',
        'source_table' => $sourceTable,
        'source_id' => $sourceId,
        'gl_line_id' => $gid,
        'amount' => $entryAmt,
        'remaining' => $sysRem,
        'score' => $scored['score'],
        'confidence' => re_bank_reco_confidence_label($scored['score']),
        'reasons' => $scored['reasons'],
        'label' => $label,
        'txn_date' => $gl['entry_date'] ?? '',
        'reference' => $gl['reference'] ?? '',
        'description' => $gl['description'] ?? '',
        'amount_diff' => round(abs($entryAmt - $lineAbs), 2),
        'transaction_type' => re_bank_reco_transaction_type_label($sourceTable),
    ];
}

/**
 * @param array<string,mixed> $filters
 * @return list<array<string,mixed>>
 */
function re_bank_reco_find_transactions(PDO $conn, int $companyId, array $line, array $filters = []): array
{
    $glAccountId = (int) ($line['gl_account_id'] ?? 0);
    if ($glAccountId <= 0) {
        return [];
    }

    $isInflow = (float) ($line['net_amount'] ?? $line['amount'] ?? 0) > 0;
    $amountCol = $isInflow ? 'debit_amount' : 'credit_amount';

    $dateFrom = trim((string) ($filters['date_from'] ?? ''));
    $dateTo = trim((string) ($filters['date_to'] ?? ''));
    if ($dateFrom === '') {
        $dateFrom = date('Y-m-d', strtotime((string) ($line['statement_date'] ?? $line['txn_date'] ?? 'now') . ' -60 days'));
    }
    if ($dateTo === '') {
        $dateTo = date('Y-m-d', strtotime((string) ($line['statement_date'] ?? $line['txn_date'] ?? 'now') . ' +60 days'));
    }

    $amount = isset($filters['amount']) && $filters['amount'] !== '' ? (float) $filters['amount'] : null;
    $reference = trim((string) ($filters['reference'] ?? ''));
    $keyword = trim((string) ($filters['keyword'] ?? ''));
    $unreconciledOnly = !empty($filters['unreconciled_only']);
    $refType = trim((string) ($filters['transaction_type'] ?? ''));

    $sql = "
        SELECT gl.*, jh.journal_number, jh.reference_type, jh.reference_id, jh.description AS journal_description
        FROM re_general_ledger gl
        LEFT JOIN re_journal_headers jh ON jh.id = gl.journal_id
        WHERE gl.company_id = ? AND gl.account_id = ?
          AND gl.{$amountCol} > 0
          AND gl.entry_date BETWEEN ? AND ?
    ";
    $params = [$companyId, $glAccountId, $dateFrom, $dateTo];

    if ($amount !== null && $amount > 0) {
        $sql .= " AND ABS(gl.{$amountCol} - ?) < 0.02";
        $params[] = $amount;
    }
    if ($reference !== '') {
        $sql .= ' AND gl.reference LIKE ?';
        $params[] = '%' . $reference . '%';
    }
    if ($keyword !== '') {
        $sql .= ' AND (gl.description LIKE ? OR gl.reference LIKE ? OR jh.description LIKE ?)';
        $params[] = '%' . $keyword . '%';
        $params[] = '%' . $keyword . '%';
        $params[] = '%' . $keyword . '%';
    }
    if ($refType !== '' && $refType !== 'all') {
        $sql .= ' AND jh.reference_type = ?';
        $params[] = $refType;
    }

    $sql .= ' ORDER BY gl.entry_date DESC, gl.id DESC LIMIT 200';
    $st = $conn->prepare($sql);
    $st->execute($params);

    $results = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $gl) {
        $c = re_bank_reco_gl_row_to_candidate($conn, $companyId, $line, $gl);
        if (!$c) {
            continue;
        }
        if ($unreconciledOnly && $c['remaining'] <= 0.009) {
            continue;
        }
        $results[] = $c;
    }
    return $results;
}

/**
 * @param list<array{system_type:string,system_id:int,amount:float}> $selections
 * @return array{success:bool,confirmed:int,error:?string}
 */
function re_bank_reco_match_selections(
    PDO $conn,
    int $companyId,
    int $lineId,
    array $selections,
    ?array $adjustment,
    ?int $userId
): array {
    $line = re_bank_get_statement_line($conn, $lineId, $companyId);
    if (!$line) {
        return ['success' => false, 'confirmed' => 0, 'error' => 'Statement line not found'];
    }
    if (re_bank_rec_period_locked($conn, $companyId, (int) $line['bank_account_id'], (string) $line['statement_date'])) {
        return ['success' => false, 'confirmed' => 0, 'error' => 'Period locked'];
    }

    $lineRem = re_bank_line_remaining($conn, $line);
    $selectedTotal = 0.0;
    foreach ($selections as $sel) {
        $selectedTotal += re_bank_rec_money((float) ($sel['amount'] ?? 0));
    }
    $adjAmt = $adjustment ? re_bank_rec_money((float) ($adjustment['amount'] ?? 0)) : 0.0;
    $target = re_bank_rec_money($lineRem);

    if (abs(($selectedTotal + $adjAmt) - $target) > 0.02) {
        return ['success' => false, 'confirmed' => 0, 'error' => 'Selected total + adjustment must equal bank line remaining (' . number_format($target, 2) . ')'];
    }

    try {
        $conn->beginTransaction();
        $confirmed = 0;

        foreach ($selections as $sel) {
            $sysType = (string) ($sel['system_type'] ?? '');
            $sysId = (int) ($sel['system_id'] ?? 0);
            $amt = re_bank_rec_money((float) ($sel['amount'] ?? 0));
            if ($amt <= 0) {
                throw new RuntimeException('Invalid selection amount');
            }

            if (in_array($sysType, ['re_general_ledger', 'gl_inflow', 'gl_outflow'], true)) {
                $glId = $sysId;
                $result = re_bank_rec_confirm_match(
                    $conn,
                    $companyId,
                    (int) $line['bank_account_id'],
                    $lineId,
                    'journal_line',
                    're_general_ledger',
                    $glId,
                    $glId,
                    $amt,
                    $userId,
                    'Find & Match',
                    null,
                    null,
                    'match'
                );
                if (empty($result['success'])) {
                    throw new RuntimeException($result['error'] ?? 'Match failed');
                }
                $confirmed++;
                continue;
            }

            throw new RuntimeException('Unsupported selection type: ' . $sysType);
        }

        if ($adjustment && $adjAmt > 0) {
            require_once __DIR__ . '/re_bank_reco_posting.php';
            $adjResult = re_bank_reco_create_adjustment($conn, $companyId, $line, $adjustment, $userId);
            if (empty($adjResult['success'])) {
                throw new RuntimeException($adjResult['error'] ?? 'Adjustment failed');
            }
        }

        re_bank_rec_refresh_line_status($conn, $companyId, $lineId);
        re_bank_rec_audit($conn, $companyId, (int) $line['bank_account_id'], $lineId, null, 'multi_match', null, json_encode([
            'selection_count' => count($selections),
            'adjustment' => $adjustment,
        ]), $userId, 'find_match');

        $conn->commit();
        return ['success' => true, 'confirmed' => $confirmed, 'error' => null];
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        return ['success' => false, 'confirmed' => 0, 'error' => $e->getMessage()];
    }
}
