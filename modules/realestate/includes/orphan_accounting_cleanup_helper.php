<?php
/**
 * Orphan accounting cleanup helper.
 *
 * Removes legacy GL/journal rows whose source documents no longer exist,
 * while protecting journals linked to live payments, invoices, and receipts.
 */

if (!function_exists('oac_table_exists')) {
    function oac_table_exists(PDO $conn, string $table): bool
    {
        static $cache = [];
        if (array_key_exists($table, $cache)) {
            return $cache[$table];
        }
        try {
            $stmt = $conn->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
            $stmt->execute([$table]);
            return $cache[$table] = ((int)$stmt->fetchColumn() > 0);
        } catch (Throwable $e) {
            return $cache[$table] = false;
        }
    }
}

if (!function_exists('oac_default_options')) {
    function oac_default_options(): array
    {
        return [
            'orphan_lease_gl' => true,
            'orphan_recognition_rows' => true,
            'orphan_billing_items' => true,
            'orphan_tenant_credits' => true,
            'clear_payroll' => false,
            'clear_vendor_ap' => false,
            'clear_expenses' => false,
            'clear_cash_payment_journals' => false,
        ];
    }
}

if (!function_exists('oac_options_from_post')) {
    function oac_options_from_post(array $post): array
    {
        $opt = $post['opt'] ?? [];
        $defaults = oac_default_options();
        $out = [];
        foreach ($defaults as $key => $default) {
            $out[$key] = !empty($opt[$key]);
        }
        return $out;
    }
}

if (!function_exists('oac_in')) {
    function oac_in(array $ids): string
    {
        return implode(',', array_fill(0, count($ids), '?'));
    }
}

if (!function_exists('oac_fetch_ids')) {
    function oac_fetch_ids(PDO $conn, string $sql, array $params): array
    {
        if (str_contains($sql, 'IN ()')) {
            return [];
        }
        try {
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            return array_values(array_unique(array_map('intval', array_filter(
                $stmt->fetchAll(PDO::FETCH_COLUMN),
                static fn($v) => $v !== null && $v !== ''
            ))));
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('oac_count')) {
    function oac_count(PDO $conn, string $sql, array $params): int
    {
        try {
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            return (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return -1;
        }
    }
}

if (!function_exists('oac_audit_table')) {
    function oac_audit_table(PDO $conn): void
    {
        $conn->exec("
            CREATE TABLE IF NOT EXISTS re_orphan_accounting_cleanup_audit (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                company_id INT(11) NOT NULL,
                user_id INT(11) DEFAULT NULL,
                database_name VARCHAR(128) NOT NULL,
                selected_options_json LONGTEXT DEFAULT NULL,
                preview_json LONGTEXT DEFAULT NULL,
                result_json LONGTEXT DEFAULT NULL,
                ip_address VARCHAR(80) DEFAULT NULL,
                session_id VARCHAR(128) DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_oac_company (company_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
}

if (!function_exists('oac_collect_orphan_journal_ids')) {
    /**
     * @return array<int,int>
     */
    function oac_collect_orphan_journal_ids(PDO $conn, int $companyId, array $opts): array
    {
        if (
            empty($opts['orphan_lease_gl'])
            && empty($opts['clear_payroll'])
            && empty($opts['clear_vendor_ap'])
            && empty($opts['clear_expenses'])
            && empty($opts['clear_cash_payment_journals'])
        ) {
            return [];
        }

        $ids = [];

        if (!empty($opts['orphan_lease_gl'])) {
            $queries = [
                "
                SELECT j.id
                FROM re_journal_headers j
                WHERE j.company_id = ?
                  AND j.reference_type IN ('payment','deferred_payment','deposit','refund','security_deposit_receipt')
                  AND NOT EXISTS (
                      SELECT 1 FROM re_payments p
                      WHERE p.id = j.reference_id AND p.company_id = j.company_id
                  )
                ",
                "
                SELECT j.id
                FROM re_journal_headers j
                WHERE j.company_id = ?
                  AND j.reference_type = 'invoice'
                  AND NOT EXISTS (
                      SELECT 1 FROM re_invoices i
                      WHERE i.id = j.reference_id AND i.company_id = j.company_id
                  )
                ",
                "
                SELECT j.id
                FROM re_journal_headers j
                WHERE j.company_id = ?
                  AND j.reference_type = 'lease'
                  AND NOT EXISTS (
                      SELECT 1 FROM re_leases l
                      WHERE l.id = j.reference_id AND l.company_id = j.company_id
                  )
                ",
                "
                SELECT j.id
                FROM re_journal_headers j
                WHERE j.company_id = ?
                  AND j.reference_type = 'lease'
                  AND j.journal_type = 'deposit'
                  AND EXISTS (
                      SELECT 1 FROM re_leases l
                      WHERE l.id = j.reference_id AND l.company_id = j.company_id
                  )
                  AND NOT EXISTS (
                      SELECT 1 FROM re_payments p
                      WHERE p.lease_id = j.reference_id AND p.company_id = j.company_id
                  )
                  AND NOT EXISTS (
                      SELECT 1 FROM re_security_deposit_settlements s
                      WHERE s.lease_id = j.reference_id AND s.company_id = j.company_id
                  )
                ",
                "
                SELECT j.id
                FROM re_journal_headers j
                WHERE j.company_id = ?
                  AND j.reference_type = 'recognition_schedule'
                  AND (
                      NOT EXISTS (
                          SELECT 1 FROM re_rent_recognition_schedule r
                          WHERE r.id = j.reference_id AND r.company_id = j.company_id
                      )
                      OR NOT EXISTS (
                          SELECT 1
                          FROM re_rent_recognition_schedule r
                          JOIN re_leases l ON l.id = r.lease_id AND l.company_id = r.company_id
                          WHERE r.id = j.reference_id AND r.company_id = j.company_id
                      )
                      OR EXISTS (
                          SELECT 1
                          FROM re_rent_recognition_schedule r
                          WHERE r.id = j.reference_id
                            AND r.company_id = j.company_id
                            AND NOT EXISTS (
                                SELECT 1 FROM re_lease_installments i
                                WHERE i.id = r.installment_id AND i.company_id = r.company_id
                            )
                      )
                  )
                ",
                "
                SELECT j.id
                FROM re_journal_headers j
                WHERE j.company_id = ?
                  AND j.reference_type = 'tenant_credit'
                  AND NOT EXISTS (
                      SELECT 1 FROM re_tenant_credit_transactions t
                      WHERE t.id = j.reference_id AND t.company_id = j.company_id
                  )
                ",
                "
                SELECT j.id
                FROM re_journal_headers j
                WHERE j.company_id = ?
                  AND j.reference_type = 'security_deposit_settlement'
                  AND NOT EXISTS (
                      SELECT 1 FROM re_security_deposit_settlements s
                      WHERE s.id = j.reference_id AND s.company_id = j.company_id
                  )
                ",
            ];

            if (oac_table_exists($conn, 're_cash_payment_requests')) {
                $queries[] = "
                    SELECT j.id
                    FROM re_journal_headers j
                    WHERE j.company_id = ?
                      AND j.reference_type = 'cash_payment_request'
                      AND NOT EXISTS (
                          SELECT 1 FROM re_cash_payment_requests r
                          WHERE r.id = j.reference_id AND r.company_id = j.company_id
                      )
                ";
            }

            foreach ($queries as $sql) {
                $ids = array_merge($ids, oac_fetch_ids($conn, $sql, [$companyId]));
            }
        }

        if (!empty($opts['clear_payroll'])) {
            $ids = array_merge($ids, oac_fetch_ids(
                $conn,
                "SELECT id FROM re_journal_headers WHERE company_id = ? AND reference_type = 'payroll'",
                [$companyId]
            ));
        }

        if (!empty($opts['clear_vendor_ap'])) {
            $ids = array_merge($ids, oac_fetch_ids(
                $conn,
                "SELECT id FROM re_journal_headers WHERE company_id = ? AND reference_type IN ('vendor_invoice','vendor_payment')",
                [$companyId]
            ));
        }

        if (!empty($opts['clear_expenses'])) {
            if (oac_table_exists($conn, 'erp_expense_headers')) {
                $ids = array_merge($ids, oac_fetch_ids(
                    $conn,
                    "
                    SELECT j.id
                    FROM re_journal_headers j
                    WHERE j.company_id = ?
                      AND j.reference_type = 'expense'
                      AND EXISTS (
                          SELECT 1 FROM erp_expense_headers eh
                          WHERE eh.id = j.reference_id
                            AND eh.company_id = j.company_id
                            AND eh.source_module = 'realestate'
                      )
                    ",
                    [$companyId]
                ));
            }
        }

        if (!empty($opts['clear_cash_payment_journals'])) {
            $ids = array_merge($ids, oac_fetch_ids(
                $conn,
                "SELECT id FROM re_journal_headers WHERE company_id = ? AND reference_type = 'cash_payment_request'",
                [$companyId]
            ));
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }
}

if (!function_exists('oac_protected_summary')) {
    function oac_protected_summary(PDO $conn, int $companyId, array $orphanJournalIds): array
    {
        $orphanSet = $orphanJournalIds ? oac_in($orphanJournalIds) : '0';
        $orphanParams = $orphanJournalIds ?: [];

        $totalJournals = oac_count($conn, "SELECT COUNT(*) FROM re_journal_headers WHERE company_id = ?", [$companyId]);

        $protectedPayments = oac_count($conn, "
            SELECT COUNT(DISTINCT j.id)
            FROM re_journal_headers j
            INNER JOIN re_payments p ON p.id = j.reference_id AND p.company_id = j.company_id
            WHERE j.company_id = ?
              AND j.reference_type IN ('payment','deferred_payment','deposit','refund','security_deposit_receipt')
              " . ($orphanJournalIds ? "AND j.id NOT IN ($orphanSet)" : '') . "
        ", array_merge([$companyId], $orphanParams));

        $protectedInvoices = oac_count($conn, "
            SELECT COUNT(DISTINCT j.id)
            FROM re_journal_headers j
            INNER JOIN re_invoices i ON i.id = j.reference_id AND i.company_id = j.company_id
            WHERE j.company_id = ?
              AND j.reference_type = 'invoice'
              " . ($orphanJournalIds ? "AND j.id NOT IN ($orphanSet)" : '') . "
        ", array_merge([$companyId], $orphanParams));

        $livePayments = oac_count($conn, "SELECT COUNT(*) FROM re_payments WHERE company_id = ?", [$companyId]);
        $liveInvoices = oac_count($conn, "SELECT COUNT(*) FROM re_invoices WHERE company_id = ?", [$companyId]);
        $liveAllocations = oac_count($conn, "SELECT COUNT(*) FROM re_payment_allocations WHERE payment_id IN (SELECT id FROM re_payments WHERE company_id = ?)", [$companyId]);

        $protectedCashPaymentJournals = 0;
        if (oac_table_exists($conn, 're_cash_payment_requests')) {
            $protectedCashPaymentJournals = oac_count($conn, "
                SELECT COUNT(DISTINCT j.id)
                FROM re_journal_headers j
                INNER JOIN re_cash_payment_requests r ON r.id = j.reference_id AND r.company_id = j.company_id
                WHERE j.company_id = ?
                  AND j.reference_type = 'cash_payment_request'
                  " . ($orphanJournalIds ? "AND j.id NOT IN ($orphanSet)" : '') . "
            ", array_merge([$companyId], $orphanParams));
        }

        return [
            'total_journals' => $totalJournals,
            'orphan_journals' => count($orphanJournalIds),
            'protected_journals' => max(0, $totalJournals - count($orphanJournalIds)),
            'protected_payment_journals' => $protectedPayments,
            'protected_invoice_journals' => $protectedInvoices,
            'protected_cash_payment_journals' => $protectedCashPaymentJournals,
            'live_payments' => $livePayments,
            'live_invoices' => $liveInvoices,
            'live_payment_allocations' => $liveAllocations,
        ];
    }
}

if (!function_exists('oac_gl_impact_by_account')) {
    function oac_gl_impact_by_account(PDO $conn, int $companyId, array $journalIds): array
    {
        if (!$journalIds) {
            return [];
        }
        $ph = oac_in($journalIds);
        $stmt = $conn->prepare("
            SELECT coa.account_code,
                   coa.account_name,
                   coa.account_type,
                   ROUND(SUM(gl.debit_amount), 2) AS total_debit,
                   ROUND(SUM(gl.credit_amount), 2) AS total_credit,
                   COUNT(DISTINCT gl.journal_id) AS journals
            FROM re_general_ledger gl
            JOIN re_chart_of_accounts coa ON coa.id = gl.account_id
            WHERE gl.company_id = ?
              AND gl.journal_id IN ($ph)
            GROUP BY coa.id, coa.account_code, coa.account_name, coa.account_type
            ORDER BY coa.account_code
        ");
        $stmt->execute(array_merge([$companyId], $journalIds));
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('oac_sample_orphan_journals')) {
    function oac_sample_orphan_journals(PDO $conn, int $companyId, array $journalIds, int $limit = 10): array
    {
        if (!$journalIds) {
            return [];
        }
        $sampleIds = array_slice($journalIds, 0, min(count($journalIds), 500));
        $ph = oac_in($sampleIds);
        $stmt = $conn->prepare("
            SELECT id, journal_number, journal_date, journal_type, reference_type, reference_id,
                   ROUND(total_debit, 2) AS total_debit, LEFT(description, 120) AS description
            FROM re_journal_headers
            WHERE company_id = ?
              AND id IN ($ph)
            ORDER BY journal_date DESC, id DESC
            LIMIT " . (int)$limit . "
        ");
        $stmt->execute(array_merge([$companyId], $sampleIds));
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('oac_delete_journals')) {
    function oac_delete_journals(PDO $conn, int $companyId, array $journalIds): int
    {
        if (!$journalIds) {
            return 0;
        }
        $ph = oac_in($journalIds);
        $params = $journalIds;

        foreach ([
            "DELETE FROM re_general_ledger WHERE company_id = ? AND journal_id IN ($ph)",
            "DELETE FROM re_account_ledger_entries WHERE company_id = ? AND journal_id IN ($ph)",
            "DELETE FROM re_accounting_postings WHERE company_id = ? AND journal_id IN ($ph)",
            "DELETE FROM re_journal_lines WHERE company_id = ? AND journal_id IN ($ph)",
            "DELETE FROM re_journal_headers WHERE company_id = ? AND id IN ($ph)",
        ] as $sql) {
            $stmt = $conn->prepare($sql);
            $stmt->execute(array_merge([$companyId], $params));
        }

        return count($journalIds);
    }
}

if (!function_exists('oac_build_steps')) {
    function oac_build_steps(PDO $conn, int $companyId, array $opts, array $orphanJournalIds): array
    {
        $steps = [];
        $add = static function (string $category, string $table, string $action, string $countSql, string $deleteSql, array $params, string $mode = 'DELETE') use (&$steps, $opts): void {
            $steps[] = compact('category', 'table', 'action', 'countSql', 'deleteSql', 'params', 'mode');
        };

        if ($orphanJournalIds) {
            $ph = oac_in($orphanJournalIds);
            $jp = $orphanJournalIds;
            foreach ([
                ['re_general_ledger', 'Delete orphan GL rows'],
                ['re_account_ledger_entries', 'Delete orphan sub-ledger rows'],
                ['re_accounting_postings', 'Delete orphan posting audit rows'],
                ['re_journal_lines', 'Delete orphan journal lines'],
                ['re_journal_headers', 'Delete orphan journal headers'],
            ] as [$table, $label]) {
                $col = $table === 're_journal_headers' ? 'id' : 'journal_id';
                $add(
                    'Orphan GL',
                    $table,
                    $label,
                    "SELECT COUNT(*) FROM {$table} WHERE company_id = ? AND {$col} IN ($ph)",
                    "DELETE FROM {$table} WHERE company_id = ? AND {$col} IN ($ph)",
                    array_merge([$companyId], $jp)
                );
            }
        }

        if (!empty($opts['orphan_recognition_rows'])) {
            $add(
                'Orphan Data',
                're_rent_recognition_schedule',
                'Delete recognition rows with missing/deleted lease or installment',
                "
                SELECT COUNT(*)
                FROM re_rent_recognition_schedule r
                WHERE r.company_id = ?
                  AND (
                      NOT EXISTS (SELECT 1 FROM re_leases l WHERE l.id = r.lease_id AND l.company_id = r.company_id)
                      OR NOT EXISTS (SELECT 1 FROM re_lease_installments i WHERE i.id = r.installment_id AND i.company_id = r.company_id)
                  )
                ",
                "
                DELETE r FROM re_rent_recognition_schedule r
                WHERE r.company_id = ?
                  AND (
                      NOT EXISTS (SELECT 1 FROM re_leases l WHERE l.id = r.lease_id AND l.company_id = r.company_id)
                      OR NOT EXISTS (SELECT 1 FROM re_lease_installments i WHERE i.id = r.installment_id AND i.company_id = r.company_id)
                  )
                ",
                [$companyId]
            );
        }

        if (!empty($opts['orphan_billing_items'])) {
            $add(
                'Orphan Data',
                're_billing_items',
                'Delete billing items linked to missing leases',
                "
                SELECT COUNT(*)
                FROM re_billing_items b
                WHERE b.company_id = ?
                  AND (
                      b.lease_id IS NULL
                      OR NOT EXISTS (SELECT 1 FROM re_leases l WHERE l.id = b.lease_id AND l.company_id = b.company_id)
                  )
                ",
                "
                DELETE b FROM re_billing_items b
                WHERE b.company_id = ?
                  AND (
                      b.lease_id IS NULL
                      OR NOT EXISTS (SELECT 1 FROM re_leases l WHERE l.id = b.lease_id AND l.company_id = b.company_id)
                  )
                ",
                [$companyId]
            );
        }

        if (!empty($opts['orphan_tenant_credits'])) {
            $add(
                'Orphan Data',
                're_tenant_credit_transactions',
                'Delete tenant credit rows with missing payment/installment source',
                "
                SELECT COUNT(*)
                FROM re_tenant_credit_transactions t
                WHERE t.company_id = ?
                  AND (
                      (t.payment_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM re_payments p WHERE p.id = t.payment_id AND p.company_id = t.company_id))
                      OR (t.installment_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM re_lease_installments i WHERE i.id = t.installment_id AND i.company_id = t.company_id))
                  )
                ",
                "
                DELETE t FROM re_tenant_credit_transactions t
                WHERE t.company_id = ?
                  AND (
                      (t.payment_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM re_payments p WHERE p.id = t.payment_id AND p.company_id = t.company_id))
                      OR (t.installment_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM re_lease_installments i WHERE i.id = t.installment_id AND i.company_id = t.company_id))
                  )
                ",
                [$companyId]
            );
        }

        if (!empty($opts['clear_payroll'])) {
            if (oac_table_exists($conn, 'payroll_items')) {
                $add(
                    'Payroll',
                    'payroll_items',
                    'Delete payroll run line items',
                    "SELECT COUNT(*) FROM payroll_items pi JOIN payroll_runs pr ON pr.id = pi.payroll_run_id WHERE pr.company_id = ?",
                    "DELETE pi FROM payroll_items pi JOIN payroll_runs pr ON pr.id = pi.payroll_run_id WHERE pr.company_id = ?",
                    [$companyId]
                );
            }
            if (oac_table_exists($conn, 'payroll_runs')) {
                $add(
                    'Payroll',
                    'payroll_runs',
                    'Delete payroll runs',
                    "SELECT COUNT(*) FROM payroll_runs WHERE company_id = ?",
                    "DELETE FROM payroll_runs WHERE company_id = ?",
                    [$companyId]
                );
            }
        }

        if (!empty($opts['clear_vendor_ap'])) {
            $vendorInvoiceIds = "SELECT id FROM re_vendor_invoices WHERE company_id = ?";
            $vendorPaymentIds = "SELECT id FROM re_vendor_payments WHERE company_id = ?";
            foreach ([
                ['re_vendor_payment_allocations', 'vendor_payment_id', $vendorPaymentIds],
                ['re_vendor_bill_attachments', 'vendor_invoice_id', $vendorInvoiceIds],
                ['re_vendor_invoice_items', 'invoice_id', $vendorInvoiceIds],
            ] as [$table, $col, $idsSql]) {
                if (!oac_table_exists($conn, $table)) {
                    continue;
                }
                $add(
                    'Vendor/AP',
                    $table,
                    'Delete vendor/AP child rows',
                    "SELECT COUNT(*) FROM {$table} WHERE company_id = ? AND {$col} IN ({$idsSql})",
                    "DELETE FROM {$table} WHERE company_id = ? AND {$col} IN ({$idsSql})",
                    [$companyId, $companyId]
                );
            }
            foreach (['re_vendor_payments', 're_vendor_invoices', 're_vendor_recurring_bills', 're_vendor_ap_audit'] as $table) {
                if (!oac_table_exists($conn, $table)) {
                    continue;
                }
                $add(
                    'Vendor/AP',
                    $table,
                    'Delete vendor/AP transactions',
                    "SELECT COUNT(*) FROM {$table} WHERE company_id = ?",
                    "DELETE FROM {$table} WHERE company_id = ?",
                    [$companyId]
                );
            }
        }

        if (!empty($opts['clear_expenses']) && oac_table_exists($conn, 'erp_expense_headers')) {
            $erpExpenseIds = "SELECT id FROM erp_expense_headers WHERE company_id = ? AND source_module = 'realestate'";
            if (oac_table_exists($conn, 'erp_expense_attachments')) {
                $add(
                    'Expenses',
                    'erp_expense_attachments',
                    'Delete Real Estate expense attachments',
                    "SELECT COUNT(*) FROM erp_expense_attachments WHERE expense_id IN ({$erpExpenseIds})",
                    "DELETE FROM erp_expense_attachments WHERE expense_id IN ({$erpExpenseIds})",
                    [$companyId]
                );
            }
            if (oac_table_exists($conn, 'erp_expense_lines')) {
                $add(
                    'Expenses',
                    'erp_expense_lines',
                    'Delete Real Estate expense lines',
                    "SELECT COUNT(*) FROM erp_expense_lines WHERE expense_id IN ({$erpExpenseIds})",
                    "DELETE FROM erp_expense_lines WHERE expense_id IN ({$erpExpenseIds})",
                    [$companyId]
                );
            }
            $add(
                'Expenses',
                'erp_expense_headers',
                'Delete Real Estate expense headers',
                "SELECT COUNT(*) FROM erp_expense_headers WHERE company_id = ? AND source_module = 'realestate'",
                "DELETE FROM erp_expense_headers WHERE company_id = ? AND source_module = 'realestate'",
                [$companyId]
            );
        }

        if (!empty($opts['clear_cash_payment_journals']) && oac_table_exists($conn, 're_cash_payment_requests')) {
            $add(
                'Cash Payments',
                're_cash_payment_requests',
                'Clear accounting_entry_id on cash payment requests (CPR records kept)',
                "SELECT COUNT(*) FROM re_cash_payment_requests WHERE company_id = ? AND accounting_entry_id IS NOT NULL",
                "UPDATE re_cash_payment_requests SET accounting_entry_id = NULL WHERE company_id = ? AND accounting_entry_id IS NOT NULL",
                [$companyId],
                'UPDATE'
            );
        }

        return $steps;
    }
}

if (!function_exists('oac_preview_steps')) {
    function oac_preview_steps(PDO $conn, array $steps): array
    {
        $out = [];
        foreach ($steps as $step) {
            $exists = oac_table_exists($conn, $step['table']);
            $count = $exists ? oac_count($conn, $step['countSql'], $step['params']) : 0;
            $out[] = $step + [
                'exists' => $exists,
                'row_count' => $count,
                'status' => $exists ? ($count < 0 ? 'error' : 'ready') : 'missing',
            ];
        }
        return $out;
    }
}

if (!function_exists('oac_execute_steps')) {
    function oac_execute_steps(PDO $conn, array $preview): array
    {
        $results = [];
        foreach ($preview as $step) {
            if (!$step['exists'] || (int)$step['row_count'] <= 0) {
                $results[] = $step + ['deleted' => 0, 'result' => 'skipped'];
                continue;
            }
            $stmt = $conn->prepare($step['deleteSql']);
            $stmt->execute($step['params']);
            $results[] = $step + ['deleted' => $stmt->rowCount(), 'result' => 'done'];
        }
        return $results;
    }
}

if (!function_exists('oac_build_preview')) {
    function oac_build_preview(PDO $conn, int $companyId, array $opts): array
    {
        $orphanJournalIds = oac_collect_orphan_journal_ids($conn, $companyId, $opts);
        $steps = oac_build_steps($conn, $companyId, $opts, $orphanJournalIds);
        $stepPreview = oac_preview_steps($conn, $steps);

        return [
            'options' => $opts,
            'orphan_journal_ids' => $orphanJournalIds,
            'protected' => oac_protected_summary($conn, $companyId, $orphanJournalIds),
            'gl_impact' => oac_gl_impact_by_account($conn, $companyId, $orphanJournalIds),
            'sample_orphans' => oac_sample_orphan_journals($conn, $companyId, $orphanJournalIds),
            'steps' => $stepPreview,
        ];
    }
}

if (!function_exists('oac_execute')) {
    function oac_execute(PDO $conn, int $companyId, array $opts): array
    {
        $preview = oac_build_preview($conn, $companyId, $opts);
        $conn->beginTransaction();
        try {
            $results = oac_execute_steps($conn, $preview['steps']);
            $conn->commit();
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            throw $e;
        }

        return [
            'before' => $preview,
            'results' => $results,
            'after' => oac_build_preview($conn, $companyId, $opts),
        ];
    }
}
