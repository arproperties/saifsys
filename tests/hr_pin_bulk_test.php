<?php
declare(strict_types=1);

/**
 * Field app PINs generated in bulk.
 *
 * Runs against the local database inside a transaction that is rolled back.
 * Uses a throwaway PIN secret, so it works on a machine with none configured.
 *
 *     php tests/hr_pin_bulk_test.php
 */

putenv('OPS_MOBILE_PIN_SECRET=test-secret-for-hr-pin-bulk');

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../hr/includes/hr_pin_bulk.php';

$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$failures = 0;
function check(string $what, $expected, $actual): void
{
    global $failures;
    if ($expected === $actual) {
        echo "ok   - {$what}\n";
        return;
    }
    $failures++;
    echo "FAIL - {$what}\n       expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . "\n";
}

$select = "
    SELECT e.id, e.full_name, e.employee_code, e.email, e.phone, e.address, e.user_id,
           e.position_title, e.department_id, e.company_id, d.name AS dept_name
      FROM employees e
      LEFT JOIN departments d ON d.id = e.department_id
      LEFT JOIN `user` u ON u.id = e.user_id
      LEFT JOIN ops_staff_pins p ON p.user_id = u.id
     WHERE e.status = 'active' AND ";
$pick = static function (string $cond, int $n) use ($conn, $select): array {
    return $conn->query($select . $cond . " ORDER BY e.id LIMIT {$n}")->fetchAll(PDO::FETCH_ASSOC);
};

$conn->beginTransaction();
try {
    // The PINs on this copy were made under another secret, so start clean.
    $conn->exec("DELETE FROM ops_staff_pins");

    $withLogin = $pick("u.id IS NOT NULL AND u.status = 1", 12);
    $noLogin   = $pick("u.id IS NULL", 3);
    $inactive  = $pick("u.id IS NOT NULL AND u.status <> 1", 1);
    if (count($withLogin) < 3 || count($noLogin) < 1) {
        echo "Not enough employees to test with.\n";
        exit(1);
    }

    // 1. A mixed batch: people with a login, people without, a deactivated login.
    $batch = array_merge($withLogin, $noLogin, $inactive);
    $results = hr_pin_bulk_generate($conn, $batch, null);
    check('one result per employee', count($batch), count($results));

    $made = array_values(array_filter($results, static fn($r) => $r['pin'] !== null));
    $pins = array_column($made, 'pin');
    check('everyone with an active or new login got a PIN', count($withLogin) + count($noLogin), count($made));
    check('every PIN is four digits', count($pins), count(array_filter($pins, static fn($p) => (bool)preg_match('/^\d{4}$/', $p))));
    check('no two people share a PIN', count($pins), count(array_unique($pins)));
    check('none marked as replaced', 0, count(array_filter($made, static fn($r) => $r['replaced'])));

    // 2. Each PIN signs in as the right person.
    $wrong = 0;
    foreach ($made as $r) {
        $st = $conn->prepare("SELECT user_id FROM employees WHERE id = ?");
        $st->execute([$r['employee_id']]);
        $who = ops_pin_resolve_user($conn, $r['pin']);
        if (!$who || $who['id'] !== (int)$st->fetchColumn()) {
            $wrong++;
        }
    }
    check('each PIN opens its own employee and nobody else', 0, $wrong);

    // 3. People with no login got one, and are told its details.
    $created = array_values(array_filter($results, static fn($r) => $r['login'] !== null));
    check('a login was created for each employee without one', count($noLogin), count($created));
    check('the new login has a username and a password', true, $created[0]['login']['username'] !== '' && strlen($created[0]['login']['password']) >= 8);

    // 4. A deactivated login is refused, with a reason, and stops nobody else.
    if ($inactive) {
        $bad = array_values(array_filter($results, static fn($r) => $r['employee_id'] === (int)$inactive[0]['id']))[0];
        check('deactivated login: no PIN', null, $bad['pin']);
        check('deactivated login: says why', true, strpos($bad['error'], 'deactivated') !== false);
    }

    // 5. Run again for someone who now has a PIN: replaced, and the old one is dead.
    $first = $withLogin[0];
    $old = array_values(array_filter($results, static fn($r) => $r['employee_id'] === (int)$first['id']))[0]['pin'];
    $again = hr_pin_bulk_generate($conn, [$first], null)[0];
    check('second time: marked as replaced', true, $again['replaced']);
    check('second time: a different PIN', true, $again['pin'] !== null && $again['pin'] !== $old);
    check('second time: the old PIN no longer works', null, ops_pin_resolve_user($conn, $old));
    check('second time: the new PIN works', (int)$first['user_id'], ops_pin_resolve_user($conn, $again['pin'])['id'] ?? null);

    // 6. Nobody ticked: nothing happens.
    check('empty selection: nothing generated', [], hr_pin_bulk_generate($conn, [], null));

    // 7. A free PIN is never one already taken, even when most are.
    $taken = $conn->query("SELECT pin_lookup FROM ops_staff_pins")->fetchAll(PDO::FETCH_COLUMN);
    $clash = 0;
    for ($i = 0; $i < 200; $i++) {
        if (in_array(ops_pin_lookup_hash((string)hr_pin_bulk_free_pin($conn)), $taken, true)) {
            $clash++;
        }
    }
    check('200 free PINs: none already in use', 0, $clash);
} finally {
    $conn->rollBack();
}

echo $failures === 0 ? "\nAll passed.\n" : "\n{$failures} failed.\n";
exit($failures === 0 ? 0 : 1);
