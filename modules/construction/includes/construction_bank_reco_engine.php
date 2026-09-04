<?php
/**
 * Construction bank reconciliation — matching engine (Phase 2).
 */
declare(strict_types=1);

require_once __DIR__ . '/construction_bank_reconciliation.php';
require_once __DIR__ . '/construction_bank_reco_rules.php';

/** @return array{score:float,reasons:list<string>} */
function co_bank_reco_score_candidate(
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

/** @return array<string,mixed>|null */
function co_bank_reco_gl_row_to_candidate(PDO $conn, int $companyId, array $line, array $gl): ?array
{
    $gid = (int) $gl['id'];
    $entryAmt = co_gl_entry_amount($gl);
    $sysRem = round($entryAmt - co_gl_entry_matched_sum($conn, $gid), 2);
    if ($sysRem <= 0.009) {
        return null;
    }

    $isInflow = (float) ($line['amount'] ?? 0) > 0;
    $systemType = $isInflow ? 'gl_inflow' : 'gl_outflow';
    $lineAbs = round(abs((float) ($line['amount'] ?? 0)), 2);
    $lineDate = (string) ($line['txn_date'] ?? '');
    $lineDesc = (string) ($line['description'] ?? '');
    $lineRef = (string) ($line['reference'] ?? '');

    $sourceLabel = '';
    $sourceTable = 're_general_ledger';
    $sourceId = $gid;
    if (!empty($gl['reference_type']) && !empty($gl['reference_id'])) {
        $sourceTable = (string) $gl['reference_type'];
        $sourceId = (int) $gl['reference_id'];
        $sourceLabel = co_bank_reco_source_label($conn, $companyId, $sourceTable, $sourceId);
    }

    $scored = co_bank_reco_score_candidate(
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

    $rule = co_bank_rule_match_line($conn, $companyId, $line, (int) ($line['bank_account_id'] ?? 0));
    if ($rule) {
        $scored['score'] = min(100, $scored['score'] + 25);
        $scored['reasons'][] = 'Bank rule: ' . $rule['rule_name'];
    }

    $label = trim(($gl['journal_number'] ?? 'GL') . ' — ' . ($gl['description'] ?: $gl['reference'] ?: ('#' . $gid)));
    if ($sourceLabel !== '') {
        $label .= ' (' . $sourceLabel . ')';
    }

    return [
        'system_type' => $systemType,
        'system_id' => $gid,
        'source_table' => $sourceTable,
        'source_id' => $sourceId,
        'amount' => $entryAmt,
        'remaining' => $sysRem,
        'score' => $scored['score'],
        'confidence' => co_bank_confidence_label($scored['score']),
        'reasons' => $scored['reasons'],
        'label' => $label,
        'txn_date' => $gl['entry_date'],
        'reference' => $gl['reference'] ?? '',
        'description' => $gl['description'] ?? '',
        'amount_diff' => round(abs($entryAmt - $lineAbs), 2),
        'transaction_type' => co_bank_reco_transaction_type_label($sourceTable),
    ];
}

function co_bank_reco_transaction_type_label(string $sourceTable): string
{
    return match ($sourceTable) {
        'co_client_payment' => 'Client receipt',
        'co_supplier_payment' => 'Supplier payment',
        'co_contractor_payment' => 'Contractor payment',
        'co_project_cost' => 'Project cost',
        'co_bank_reconciliation' => 'Created from reconciliation',
        default => 'Journal / GL',
    };
}

function co_bank_reco_source_label(PDO $conn, int $companyId, string $refType, int $refId): string
{
    try {
        return match ($refType) {
            'co_client_payment' => (function () use ($conn, $companyId, $refId) {
                $st = $conn->prepare('SELECT c.client_name FROM co_client_payments p LEFT JOIN co_clients c ON c.id = p.client_id WHERE p.id = ? AND p.company_id = ?');
                $st->execute([$refId, $companyId]);
                return (string) ($st->fetchColumn() ?: '');
            })(),
            'co_supplier_payment' => (function () use ($conn, $companyId, $refId) {
                $st = $conn->prepare('SELECT s.supplier_name FROM co_supplier_payments p JOIN co_suppliers s ON s.id = p.supplier_id WHERE p.id = ? AND p.company_id = ?');
                $st->execute([$refId, $companyId]);
                return (string) ($st->fetchColumn() ?: '');
            })(),
            'co_contractor_payment' => (function () use ($conn, $companyId, $refId) {
                $st = $conn->prepare('
                    SELECT c.contractor_name FROM co_contractor_payments cp
                    JOIN co_project_contractors pc ON pc.id = cp.project_contractor_id
                    JOIN co_contractors c ON c.id = pc.contractor_id
                    WHERE cp.id = ? AND cp.company_id = ?
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

/**
 * @return list<array<string,mixed>>
 */
function co_bank_reco_suggestions_for_line(PDO $conn, int $companyId, array $line, int $minScore = 50): array
{
    $glAccountId = (int) ($line['gl_account_id'] ?? 0);
    if ($glAccountId <= 0) {
        return [];
    }
    $abs = round(abs((float) ($line['amount'] ?? 0)), 2);
    if ($abs <= 0) {
        return [];
    }
    $date = (string) ($line['txn_date'] ?? date('Y-m-d'));
    $isInflow = (float) ($line['amount'] ?? 0) > 0;
    $amountCol = $isInflow ? 'debit_amount' : 'credit_amount';

    $st = $conn->prepare("
        SELECT gl.*, jh.journal_number, jh.reference_type, jh.reference_id, jh.description AS journal_description
        FROM re_general_ledger gl
        LEFT JOIN re_journal_headers jh ON jh.id = gl.journal_id
        WHERE gl.company_id = ? AND gl.account_id = ?
          AND gl.{$amountCol} > 0
          AND gl.entry_date BETWEEN DATE_SUB(?, INTERVAL 30 DAY) AND DATE_ADD(?, INTERVAL 30 DAY)
        ORDER BY ABS(gl.{$amountCol} - ?) ASC, ABS(DATEDIFF(gl.entry_date, ?)) ASC, gl.id DESC
        LIMIT 100
    ");
    $st->execute([$companyId, $glAccountId, $date, $date, $abs, $date]);

    $suggestions = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $gl) {
        $c = co_bank_reco_gl_row_to_candidate($conn, $companyId, $line, $gl);
        if (!$c || $c['score'] < $minScore) {
            continue;
        }
        $suggestions[] = $c;
    }
    usort($suggestions, static fn($a, $b) => $b['score'] <=> $a['score']);
    return array_slice($suggestions, 0, 15);
}

/**
 * @param array<string,mixed> $filters
 * @return list<array<string,mixed>>
 */
function co_bank_reco_find_transactions(PDO $conn, int $companyId, array $line, array $filters = []): array
{
    $glAccountId = (int) ($line['gl_account_id'] ?? 0);
    if ($glAccountId <= 0) {
        return [];
    }

    $isInflow = (float) ($line['amount'] ?? 0) > 0;
    $amountCol = $isInflow ? 'debit_amount' : 'credit_amount';

    $dateFrom = trim((string) ($filters['date_from'] ?? ''));
    $dateTo = trim((string) ($filters['date_to'] ?? ''));
    if ($dateFrom === '') {
        $dateFrom = date('Y-m-d', strtotime((string) ($line['txn_date'] ?? 'now') . ' -60 days'));
    }
    if ($dateTo === '') {
        $dateTo = date('Y-m-d', strtotime((string) ($line['txn_date'] ?? 'now') . ' +60 days'));
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
        $c = co_bank_reco_gl_row_to_candidate($conn, $companyId, $line, $gl);
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

/** @return array{proposed:int,skipped:list<array<string,mixed>>} */
function co_bank_reco_auto_propose(PDO $conn, int $companyId, int $bankAccountId, string $from, string $to, ?int $userId = null): array
{
    $bank = co_bank_verify_account($conn, $bankAccountId, $companyId);
    if (!$bank) {
        return ['proposed' => 0, 'skipped' => [['reason' => 'invalid_bank']]];
    }

    $st = $conn->prepare("
        SELECT l.*, ? AS gl_account_id
        FROM co_bank_statement_lines l
        WHERE l.bank_account_id = ? AND l.company_id = ? AND l.txn_date BETWEEN ? AND ?
        ORDER BY l.txn_date ASC, l.id ASC
    ");
    $st->execute([(int) $bank['gl_account_id'], $bankAccountId, $companyId, $from, $to]);
    $lines = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $proposed = 0;
    $skipped = [];

    foreach ($lines as $line) {
        $lineId = (int) $line['id'];
        if (co_bank_is_period_locked($conn, $bankAccountId, $line['txn_date'])) {
            $skipped[] = ['line_id' => $lineId, 'reason' => 'period_locked'];
            continue;
        }
        if (co_bank_line_remaining($conn, $line) <= 0.009) {
            continue;
        }

        $existing = $conn->prepare("SELECT COUNT(*) FROM co_reconciliation_matches WHERE bank_statement_line_id = ? AND status IN ('proposed','confirmed')");
        $existing->execute([$lineId]);
        if ((int) $existing->fetchColumn() > 0) {
            continue;
        }

        $suggestions = co_bank_reco_suggestions_for_line($conn, $companyId, $line, 70);
        $top = $suggestions[0] ?? null;
        if (!$top) {
            $skipped[] = ['line_id' => $lineId, 'reason' => 'no_suggestion'];
            continue;
        }

        $rem = co_bank_line_remaining($conn, $line);
        $amt = round(min($rem, (float) $top['remaining']), 2);
        co_bank_reco_insert_proposed_match($conn, $companyId, $bankAccountId, $lineId, $top, $amt, $userId);
        co_bank_refresh_line_status($conn, $lineId, $companyId);
        $proposed++;
    }

    return ['proposed' => $proposed, 'skipped' => $skipped];
}

/** @param array<string,mixed> $candidate */
function co_bank_reco_insert_proposed_match(
    PDO $conn,
    int $companyId,
    int $bankAccountId,
    int $lineId,
    array $candidate,
    float $amount,
    ?int $userId
): int {
    $insCols = 'bank_statement_line_id, system_type, system_id, amount_matched, status, period_lock_key, created_by';
    $insVals = [
        $lineId,
        $candidate['system_type'],
        (int) $candidate['system_id'],
        $amount,
        'proposed',
        null,
        $userId ?: null,
    ];
    if (co_db_column_exists($conn, 'co_reconciliation_matches', 'company_id')) {
        $insCols .= ', company_id, bank_account_id, source_table, source_id, match_method, confidence_score, confidence_label';
        $insVals = array_merge($insVals, [
            $companyId,
            $bankAccountId,
            $candidate['source_table'] ?? null,
            $candidate['source_id'] ?? null,
            'match',
            $candidate['score'] ?? null,
            $candidate['confidence'] ?? null,
        ]);
    }
    $conn->prepare('INSERT INTO co_reconciliation_matches (' . $insCols . ') VALUES (' . implode(',', array_fill(0, count($insVals), '?')) . ')')
        ->execute($insVals);
    return (int) $conn->lastInsertId();
}

/**
 * @param list<array{system_type:string,system_id:int,amount:float}> $selections
 * @return array{success:bool,confirmed:int,error:?string}
 */
function co_bank_reco_match_selections(
    PDO $conn,
    int $companyId,
    int $lineId,
    array $selections,
    ?array $adjustment,
    ?int $userId
): array {
    $line = co_bank_get_statement_line($conn, $lineId, $companyId);
    if (!$line) {
        return ['success' => false, 'confirmed' => 0, 'error' => 'Statement line not found'];
    }
    if (co_bank_is_period_locked($conn, (int) $line['bank_account_id'], $line['txn_date'])) {
        return ['success' => false, 'confirmed' => 0, 'error' => 'Period locked'];
    }

    $lineRem = co_bank_line_remaining($conn, $line);
    $selectedTotal = 0.0;
    foreach ($selections as $sel) {
        $selectedTotal += co_bank_reco_money((float) ($sel['amount'] ?? 0));
    }
    $adjAmt = $adjustment ? co_bank_reco_money((float) ($adjustment['amount'] ?? 0)) : 0.0;
    $target = co_bank_reco_money($lineRem);

    if (abs(($selectedTotal + $adjAmt) - $target) > 0.02) {
        return ['success' => false, 'confirmed' => 0, 'error' => 'Selected total + adjustment must equal bank line remaining (' . number_format($target, 2) . ')'];
    }

    try {
        $conn->beginTransaction();
        $matchIds = [];

        foreach ($selections as $sel) {
            $sysType = (string) ($sel['system_type'] ?? '');
            $sysId = (int) ($sel['system_id'] ?? 0);
            $amt = co_bank_reco_money((float) ($sel['amount'] ?? 0));
            if ($amt <= 0 || !in_array($sysType, ['gl_inflow', 'gl_outflow'], true)) {
                throw new RuntimeException('Invalid selection');
            }
            $matchIds[] = co_bank_reco_insert_and_confirm_match($conn, $companyId, (int) $line['bank_account_id'], $lineId, $sysType, $sysId, $amt, 'match', $userId);
        }

        if ($adjustment && $adjAmt > 0) {
            require_once __DIR__ . '/construction_bank_reco_posting.php';
            $adjResult = co_bank_reco_create_adjustment($conn, $companyId, $line, $adjustment, $userId);
            if (!$adjResult['success']) {
                throw new RuntimeException($adjResult['error'] ?? 'Adjustment failed');
            }
        }

        co_bank_refresh_line_status($conn, $lineId, $companyId);
        co_bank_reco_audit($conn, $companyId, (int) $line['bank_account_id'], $lineId, null, 'multi_match', null, [
            'match_ids' => $matchIds,
            'selection_count' => count($selections),
            'adjustment' => $adjustment,
        ], $userId);

        $conn->commit();
        return ['success' => true, 'confirmed' => count($matchIds), 'error' => null];
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        return ['success' => false, 'confirmed' => 0, 'error' => $e->getMessage()];
    }
}

function co_bank_reco_insert_and_confirm_match(
    PDO $conn,
    int $companyId,
    int $bankAccountId,
    int $lineId,
    string $systemType,
    int $systemId,
    float $amount,
    string $method,
    ?int $userId,
    ?int $createdJournalId = null
): int {
    $insCols = 'bank_statement_line_id, system_type, system_id, amount_matched, status, period_lock_key, created_by, confirmed_by, confirmed_at';
    $insVals = [$lineId, $systemType, $systemId, $amount, 'confirmed', null, $userId ?: null, $userId ?: null, date('Y-m-d H:i:s')];
    if (co_db_column_exists($conn, 'co_reconciliation_matches', 'company_id')) {
        $insCols .= ', company_id, bank_account_id, match_method';
        $insVals = array_merge($insVals, [$companyId, $bankAccountId, $method]);
        if ($createdJournalId && co_db_column_exists($conn, 'co_reconciliation_matches', 'created_transaction_id')) {
            $insCols .= ', created_transaction_id';
            $insVals[] = $createdJournalId;
        }
    }
    $conn->prepare('INSERT INTO co_reconciliation_matches (' . $insCols . ') VALUES (' . implode(',', array_fill(0, count($insVals), '?')) . ')')
        ->execute($insVals);
    $matchId = (int) $conn->lastInsertId();
    co_bank_sync_gl_reconciled_flag($conn, $systemId, $companyId, $userId);
    return $matchId;
}
