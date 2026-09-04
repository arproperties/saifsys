<?php
/**
 * Unified customer Firebase push notifications.
 *
 * Step 1 covers foundation + manual sending for Tenant Mode and ARS Guest Mode.
 * In-app notification tables remain separate; this helper only manages device
 * tokens, Firebase delivery, and push logs.
 */

declare(strict_types=1);

if (!function_exists('customer_push_ensure_schema')) {
    function customer_push_ensure_schema(PDO $conn): void {
        static $done = false;
        if ($done) return;
        $done = true;

        $conn->exec("
            CREATE TABLE IF NOT EXISTS customer_device_tokens (
              id INT(11) NOT NULL AUTO_INCREMENT,
              company_id INT(11) NOT NULL,
              user_type ENUM('tenant','guest') NOT NULL,
              tenant_portal_user_id INT(11) DEFAULT NULL,
              tenant_id INT(11) DEFAULT NULL,
              lease_id INT(11) DEFAULT NULL,
              guest_portal_user_id INT(11) DEFAULT NULL,
              guest_id INT(11) DEFAULT NULL,
              booking_id INT(11) DEFAULT NULL,
              platform ENUM('android','ios','web','unknown') NOT NULL DEFAULT 'unknown',
              device_id VARCHAR(150) DEFAULT NULL,
              fcm_token TEXT NOT NULL,
              token_hash CHAR(64) NOT NULL,
              app_version VARCHAR(50) DEFAULT NULL,
              device_name VARCHAR(150) DEFAULT NULL,
              is_active TINYINT(1) NOT NULL DEFAULT 1,
              last_seen_at DATETIME DEFAULT NULL,
              invalidated_at DATETIME DEFAULT NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME DEFAULT NULL,
              PRIMARY KEY (id),
              UNIQUE KEY uq_customer_device_token_hash (token_hash),
              KEY idx_customer_tokens_scope (company_id, user_type, is_active),
              KEY idx_customer_tokens_tenant (tenant_portal_user_id, tenant_id, lease_id),
              KEY idx_customer_tokens_guest (guest_portal_user_id, guest_id, booking_id),
              KEY idx_customer_tokens_device (user_type, device_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $conn->exec("
            CREATE TABLE IF NOT EXISTS customer_notification_batches (
              id INT(11) NOT NULL AUTO_INCREMENT,
              company_id INT(11) NOT NULL,
              user_type_scope ENUM('tenant','guest','mixed') NOT NULL,
              target_scope VARCHAR(50) NOT NULL,
              target_label VARCHAR(255) DEFAULT NULL,
              action_type VARCHAR(50) NOT NULL DEFAULT 'general',
              title VARCHAR(200) NOT NULL,
              message VARCHAR(500) DEFAULT NULL,
              recipient_count INT(11) NOT NULL DEFAULT 0,
              token_count INT(11) NOT NULL DEFAULT 0,
              sent_count INT(11) NOT NULL DEFAULT 0,
              failed_count INT(11) NOT NULL DEFAULT 0,
              invalid_token_count INT(11) NOT NULL DEFAULT 0,
              created_by INT(11) DEFAULT NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              KEY idx_customer_batches_company (company_id, created_at),
              KEY idx_customer_batches_scope (user_type_scope, target_scope)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $conn->exec("
            CREATE TABLE IF NOT EXISTS customer_push_logs (
              id INT(11) NOT NULL AUTO_INCREMENT,
              company_id INT(11) NOT NULL,
              user_type ENUM('tenant','guest') NOT NULL,
              batch_id INT(11) DEFAULT NULL,
              tenant_notification_id INT(11) DEFAULT NULL,
              guest_notification_id INT(11) DEFAULT NULL,
              device_token_id INT(11) DEFAULT NULL,
              tenant_portal_user_id INT(11) DEFAULT NULL,
              tenant_id INT(11) DEFAULT NULL,
              lease_id INT(11) DEFAULT NULL,
              guest_portal_user_id INT(11) DEFAULT NULL,
              guest_id INT(11) DEFAULT NULL,
              booking_id INT(11) DEFAULT NULL,
              target_scope VARCHAR(50) NOT NULL DEFAULT 'manual',
              action_type VARCHAR(50) NOT NULL DEFAULT 'general',
              status ENUM('pending','sent','failed','invalid_token','skipped') NOT NULL DEFAULT 'pending',
              firebase_message_id VARCHAR(255) DEFAULT NULL,
              error_code VARCHAR(100) DEFAULT NULL,
              error_message TEXT DEFAULT NULL,
              created_by INT(11) DEFAULT NULL,
              sent_at DATETIME DEFAULT NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              KEY idx_customer_push_company (company_id, created_at),
              KEY idx_customer_push_batch (batch_id),
              KEY idx_customer_push_token (device_token_id),
              KEY idx_customer_push_status (status),
              KEY idx_customer_push_tenant (tenant_notification_id, tenant_id),
              KEY idx_customer_push_guest (guest_notification_id, guest_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
}

if (!function_exists('customer_push_clean')) {
    function customer_push_clean($value, int $max = 255): string {
        $text = trim(strip_tags((string)$value));
        return function_exists('mb_substr') ? mb_substr($text, 0, $max, 'UTF-8') : substr($text, 0, $max);
    }
}

if (!function_exists('customer_push_platform')) {
    function customer_push_platform($platform): string {
        $platform = strtolower(customer_push_clean($platform, 20));
        return in_array($platform, ['android', 'ios', 'web'], true) ? $platform : 'unknown';
    }
}

if (!function_exists('customer_push_upsert_token')) {
    /**
     * @param array<string,mixed> $identity
     * @param array<string,mixed> $device
     */
    function customer_push_upsert_token(PDO $conn, string $userType, array $identity, array $device): array {
        customer_push_ensure_schema($conn);
        if (!in_array($userType, ['tenant', 'guest'], true)) {
            return ['ok' => false, 'error' => 'Invalid user type'];
        }

        $token = trim((string)($device['fcm_token'] ?? ''));
        if ($token === '' || strlen($token) < 20) {
            return ['ok' => false, 'error' => 'Invalid FCM token'];
        }

        $hash = hash('sha256', $token);
        $platform = customer_push_platform($device['platform'] ?? null);
        $deviceId = customer_push_clean($device['device_id'] ?? '', 150);
        if ($deviceId === '') {
            $deviceId = substr($hash, 0, 32);
        }

        $stmt = $conn->prepare("
            INSERT INTO customer_device_tokens
                (company_id, user_type, tenant_portal_user_id, tenant_id, lease_id,
                 guest_portal_user_id, guest_id, booking_id, platform, device_id,
                 fcm_token, token_hash, app_version, device_name, is_active, last_seen_at, updated_at)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                company_id = VALUES(company_id),
                user_type = VALUES(user_type),
                tenant_portal_user_id = VALUES(tenant_portal_user_id),
                tenant_id = VALUES(tenant_id),
                lease_id = VALUES(lease_id),
                guest_portal_user_id = VALUES(guest_portal_user_id),
                guest_id = VALUES(guest_id),
                booking_id = VALUES(booking_id),
                platform = VALUES(platform),
                device_id = VALUES(device_id),
                fcm_token = VALUES(fcm_token),
                app_version = VALUES(app_version),
                device_name = VALUES(device_name),
                is_active = 1,
                invalidated_at = NULL,
                last_seen_at = NOW(),
                updated_at = NOW()
        ");
        $stmt->execute([
            (int)($identity['company_id'] ?? 0),
            $userType,
            isset($identity['tenant_portal_user_id']) ? (int)$identity['tenant_portal_user_id'] : null,
            isset($identity['tenant_id']) ? (int)$identity['tenant_id'] : null,
            isset($identity['lease_id']) ? (int)$identity['lease_id'] : null,
            isset($identity['guest_portal_user_id']) ? (int)$identity['guest_portal_user_id'] : null,
            isset($identity['guest_id']) ? (int)$identity['guest_id'] : null,
            isset($identity['booking_id']) ? (int)$identity['booking_id'] : null,
            $platform,
            $deviceId,
            $token,
            $hash,
            customer_push_clean($device['app_version'] ?? '', 50) ?: null,
            customer_push_clean($device['device_name'] ?? '', 150) ?: null,
        ]);

        $id = (int)$conn->lastInsertId();
        if ($id <= 0) {
            $sel = $conn->prepare("SELECT id FROM customer_device_tokens WHERE token_hash = ? LIMIT 1");
            $sel->execute([$hash]);
            $id = (int)($sel->fetchColumn() ?: 0);
        }
        return ['ok' => true, 'id' => $id];
    }
}

if (!function_exists('customer_push_deactivate_token')) {
    /**
     * @param array<string,mixed> $identity
     */
    function customer_push_deactivate_token(PDO $conn, string $userType, array $identity, ?string $fcmToken, ?string $deviceId): int {
        customer_push_ensure_schema($conn);
        $where = ['company_id = ?', 'user_type = ?'];
        $params = [(int)($identity['company_id'] ?? 0), $userType];
        if ($userType === 'tenant') {
            if (!empty($identity['tenant_portal_user_id'])) {
                $where[] = 'tenant_portal_user_id = ?';
                $params[] = (int)$identity['tenant_portal_user_id'];
            } else {
                $where[] = 'tenant_id = ?';
                $params[] = (int)($identity['tenant_id'] ?? 0);
            }
        } else {
            $where[] = 'guest_id = ?';
            $params[] = (int)($identity['guest_id'] ?? 0);
        }
        if ($fcmToken !== null && trim($fcmToken) !== '') {
            $where[] = 'token_hash = ?';
            $params[] = hash('sha256', trim($fcmToken));
        } elseif ($deviceId !== null && trim($deviceId) !== '') {
            $where[] = 'device_id = ?';
            $params[] = customer_push_clean($deviceId, 150);
        } else {
            return 0;
        }
        $stmt = $conn->prepare("UPDATE customer_device_tokens SET is_active = 0, invalidated_at = NOW(), updated_at = NOW() WHERE " . implode(' AND ', $where));
        $stmt->execute($params);
        return $stmt->rowCount();
    }
}

if (!function_exists('customer_push_tokens_for_identity')) {
    /**
     * @param array<string,mixed> $identity
     * @return list<array<string,mixed>>
     */
    function customer_push_tokens_for_identity(PDO $conn, string $userType, array $identity): array {
        customer_push_ensure_schema($conn);
        $where = ['company_id = ?', 'user_type = ?', 'is_active = 1'];
        $params = [(int)($identity['company_id'] ?? 0), $userType];
        if ($userType === 'tenant') {
            if (!empty($identity['tenant_portal_user_id']) && !empty($identity['tenant_id'])) {
                $where[] = '(tenant_portal_user_id = ? OR tenant_id = ?)';
                $params[] = (int)$identity['tenant_portal_user_id'];
                $params[] = (int)$identity['tenant_id'];
            } elseif (!empty($identity['tenant_portal_user_id'])) {
                $where[] = 'tenant_portal_user_id = ?';
                $params[] = (int)$identity['tenant_portal_user_id'];
            } else {
                $where[] = 'tenant_id = ?';
                $params[] = (int)($identity['tenant_id'] ?? 0);
            }
        } else {
            $where[] = 'guest_id = ?';
            $params[] = (int)($identity['guest_id'] ?? 0);
        }
        $stmt = $conn->prepare("SELECT * FROM customer_device_tokens WHERE " . implode(' AND ', $where) . " ORDER BY last_seen_at DESC, id DESC");
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('customer_push_service_account')) {
    /**
     * @return array<string,mixed>|null
     */
    function customer_push_service_account(): ?array {
        $path = getenv('FIREBASE_SERVICE_ACCOUNT_PATH') ?: '';
        if ($path === '' && defined('FIREBASE_SERVICE_ACCOUNT_PATH')) {
            $path = (string)FIREBASE_SERVICE_ACCOUNT_PATH;
        }
        if ($path === '' || !is_file($path)) {
            return null;
        }
        $json = json_decode((string)file_get_contents($path), true);
        return is_array($json) ? $json : null;
    }
}

if (!function_exists('customer_push_http_post_json')) {
    /**
     * @param array<string,mixed> $payload
     * @param array<string,string> $headers
     * @return array{ok:bool,status:int,body:string,error:?string}
     */
    function customer_push_http_post_json(string $url, array $payload, array $headers = []): array {
        $headerLines = ['Content-Type: application/json'];
        foreach ($headers as $k => $v) {
            $headerLines[] = $k . ': ' . $v;
        }
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headerLines),
                'content' => json_encode($payload, JSON_UNESCAPED_SLASHES),
                'ignore_errors' => true,
                'timeout' => 15,
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        $status = 0;
        foreach (($http_response_header ?? []) as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m)) {
                $status = (int)$m[1];
                break;
            }
        }
        if ($body === false) {
            return ['ok' => false, 'status' => $status, 'body' => '', 'error' => 'HTTP request failed'];
        }
        return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'body' => (string)$body, 'error' => null];
    }
}

if (!function_exists('customer_push_access_token')) {
    function customer_push_access_token(?array $serviceAccount = null): ?string {
        static $cachedToken = null;
        static $expiresAt = 0;
        if ($cachedToken && $expiresAt > time() + 60) {
            return $cachedToken;
        }
        $sa = $serviceAccount ?: customer_push_service_account();
        if (!$sa || empty($sa['client_email']) || empty($sa['private_key'])) {
            return null;
        }
        $now = time();
        $header = rtrim(strtr(base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])), '+/', '-_'), '=');
        $claims = [
            'iss' => $sa['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ];
        $payload = rtrim(strtr(base64_encode(json_encode($claims, JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
        $signature = '';
        if (!function_exists('openssl_sign') || !openssl_sign($header . '.' . $payload, $signature, (string)$sa['private_key'], OPENSSL_ALGO_SHA256)) {
            return null;
        }
        $jwt = $header . '.' . $payload . '.' . rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
        $result = customer_push_http_post_json('https://oauth2.googleapis.com/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]);
        $decoded = json_decode($result['body'], true);
        if (!$result['ok'] || !is_array($decoded) || empty($decoded['access_token'])) {
            error_log('customer_push_access_token failed: ' . ($result['body'] ?: $result['error']));
            return null;
        }
        $cachedToken = (string)$decoded['access_token'];
        $expiresAt = $now + (int)($decoded['expires_in'] ?? 3600);
        return $cachedToken;
    }
}

if (!function_exists('customer_push_send_fcm')) {
    /**
     * @param array<string,string> $data
     * @return array{ok:bool,message_id:?string,error_code:?string,error_message:?string,invalid:bool}
     */
    function customer_push_send_fcm(string $token, string $title, string $body, array $data): array {
        $sa = customer_push_service_account();
        if (!$sa) {
            return ['ok' => false, 'message_id' => null, 'error_code' => 'firebase_not_configured', 'error_message' => 'Firebase service account is not configured.', 'invalid' => false];
        }
        $projectId = getenv('FIREBASE_PROJECT_ID') ?: ($sa['project_id'] ?? '');
        if ($projectId === '') {
            return ['ok' => false, 'message_id' => null, 'error_code' => 'firebase_project_missing', 'error_message' => 'Firebase project id is missing.', 'invalid' => false];
        }
        $access = customer_push_access_token($sa);
        if (!$access) {
            return ['ok' => false, 'message_id' => null, 'error_code' => 'firebase_auth_failed', 'error_message' => 'Could not authenticate with Firebase.', 'invalid' => false];
        }
        $safeData = [];
        foreach ($data as $k => $v) {
            $safeData[(string)$k] = (string)$v;
        }
        $payload = [
            'message' => [
                'token' => $token,
                'notification' => [
                    'title' => customer_push_clean($title, 200),
                    'body' => customer_push_clean($body, 500),
                ],
                'data' => $safeData,
                'android' => ['priority' => 'HIGH'],
                'apns' => ['payload' => ['aps' => ['sound' => 'default']]],
            ],
        ];
        $url = 'https://fcm.googleapis.com/v1/projects/' . rawurlencode((string)$projectId) . '/messages:send';
        $result = customer_push_http_post_json($url, $payload, ['Authorization' => 'Bearer ' . $access]);
        $decoded = json_decode($result['body'], true);
        if ($result['ok'] && is_array($decoded)) {
            return ['ok' => true, 'message_id' => (string)($decoded['name'] ?? ''), 'error_code' => null, 'error_message' => null, 'invalid' => false];
        }
        $errorCode = 'firebase_send_failed';
        $errorMessage = $result['body'] ?: ($result['error'] ?? 'Firebase send failed');
        if (is_array($decoded) && isset($decoded['error'])) {
            $err = $decoded['error'];
            $errorCode = (string)($err['status'] ?? $err['code'] ?? $errorCode);
            $errorMessage = (string)($err['message'] ?? $errorMessage);
        }
        $invalid = stripos($errorMessage, 'UNREGISTERED') !== false
            || stripos($errorMessage, 'registration token') !== false
            || stripos($errorCode, 'INVALID_ARGUMENT') !== false;
        return ['ok' => false, 'message_id' => null, 'error_code' => $errorCode, 'error_message' => $errorMessage, 'invalid' => $invalid];
    }
}

if (!function_exists('customer_push_send_to_identity')) {
    /**
     * @param array<string,mixed> $identity
     * @param array<string,string|int|null> $data
     * @return array{tokens:int,sent:int,failed:int,invalid:int}
     */
    function customer_push_send_to_identity(
        PDO $conn,
        string $userType,
        array $identity,
        string $title,
        string $body,
        array $data,
        ?int $tenantNotificationId = null,
        ?int $guestNotificationId = null,
        ?int $batchId = null,
        string $targetScope = 'manual',
        ?int $createdBy = null
    ): array {
        customer_push_ensure_schema($conn);
        $tokens = customer_push_tokens_for_identity($conn, $userType, $identity);
        $totals = ['tokens' => count($tokens), 'sent' => 0, 'failed' => 0, 'invalid' => 0];
        if ($tokens === []) {
            return $totals;
        }

        $baseData = [];
        foreach ($data as $k => $v) {
            if ($v !== null) $baseData[(string)$k] = (string)$v;
        }
        $baseData['mode'] = $userType;
        if ($tenantNotificationId) $baseData['notification_id'] = (string)$tenantNotificationId;
        if ($guestNotificationId) $baseData['notification_id'] = (string)$guestNotificationId;

        $log = $conn->prepare("
            INSERT INTO customer_push_logs
                (company_id, user_type, batch_id, tenant_notification_id, guest_notification_id, device_token_id,
                 tenant_portal_user_id, tenant_id, lease_id, guest_portal_user_id, guest_id, booking_id,
                 target_scope, action_type, status, firebase_message_id, error_code, error_message, created_by, sent_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($tokens as $tokenRow) {
            $send = customer_push_send_fcm((string)$tokenRow['fcm_token'], $title, $body, $baseData);
            $status = $send['ok'] ? 'sent' : ($send['invalid'] ? 'invalid_token' : 'failed');
            if ($send['ok']) {
                $totals['sent']++;
            } elseif ($send['invalid']) {
                $totals['invalid']++;
                $conn->prepare("UPDATE customer_device_tokens SET is_active = 0, invalidated_at = NOW(), updated_at = NOW() WHERE id = ?")
                    ->execute([(int)$tokenRow['id']]);
            } else {
                $totals['failed']++;
            }
            $log->execute([
                (int)($identity['company_id'] ?? 0),
                $userType,
                $batchId,
                $tenantNotificationId,
                $guestNotificationId,
                (int)$tokenRow['id'],
                $identity['tenant_portal_user_id'] ?? null,
                $identity['tenant_id'] ?? null,
                $identity['lease_id'] ?? null,
                $identity['guest_portal_user_id'] ?? null,
                $identity['guest_id'] ?? null,
                $identity['booking_id'] ?? null,
                $targetScope,
                (string)($baseData['action_type'] ?? 'general'),
                $status,
                $send['message_id'],
                $send['error_code'],
                $send['error_message'],
                $createdBy,
                $send['ok'] ? date('Y-m-d H:i:s') : null,
            ]);
        }
        return $totals;
    }
}

if (!function_exists('customer_push_update_batch_counts')) {
    /**
     * @param array{tokens:int,sent:int,failed:int,invalid:int} $totals
     */
    function customer_push_update_batch_counts(PDO $conn, int $batchId, int $recipientCount, array $totals): void {
        $stmt = $conn->prepare("
            UPDATE customer_notification_batches
            SET recipient_count = ?, token_count = ?, sent_count = ?, failed_count = ?, invalid_token_count = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $recipientCount,
            (int)$totals['tokens'],
            (int)$totals['sent'],
            (int)$totals['failed'],
            (int)$totals['invalid'],
            $batchId,
        ]);
    }
}

if (!function_exists('customer_push_tenant_action_type')) {
    function customer_push_tenant_action_type(string $type, ?string $entityType = null): string {
        if ($entityType === 'invoice' || $entityType === 'payment') return 'payments';
        if ($entityType === 'maintenance') return 'maintenance';
        if ($entityType === 'cleaning') return 'cleaning';
        if ($entityType === 'pest_control') return 'pest';
        if ($entityType === 'extra_service') return 'extra_service';
        if ($entityType === 'renewal') return 'renewal';
        if ($entityType === 'document') return 'document';

        if (str_contains($type, 'maintenance')) return 'maintenance';
        if (str_contains($type, 'cleaning')) return 'cleaning';
        if (str_contains($type, 'pest')) return 'pest';
        if (str_contains($type, 'extra_service')) return 'extra_service';
        if (str_contains($type, 'renewal')) return 'renewal';
        if (str_contains($type, 'document')) return 'document';
        if (str_contains($type, 'payment') || str_contains($type, 'invoice')) return 'payments';
        return 'general';
    }
}

if (!function_exists('customer_push_guest_action_type')) {
    function customer_push_guest_action_type(string $eventType, ?string $entityType = null): string {
        if ($entityType === 'payment') return 'payment';
        if ($entityType === 'lifecycle_request') return 'lifecycle_request';
        if ($entityType === 'document') return 'document';
        if ($entityType === 'receipt') return 'receipt';
        if ($eventType === 'reminder_checkin' || $eventType === 'manual_checkin') return 'checkin';
        if ($eventType === 'reminder_checkout' || $eventType === 'manual_checkout') return 'checkout';
        if (str_contains($eventType, 'payment') || str_contains($eventType, 'deposit')) return 'payment';
        if (str_contains($eventType, 'receipt')) return 'receipt';
        if (str_contains($eventType, 'document')) return 'document';
        if (str_starts_with($eventType, 'request_') || str_contains($eventType, 'lifecycle')) return 'lifecycle_request';
        return 'booking';
    }
}

if (!function_exists('customer_push_safe_auto_body')) {
    function customer_push_safe_auto_body(string $userType, string $actionType, string $body, string $title): string {
        if ($userType === 'tenant' && $actionType === 'payments') {
            return 'Open the app to view payment details.';
        }
        if ($userType === 'tenant' && $actionType === 'document') {
            return 'Open the app to view your document.';
        }
        if ($userType === 'guest' && in_array($actionType, ['payment', 'receipt', 'document'], true)) {
            return 'Open the app to view the latest details.';
        }
        if ($userType === 'guest' && $actionType === 'lifecycle_request') {
            return 'Open the app to view your request update.';
        }
        return $body !== '' ? $body : $title;
    }
}

if (!function_exists('customer_push_send_tenant_auto_notification')) {
    /**
     * @param array<string,mixed> $args Same payload passed to tenant_notification_create.
     */
    function customer_push_send_tenant_auto_notification(PDO $conn, array $args, int $notificationId): void {
        try {
            if ($notificationId <= 0) return;
            $companyId = (int)($args['company_id'] ?? 0);
            if ($companyId <= 0) return;

            $leaseId = isset($args['lease_id']) ? (int)$args['lease_id'] : null;
            $tenantId = isset($args['tenant_id']) ? (int)$args['tenant_id'] : null;
            $tpuId = isset($args['tenant_portal_user_id']) ? (int)$args['tenant_portal_user_id'] : null;

            if (($tenantId === null || $tenantId <= 0) && $leaseId !== null && $leaseId > 0) {
                $st = $conn->prepare('SELECT tenant_id FROM re_leases WHERE id = ? AND company_id = ? LIMIT 1');
                $st->execute([$leaseId, $companyId]);
                $resolvedTenantId = (int)($st->fetchColumn() ?: 0);
                if ($resolvedTenantId > 0) $tenantId = $resolvedTenantId;
            }

            $entityType = isset($args['entity_type']) ? trim((string)$args['entity_type']) : null;
            $entityId = isset($args['entity_id']) ? (int)$args['entity_id'] : null;
            $type = trim((string)($args['type'] ?? 'general'));
            $title = customer_push_clean($args['title'] ?? '', 200);
            $body = customer_push_clean($args['body'] ?? '', 500);
            $actionType = customer_push_tenant_action_type($type, $entityType);
            if ($title === '' || ($tenantId !== null && $tenantId <= 0 && ($tpuId === null || $tpuId <= 0))) return;

            customer_push_send_to_identity(
                $conn,
                'tenant',
                [
                    'company_id' => $companyId,
                    'tenant_portal_user_id' => $tpuId && $tpuId > 0 ? $tpuId : null,
                    'tenant_id' => $tenantId && $tenantId > 0 ? $tenantId : null,
                    'lease_id' => $leaseId && $leaseId > 0 ? $leaseId : null,
                ],
                $title,
                customer_push_safe_auto_body('tenant', $actionType, $body, $title),
                [
                    'action_type' => $actionType,
                    'entity_type' => $entityType,
                    'entity_id' => $entityId && $entityId > 0 ? $entityId : null,
                    'lease_id' => $leaseId && $leaseId > 0 ? $leaseId : null,
                ],
                $notificationId,
                null,
                null,
                'auto_' . ($type !== '' ? $type : 'tenant'),
                null
            );
        } catch (Throwable $e) {
            error_log('customer_push_send_tenant_auto_notification failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('customer_push_send_guest_auto_notification')) {
    /**
     * @param array<string,mixed> $args Same payload passed to ars_guest_notification_create.
     */
    function customer_push_send_guest_auto_notification(PDO $conn, array $args, int $notificationId): void {
        try {
            if ($notificationId <= 0) return;
            $companyId = (int)($args['company_id'] ?? 0);
            $guestId = (int)($args['guest_id'] ?? 0);
            if ($companyId <= 0 || $guestId <= 0) return;

            $bookingId = isset($args['booking_id']) ? (int)$args['booking_id'] : null;
            $eventType = trim((string)($args['event_type'] ?? 'general'));
            $title = customer_push_clean($args['title'] ?? '', 200);
            $message = customer_push_clean($args['message'] ?? '', 500);
            if ($title === '') return;

            $entityType = null;
            $entityId = null;
            $meta = $args['meta'] ?? null;
            if (is_array($meta)) {
                $entityType = isset($meta['entity_type']) ? trim((string)$meta['entity_type']) : null;
                $entityId = isset($meta['entity_id']) ? (int)$meta['entity_id'] : null;
                if ($entityId === null || $entityId <= 0) {
                    $entityId = isset($meta['request_id']) ? (int)$meta['request_id'] : null;
                }
            }
            if ($entityType === null) {
                if (str_contains($eventType, 'payment') || str_contains($eventType, 'deposit')) $entityType = 'payment';
                elseif (str_contains($eventType, 'receipt')) $entityType = 'receipt';
                elseif (str_contains($eventType, 'document')) $entityType = 'document';
                elseif (str_starts_with($eventType, 'request_')) $entityType = 'lifecycle_request';
                else $entityType = 'booking';
            }
            if (($entityId === null || $entityId <= 0) && $entityType === 'booking' && $bookingId !== null && $bookingId > 0) {
                $entityId = $bookingId;
            }
            $actionType = customer_push_guest_action_type($eventType, $entityType);

            customer_push_send_to_identity(
                $conn,
                'guest',
                [
                    'company_id' => $companyId,
                    'guest_id' => $guestId,
                    'booking_id' => $bookingId && $bookingId > 0 ? $bookingId : null,
                ],
                $title,
                customer_push_safe_auto_body('guest', $actionType, $message, $title),
                [
                    'action_type' => $actionType,
                    'entity_type' => $entityType,
                    'entity_id' => $entityId && $entityId > 0 ? $entityId : null,
                    'booking_id' => $bookingId && $bookingId > 0 ? $bookingId : null,
                ],
                null,
                $notificationId,
                null,
                'auto_' . ($eventType !== '' ? $eventType : 'guest'),
                null
            );
        } catch (Throwable $e) {
            error_log('customer_push_send_guest_auto_notification failed: ' . $e->getMessage());
        }
    }
}
