<?php
/**
 * Barber tablet POS — services, cart, cash/card checkout.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/url_helper.php';
require_once __DIR__ . '/../../includes/barber/barber_helpers.php';

require_login();
require_module_access($conn, MODULE_BARBER);
require_barber_pos_department($conn);
require_permission('barber_pos.view', MODULE_BARBER, $conn);
ensure_current_company_supports_module($conn, MODULE_BARBER);

$brand = getBrandSettings($conn);
$canPost = has_permission('barber_pos.post', MODULE_BARBER, $conn);
$appBase = get_application_web_root();
$companyId = (int)(current_company_id($conn) ?: 0);
$userId = (int)current_user_id();

function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

$tableMissing = false;
$flashErr = '';
$flashOk = $_SESSION['barber_pos_flash_ok'] ?? '';
unset($_SESSION['barber_pos_flash_ok']);

try {
    $conn->query('SELECT 1 FROM barber_staff LIMIT 1');
} catch (Throwable $e) {
    $tableMissing = true;
}

if (!$tableMissing && $companyId > 0 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_verify();
        $action = $_POST['action'] ?? '';

        if ($action === 'set_barber') {
            require_permission('barber_pos.view', MODULE_BARBER, $conn);
            $bid = (int)($_POST['barber_staff_id'] ?? 0);
            if ($bid > 0) {
                $chk = $conn->prepare('SELECT id FROM barber_staff WHERE id = ? AND company_id = ? AND is_active = 1');
                $chk->execute([$bid, $companyId]);
                if ($chk->fetch()) {
                    barber_active_staff_set($companyId, $bid);
                }
            }
            header('Location: ' . $appBase . '/modules/barber/pos.php');
            exit;
        }

        if ($action === 'clear_barber') {
            require_permission('barber_pos.view', MODULE_BARBER, $conn);
            barber_active_staff_set($companyId, null);
            header('Location: ' . $appBase . '/modules/barber/pos.php');
            exit;
        }

        if ($action === 'add_service') {
            require_permission('barber_pos.post', MODULE_BARBER, $conn);
            $sid = (int)($_POST['service_id'] ?? 0);
            if ($sid > 0) {
                $st = $conn->prepare('SELECT id, name, price FROM barber_services WHERE id = ? AND company_id = ? AND is_active = 1');
                $st->execute([$sid, $companyId]);
                $svc = $st->fetch(PDO::FETCH_ASSOC);
                if ($svc) {
                    $cart = barber_cart_get($companyId);
                    $found = false;
                    foreach ($cart as &$row) {
                        if ((int)$row['service_id'] === $sid) {
                            $row['qty'] = (float)$row['qty'] + 1;
                            $row['line_total'] = round((float)$row['unit_price'] * (float)$row['qty'], 2);
                            $found = true;
                            break;
                        }
                    }
                    unset($row);
                    if (!$found) {
                        $cart[] = [
                            'service_id' => (int)$svc['id'],
                            'name' => (string)$svc['name'],
                            'unit_price' => (float)$svc['price'],
                            'qty' => 1.0,
                            'line_total' => (float)$svc['price'],
                        ];
                    }
                    barber_cart_set($companyId, $cart);
                }
            }
            header('Location: ' . $appBase . '/modules/barber/pos.php');
            exit;
        }

        if ($action === 'remove_line') {
            require_permission('barber_pos.post', MODULE_BARBER, $conn);
            $idx = (int)($_POST['line_index'] ?? -1);
            $cart = barber_cart_get($companyId);
            if ($idx >= 0 && $idx < count($cart)) {
                array_splice($cart, $idx, 1);
                barber_cart_set($companyId, $cart);
            }
            header('Location: ' . $appBase . '/modules/barber/pos.php');
            exit;
        }

        if ($action === 'clear_cart') {
            require_permission('barber_pos.post', MODULE_BARBER, $conn);
            barber_cart_clear($companyId);
            header('Location: ' . $appBase . '/modules/barber/pos.php');
            exit;
        }

        if ($action === 'checkout') {
            require_permission('barber_pos.post', MODULE_BARBER, $conn);
            $barberId = barber_active_staff_get($companyId);
            if (!$barberId) {
                throw new RuntimeException('Select a barber first.');
            }
            $cart = barber_cart_get($companyId);
            if (!$cart) {
                throw new RuntimeException('Add at least one service.');
            }
            $pay = $_POST['payment_method'] ?? '';
            if (!in_array($pay, ['cash', 'card'], true)) {
                throw new RuntimeException('Choose cash or card.');
            }
            $subtotal = 0.0;
            foreach ($cart as $ln) {
                $subtotal += (float)($ln['line_total'] ?? 0);
            }
            $subtotal = round($subtotal, 2);
            $saleNo = barber_allocate_sale_number($conn, $companyId);
            $conn->beginTransaction();
            $ins = $conn->prepare('
                INSERT INTO barber_sales (company_id, sale_number, barber_staff_id, sale_at, payment_method, subtotal, total, created_by)
                VALUES (?,?,?,NOW(),?,?,?,?)
            ');
            $ins->execute([$companyId, $saleNo, $barberId, $pay, $subtotal, $subtotal, $userId ?: null]);
            $saleId = (int)$conn->lastInsertId();
            $insL = $conn->prepare('
                INSERT INTO barber_sale_lines (sale_id, service_id, service_name_snapshot, unit_price, qty, line_total)
                VALUES (?,?,?,?,?,?)
            ');
            foreach ($cart as $ln) {
                $insL->execute([
                    $saleId,
                    (int)$ln['service_id'],
                    (string)$ln['name'],
                    (float)$ln['unit_price'],
                    (float)$ln['qty'],
                    (float)$ln['line_total'],
                ]);
            }
            $conn->commit();
            barber_cart_clear($companyId);
            $_SESSION['barber_pos_flash_ok'] = 'Sale ' . $saleNo . ' recorded.';
            header('Location: ' . $appBase . '/modules/barber/pos.php');
            exit;
        }
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        $flashErr = $e->getMessage();
    }
}

$services = [];
$barbers = [];
$mappedBarberId = null;
$activeBarberId = null;

if (!$tableMissing && $companyId > 0) {
    $st = $conn->prepare('SELECT id, name, price, sort_order FROM barber_services WHERE company_id = ? AND is_active = 1 ORDER BY sort_order ASC, name ASC');
    $st->execute([$companyId]);
    $services = $st->fetchAll(PDO::FETCH_ASSOC);

    $st = $conn->prepare('SELECT id, display_name, user_id FROM barber_staff WHERE company_id = ? AND is_active = 1 ORDER BY sort_order ASC, display_name ASC');
    $st->execute([$companyId]);
    $barbers = $st->fetchAll(PDO::FETCH_ASSOC);

    if ($userId > 0) {
        $mappedBarberId = barber_resolve_mapped_staff_id($conn, $companyId, $userId);
    }
    $activeBarberId = barber_active_staff_get($companyId);
    if ($activeBarberId === null && $mappedBarberId !== null) {
        barber_active_staff_set($companyId, $mappedBarberId);
        $activeBarberId = $mappedBarberId;
    }
}

$cart = $tableMissing ? [] : barber_cart_get($companyId);
$cartTotal = 0.0;
foreach ($cart as $ln) {
    $cartTotal += (float)($ln['line_total'] ?? 0);
}
$cartTotal = round($cartTotal, 2);

$activeBarberName = '';
if ($activeBarberId) {
    foreach ($barbers as $b) {
        if ((int)$b['id'] === (int)$activeBarberId) {
            $activeBarberName = (string)$b['display_name'];
            break;
        }
    }
}

$pageTitle = 'Barber POS';
$brandSpot = $brand['primary_color'] ?? '#c9a227';
if (!is_string($brandSpot) || !preg_match('/^#[0-9A-Fa-f]{6}$/', $brandSpot)) {
    $brandSpot = '#c9a227';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?= h($pageTitle) ?> — <?= h($brand['system_name'] ?? 'POS') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,400&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <style>
        :root {
            --bp-spot: <?= h($brandSpot) ?>;
            --bp-bg0: #0c0e12;
            --bp-bg1: #12151c;
            --bp-elev: #1a1f28;
            --bp-line: rgba(255, 255, 255, 0.08);
            --bp-line2: rgba(255, 255, 255, 0.12);
            --bp-text: #f4f4f5;
            --bp-muted: #9ca3af;
            --bp-gold: #e8c547;
            --bp-radius: 20px;
            --bp-radius-sm: 14px;
            --bp-tap: 48px;
        }
        *, *::before, *::after { box-sizing: border-box; }
        html { -webkit-tap-highlight-color: transparent; }
        body {
            margin: 0;
            min-height: 100dvh;
            font-family: "DM Sans", system-ui, -apple-system, sans-serif;
            color: var(--bp-text);
            background-color: var(--bp-bg0);
            background-image:
                radial-gradient(1200px 600px at 10% -10%, color-mix(in srgb, var(--bp-spot) 22%, transparent), transparent 55%),
                radial-gradient(900px 500px at 100% 0%, rgba(99, 102, 241, 0.12), transparent 50%),
                linear-gradient(180deg, var(--bp-bg1) 0%, var(--bp-bg0) 100%);
            padding: env(safe-area-inset-top) env(safe-area-inset-right) env(safe-area-inset-bottom) env(safe-area-inset-left);
        }
        .pos-shell { min-height: 100dvh; min-height: 100svh; display: flex; flex-direction: column; }
        .pos-header {
            flex-shrink: 0;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            padding: 0.85rem 1.25rem;
            background: rgba(12, 14, 18, 0.72);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border-bottom: 1px solid var(--bp-line);
            position: sticky;
            top: 0;
            z-index: 1000;
        }
        .pos-brand {
            display: flex;
            align-items: center;
            gap: 0.85rem;
        }
        .pos-brand-mark {
            width: 44px;
            height: 44px;
            border-radius: 14px;
            display: grid;
            place-items: center;
            background: linear-gradient(145deg, color-mix(in srgb, var(--bp-spot) 35%, #1a1a1a), #252830);
            border: 1px solid var(--bp-line2);
            color: var(--bp-gold);
            font-size: 1.25rem;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.35);
        }
        .pos-brand-text .pos-title { font-weight: 700; font-size: 1.1rem; letter-spacing: -0.02em; }
        .pos-brand-text .pos-sub { font-size: 0.75rem; color: var(--bp-muted); margin-top: 0.1rem; }
        .pos-header-actions { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; }
        .btn-pos-ghost {
            border: 1px solid var(--bp-line2);
            color: var(--bp-text);
            background: rgba(255, 255, 255, 0.04);
            border-radius: 12px;
            padding: 0.55rem 1rem;
            min-height: 44px;
            display: inline-flex;
            align-items: center;
            font-size: 0.875rem;
            font-weight: 500;
            text-decoration: none;
            transition: background 0.15s, border-color 0.15s, transform 0.1s;
            touch-action: manipulation;
        }
        .btn-pos-ghost:hover { background: rgba(255, 255, 255, 0.08); color: #fff; border-color: rgba(255, 255, 255, 0.2); }
        .btn-pos-ghost:active { transform: scale(0.98); }
        .pos-main {
            flex: 1;
            width: 100%;
            max-width: 1400px;
            margin: 0 auto;
            padding: 0.75rem 0.85rem max(1rem, env(safe-area-inset-bottom));
            min-height: 0;
            display: flex;
            flex-direction: column;
        }
        /* Fills space between header and bottom on tablets — scroll services inside */
        .pos-floor {
            flex: 1;
            min-height: 0;
            display: flex;
            flex-direction: column;
        }
        .pos-toast {
            border-radius: var(--bp-radius-sm);
            padding: 0.85rem 1rem;
            margin-bottom: 1rem;
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            border: 1px solid transparent;
            animation: posToastIn 0.35s ease;
        }
        @keyframes posToastIn {
            from { opacity: 0; transform: translateY(-6px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .pos-toast--ok {
            background: rgba(34, 197, 94, 0.12);
            border-color: rgba(34, 197, 94, 0.35);
            color: #bbf7d0;
        }
        .pos-toast--err {
            background: rgba(248, 113, 113, 0.1);
            border-color: rgba(248, 113, 113, 0.35);
            color: #fecaca;
        }
        .pos-toast i { font-size: 1.25rem; margin-top: 0.1rem; }
        .pos-section-title {
            font-size: 0.9375rem;
            font-weight: 600;
            letter-spacing: -0.02em;
            color: var(--bp-text);
            margin-bottom: 0.65rem;
        }
        .pos-section-hint {
            font-size: 0.8125rem;
            color: var(--bp-muted);
            margin: -0.35rem 0 0.85rem;
            font-weight: 400;
        }
        .picker-intro { color: var(--bp-muted); font-size: 0.9rem; max-width: 36rem; line-height: 1.5; margin-bottom: 1.25rem; }
        .barber-card-wrap form { height: 100%; }
        .barber-card {
            width: 100%;
            min-height: clamp(7.5rem, 20vh, 8.5rem);
            border: 1px solid var(--bp-line);
            border-radius: var(--bp-radius);
            background: linear-gradient(165deg, rgba(255, 255, 255, 0.06) 0%, rgba(255, 255, 255, 0.02) 100%);
            color: var(--bp-text);
            padding: 1.15rem 1rem;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 0.35rem;
            transition: border-color 0.2s, box-shadow 0.2s, transform 0.15s;
            cursor: pointer;
            touch-action: manipulation;
        }
        .barber-card:hover {
            border-color: color-mix(in srgb, var(--bp-spot) 55%, var(--bp-line));
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.35), 0 0 0 1px color-mix(in srgb, var(--bp-spot) 25%, transparent);
            transform: translateY(-2px);
        }
        .barber-card:active { transform: scale(0.99); }
        .barber-card-avatar {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--bp-spot), #3d3520);
            display: grid;
            place-items: center;
            font-weight: 700;
            font-size: 1rem;
            color: #fff;
            border: 2px solid rgba(255, 255, 255, 0.15);
        }
        .barber-card-name { font-weight: 600; font-size: 1.05rem; text-align: center; }
        .barber-card-badge {
            font-size: 0.7rem;
            font-weight: 600;
            padding: 0.2rem 0.5rem;
            border-radius: 999px;
            background: color-mix(in srgb, var(--bp-gold) 22%, transparent);
            color: var(--bp-gold);
        }
        .barber-strip {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1.25rem;
            padding: 0.65rem 1rem;
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid var(--bp-line);
            border-radius: var(--bp-radius-sm);
        }
        .barber-strip-label { font-size: 0.8125rem; color: var(--bp-muted); }
        .barber-strip-name {
            font-weight: 600;
            padding: 0.35rem 0.85rem;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid var(--bp-line2);
        }
        .barber-strip-change {
            margin-left: auto;
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--bp-gold);
            background: rgba(232, 197, 71, 0.1);
            border: 1px solid rgba(232, 197, 71, 0.25);
            border-radius: 10px;
            padding: 0.5rem 0.95rem;
            min-height: 44px;
            cursor: pointer;
            touch-action: manipulation;
        }
        .barber-strip-change:active { transform: scale(0.98); }
        .pos-grid {
            flex: 1;
            min-height: 0;
            display: grid;
            grid-template-columns: 1fr;
            gap: 1rem;
            align-items: stretch;
        }
        @media (min-width: 992px) {
            .pos-grid { grid-template-columns: 1fr minmax(300px, 380px); align-items: stretch; }
        }
        .pos-grid > * { min-height: 0; }
        .svc-panel {
            display: flex;
            flex-direction: column;
            min-height: 0;
            flex: 1;
        }
        .svc-scroll {
            flex: 1;
            min-height: 140px;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
            padding-bottom: 0.25rem;
            margin: 0 -0.15rem;
            padding-left: 0.15rem;
            padding-right: 0.15rem;
        }
        .svc-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.85rem;
        }
        /* Tablet: two larger columns for comfortable taps */
        @media (min-width: 600px) {
            .svc-grid { gap: 1rem; }
        }
        @media (min-width: 1200px) {
            .svc-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        }
        .svc-card {
            width: 100%;
            min-height: clamp(7.25rem, 18vh, 9.5rem);
            border-radius: calc(var(--bp-radius-sm) + 2px);
            border: 1px solid var(--bp-line);
            background: linear-gradient(165deg, rgba(255, 255, 255, 0.09) 0%, rgba(22, 26, 34, 0.92) 55%, rgba(18, 21, 28, 0.98) 100%);
            color: var(--bp-text);
            padding: 1.1rem 0.9rem 1rem;
            display: flex;
            flex-direction: column;
            align-items: stretch;
            justify-content: space-between;
            text-align: center;
            transition: border-color 0.2s, box-shadow 0.2s, transform 0.1s;
            cursor: pointer;
            touch-action: manipulation;
            -webkit-tap-highlight-color: transparent;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
        }
        .svc-card:hover:not(:disabled) {
            border-color: color-mix(in srgb, var(--bp-spot) 45%, var(--bp-line));
            box-shadow: 0 12px 32px rgba(0, 0, 0, 0.35), 0 0 0 1px color-mix(in srgb, var(--bp-spot) 18%, transparent);
        }
        .svc-card:active:not(:disabled) { transform: scale(0.985); }
        .svc-card:disabled { opacity: 0.45; cursor: not-allowed; }
        .svc-name {
            font-size: clamp(1.05rem, 2.5vw, 1.25rem);
            font-weight: 700;
            line-height: 1.2;
            letter-spacing: -0.02em;
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0.25rem 0;
        }
        .svc-price-row {
            margin-top: auto;
            padding-top: 0.65rem;
            border-top: 1px solid var(--bp-line);
        }
        .svc-price {
            font-size: clamp(1.2rem, 3.2vw, 1.45rem);
            font-weight: 800;
            color: var(--bp-gold);
            letter-spacing: -0.03em;
        }
        .svc-currency {
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--bp-muted);
            margin-left: 0.2rem;
        }
        .cart-sheet {
            border-radius: var(--bp-radius);
            border: 1px solid var(--bp-line);
            background: linear-gradient(180deg, rgba(30, 35, 45, 0.95) 0%, rgba(18, 21, 28, 0.98) 100%);
            box-shadow: 0 24px 48px rgba(0, 0, 0, 0.4);
            padding: 1.15rem 1.1rem;
            position: sticky;
            top: 76px;
            align-self: start;
            max-height: min(70dvh, 100%);
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
        }
        @media (max-width: 991.98px) {
            .cart-sheet {
                position: relative;
                top: auto;
                max-height: none;
            }
        }
        .cart-sheet-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem; }
        .cart-sheet-head strong { font-size: 1rem; letter-spacing: -0.02em; }
        .btn-clear-cart {
            font-size: 0.8125rem;
            font-weight: 600;
            padding: 0.45rem 0.75rem;
            min-height: 40px;
            border-radius: 10px;
            border: 1px solid rgba(248, 113, 113, 0.4);
            background: rgba(248, 113, 113, 0.08);
            color: #fecaca;
            touch-action: manipulation;
        }
        .cart-empty {
            text-align: center;
            padding: 1.5rem 0.5rem;
            color: var(--bp-muted);
            font-size: 0.9rem;
        }
        .cart-empty i { font-size: 2rem; opacity: 0.35; display: block; margin-bottom: 0.5rem; }
        .cart-line {
            display: flex;
            justify-content: space-between;
            gap: 0.75rem;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--bp-line);
            font-size: 0.9rem;
        }
        .cart-line:last-of-type { border-bottom: none; }
        .cart-line-name { font-weight: 600; }
        .cart-line-meta { font-size: 0.75rem; color: var(--bp-muted); margin-top: 0.15rem; }
        .cart-line-total { font-weight: 700; text-align: right; white-space: nowrap; }
        .btn-remove-line {
            font-size: 0.8125rem;
            font-weight: 600;
            color: #fecaca;
            background: rgba(248, 113, 113, 0.12);
            border: 1px solid rgba(248, 113, 113, 0.25);
            border-radius: 8px;
            padding: 0.35rem 0.65rem;
            margin-top: 0.45rem;
            min-height: 36px;
            cursor: pointer;
            touch-action: manipulation;
        }
        .cart-total-row {
            margin-top: 0.75rem;
            padding-top: 1rem;
            border-top: 1px solid var(--bp-line2);
            display: flex;
            justify-content: space-between;
            align-items: baseline;
        }
        .cart-total-row .label { color: var(--bp-muted); font-size: 0.8125rem; }
        .cart-total-row .amount { font-size: 1.65rem; font-weight: 700; letter-spacing: -0.03em; }
        .cart-total-row .cur { font-size: 0.85rem; font-weight: 600; color: var(--bp-muted); margin-left: 0.25rem; }
        .pay-grid { display: grid; gap: 0.75rem; margin-top: 1rem; }
        .pay-btn {
            min-height: 56px;
            border-radius: var(--bp-radius-sm);
            font-weight: 700;
            font-size: 1.05rem;
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            transition: transform 0.12s, filter 0.15s;
            touch-action: manipulation;
        }
        @media (min-width: 600px) {
            .pay-btn { min-height: 64px; font-size: 1.15rem; }
        }
        .pay-btn:active:not(:disabled) { transform: scale(0.99); }
        .pay-btn:disabled { opacity: 0.45; cursor: not-allowed; filter: grayscale(0.3); }
        .pay-btn--cash {
            background: linear-gradient(180deg, #22c55e, #16a34a);
            color: #fff;
            box-shadow: 0 8px 20px rgba(34, 197, 94, 0.25);
        }
        .pay-btn--card {
            background: linear-gradient(180deg, #6366f1, #4f46e5);
            color: #fff;
            box-shadow: 0 8px 20px rgba(99, 102, 241, 0.25);
        }
        .pay-btn small { font-weight: 500; opacity: 0.9; font-size: 0.75rem; }
        .pos-empty-services {
            padding: 1.25rem;
            border-radius: var(--bp-radius-sm);
            border: 1px dashed rgba(234, 179, 8, 0.35);
            background: rgba(234, 179, 8, 0.06);
            color: #fde68a;
            font-size: 0.9rem;
        }
        .alert-sys { border-radius: var(--bp-radius-sm); }
    </style>
</head>
<body>
<div class="pos-shell">
    <header class="pos-header">
        <div class="pos-brand">
            <div class="pos-brand-mark" aria-hidden="true"><i class="bi bi-scissors"></i></div>
            <div class="pos-brand-text">
                <div class="pos-title text-white">Barber POS</div>
                <div class="pos-sub"><?= $companyId ? h($brand['system_name'] ?? '') : 'Select a company' ?></div>
            </div>
        </div>
        <div class="pos-header-actions">
            <?php if (has_permission('barber_backoffice.view', MODULE_BARBER, $conn)): ?>
                <a href="<?= h($appBase) ?>/modules/barber/dashboard.php" class="btn-pos-ghost"><i class="bi bi-speedometer2 me-1"></i>Back office</a>
            <?php endif; ?>
            <a href="<?= h($appBase) ?>/select-module.php" class="btn-pos-ghost"><i class="bi bi-grid-3x3-gap me-1"></i>Modules</a>
        </div>
    </header>

    <main class="pos-main">
<?php if ($tableMissing): ?>
    <div class="alert alert-warning alert-sys">Barber tables are missing. Run <code>migrations/barber_phase_b1_schema.sql</code> then <code>migrations/barber_module_rbac.sql</code>.</div>
<?php elseif ($companyId <= 0): ?>
    <div class="alert alert-danger alert-sys">No company selected.</div>
<?php else: ?>

    <?php if ($flashErr): ?>
        <div class="pos-toast pos-toast--err" role="alert"><i class="bi bi-exclamation-octagon-fill"></i><div><?= h($flashErr) ?></div></div>
    <?php endif; ?>
    <?php if ($flashOk): ?>
        <div class="pos-toast pos-toast--ok" role="status"><i class="bi bi-check-circle-fill"></i><div><?= h($flashOk) ?></div></div>
    <?php endif; ?>

    <?php if (!$activeBarberId): ?>
        <div class="pos-section-title">Who is serving?</div>
        <p class="picker-intro">Tap a name to start. If your user is linked in <strong>Team</strong>, you will be picked automatically next time.</p>
        <div class="row g-3">
            <?php foreach ($barbers as $b): ?>
            <?php
                $dn = trim((string)($b['display_name'] ?: '?'));
                $initial = strtoupper(function_exists('mb_substr') ? mb_substr($dn, 0, 1, 'UTF-8') : substr($dn, 0, 1));
            ?>
            <div class="col-6 col-md-4 col-lg-3 barber-card-wrap">
                <form method="post" class="h-100">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="set_barber">
                    <input type="hidden" name="barber_staff_id" value="<?= (int)$b['id'] ?>">
                    <button type="submit" class="barber-card">
                        <span class="barber-card-avatar"><?= h($initial) ?></span>
                        <span class="barber-card-name"><?= h($b['display_name']) ?></span>
                        <?php if (!empty($b['user_id']) && (int)$b['user_id'] === $userId): ?>
                            <span class="barber-card-badge">Your profile</span>
                        <?php endif; ?>
                    </button>
                </form>
            </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>

        <div class="pos-floor">
        <div class="barber-strip">
            <span class="barber-strip-label">Serving as</span>
            <span class="barber-strip-name"><?= h($activeBarberName) ?></span>
            <form method="post" class="m-0 ms-lg-auto">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="clear_barber">
                <button type="submit" class="barber-strip-change">Change barber</button>
            </form>
        </div>
        <?php if (!$canPost): ?>
            <div class="pos-toast pos-toast--err mb-3" role="note" style="border-color:rgba(234,179,8,.4);background:rgba(234,179,8,.1);color:#fde68a;">
                <i class="bi bi-eye"></i>
                <div class="small">View only: you cannot add services or check out. Ask an admin for <strong>barber_pos.post</strong>.</div>
            </div>
        <?php endif; ?>

        <div class="pos-grid">
            <div class="svc-panel">
                <div class="pos-section-title">Pick a service</div>
                <p class="pos-section-hint">Tap a tile to add it to the cart.</p>
                <div class="svc-scroll">
                <div class="svc-grid">
                    <?php foreach ($services as $s): ?>
                    <form method="post">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="action" value="add_service">
                        <input type="hidden" name="service_id" value="<?= (int)$s['id'] ?>">
                        <button type="submit" class="svc-card"<?= $canPost ? '' : ' disabled' ?> aria-label="Add <?= h($s['name']) ?> to cart">
                            <span class="svc-name"><?= h($s['name']) ?></span>
                            <div class="svc-price-row">
                                <span class="svc-price"><?= number_format((float)$s['price'], 2) ?><span class="svc-currency">AED</span></span>
                            </div>
                        </button>
                    </form>
                    <?php endforeach; ?>
                </div>
                </div>
                <?php if (empty($services)): ?>
                    <div class="pos-empty-services mt-3"><i class="bi bi-inbox me-2"></i>No active services. Add them under <strong>Back office → Services</strong>.</div>
                <?php endif; ?>
            </div>
            <aside class="cart-sheet">
                <div class="cart-sheet-head">
                    <strong>Cart</strong>
                    <?php if ($cart && $canPost): ?>
                    <form method="post" class="m-0" onsubmit="return confirm('Clear all lines?');">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="action" value="clear_cart">
                        <button type="submit" class="btn-clear-cart">Clear</button>
                    </form>
                    <?php endif; ?>
                </div>
                <?php if (!$cart): ?>
                    <div class="cart-empty">
                        <i class="bi bi-bag"></i>
                        Cart is empty.<br><span class="small">Tap a service to add a line.</span>
                    </div>
                <?php else: ?>
                    <?php foreach ($cart as $i => $ln): ?>
                    <div class="cart-line">
                        <div>
                            <div class="cart-line-name"><?= h($ln['name']) ?> <span class="text-secondary fw-normal">× <?= h((string)(float)$ln['qty']) ?></span></div>
                            <div class="cart-line-meta"><?= number_format((float)$ln['unit_price'], 2) ?> AED each</div>
                            <?php if ($canPost): ?>
                            <form method="post" class="m-0">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="action" value="remove_line">
                                <input type="hidden" name="line_index" value="<?= (int)$i ?>">
                                <button type="submit" class="btn-remove-line">Remove</button>
                            </form>
                            <?php endif; ?>
                        </div>
                        <div class="cart-line-total"><?= number_format((float)$ln['line_total'], 2) ?></div>
                    </div>
                    <?php endforeach; ?>
                    <div class="cart-total-row">
                        <span class="label">Total</span>
                        <div><span class="amount"><?= number_format($cartTotal, 2) ?></span><span class="cur">AED</span></div>
                    </div>
                    <form method="post" class="m-0">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="action" value="checkout">
                        <div class="pay-grid">
                            <button type="submit" name="payment_method" value="cash" class="pay-btn pay-btn--cash"<?= $canPost ? '' : ' disabled' ?> title="<?= $canPost ? '' : 'No permission to post sales' ?>">
                                <i class="bi bi-cash-stack"></i> Cash
                            </button>
                            <button type="submit" name="payment_method" value="card" class="pay-btn pay-btn--card"<?= $canPost ? '' : ' disabled' ?> title="<?= $canPost ? '' : 'No permission to post sales' ?>">
                                <i class="bi bi-credit-card"></i> Card <small>(record only)</small>
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </aside>
        </div>
        </div>
    <?php endif; ?>

<?php endif; ?>
    </main>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
