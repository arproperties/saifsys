<?php
/**
 * Unified customer mobile API — JSON envelope, JWT (HS256), routing helpers.
 */

if (!function_exists('customer_api_jwt_secret')) {
    function customer_api_jwt_secret(): string {
        $env = getenv('CUSTOMER_API_JWT_SECRET');
        if ($env !== false && $env !== '') {
            return $env;
        }
        return 'CHANGE_ME_CUSTOMER_API_JWT_SECRET';
    }
}

if (!function_exists('customer_api_access_ttl')) {
    function customer_api_access_ttl(): int {
        $v = getenv('CUSTOMER_API_ACCESS_TTL');
        if ($v !== false && $v !== '') {
            return max(60, (int)$v);
        }
        return 3600;
    }
}

if (!function_exists('customer_api_refresh_ttl')) {
    function customer_api_refresh_ttl(): int {
        $v = getenv('CUSTOMER_API_REFRESH_TTL');
        if ($v !== false && $v !== '') {
            return max(3600, (int)$v);
        }
        return 30 * 24 * 3600;
    }
}

if (!function_exists('customer_api_base64url_encode')) {
    function customer_api_base64url_encode(string $raw): string {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}

if (!function_exists('customer_api_base64url_decode')) {
    function customer_api_base64url_decode(string $data): string {
        $pad = 4 - (strlen($data) % 4);
        if ($pad < 4) {
            $data .= str_repeat('=', $pad);
        }
        $out = base64_decode(strtr($data, '-_', '+/'), true);
        return $out === false ? '' : $out;
    }
}

if (!function_exists('customer_api_jwt_sign')) {
    function customer_api_jwt_sign(array $payload, int $ttlSeconds): string {
        $now = time();
        $payload['iat'] = $now;
        $payload['exp'] = $now + $ttlSeconds;
        $header = ['typ' => 'JWT', 'alg' => 'HS256'];
        $h = customer_api_base64url_encode(json_encode($header, JSON_UNESCAPED_SLASHES));
        $p = customer_api_base64url_encode(json_encode($payload, JSON_UNESCAPED_SLASHES));
        $sig = hash_hmac('sha256', $h . '.' . $p, customer_api_jwt_secret(), true);
        return $h . '.' . $p . '.' . customer_api_base64url_encode($sig);
    }
}

if (!function_exists('customer_api_jwt_verify')) {
    /**
     * @return array<string,mixed>|null
     */
    function customer_api_jwt_verify(string $token): ?array {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        [$h, $p, $s] = $parts;
        $expected = customer_api_base64url_encode(
            hash_hmac('sha256', $h . '.' . $p, customer_api_jwt_secret(), true)
        );
        if (!hash_equals($expected, $s)) {
            return null;
        }
        $payload = json_decode(customer_api_base64url_decode($p), true);
        if (!is_array($payload)) {
            return null;
        }
        if (($payload['exp'] ?? 0) < time()) {
            return null;
        }
        return $payload;
    }
}

if (!function_exists('customer_api_issue_token_pair')) {
    /**
     * @param array<string,mixed> $accessClaims typ guest|tenant, sub, cid, optional gid
     * @return array{access_token:string,refresh_token:string,expires_in:int}
     */
    function customer_api_issue_token_pair(array $accessClaims): array {
        $refreshClaims = array_merge($accessClaims, ['token_use' => 'refresh']);
        unset($refreshClaims['exp'], $refreshClaims['iat']);
        $access = customer_api_jwt_sign($accessClaims, customer_api_access_ttl());
        $refresh = customer_api_jwt_sign($refreshClaims, customer_api_refresh_ttl());
        return [
            'access_token' => $access,
            'refresh_token' => $refresh,
            'expires_in' => customer_api_access_ttl(),
        ];
    }
}

if (!function_exists('customer_api_json_headers')) {
    function customer_api_json_headers(): void {
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PATCH, PUT, DELETE, OPTIONS');
        header(
            'Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Client-Id, X-Tenant-Lease-Id'
        );
    }
}

if (!function_exists('customer_api_handle_options')) {
    function customer_api_handle_options(): void {
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }
}

if (!function_exists('customer_api_send_ok')) {
    function customer_api_send_ok($data, int $http = 200): void {
        http_response_code($http);
        echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('customer_api_send_error')) {
    function customer_api_send_error(
        string $code,
        string $message,
        int $http = 400,
        array $details = []
    ): void {
        http_response_code($http);
        $err = ['code' => $code, 'message' => $message];
        if ($details !== []) {
            $err['details'] = $details;
        }
        echo json_encode(['ok' => false, 'error' => $err], JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('customer_api_read_json_body')) {
    /**
     * @return array<string,mixed>
     */
    function customer_api_read_json_body(): array {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}

if (!function_exists('customer_api_get_bearer')) {
    function customer_api_get_bearer(): ?string {
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if ($auth !== '' && preg_match('/Bearer\s+(\S+)/i', $auth, $m)) {
            return $m[1];
        }
        return null;
    }
}

if (!function_exists('customer_api_route_prefix')) {
    function customer_api_route_prefix(): string {
        require_once __DIR__ . '/url_helper.php';
        $root = get_application_web_root();
        return ($root === '' ? '' : $root) . '/api/customer/v1';
    }
}

if (!function_exists('customer_api_parse_route')) {
    function customer_api_parse_route(): string {
        $fromQuery = trim((string)($_GET['route'] ?? ''), '/');
        if ($fromQuery !== '') {
            if (str_contains($fromQuery, '?')) {
                [$path, $qs] = explode('?', $fromQuery, 2);
                parse_str($qs, $parsed);
                if (is_array($parsed)) {
                    foreach ($parsed as $key => $value) {
                        if (!array_key_exists((string)$key, $_GET)) {
                            $_GET[(string)$key] = $value;
                        }
                    }
                }
                return $path;
            }
            return $fromQuery;
        }
        $uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        $uriPath = $uriPath === false ? '' : (string)$uriPath;
        $prefix = customer_api_route_prefix();
        if ($prefix !== '' && strpos($uriPath, $prefix) === 0) {
            $rest = trim(substr($uriPath, strlen($prefix)), '/');
            if ($rest === 'index.php' || str_starts_with($rest, 'index.php/')) {
                $rest = trim(substr($rest, strlen('index.php')), '/');
            }
            return $rest;
        }
        return '';
    }
}

if (!function_exists('customer_api_public_path_url')) {
    function customer_api_public_path_url(string $filePath): string {
        require_once __DIR__ . '/url_helper.php';
        $root = get_application_web_root();
        $path = '/' . ltrim(str_replace('\\', '/', $filePath), '/');
        if ($root === '') {
            return $path;
        }
        return $root . $path;
    }
}

if (!function_exists('customer_api_absolute_url')) {
    function customer_api_absolute_url(string $path): string {
        $path = '/' . ltrim(str_replace('\\', '/', $path), '/');
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
        return $protocol . '://' . $host . $path;
    }
}
