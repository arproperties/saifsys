<?php
/**
 * Shared Tasks navigation access helper.
 * Determines whether current user should see a Tasks menu link.
 */

if (!function_exists('tasks_nav_user_can_access')) {
    function tasks_nav_user_can_access(PDO $conn, ?int $userId = null): bool
    {
        $uid = $userId ?: (function_exists('current_user_id') ? (int)current_user_id() : 0);
        if ($uid <= 0) {
            return false;
        }

        if (function_exists('has_role') && (has_role('Owner', $conn) || has_role('Manager', $conn))) {
            return true;
        }

        if (function_exists('has_permission')) {
            if (has_permission('tasks.view', defined('MODULE_REALESTATE') ? MODULE_REALESTATE : 'realestate', $conn)
                || has_permission('tasks.admin', defined('MODULE_REALESTATE') ? MODULE_REALESTATE : 'realestate', $conn)
                || has_permission('tasks.view_all', defined('MODULE_REALESTATE') ? MODULE_REALESTATE : 'realestate', $conn)
                || has_permission('realestate_tasks.admin_view', defined('MODULE_REALESTATE') ? MODULE_REALESTATE : 'realestate', $conn)) {
                return true;
            }
        }

        try {
            $st = $conn->prepare("SELECT can_view, can_admin_view FROM user_task_permissions WHERE user_id = ? LIMIT 1");
            $st->execute([$uid]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row && (((int)$row['can_view'] === 1) || ((int)$row['can_admin_view'] === 1))) {
                return true;
            }
        } catch (Throwable $e) {
            // Table may not exist yet.
        }

        return false;
    }
}

