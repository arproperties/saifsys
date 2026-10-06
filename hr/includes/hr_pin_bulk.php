<?php
/**
 * Staff app PINs for many employees at once.
 *
 * The profile page sets one PIN at a time, typed twice. That is fine for one
 * new starter and hopeless for a whole camp. This makes a random PIN for each
 * employee chosen, the same way the profile would have — the login is created
 * if there is none, the PIN goes through ops_pin_set(), and both are audited —
 * and hands the PINs back to be shown ONCE. They are stored hashed like every
 * other PIN, so a list that is closed without being printed cannot be reopened;
 * those people are simply given new ones.
 *
 * Random, not the employee code: a PIN is typed on its own with no name beside
 * it, so it is the whole of who somebody is to the app, and employee codes run
 * in order on every payslip.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../modules/operations/includes/ops_pin.php';
require_once __DIR__ . '/hr_employee_login.php';

/**
 * A PIN nobody holds yet, or null when every one is taken.
 *
 * Tried at random first; with a few hundred staff and 10,000 values that lands
 * almost every time. The walk through all of them is only for the day it does
 * not.
 */
function hr_pin_bulk_free_pin(PDO $conn): ?string
{
    $length = ops_pin_length();
    $max = (10 ** $length) - 1;
    $taken = $conn->prepare("SELECT 1 FROM ops_staff_pins WHERE pin_lookup = ? LIMIT 1");
    $isFree = static function (string $pin) use ($taken): bool {
        $taken->execute([ops_pin_lookup_hash($pin)]);
        return $taken->fetchColumn() === false;
    };

    for ($i = 0; $i < 40; $i++) {
        $pin = str_pad((string)random_int(0, $max), $length, '0', STR_PAD_LEFT);
        if ($isFree($pin)) {
            return $pin;
        }
    }
    $start = random_int(0, $max);
    for ($i = 0; $i <= $max; $i++) {
        $pin = str_pad((string)(($start + $i) % ($max + 1)), $length, '0', STR_PAD_LEFT);
        if ($isFree($pin)) {
            return $pin;
        }
    }
    return null;
}

/**
 * Give each of these employees a new random PIN.
 *
 * $employees are rows with what hr_create_login_for_employee() needs: id,
 * full_name, employee_code, email, phone, address, user_id, position_title,
 * department_id, company_id, dept_name.
 *
 * One person failing never stops the rest: their row comes back with `error`
 * set and no PIN.
 *
 * @return array<int, array{employee_id:int, name:string, code:string, pin:?string,
 *                          replaced:bool, login:?array, error:string}>
 */
function hr_pin_bulk_generate(PDO $conn, array $employees, ?int $actorId): array
{
    $audit = function_exists('audit_bridge_hr_ops');
    $results = [];

    foreach ($employees as $emp) {
        $empId = (int)$emp['id'];
        $userId = (int)($emp['user_id'] ?? 0);
        $label = trim((string)($emp['full_name'] ?? '') . ' (' . (string)($emp['employee_code'] ?? ('#' . $empId)) . ')');
        $result = [
            'employee_id' => $empId,
            'name' => (string)($emp['full_name'] ?? ''),
            'code' => (string)($emp['employee_code'] ?? ''),
            'pin' => null,
            'replaced' => false,
            'login' => null,
            'error' => '',
        ];

        try {
            $pin = hr_pin_bulk_free_pin($conn);
            if ($pin === null) {
                $result['error'] = 'Every PIN is already in use.';
                $results[] = $result;
                continue;
            }

            // No login yet (or one that has since been deleted): make it, as
            // the profile page does when a PIN is set there.
            if (!hr_employee_has_login($conn, $userId)) {
                $login = hr_create_login_for_employee($conn, $emp, $actorId);
                $userId = (int)$login['user_id'];
                $result['login'] = ['username' => $login['username'], 'password' => $login['password']];
                if ($audit) {
                    audit_bridge_hr_ops(
                        'employee_login_created', 'user', $userId,
                        'Created login for ' . $label . ' — username ' . $login['username']
                            . ' (roles: ' . implode(', ', $login['roles']) . ') while generating staff app PINs',
                        null,
                        ['employee_id' => $empId, 'user_id' => $userId, 'username' => $login['username'], 'roles' => $login['roles']],
                        'User #' . $userId . ' — ' . $label,
                        $actorId
                    );
                }
            } else {
                $result['replaced'] = ops_pin_status($conn, $userId) !== null;
            }

            $set = ops_pin_set($conn, $userId, $pin, $actorId);
            if (!$set['ok']) {
                $result['error'] = $set['error'];
                $results[] = $result;
                continue;
            }
            $result['pin'] = $pin;

            if ($audit) {
                audit_bridge_hr_ops(
                    'ops_pin_set', 'user', $userId,
                    ($result['replaced'] ? 'Replaced' : 'Set') . ' the Operations app PIN for ' . $label . ' (generated in bulk)',
                    null,
                    ['employee_id' => $empId, 'user_id' => $userId, 'bulk' => true],
                    'User #' . $userId . ' — ' . $label,
                    $actorId
                );
            }
        } catch (Throwable $e) {
            error_log('hr_pin_bulk_generate failed for employee ' . $empId . ': ' . $e->getMessage());
            $result['pin'] = null;
            $result['error'] = 'Could not be saved. Set this one from the employee profile.';
        }

        $results[] = $result;
    }

    return $results;
}
