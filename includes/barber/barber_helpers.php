<?php
/**
 * Barber POS — session cart, active barber, sale numbers.
 */

function barber_session_key(int $companyId): string {
    return 'barber_pos_' . $companyId;
}

function barber_cart_get(int $companyId): array {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $k = barber_session_key($companyId);
    return $_SESSION[$k]['cart'] ?? [];
}

function barber_cart_set(int $companyId, array $cart): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $k = barber_session_key($companyId);
    if (!isset($_SESSION[$k]) || !is_array($_SESSION[$k])) {
        $_SESSION[$k] = [];
    }
    $_SESSION[$k]['cart'] = array_values($cart);
}

function barber_cart_clear(int $companyId): void {
    barber_cart_set($companyId, []);
}

function barber_active_staff_get(int $companyId): ?int {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $k = barber_session_key($companyId);
    $id = $_SESSION[$k]['active_staff_id'] ?? null;
    return $id ? (int)$id : null;
}

function barber_active_staff_set(int $companyId, ?int $staffId): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $k = barber_session_key($companyId);
    if (!isset($_SESSION[$k]) || !is_array($_SESSION[$k])) {
        $_SESSION[$k] = [];
    }
    if ($staffId === null || $staffId <= 0) {
        unset($_SESSION[$k]['active_staff_id']);
    } else {
        $_SESSION[$k]['active_staff_id'] = $staffId;
    }
}

function barber_resolve_mapped_staff_id(PDO $conn, int $companyId, int $userId): ?int {
    $st = $conn->prepare('SELECT id FROM barber_staff WHERE company_id = ? AND user_id = ? AND is_active = 1 LIMIT 1');
    $st->execute([$companyId, $userId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ? (int)$row['id'] : null;
}

function barber_allocate_sale_number(PDO $db, int $companyId): string {
    $owns = !$db->inTransaction();
    if ($owns) {
        $db->beginTransaction();
    }
    try {
        $st = $db->prepare('SELECT last_num FROM barber_sale_seq WHERE company_id = ? FOR UPDATE');
        $st->execute([$companyId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $next = (int)$row['last_num'] + 1;
            $db->prepare('UPDATE barber_sale_seq SET last_num = ? WHERE company_id = ?')->execute([$next, $companyId]);
        } else {
            $next = 1;
            $db->prepare('INSERT INTO barber_sale_seq (company_id, last_num) VALUES (?, ?)')->execute([$companyId, $next]);
        }
        $num = 'BS-' . str_pad((string)$next, 5, '0', STR_PAD_LEFT);
        if ($owns) {
            $db->commit();
        }
        return $num;
    } catch (Throwable $e) {
        if ($owns && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}
