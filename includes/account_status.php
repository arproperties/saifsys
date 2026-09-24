<?php
/**
 * Whether a user account is still allowed to sign in.
 *
 * Sign-in used to verify the password and nothing else, so an account kept
 * working after the person left the company. Every authentication path should
 * run account_login_block_reason() once the password (or PIN) checks out.
 */

if (!function_exists('account_login_block_reason')) {

/**
 * Returns null when the account may sign in, otherwise:
 *   ['code' => 'unknown'|'disabled'|'departed', 'message' => ..., 'detail' => ...]
 *
 * 'message' is safe to show on the sign-in screen; 'detail' is for the audit log.
 */
function account_login_block_reason(PDO $conn, int $userId): ?array
{
    $contact = 'Please contact HR if you believe this is a mistake.';

    try {
        // employees.user_id and user.employee_id are both in use, so resolve
        // through whichever one is populated.
        $stmt = $conn->prepare("
            SELECT u.is_active,
                   u.status,
                   e.status AS employee_status,
                   COALESCE(e.last_working_day, e.exit_date) AS left_on,
                   (COALESCE(e.last_working_day, e.exit_date) > CURDATE()) AS leaves_later
            FROM `user` u
            LEFT JOIN employees e
                   ON e.id = COALESCE(
                          u.employee_id,
                          (SELECT e2.id FROM employees e2 WHERE e2.user_id = u.id ORDER BY e2.id LIMIT 1)
                      )
            WHERE u.id = ?
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // A database hiccup must not lock the whole company out.
        error_log('account_login_block_reason: ' . $e->getMessage());
        return null;
    }

    if (!$row) {
        return [
            'code'    => 'unknown',
            'message' => 'This account is no longer available. ' . $contact,
            'detail'  => 'User record not found',
        ];
    }

    if ((int)$row['is_active'] !== 1 || (int)$row['status'] !== 1) {
        return [
            'code'    => 'disabled',
            'message' => 'This account has been disabled. ' . $contact,
            'detail'  => 'Account disabled',
        ];
    }

    // Serving notice: still employed until the last working day has passed.
    if (!empty($row['leaves_later'])) {
        return null;
    }

    $employeeStatus = (string)($row['employee_status'] ?? '');
    $leftOn = $row['left_on'] ? ' (left ' . $row['left_on'] . ')' : '';

    if (in_array($employeeStatus, ['terminated', 'resigned', 'not_renewed'], true)) {
        return [
            'code'    => 'departed',
            'message' => 'This account is closed because you are no longer an employee. ' . $contact,
            'detail'  => 'Employee status: ' . $employeeStatus . $leftOn,
        ];
    }

    // 'inactive' also covers accounts that were simply switched off, so it only
    // blocks once HR has recorded a leaving date that has already passed.
    if ($employeeStatus === 'inactive' && !empty($row['left_on'])) {
        return [
            'code'    => 'departed',
            'message' => 'This account is closed because you are no longer an employee. ' . $contact,
            'detail'  => 'Employee status: inactive' . $leftOn,
        ];
    }

    return null;
}

}
