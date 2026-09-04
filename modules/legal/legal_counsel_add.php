<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/includes/legal_helper.php';

require_login();
require_module_access($conn, MODULE_LEGAL);
legal_require_access($conn);

$currentCompanyId = current_company_id($conn) ?: 1;
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $name = trim($_POST['name'] ?? '');
    if ($name === '') { $error = 'Name is required.'; }
    else {
        $conn->prepare("INSERT INTO re_legal_counsel (company_id, counsel_type, name, contact_person, email, phone, license_number, specialization, hourly_rate, notes, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,NOW(),NOW())")
             ->execute([$currentCompanyId, $_POST['counsel_type'] ?? 'firm', $name, trim($_POST['contact_person']??'')?:null, trim($_POST['email']??'')?:null, trim($_POST['phone']??'')?:null, trim($_POST['license_number']??'')?:null, trim($_POST['specialization']??'')?:null, $_POST['hourly_rate']!==''?(float)$_POST['hourly_rate']:null, trim($_POST['notes']??'')?:null]);
        $_SESSION['success'] = 'Counsel added.';
        header('Location: legal_counsel.php'); exit;
    }
}
$pageTitle = 'Add Counsel';
require_once __DIR__ . '/includes/legal_layout_header.php';
include __DIR__ . '/includes/legal_counsel_form.php';
require_once __DIR__ . '/includes/legal_layout_footer.php';
