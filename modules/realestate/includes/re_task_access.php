<?php
/**
 * Real Estate tasks access helpers.
 *
 * Visibility rule:
 * - task creator always sees own tasks
 * - primary assignee sees task
 * - additional assignee in re_task_assignees sees task
 * - Owner / Manager / tasks admin permission can see all
 */

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/permissions.php';
require_once __DIR__ . '/../../../includes/module_access.php';

function re_tasks_current_employee_id(PDO $conn, int $userId, int $companyId): ?int
{
    if ($userId <= 0) {
        return null;
    }

    try {
        $stmt = $conn->prepare("
            SELECT id
            FROM employees
            WHERE user_id = ?
              AND (company_id = ? OR company_id IS NULL)
            LIMIT 1
        ");
        $stmt->execute([$userId, $companyId]);
        $id = $stmt->fetchColumn();
        return $id ? (int) $id : null;
    } catch (Throwable $e) {
        return null;
    }
}

function re_tasks_is_admin_viewer(PDO $conn, int $userId): bool
{
    if ($userId <= 0) {
        return false;
    }

    if (has_role('Owner', $conn) || has_role('Manager', $conn)) {
        return true;
    }

    try {
        $st = $conn->prepare("SELECT can_admin_view FROM user_task_permissions WHERE user_id = ? LIMIT 1");
        $st->execute([$userId]);
        $val = $st->fetchColumn();
        if ((int) $val === 1) {
            return true;
        }
    } catch (Throwable $e) {
        // table may not exist yet; ignore
    }

    return has_permission('tasks.admin', MODULE_REALESTATE, $conn)
        || has_permission('tasks.view_all', MODULE_REALESTATE, $conn)
        || has_permission('realestate_tasks.admin_view', MODULE_REALESTATE, $conn);
}

function re_tasks_user_can_view_shared(PDO $conn, int $userId): bool
{
    if ($userId <= 0) {
        return false;
    }

    if (re_tasks_is_admin_viewer($conn, $userId)) {
        return true;
    }

    try {
        $st = $conn->prepare("SELECT can_view FROM user_task_permissions WHERE user_id = ? LIMIT 1");
        $st->execute([$userId]);
        $val = $st->fetchColumn();
        if ((int) $val === 1) {
            return true;
        }
    } catch (Throwable $e) {
        // table may not exist yet; ignore
    }

    return has_permission('tasks.view', MODULE_REALESTATE, $conn);
}

/**
 * @return array{sql:string, params:array<int,int>, employee_id:?int}
 */
function re_tasks_visibility_clause(PDO $conn, int $userId, int $companyId, string $taskAlias = 't'): array
{
    if (re_tasks_is_admin_viewer($conn, $userId)) {
        return ['sql' => '1=1', 'params' => [], 'employee_id' => null];
    }

    $employeeId = re_tasks_current_employee_id($conn, $userId, $companyId);
    if ($employeeId) {
        return [
            'sql' => "({$taskAlias}.created_by = ? OR {$taskAlias}.assigned_to = ? OR EXISTS (
                        SELECT 1
                        FROM re_task_assignees a
                        WHERE a.task_id = {$taskAlias}.id
                          AND a.employee_id = ?
                      ))",
            'params' => [$userId, $employeeId, $employeeId],
            'employee_id' => $employeeId,
        ];
    }

    return [
        'sql' => "{$taskAlias}.created_by = ?",
        'params' => [$userId],
        'employee_id' => null,
    ];
}

function re_tasks_can_view_task(PDO $conn, int $taskId, int $companyId, int $userId): bool
{
    if ($taskId <= 0 || $companyId <= 0 || $userId <= 0) {
        return false;
    }

    if (re_tasks_is_admin_viewer($conn, $userId)) {
        $stmt = $conn->prepare("SELECT 1 FROM re_tasks WHERE id = ? AND company_id = ? LIMIT 1");
        $stmt->execute([$taskId, $companyId]);
        return (bool) $stmt->fetchColumn();
    }

    $vis = re_tasks_visibility_clause($conn, $userId, $companyId, 't');
    $sql = "SELECT 1 FROM re_tasks t WHERE t.id = ? AND t.company_id = ? AND {$vis['sql']} LIMIT 1";
    $params = array_merge([$taskId, $companyId], $vis['params']);
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    return (bool) $stmt->fetchColumn();
}

