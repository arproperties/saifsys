<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/sm_expense_service.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../expenses.php');
    exit;
}

csrf_verify();
$id = (int)($_POST['id'] ?? 0);
$result = sm_expense_approve_and_post($conn, $id, $_SESSION['user_id'] ?? null);

if ($result['success']) {
    header('Location: ../expenses.php?ok=approved');
} else {
    header('Location: ../expenses.php?rev=0&msg=' . urlencode($result['message']));
}
exit;
