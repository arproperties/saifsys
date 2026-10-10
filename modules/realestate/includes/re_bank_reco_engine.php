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
          " . re_bank_rec_sql_skip_edit_reversals('gl') . "
          " . re_bank_rec_sql_skip_matched_sources('gl') . "
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
    ?int $userId,
    bool $allowPartial = false
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

    if ($allowPartial && !$adjustment && $selectedTotal > 0 && $selectedTotal <= $target + 0.02) {
        // One receipt of a split bank line: the rest of the line stays open.
    } elseif (abs(($selectedTotal + $adjAmt) - $target) > 0.02) {
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

/**
 * One bank line paid as several tenant receipts (e.g. rent + service charge from one transfer).
 * Looks for unreconciled receipts of a single tenant, dated near the line, that add up exactly
 * to what is left on the line. Returns null unless one tenant clearly fits. Once part of the
 * line is matched, a single receipt for the rest is enough.
 *
 * When nothing adds up exactly (a receipt was edited, or one is still to be recorded), the
 * receipts tied to the bank line are returned with the difference ('short' or 'over' > 0)
 * so the screen can show what does not add up. Those are for display only, not for reconciling.
 *
 * @return array<string,mixed>|null
 */
function re_bank_reco_combined_suggestion(PDO $conn, int $companyId, array $line): ?array
{
    $glAccountId = (int) ($line['gl_account_id'] ?? 0);
    $target = re_bank_rec_money((float) ($line['remaining'] ?? 0));
    $date = (string) ($line['statement_date'] ?? '');
    if ($glAccountId <= 0 || $target <= 0 || $date === '' || (float) ($line['net_amount'] ?? 0) <= 0) {
        return null;
    }

    $partlyMatched = $target + 0.009 < abs((float) ($line['net_amount'] ?? 0));
    $minReceipts = $partlyMatched ? 1 : 2;
    if (!$partlyMatched) {
        // A single entry for the full amount is the normal one-to-one match, not a split.
        $one = $conn->prepare("
            SELECT 1 FROM re_general_ledger gl
            WHERE gl.company_id = ? AND gl.account_id = ? AND ABS(gl.debit_amount - ?) < 0.01 AND COALESCE(gl.is_reconciled, 0) = 0
              AND gl.entry_date BETWEEN DATE_SUB(?, INTERVAL 7 DAY) AND DATE_ADD(?, INTERVAL 7 DAY)
              " . re_bank_rec_sql_skip_edit_reversals('gl') . "
              " . re_bank_rec_sql_skip_matched_sources('gl') . "
            LIMIT 1
        ");
        $one->execute([$companyId, $glAccountId, $target, $date, $date]);
        if ($one->fetchColumn()) {
            return null;
        }
    }

    $st = $conn->prepare("
        SELECT gl.id AS gl_id, gl.entry_date, gl.debit_amount, gl.credit_amount,
               p.id AS payment_id, p.receipt_number, p.reference_number, l.lease_number,
               (SELECT pc.cheque_number FROM re_post_dated_cheques pc
                 WHERE pc.id = p.cheque_id AND pc.company_id = p.company_id LIMIT 1) AS cheque_number,
               COALESCE(p.tenant_id, l.tenant_id) AS tenant_id,
               COALESCE(NULLIF(t.company_name,''), CONCAT(t.first_name,' ',t.last_name)) AS tenant_name
        FROM re_general_ledger gl
        JOIN re_journal_headers jh ON jh.id = gl.journal_id AND jh.reference_type IN ('payment','re_payments')
        JOIN re_payments p ON p.id = jh.reference_id AND p.company_id = gl.company_id
        LEFT JOIN re_leases l ON l.id = p.lease_id
        LEFT JOIN re_tenants t ON t.id = COALESCE(p.tenant_id, l.tenant_id)
        WHERE gl.company_id = ? AND gl.account_id = ? AND gl.debit_amount > 0
          AND gl.debit_amount <= ?
          AND gl.entry_date BETWEEN DATE_SUB(?, INTERVAL 7 DAY) AND DATE_ADD(?, INTERVAL 7 DAY)
          " . re_bank_rec_sql_skip_edit_reversals('gl') . "
          " . re_bank_rec_sql_skip_matched_sources('gl') . "
        ORDER BY gl.entry_date, gl.id
        LIMIT 500
    ");
    $st->execute([$companyId, $glAccountId, $target + 0.005, $date, $date]);

    $groups = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $tenantId = (int) ($row['tenant_id'] ?? 0);
        if ($tenantId <= 0) {
            continue;
        }
        $rem = re_gl_entry_remaining($conn, (int) $row['gl_id'], $companyId);
        if ($rem <= 0.009) {
            continue;
        }
        $row['remaining'] = $rem;
        $groups[$tenantId][] = $row;
    }

    $haystack = mb_strtolower((string) ($line['description'] ?? '') . ' ' . (string) ($line['reference'] ?? ''), 'UTF-8');
    $lineCheque = preg_match('/chq\\.?\\s*no\\.?\\s*:?\\s*(\\d+)/i', $haystack, $cm) ? ltrim($cm[1], '0') : '';
    $found = [];
    $under = [];
    foreach ($groups as $rows) {
        $n = count($rows);
        if ($n > 12) {
            continue;
        }
        $groupName = re_bank_rec_name_match_score($haystack, (string) ($rows[0]['tenant_name'] ?? ''));
        // The line is tied to this tenant by name, by the bank text saved on a receipt, or by
        // the cheque number printed on a cheque-deposit line.
        $identified = !empty($groupName['strong']);
        foreach ($rows as $row) {
            $ref = mb_strtolower(trim((string) ($row['reference_number'] ?? '')), 'UTF-8');
            $chq = ltrim(trim((string) ($row['cheque_number'] ?? '')), '0');
            if ((mb_strlen($ref, 'UTF-8') >= 20 && str_contains($haystack, $ref))
                || ($chq !== '' && $lineCheque !== '' && $chq === $lineCheque)) {
                $identified = true;
                break;
            }
        }
        // Smallest set of receipts that adds up to the line.
        $best = null;
        $bestUnder = null;
        $bestUnderSum = 0.0;
        for ($mask = 1; $mask < (1 << $n); $mask++) {
            $sum = 0.0;
            $picked = [];
            for ($i = 0; $i < $n; $i++) {
                if ($mask & (1 << $i)) {
                    $sum += $rows[$i]['remaining'];
                    $picked[] = $rows[$i];
                }
            }
            if (count($picked) >= $minReceipts && abs($sum - $target) < 0.01 && ($best === null || count($picked) < count($best))) {
                $best = $picked;
            }
            // Set closest to the line (below or above it), kept only when the line is tied to this tenant.
            if ($identified && abs($sum - $target) >= 0.01
                && ($bestUnder === null || abs($sum - $target) < abs($bestUnderSum - $target) - 0.009)) {
                $bestUnder = $picked;
                $bestUnderSum = $sum;
            }
        }
        if ($best !== null) {
            $found[] = ['rows' => $best, 'name' => $groupName, 'sum' => $target];
        } elseif ($bestUnder !== null && $bestUnderSum >= $target * 0.5 && $bestUnderSum <= $target * 1.5) {
            // Further off than half the line it is more likely a different payment than an edited receipt.
            $under[] = ['rows' => $bestUnder, 'name' => $groupName, 'sum' => round($bestUnderSum, 2)];
        }
    }
    if (!$found) {
        // Nothing adds up: show the receipts of the one tenant tied to the line, with what is missing.
        if (count($under) !== 1) {
            return null;
        }
        $found = $under;
    }
    // Several tenants fit the amount: only trust the one whose name is on the bank line.
    if (count($found) > 1) {
        $found = array_values(array_filter($found, static fn(array $f): bool => !empty($f['name']['strong'])));
        if (count($found) !== 1) {
            return null;
        }
    }

    $hit = $found[0];
    $items = [];
    $leases = [];
    foreach ($hit['rows'] as $row) {
        $items[] = [
            'system_type' => 're_general_ledger',
            'system_id' => (int) $row['gl_id'],
            'payment_id' => (int) $row['payment_id'],
            'amount' => (float) $row['remaining'],
            'txn_date' => (string) $row['entry_date'],
            'receipt_number' => (string) ($row['receipt_number'] ?? ''),
            'reference' => (string) ($row['reference_number'] ?? ''),
        ];
        if (!empty($row['lease_number'])) {
            $leases[(string) $row['lease_number']] = true;
        }
    }
    $short = round($target - (float) $hit['sum'], 2);
    $over = 0.0;
    if (abs($short) > 0.009) {
        $reasons = [(count($items) > 1 ? count($items) . ' receipts total ' : 'Receipt of ') . number_format((float) $hit['sum'], 2)
            . ' — ' . number_format(abs($short), 2) . ($short > 0 ? ' short of' : ' more than') . ' the ' . number_format($target, 2) . ' on this line'];
        if ($short < 0) {
            $over = abs($short);
            $short = 0.0;
        }
    } else {
        $short = 0.0;
        $reasons = [count($items) > 1
            ? count($items) . ' receipts add up to ' . number_format($target, 2)
            : 'Receipt equals the ' . number_format($target, 2) . ' left on this line'];
    }
    if (!empty($hit['name']['reason'])) {
        $reasons[] = (string) $hit['name']['reason'];
    }

    return [
        'items' => $items,
        'total' => round((float) $hit['sum'], 2),
        'short' => $short,
        'over' => $over,
        'line_amount' => round(abs((float) ($line['net_amount'] ?? 0)), 2),
        'party_name' => (string) ($hit['rows'][0]['tenant_name'] ?? ''),
        'lease_number' => implode(', ', array_keys($leases)),
        'confidence' => (!empty($hit['name']['strong']) && $short <= 0 && $over <= 0) ? 'High' : 'Medium',
        'reasons' => $reasons,
    ];
}

/**
 * Tenant receipt (re_payments.id) behind a suggestion or ERP candidate, for the "View receipt" link.
 * 0 when the entry is not a tenant receipt.
 */
function re_bank_reco_receipt_id(PDO $conn, int $companyId, array $s): int
{
    $table = (string) ($s['source_table'] ?? '');
    if (in_array($table, ['re_payments', 'payment'], true) && (int) ($s['source_id'] ?? 0) > 0) {
        return (int) $s['source_id'];
    }
    $glId = (int) ($s['gl_line_id'] ?? 0);
    if ($glId <= 0) {
        return 0;
    }
    $st = $conn->prepare("
        SELECT p.id
        FROM re_general_ledger gl
        JOIN re_journal_headers jh ON jh.id = gl.journal_id AND jh.reference_type IN ('payment','re_payments')
        JOIN re_payments p ON p.id = jh.reference_id AND p.company_id = gl.company_id
        WHERE gl.id = ? AND gl.company_id = ?
        LIMIT 1
    ");
    $st->execute([$glId, $companyId]);
    return (int) $st->fetchColumn();
}

/**
 * Bank's own transaction code at the end of a line ("... - AE0195395"). A charge and its VAT
 * line carry the same code, which is how the two are paired.
 */
function re_bank_reco_line_bank_code(string $description): string
{
    return preg_match('/-\s*([A-Z]{2}\d{6,})\s*$/', trim($description), $m) ? $m[1] : '';
}

/** The bank's own VAT line for a charge ("VALUE ADDED TAX...", "Value Added Tax (VAT) @ 5%"). */
function re_bank_reco_is_vat_line(string $description): bool
{
    return (bool) preg_match('/VALUE ADDED TAX|\bVAT\b/i', $description);
}

/**
 * Splits lines sharing one bank code into the sets that belong together. The bank puts a
 * transfer, its fee and the fee's VAT under one code, but only the fee and its VAT are one
 * entry: each VAT line is paired with the line it is the VAT of (amount x rate), and the
 * transfer itself is left on its own. With no VAT line in the set, the lines stay together.
 *
 * @param array<int,array{amount:float,description:string}> $lines keyed by caller's index
 * @return list<list<int>> sets of the caller's indexes, each with two or more lines
 */
function re_bank_reco_split_code_group(array $lines, float $vatRate = 5.0): array
{
    $vat = [];
    $base = [];
    foreach ($lines as $i => $l) {
        if (re_bank_reco_is_vat_line((string) $l['description'])) {
            $vat[] = $i;
        } else {
            $base[] = $i;
        }
    }
    if (!$vat) {
        return count($lines) >= 2 ? [array_keys($lines)] : [];
    }
    $groups = [];
    $loose = [];
    foreach ($vat as $v) {
        $hit = null;
        foreach ($base as $k => $b) {
            if (abs(round(abs((float) $lines[$b]['amount']) * $vatRate / 100, 2) - abs((float) $lines[$v]['amount'])) <= 0.011) {
                $hit = $k;
                break;
            }
        }
        if ($hit === null) {
            $loose[] = $v;
            continue;
        }
        $groups[] = [$base[$hit], $v];
        unset($base[$hit]);
    }
    // A VAT line whose amount fits nothing still belongs to a fee line, never to the transfer.
    if ($loose) {
        $fees = array_values(array_filter($base, static fn(int $b): bool => (bool) preg_match('/CHARGE|CHGS|\bFEE|COMMISSION/i', (string) $lines[$b]['description'])));
        if ($fees) {
            $groups[] = array_merge($fees, $loose);
        }
    }
    return $groups;
}

/**
 * Bank lines that the ERP holds as one entry (a bank charge and its VAT line booked as one
 * expense). Loads and checks them: same bank account, same day, same bank code, same direction,
 * all still open.
 *
 * @param list<int> $lineIds
 * @return array{success:bool,lines:list<array<string,mixed>>,total:float,error:?string}
 */
function re_bank_reco_load_line_group(PDO $conn, int $companyId, array $lineIds): array
{
    $fail = static fn(string $msg): array => ['success' => false, 'lines' => [], 'total' => 0.0, 'error' => $msg];
    $lineIds = array_values(array_unique(array_filter(array_map('intval', $lineIds), static fn(int $i): bool => $i > 0)));
    if (count($lineIds) < 2 || count($lineIds) > 10) {
        return $fail('Select the charge line together with its VAT line.');
    }
    $lines = [];
    $total = 0.0;
    foreach ($lineIds as $lineId) {
        $line = re_bank_get_statement_line($conn, $lineId, $companyId);
        if (!$line) {
            return $fail('Statement line not found.');
        }
        $line['bank_code'] = re_bank_reco_line_bank_code((string) $line['description']);
        if ($line['bank_code'] === '') {
            return $fail('These lines do not carry a shared bank code.');
        }
        if ($lines && (
            (int) $line['bank_account_id'] !== (int) $lines[0]['bank_account_id']
            || $line['is_spent'] !== $lines[0]['is_spent']
            || (string) $line['statement_date'] !== (string) $lines[0]['statement_date']
            || $line['bank_code'] !== $lines[0]['bank_code']
        )) {
            return $fail('Only lines the bank posted together (same day, same bank code) can be reconciled as one.');
        }
        if (in_array((string) $line['status'], ['ignored', 'investigating'], true)) {
            return $fail('A selected line is on hold or ignored.');
        }
        if ((float) $line['remaining'] <= 0.009) {
            return $fail('A selected line is already fully reconciled.');
        }
        $lines[] = $line;
        $total += (float) $line['remaining'];
    }
    return ['success' => true, 'lines' => $lines, 'total' => re_bank_rec_money($total), 'error' => null];
}

/**
 * ERP entries whose open amount equals the total of the grouped bank lines. Bank charges repeat
 * with the same amount, so only the entry dated the same day is offered; when there is none,
 * entries up to 3 days either side, nearest first.
 *
 * @param list<array<string,mixed>> $lines
 * @return list<array<string,mixed>>
 */
function re_bank_reco_line_group_candidates(PDO $conn, int $companyId, array $lines, float $total): array
{
    $date = (string) $lines[0]['statement_date'];
    $found = re_bank_reco_find_transactions($conn, $companyId, $lines[0], [
        'date_from' => date('Y-m-d', strtotime($date . ' -3 days')),
        'date_to' => date('Y-m-d', strtotime($date . ' +3 days')),
        'amount' => (string) $total,
        'unreconciled_only' => true,
    ]);
    $out = [];
    foreach ($found as $c) {
        if (abs((float) $c['remaining'] - $total) > 0.02) {
            continue;
        }
        $c['day_gap'] = (int) round(abs(strtotime((string) $c['txn_date']) - strtotime($date)) / 86400);
        // The bank-side ledger line often just says "Payment"; the journal says what it was for.
        $jh = $conn->prepare('
            SELECT jh.journal_number, jh.description
            FROM re_general_ledger gl
            JOIN re_journal_headers jh ON jh.id = gl.journal_id
            WHERE gl.id = ? AND gl.company_id = ?
            LIMIT 1
        ');
        $jh->execute([(int) $c['system_id'], $companyId]);
        if (($journal = $jh->fetch(PDO::FETCH_ASSOC)) && trim((string) $journal['description']) !== '') {
            $c['label'] = trim($journal['journal_number'] . ' — ' . $journal['description']);
        }
        $out[] = $c;
    }
    usort($out, static fn(array $a, array $b): int => [$a['day_gap'], $a['system_id']] <=> [$b['day_gap'], $b['system_id']]);
    $sameDay = array_values(array_filter($out, static fn(array $c): bool => $c['day_gap'] === 0));
    return $sameDay ?: $out;
}

/**
 * Reconcile the grouped bank lines against one ERP ledger entry: each line is matched for its own
 * open amount, and together they equal what is open on the entry.
 *
 * @param list<int> $lineIds
 * @return array{success:bool,confirmed:int,error:?string}
 */
function re_bank_reco_match_lines_to_gl(PDO $conn, int $companyId, array $lineIds, int $glLineId, ?int $userId): array
{
    $group = re_bank_reco_load_line_group($conn, $companyId, $lineIds);
    if (empty($group['success'])) {
        return ['success' => false, 'confirmed' => 0, 'error' => $group['error']];
    }
    $lines = $group['lines'];
    $total = (float) $group['total'];
    foreach ($lines as $line) {
        if (re_bank_rec_period_locked($conn, $companyId, (int) $line['bank_account_id'], (string) $line['statement_date'])) {
            return ['success' => false, 'confirmed' => 0, 'error' => 'Period locked'];
        }
    }

    // Only an entry the screen offers for these lines.
    $allowed = false;
    foreach (re_bank_reco_line_group_candidates($conn, $companyId, $lines, $total) as $c) {
        if ((int) $c['system_id'] === $glLineId) {
            $allowed = true;
            break;
        }
    }
    if (!$allowed) {
        return ['success' => false, 'confirmed' => 0, 'error' => 'The ERP entry must be unreconciled, equal the lines total (' . number_format($total, 2) . ') and be dated with the bank lines.'];
    }

    try {
        $conn->beginTransaction();
        $confirmed = 0;
        foreach ($lines as $line) {
            $result = re_bank_rec_confirm_match(
                $conn,
                $companyId,
                (int) $line['bank_account_id'],
                (int) $line['id'],
                'journal_line',
                're_general_ledger',
                $glLineId,
                $glLineId,
                (float) $line['remaining'],
                $userId,
                'Charge + VAT lines (' . count($lines) . ' lines, total ' . number_format($total, 2) . ')',
                null,
                null,
                'match'
            );
            if (empty($result['success'])) {
                throw new RuntimeException($result['error'] ?? 'Match failed');
            }
            $confirmed++;
        }
        re_bank_rec_audit($conn, $companyId, (int) $lines[0]['bank_account_id'], (int) $lines[0]['id'], null, 'group_match', null, json_encode([
            'line_ids' => array_map(static fn(array $l): int => (int) $l['id'], $lines),
            'gl_line_id' => $glLineId,
            'total' => $total,
        ]), $userId, 'grouped_lines');
        $conn->commit();
        return ['success' => true, 'confirmed' => $confirmed, 'error' => null];
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        return ['success' => false, 'confirmed' => 0, 'error' => $e->getMessage()];
    }
}

/**
 * A cheque the bank took in and sent back: a deposit line and a return line with the same bank
 * reference and opposite amounts. No money stayed, so the two lines close each other and nothing
 * is posted. Null when the line is not one half of such a pair.
 *
 * @return array<string,mixed>|null
 */
function re_bank_reco_bounced_pair(PDO $conn, int $companyId, array $line): ?array
{
    $lineId = (int) ($line['id'] ?? 0);
    $net = (float) ($line['net_amount'] ?? 0);
    $amount = re_bank_rec_money(abs($net));
    $ref = trim((string) ($line['reference'] ?? ''));
    if ($lineId <= 0 || $amount <= 0 || mb_strlen($ref, 'UTF-8') < 8
        || in_array((string) ($line['status'] ?? ''), ['ignored', 'investigating'], true)
        || abs(re_bank_line_remaining($conn, $line) - $amount) > 0.009) {
        return null;
    }

    $st = $conn->prepare("
        SELECT l.* FROM re_bank_statement_lines l
        WHERE l.company_id = ? AND l.bank_account_id = ? AND l.id <> ? AND l.reference = ?
          AND ABS(l.net_amount + ?) < 0.01
          AND l.status NOT IN ('ignored','investigating')
          AND l.statement_date BETWEEN DATE_SUB(?, INTERVAL 10 DAY) AND DATE_ADD(?, INTERVAL 10 DAY)
          AND NOT EXISTS (SELECT 1 FROM re_bank_reconciliation_matches m
                           WHERE m.company_id = l.company_id AND m.statement_line_id = l.id AND m.status = 'confirmed')
        LIMIT 20
    ");
    $date = (string) ($line['statement_date'] ?? '');
    $st->execute([$companyId, (int) ($line['bank_account_id'] ?? 0), $lineId, $ref, $net, $date, $date]);
    $partners = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (count($partners) > 1) {
        // Some banks put the account number in the reference, so two bounced cheques of the same
        // amount share it. The cheque number in the text tells them apart.
        $chqOf = static fn(array $l): string => preg_match('/chq\.?\s*no\.?\s*:?\s*(\d+)/i', (string) ($l['description'] ?? ''), $m) ? ltrim($m[1], '0') : '';
        $mine = $chqOf($line);
        $partners = $mine === '' ? [] : array_values(array_filter($partners, static fn(array $p): bool => $chqOf($p) === $mine));
    }
    if (count($partners) !== 1) {
        return null;
    }
    $partner = $partners[0];
    $deposit = $net > 0 ? $line : $partner;
    $return = $net > 0 ? $partner : $line;
    $returnText = (string) ($return['description'] ?? '');
    $bothText = (string) ($deposit['description'] ?? '') . ' ' . $returnText;
    // The money-out line must say it is a returned cheque; a refund or transfer back is not a bounce.
    if (!preg_match('/REJECT|RETURN|BOUNC|\bRET\b/i', $returnText) || !preg_match('/CHQ|CHEQUE/i', $bothText)) {
        return null;
    }

    $chequeNo = preg_match('/chq\.?\s*no\.?\s*:?\s*(\d+)/i', $bothText, $cm) ? $cm[1] : '';
    $bare = ltrim($chequeNo, '0');
    $cheque = null;
    if ($bare !== '') {
        $cq = $conn->prepare("
            SELECT c.id, c.lease_id, c.cheque_number, c.status, c.cheque_date, c.bounced_date, c.bounced_reason, l.lease_number,
                   COALESCE(NULLIF(t.company_name,''), CONCAT(t.first_name,' ',t.last_name)) AS tenant_name
            FROM re_post_dated_cheques c
            LEFT JOIN re_leases l ON l.id = c.lease_id
            LEFT JOIN re_tenants t ON t.id = l.tenant_id
            WHERE c.company_id = ? AND ABS(c.cheque_amount - ?) < 0.01 AND TRIM(LEADING '0' FROM c.cheque_number) = ?
              AND c.status NOT IN ('cancelled','returned')
            LIMIT 50
        ");
        $cq->execute([$companyId, $amount, $bare]);
        $found = $cq->fetchAll(PDO::FETCH_ASSOC) ?: [];
        // Cheque numbers repeat across tenants: trust the one whose bounce note carries this bank
        // reference, else the only one dated around the bank line.
        $byRef = array_values(array_filter($found, static fn(array $c): bool => str_contains((string) ($c['bounced_reason'] ?? ''), $ref)));
        if (count($byRef) === 1) {
            $cheque = $byRef[0];
        } else {
            $lineTs = (int) strtotime($date);
            $near = array_values(array_filter($found, static function (array $c) use ($lineTs): bool {
                $ts = strtotime((string) ($c['bounced_date'] ?: $c['cheque_date']));
                return $ts && abs($ts - $lineTs) <= 10 * 86400;
            }));
            if (count($near) === 1) {
                $cheque = $near[0];
            }
        }
    }

    $status = $cheque ? (string) $cheque['status'] : '';
    $blocked = $status === 'cleared';
    $warning = '';
    if (!$cheque) {
        $warning = 'No cheque with this number and amount was found in the system. Check the cheque is recorded and marked Bounced.';
    } elseif ($blocked) {
        $warning = 'This cheque is Cleared in the system, so its receipt is in the bank ledger. Mark the cheque Bounced first, then reconcile.';
    } elseif ($status !== 'bounced') {
        $warning = 'This cheque is still ' . ucfirst(str_replace('_', ' ', $status)) . ' in the system. Mark it Bounced on the lease.';
    }

    $side = static fn(array $l): array => [
        'id' => (int) $l['id'],
        'txn_date' => (string) $l['statement_date'],
        'amount' => round(abs((float) $l['net_amount']), 2),
        'description' => (string) $l['description'],
    ];

    return [
        'deposit_line' => $side($deposit),
        'return_line' => $side($return),
        'partner_line_id' => (int) $partner['id'],
        'amount' => $amount,
        'reference' => $ref,
        'cheque_number' => $chequeNo,
        'cheque_id' => $cheque ? (int) $cheque['id'] : 0,
        'lease_id' => $cheque ? (int) $cheque['lease_id'] : 0,
        'lease_number' => $cheque ? (string) ($cheque['lease_number'] ?? '') : '',
        'party_name' => $cheque ? trim((string) ($cheque['tenant_name'] ?? '')) : '',
        'cheque_status' => $status,
        'warning' => $warning,
        'blocked' => $blocked,
    ];
}

/**
 * Close both lines of a bounced cheque against each other. No journal: the deposit and the
 * return net to zero in the bank and the cheque never produced a receipt.
 *
 * @return array{success:bool,match_ids:list<int>,error:?string}
 */
function re_bank_reco_confirm_bounced_pair(PDO $conn, int $companyId, int $lineId, ?int $userId): array
{
    $line = re_bank_get_statement_line($conn, $lineId, $companyId);
    $pair = $line ? re_bank_reco_bounced_pair($conn, $companyId, $line) : null;
    if (!$pair) {
        return ['success' => false, 'match_ids' => [], 'error' => 'This line is no longer an open bounced cheque pair. Press Refresh.'];
    }
    if (!empty($pair['blocked'])) {
        return ['success' => false, 'match_ids' => [], 'error' => (string) $pair['warning']];
    }

    $note = 'Bounced cheque' . ($pair['cheque_number'] !== '' ? ' ' . $pair['cheque_number'] : '')
        . ($pair['lease_number'] !== '' ? ' · Lease ' . $pair['lease_number'] : '')
        . ($pair['cheque_id'] > 0 ? ' · cheque #' . $pair['cheque_id'] : '');
    $bankAccountId = (int) $line['bank_account_id'];
    $ids = [];
    $ownsTxn = !$conn->inTransaction();
    try {
        if ($ownsTxn) {
            $conn->beginTransaction();
        }
        foreach ([[$pair['deposit_line']['id'], $pair['return_line']['id'], 'deposit'], [$pair['return_line']['id'], $pair['deposit_line']['id'], 'return']] as [$own, $other, $word]) {
            // Each row points at the other bank line, which is what ties the pair together for undo.
            $r = re_bank_rec_confirm_match(
                $conn, $companyId, $bankAccountId, (int) $own, 'adjustment', 're_bank_statement_lines', (int) $other, null,
                (float) $pair['amount'], $userId, mb_substr($note . ' · ' . $word . ' paired with line #' . $other, 0, 255, 'UTF-8'),
                100, 'High', 'match'
            );
            if (empty($r['success'])) {
                throw new RuntimeException($r['error'] ?? 'Could not reconcile the pair');
            }
            $ids[] = (int) $r['match_id'];
        }
        if ($ownsTxn) {
            $conn->commit();
        }
        return ['success' => true, 'match_ids' => $ids, 'error' => null];
    } catch (Throwable $e) {
        if ($ownsTxn && $conn->inTransaction()) {
            $conn->rollBack();
        }
        return ['success' => false, 'match_ids' => [], 'error' => $e->getMessage()];
    }
}
