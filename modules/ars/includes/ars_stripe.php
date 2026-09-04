<?php
/**
 * ARS Home Rentals — Stripe payment helpers.
 *
 * Scope: short-term guest booking payments only. This file intentionally does
 * not post accounting journals or touch tenant/cleaning/maintenance modules.
 */

declare(strict_types=1);

require_once __DIR__ . '/ars_helpers.php';
require_once __DIR__ . '/ars_guest_notifications.php';
require_once __DIR__ . '/ars_deposit.php';

use Stripe\PaymentIntent;
use Stripe\Stripe;
use Stripe\Webhook;

function ars_stripe_require_sdk(): void {
    static $loaded = false;
    if ($loaded) return;
    $autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new RuntimeException('Stripe SDK is not installed. Run composer install.');
    }
    require_once $autoload;
    if (!class_exists(Stripe::class) || !class_exists(PaymentIntent::class) || !class_exists(Webhook::class)) {
        throw new RuntimeException('Stripe SDK is not available.');
    }
    $loaded = true;
}

function ars_stripe_ensure_schema(PDO $conn): void {
    static $done = false;
    if ($done) return;
    $done = true;
    ars_deposit_ensure_schema($conn);

    ars_stripe_add_column($conn, 'ars_company_settings', 'stripe_enabled', "TINYINT(1) NOT NULL DEFAULT 0");
    ars_stripe_add_column($conn, 'ars_company_settings', 'stripe_mode', "ENUM('test','live') NOT NULL DEFAULT 'test'");
    ars_stripe_add_column($conn, 'ars_company_settings', 'stripe_publishable_key_test', "VARCHAR(255) DEFAULT NULL");
    ars_stripe_add_column($conn, 'ars_company_settings', 'stripe_secret_key_test_enc', "TEXT DEFAULT NULL");
    ars_stripe_add_column($conn, 'ars_company_settings', 'stripe_webhook_secret_test_enc', "TEXT DEFAULT NULL");
    ars_stripe_add_column($conn, 'ars_company_settings', 'stripe_publishable_key_live', "VARCHAR(255) DEFAULT NULL");
    ars_stripe_add_column($conn, 'ars_company_settings', 'stripe_secret_key_live_enc', "TEXT DEFAULT NULL");
    ars_stripe_add_column($conn, 'ars_company_settings', 'stripe_webhook_secret_live_enc', "TEXT DEFAULT NULL");
    ars_stripe_add_column($conn, 'ars_company_settings', 'stripe_default_currency', "VARCHAR(10) NOT NULL DEFAULT 'AED'");
    ars_stripe_add_column($conn, 'ars_company_settings', 'stripe_payment_policy', "ENUM('full','deposit','partial') NOT NULL DEFAULT 'full'");
    ars_stripe_add_column($conn, 'ars_company_settings', 'stripe_deposit_percentage', "DECIMAL(5,2) NOT NULL DEFAULT 20.00");
    ars_stripe_add_column($conn, 'ars_company_settings', 'stripe_auto_confirm', "TINYINT(1) NOT NULL DEFAULT 0");
    ars_stripe_add_column($conn, 'ars_company_settings', 'stripe_success_url', "VARCHAR(500) DEFAULT NULL");
    ars_stripe_add_column($conn, 'ars_company_settings', 'stripe_failed_url', "VARCHAR(500) DEFAULT NULL");

    ars_stripe_add_column($conn, 'ars_booking_payments', 'payment_gateway', "VARCHAR(40) DEFAULT NULL");
    ars_stripe_add_column($conn, 'ars_booking_payments', 'payment_type', "ENUM('manual','full','deposit','balance','partial') NOT NULL DEFAULT 'manual'");
    ars_stripe_add_column($conn, 'ars_booking_payments', 'currency', "VARCHAR(10) DEFAULT NULL");
    ars_stripe_add_column($conn, 'ars_booking_payments', 'gateway_payment_intent_id', "VARCHAR(120) DEFAULT NULL");
    ars_stripe_add_column($conn, 'ars_booking_payments', 'gateway_charge_id', "VARCHAR(120) DEFAULT NULL");
    ars_stripe_add_column($conn, 'ars_booking_payments', 'gateway_status', "VARCHAR(60) DEFAULT NULL");
    ars_stripe_add_column($conn, 'ars_booking_payments', 'amount_refunded', "DECIMAL(12,2) NOT NULL DEFAULT 0.00");
    ars_stripe_add_column($conn, 'ars_booking_payments', 'refunded_at', "DATETIME DEFAULT NULL");
    ars_stripe_add_column($conn, 'ars_booking_payments', 'failure_code', "VARCHAR(120) DEFAULT NULL");
    ars_stripe_add_column($conn, 'ars_booking_payments', 'failure_message', "TEXT DEFAULT NULL");
    ars_stripe_add_column($conn, 'ars_booking_payments', 'raw_payload_json', "JSON DEFAULT NULL");
    ars_stripe_add_column($conn, 'ars_booking_payments', 'updated_at', "DATETIME DEFAULT NULL");
    ars_stripe_add_index($conn, 'ars_booking_payments', 'idx_ars_payments_gateway_intent', 'gateway_payment_intent_id');

    $conn->exec("
        CREATE TABLE IF NOT EXISTS ars_stripe_events (
            id INT(11) NOT NULL AUTO_INCREMENT,
            company_id INT(11) DEFAULT NULL,
            booking_id INT(11) DEFAULT NULL,
            event_id VARCHAR(120) NOT NULL,
            event_type VARCHAR(120) NOT NULL,
            livemode TINYINT(1) NOT NULL DEFAULT 0,
            payment_intent_id VARCHAR(120) DEFAULT NULL,
            status ENUM('received','processed','failed') NOT NULL DEFAULT 'received',
            payload_json JSON DEFAULT NULL,
            error_message TEXT DEFAULT NULL,
            processed_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_ars_stripe_event_id (event_id),
            KEY idx_ars_stripe_events_booking (booking_id),
            KEY idx_ars_stripe_events_intent (payment_intent_id),
            KEY idx_ars_stripe_events_type (event_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    try {
        $conn->exec("ALTER TABLE ars_bookings MODIFY payment_status ENUM('unpaid','partial','paid','refunded','failed') NOT NULL DEFAULT 'unpaid'");
    } catch (Throwable $e) {
        error_log('ARS Stripe payment_status enum migration failed: ' . $e->getMessage());
    }
}

function ars_stripe_add_column(PDO $conn, string $table, string $column, string $definition): void {
    try {
        $stmt = $conn->query("SHOW COLUMNS FROM `$table` LIKE " . $conn->quote($column));
        if (!$stmt || !$stmt->fetch(PDO::FETCH_ASSOC)) {
            $conn->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        }
    } catch (Throwable $e) {
        error_log("ARS Stripe schema migration failed for $table.$column: " . $e->getMessage());
    }
}

function ars_stripe_add_index(PDO $conn, string $table, string $index, string $column): void {
    try {
        $stmt = $conn->query("SHOW INDEX FROM `$table` WHERE Key_name = " . $conn->quote($index));
        if (!$stmt || !$stmt->fetch(PDO::FETCH_ASSOC)) {
            $conn->exec("ALTER TABLE `$table` ADD INDEX `$index` (`$column`)");
        }
    } catch (Throwable $e) {
        error_log("ARS Stripe index migration failed for $table.$index: " . $e->getMessage());
    }
}

function ars_stripe_crypto_key(): string {
    $raw = getenv('ARS_STRIPE_ENCRYPTION_KEY') ?: getenv('CUSTOMER_API_JWT_SECRET') ?: '';
    if ($raw === '' && defined('DB_NAME')) {
        $raw = DB_NAME . '|' . (defined('DB_USER') ? DB_USER : '') . '|' . (defined('DB_PASS') ? DB_PASS : '') . '|' . __DIR__;
    }
    return hash('sha256', $raw, true);
}

function ars_stripe_encrypt_secret(?string $plain): ?string {
    $plain = trim((string)$plain);
    if ($plain === '') return null;
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', ars_stripe_crypto_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) {
        throw new RuntimeException('Could not encrypt Stripe secret.');
    }
    return 'v1:' . base64_encode($iv . $tag . $cipher);
}

function ars_stripe_decrypt_secret(?string $encoded): string {
    $encoded = trim((string)$encoded);
    if ($encoded === '') return '';
    if (!str_starts_with($encoded, 'v1:')) return $encoded;
    $raw = base64_decode(substr($encoded, 3), true);
    if ($raw === false || strlen($raw) < 29) return '';
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipher = substr($raw, 28);
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', ars_stripe_crypto_key(), OPENSSL_RAW_DATA, $iv, $tag);
    return $plain === false ? '' : $plain;
}

function ars_stripe_public_settings(array $settings): array {
    $mode = ($settings['stripe_mode'] ?? 'test') === 'live' ? 'live' : 'test';
    return [
        'enabled' => !empty($settings['stripe_enabled']),
        'mode' => $mode,
        'publishable_key' => (string)($settings["stripe_publishable_key_{$mode}"] ?? ''),
        'currency' => strtoupper((string)($settings['stripe_default_currency'] ?? $settings['currency'] ?? 'AED')),
        'payment_policy' => (string)($settings['stripe_payment_policy'] ?? 'full'),
        'deposit_percentage' => (float)($settings['stripe_deposit_percentage'] ?? 20),
    ];
}

function ars_stripe_secret_key(array $settings): string {
    $mode = ($settings['stripe_mode'] ?? 'test') === 'live' ? 'live' : 'test';
    return ars_stripe_decrypt_secret($settings["stripe_secret_key_{$mode}_enc"] ?? '');
}

function ars_stripe_webhook_secret(array $settings, bool $livemode): string {
    $mode = $livemode ? 'live' : 'test';
    return ars_stripe_decrypt_secret($settings["stripe_webhook_secret_{$mode}_enc"] ?? '');
}

function ars_stripe_validate_settings(array $settings): array {
    $errors = [];
    $mode = ($settings['stripe_mode'] ?? 'test') === 'live' ? 'live' : 'test';
    if (!empty($settings['stripe_enabled'])) {
        $pk = (string)($settings["stripe_publishable_key_{$mode}"] ?? '');
        $sk = ars_stripe_decrypt_secret($settings["stripe_secret_key_{$mode}_enc"] ?? '');
        $wh = ars_stripe_decrypt_secret($settings["stripe_webhook_secret_{$mode}_enc"] ?? '');
        if ($pk === '' || !str_starts_with($pk, $mode === 'live' ? 'pk_live_' : 'pk_test_')) {
            $errors[] = ucfirst($mode) . ' publishable key is required and must match the selected mode.';
        }
        if ($sk === '' || !str_starts_with($sk, $mode === 'live' ? 'sk_live_' : 'sk_test_')) {
            $errors[] = ucfirst($mode) . ' secret key is required and must match the selected mode.';
        }
        if ($wh === '' || !str_starts_with($wh, 'whsec_')) {
            $errors[] = ucfirst($mode) . ' webhook secret is required.';
        }
    }
    $currency = strtoupper((string)($settings['stripe_default_currency'] ?? 'AED'));
    if (!preg_match('/^[A-Z]{3}$/', $currency)) {
        $errors[] = 'Stripe default currency must be a 3-letter ISO code.';
    }
    $policy = (string)($settings['stripe_payment_policy'] ?? 'full');
    if (!in_array($policy, ['full', 'deposit', 'partial'], true)) {
        $errors[] = 'Invalid Stripe payment policy.';
    }
    $pct = (float)($settings['stripe_deposit_percentage'] ?? 0);
    if (in_array($policy, ['deposit', 'partial'], true) && ($pct <= 0 || $pct > 100)) {
        $errors[] = 'Deposit percentage must be greater than 0 and not more than 100.';
    }
    return $errors;
}

function ars_stripe_payment_amount(array $booking, array $settings, string $paymentType, ?float $requestedAmount = null): float {
    if ($paymentType === 'security_deposit') {
        if ((string)($booking['deposit_status'] ?? 'none') !== 'pending') {
            return 0.0;
        }
        return round(max(0.0, (float)($booking['deposit_amount'] ?? 0)), 2);
    }

    $balance = max(0.0, (float)($booking['balance_due'] ?? 0));
    $total = max(0.0, (float)($booking['total_amount'] ?? 0));
    $policy = (string)($settings['stripe_payment_policy'] ?? 'full');
    $depositPct = max(0.0, min(100.0, (float)($settings['stripe_deposit_percentage'] ?? 20)));

    if ($paymentType === 'balance') {
        return round($balance, 2);
    }
    if ($paymentType === 'deposit') {
        return round(min($balance, $total * ($depositPct / 100)), 2);
    }
    if ($paymentType === 'partial') {
        $amount = $requestedAmount !== null ? (float)$requestedAmount : round($total * ($depositPct / 100), 2);
        return round(min($balance, max(1.0, $amount)), 2);
    }
    if ($policy === 'deposit' && $paymentType === 'full') {
        return round(min($balance, $total * ($depositPct / 100)), 2);
    }
    return round($balance, 2);
}

function ars_stripe_to_minor_units(float $amount, string $currency): int {
    $zeroDecimal = ['BIF','CLP','DJF','GNF','JPY','KMF','KRW','MGA','PYG','RWF','UGX','VND','VUV','XAF','XOF','XPF'];
    return in_array(strtoupper($currency), $zeroDecimal, true)
        ? (int)round($amount)
        : (int)round($amount * 100);
}

function ars_stripe_create_payment_intent(PDO $conn, array $booking, array $settings, string $paymentType, ?float $requestedAmount = null): array {
    ars_stripe_ensure_schema($conn);
    if (empty($settings['stripe_enabled'])) {
        throw new RuntimeException('Stripe is not enabled.');
    }
    ars_stripe_require_sdk();
    $secret = ars_stripe_secret_key($settings);
    if ($secret === '') {
        throw new RuntimeException('Stripe secret key is not configured.');
    }

    $amount = ars_stripe_payment_amount($booking, $settings, $paymentType, $requestedAmount);
    if ($amount <= 0) {
        if ($paymentType === 'security_deposit') {
            throw new RuntimeException('Security deposit is not due or has already been paid.');
        }
        throw new RuntimeException('No payable balance remains for this booking.');
    }

    $currency = strtolower((string)($settings['stripe_default_currency'] ?? $settings['currency'] ?? 'AED'));
    Stripe::setApiKey($secret);

    $intent = PaymentIntent::create([
        'amount' => ars_stripe_to_minor_units($amount, $currency),
        'currency' => $currency,
        'automatic_payment_methods' => ['enabled' => true],
        'metadata' => [
            'module' => 'ars',
            'booking_id' => (string)$booking['id'],
            'booking_number' => (string)$booking['booking_number'],
            'company_id' => (string)$booking['company_id'],
            'payment_type' => $paymentType,
        ],
        'description' => $paymentType === 'security_deposit'
            ? 'ARS security deposit ' . $booking['booking_number']
            : 'ARS booking ' . $booking['booking_number'] . ' payment',
    ]);

    $conn->prepare("
        INSERT INTO ars_booking_payments
            (booking_id, company_id, amount, payment_method, payment_date, reference_number,
             payment_link_status, notes, payment_gateway, payment_type, currency,
             gateway_payment_intent_id, gateway_status, raw_payload_json)
        VALUES (?, ?, ?, 'online', CURDATE(), ?, 'pending', ?, 'stripe', ?, ?, ?, ?, ?)
    ")->execute([
        (int)$booking['id'],
        (int)$booking['company_id'],
        $amount,
        $intent->id,
        'Stripe PaymentIntent created',
        $paymentType,
        strtoupper($currency),
        $intent->id,
        $intent->status,
        json_encode($intent->toArray(), JSON_UNESCAPED_SLASHES),
    ]);

    return [
        'payment_id' => (int)$conn->lastInsertId(),
        'payment_intent_id' => $intent->id,
        'client_secret' => $intent->client_secret,
        'amount' => number_format($amount, 2, '.', ''),
        'currency' => strtoupper($currency),
        'payment_type' => $paymentType,
        'publishable_key' => ars_stripe_public_settings($settings)['publishable_key'],
    ];
}

function ars_stripe_recalculate_booking_payment(PDO $conn, int $bookingId): void {
    $stmt = $conn->prepare("SELECT total_amount FROM ars_bookings WHERE id = ? LIMIT 1");
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$booking) return;

    $roomFilter = ars_payment_room_balance_sql_filter();
    $stmt = $conn->prepare("
        SELECT
            COALESCE(SUM(CASE
                WHEN COALESCE(gateway_status, '') IN ('requires_payment_method','canceled','failed') THEN 0
                ELSE GREATEST(amount - COALESCE(amount_refunded, 0), 0)
            END), 0) AS paid,
            COALESCE(SUM(COALESCE(amount_refunded, 0)), 0) AS refunded,
            COALESCE(SUM(CASE WHEN COALESCE(gateway_status, '') IN ('requires_payment_method','failed') THEN 1 ELSE 0 END), 0) AS failed_count
        FROM ars_booking_payments
        WHERE booking_id = ?
          AND (payment_gateway IS NULL OR payment_gateway != 'stripe' OR gateway_status IN ('succeeded','partially_refunded','refunded'))
          $roomFilter
    ");
    $stmt->execute([$bookingId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $total = (float)$booking['total_amount'];
    $paid = round((float)($row['paid'] ?? 0), 2);
    $refunded = round((float)($row['refunded'] ?? 0), 2);
    $balance = round(max($total - $paid, 0), 2);
    $status = 'unpaid';
    if ($refunded > 0 && $paid <= 0) {
        $status = 'refunded';
    } elseif ($paid >= $total && $total > 0) {
        $status = 'paid';
    } elseif ($paid > 0) {
        $status = 'partial';
    } else {
        $f = $conn->prepare("
            SELECT COUNT(*) FROM ars_booking_payments
            WHERE booking_id = ? AND payment_gateway = 'stripe'
              AND gateway_status IN ('requires_payment_method','failed')
              " . ars_payment_room_balance_sql_filter() . "
        ");
        $f->execute([$bookingId]);
        if ((int)$f->fetchColumn() > 0) {
            $status = 'failed';
        }
    }

    $conn->prepare("UPDATE ars_bookings SET paid_amount = ?, balance_due = ?, payment_status = ?, updated_at = NOW() WHERE id = ?")
        ->execute([$paid, $balance, $status, $bookingId]);
}

function ars_stripe_handle_payment_intent(PDO $conn, object $intent, string $eventType): void {
    $intentId = (string)($intent->id ?? '');
    if ($intentId === '') return;
    $bookingId = (int)($intent->metadata->booking_id ?? 0);
    if ($bookingId <= 0) {
        $stmt = $conn->prepare("SELECT booking_id FROM ars_booking_payments WHERE gateway_payment_intent_id = ? LIMIT 1");
        $stmt->execute([$intentId]);
        $bookingId = (int)$stmt->fetchColumn();
    }
    if ($bookingId <= 0) return;

    $status = (string)($intent->status ?? '');
    $chargeId = '';
    $latestCharge = $intent->latest_charge ?? null;
    if (is_string($latestCharge)) {
        $chargeId = $latestCharge;
    } elseif (is_object($latestCharge) && isset($latestCharge->id)) {
        $chargeId = (string)$latestCharge->id;
    }

    $failureCode = null;
    $failureMessage = null;
    if (isset($intent->last_payment_error) && is_object($intent->last_payment_error)) {
        $failureCode = (string)($intent->last_payment_error->code ?? '');
        $failureMessage = (string)($intent->last_payment_error->message ?? '');
    }

    $paymentLookup = $conn->prepare("SELECT id, gateway_status, amount FROM ars_booking_payments WHERE gateway_payment_intent_id = ? LIMIT 1");
    $paymentLookup->execute([$intentId]);
    $existingPayment = $paymentLookup->fetch(PDO::FETCH_ASSOC) ?: [];
    $oldStatus = strtolower((string)($existingPayment['gateway_status'] ?? ''));

    $conn->prepare("
        UPDATE ars_booking_payments
        SET gateway_status = ?, gateway_charge_id = NULLIF(?, ''), payment_link_status = ?,
            failure_code = NULLIF(?, ''), failure_message = NULLIF(?, ''),
            raw_payload_json = ?, updated_at = NOW()
        WHERE gateway_payment_intent_id = ?
    ")->execute([
        $status,
        $chargeId,
        $status === 'succeeded' ? 'paid' : 'pending',
        $failureCode,
        $failureMessage,
        json_encode(method_exists($intent, 'toArray') ? $intent->toArray() : $intent, JSON_UNESCAPED_SLASHES),
        $intentId,
    ]);

    $paymentTypeMeta = '';
    if (isset($intent->metadata->payment_type)) {
        $paymentTypeMeta = (string)$intent->metadata->payment_type;
    }
    if ($paymentTypeMeta === '') {
        $ptStmt = $conn->prepare('SELECT payment_type FROM ars_booking_payments WHERE gateway_payment_intent_id = ? LIMIT 1');
        $ptStmt->execute([$intentId]);
        $paymentTypeMeta = (string)($ptStmt->fetchColumn() ?: '');
    }

    if ($paymentTypeMeta === 'security_deposit' && $status === 'succeeded') {
        $bookingStmt = $conn->prepare('SELECT * FROM ars_bookings WHERE id = ? LIMIT 1');
        $bookingStmt->execute([$bookingId]);
        $bookingRow = $bookingStmt->fetch(PDO::FETCH_ASSOC);
        if ($bookingRow && (string)($bookingRow['deposit_status'] ?? '') === 'pending') {
            try {
                ars_booking_mark_deposit_received(
                    $conn,
                    $bookingRow,
                    (float)($existingPayment['amount'] ?? $intent->amount / 100),
                    'online',
                    null,
                    isset($existingPayment['id']) ? (int)$existingPayment['id'] : null
                );
            } catch (Throwable $e) {
                error_log('ARS security deposit Stripe finalize failed: ' . $e->getMessage());
            }
        }
    } else {
        ars_stripe_recalculate_booking_payment($conn, $bookingId);
    }

    $newStatus = strtolower($status);
    if ($newStatus !== '' && $newStatus !== $oldStatus) {
        $bookingStmt = $conn->prepare("SELECT id, company_id, guest_id, booking_number FROM ars_bookings WHERE id = ? LIMIT 1");
        $bookingStmt->execute([$bookingId]);
        $booking = $bookingStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($booking) {
            $route = '/guest/bookings/' . (int)$booking['id'];
            if ($newStatus === 'succeeded') {
                ars_guest_notification_create($conn, [
                    'company_id' => (int)$booking['company_id'],
                    'guest_id' => (int)$booking['guest_id'],
                    'booking_id' => (int)$booking['id'],
                    'payment_id' => isset($existingPayment['id']) ? (int)$existingPayment['id'] : null,
                    'event_type' => 'payment_succeeded',
                    'title' => 'Payment successful',
                    'message' => 'We received your payment for booking ' . (string)$booking['booking_number'] . '.',
                    'cta_route' => $route,
                    'meta' => [
                        'payment_intent_id' => $intentId,
                        'event_type' => $eventType,
                        'amount' => isset($existingPayment['amount']) ? number_format((float)$existingPayment['amount'], 2, '.', '') : null,
                    ],
                ]);
                if (isset($existingPayment['id'])) {
                    ars_guest_notification_create($conn, [
                        'company_id' => (int)$booking['company_id'],
                        'guest_id' => (int)$booking['guest_id'],
                        'booking_id' => (int)$booking['id'],
                        'payment_id' => (int)$existingPayment['id'],
                        'event_type' => 'receipt_available',
                        'title' => 'Receipt available',
                        'message' => 'Your payment receipt is now available.',
                        'cta_route' => $route,
                        'meta' => ['entity_type' => 'receipt', 'entity_id' => (int)$existingPayment['id']],
                    ]);
                }
            } elseif (in_array($newStatus, ['requires_payment_method', 'failed', 'canceled'], true)) {
                ars_guest_notification_create($conn, [
                    'company_id' => (int)$booking['company_id'],
                    'guest_id' => (int)$booking['guest_id'],
                    'booking_id' => (int)$booking['id'],
                    'payment_id' => isset($existingPayment['id']) ? (int)$existingPayment['id'] : null,
                    'event_type' => 'payment_failed',
                    'title' => 'Payment attempt failed',
                    'message' => 'Your payment attempt for booking ' . (string)$booking['booking_number'] . ' was not completed.',
                    'cta_route' => $route,
                    'meta' => [
                        'payment_intent_id' => $intentId,
                        'event_type' => $eventType,
                        'failure_message' => $failureMessage,
                    ],
                ]);
            }
        }
    }

    if ($status === 'succeeded') {
        $settings = getArsSettings($conn, null);
        if (!empty($settings['stripe_auto_confirm'])) {
            $stmt = $conn->prepare("UPDATE ars_bookings SET status = 'confirmed', expires_at = NULL, updated_at = NOW() WHERE id = ? AND status = 'pending'");
            $stmt->execute([$bookingId]);
            if ($stmt->rowCount() > 0) {
                $bookingStmt = $conn->prepare("SELECT * FROM ars_bookings WHERE id = ? LIMIT 1");
                $bookingStmt->execute([$bookingId]);
                $booking = $bookingStmt->fetch(PDO::FETCH_ASSOC) ?: null;
                if ($booking) {
                    ars_guest_notification_create($conn, [
                        'company_id' => (int)$booking['company_id'],
                        'guest_id' => (int)$booking['guest_id'],
                        'booking_id' => (int)$booking['id'],
                        'event_type' => 'booking_confirmed',
                        'title' => 'Booking confirmed',
                        'message' => 'Your booking ' . (string)$booking['booking_number'] . ' is now confirmed.',
                        'cta_route' => '/guest/bookings/' . (int)$booking['id'],
                    ]);
                    ars_guest_notification_create($conn, [
                        'company_id' => (int)$booking['company_id'],
                        'guest_id' => (int)$booking['guest_id'],
                        'booking_id' => (int)$booking['id'],
                        'event_type' => 'document_available',
                        'title' => 'Booking document available',
                        'message' => 'Your booking confirmation document is now available.',
                        'cta_route' => '/guest/bookings/' . (int)$booking['id'],
                        'meta' => ['entity_type' => 'document', 'entity_id' => (int)$booking['id']],
                    ]);
                    ars_guest_notifications_schedule_booking_reminders($conn, $booking);
                }
            }
        }
    }
}

function ars_stripe_handle_charge_refunded(PDO $conn, object $charge): void {
    $intentId = is_string($charge->payment_intent ?? null) ? (string)$charge->payment_intent : '';
    if ($intentId === '') return;

    $amountRefunded = ((float)($charge->amount_refunded ?? 0)) / 100;
    $status = !empty($charge->refunded) ? 'refunded' : 'partially_refunded';

    $stmt = $conn->prepare("SELECT booking_id, amount FROM ars_booking_payments WHERE gateway_payment_intent_id = ? LIMIT 1");
    $stmt->execute([$intentId]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$payment) return;

    $conn->prepare("
        UPDATE ars_booking_payments
        SET amount_refunded = ?, refunded_at = NOW(), gateway_status = ?, raw_payload_json = ?, updated_at = NOW()
        WHERE gateway_payment_intent_id = ?
    ")->execute([
        min((float)$payment['amount'], $amountRefunded),
        $status,
        json_encode(method_exists($charge, 'toArray') ? $charge->toArray() : $charge, JSON_UNESCAPED_SLASHES),
        $intentId,
    ]);

    ars_stripe_recalculate_booking_payment($conn, (int)$payment['booking_id']);
}

function ars_stripe_process_webhook(PDO $conn, string $payload, string $signature, bool $livemode): array {
    ars_stripe_ensure_schema($conn);
    ars_stripe_require_sdk();
    $companyId = getArsCompanyId($conn);
    $settings = getArsSettings($conn, $companyId);
    $secret = ars_stripe_webhook_secret($settings, $livemode);
    if ($secret === '') {
        throw new RuntimeException('Stripe webhook secret is not configured for this mode.');
    }

    $event = Webhook::constructEvent($payload, $signature, $secret);
    $eventId = (string)$event->id;
    $eventType = (string)$event->type;
    $object = $event->data->object;
    $intentId = '';
    if (isset($object->id) && str_starts_with((string)$object->id, 'pi_')) {
        $intentId = (string)$object->id;
    } elseif (isset($object->payment_intent) && is_string($object->payment_intent)) {
        $intentId = (string)$object->payment_intent;
    }

    $bookingId = 0;
    if (isset($object->metadata->booking_id)) {
        $bookingId = (int)$object->metadata->booking_id;
    } elseif ($intentId !== '') {
        $stmt = $conn->prepare("SELECT booking_id FROM ars_booking_payments WHERE gateway_payment_intent_id = ? LIMIT 1");
        $stmt->execute([$intentId]);
        $bookingId = (int)$stmt->fetchColumn();
    }

    $insert = $conn->prepare("
        INSERT IGNORE INTO ars_stripe_events
            (company_id, booking_id, event_id, event_type, livemode, payment_intent_id, payload_json)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $insert->execute([
        $companyId,
        $bookingId ?: null,
        $eventId,
        $eventType,
        $livemode ? 1 : 0,
        $intentId ?: null,
        $payload,
    ]);
    if ($insert->rowCount() === 0) {
        return ['ok' => true, 'duplicate' => true];
    }

    try {
        if (in_array($eventType, ['payment_intent.succeeded', 'payment_intent.payment_failed', 'payment_intent.canceled', 'payment_intent.processing'], true)) {
            ars_stripe_handle_payment_intent($conn, $object, $eventType);
        } elseif (in_array($eventType, ['charge.refunded', 'charge.refund.updated'], true)) {
            ars_stripe_handle_charge_refunded($conn, $object);
        }
        $conn->prepare("UPDATE ars_stripe_events SET status = 'processed', processed_at = NOW() WHERE event_id = ?")
            ->execute([$eventId]);
        return ['ok' => true, 'event_id' => $eventId, 'event_type' => $eventType];
    } catch (Throwable $e) {
        $conn->prepare("UPDATE ars_stripe_events SET status = 'failed', error_message = ? WHERE event_id = ?")
            ->execute([$e->getMessage(), $eventId]);
        throw $e;
    }
}
