<?php
/**
 * Tenant in-app notifications — shared helper.
 *
 * Provides a single, best-effort entry point [tenant_notification_create] used by
 * both the admin web modules (trigger points) and the customer API. All writes are
 * wrapped so a notification failure can NEVER break the host action (invoice
 * creation, payment recording, status updates, etc.).
 *
 * Recipients: a notification is linked to company_id + tenant_id + lease_id and,
 * when resolvable, tenant_portal_user_id. The mobile API scopes reads by the
 * authenticated tenant identity so a tenant only ever sees their own rows.
 *
 * In-app only. No push / email is sent from here.
 */

declare(strict_types=1);

if (!function_exists('tenant_notifications_table_ready')) {
    /**
     * Cheap, cached check that the notifications table exists.
     */
    function tenant_notifications_table_ready(PDO $conn): bool {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }
        try {
            $conn->query('SELECT 1 FROM re_tenant_notifications LIMIT 1');
            $ready = true;
        } catch (Throwable $e) {
            $ready = false;
        }
        return $ready;
    }
}

if (!function_exists('tenant_notification_resolve_recipient')) {
    /**
     * Resolve [tenant_id, tenant_portal_user_id] for a lease. Best-effort.
     *
     * @return array{0:?int,1:?int} [tenant_id, tenant_portal_user_id]
     */
    function tenant_notification_resolve_recipient(PDO $conn, int $leaseId, int $companyId): array {
        $tenantId = null;
        $tpuId = null;
        if ($leaseId <= 0) {
            return [null, null];
        }
        try {
            $st = $conn->prepare('SELECT tenant_id FROM re_leases WHERE id = ? LIMIT 1');
            $st->execute([$leaseId]);
            $tid = $st->fetchColumn();
            if ($tid !== false && $tid !== null) {
                $tenantId = (int)$tid;
            }
        } catch (Throwable $e) {
            // ignore
        }
        try {
            $st = $conn->prepare("
                SELECT id FROM tenant_portal_users
                WHERE lease_id = ? AND company_id = ? AND status = 'approved'
                ORDER BY id DESC
                LIMIT 1
            ");
            $st->execute([$leaseId, $companyId]);
            $pid = $st->fetchColumn();
            if ($pid !== false && $pid !== null) {
                $tpuId = (int)$pid;
            }
        } catch (Throwable $e) {
            // ignore
        }
        return [$tenantId, $tpuId];
    }
}

if (!function_exists('tenant_notification_create')) {
    /**
     * Create a tenant notification. Best-effort: returns the new id or null,
     * and never throws.
     *
     * Required: company_id, type, title.
     * Recommended: lease_id (auto-resolves tenant_id + tenant_portal_user_id),
     * entity_type, entity_id, body.
     *
     * Optional dedup: pass 'dedup_window_minutes' (>0) to skip creating a row when
     * an identical (type, entity_type, entity_id, lease_id) notification already
     * exists within that window — useful for reminders that may run repeatedly.
     *
     * @param array<string,mixed> $args
     */
    function tenant_notification_create(PDO $conn, array $args): ?int {
        try {
            if (!tenant_notifications_table_ready($conn)) {
                return null;
            }
            $companyId = (int)($args['company_id'] ?? 0);
            $type = trim((string)($args['type'] ?? ''));
            $title = trim((string)($args['title'] ?? ''));
            if ($companyId <= 0 || $type === '' || $title === '') {
                return null;
            }

            $leaseId = isset($args['lease_id']) ? (int)$args['lease_id'] : 0;
            $tenantId = isset($args['tenant_id']) ? (int)$args['tenant_id'] : 0;
            $tpuId = isset($args['tenant_portal_user_id']) ? (int)$args['tenant_portal_user_id'] : 0;

            if ($leaseId > 0 && ($tenantId <= 0 || $tpuId <= 0)) {
                [$resolvedTenant, $resolvedTpu] = tenant_notification_resolve_recipient($conn, $leaseId, $companyId);
                if ($tenantId <= 0 && $resolvedTenant) {
                    $tenantId = $resolvedTenant;
                }
                if ($tpuId <= 0 && $resolvedTpu) {
                    $tpuId = $resolvedTpu;
                }
            }

            // Nothing to deliver to — skip silently.
            if ($tenantId <= 0 && $tpuId <= 0) {
                return null;
            }

            $entityType = isset($args['entity_type']) ? trim((string)$args['entity_type']) : null;
            $entityId = isset($args['entity_id']) ? (int)$args['entity_id'] : null;
            $body = isset($args['body']) ? (string)$args['body'] : null;
            if ($body !== null && function_exists('mb_substr')) {
                $body = mb_substr($body, 0, 500, 'UTF-8');
            } elseif ($body !== null) {
                $body = substr($body, 0, 500);
            }
            $title = function_exists('mb_substr') ? mb_substr($title, 0, 200, 'UTF-8') : substr($title, 0, 200);

            $dedupWindow = (int)($args['dedup_window_minutes'] ?? 0);
            if ($dedupWindow > 0) {
                $dq = $conn->prepare("
                    SELECT id FROM re_tenant_notifications
                    WHERE company_id = ?
                      AND type = ?
                      AND (entity_type <=> ?)
                      AND (entity_id <=> ?)
                      AND (lease_id <=> ?)
                      AND created_at >= (NOW() - INTERVAL ? MINUTE)
                    LIMIT 1
                ");
                $dq->execute([
                    $companyId,
                    $type,
                    $entityType,
                    $entityId !== null && $entityId > 0 ? $entityId : null,
                    $leaseId > 0 ? $leaseId : null,
                    $dedupWindow,
                ]);
                if ($dq->fetchColumn() !== false) {
                    return null;
                }
            }

            $ins = $conn->prepare("
                INSERT INTO re_tenant_notifications
                (company_id, tenant_id, lease_id, tenant_portal_user_id, type, entity_type, entity_id, title, body)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $ins->execute([
                $companyId,
                $tenantId > 0 ? $tenantId : null,
                $leaseId > 0 ? $leaseId : null,
                $tpuId > 0 ? $tpuId : null,
                $type,
                $entityType !== '' ? $entityType : null,
                $entityId !== null && $entityId > 0 ? $entityId : null,
                $title,
                $body,
            ]);
            $notificationId = (int)$conn->lastInsertId();

            if ($notificationId > 0 && empty($args['skip_push'])) {
                try {
                    require_once __DIR__ . '/customer_push_notifications.php';
                    if (function_exists('customer_push_send_tenant_auto_notification')) {
                        $pushArgs = $args;
                        $pushArgs['tenant_id'] = $tenantId > 0 ? $tenantId : ($args['tenant_id'] ?? null);
                        $pushArgs['tenant_portal_user_id'] = $tpuId > 0 ? $tpuId : ($args['tenant_portal_user_id'] ?? null);
                        $pushArgs['lease_id'] = $leaseId > 0 ? $leaseId : ($args['lease_id'] ?? null);
                        $pushArgs['company_id'] = $companyId;
                        $pushArgs['type'] = $type;
                        $pushArgs['entity_type'] = $entityType;
                        $pushArgs['entity_id'] = $entityId;
                        $pushArgs['title'] = $title;
                        $pushArgs['body'] = $body;
                        customer_push_send_tenant_auto_notification($conn, $pushArgs, $notificationId);
                    }
                } catch (Throwable $e) {
                    error_log('tenant notification push failed: ' . $e->getMessage());
                }
            }

            return $notificationId;
        } catch (Throwable $e) {
            error_log('tenant_notification_create failed: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('tenant_notifications_generate_due_reminders')) {
    /**
     * Emit "payment due" reminders for installments due soon or overdue.
     * Idempotent within a day via dedup. Safe to call from a cron or admin action.
     *
     * @return int number of notifications created
     */
    function tenant_notifications_generate_due_reminders(PDO $conn, ?int $companyId = null, int $withinDays = 7): int {
        if (!tenant_notifications_table_ready($conn)) {
            return 0;
        }
        $created = 0;
        try {
            $sql = "
                SELECT i.id AS installment_id, i.lease_id, i.amount, i.installment_date, i.status,
                       l.company_id
                FROM re_lease_installments i
                INNER JOIN re_leases l ON l.id = i.lease_id
                WHERE i.status IN ('pending', 'overdue')
                  AND i.installment_date <= (CURDATE() + INTERVAL ? DAY)
            ";
            $params = [$withinDays];
            if ($companyId !== null && $companyId > 0) {
                $sql .= ' AND l.company_id = ?';
                $params[] = $companyId;
            }
            $sql .= ' ORDER BY i.installment_date ASC LIMIT 500';
            $st = $conn->prepare($sql);
            $st->execute($params);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $due = (string)($row['installment_date'] ?? '');
                $amount = number_format((float)($row['amount'] ?? 0), 2);
                $overdue = ((string)($row['status'] ?? '') === 'overdue');
                $id = tenant_notification_create($conn, [
                    'company_id' => (int)$row['company_id'],
                    'lease_id' => (int)$row['lease_id'],
                    'type' => 'payment_due',
                    'entity_type' => 'payment',
                    'entity_id' => (int)$row['installment_id'],
                    'title' => $overdue ? 'Payment overdue' : 'Payment due soon',
                    'body' => ($overdue ? 'An installment of AED ' : 'An installment of AED ')
                        . $amount . ($overdue ? ' is overdue (due ' : ' is due on ') . $due . ').',
                    'dedup_window_minutes' => 60 * 24, // once per day per installment
                ]);
                if ($id) {
                    $created++;
                }
            }
        } catch (Throwable $e) {
            error_log('tenant_notifications_generate_due_reminders failed: ' . $e->getMessage());
        }
        return $created;
    }
}
