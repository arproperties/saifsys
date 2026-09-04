<?php
/**
 * Phase 8 bank reconciliation helpers.
 *
 * Imported statement lines and confirmed matches are persisted. Suggestions are
 * never auto-confirmed.
 */
declare(strict_types=1);

require_once __DIR__ . '/../accounting/accounting_engine.php';

if (!function_exists('re_bank_rec_money')) {
    function re_bank_rec_money($value): float
    {
        return round((float)$value, 2);
    }
}

if (!function_exists('re_bank_rec_schema_ready')) {
    function re_bank_rec_schema_ready(PDO $conn): bool
    {
        try {
            $conn->query('SELECT 1 FROM re_bank_statement_lines LIMIT 1');
            $conn->query('SELECT 1 FROM re_bank_reconciliation_matches LIMIT 1');
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('re_bank_rec_audit')) {
    function re_bank_rec_audit(PDO $conn, int $companyId, int $bankAccountId, ?int $statementLineId, ?int $matchId, string $action, ?string $oldValue, ?string $newValue, ?int $userId, string $reason = ''): void
    {
        try {
            $stmt = $conn->prepare("
                INSERT INTO re_bank_reconciliation_audit
                    (company_id, bank_account_id, statement_line_id, match_id, action, old_value, new_value, changed_by, reason)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$companyId, $bankAccountId, $statementLineId, $matchId, $action, $oldValue, $newValue, $userId, $reason ?: null]);
        } catch (Throwable $e) {
            error_log('Bank reconciliation audit failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('re_bank_rec_period_locked')) {
    function re_bank_rec_period_locked(PDO $conn, int $companyId, int $bankAccountId, string $date): bool
    {
        try {
            $stmt = $conn->prepare("
                SELECT 1
                FROM re_bank_reconciliation_locks
                WHERE company_id = ? AND bank_account_id = ?
                  AND ? BETWEEN period_start AND period_end
                LIMIT 1
            ");
            $stmt->execute([$companyId, $bankAccountId, $date]);
            if ($stmt->fetchColumn()) {
                return true;
            }
        } catch (Throwable $e) {
            return false;
        }
        return function_exists('is_period_locked') && is_period_locked($companyId, $date);
    }
}

if (!function_exists('re_bank_rec_hash')) {
    function re_bank_rec_hash(int $bankAccountId, string $date, float $amount, string $reference, string $description): string
    {
        return hash('sha256', implode('|', [
            $bankAccountId,
            $date,
            number_format(re_bank_rec_money($amount), 2, '.', ''),
            trim(mb_strtolower($reference ?: '-', 'UTF-8')),
            trim(mb_strtolower($description ?: '-', 'UTF-8')),
        ]));
    }
}

if (!function_exists('re_bank_rec_bank_account')) {
    function re_bank_rec_bank_account(PDO $conn, int $companyId, int $bankAccountId): ?array
    {
        $stmt = $conn->prepare("
            SELECT ba.*, coa.account_code, coa.account_name
            FROM re_bank_accounts ba
            JOIN re_chart_of_accounts coa ON coa.id = ba.gl_account_id
            WHERE ba.id = ? AND ba.company_id = ?
            LIMIT 1
        ");
        $stmt->execute([$bankAccountId, $companyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('re_bank_rec_import_lines')) {
    /**
     * @param list<array<string,mixed>> $rows
     * @return array{success:bool,batch_id:int|null,inserted:int,duplicates:int,balances_updated?:int,error:string|null}
     */
    function re_bank_rec_import_lines(PDO $conn, int $companyId, int $bankAccountId, string $fileName, array $rows, ?int $userId = null, ?string $notes = null): array
    {
        if (empty($rows)) {
            return ['success' => false, 'batch_id' => null, 'inserted' => 0, 'duplicates' => 0, 'error' => 'No statement rows found.'];
        }
        $dates = array_values(array_filter(array_map(static fn($r) => (string)($r['statement_date'] ?? ''), $rows)));
        sort($dates);
        $opening = null;
        $closing = null;
        foreach ($rows as $row) {
            if (isset($row['running_balance']) && $row['running_balance'] !== '') {
                $closing = (float)$row['running_balance'];
                if ($opening === null) {
                    $opening = $closing - (float)($row['net_amount'] ?? 0);
                }
            }
        }
        $conn->beginTransaction();
        try {
            $batch = $conn->prepare("
                INSERT INTO re_bank_import_batches
                    (company_id, bank_account_id, file_name, statement_start_date, statement_end_date,
                     opening_balance, closing_balance, row_count, imported_by, status, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, 'imported', ?)
            ");
            $batch->execute([$companyId, $bankAccountId, $fileName, $dates[0] ?? null, $dates ? end($dates) : null, $opening, $closing, $userId, $notes]);
            $batchId = (int)$conn->lastInsertId();

            $insert = $conn->prepare("
                INSERT IGNORE INTO re_bank_statement_lines
                    (company_id, bank_account_id, import_batch_id, statement_date, value_date, description,
                     reference, debit_amount, credit_amount, net_amount, running_balance, raw_payload_json,
                     source_hash, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'unmatched')
            ");
            $backfillBalance = $conn->prepare("
                UPDATE re_bank_statement_lines
                SET running_balance = ?
                WHERE company_id = ? AND bank_account_id = ? AND source_hash = ?
                  AND running_balance IS NULL
            ");
            $inserted = 0;
            $duplicates = 0;
            $balancesUpdated = 0;
            foreach ($rows as $row) {
                $date = (string)($row['statement_date'] ?? '');
                if ($date === '') {
                    continue;
                }
                if (re_bank_rec_period_locked($conn, $companyId, $bankAccountId, $date)) {
                    throw new RuntimeException('Cannot import statement line inside a locked period: ' . $date);
                }
                $net = re_bank_rec_money($row['net_amount'] ?? 0);
                $debit = re_bank_rec_money($row['debit_amount'] ?? ($net < 0 ? abs($net) : 0));
                $credit = re_bank_rec_money($row['credit_amount'] ?? ($net > 0 ? $net : 0));
                $reference = trim((string)($row['reference'] ?? ''));
                $description = trim((string)($row['description'] ?? ''));
                $hash = re_bank_rec_hash($bankAccountId, $date, $net, $reference, $description);
                $runningBalance = isset($row['running_balance']) && $row['running_balance'] !== ''
                    ? (float) $row['running_balance']
                    : null;
                $insert->execute([
                    $companyId,
                    $bankAccountId,
                    $batchId,
                    $date,
                    !empty($row['value_date']) ? (string)$row['value_date'] : null,
                    $description,
                    $reference ?: null,
                    $debit,
                    $credit,
                    $net,
                    $runningBalance,
                    json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    $hash,
                ]);
                if ($insert->rowCount() > 0) {
                    $inserted++;
                } else {
                    $duplicates++;
                    // Re-import with Balance: fill running_balance on existing lines imported without it.
                    if ($runningBalance !== null) {
                        $backfillBalance->execute([$runningBalance, $companyId, $bankAccountId, $hash]);
                        if ($backfillBalance->rowCount() > 0) {
                            $balancesUpdated++;
                        }
                    }
                }
            }
            $conn->prepare("UPDATE re_bank_import_batches SET row_count = ? WHERE id = ? AND company_id = ?")
                ->execute([$inserted, $batchId, $companyId]);
            $auditMsg = "Batch {$batchId}, inserted {$inserted}, duplicates {$duplicates}";
            if ($balancesUpdated > 0) {
                $auditMsg .= ", balances backfilled {$balancesUpdated}";
            }
            re_bank_rec_audit($conn, $companyId, $bankAccountId, null, null, 'import_batch', null, $auditMsg, $userId, $notes ?: '');
            $conn->commit();
            return [
                'success' => true,
                'batch_id' => $batchId,
                'inserted' => $inserted,
                'duplicates' => $duplicates,
                'balances_updated' => $balancesUpdated,
                'error' => null,
            ];
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            return ['success' => false, 'batch_id' => null, 'inserted' => 0, 'duplicates' => 0, 'error' => $e->getMessage()];
        }
    }
}

if (!function_exists('re_bank_rec_confidence')) {
    function re_bank_rec_confidence(float $score): string
    {
        // High ≥75 after name/date rebalance so amount+strong-name (+ modest date) can reach High.
        if ($score >= 75) {
            return 'High';
        }
        if ($score >= 55) {
            return 'Medium';
        }
        return 'Low';
    }
}

if (!function_exists('re_bank_rec_date_score')) {
    /**
     * Soft date proximity: up to +25 within 14 days (not a hard cliff at day 5).
     *
     * @return array{points:float,days:int,reason:?string}
     */
    function re_bank_rec_date_score(string $statementDate, string $txnDate): array
    {
        $st = strtotime($statementDate);
        $tt = strtotime($txnDate);
        if (!$st || !$tt) {
            return ['points' => 0.0, 'days' => 999, 'reason' => null];
        }
        $days = (int)round(abs($st - $tt) / 86400);
        if ($days > 14) {
            return ['points' => 0.0, 'days' => $days, 'reason' => null];
        }
        // Day 0 = 25, then taper ~1.6/day so day 14 ≈ 3
        $points = max(0.0, 25.0 - ($days * 1.6));
        return [
            'points' => $points,
            'days' => $days,
            'reason' => $days === 0 ? 'Same date' : ('Date +' . $days . ' day' . ($days === 1 ? '' : 's')),
        ];
    }
}

if (!function_exists('re_bank_rec_name_match_score')) {
    /**
     * Full-string or token name match against bank description/reference haystack.
     *
     * @return array{points:float,matched:bool,strong:bool,reason:?string}
     */
    function re_bank_rec_name_match_score(string $haystackLower, string $displayName, array $extraTokens = []): array
    {
        $displayName = trim($displayName);
        if ($displayName === '' || $haystackLower === '') {
            return ['points' => 0.0, 'matched' => false, 'strong' => false, 'reason' => null];
        }
        $full = mb_strtolower($displayName, 'UTF-8');
        if ($full !== '' && str_contains($haystackLower, $full)) {
            return [
                'points' => 25.0,
                'matched' => true,
                'strong' => true,
                'reason' => 'Name match: ' . $displayName,
            ];
        }

        $tokens = [];
        foreach (preg_split('/[\s,.\-\/\\\\]+/u', $full) ?: [] as $t) {
            $t = trim($t);
            if (mb_strlen($t, 'UTF-8') >= 3) {
                $tokens[$t] = true;
            }
        }
        foreach ($extraTokens as $t) {
            $t = mb_strtolower(trim((string)$t), 'UTF-8');
            if (mb_strlen($t, 'UTF-8') >= 3) {
                $tokens[$t] = true;
            }
        }
        $hit = [];
        foreach (array_keys($tokens) as $t) {
            if (str_contains($haystackLower, $t)) {
                $hit[] = $t;
            }
        }
        $n = count($hit);
        if ($n >= 2) {
            return [
                'points' => 25.0,
                'matched' => true,
                'strong' => true,
                'reason' => 'Name match: ' . implode(' + ', array_map('mb_strtoupper', $hit)),
            ];
        }
        if ($n === 1) {
            return [
                'points' => 12.0,
                'matched' => true,
                'strong' => false,
                'reason' => 'Partial name: ' . mb_strtoupper($hit[0]),
            ];
        }
        return ['points' => 0.0, 'matched' => false, 'strong' => false, 'reason' => null];
    }
}

if (!function_exists('re_bank_rec_suggestions')) {
    /**
     * @return list<array<string,mixed>>
     */
    function re_bank_rec_suggestions(PDO $conn, int $companyId, int $statementLineId): array
    {
        $stmt = $conn->prepare("SELECT * FROM re_bank_statement_lines WHERE id = ? AND company_id = ? LIMIT 1");
        $stmt->execute([$statementLineId, $companyId]);
        $line = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$line) {
            return [];
        }
        $bank = re_bank_rec_bank_account($conn, $companyId, (int)$line['bank_account_id']);
        if (!$bank) {
            return [];
        }
        $net = (float)$line['net_amount'];
        $abs = abs($net);
        $date = (string)$line['statement_date'];
        $desc = mb_strtolower((string)$line['description'] . ' ' . (string)$line['reference'], 'UTF-8');
        $suggestions = [];

        $payments = $conn->prepare("
            SELECT p.id, p.amount, p.payment_date, p.cleared_date, p.receipt_number, p.reference_number,
                   p.payment_method, p.accounting_mode, p.receipt_status, l.lease_number,
                   t.first_name, t.last_name, t.company_name,
                   COALESCE(NULLIF(t.company_name,''), CONCAT(t.first_name,' ',t.last_name)) AS tenant_name
            FROM re_payments p
            JOIN re_leases l ON l.id = p.lease_id AND l.company_id = p.company_id
            JOIN re_tenants t ON t.id = l.tenant_id
            WHERE p.company_id = ?
              AND p.amount BETWEEN ? AND ?
              AND NOT EXISTS (
                    SELECT 1 FROM re_bank_reconciliation_matches m
                    WHERE m.company_id = p.company_id AND m.source_table = 're_payments'
                      AND m.source_id = p.id AND m.status = 'confirmed'
              )
            ORDER BY COALESCE(p.cleared_date, p.payment_date) DESC
            LIMIT 50
        ");
        $payments->execute([$companyId, $abs - 1.00, $abs + 1.00]);
        foreach ($payments->fetchAll(PDO::FETCH_ASSOC) ?: [] as $payment) {
            $score = 0.0;
            $reasons = [];
            $nameMatched = false;

            if (abs((float)$payment['amount'] - $abs) < 0.01) {
                $score += 45;
                $reasons[] = 'Exact amount';
            } elseif (abs((float)$payment['amount'] - $abs) < 1.01) {
                $score += 25;
                $reasons[] = 'Amount close';
            }

            $pDate = (string)($payment['cleared_date'] ?: $payment['payment_date']);
            $dateScore = re_bank_rec_date_score($date, $pDate);
            if ($dateScore['points'] > 0) {
                $score += $dateScore['points'];
                if ($dateScore['reason']) {
                    $reasons[] = $dateScore['reason'];
                }
            }

            foreach (['receipt_number', 'reference_number', 'lease_number'] as $field) {
                $value = mb_strtolower((string)($payment[$field] ?? ''), 'UTF-8');
                if ($value !== '' && str_contains($desc, $value)) {
                    $score += 18;
                    $reasons[] = 'Ref match: ' . (string)$payment[$field];
                }
            }

            $party = (string)($payment['tenant_name'] ?? '');
            $nameScore = re_bank_rec_name_match_score($desc, $party, [
                (string)($payment['first_name'] ?? ''),
                (string)($payment['last_name'] ?? ''),
                (string)($payment['company_name'] ?? ''),
            ]);
            if ($nameScore['points'] > 0) {
                $score += $nameScore['points'];
                $nameMatched = true;
                if ($nameScore['reason']) {
                    $reasons[] = $nameScore['reason'];
                }
            }

            if (($payment['accounting_mode'] ?? '') === 'invoice' && ($payment['receipt_status'] ?? '') === 'cleared') {
                $score += 10;
                $reasons[] = 'Cleared invoice receipt';
            }

            $score = min(100.0, $score);
            if ($score >= 35) {
                $refLabel = (string)($payment['receipt_number'] ?: ('#' . $payment['id']));
                $suggestions[] = [
                    'match_type' => ($payment['accounting_mode'] ?? '') === 'invoice' ? 'receipt' : 'payment',
                    'source_table' => 're_payments',
                    'source_id' => (int)$payment['id'],
                    'gl_line_id' => null,
                    'amount' => (float)$payment['amount'],
                    'score' => $score,
                    'confidence' => re_bank_rec_confidence($score),
                    'label' => 'Receipt/Payment ' . $refLabel . ' - ' . $party,
                    'txn_date' => $pDate,
                    'reference' => $refLabel,
                    'party_name' => $party,
                    'reasons' => $reasons,
                    'name_matched' => $nameMatched,
                ];
            }
        }

        try {
            $vendorPayments = $conn->prepare("
                SELECT vp.id, vp.amount, vp.payment_date, vp.reference_number, v.vendor_name
                FROM re_vendor_payments vp
                JOIN re_vendors v ON v.id = vp.vendor_id AND v.company_id = vp.company_id
                WHERE vp.company_id = ?
                  AND vp.amount BETWEEN ? AND ?
                  AND vp.status = 'posted'
                  AND NOT EXISTS (
                        SELECT 1 FROM re_bank_reconciliation_matches m
                        WHERE m.company_id = vp.company_id AND m.source_table = 're_vendor_payments'
                          AND m.source_id = vp.id AND m.status = 'confirmed'
                  )
                ORDER BY vp.payment_date DESC
                LIMIT 50
            ");
            $vendorPayments->execute([$companyId, $abs - 1.00, $abs + 1.00]);
            foreach ($vendorPayments->fetchAll(PDO::FETCH_ASSOC) ?: [] as $vp) {
                $score = 0.0;
                $reasons = [];
                $nameMatched = false;

                if (abs((float)$vp['amount'] - $abs) < 0.01) {
                    $score += 45;
                    $reasons[] = 'Exact amount';
                } elseif (abs((float)$vp['amount'] - $abs) < 1.01) {
                    $score += 25;
                    $reasons[] = 'Amount close';
                }

                $dateScore = re_bank_rec_date_score($date, (string)$vp['payment_date']);
                if ($dateScore['points'] > 0) {
                    $score += $dateScore['points'];
                    if ($dateScore['reason']) {
                        $reasons[] = $dateScore['reason'];
                    }
                }

                $ref = mb_strtolower((string)($vp['reference_number'] ?? ''), 'UTF-8');
                if ($ref !== '' && str_contains($desc, $ref)) {
                    $score += 20;
                    $reasons[] = 'Ref match: ' . (string)$vp['reference_number'];
                }

                $vendorName = (string)($vp['vendor_name'] ?? '');
                $nameScore = re_bank_rec_name_match_score($desc, $vendorName);
                if ($nameScore['points'] > 0) {
                    $score += $nameScore['points'];
                    $nameMatched = true;
                    if ($nameScore['reason']) {
                        $reasons[] = $nameScore['reason'];
                    }
                }

                $score = min(100.0, $score);
                if ($score >= 35) {
                    $refLabel = (string)($vp['reference_number'] ?: ('#' . $vp['id']));
                    $suggestions[] = [
                        'match_type' => 'payment',
                        'source_table' => 're_vendor_payments',
                        'source_id' => (int)$vp['id'],
                        'gl_line_id' => null,
                        'amount' => (float)$vp['amount'],
                        'score' => $score,
                        'confidence' => re_bank_rec_confidence($score),
                        'label' => 'Vendor Payment ' . $refLabel . ' - ' . $vendorName,
                        'txn_date' => (string)$vp['payment_date'],
                        'reference' => $refLabel,
                        'party_name' => $vendorName,
                        'reasons' => $reasons,
                        'name_matched' => $nameMatched,
                    ];
                }
            }
        } catch (Throwable $e) {
            // Vendor payment tables may not exist before Phase 9.5 migration.
        }

        $glAmountColumn = $net >= 0 ? 'gl.debit_amount' : 'gl.credit_amount';
        $gl = $conn->prepare("
            SELECT gl.*, jh.journal_number, jh.description AS journal_description
            FROM re_general_ledger gl
            JOIN re_journal_headers jh ON jh.id = gl.journal_id
            WHERE gl.company_id = ? AND gl.account_id = ? AND gl.is_reconciled = 0
              AND ABS({$glAmountColumn} - ?) < 1.00
            ORDER BY gl.entry_date DESC
            LIMIT 50
        ");
        $gl->execute([$companyId, (int)$bank['gl_account_id'], $abs]);
        foreach ($gl->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $glAmount = max((float)$row['debit_amount'], (float)$row['credit_amount']);
            $score = 0.0;
            $reasons = [];
            if (abs($glAmount - $abs) < 0.01) {
                $score += 45;
                $reasons[] = 'Exact amount';
            } else {
                $score += 25;
                $reasons[] = 'Amount close';
            }
            $dateScore = re_bank_rec_date_score($date, (string)$row['entry_date']);
            if ($dateScore['points'] > 0) {
                $score += $dateScore['points'];
                if ($dateScore['reason']) {
                    $reasons[] = $dateScore['reason'];
                }
            }
            $ref = mb_strtolower((string)($row['reference'] ?? ''), 'UTF-8');
            if ($ref !== '' && str_contains($desc, $ref)) {
                $score += 20;
                $reasons[] = 'Ref match: ' . (string)$row['reference'];
            }
            $score = min(100.0, $score);
            if ($score >= 35) {
                $jDesc = (string)($row['journal_description'] ?: $row['description'] ?: '');
                $suggestions[] = [
                    'match_type' => 'journal_line',
                    'source_table' => 're_general_ledger',
                    'source_id' => (int)$row['id'],
                    'gl_line_id' => (int)$row['id'],
                    'amount' => $glAmount,
                    'score' => $score,
                    'confidence' => re_bank_rec_confidence($score),
                    'label' => 'GL ' . $row['journal_number'] . ' - ' . $jDesc,
                    'txn_date' => (string)$row['entry_date'],
                    'reference' => (string)($row['journal_number'] ?: $row['reference'] ?: ('GL#' . $row['id'])),
                    'party_name' => '',
                    'reasons' => $reasons,
                    'name_matched' => false,
                ];
            }
        }

        // Prefer higher score; within 5 points prefer name-matched candidates (tie-break).
        usort($suggestions, static function ($a, $b) {
            $sa = (float)$a['score'];
            $sb = (float)$b['score'];
            if (abs($sa - $sb) <= 5.0) {
                $na = !empty($a['name_matched']) ? 1 : 0;
                $nb = !empty($b['name_matched']) ? 1 : 0;
                if ($na !== $nb) {
                    return $nb <=> $na;
                }
            }
            if ($sa !== $sb) {
                return $sb <=> $sa;
            }
            return strcmp((string)($b['txn_date'] ?? ''), (string)($a['txn_date'] ?? ''));
        });
        return array_slice($suggestions, 0, 10);
    }
}

if (!function_exists('re_bank_rec_refresh_line_status')) {
    function re_bank_rec_refresh_line_status(PDO $conn, int $companyId, int $statementLineId): void
    {
        $stmt = $conn->prepare("SELECT net_amount, status FROM re_bank_statement_lines WHERE id = ? AND company_id = ?");
        $stmt->execute([$statementLineId, $companyId]);
        $line = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$line || in_array((string)$line['status'], ['ignored','investigating'], true)) {
            return;
        }
        $sum = $conn->prepare("SELECT COALESCE(SUM(matched_amount), 0) FROM re_bank_reconciliation_matches WHERE company_id = ? AND statement_line_id = ? AND status = 'confirmed'");
        $sum->execute([$companyId, $statementLineId]);
        $matched = abs((float)$sum->fetchColumn());
        $target = abs((float)$line['net_amount']);
        $status = 'unmatched';
        if ($matched > 0.005 && $matched + 0.005 < $target) $status = 'partially_matched';
        elseif ($matched >= $target - 0.005 && $target > 0) $status = 'matched';
        $conn->prepare("UPDATE re_bank_statement_lines SET status = ? WHERE id = ? AND company_id = ?")
            ->execute([$status, $statementLineId, $companyId]);
    }
}

if (!function_exists('re_bank_rec_confirm_match')) {
    function re_bank_rec_confirm_match(PDO $conn, int $companyId, int $bankAccountId, int $statementLineId, string $matchType, ?string $sourceTable, ?int $sourceId, ?int $glLineId, float $amount, ?int $userId, string $notes = '', ?float $score = null, ?string $confidence = null, string $matchMethod = 'match', ?int $createdTransactionId = null): array
    {
        $lineStmt = $conn->prepare("SELECT * FROM re_bank_statement_lines WHERE id = ? AND company_id = ? AND bank_account_id = ? LIMIT 1");
        $lineStmt->execute([$statementLineId, $companyId, $bankAccountId]);
        $line = $lineStmt->fetch(PDO::FETCH_ASSOC);
        if (!$line) return ['success' => false, 'error' => 'Statement line not found.'];
        if (re_bank_rec_period_locked($conn, $companyId, $bankAccountId, (string)$line['statement_date'])) {
            return ['success' => false, 'error' => 'This reconciliation period is locked.'];
        }
        if (in_array((string)$line['status'], ['ignored','investigating'], true)) {
            return ['success' => false, 'error' => 'Ignored/investigating lines cannot be matched until reopened.'];
        }
        $amount = re_bank_rec_money($amount);
        if ($amount <= 0) return ['success' => false, 'error' => 'Matched amount must be greater than zero.'];

        $ownsTxn = !$conn->inTransaction();
        if ($ownsTxn) {
            $conn->beginTransaction();
        }
        try {
            if ($glLineId) {
                $bank = re_bank_rec_bank_account($conn, $companyId, $bankAccountId);
                if (!$bank) {
                    throw new RuntimeException('Bank account not found.');
                }
                $glCheck = $conn->prepare("
                    SELECT id
                    FROM re_general_ledger
                    WHERE id = ? AND company_id = ? AND account_id = ?
                    LIMIT 1
                ");
                $glCheck->execute([$glLineId, $companyId, (int) $bank['gl_account_id']]);
                if (!$glCheck->fetchColumn()) {
                    throw new RuntimeException('Selected GL line is not for this bank account.');
                }
                if (function_exists('re_gl_entry_remaining')) {
                    $glRem = re_gl_entry_remaining($conn, $glLineId, $companyId);
                    if ($glRem + 0.01 < $amount) {
                        throw new RuntimeException('Selected GL line has insufficient remaining amount.');
                    }
                }
            }
            $hasMethod = false;
            try {
                $colCheck = $conn->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 're_bank_reconciliation_matches' AND COLUMN_NAME = 'match_method' LIMIT 1");
                $colCheck->execute();
                $hasMethod = (bool) $colCheck->fetchColumn();
            } catch (Throwable $e) {
                $hasMethod = false;
            }
            if ($hasMethod) {
                $stmt = $conn->prepare("
                    INSERT INTO re_bank_reconciliation_matches
                        (company_id, bank_account_id, statement_line_id, match_type, source_table, source_id,
                         gl_line_id, matched_amount, confidence_score, confidence_label, matched_by, matched_at, status, notes, match_method, created_transaction_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'confirmed', ?, ?, ?)
                ");
                $stmt->execute([$companyId, $bankAccountId, $statementLineId, $matchType, $sourceTable, $sourceId, $glLineId, $amount, $score, $confidence, $userId, $notes ?: null, $matchMethod, $createdTransactionId]);
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO re_bank_reconciliation_matches
                        (company_id, bank_account_id, statement_line_id, match_type, source_table, source_id,
                         gl_line_id, matched_amount, confidence_score, confidence_label, matched_by, matched_at, status, notes)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'confirmed', ?)
                ");
                $stmt->execute([$companyId, $bankAccountId, $statementLineId, $matchType, $sourceTable, $sourceId, $glLineId, $amount, $score, $confidence, $userId, $notes ?: null]);
            }
            $matchId = (int)$conn->lastInsertId();
            if ($glLineId && function_exists('re_bank_sync_gl_reconciled_flag')) {
                re_bank_sync_gl_reconciled_flag($conn, $glLineId, $companyId, $userId);
            } elseif ($glLineId) {
                $conn->prepare("UPDATE re_general_ledger SET is_reconciled = 1, reconciled_at = NOW(), reconciled_by = ? WHERE id = ? AND company_id = ?")
                    ->execute([$userId, $glLineId, $companyId]);
            }
            re_bank_rec_refresh_line_status($conn, $companyId, $statementLineId);
            re_bank_rec_audit($conn, $companyId, $bankAccountId, $statementLineId, $matchId, 'confirm_match', null, json_encode(['type' => $matchType, 'source' => $sourceTable, 'id' => $sourceId, 'amount' => $amount]), $userId, $notes);
            if ($ownsTxn) {
                $conn->commit();
            }
            return ['success' => true, 'match_id' => $matchId, 'error' => null];
        } catch (Throwable $e) {
            if ($ownsTxn && $conn->inTransaction()) $conn->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}

if (!function_exists('re_bank_rec_unmatch')) {
    function re_bank_rec_unmatch(PDO $conn, int $companyId, int $matchId, ?int $userId, string $reason): array
    {
        if (trim($reason) === '') return ['success' => false, 'error' => 'Reason is required.'];
        $stmt = $conn->prepare("SELECT * FROM re_bank_reconciliation_matches WHERE id = ? AND company_id = ? AND status = 'confirmed' LIMIT 1");
        $stmt->execute([$matchId, $companyId]);
        $match = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$match) return ['success' => false, 'error' => 'Confirmed match not found.'];
        $lineStmt = $conn->prepare("SELECT statement_date FROM re_bank_statement_lines WHERE id = ? AND company_id = ?");
        $lineStmt->execute([(int)$match['statement_line_id'], $companyId]);
        $date = (string)$lineStmt->fetchColumn();
        if (re_bank_rec_period_locked($conn, $companyId, (int)$match['bank_account_id'], $date)) {
            return ['success' => false, 'error' => 'This reconciliation period is locked.'];
        }
        $conn->beginTransaction();
        try {
            $conn->prepare("UPDATE re_bank_reconciliation_matches SET status = 'void' WHERE id = ? AND company_id = ?")
                ->execute([$matchId, $companyId]);
            if (!empty($match['gl_line_id'])) {
                if (function_exists('re_bank_sync_gl_reconciled_flag')) {
                    re_bank_sync_gl_reconciled_flag($conn, (int) $match['gl_line_id'], $companyId, $userId);
                } else {
                    $conn->prepare("UPDATE re_general_ledger SET is_reconciled = 0, reconciled_at = NULL, reconciled_by = NULL WHERE id = ? AND company_id = ?")
                        ->execute([(int)$match['gl_line_id'], $companyId]);
                }
            }
            re_bank_rec_refresh_line_status($conn, $companyId, (int)$match['statement_line_id']);
            re_bank_rec_audit($conn, $companyId, (int)$match['bank_account_id'], (int)$match['statement_line_id'], $matchId, 'unmatch', 'confirmed', 'void', $userId, $reason);
            $conn->commit();
            return ['success' => true, 'error' => null];
        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}

if (!function_exists('re_bank_rec_set_line_status')) {
    function re_bank_rec_set_line_status(PDO $conn, int $companyId, int $bankAccountId, int $statementLineId, string $status, ?int $userId, string $reason): array
    {
        if (!in_array($status, ['ignored','investigating','unmatched'], true)) return ['success' => false, 'error' => 'Invalid line status.'];
        $stmt = $conn->prepare("SELECT status, statement_date FROM re_bank_statement_lines WHERE id = ? AND company_id = ? AND bank_account_id = ?");
        $stmt->execute([$statementLineId, $companyId, $bankAccountId]);
        $line = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$line) return ['success' => false, 'error' => 'Statement line not found.'];
        if (re_bank_rec_period_locked($conn, $companyId, $bankAccountId, (string)$line['statement_date'])) return ['success' => false, 'error' => 'This reconciliation period is locked.'];
        if (trim($reason) === '' && $status !== 'unmatched') return ['success' => false, 'error' => 'Reason is required.'];
        $conn->prepare("UPDATE re_bank_statement_lines SET status = ? WHERE id = ? AND company_id = ?")
            ->execute([$status, $statementLineId, $companyId]);
        re_bank_rec_audit($conn, $companyId, $bankAccountId, $statementLineId, null, $status, (string)$line['status'], $status, $userId, $reason);
        return ['success' => true, 'error' => null];
    }
}

if (!function_exists('re_bank_rec_create_adjustment')) {
    function re_bank_rec_create_adjustment(PDO $conn, int $companyId, int $bankAccountId, int $statementLineId, int $offsetAccountId, float $amount, string $reason, ?int $userId): array
    {
        $lineStmt = $conn->prepare("SELECT * FROM re_bank_statement_lines WHERE id = ? AND company_id = ? AND bank_account_id = ? LIMIT 1");
        $lineStmt->execute([$statementLineId, $companyId, $bankAccountId]);
        $line = $lineStmt->fetch(PDO::FETCH_ASSOC);
        if (!$line) return ['success' => false, 'error' => 'Statement line not found.'];
        if (re_bank_rec_period_locked($conn, $companyId, $bankAccountId, (string)$line['statement_date'])) return ['success' => false, 'error' => 'This reconciliation period is locked.'];
        if (trim($reason) === '') return ['success' => false, 'error' => 'Adjustment reason is required.'];
        $bank = re_bank_rec_bank_account($conn, $companyId, $bankAccountId);
        if (!$bank) return ['success' => false, 'error' => 'Bank account not found.'];
        $amount = re_bank_rec_money($amount);
        if ($amount <= 0) return ['success' => false, 'error' => 'Adjustment amount must be greater than zero.'];
        $net = (float)$line['net_amount'];
        $desc = 'Bank reconciliation adjustment - ' . $reason;
        if ($net >= 0) {
            $lines = [
                ['account_id' => (int)$bank['gl_account_id'], 'debit' => $amount, 'credit' => 0, 'description' => $desc, 'reference' => $line['reference']],
                ['account_id' => $offsetAccountId, 'debit' => 0, 'credit' => $amount, 'description' => $desc, 'reference' => $line['reference']],
            ];
        } else {
            $lines = [
                ['account_id' => $offsetAccountId, 'debit' => $amount, 'credit' => 0, 'description' => $desc, 'reference' => $line['reference']],
                ['account_id' => (int)$bank['gl_account_id'], 'debit' => 0, 'credit' => $amount, 'description' => $desc, 'reference' => $line['reference']],
            ];
        }
        $journal = create_and_post_journal($companyId, 'adjustment', 'bank_reconciliation', $statementLineId, $lines, $desc, (string)$line['statement_date'], $userId);
        if (empty($journal['success'])) return ['success' => false, 'error' => (string)($journal['error'] ?? 'Could not post adjustment journal.')];
        $glStmt = $conn->prepare("SELECT id FROM re_general_ledger WHERE company_id = ? AND journal_id = ? AND account_id = ? LIMIT 1");
        $glStmt->execute([$companyId, (int)$journal['journal_id'], (int)$bank['gl_account_id']]);
        $glLineId = (int)$glStmt->fetchColumn();
        return re_bank_rec_confirm_match($conn, $companyId, $bankAccountId, $statementLineId, 'adjustment', 're_journal_headers', (int)$journal['journal_id'], $glLineId ?: null, $amount, $userId, $reason, 100, 'High');
    }
}

