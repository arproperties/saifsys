<?php
/**
 * Service Management Phase 7 — manual journal entries on Cleaning GL.
 */

require_once __DIR__ . '/cleaning_accounting_context.php';
require_once __DIR__ . '/gl_posting.php';
require_once __DIR__ . '/work_order_financial_guard.php';

if (!function_exists('sm_jv_table_exists')) {
    function sm_jv_table_exists(PDO $conn): bool
    {
        static $ok = null;
        if ($ok !== null) {
            return $ok;
        }
        $st = $conn->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sm_manual_journals'");
        $st->execute();
        return $ok = ((int)$st->fetchColumn() > 0);
    }
}

if (!function_exists('sm_jv_load')) {
    function sm_jv_load(PDO $conn, int $id): ?array
    {
        if (!sm_jv_table_exists($conn)) {
            return null;
        }
        $st = $conn->prepare('SELECT * FROM sm_manual_journals WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('sm_jv_load_lines')) {
    function sm_jv_load_lines(PDO $conn, int $jvId): array
    {
        $st = $conn->prepare('SELECT * FROM sm_manual_journal_lines WHERE manual_journal_id = ? ORDER BY line_no');
        $st->execute([$jvId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('sm_jv_save_draft')) {
    /**
     * @param array<int, array{account_no:string, description?:string, debit:float, credit:float}> $lines
     * @return array{success:bool, message:string, id?:int}
     */
    function sm_jv_save_draft(PDO $conn, array $header, array $lines, ?int $jvId = null, ?int $userId = null): array
    {
        if (!sm_jv_table_exists($conn)) {
            return ['success' => false, 'message' => 'Phase 7 JV tables not installed. Run sm_apply_phase7_schema.php'];
        }
        if (count($lines) < 2) {
            return ['success' => false, 'message' => 'At least two journal lines are required.'];
        }

        $sumDr = 0.0;
        $sumCr = 0.0;
        foreach ($lines as $line) {
            $sumDr += round((float)($line['debit'] ?? 0), 2);
            $sumCr += round((float)($line['credit'] ?? 0), 2);
        }
        if (abs($sumDr - $sumCr) > 0.02 || $sumDr <= 0) {
            return ['success' => false, 'message' => 'Journal must balance (debits must equal credits).'];
        }

        $userId = $userId ?? (function_exists('current_user_id') ? current_user_id() : null);
        $companyId = cleaning_accounting_company_id($conn);
        $date = $header['journal_date'] ?? date('Y-m-d');
        $memo = trim((string)($header['memo'] ?? ''));

        $conn->beginTransaction();
        try {
            if ($jvId) {
                $existing = sm_jv_load($conn, $jvId);
                if (!$existing || $existing['status'] !== 'draft') {
                    throw new RuntimeException('Only draft journals can be edited.');
                }
                $conn->prepare('UPDATE sm_manual_journals SET journal_date = ?, memo = ? WHERE id = ?')
                    ->execute([$date, $memo ?: null, $jvId]);
                $conn->prepare('DELETE FROM sm_manual_journal_lines WHERE manual_journal_id = ?')->execute([$jvId]);
            } else {
                $conn->prepare("
                    INSERT INTO sm_manual_journals (company_id, journal_date, memo, status, created_by)
                    VALUES (?, ?, ?, 'draft', ?)
                ")->execute([$companyId, $date, $memo ?: null, $userId]);
                $jvId = (int)$conn->lastInsertId();
            }

            $ins = $conn->prepare("
                INSERT INTO sm_manual_journal_lines (manual_journal_id, line_no, account_no, description, debit, credit)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $n = 1;
            foreach ($lines as $line) {
                $acc = trim((string)($line['account_no'] ?? ''));
                if ($acc === '') {
                    continue;
                }
                $ins->execute([
                    $jvId,
                    $n++,
                    $acc,
                    trim((string)($line['description'] ?? '')) ?: null,
                    round((float)($line['debit'] ?? 0), 2),
                    round((float)($line['credit'] ?? 0), 2),
                ]);
            }

            $conn->commit();
            return ['success' => true, 'message' => 'Journal saved as draft.', 'id' => $jvId];
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}

if (!function_exists('sm_jv_post')) {
    function sm_jv_post(PDO $conn, int $jvId, ?int $userId = null): array
    {
        if (!sm_user_can_finalize($conn)) {
            return ['success' => false, 'message' => 'Only Admin or Accountant can post journal entries.'];
        }

        $jv = sm_jv_load($conn, $jvId);
        if (!$jv || $jv['status'] !== 'draft') {
            return ['success' => false, 'message' => 'Draft journal not found.'];
        }

        $lines = sm_jv_load_lines($conn, $jvId);
        if (count($lines) < 2) {
            return ['success' => false, 'message' => 'Journal has insufficient lines.'];
        }

        $glLines = [];
        foreach ($lines as $line) {
            $dr = round((float)$line['debit'], 2);
            $cr = round((float)$line['credit'], 2);
            if ($dr <= 0 && $cr <= 0) {
                continue;
            }
            $glLines[] = [
                'account_id' => coa_id($conn, $line['account_no']),
                'desc' => $line['description'] ?: 'Manual JV',
                'debit' => $dr,
                'credit' => $cr,
            ];
        }

        $userId = $userId ?? (function_exists('current_user_id') ? current_user_id() : null);
        try {
            $jid = gl_create_journal($conn, [
                'date' => $jv['journal_date'],
                'source' => 'manual',
                'source_id' => $jvId,
                'memo' => $jv['memo'] ?: 'Manual journal #' . $jvId,
                'created_by' => $userId,
                'company_id' => (int)$jv['company_id'],
            ], $glLines);

            $conn->prepare("
                UPDATE sm_manual_journals
                SET status = 'posted', gl_journal_id = ?, posted_by = ?, posted_at = NOW()
                WHERE id = ?
            ")->execute([$jid, $userId, $jvId]);

            require_once __DIR__ . '/service_accounting_service.php';
            (new ServiceAccountingService($conn))->invalidateFinancialCache();

            return ['success' => true, 'message' => 'Journal posted to GL.', 'journal_id' => $jid];
        } catch (Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
