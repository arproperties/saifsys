<?php
/**
 * Service Management — live data repair orchestration (Owner-only via UI/CLI).
 *
 * Each step supports dry_run. Execute mode uses one transaction per record.
 */

require_once __DIR__ . '/accounting_health_service.php';
require_once __DIR__ . '/service_health_service.php';
require_once __DIR__ . '/cleaning_accounting_context.php';
require_once __DIR__ . '/ar_helpers.php';
require_once __DIR__ . '/gl_posting.php';
require_once __DIR__ . '/work_order_batch_invoice_service.php';
require_once __DIR__ . '/work_order_financial_guard.php';
require_once __DIR__ . '/service_accounting_service.php';
require_once __DIR__ . '/AuditService.php';

if (!function_exists('sm_repair_empty_result')) {
    function sm_repair_empty_result(string $step, bool $dryRun): array
    {
        return [
            'step' => $step,
            'dry_run' => $dryRun,
            'eligible' => 0,
            'fixed' => 0,
            'skipped' => 0,
            'unchanged' => 0,
            'errors' => [],
            'items' => [],
        ];
    }
}

if (!function_exists('sm_repair_steps')) {
    /** @return array<string, array{key:string, title:string, description:string, order:int}> */
    function sm_repair_steps(): array
    {
        return [
            'allocations' => [
                'key' => 'allocations',
                'title' => 'Refresh invoice allocation status',
                'description' => 'Recompute amount_paid, balance_due and status from receipt_allocations.',
                'order' => 1,
            ],
            'missing_invoice_gl' => [
                'key' => 'missing_invoice_gl',
                'title' => 'Backfill missing invoice GL journals',
                'description' => 'Post GL for active INV- invoices missing journals (BINV excluded).',
                'order' => 2,
            ],
            'expenses_gl' => [
                'key' => 'expenses_gl',
                'title' => 'Post missing expense GL',
                'description' => 'Create GL journals for posted expenses without active journals.',
                'order' => 3,
            ],
            'duplicate_invoice_gl' => [
                'key' => 'duplicate_invoice_gl',
                'title' => 'Fix duplicate invoice journals',
                'description' => 'Keep newest journal per invoice; reverse older duplicates.',
                'order' => 4,
            ],
            'wo_sync' => [
                'key' => 'wo_sync',
                'title' => 'Sync WO totals from invoice',
                'description' => 'Align finalized work order frozen totals to issued invoice (invoice wins).',
                'order' => 5,
            ],
            'clear_cache' => [
                'key' => 'clear_cache',
                'title' => 'Clear financial dashboard cache',
                'description' => 'Invalidate ServiceAccountingService cached metrics.',
                'order' => 6,
            ],
        ];
    }
}

if (!function_exists('sm_repair_log_item')) {
    function sm_repair_log_item(string $step, array $item, bool $dryRun, ?int $userId = null): void
    {
        if ($dryRun) {
            return;
        }
        AuditService::log([
            'action' => 'sm_live_repair',
            'object_type' => 'sm_repair_' . $step,
            'object_id' => $item['object_id'] ?? null,
            'summary' => ($item['label'] ?? '') . ': ' . ($item['status'] ?? '') . ' — ' . ($item['message'] ?? ''),
            'new_data' => $item,
            'success' => ($item['status'] ?? '') !== 'error',
            'error_message' => ($item['status'] ?? '') === 'error' ? ($item['message'] ?? '') : null,
            'user_id' => $userId,
        ]);
    }
}

if (!function_exists('sm_repair_refresh_allocations')) {
    function sm_repair_refresh_allocations(PDO $conn, ?int $companyId = null, bool $dryRun = true, ?int $userId = null): array
    {
        $step = 'allocations';
        $companyId = $companyId ?? cleaning_accounting_company_id($conn);
        $health = accounting_health_collect($conn, $companyId);
        $rows = $health['allocation_status_mismatches']['rows'] ?? [];
        $result = sm_repair_empty_result($step, $dryRun);
        $result['eligible'] = count($rows);

        foreach ($rows as $row) {
            $invoiceId = (int)($row['id'] ?? 0);
            $label = (string)($row['invoice_no'] ?? ('Invoice #' . $invoiceId));
            $item = [
                'object_id' => $invoiceId,
                'label' => $label,
                'status' => 'dry_run',
                'message' => 'Would refresh paid/balance/status from allocations.',
            ];
            if ($dryRun) {
                $result['items'][] = $item;
                continue;
            }
            try {
                $conn->beginTransaction();
                ar_refresh_status_from_allocations($conn, $invoiceId);
                $conn->commit();
                $item['status'] = 'fixed';
                $item['message'] = 'Allocation status refreshed.';
                $result['fixed']++;
            } catch (Throwable $e) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                $item['status'] = 'error';
                $item['message'] = $e->getMessage();
                $result['errors'][] = $label . ': ' . $e->getMessage();
            }
            sm_repair_log_item($step, $item, $dryRun, $userId);
            $result['items'][] = $item;
        }

        return $result;
    }
}

if (!function_exists('sm_repair_missing_invoice_journals')) {
    function sm_repair_missing_invoice_journals(PDO $conn, ?int $companyId = null, bool $dryRun = true, ?int $userId = null): array
    {
        $step = 'missing_invoice_gl';
        $companyId = $companyId ?? cleaning_accounting_company_id($conn);
        $health = accounting_health_collect($conn, $companyId);
        $rows = $health['missing_invoice_journals']['rows'] ?? [];
        $result = sm_repair_empty_result($step, $dryRun);

        foreach ($rows as $row) {
            $invoiceId = (int)($row['id'] ?? 0);
            $label = (string)($row['invoice_no'] ?? ('Invoice #' . $invoiceId));
            $result['eligible']++;

            if (sm_invoice_is_batch_summary($conn, $invoiceId)) {
                $result['skipped']++;
                $result['items'][] = [
                    'object_id' => $invoiceId,
                    'label' => $label,
                    'status' => 'skipped',
                    'message' => 'BINV batch summary — no per-invoice GL expected.',
                ];
                continue;
            }

            $item = [
                'object_id' => $invoiceId,
                'label' => $label,
                'status' => 'dry_run',
                'message' => 'Would post/repost invoice to GL.',
            ];
            if ($dryRun) {
                $result['items'][] = $item;
                continue;
            }
            try {
                $conn->beginTransaction();
                ar_post_or_repost_invoice($conn, $invoiceId);
                $conn->commit();
                $item['status'] = 'fixed';
                $item['message'] = 'Invoice posted to GL.';
                $result['fixed']++;
            } catch (Throwable $e) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                $item['status'] = 'error';
                $item['message'] = $e->getMessage();
                $result['errors'][] = $label . ': ' . $e->getMessage();
            }
            sm_repair_log_item($step, $item, $dryRun, $userId);
            $result['items'][] = $item;
        }

        return $result;
    }
}

if (!function_exists('sm_repair_expenses_missing_gl')) {
    function sm_repair_expenses_missing_gl(PDO $conn, ?int $companyId = null, bool $dryRun = true, ?int $userId = null): array
    {
        $step = 'expenses_gl';
        $companyId = $companyId ?? cleaning_accounting_company_id($conn);
        $health = service_health_collect($conn, $companyId);
        $rows = $health['expenses_missing_gl']['rows'] ?? [];
        $result = sm_repair_empty_result($step, $dryRun);
        $result['eligible'] = count($rows);

        foreach ($rows as $row) {
            $expenseId = (int)($row['id'] ?? 0);
            $label = (string)($row['reference_no'] ?? ('Expense #' . $expenseId));
            $item = [
                'object_id' => $expenseId,
                'label' => $label,
                'status' => 'dry_run',
                'message' => 'Would post expense to GL.',
            ];
            if ($dryRun) {
                $result['items'][] = $item;
                continue;
            }
            try {
                $conn->beginTransaction();
                gl_post_expense($conn, $expenseId);
                $conn->commit();
                $item['status'] = 'fixed';
                $item['message'] = 'Expense posted to GL.';
                $result['fixed']++;
            } catch (Throwable $e) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                $item['status'] = 'error';
                $item['message'] = $e->getMessage();
                $result['errors'][] = $label . ': ' . $e->getMessage();
            }
            sm_repair_log_item($step, $item, $dryRun, $userId);
            $result['items'][] = $item;
        }

        return $result;
    }
}

if (!function_exists('sm_repair_find_duplicate_invoice_journals')) {
    /** @return list<array<string,mixed>> */
    function sm_repair_find_duplicate_invoice_journals(PDO $conn): array
    {
        $st = $conn->query("
            SELECT j.source_id AS invoice_id,
                   COUNT(*) AS journal_count,
                   GROUP_CONCAT(j.id ORDER BY j.id DESC SEPARATOR ',') AS journal_ids,
                   MAX(i.invoice_no) AS invoice_no
            FROM gl_journals j
            LEFT JOIN invoices i ON i.id = j.source_id
            WHERE j.source = 'invoice'
              AND j.is_posted = 1
              AND j.is_reversed = 0
            GROUP BY j.source_id
            HAVING journal_count > 1
            ORDER BY j.source_id
        ");
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('sm_repair_fix_duplicate_invoice_for_id')) {
    function sm_repair_fix_duplicate_invoice_for_id(PDO $conn, int $invoiceId, array $journalIds, bool $dryRun): array
    {
        $keepJournalId = max($journalIds);
        $reverseJournalIds = array_values(array_filter($journalIds, static fn($id) => $id !== $keepJournalId));
        $reversed = 0;

        if ($dryRun) {
            return [
                'keep_journal_id' => $keepJournalId,
                'reverse_journal_ids' => $reverseJournalIds,
                'reversed' => count($reverseJournalIds),
            ];
        }

        foreach ($reverseJournalIds as $jid) {
            $check = $conn->prepare('SELECT is_reversed FROM gl_journals WHERE id = ?');
            $check->execute([$jid]);
            $journal = $check->fetch(PDO::FETCH_ASSOC);
            if (!$journal || (int)$journal['is_reversed'] === 1) {
                continue;
            }
            $revCheck = $conn->prepare("SELECT COUNT(*) FROM gl_journals WHERE source='reversal' AND source_id=? AND is_posted=1");
            $revCheck->execute([$jid]);
            if ((int)$revCheck->fetchColumn() === 0) {
                gl_reverse_journal($conn, $jid);
            } else {
                $conn->prepare('UPDATE gl_journals SET is_reversed=1 WHERE id=?')->execute([$jid]);
            }
            $reversed++;
        }

        $inv = $conn->prepare('SELECT id FROM invoices WHERE id = ?');
        $inv->execute([$invoiceId]);
        if ($inv->fetch()) {
            $conn->prepare('UPDATE invoices SET gl_journal_id=? WHERE id=?')->execute([$keepJournalId, $invoiceId]);
        }

        return [
            'keep_journal_id' => $keepJournalId,
            'reverse_journal_ids' => $reverseJournalIds,
            'reversed' => $reversed,
        ];
    }
}

if (!function_exists('sm_repair_duplicate_invoice_journals')) {
    function sm_repair_duplicate_invoice_journals(PDO $conn, ?int $companyId = null, bool $dryRun = true, ?int $userId = null): array
    {
        $step = 'duplicate_invoice_gl';
        $rows = sm_repair_find_duplicate_invoice_journals($conn);
        $result = sm_repair_empty_result($step, $dryRun);
        $result['eligible'] = count($rows);

        foreach ($rows as $dup) {
            $invoiceId = (int)$dup['invoice_id'];
            $journalIds = array_map('intval', explode(',', (string)$dup['journal_ids']));
            $label = (string)($dup['invoice_no'] ?: ('Invoice #' . $invoiceId));
            $item = [
                'object_id' => $invoiceId,
                'label' => $label,
                'status' => 'dry_run',
                'message' => 'Would keep journal #' . max($journalIds) . ' and reverse ' . (count($journalIds) - 1) . ' duplicate(s).',
            ];
            if ($dryRun) {
                $result['items'][] = $item;
                continue;
            }
            try {
                $conn->beginTransaction();
                $fix = sm_repair_fix_duplicate_invoice_for_id($conn, $invoiceId, $journalIds, false);
                $conn->commit();
                $item['status'] = 'fixed';
                $item['message'] = 'Kept journal #' . $fix['keep_journal_id'] . ', reversed ' . $fix['reversed'] . ' duplicate(s).';
                $result['fixed']++;
            } catch (Throwable $e) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                $item['status'] = 'error';
                $item['message'] = $e->getMessage();
                $result['errors'][] = $label . ': ' . $e->getMessage();
            }
            sm_repair_log_item($step, $item, $dryRun, $userId);
            $result['items'][] = $item;
        }

        return $result;
    }
}

if (!function_exists('sm_repair_wo_sync_candidates')) {
    /** @return list<array<string,mixed>> */
    function sm_repair_wo_sync_candidates(PDO $conn, ?int $companyId = null): array
    {
        $companyId = $companyId ?? cleaning_accounting_company_id($conn);
        $moCompany = '';
        $params = [];
        if (gl_column_exists($conn, 'make_order', 'company_id')) {
            $moCompany = ' AND mo.company_id = ?';
            $params[] = $companyId;
        }

        $finalizedSql = wo_column_exists($conn, 'is_finalized')
            ? ' AND COALESCE(mo.is_finalized, 0) = 1'
            : '';

        $st = $conn->prepare("
            SELECT mo.id AS order_id,
                   i.id AS invoice_id,
                   i.invoice_no,
                   COALESCE(mo.grand_total, mo.total) AS wo_total,
                   i.total AS invoice_total
            FROM make_order mo
            INNER JOIN invoices i ON i.order_id = mo.id AND i.status NOT IN ('void', 'draft')
            WHERE 1=1 {$finalizedSql} {$moCompany}
            ORDER BY mo.id DESC
        ");
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('sm_repair_sync_wo_from_invoice')) {
    function sm_repair_sync_wo_from_invoice(PDO $conn, ?int $companyId = null, bool $dryRun = true, ?int $userId = null): array
    {
        $step = 'wo_sync';
        require_once __DIR__ . '/work_order_adjustment_service.php';
        $rows = sm_repair_wo_sync_candidates($conn, $companyId);
        $result = sm_repair_empty_result($step, $dryRun);
        $result['eligible'] = count($rows);

        foreach ($rows as $row) {
            $orderId = (int)($row['order_id'] ?? 0);
            $label = 'WO #' . $orderId . ' / ' . (string)($row['invoice_no'] ?? '');
            $woTotal = round((float)($row['wo_total'] ?? 0), 2);
            $invTotal = round((float)($row['invoice_total'] ?? 0), 2);

            if (sm_pending_adjustment_for_order($conn, $orderId)) {
                $result['skipped']++;
                $result['items'][] = [
                    'object_id' => $orderId,
                    'label' => $label,
                    'status' => 'skipped',
                    'message' => 'Pending adjustment request — resolve first.',
                ];
                continue;
            }

            if (abs($woTotal - $invTotal) < 0.02) {
                $result['unchanged']++;
                if ($dryRun) {
                    $result['items'][] = [
                        'object_id' => $orderId,
                        'label' => $label,
                        'status' => 'unchanged',
                        'message' => 'Totals already match (AED ' . number_format($invTotal, 2) . ').',
                    ];
                }
                continue;
            }

            $item = [
                'object_id' => $orderId,
                'label' => $label,
                'status' => 'dry_run',
                'message' => 'Would sync WO from AED ' . number_format($woTotal, 2) . ' to AED ' . number_format($invTotal, 2) . '.',
            ];
            if ($dryRun) {
                $result['items'][] = $item;
                continue;
            }

            try {
                $conn->beginTransaction();
                $sync = wo_sync_finalized_order_from_invoice($conn, $orderId, $userId);
                if (!$sync['success']) {
                    throw new RuntimeException($sync['message'] ?? 'Sync failed.');
                }
                $conn->commit();
                if (!empty($sync['changed'])) {
                    $item['status'] = 'fixed';
                    $item['message'] = $sync['message'] ?? 'Synced.';
                    $result['fixed']++;
                } else {
                    $item['status'] = 'unchanged';
                    $item['message'] = $sync['message'] ?? 'Already matched.';
                    $result['unchanged']++;
                }
            } catch (Throwable $e) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                $item['status'] = 'error';
                $item['message'] = $e->getMessage();
                $result['errors'][] = $label . ': ' . $e->getMessage();
            }
            sm_repair_log_item($step, $item, $dryRun, $userId);
            $result['items'][] = $item;
        }

        return $result;
    }
}

if (!function_exists('sm_repair_clear_financial_cache')) {
    function sm_repair_clear_financial_cache(PDO $conn, ?int $companyId = null, bool $dryRun = true, ?int $userId = null): array
    {
        $step = 'clear_cache';
        $companyId = $companyId ?? cleaning_accounting_company_id($conn);
        $result = sm_repair_empty_result($step, $dryRun);
        $result['eligible'] = 1;
        $item = [
            'object_id' => $companyId,
            'label' => 'Financial cache',
            'status' => 'dry_run',
            'message' => 'Would invalidate ServiceAccountingService cache.',
        ];
        if ($dryRun) {
            $result['items'][] = $item;
            return $result;
        }
        try {
            $svc = new ServiceAccountingService($conn, $companyId);
            $svc->invalidateFinancialCache();
            $item['status'] = 'fixed';
            $item['message'] = 'Cache cleared.';
            $result['fixed'] = 1;
        } catch (Throwable $e) {
            $item['status'] = 'error';
            $item['message'] = $e->getMessage();
            $result['errors'][] = $e->getMessage();
        }
        sm_repair_log_item($step, $item, $dryRun, $userId);
        $result['items'][] = $item;
        return $result;
    }
}

if (!function_exists('sm_repair_preview_all')) {
    /** Dry-run counts for all steps (for dashboard cards). */
    function sm_repair_preview_all(PDO $conn, ?int $companyId = null): array
    {
        $companyId = $companyId ?? cleaning_accounting_company_id($conn);
        $previews = [];
        foreach (sm_repair_steps() as $key => $meta) {
            $previews[$key] = array_merge($meta, sm_repair_run_step($conn, $key, $companyId, true, null));
        }
        return $previews;
    }
}

if (!function_exists('sm_repair_run_step')) {
    function sm_repair_run_step(PDO $conn, string $step, ?int $companyId = null, bool $dryRun = true, ?int $userId = null): array
    {
        switch ($step) {
            case 'allocations':
                return sm_repair_refresh_allocations($conn, $companyId, $dryRun, $userId);
            case 'missing_invoice_gl':
                return sm_repair_missing_invoice_journals($conn, $companyId, $dryRun, $userId);
            case 'expenses_gl':
                return sm_repair_expenses_missing_gl($conn, $companyId, $dryRun, $userId);
            case 'duplicate_invoice_gl':
                return sm_repair_duplicate_invoice_journals($conn, $companyId, $dryRun, $userId);
            case 'wo_sync':
                return sm_repair_sync_wo_from_invoice($conn, $companyId, $dryRun, $userId);
            case 'clear_cache':
                return sm_repair_clear_financial_cache($conn, $companyId, $dryRun, $userId);
            default:
                throw new InvalidArgumentException('Unknown repair step: ' . $step);
        }
    }
}

if (!function_exists('sm_repair_run_all')) {
    /** Run ordered steps; returns map of step => result. */
    function sm_repair_run_all(PDO $conn, ?int $companyId = null, bool $dryRun = true, ?int $userId = null): array
    {
        $steps = sm_repair_steps();
        uasort($steps, static fn($a, $b) => $a['order'] <=> $b['order']);
        $out = [];
        foreach ($steps as $key => $_meta) {
            $out[$key] = sm_repair_run_step($conn, $key, $companyId, $dryRun, $userId);
        }
        return $out;
    }
}
