<?php
declare(strict_types=1);

/**
 * Guard - role-aware access control for worker self-service
 */
class Guard
{
    private static bool $enforced = false;
    private const PRIVILEGED_ROLES = ['Owner', 'Admin', 'HR'];
    public static array $testLastAction = [];

    public static function enforce(\PDO $conn): void
    {
        if (self::$enforced) {
            return;
        }
        self::$enforced = true;

        if (php_sapi_name() === 'cli' && !self::inTestMode()) {
            return;
        }

        $path = self::currentPath();
        if ($path === '' || self::isStaticAsset($path)) {
            return;
        }

        if (!function_exists('is_logged_in') || !is_logged_in()) {
            return;
        }

        $roles = current_user_roles($conn);
        if (!self::isWorker($roles)) {
            return;
        }

        $userId = current_user_id();
        if (!$userId) {
            return;
        }

        $employeeId = self::resolveEmployeeId($conn, $userId);
        if (!$employeeId) {
            self::handleMissingEmployeeMapping($roles, $userId);
            return;
        }

        self::cacheEmployeeId($employeeId);

        if (!self::isRouteAllowed($path)) {
            self::denyAccess($employeeId, $path);
            if (self::inTestMode()) {
                return;
            }
        }

        // Route-specific ownership checks
        if (self::isEmployeeProfileRoute($path)) {
            self::enforceEmployeeProfileOwnership($employeeId);
            if (self::inTestMode() && !empty(self::$testLastAction)) {
                return;
            }
        } elseif (self::isPayslipRoute($path)) {
            self::enforcePayslipOwnership($employeeId);
            if (self::inTestMode() && !empty(self::$testLastAction)) {
                return;
            }
        }
    }

    public static function isWorker(array $roles): bool
    {
        $workerRoles = defined('WORKER_ROLE_NAMES') ? (array)WORKER_ROLE_NAMES : [];
        foreach ($roles as $role) {
            if (in_array($role, $workerRoles, true)) {
                return true;
            }
        }
        return false;
    }

    public static function currentEmployeeId(?\PDO $conn = null): ?int
    {
        if (!empty($_SESSION['worker_employee_id'])) {
            return (int)$_SESSION['worker_employee_id'];
        }
        if (!empty($_SESSION['user']['employee_id'])) {
            return (int)$_SESSION['user']['employee_id'];
        }
        if ($conn instanceof PDO) {
            $uid = current_user_id();
            if ($uid) {
                $employeeId = self::resolveEmployeeId($conn, $uid);
                if ($employeeId) {
                    self::cacheEmployeeId($employeeId);
                    return $employeeId;
                }
            }
        }
        return null;
    }

    public static function resolveEmployeeId(\PDO $conn, int $userId): ?int
    {
        if ($userId <= 0) {
            return null;
        }

        if (!empty($_SESSION['worker_employee_id'])) {
            return (int)$_SESSION['worker_employee_id'];
        }
        if (!empty($_SESSION['user']['employee_id'])) {
            return (int)$_SESSION['user']['employee_id'];
        }

        $stmt = $conn->prepare("SELECT employee_id FROM `user` WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $employeeId = $stmt->fetchColumn();
        if ($employeeId) {
            self::cacheEmployeeId((int)$employeeId);
            return (int)$employeeId;
        }

        $stmt = $conn->prepare("SELECT id FROM employees WHERE user_id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $employeeId = $stmt->fetchColumn();
        if ($employeeId) {
            self::cacheEmployeeId((int)$employeeId);
            return (int)$employeeId;
        }
        return null;
    }

    private static function handleMissingEmployeeMapping(array $roles, int $userId): void
    {
        self::logAudit('missing_employee_link', [
            'summary' => "Worker user {$userId} has no linked employee record",
            'success' => false,
        ]);

        $privileged = array_intersect($roles, self::PRIVILEGED_ROLES);

        if (self::inTestMode()) {
            self::setTestAction([
                'type' => 'error',
                'code' => 500,
                'message' => 'missing_employee_mapping',
                'privileged' => (bool)$privileged,
            ]);
            return;
        }

        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');

        if ($privileged) {
            echo "<div style='font-family:system-ui;padding:40px;max-width:640px;margin:60px auto;border:1px solid #eee;border-radius:16px;background:#fff'>";
            echo "<h2 style='color:#b91c1c;margin-bottom:12px'>Configuration required</h2>";
            echo "<p>This account has worker permissions but is not linked to an employee record.</p>";
            echo "<p>Please open <strong>HR → Employees</strong>, assign the correct employee, or run <code>tools/backfill_user_employee_link.php</code>.</p>";
            echo "</div>";
        } else {
            echo "<div style='font-family:system-ui;padding:40px;max-width:640px;margin:60px auto;border:1px solid #eee;border-radius:16px;background:#fff'>";
            echo "<h2 style='color:#b91c1c;margin-bottom:12px'>Account needs attention</h2>";
            echo "<p>Your login is not linked to an employee profile yet. Please contact HR or your administrator.</p>";
            echo "</div>";
        }
        exit;
    }

    private static function cacheEmployeeId(int $employeeId): void
    {
        $_SESSION['worker_employee_id'] = $employeeId;
        $_SESSION['user']['employee_id'] = $employeeId;
    }

    private static function denyAccess(int $employeeId, string $path): void
    {
        self::logAudit('worker_route_blocked', [
            'summary' => "Worker redirected from {$path} to own profile",
            'object_type' => 'route',
            'object_id' => $path,
            'new_data' => ['employee_id' => $employeeId],
            'success' => false,
        ]);

        if (self::wantsJson()) {
            if (self::inTestMode()) {
                self::setTestAction([
                    'type' => 'json',
                    'code' => 403,
                    'payload' => [
                        'error' => 'forbidden',
                        'message' => 'Workers can only access their own employee portal.',
                    ],
                ]);
                return;
            }
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode([
                'error' => 'forbidden',
                'message' => 'Workers can only access their own employee portal.',
            ]);
        } else {
            $target = self::employeeProfileLink($employeeId);
            if (self::inTestMode()) {
                self::setTestAction([
                    'type' => 'redirect',
                    'code' => 302,
                    'target' => $target,
                ]);
                return;
            }
            if (!headers_sent()) {
                header('Location: ' . $target, true, 302);
            } else {
                echo '<script>location.href=' . json_encode($target) . ';</script>';
            }
        }
        exit;
    }

    private static function enforceEmployeeProfileOwnership(int $employeeId): void
    {
        $requestedId = $_GET['id'] ?? $_GET['employee_id'] ?? '';
        if ((string)$employeeId === (string)$requestedId || $requestedId === '') {
            return;
        }

        self::logAudit('worker_profile_redirect', [
            'summary' => "Worker attempted to view employee {$requestedId}",
            'object_type' => 'employee',
            'object_id' => $requestedId,
            'new_data' => ['employee_id' => $employeeId],
            'success' => false,
        ]);

        $target = self::employeeProfileLink($employeeId);
        if (self::wantsJson()) {
            if (self::inTestMode()) {
                self::setTestAction([
                    'type' => 'json',
                    'code' => 403,
                    'payload' => [
                        'error' => 'forbidden',
                        'message' => 'You can only view your own profile.',
                    ],
                ]);
                return;
            }
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode([
                'error' => 'forbidden',
                'message' => 'You can only view your own profile.',
            ]);
        } else {
            if (self::inTestMode()) {
                self::setTestAction([
                    'type' => 'redirect',
                    'code' => 302,
                    'target' => $target,
                ]);
                return;
            }
            if (!headers_sent()) {
                header('Location: ' . $target);
            } else {
                echo '<script>location.href=' . json_encode($target) . ';</script>';
            }
        }
        exit;
    }

    private static function enforcePayslipOwnership(int $employeeId): void
    {
        $requested = isset($_GET['employee_id']) ? (int)$_GET['employee_id'] : null;
        if ($requested === null || $requested === $employeeId) {
            return;
        }

        self::logAudit('worker_payslip_blocked', [
            'summary' => "Worker attempted to view payslip for employee {$requested}",
            'object_type' => 'payslip',
            'object_id' => $requested,
            'new_data' => ['employee_id' => $employeeId],
            'success' => false,
        ]);

        if (self::wantsJson()) {
            if (self::inTestMode()) {
                self::setTestAction([
                    'type' => 'json',
                    'code' => 403,
                    'payload' => [
                        'error' => 'forbidden',
                        'message' => 'You can only view your own payslips.',
                    ],
                ]);
                return;
            }
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode([
                'error' => 'forbidden',
                'message' => 'You can only view your own payslips.',
            ]);
        } else {
            $target = self::employeeProfileLink($employeeId);
            if (self::inTestMode()) {
                self::setTestAction([
                    'type' => 'redirect',
                    'code' => 302,
                    'target' => $target,
                ]);
                return;
            }
            if (!headers_sent()) {
                header('Location: ' . $target);
            } else {
                echo '<script>location.href=' . json_encode($target) . ';</script>';
            }
        }
        exit;
    }

    private static function logAudit(string $action, array $payload): void
    {
        if (!class_exists('AuditService')) {
            require_once __DIR__ . '/../includes/AuditService.php';
        }

        $defaults = [
            'action' => $action,
            'object_type' => 'guard',
            'summary' => $payload['summary'] ?? $action,
        ];
        AuditService::log(array_merge($defaults, $payload));
    }

    private static function currentPath(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $path = parse_url($uri, PHP_URL_PATH) ?: '';
        if ($path === '' && isset($_SERVER['SCRIPT_NAME'])) {
            $path = $_SERVER['SCRIPT_NAME'];
        }
        return $path ?: '';
    }

    private static function isStaticAsset(string $path): bool
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext === '') {
            return false;
        }
        $assetExt = ['css','js','json','map','png','jpg','jpeg','gif','svg','webp','ico','woff','woff2','ttf','otf','txt','pdf'];
        return in_array($ext, $assetExt, true);
    }

    private static function isRouteAllowed(string $path): bool
    {
        $allowlist = defined('WORKER_ROUTE_ALLOWLIST') ? (array)WORKER_ROUTE_ALLOWLIST : [];
        foreach ($allowlist as $pattern) {
            $pattern = (string)$pattern;
            if ($pattern === '') {
                continue;
            }
            if (fnmatch($pattern, $path, FNM_CASEFOLD)) {
                return true;
            }
        }
        return false;
    }

    private static function wantsJson(): bool
    {
        if (!empty($_SERVER['HTTP_ACCEPT']) && stripos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
            return true;
        }
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            return true;
        }
        return false;
    }

    private static function isEmployeeProfileRoute(string $path): bool
    {
        return stripos($path, '/hr/employee_view.php') !== false;
    }

    private static function isPayslipRoute(string $path): bool
    {
        return stripos($path, '/hr/payslip.php') !== false;
    }

    private static function inTestMode(): bool
    {
        return defined('GUARD_TEST_MODE') && GUARD_TEST_MODE;
    }

    private static function setTestAction(array $data): void
    {
        self::$testLastAction = $data;
    }

    public static function employeeProfileLink(int $employeeId): string
    {
        return self::buildUrl('hr/employee_view.php?id=' . $employeeId);
    }

    public static function resetForTesting(): void
    {
        if (!self::inTestMode()) {
            return;
        }
        self::$enforced = false;
        self::$testLastAction = [];
    }

    private static function buildUrl(string $relative): string
    {
        $relative = '/' . ltrim($relative, '/');

        if (php_sapi_name() === 'cli') {
            return $relative;
        }

        if (defined('APP_BASE_URL') && APP_BASE_URL) {
            return rtrim(APP_BASE_URL, '/') . $relative;
        }

        $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
        $projectRoot = realpath(__DIR__ . '/..');
        if ($docRoot && $projectRoot) {
            $docRoot = rtrim(str_replace('\\', '/', $docRoot), '/');
            $projectRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');
            if (str_starts_with($projectRoot, $docRoot)) {
                $base = substr($projectRoot, strlen($docRoot));
                $base = $base ? '/' . ltrim($base, '/') : '';
                if ($base !== '') {
                    return rtrim($base, '/') . $relative;
                }
            }
        }

        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        if ($scriptName) {
            $dir = str_replace('\\', '/', dirname($scriptName));
            if ($dir !== '/' && $dir !== '.' && $dir !== '') {
                return rtrim($dir, '/') . $relative;
            }
        }

        return $relative;
    }
}

