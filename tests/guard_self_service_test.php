<?php
declare(strict_types=1);

define('GUARD_TEST_MODE', true);
define('WORKER_ROLE_NAMES', ['Cleaner', 'Driver']);
define('WORKER_ROUTE_ALLOWLIST', [
    '/hr/employee_view.php',
    '/hr/employee_view.php*',
    '/profile.php',
    '/login.php',
    '/logout.php',
]);

// Stub session helpers
$_SESSION = [];

function current_user_id(): ?int {
    if (isset($_SESSION['user']['id'])) {
        return (int)$_SESSION['user']['id'];
    }
    if (isset($_SESSION['user_id'])) {
        return (int)$_SESSION['user_id'];
    }
    return null;
}

function current_user_roles(?PDO $conn = null): array {
    return $_SESSION['roles'] ?? [];
}

function is_logged_in(): bool {
    return current_user_id() !== null;
}

class AuditService {
    public static array $events = [];
    public static function log(array $params): bool {
        self::$events[] = $params;
        return true;
    }
}

require_once __DIR__ . '/../lib/Guard.php';

$conn = new PDO('sqlite::memory:');
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['conn'] = $conn;

$conn->exec('CREATE TABLE user (id INTEGER PRIMARY KEY, employee_id INTEGER)');
$conn->exec('CREATE TABLE employees (id INTEGER PRIMARY KEY, user_id INTEGER)');

$conn->exec('INSERT INTO user (id, employee_id) VALUES (1, 100)');
$conn->exec('INSERT INTO employees (id, user_id) VALUES (100, 1)');
$conn->exec('INSERT INTO user (id, employee_id) VALUES (2, NULL)');
$conn->exec('INSERT INTO employees (id, user_id) VALUES (200, 2)');

function reset_test_env(array $roles, int $userId = 1): void {
    $_SESSION = [
        'user' => ['id' => $userId, 'employee_id' => null],
        'user_id' => $userId,
        'roles' => $roles,
    ];
    $_GET = [];
    Guard::resetForTesting();
    AuditService::$events = [];
}

function assert_equals($expected, $actual, string $label): void {
    if ($expected !== $actual) {
        throw new RuntimeException("Assertion failed: {$label}. Expected " . var_export($expected, true) . ' got ' . var_export($actual, true));
    }
}

function assert_true(bool $condition, string $label): void {
    if (!$condition) {
        throw new RuntimeException("Assertion failed: {$label}");
    }
}

try {
    // 1) Cleaner redirected away from dashboard
    reset_test_env(['Cleaner']);
    $_SERVER['REQUEST_URI'] = '/hr/dashboard.php';
    $resolved = Guard::resolveEmployeeId($GLOBALS['conn'], current_user_id());
    assert_equals(100, $resolved, 'Cleaner employee id resolved');
    assert_true(Guard::isWorker($_SESSION['roles']), 'Worker role detected');
    Guard::enforce($GLOBALS['conn']);
    assert_true(!empty(AuditService::$events), 'Redirect audit logged');
    assert_equals('redirect', Guard::$testLastAction['type'] ?? null, 'Cleaner redirected from dashboard');
    assert_equals('/hr/employee_view.php?id=100', Guard::$testLastAction['target'] ?? null, 'Redirect target is own profile');

    // 2) Cleaner allowed on own profile
    reset_test_env(['Cleaner']);
    $_SERVER['REQUEST_URI'] = '/hr/employee_view.php?id=100';
    $_GET['id'] = '100';
    Guard::enforce($GLOBALS['conn']);
    assert_equals([], Guard::$testLastAction, 'Cleaner stays on own profile');

    // 3) Cleaner blocked from another employee profile
    reset_test_env(['Cleaner']);
    $_SERVER['REQUEST_URI'] = '/hr/employee_view.php?id=200';
    $_GET['id'] = '200';
    Guard::enforce($GLOBALS['conn']);
    assert_equals('redirect', Guard::$testLastAction['type'] ?? null, 'Cleaner blocked from other profile');
    assert_equals('/hr/employee_view.php?id=100', Guard::$testLastAction['target'] ?? null, 'Redirect back to own profile');

    // 4) Admin can access dashboard freely
    reset_test_env(['Admin'], 2);
    $_SERVER['REQUEST_URI'] = '/hr/dashboard.php';
    Guard::enforce($GLOBALS['conn']);
    assert_equals([], Guard::$testLastAction, 'Admin not redirected');

    echo "All Guard self-service tests passed.\n";
} catch (Throwable $e) {
    echo "Guard self-service tests failed: " . $e->getMessage() . "\n";
    exit(1);
}

