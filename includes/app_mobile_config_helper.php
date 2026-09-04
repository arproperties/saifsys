<?php
/**
 * Customer App mobile runtime config (versions, force update, maintenance, future flags).
 * Global singleton — not company-scoped (one mobile app binary).
 */

declare(strict_types=1);

function app_mobile_config_ensure_schema(PDO $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    try {
        $conn->exec("
            CREATE TABLE IF NOT EXISTS app_mobile_config (
              id INT(11) NOT NULL DEFAULT 1,
              android_latest_version VARCHAR(32) NOT NULL DEFAULT '1.0.0',
              android_min_version VARCHAR(32) NOT NULL DEFAULT '1.0.0',
              ios_latest_version VARCHAR(32) NOT NULL DEFAULT '1.0.0',
              ios_min_version VARCHAR(32) NOT NULL DEFAULT '1.0.0',
              force_update TINYINT(1) NOT NULL DEFAULT 0,
              play_store_url VARCHAR(500) DEFAULT NULL,
              app_store_url VARCHAR(500) DEFAULT NULL,
              update_title VARCHAR(200) NOT NULL DEFAULT 'Update Available',
              update_message TEXT DEFAULT NULL,
              maintenance_mode TINYINT(1) NOT NULL DEFAULT 0,
              maintenance_message TEXT DEFAULT NULL,
              feature_flags_json LONGTEXT DEFAULT NULL,
              updated_at DATETIME DEFAULT NULL,
              updated_by INT(11) DEFAULT NULL,
              PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $exists = (int)$conn->query('SELECT COUNT(*) FROM app_mobile_config WHERE id = 1')->fetchColumn();
        if ($exists === 0) {
            $conn->exec("
                INSERT INTO app_mobile_config (
                  id, android_latest_version, android_min_version,
                  ios_latest_version, ios_min_version, force_update,
                  update_title, update_message, maintenance_mode, maintenance_message,
                  feature_flags_json, updated_at
                ) VALUES (
                  1, '1.0.1', '1.0.0', '1.0.1', '1.0.0', 0,
                  'Update Available',
                  'A new version of Ain Al Reem Living is available. Please update for the latest improvements.',
                  0,
                  'We are performing scheduled maintenance. Please try again shortly.',
                  '{}',
                  NOW()
                )
            ");
        }
        app_mobile_config_ensure_share_columns($conn);
        $done = true;
    } catch (Throwable $e) {
        error_log('app_mobile_config_ensure_schema: ' . $e->getMessage());
    }
}

/**
 * Additive columns for Property Sharing (ERP-configurable).
 */
function app_mobile_config_ensure_share_columns(PDO $conn): void
{
    $columns = [
        'property_share_enabled' => "TINYINT(1) NOT NULL DEFAULT 1",
        'share_base_url' => "VARCHAR(500) DEFAULT NULL",
        'share_message_template' => "TEXT DEFAULT NULL",
        'android_package_name' => "VARCHAR(200) DEFAULT 'com.ainalreem.living'",
        'android_sha256_fingerprints' => "TEXT DEFAULT NULL",
        'ios_team_id' => "VARCHAR(32) DEFAULT NULL",
        'ios_bundle_id' => "VARCHAR(200) DEFAULT 'com.ainalreem.living'",
    ];
    foreach ($columns as $name => $ddl) {
        try {
            $stmt = $conn->prepare("
                SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'app_mobile_config'
                  AND COLUMN_NAME = ?
            ");
            $stmt->execute([$name]);
            if ((int)$stmt->fetchColumn() === 0) {
                $conn->exec("ALTER TABLE app_mobile_config ADD COLUMN `{$name}` {$ddl}");
            }
        } catch (Throwable $e) {
            error_log("app_mobile_config_ensure_share_columns {$name}: " . $e->getMessage());
        }
    }
}

/**
 * @return array<string,mixed>
 */
function app_mobile_config_defaults(): array
{
    return [
        'id' => 1,
        'android_latest_version' => '1.0.1',
        'android_min_version' => '1.0.0',
        'ios_latest_version' => '1.0.1',
        'ios_min_version' => '1.0.0',
        'force_update' => 0,
        'play_store_url' => '',
        'app_store_url' => '',
        'update_title' => 'Update Available',
        'update_message' => 'A new version of Ain Al Reem Living is available. Please update for the latest improvements.',
        'maintenance_mode' => 0,
        'maintenance_message' => 'We are performing scheduled maintenance. Please try again shortly.',
        'feature_flags_json' => '{}',
        'property_share_enabled' => 1,
        'share_base_url' => '',
        'share_message_template' => "Check out this property:\n{title}\n{building} · {location}\n{rent}\n\n{url}",
        'android_package_name' => 'com.ainalreem.living',
        'android_sha256_fingerprints' => '',
        'ios_team_id' => '',
        'ios_bundle_id' => 'com.ainalreem.living',
        'updated_at' => null,
        'updated_by' => null,
    ];
}

/**
 * @return array<string,mixed>
 */
function app_mobile_config_get(PDO $conn): array
{
    app_mobile_config_ensure_schema($conn);
    try {
        $row = $conn->query('SELECT * FROM app_mobile_config WHERE id = 1 LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return app_mobile_config_defaults();
        }
        return array_merge(app_mobile_config_defaults(), $row);
    } catch (Throwable $e) {
        error_log('app_mobile_config_get: ' . $e->getMessage());
        return app_mobile_config_defaults();
    }
}

function app_mobile_config_normalize_version(string $version): string
{
    $version = trim($version);
    if ($version === '') {
        return '0.0.0';
    }
    // Keep digits/dots only for storage validation (semantic).
    if (!preg_match('/^\d+(\.\d+){0,3}([+-][A-Za-z0-9.\-]+)?$/', $version)) {
        return '0.0.0';
    }
    return $version;
}

/**
 * @param array<string,mixed> $input
 */
function app_mobile_config_save(PDO $conn, array $input, ?int $userId = null): void
{
    app_mobile_config_ensure_schema($conn);

    $flags = $input['feature_flags_json'] ?? '{}';
    if (is_array($flags)) {
        $flags = json_encode($flags, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $flags = trim((string)$flags);
    if ($flags === '') {
        $flags = '{}';
    }
    json_decode($flags);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('Feature flags must be valid JSON.');
    }

    $shareTemplate = trim((string)($input['share_message_template'] ?? ''));
    if ($shareTemplate === '') {
        $shareTemplate = "Check out this property:\n{title}\n{building} · {location}\n{rent}\n\n{url}";
    }

    $stmt = $conn->prepare("
        INSERT INTO app_mobile_config (
            id, android_latest_version, android_min_version,
            ios_latest_version, ios_min_version, force_update,
            play_store_url, app_store_url, update_title, update_message,
            maintenance_mode, maintenance_message, feature_flags_json,
            property_share_enabled, share_base_url, share_message_template,
            android_package_name, android_sha256_fingerprints, ios_team_id, ios_bundle_id,
            updated_at, updated_by
        ) VALUES (
            1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?
        )
        ON DUPLICATE KEY UPDATE
            android_latest_version = VALUES(android_latest_version),
            android_min_version = VALUES(android_min_version),
            ios_latest_version = VALUES(ios_latest_version),
            ios_min_version = VALUES(ios_min_version),
            force_update = VALUES(force_update),
            play_store_url = VALUES(play_store_url),
            app_store_url = VALUES(app_store_url),
            update_title = VALUES(update_title),
            update_message = VALUES(update_message),
            maintenance_mode = VALUES(maintenance_mode),
            maintenance_message = VALUES(maintenance_message),
            feature_flags_json = VALUES(feature_flags_json),
            property_share_enabled = VALUES(property_share_enabled),
            share_base_url = VALUES(share_base_url),
            share_message_template = VALUES(share_message_template),
            android_package_name = VALUES(android_package_name),
            android_sha256_fingerprints = VALUES(android_sha256_fingerprints),
            ios_team_id = VALUES(ios_team_id),
            ios_bundle_id = VALUES(ios_bundle_id),
            updated_at = NOW(),
            updated_by = VALUES(updated_by)
    ");
    $stmt->execute([
        app_mobile_config_normalize_version((string)($input['android_latest_version'] ?? '1.0.0')),
        app_mobile_config_normalize_version((string)($input['android_min_version'] ?? '1.0.0')),
        app_mobile_config_normalize_version((string)($input['ios_latest_version'] ?? '1.0.0')),
        app_mobile_config_normalize_version((string)($input['ios_min_version'] ?? '1.0.0')),
        !empty($input['force_update']) ? 1 : 0,
        trim((string)($input['play_store_url'] ?? '')) ?: null,
        trim((string)($input['app_store_url'] ?? '')) ?: null,
        trim((string)($input['update_title'] ?? 'Update Available')) ?: 'Update Available',
        trim((string)($input['update_message'] ?? '')),
        !empty($input['maintenance_mode']) ? 1 : 0,
        trim((string)($input['maintenance_message'] ?? '')),
        $flags,
        !empty($input['property_share_enabled']) ? 1 : 0,
        trim((string)($input['share_base_url'] ?? '')) ?: null,
        $shareTemplate,
        trim((string)($input['android_package_name'] ?? 'com.ainalreem.living')) ?: 'com.ainalreem.living',
        trim((string)($input['android_sha256_fingerprints'] ?? '')) ?: null,
        trim((string)($input['ios_team_id'] ?? '')) ?: null,
        trim((string)($input['ios_bundle_id'] ?? 'com.ainalreem.living')) ?: 'com.ainalreem.living',
        $userId,
    ]);
}

/**
 * Public payload for GET /app-config (future-ready envelope).
 *
 * @return array<string,mixed>
 */
function app_mobile_config_public_payload(PDO $conn): array
{
    $row = app_mobile_config_get($conn);
    $flags = [];
    $rawFlags = trim((string)($row['feature_flags_json'] ?? ''));
    if ($rawFlags !== '') {
        $decoded = json_decode($rawFlags, true);
        if (is_array($decoded)) {
            $flags = $decoded;
        }
    }

    return [
        'schema_version' => 1,
        'android' => [
            'latest_version' => (string)$row['android_latest_version'],
            'min_version' => (string)$row['android_min_version'],
            'store_url' => (string)($row['play_store_url'] ?? ''),
        ],
        'ios' => [
            'latest_version' => (string)$row['ios_latest_version'],
            'min_version' => (string)$row['ios_min_version'],
            'store_url' => (string)($row['app_store_url'] ?? ''),
        ],
        'force_update' => !empty($row['force_update']),
        'update' => [
            'title' => (string)($row['update_title'] ?? 'Update Available'),
            'message' => (string)($row['update_message'] ?? ''),
        ],
        'maintenance' => [
            'enabled' => !empty($row['maintenance_mode']),
            'message' => (string)($row['maintenance_message'] ?? ''),
        ],
        // Always a JSON object (never a bare array) for future flag adoption.
        'feature_flags' => empty($flags) ? new \stdClass() : (object)$flags,
        'property_share' => [
            'enabled' => !empty($row['property_share_enabled']),
            'base_url' => (string)($row['share_base_url'] ?? ''),
            'message_template' => (string)($row['share_message_template'] ?? ''),
        ],
        'updated_at' => $row['updated_at'] ?? null,
    ];
}
