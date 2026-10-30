<?php
/**
 * Add a guest from the booking wizard (booking_add.php) without leaving it.
 * Same fields and rules as Add guest on guests.php. A guest with the same
 * phone or email is not added twice: the existing guest is returned instead.
 * POST first_name, last_name, email, phone, nationality, id_type, id_number, _csrf.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
header('Content-Type: application/json');
ini_set('display_errors', '0');
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/includes/ars_helpers.php';
require_once __DIR__ . '/includes/ars_permissions.php';

$arsCompanyId = arsPageAuth($conn);
ars_ajax_csrf_verify();

$v = static fn(string $k): string => trim((string)($_POST[$k] ?? ''));
$fn = mb_substr($v('first_name'), 0, 100);
$ln = mb_substr($v('last_name'), 0, 100);
$email = mb_substr($v('email'), 0, 190);
$phone = mb_substr($v('phone'), 0, 50);
$nationality = mb_substr($v('nationality'), 0, 100);
$idType = $v('id_type');
$idNumber = mb_substr($v('id_number'), 0, 100);

if ($fn === '' || $ln === '') {
    echo json_encode(['success' => false, 'error' => 'First and last name are required.']);
    exit;
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'error' => 'Enter a valid email address.']);
    exit;
}
if ($idType !== '' && !in_array($idType, ['emirates_id', 'passport', 'visa', 'driving_license', 'other'], true)) {
    $idType = 'other';
}

$guestOut = static fn(array $g): array => [
    'id' => (int)$g['id'],
    'name' => trim($g['first_name'] . ' ' . $g['last_name']),
    'phone' => (string)($g['phone'] ?? ''),
    'email' => (string)($g['email'] ?? ''),
];

try {
    // Same phone (digits only) or same email: already a guest
    $digits = preg_replace('/\D+/', '', $phone);
    $dup = null;
    // Compared in PHP (last 9 digits of the phone, email case-insensitive), so no DB regex function is needed
    $last9 = strlen($digits) >= 7 ? substr($digits, -9) : '';
    if ($email !== '' || $last9 !== '') {
        $st = $conn->prepare("SELECT id, first_name, last_name, phone, email, is_active FROM ars_guests WHERE company_id = ? AND (phone IS NOT NULL OR email IS NOT NULL) ORDER BY is_active DESC, id");
        $st->execute([$arsCompanyId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $g) {
            $gd = preg_replace('/\D+/', '', (string)$g['phone']);
            if (($email !== '' && strcasecmp((string)$g['email'], $email) === 0) || ($last9 !== '' && strlen($gd) >= 7 && substr($gd, -9) === $last9)) {
                $dup = $g;
                break;
            }
        }
    }
    if ($dup) {
        if (empty($dup['is_active'])) {
            $conn->prepare("UPDATE ars_guests SET is_active = 1 WHERE id = ? AND company_id = ?")->execute([(int)$dup['id'], $arsCompanyId]);
        }
        echo json_encode(['success' => true, 'existing' => true, 'guest' => $guestOut($dup),
            'message' => trim($dup['first_name'] . ' ' . $dup['last_name']) . ' already has this ' . ($email !== '' && strcasecmp((string)$dup['email'], $email) === 0 ? 'email' : 'phone') . ', so that guest is selected.']);
        exit;
    }

    $conn->prepare("
        INSERT INTO ars_guests (company_id, first_name, last_name, email, phone, nationality, id_type, id_number)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([$arsCompanyId, $fn, $ln, $email !== '' ? $email : null, $phone !== '' ? $phone : null,
        $nationality !== '' ? $nationality : null, $idType !== '' ? $idType : null, $idNumber !== '' ? $idNumber : null]);
    $id = (int)$conn->lastInsertId();
    echo json_encode(['success' => true, 'existing' => false,
        'guest' => ['id' => $id, 'name' => $fn . ' ' . $ln, 'phone' => $phone, 'email' => $email],
        'message' => 'Guest added and selected.']);
} catch (Throwable $e) {
    error_log('ARS quick add guest: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Could not add the guest: ' . $e->getMessage()]);
}
