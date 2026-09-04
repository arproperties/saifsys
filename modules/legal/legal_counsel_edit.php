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
$id = (int)($_GET['id'] ?? 0);
$stmt = $conn->prepare("SELECT * FROM re_legal_counsel WHERE id = ? AND company_id = ?");
$stmt->execute([$id, $currentCompanyId]);
$counsel = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$counsel) { header('Location: legal_counsel.php'); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $name = trim($_POST['name'] ?? '');
    if ($name === '') { $error = 'Name is required.'; }
    else {
        $conn->prepare("UPDATE re_legal_counsel SET counsel_type=?, name=?, contact_person=?, email=?, phone=?, license_number=?, specialization=?, hourly_rate=?, notes=?, is_active=?, updated_at=NOW() WHERE id=? AND company_id=?")
             ->execute([$_POST['counsel_type']??'firm', $name, trim($_POST['contact_person']??'')?:null, trim($_POST['email']??'')?:null, trim($_POST['phone']??'')?:null, trim($_POST['license_number']??'')?:null, trim($_POST['specialization']??'')?:null, $_POST['hourly_rate']!==''?(float)$_POST['hourly_rate']:null, trim($_POST['notes']??'')?:null, !empty($_POST['is_active'])?1:0, $id, $currentCompanyId]);
        $_SESSION['success'] = 'Counsel updated.';
        header('Location: legal_counsel.php'); exit;
    }
    $counsel = array_merge($counsel, $_POST);
}
$pageTitle = 'Edit Counsel';
require_once __DIR__ . '/includes/legal_layout_header.php';
include __DIR__ . '/includes/legal_counsel_form.php';
require_once __DIR__ . '/includes/legal_layout_footer.php';
