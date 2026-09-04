<?php
/**
 * Move aged audit_log rows into audit_log_archive (append-only cold store).
 */

if (!function_exists('audit_log_retention_months')) {
    function audit_log_retention_months(PDO $conn): int
    {
        try {
            $st = $conn->prepare("SELECT `value` FROM settings WHERE `key` = 'audit_retention_months' LIMIT 1");
            $st->execute();
            $months = (int)($st->fetchColumn() ?: 24);
        } catch (Throwable $e) {
            $months = 24;
        }
        if ($months < 6) {
            $months = 6;
        }
        if ($months > 120) {
            $months = 120;
        }
        return $months;
    }
}

if (!function_exists('audit_log_run_retention')) {
    /**
     * @return array{archived:int,retention_months:int}
     */
    function audit_log_run_retention(PDO $conn, ?int $months = null): array
    {
        $months = $months ?? audit_log_retention_months($conn);
        $cutoff = (new DateTimeImmutable('now'))->modify('-' . $months . ' months')->format('Y-m-d H:i:s');
        $archived = 0;

        $archiveExists = (bool)$conn->query("SHOW TABLES LIKE 'audit_log_archive'")->fetchColumn();
        if (!$archiveExists) {
            throw new RuntimeException('audit_log_archive table missing. Apply migrations/audit_log_enrichment.sql first.');
        }

        $conn->beginTransaction();
        try {
            $sel = $conn->prepare('SELECT * FROM audit_log WHERE created_at < ? ORDER BY id ASC LIMIT 5000');
            $sel->execute([$cutoff]);
            $rows = $sel->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as $row) {
                $id = (int)$row['id'];
                $ins = $conn->prepare("
                    INSERT IGNORE INTO audit_log_archive (
                        id, user_id, user_name, user_role, company_id, module, source,
                        action, action_label, object_type, object_id, object_ref,
                        summary, old_data, new_data, ip_address, user_agent,
                        success, error_message, created_at, archived_at
                    ) VALUES (
                        ?, ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?,
                        ?, ?, ?, NOW()
                    )
                ");
                $ins->execute([
                    $id,
                    $row['user_id'] ?? null,
                    $row['user_name'] ?? null,
                    $row['user_role'] ?? null,
                    $row['company_id'] ?? null,
                    $row['module'] ?? null,
                    $row['source'] ?? 'user',
                    $row['action'] ?? '',
                    $row['action_label'] ?? null,
                    $row['object_type'] ?? '',
                    $row['object_id'] ?? null,
                    $row['object_ref'] ?? null,
                    $row['summary'] ?? '',
                    $row['old_data'] ?? null,
                    $row['new_data'] ?? null,
                    $row['ip_address'] ?? null,
                    $row['user_agent'] ?? null,
                    (int)($row['success'] ?? 1),
                    $row['error_message'] ?? null,
                    $row['created_at'] ?? date('Y-m-d H:i:s'),
                ]);
                $conn->prepare('DELETE FROM audit_log WHERE id = ?')->execute([$id]);
                $archived++;
            }

            $conn->commit();
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            throw $e;
        }

        return ['archived' => $archived, 'retention_months' => $months];
    }
}
