<?php
/**
 * Controlled lease financial reset + Invoice Mode conversion tool.
 *
 * Disabled unless ALLOW_LEASE_FINANCIAL_RESET_TOOL is explicitly true.
 */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/branding.php';
require_once __DIR__ . '/../../../includes/company_helper.php';
require_once __DIR__ . '/../../../includes/module_access.php';
require_once __DIR__ . '/../../../includes/rbac_department.php';
require_once __DIR__ . '/../includes/accounting_mode_helper.php';
require_once __DIR__ . '/../includes/obligation_engine.php';
require_once __DIR__ . '/../includes/invoice_engine.php';

require_login();
require_module_access($conn, MODULE_REALESTATE);

$brand = getBrandSettings($conn);
$companyId = current_company_id($conn) ?: 1;
$userId = current_user_id();
$roles = current_user_roles($conn);
$isOwnerAdmin = in_array('Owner', $roles, true) || in_array('Admin', $roles, true);
$toolEnabled = defined('ALLOW_LEASE_FINANCIAL_RESET_TOOL') && ALLOW_LEASE_FINANCIAL_RESET_TOOL === true;
$dbName = (string)$conn->query('SELECT DATABASE()')->fetchColumn();
$action = $_POST['action'] ?? '';
$message = '';
$error = '';
$dryRunRows = [];
$dryRunTotals = [];
$executionRows = [];

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function m($n) { return number_format((float)$n, 2); }

function lfr_table_exists(PDO $conn, string $table): bool {
    static $cache = [];
    if (array_key_exists($table, $cache)) return $cache[$table];
    try {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $stmt->execute([$table]);
        return $cache[$table] = ((int)$stmt->fetchColumn() > 0);
    } catch (Throwable $e) {
        return $cache[$table] = false;
    }
}

function lfr_column_exists(PDO $conn, string $table, string $column): bool {
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $stmt->execute([$table, $column]);
        return $cache[$key] = ((int)$stmt->fetchColumn() > 0);
    } catch (Throwable $e) {
        return $cache[$key] = false;
    }
}

function lfr_in(array $ids): string {
    return implode(',', array_fill(0, count($ids), '?'));
}

function lfr_fetch_ids(PDO $conn, string $sql, array $params): array {
    if (!$params && str_contains($sql, 'IN ()')) return [];
    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        return array_values(array_unique(array_map('intval', array_filter($stmt->fetchAll(PDO::FETCH_COLUMN), static fn($v) => $v !== null && $v !== ''))));
    } catch (Throwable $e) {
        return [];
    }
}

function lfr_count(PDO $conn, string $sql, array $params): int {
    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return -1;
    }
}

function lfr_exec(PDO $conn, string $sql, array $params): int {
    if (str_contains($sql, 'IN ()')) return 0;
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}

function lfr_selected_options(array $post): array {
    $opt = $post['opt'] ?? [];
    return [
        'payments' => !empty($opt['payments']),
        'invoices' => !empty($opt['invoices']),
        'gl' => !empty($opt['gl']),
        'recognition' => !empty($opt['recognition']),
        'bank_matches' => !empty($opt['bank_matches']),
        'tenant_credits' => !empty($opt['tenant_credits']),
        'security_deposit' => !empty($opt['security_deposit']),
        'billing_items' => !empty($opt['billing_items']),
        'reset_operational_schedule' => !empty($opt['reset_operational_schedule']),
        'reset_service_charges' => !empty($opt['reset_service_charges']),
        'full_cheque_reset' => !empty($opt['full_cheque_reset']),
        'reset_cheques' => !empty($opt['reset_cheques']),
        'convert_invoice' => !empty($opt['convert_invoice']),
        'generate_obligations' => !empty($opt['generate_obligations']),
        'prepare_candidates' => !empty($opt['prepare_candidates']),
    ];
}

function lfr_full_rebook_preset_options(): array {
    return [
        'payments' => true,
        'invoices' => true,
        'gl' => true,
        'recognition' => true,
        'bank_matches' => true,
        'tenant_credits' => true,
        'security_deposit' => true,
        'billing_items' => true,
        'reset_operational_schedule' => true,
        'reset_service_charges' => true,
        'full_cheque_reset' => false,
        'reset_cheques' => false,
        'convert_invoice' => false,
        'generate_obligations' => false,
        'prepare_candidates' => false,
    ];
}

function lfr_pdc_reset_set_clauses(PDO $conn, string $table): array {
    $sets = ["status = 'pending'"];
    foreach ([
        'payment_id', 'receipt_id', 'cleared_date', 'deposited_date', 'bounced_date', 'bounced_reason',
        'settlement_method', 'settlement_reference', 'settlement_payment_id',
        'replacement_for_cheque_id', 'replaced_by_cheque_id',
    ] as $column) {
        if (lfr_column_exists($conn, $table, $column)) {
            $sets[] = $column . ' = NULL';
        }
    }
    return $sets;
}

function lfr_load_lease_context(PDO $conn, int $companyId, array $leaseIds): array {
    if (!$leaseIds) return [];
    $ph = lfr_in($leaseIds);
    $stmt = $conn->prepare("
        SELECT l.id, l.lease_number, l.status, l.accounting_mode, l.start_date, l.end_date,
               COALESCE(NULLIF(t.company_name,''), TRIM(CONCAT(COALESCE(t.first_name,''),' ',COALESCE(t.last_name,'')))) AS tenant_name,
               b.name AS building_name, u.unit_number
        FROM re_leases l
        JOIN re_tenants t ON t.id = l.tenant_id
        JOIN re_units u ON u.id = l.unit_id
        JOIN re_buildings b ON b.id = u.building_id
        WHERE l.company_id = ? AND l.id IN ($ph)
        ORDER BY l.id
    ");
    $stmt->execute(array_merge([$companyId], $leaseIds));
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function lfr_related_ids(PDO $conn, int $companyId, array $leaseIds): array {
    $out = [
        'payment_ids' => [],
        'invoice_ids' => [],
        'invoice_item_ids' => [],
        'obligation_ids' => [],
        'candidate_ids' => [],
        'recognition_ids' => [],
        'billing_item_ids' => [],
        'installment_ids' => [],
        'cheque_ids' => [],
        'security_settlement_ids' => [],
        'journal_ids' => [],
        'gl_ids' => [],
        'bank_match_ids' => [],
    ];
    if (!$leaseIds) return $out;
    $ph = lfr_in($leaseIds);
    $leaseParams = array_merge([$companyId], $leaseIds);

    $out['payment_ids'] = lfr_fetch_ids($conn, "SELECT id FROM re_payments WHERE company_id = ? AND lease_id IN ($ph)", $leaseParams);
    $out['invoice_ids'] = lfr_fetch_ids($conn, "SELECT id FROM re_invoices WHERE company_id = ? AND lease_id IN ($ph)", $leaseParams);
    $out['obligation_ids'] = lfr_fetch_ids($conn, "SELECT id FROM re_obligations WHERE company_id = ? AND lease_id IN ($ph)", $leaseParams);
    $out['candidate_ids'] = lfr_fetch_ids($conn, "SELECT id FROM re_invoice_candidates WHERE company_id = ? AND lease_id IN ($ph)", $leaseParams);
    $out['recognition_ids'] = lfr_fetch_ids($conn, "SELECT id FROM re_rent_recognition_schedule WHERE company_id = ? AND lease_id IN ($ph)", $leaseParams);
    $out['billing_item_ids'] = lfr_fetch_ids($conn, "SELECT id FROM re_billing_items WHERE company_id = ? AND lease_id IN ($ph)", $leaseParams);
    $out['installment_ids'] = lfr_fetch_ids($conn, "SELECT id FROM re_lease_installments WHERE company_id = ? AND lease_id IN ($ph)", $leaseParams);
    $out['cheque_ids'] = lfr_fetch_ids($conn, "SELECT id FROM re_post_dated_cheques WHERE company_id = ? AND lease_id IN ($ph)", $leaseParams);
    $out['security_settlement_ids'] = lfr_fetch_ids($conn, "SELECT id FROM re_security_deposit_settlements WHERE company_id = ? AND lease_id IN ($ph)", $leaseParams);

    if ($out['invoice_ids']) {
        $out['invoice_item_ids'] = lfr_fetch_ids($conn, "SELECT id FROM re_invoice_items WHERE invoice_id IN (" . lfr_in($out['invoice_ids']) . ")", $out['invoice_ids']);
    }

    $journalIds = [];
    $journalIds = array_merge($journalIds, lfr_fetch_ids($conn, "SELECT id FROM re_journal_headers WHERE company_id = ? AND reference_type = 'lease' AND reference_id IN ($ph)", $leaseParams));
    if ($out['invoice_ids']) {
        $journalIds = array_merge($journalIds, lfr_fetch_ids($conn, "SELECT id FROM re_journal_headers WHERE company_id = ? AND reference_type = 'invoice' AND reference_id IN (" . lfr_in($out['invoice_ids']) . ")", array_merge([$companyId], $out['invoice_ids'])));
    }
    if ($out['payment_ids']) {
        $journalIds = array_merge($journalIds, lfr_fetch_ids($conn, "SELECT id FROM re_journal_headers WHERE company_id = ? AND reference_type IN ('payment','deferred_payment','deposit','refund','security_deposit_receipt') AND reference_id IN (" . lfr_in($out['payment_ids']) . ")", array_merge([$companyId], $out['payment_ids'])));
    }
    if ($out['recognition_ids']) {
        $journalIds = array_merge($journalIds, lfr_fetch_ids($conn, "SELECT id FROM re_journal_headers WHERE company_id = ? AND reference_type = 'recognition_schedule' AND reference_id IN (" . lfr_in($out['recognition_ids']) . ")", array_merge([$companyId], $out['recognition_ids'])));
    }
    if ($out['security_settlement_ids']) {
        $journalIds = array_merge($journalIds, lfr_fetch_ids($conn, "SELECT id FROM re_journal_headers WHERE company_id = ? AND reference_type = 'security_deposit_settlement' AND reference_id IN (" . lfr_in($out['security_settlement_ids']) . ")", array_merge([$companyId], $out['security_settlement_ids'])));
    }
    $out['journal_ids'] = array_values(array_unique(array_map('intval', $journalIds)));
    if ($out['journal_ids']) {
        $out['gl_ids'] = lfr_fetch_ids($conn, "SELECT id FROM re_general_ledger WHERE company_id = ? AND journal_id IN (" . lfr_in($out['journal_ids']) . ")", array_merge([$companyId], $out['journal_ids']));
    }
    $matchIds = [];
    if ($out['payment_ids']) {
        $matchIds = array_merge($matchIds, lfr_fetch_ids($conn, "SELECT id FROM re_bank_reconciliation_matches WHERE company_id = ? AND source_table = 're_payments' AND source_id IN (" . lfr_in($out['payment_ids']) . ")", array_merge([$companyId], $out['payment_ids'])));
    }
    if ($out['gl_ids']) {
        $matchIds = array_merge($matchIds, lfr_fetch_ids($conn, "SELECT id FROM re_bank_reconciliation_matches WHERE company_id = ? AND gl_line_id IN (" . lfr_in($out['gl_ids']) . ")", array_merge([$companyId], $out['gl_ids'])));
    }
    $out['bank_match_ids'] = array_values(array_unique(array_map('intval', $matchIds)));
    return $out;
}

function lfr_per_lease_summary(PDO $conn, int $companyId, array $leases, array $opts): array {
    $rows = [];
    foreach ($leases as $lease) {
        $leaseId = (int)$lease['id'];
        $ids = lfr_related_ids($conn, $companyId, [$leaseId]);
        $ph = '?';
        $chequeStatuses = [];
        try {
            $st = $conn->prepare("SELECT status, COUNT(*) cnt FROM re_post_dated_cheques WHERE company_id = ? AND lease_id = ? GROUP BY status");
            $st->execute([$companyId, $leaseId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) $chequeStatuses[] = $r['status'] . ':' . $r['cnt'];
        } catch (Throwable $e) {}
        $blockers = [];
        if ($ids['journal_ids']) {
            $locked = lfr_count($conn, "
                SELECT COUNT(*)
                FROM re_journal_headers jh
                JOIN re_fiscal_years fy ON fy.company_id = jh.company_id AND fy.is_closed = 1 AND jh.journal_date BETWEEN fy.start_date AND fy.end_date
                WHERE jh.company_id = ? AND jh.id IN (" . lfr_in($ids['journal_ids']) . ")
            ", array_merge([$companyId], $ids['journal_ids']));
            if ($locked > 0) $blockers[] = 'posted journals in locked fiscal period';
        }
        if ($ids['bank_match_ids'] && !$opts['bank_matches']) $blockers[] = 'bank matches exist but bank reset option not selected';
        if ($ids['bank_match_ids'] && $opts['bank_matches']) {
            $lockedBank = lfr_count($conn, "
                SELECT COUNT(*)
                FROM re_bank_reconciliation_matches m
                JOIN re_bank_statement_lines l ON l.id = m.statement_line_id AND l.company_id = m.company_id
                JOIN re_bank_reconciliation_locks k ON k.company_id = m.company_id
                    AND k.bank_account_id = m.bank_account_id
                    AND l.statement_date BETWEEN k.period_start AND k.period_end
                WHERE m.company_id = ? AND m.id IN (" . lfr_in($ids['bank_match_ids']) . ")
            ", array_merge([$companyId], $ids['bank_match_ids']));
            if ($lockedBank > 0) $blockers[] = 'bank reconciliation match in locked period';
        }
        $legal = lfr_count($conn, "SELECT COUNT(*) FROM re_legal_cases WHERE company_id = ? AND lease_id = ?", [$companyId, $leaseId]);
        $esc = lfr_count($conn, "SELECT COUNT(*) FROM re_legal_cheque_escalations WHERE lease_id = ?", [$leaseId]);
        if ($legal > 0) {
            $blockers[] = 'active legal case exists — resolve or unlink before financial rebook';
        }
        if ($esc > 0 && empty($opts['reset_operational_schedule'])) {
            $blockers[] = 'cheque legal escalation exists — enable "Delete operational schedule" to remove with rebook';
        }
        $moveOut = lfr_count($conn, "SELECT COUNT(*) FROM re_move_outs WHERE company_id = ? AND lease_id = ? AND status IN ('completed','deposit_processing')", [$companyId, $leaseId]);
        if ($moveOut > 0) $blockers[] = 'completed/deposit-processing move-out exists';
        $deposit = lfr_count($conn, "SELECT COUNT(*) FROM re_security_deposit_settlements WHERE company_id = ? AND lease_id = ? AND status IN ('approved','refunded','partially_refunded','deducted')", [$companyId, $leaseId]);
        if ($deposit > 0) $blockers[] = 'approved/processed security deposit settlement exists';
        $rows[] = [
            'lease' => $lease,
            'ids' => $ids,
            'payments' => count($ids['payment_ids']),
            'allocations' => count($ids['payment_ids']) ? lfr_count($conn, "SELECT COUNT(*) FROM re_payment_allocations WHERE payment_id IN (" . lfr_in($ids['payment_ids']) . ")", $ids['payment_ids']) : 0,
            'receipt_allocations' => lfr_count($conn, "SELECT COUNT(*) FROM re_receipt_allocations WHERE company_id = ? AND lease_id = ?", [$companyId, $leaseId]),
            'invoices' => count($ids['invoice_ids']),
            'obligations' => count($ids['obligation_ids']),
            'candidates' => count($ids['candidate_ids']),
            'journals' => count($ids['journal_ids']),
            'recognition' => count($ids['recognition_ids']),
            'bank_matches' => count($ids['bank_match_ids']),
            'tenant_credits' => lfr_count($conn, "SELECT COUNT(*) FROM re_tenant_credit_transactions WHERE company_id = ? AND (payment_id IN (SELECT id FROM re_payments WHERE company_id = ? AND lease_id = ?) OR installment_id IN (SELECT id FROM re_lease_installments WHERE company_id = ? AND lease_id = ?))", [$companyId, $companyId, $leaseId, $companyId, $leaseId]),
            'deposit_postings' => lfr_count($conn, "SELECT COUNT(*) FROM re_security_deposit_audit WHERE company_id = ? AND lease_id = ?", [$companyId, $leaseId]),
            'installments' => count($ids['installment_ids']),
            'schedule_cheques' => count($ids['cheque_ids']),
            'service_charges' => lfr_table_exists($conn, 're_service_charges')
                ? lfr_count($conn, "SELECT COUNT(*) FROM re_service_charges WHERE company_id = ? AND lease_id = ?", [$companyId, $leaseId])
                : 0,
            'cheque_audit' => lfr_table_exists($conn, 're_cheque_lifecycle_audit')
                ? lfr_count($conn, "SELECT COUNT(*) FROM re_cheque_lifecycle_audit WHERE company_id = ? AND lease_id = ?", [$companyId, $leaseId])
                : 0,
            'legal_cases' => $legal,
            'legal_escalations' => $esc,
            'cheque_statuses' => implode(', ', $chequeStatuses) ?: '-',
            'blockers' => $blockers,
            'can_convert' => empty($blockers),
        ];
    }
    return $rows;
}

function lfr_delete_if_ids(PDO $conn, string $sql, array $ids, array $prefix = []): int {
    if (!$ids) return 0;
    return lfr_exec($conn, $sql . " (" . lfr_in($ids) . ")", array_merge($prefix, $ids));
}

function lfr_audit_table(PDO $conn): void {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS re_lease_financial_reset_audit (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_id INT(11) NOT NULL,
            user_id INT(11) DEFAULT NULL,
            database_name VARCHAR(128) NOT NULL,
            lease_ids_json LONGTEXT NOT NULL,
            selected_options_json LONGTEXT DEFAULT NULL,
            dry_run_json LONGTEXT DEFAULT NULL,
            result_json LONGTEXT DEFAULT NULL,
            ip_address VARCHAR(80) DEFAULT NULL,
            session_id VARCHAR(128) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_lfr_company (company_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function lfr_execute(PDO $conn, int $companyId, array $leaseIds, array $opts, ?int $userId): array {
    $ids = lfr_related_ids($conn, $companyId, $leaseIds);
    $results = [];
    $add = function (string $label, int $count) use (&$results): void { $results[] = ['action' => $label, 'affected' => $count]; };

    $conn->beginTransaction();
    try {
        if ($opts['bank_matches'] && $ids['bank_match_ids']) {
            $add('bank reconciliation matches', lfr_delete_if_ids($conn, "DELETE FROM re_bank_reconciliation_matches WHERE id IN", $ids['bank_match_ids']));
        }
        if ($opts['payments']) {
            $add('receipt allocations (lease)', lfr_exec($conn, "DELETE FROM re_receipt_allocations WHERE company_id = ? AND lease_id IN (" . lfr_in($leaseIds) . ")", array_merge([$companyId], $leaseIds)));
            if ($ids['payment_ids']) {
                $add('receipt allocations (payment)', lfr_delete_if_ids($conn, "DELETE FROM re_receipt_allocations WHERE payment_id IN", $ids['payment_ids']));
                $add('payment allocations', lfr_delete_if_ids($conn, "DELETE FROM re_payment_allocations WHERE payment_id IN", $ids['payment_ids']));
                $add('tenant credit transactions', lfr_delete_if_ids($conn, "DELETE FROM re_tenant_credit_transactions WHERE payment_id IN", $ids['payment_ids']));
                $add('payments/receipts', lfr_delete_if_ids($conn, "DELETE FROM re_payments WHERE id IN", $ids['payment_ids']));
            }
            if ($ids['installment_ids']) {
                $add('installment payment allocations', lfr_delete_if_ids($conn, "DELETE FROM re_payment_allocations WHERE installment_id IN", $ids['installment_ids']));
                $add('installment allocation credit rows', lfr_delete_if_ids($conn, "DELETE FROM re_tenant_credit_transactions WHERE installment_id IN", $ids['installment_ids']));
            }
        }
        if ($opts['invoices']) {
            $add('invoice candidates', lfr_exec($conn, "DELETE FROM re_invoice_candidates WHERE company_id = ? AND lease_id IN (" . lfr_in($leaseIds) . ")", array_merge([$companyId], $leaseIds)));
            if ($ids['invoice_ids']) $add('invoice items', lfr_delete_if_ids($conn, "DELETE FROM re_invoice_items WHERE invoice_id IN", $ids['invoice_ids']));
            if ($ids['obligation_ids']) $add('obligations', lfr_delete_if_ids($conn, "DELETE FROM re_obligations WHERE id IN", $ids['obligation_ids']));
            if ($ids['invoice_ids']) $add('invoices', lfr_delete_if_ids($conn, "DELETE FROM re_invoices WHERE id IN", $ids['invoice_ids']));
        }
        if ($opts['billing_items'] && $ids['billing_item_ids']) {
            $add('billing item payment allocations', lfr_delete_if_ids($conn, "DELETE FROM re_billing_item_payment_allocations WHERE billing_item_id IN", $ids['billing_item_ids']));
            $add('billing items / penalties', lfr_delete_if_ids($conn, "DELETE FROM re_billing_items WHERE id IN", $ids['billing_item_ids']));
        }
        if ($opts['recognition']) {
            $add('rent recognition schedule', lfr_exec($conn, "DELETE FROM re_rent_recognition_schedule WHERE company_id = ? AND lease_id IN (" . lfr_in($leaseIds) . ")", array_merge([$companyId], $leaseIds)));
        }
        if ($opts['tenant_credits']) {
            if ($ids['payment_ids']) {
                $add('tenant credit transactions by payment', lfr_delete_if_ids($conn, "DELETE FROM re_tenant_credit_transactions WHERE payment_id IN", $ids['payment_ids']));
            }
            if ($ids['installment_ids']) {
                $add('tenant credit transactions by installment', lfr_delete_if_ids($conn, "DELETE FROM re_tenant_credit_transactions WHERE installment_id IN", $ids['installment_ids']));
            }
            $tenantIds = lfr_fetch_ids($conn, "SELECT DISTINCT tenant_id FROM re_leases WHERE company_id = ? AND id IN (" . lfr_in($leaseIds) . ")", array_merge([$companyId], $leaseIds));
            $recalcCount = 0;
            foreach ($tenantIds as $tenantId) {
                $balStmt = $conn->prepare("
                    SELECT COALESCE(SUM(CASE WHEN type = 'credit' THEN amount_aed ELSE -amount_aed END), 0)
                    FROM re_tenant_credit_transactions
                    WHERE company_id = ? AND tenant_id = ?
                ");
                $balStmt->execute([$companyId, $tenantId]);
                $balance = round((float)$balStmt->fetchColumn(), 2);
                $conn->prepare("
                    INSERT INTO re_tenant_credit_balances (tenant_id, company_id, balance_aed)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE balance_aed = VALUES(balance_aed)
                ")->execute([$tenantId, $companyId, $balance]);
                $recalcCount++;
            }
            $add('tenant credit balances recalculated', $recalcCount);
        }
        if ($opts['security_deposit']) {
            $add('security deposit audit', lfr_exec($conn, "DELETE FROM re_security_deposit_audit WHERE company_id = ? AND lease_id IN (" . lfr_in($leaseIds) . ")", array_merge([$companyId], $leaseIds)));
            $add('security deposit deductions', lfr_exec($conn, "DELETE FROM re_security_deposit_deductions WHERE company_id = ? AND lease_id IN (" . lfr_in($leaseIds) . ")", array_merge([$companyId], $leaseIds)));
            $add('security deposit settlements', lfr_exec($conn, "DELETE FROM re_security_deposit_settlements WHERE company_id = ? AND lease_id IN (" . lfr_in($leaseIds) . ")", array_merge([$companyId], $leaseIds)));
        }
        if ($opts['gl'] && $ids['journal_ids']) {
            if ($ids['gl_ids']) {
                $add('general ledger rows', lfr_delete_if_ids($conn, "DELETE FROM re_general_ledger WHERE id IN", $ids['gl_ids']));
                $add('account ledger entries', lfr_delete_if_ids($conn, "DELETE FROM re_account_ledger_entries WHERE journal_id IN", $ids['journal_ids']));
                $add('accounting postings', lfr_delete_if_ids($conn, "DELETE FROM re_accounting_postings WHERE journal_id IN", $ids['journal_ids']));
            }
            $add('journal lines', lfr_delete_if_ids($conn, "DELETE FROM re_journal_lines WHERE journal_id IN", $ids['journal_ids']));
            $add('journal headers', lfr_delete_if_ids($conn, "DELETE FROM re_journal_headers WHERE id IN", $ids['journal_ids']));
        }
        if ($opts['reset_operational_schedule']) {
            if ($ids['cheque_ids']) {
                if (lfr_table_exists($conn, 're_cheque_lifecycle_audit')) {
                    $add('cheque lifecycle audit', lfr_delete_if_ids($conn, "DELETE FROM re_cheque_lifecycle_audit WHERE company_id = ? AND cheque_id IN", $ids['cheque_ids'], [$companyId]));
                }
                if (lfr_table_exists($conn, 're_cheque_reminder_log')) {
                    $add('cheque reminder log', lfr_delete_if_ids($conn, "DELETE FROM re_cheque_reminder_log WHERE cheque_id IN", $ids['cheque_ids']));
                }
            }
            if (lfr_table_exists($conn, 're_cheque_lifecycle_audit')) {
                $add('cheque lifecycle audit (lease)', lfr_exec($conn, "DELETE FROM re_cheque_lifecycle_audit WHERE company_id = ? AND lease_id IN (" . lfr_in($leaseIds) . ")", array_merge([$companyId], $leaseIds)));
            }
            $add('bounced cheque alerts', lfr_exec($conn, "DELETE FROM re_bounced_cheque_alerts WHERE lease_id IN (" . lfr_in($leaseIds) . ")", $leaseIds));
            $add('legal cheque escalations', lfr_exec($conn, "DELETE FROM re_legal_cheque_escalations WHERE lease_id IN (" . lfr_in($leaseIds) . ")", $leaseIds));
            $add('post-dated cheques / schedule instruments', lfr_exec($conn, "DELETE FROM re_post_dated_cheques WHERE company_id = ? AND lease_id IN (" . lfr_in($leaseIds) . ")", array_merge([$companyId], $leaseIds)));
            if (lfr_table_exists($conn, 're_lease_cheques')) {
                $add('lease cheque mirror rows', lfr_exec($conn, "DELETE FROM re_lease_cheques WHERE company_id = ? AND lease_id IN (" . lfr_in($leaseIds) . ")", array_merge([$companyId], $leaseIds)));
            }
            if ($ids['installment_ids']) {
                $add('installment payment allocations (remaining)', lfr_delete_if_ids($conn, "DELETE FROM re_payment_allocations WHERE installment_id IN", $ids['installment_ids']));
            }
            $add('lease installments / operational schedule', lfr_exec($conn, "DELETE FROM re_lease_installments WHERE company_id = ? AND lease_id IN (" . lfr_in($leaseIds) . ")", array_merge([$companyId], $leaseIds)));
        }
        if ($opts['reset_service_charges'] && lfr_table_exists($conn, 're_service_charges')) {
            $add('service charges', lfr_exec($conn, "DELETE FROM re_service_charges WHERE company_id = ? AND lease_id IN (" . lfr_in($leaseIds) . ")", array_merge([$companyId], $leaseIds)));
        }
        if ($opts['full_cheque_reset']) {
            $pdcSets = lfr_pdc_reset_set_clauses($conn, 're_post_dated_cheques');
            $add('post-dated cheque full status reset', lfr_exec($conn, "UPDATE re_post_dated_cheques SET " . implode(', ', $pdcSets) . " WHERE company_id = ? AND lease_id IN (" . lfr_in($leaseIds) . ")", array_merge([$companyId], $leaseIds)));
            if (lfr_table_exists($conn, 're_lease_cheques')) {
                $lcSets = lfr_pdc_reset_set_clauses($conn, 're_lease_cheques');
                $add('lease cheque full status reset', lfr_exec($conn, "UPDATE re_lease_cheques SET " . implode(', ', $lcSets) . " WHERE company_id = ? AND lease_id IN (" . lfr_in($leaseIds) . ")", array_merge([$companyId], $leaseIds)));
            }
        }
        if ($opts['reset_cheques']) {
            $updateCols = ["status = 'pending'"];
            if (lfr_column_exists($conn, 're_post_dated_cheques', 'payment_id')) $updateCols[] = 'payment_id = NULL';
            if (lfr_column_exists($conn, 're_post_dated_cheques', 'receipt_id')) $updateCols[] = 'receipt_id = NULL';
            if (lfr_column_exists($conn, 're_post_dated_cheques', 'cleared_date')) $updateCols[] = 'cleared_date = NULL';
            $add('post-dated cheque status reset', lfr_exec($conn, "UPDATE re_post_dated_cheques SET " . implode(', ', $updateCols) . " WHERE company_id = ? AND lease_id IN (" . lfr_in($leaseIds) . ") AND status IN ('paid','cleared')", array_merge([$companyId], $leaseIds)));
            $add('lease cheque status reset', lfr_exec($conn, "UPDATE re_lease_cheques SET status = 'pending' WHERE company_id = ? AND lease_id IN (" . lfr_in($leaseIds) . ") AND status IN ('paid','cleared')", array_merge([$companyId], $leaseIds)));
        }
        if ($opts['convert_invoice']) {
            $set = "accounting_mode = 'invoice'";
            if (lfr_column_exists($conn, 're_leases', 'deferred_revenue_mode')) $set .= ", deferred_revenue_mode = 0";
            $add('leases converted to Invoice Mode', lfr_exec($conn, "UPDATE re_leases SET {$set}, updated_at = NOW() WHERE company_id = ? AND id IN (" . lfr_in($leaseIds) . ")", array_merge([$companyId], $leaseIds)));
            if (function_exists('re_accounting_log_mode_change')) {
                foreach ($leaseIds as $leaseId) {
                    re_accounting_log_mode_change($conn, $companyId, (int)$leaseId, null, 'invoice', $userId, 'Lease financial reset conversion to Invoice Mode', 'lease_financial_reset_convert');
                }
            }
        }
        if ($opts['generate_obligations']) {
            foreach ($leaseIds as $leaseId) {
                $res = re_obligation_engine_generate_for_lease($conn, $companyId, (int)$leaseId, $userId);
                if (empty($res['success'])) throw new RuntimeException('Obligation generation failed for lease #' . $leaseId . ': ' . ($res['error'] ?? 'unknown error'));
                $add('obligations generated for lease #' . $leaseId, (int)($res['stats']['created'] ?? 0) + (int)($res['stats']['updated'] ?? 0));
            }
        }
        if ($opts['prepare_candidates']) {
            foreach ($leaseIds as $leaseId) {
                $res = re_invoice_engine_prepare_candidates_for_lease($conn, $companyId, (int)$leaseId, $userId);
                if (empty($res['success'])) throw new RuntimeException('Invoice candidate preparation failed for lease #' . $leaseId . ': ' . ($res['error'] ?? 'unknown error'));
                $add('invoice candidates prepared for lease #' . $leaseId, (int)($res['stats']['created'] ?? 0));
            }
        }
        $conn->commit();
        return $results;
    } catch (Throwable $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        throw $e;
    }
}

$buildingFilter = (int)($_GET['building_id'] ?? $_POST['building_id'] ?? 0);
$tenantFilter = (int)($_GET['tenant_id'] ?? $_POST['tenant_id'] ?? 0);
$statusFilter = (string)($_GET['lease_status'] ?? $_POST['lease_status'] ?? 'all');
$modeFilter = (string)($_GET['accounting_mode'] ?? $_POST['accounting_mode'] ?? 'all');
$contextLeaseId = (int)($_GET['lease_id'] ?? $_POST['lease_id'] ?? 0);
$validStatuses = ['all','draft','active','expired','terminated','renewed'];
if (!in_array($statusFilter, $validStatuses, true)) $statusFilter = 'all';
if (!in_array($modeFilter, ['all','legacy','invoice'], true)) $modeFilter = 'all';

$buildings = $conn->prepare("SELECT id, name FROM re_buildings WHERE company_id = ? ORDER BY name");
$buildings->execute([$companyId]);
$buildings = $buildings->fetchAll(PDO::FETCH_ASSOC) ?: [];
$tenants = $conn->prepare("SELECT id, COALESCE(NULLIF(company_name,''), TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,'')))) tenant_name FROM re_tenants WHERE company_id = ? ORDER BY tenant_name");
$tenants->execute([$companyId]);
$tenants = $tenants->fetchAll(PDO::FETCH_ASSOC) ?: [];

$leaseWhere = ['l.company_id = ?'];
$leaseParams = [$companyId];
if ($buildingFilter > 0) { $leaseWhere[] = 'b.id = ?'; $leaseParams[] = $buildingFilter; }
if ($tenantFilter > 0) { $leaseWhere[] = 'l.tenant_id = ?'; $leaseParams[] = $tenantFilter; }
if ($statusFilter !== 'all') { $leaseWhere[] = 'l.status = ?'; $leaseParams[] = $statusFilter; }
if ($modeFilter !== 'all') { $leaseWhere[] = "COALESCE(l.accounting_mode,'legacy') = ?"; $leaseParams[] = $modeFilter; }
$leaseStmt = $conn->prepare("
    SELECT l.id, l.lease_number, l.status, COALESCE(l.accounting_mode,'legacy') accounting_mode, l.start_date, l.end_date,
           COALESCE(NULLIF(t.company_name,''), TRIM(CONCAT(COALESCE(t.first_name,''),' ',COALESCE(t.last_name,'')))) tenant_name,
           b.name building_name, u.unit_number
    FROM re_leases l
    JOIN re_tenants t ON t.id = l.tenant_id
    JOIN re_units u ON u.id = l.unit_id
    JOIN re_buildings b ON b.id = u.building_id
    WHERE " . implode(' AND ', $leaseWhere) . "
    ORDER BY l.id DESC
    LIMIT 500
");
$leaseStmt->execute($leaseParams);
$leaseOptions = $leaseStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$selectAllFiltered = !empty($_POST['select_all_filtered']);
$selectedLeaseIds = array_values(array_unique(array_map('intval', $_POST['lease_ids'] ?? [])));
if ($contextLeaseId > 0 && !in_array($contextLeaseId, $selectedLeaseIds, true)) {
    $selectedLeaseIds[] = $contextLeaseId;
}
if ($selectAllFiltered && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $selectedLeaseIds = array_values(array_unique(array_map(static fn($row) => (int)$row['id'], $leaseOptions)));
}
$selectedOptions = lfr_selected_options($_POST);
if (!empty($_POST['apply_full_rebook_preset'])) {
    $selectedOptions = lfr_full_rebook_preset_options();
}
$selectedLeases = $selectedLeaseIds ? lfr_load_lease_context($conn, $companyId, $selectedLeaseIds) : [];

$toolEnabled = defined('ALLOW_LEASE_FINANCIAL_RESET_TOOL') && ALLOW_LEASE_FINANCIAL_RESET_TOOL === true;
$roles = current_user_roles($conn);
$isOwnerAdmin = in_array('Owner', $roles, true) || in_array('Admin', $roles, true);
$dbName = (string)$conn->query('SELECT DATABASE()')->fetchColumn();

if (!$isOwnerAdmin) {
    $error = 'Forbidden. Owner/Admin role is required.';
} elseif (!$toolEnabled) {
    $error = 'Tool is disabled. Define ALLOW_LEASE_FINANCIAL_RESET_TOOL as true to enable it.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (!$selectedLeaseIds) {
        $error = 'Select at least one lease.';
    } else {
        $dryRunRows = lfr_per_lease_summary($conn, $companyId, $selectedLeases, $selectedOptions);
        if ($action === 'execute') {
            $hasBlockers = array_reduce($dryRunRows, static fn($carry, $row) => $carry || !empty($row['blockers']), false);
            $confirmed = trim((string)($_POST['confirm_phrase'] ?? '')) === 'RESET LEASE FINANCIALS'
                && !empty($_POST['duplicate_confirmed'])
                && !empty($_POST['dry_run_reviewed']);
            if (!$confirmed) {
                $error = 'Execution blocked. Confirm duplicated/test database, dry-run review, and exact phrase.';
            } elseif ($hasBlockers) {
                $error = 'Execution blocked. Resolve blockers shown in dry-run first.';
            } else {
                try {
                    lfr_audit_table($conn);
                    $executionRows = lfr_execute($conn, $companyId, $selectedLeaseIds, $selectedOptions, $userId);
                    $conn->prepare("
                        INSERT INTO re_lease_financial_reset_audit
                            (company_id, user_id, database_name, lease_ids_json, selected_options_json, dry_run_json, result_json, ip_address, session_id)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ")->execute([
                        $companyId,
                        $userId,
                        $dbName,
                        json_encode($selectedLeaseIds, JSON_UNESCAPED_SLASHES),
                        json_encode($selectedOptions, JSON_UNESCAPED_SLASHES),
                        json_encode($dryRunRows, JSON_UNESCAPED_SLASHES),
                        json_encode($executionRows, JSON_UNESCAPED_SLASHES),
                        $_SERVER['REMOTE_ADDR'] ?? null,
                        session_id(),
                    ]);
                    $message = 'Lease financial rebook completed. Documents and lease terms were kept. '
                        . 'Operational schedule was removed and will NOT regenerate automatically — '
                        . 'data entry must open Edit Lease, review terms/cheque schedule, then Save.';
                    if ($selectedOptions['reset_operational_schedule'] ?? false) {
                        $editLinks = [];
                        foreach (array_slice($selectedLeaseIds, 0, 10) as $resetLeaseId) {
                            $editLinks[] = '<a href="../lease_add.php?id=' . (int)$resetLeaseId . '#installments">Lease #' . (int)$resetLeaseId . '</a>';
                        }
                        if ($editLinks) {
                            $message .= ' Next step: ' . implode(', ', $editLinks);
                            if (count($selectedLeaseIds) > 10) {
                                $message .= ' (+' . (count($selectedLeaseIds) - 10) . ' more)';
                            }
                        }
                    }
                    $dryRunRows = lfr_per_lease_summary($conn, $companyId, $selectedLeases, $selectedOptions);
                } catch (Throwable $e) {
                    $error = 'Execution failed: ' . $e->getMessage();
                }
            }
        }
    }
}

$pageTitle = 'Lease Financial Reset & Convert';
require_once __DIR__ . '/../includes/re_layout_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div class="page-header-label"><i class="bi bi-arrow-repeat"></i> Lease Financial Reset & Convert</div>
    <a href="../leases.php" class="btn btn-outline-secondary">Back to Leases</a>
</div>

<div class="alert alert-danger">
    <strong>Destructive financial rebook.</strong>
    Keeps the lease shell, tenant, unit, building, lease terms, and uploaded documents.
    Can remove payments, invoices, GL, billing items, service charges, and the operational payment/cheque schedule.
    <strong>Schedule is not regenerated automatically</strong> — data entry must open Edit Lease and Save after review.
</div>
<?php if ($contextLeaseId > 0): ?>
<div class="alert alert-info">
    Context lease <strong>#<?= (int)$contextLeaseId ?></strong> is pre-selected below.
    <a href="../lease_view.php?id=<?= (int)$contextLeaseId ?>" class="alert-link">Back to lease view</a>
</div>
<?php endif; ?>
<?php if ($message): ?><div class="alert alert-success"><?= h($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-warning"><?= h($error) ?></div><?php endif; ?>

<div class="card card-round mb-4">
    <div class="card-body">
        <div><strong>Current database:</strong> <code><?= h($dbName) ?></code></div>
        <div><strong>Tool enabled:</strong> <span class="badge bg-<?= $toolEnabled ? 'success' : 'danger' ?>"><?= $toolEnabled ? 'yes' : 'no' ?></span></div>
    </div>
</div>

<form method="get" class="card card-round mb-4">
    <div class="card-header bg-white"><strong>Filter Leases</strong></div>
    <div class="card-body row g-3 align-items-end">
        <div class="col-md-3"><label class="form-label">Building</label><select name="building_id" class="form-select"><option value="0">All</option><?php foreach ($buildings as $b): ?><option value="<?= (int)$b['id'] ?>" <?= $buildingFilter === (int)$b['id'] ? 'selected' : '' ?>><?= h($b['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><label class="form-label">Tenant</label><select name="tenant_id" class="form-select"><option value="0">All</option><?php foreach ($tenants as $t): ?><option value="<?= (int)$t['id'] ?>" <?= $tenantFilter === (int)$t['id'] ? 'selected' : '' ?>><?= h($t['tenant_name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><label class="form-label">Lease Status</label><select name="lease_status" class="form-select"><?php foreach (['all'=>'All','draft'=>'Draft','active'=>'Active','expired'=>'Expired','terminated'=>'Terminated','renewed'=>'Renewed'] as $k=>$v): ?><option value="<?= h($k) ?>" <?= $statusFilter === $k ? 'selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><label class="form-label">Mode</label><select name="accounting_mode" class="form-select"><option value="all" <?= $modeFilter==='all'?'selected':'' ?>>All</option><option value="legacy" <?= $modeFilter==='legacy'?'selected':'' ?>>Legacy</option><option value="invoice" <?= $modeFilter==='invoice'?'selected':'' ?>>Invoice</option></select></div>
        <div class="col-md-2"><button class="btn btn-primary w-100">Filter</button></div>
    </div>
</form>

<form method="post">
    <?php csrf_field(); ?>
    <input type="hidden" name="building_id" value="<?= (int)$buildingFilter ?>">
    <input type="hidden" name="tenant_id" value="<?= (int)$tenantFilter ?>">
    <input type="hidden" name="lease_status" value="<?= h($statusFilter) ?>">
    <input type="hidden" name="accounting_mode" value="<?= h($modeFilter) ?>">
    <?php if ($contextLeaseId > 0): ?>
        <input type="hidden" name="lease_id" value="<?= (int)$contextLeaseId ?>">
    <?php endif; ?>

    <div class="card card-round mb-4">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <strong>Select Leases</strong>
            <span class="badge bg-secondary"><?= count($leaseOptions) ?> lease<?= count($leaseOptions) === 1 ? '' : 's' ?> in current filter</span>
        </div>
        <div class="card-body border-bottom">
            <label class="form-check mb-0">
                <input type="checkbox" class="form-check-input" name="select_all_filtered" value="1" id="selectAllFiltered" <?= $selectAllFiltered ? 'checked' : '' ?>>
                <span class="form-check-label fw-semibold">Select all leases in current filter</span>
            </label>
            <div class="small text-muted mt-1">Use this to dry-run or execute the reset for all leases returned by the filters above.</div>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light"><tr><th><input type="checkbox" id="selectVisibleLeases" title="Select visible rows"></th><th>Lease</th><th>Tenant</th><th>Unit</th><th>Status</th><th>Mode</th><th>Period</th></tr></thead>
                <tbody>
                    <?php foreach ($leaseOptions as $lease): ?>
                        <tr>
                            <td><input type="checkbox" class="lease-select-box" name="lease_ids[]" value="<?= (int)$lease['id'] ?>" <?= in_array((int)$lease['id'], $selectedLeaseIds, true) ? 'checked' : '' ?>></td>
                            <td><?= h($lease['lease_number'] ?: ('#'.$lease['id'])) ?></td>
                            <td><?= h($lease['tenant_name']) ?></td>
                            <td><?= h($lease['building_name'].' - '.$lease['unit_number']) ?></td>
                            <td><?= h($lease['status']) ?></td>
                            <td><span class="badge bg-<?= $lease['accounting_mode'] === 'invoice' ? 'primary' : 'secondary' ?>"><?= h($lease['accounting_mode']) ?></span></td>
                            <td><?= h($lease['start_date'].' to '.$lease['end_date']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$leaseOptions): ?><tr><td colspan="7" class="text-center text-muted py-4">No leases found for the filters.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card card-round mb-4">
        <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
            <strong>Options</strong>
            <button type="button" id="applyFullRebookPreset" class="btn btn-sm btn-outline-primary">
                Apply Full Rebook Preset
            </button>
        </div>
        <div class="card-body">
            <div class="alert alert-light border small mb-3">
                <strong>Full Rebook Preset</strong> selects: payments, invoices, GL, recognition, bank matches,
                tenant credits, security deposit, billing items, delete operational schedule, delete service charges.
                It does <strong>not</strong> regenerate the schedule, obligations, or invoice candidates — those happen when data entry saves Edit Lease.
            </div>
            <div class="row g-3">
            <?php foreach ([
                'payments'=>'Reset old payments / receipt allocations',
                'invoices'=>'Reset invoices / invoice items / obligations / candidates',
                'gl'=>'Reset linked GL / journals',
                'recognition'=>'Reset rent recognition schedule',
                'bank_matches'=>'Reset old bank reconciliation matches',
                'tenant_credits'=>'Reset old tenant credit balances',
                'security_deposit'=>'Reset old security deposit postings',
                'billing_items'=>'Reset old billing items / penalties',
                'reset_operational_schedule'=>'Delete operational schedule (installments + cheques + lifecycle audit)',
                'reset_service_charges'=>'Delete linked service charges',
                'full_cheque_reset'=>'Reset cheque statuses only (keep schedule rows)',
                'reset_cheques'=>'Legacy narrow cheque reset (paid/cleared only)',
                'convert_invoice'=>'Convert to Invoice Mode',
                'generate_obligations'=>'Generate obligations after conversion',
                'prepare_candidates'=>'Prepare invoice candidates after conversion',
            ] as $key=>$label): ?>
                <div class="col-md-4"><label class="form-check"><input class="form-check-input preset-option" type="checkbox" name="opt[<?= h($key) ?>]" value="1" <?= !empty($selectedOptions[$key]) ? 'checked' : '' ?>> <span class="form-check-label"><?= h($label) ?></span></label></div>
            <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="card card-round mb-4">
        <div class="card-body row g-3 align-items-end">
            <div class="col-md-4"><label class="form-check"><input class="form-check-input" type="checkbox" name="duplicate_confirmed" value="1" <?= !empty($_POST['duplicate_confirmed']) ? 'checked' : '' ?>> <span class="form-check-label">I confirm this is the duplicated/test database or approved reset window.</span></label></div>
            <div class="col-md-4"><label class="form-check"><input class="form-check-input" type="checkbox" name="dry_run_reviewed" value="1" <?= !empty($_POST['dry_run_reviewed']) ? 'checked' : '' ?>> <span class="form-check-label">I reviewed the dry-run result.</span></label></div>
            <div class="col-md-4"><label class="form-label">Type confirmation</label><input type="text" name="confirm_phrase" class="form-control" value="<?= h($_POST['confirm_phrase'] ?? '') ?>" placeholder="RESET LEASE FINANCIALS"></div>
            <div class="col-md-12 d-flex gap-2"><button class="btn btn-primary" name="action" value="dry_run" <?= (!$toolEnabled || !$isOwnerAdmin) ? 'disabled' : '' ?>>Dry Run</button><button class="btn btn-danger" name="action" value="execute" <?= (!$toolEnabled || !$isOwnerAdmin) ? 'disabled' : '' ?> onclick="return confirm('Execute financial reset/conversion for selected leases?');">Execute</button></div>
        </div>
    </div>

    <?php if ($dryRunRows): ?>
    <div class="card card-round mb-4">
        <div class="card-header bg-white"><strong>Dry Run Results</strong></div>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light"><tr><th>Lease</th><th>Tenant</th><th>Mode</th><th>Payments</th><th>Alloc</th><th>Invoices</th><th>Sched.</th><th>Cheques</th><th>Services</th><th>Legal</th><th>Journals</th><th>Can Convert</th><th>Warnings / Blockers</th></tr></thead>
                <tbody>
                    <?php foreach ($dryRunRows as $row): $lease = $row['lease']; ?>
                        <tr class="<?= $row['blockers'] ? 'table-danger' : '' ?>">
                            <td><?= h($lease['lease_number'] ?: ('#'.$lease['id'])) ?></td>
                            <td><?= h($lease['tenant_name']) ?></td>
                            <td><?= h($lease['accounting_mode'] ?? 'legacy') ?></td>
                            <td><?= (int)$row['payments'] ?></td>
                            <td><?= (int)$row['allocations'] + (int)$row['receipt_allocations'] ?></td>
                            <td><?= (int)$row['invoices'] ?></td>
                            <td><?= (int)$row['installments'] ?></td>
                            <td><?= (int)$row['schedule_cheques'] ?></td>
                            <td><?= (int)$row['service_charges'] ?></td>
                            <td>
                                <?php if ((int)$row['legal_cases'] > 0): ?>
                                    <?= (int)$row['legal_cases'] ?> case<?= (int)$row['legal_cases'] === 1 ? '' : 's' ?>
                                <?php elseif ((int)$row['legal_escalations'] > 0): ?>
                                    <?= (int)$row['legal_escalations'] ?> esc.
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td><?= (int)$row['journals'] ?></td>
                            <td><?= $row['can_convert'] ? '<span class="badge bg-success">yes</span>' : '<span class="badge bg-danger">no</span>' ?></td>
                            <td><?= $row['blockers'] ? h(implode('; ', $row['blockers'])) : '<span class="text-muted">None</span>' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($executionRows): ?>
    <div class="card card-round mb-4">
        <div class="card-header bg-white"><strong>Execution Summary</strong></div>
        <div class="table-responsive"><table class="table table-sm mb-0"><thead class="table-light"><tr><th>Action</th><th class="text-end">Affected</th></tr></thead><tbody><?php foreach ($executionRows as $row): ?><tr><td><?= h($row['action']) ?></td><td class="text-end"><?= (int)$row['affected'] ?></td></tr><?php endforeach; ?></tbody></table></div>
    </div>
    <?php endif; ?>
</form>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const selectVisible = document.getElementById('selectVisibleLeases');
    const selectAllFiltered = document.getElementById('selectAllFiltered');
    const boxes = Array.from(document.querySelectorAll('.lease-select-box'));

    if (selectVisible) {
        selectVisible.addEventListener('change', function () {
            boxes.forEach(box => box.checked = selectVisible.checked);
            if (selectAllFiltered && selectVisible.checked === false) {
                selectAllFiltered.checked = false;
            }
        });
    }

    if (selectAllFiltered) {
        selectAllFiltered.addEventListener('change', function () {
            if (selectAllFiltered.checked) {
                boxes.forEach(box => box.checked = true);
                if (selectVisible) selectVisible.checked = true;
            }
        });
    }

    const presetKeys = <?= json_encode(array_keys(lfr_full_rebook_preset_options()), JSON_UNESCAPED_SLASHES) ?>;

    document.getElementById('applyFullRebookPreset')?.addEventListener('click', function () {
        document.querySelectorAll('.preset-option').forEach(function (input) {
            const key = input.name.match(/opt\[([^\]]+)\]/)?.[1] || '';
            input.checked = presetKeys.includes(key);
        });
    });
});
</script>

<?php require_once __DIR__ . '/../includes/re_layout_footer.php'; ?>
