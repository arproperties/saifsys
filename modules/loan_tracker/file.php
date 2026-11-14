<?php
/**
 * Loan Tracker — show a document's file.
 *   file.php?id=5    a loan document (attached here, or HR's copy)
 *   file.php?hr=88   an employee's Emirates ID, passport or visa from HR (step 2 preview)
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/url_helper.php';
require_once __DIR__ . '/includes/lt_helper.php';

$companyId = lt_boot($conn);

$abs = '';
$name = 'document';
if (isset($_GET['proof'])) {
    // Proof attached with the loan details
    $q = $conn->prepare("SELECT proof_path FROM lt_loans WHERE id = ? AND company_id = ?");
    $q->execute([(int)$_GET['proof'], $companyId]);
    $p = (string)($q->fetchColumn() ?: '');
    if ($p !== '') {
        $abs = lt_doc_abs_path(['source' => 'upload', 'file_path' => $p]);
        $name = 'loan-proof-' . (int)$_GET['proof'];
    }
} elseif (isset($_GET['ltrep'])) {
    // Proof of a repayment on a Loan Tracker loan
    $q = $conn->prepare("SELECT r.file_path FROM lt_loan_repayments r JOIN lt_loans l ON l.id = r.loan_id WHERE r.id = ? AND l.company_id = ?");
    $q->execute([(int)$_GET['ltrep'], $companyId]);
    $p = (string)($q->fetchColumn() ?: '');
    if ($p !== '') {
        $abs = lt_doc_abs_path(['source' => 'upload', 'file_path' => $p]);
        $name = 'repayment-' . (int)$_GET['ltrep'];
    }
} elseif (isset($_GET['rep'])) {
    // Proof of a repayment recorded from Loan Tracker
    $q = $conn->prepare("SELECT file_path FROM lt_repayment_files WHERE settlement_id = ?");
    $q->execute([(int)$_GET['rep']]);
    $p = (string)($q->fetchColumn() ?: '');
    if ($p !== '') {
        $abs = lt_doc_abs_path(['source' => 'upload', 'file_path' => $p]);
        $name = 'repayment-' . (int)$_GET['rep'];
    }
} elseif (isset($_GET['hr'])) {
    $hrDoc = lt_hr_document($conn, (int)$_GET['hr']);
    if ($hrDoc) {
        $abs = hr_document_file_absolute_path($hrDoc['file_path']);
    }
} else {
    $q = $conn->prepare("SELECT d.* FROM lt_loan_documents d JOIN lt_loans l ON l.id = d.loan_id WHERE d.id = ? AND l.company_id = ?");
    $q->execute([(int)($_GET['id'] ?? 0), $companyId]);
    $doc = $q->fetch(PDO::FETCH_ASSOC);
    if ($doc) {
        $abs = lt_doc_abs_path($doc);
        $name = preg_replace('/[^A-Za-z0-9 _.-]/', '', $doc['title']) ?: 'document';
    }
}

if ($abs === '' || !is_file($abs)) {
    http_response_code(404);
    exit('Not found');
}
$type = (new finfo(FILEINFO_MIME_TYPE))->file($abs);
if (!isset(LT_FILE_TYPES[$type])) {
    http_response_code(404);
    exit('Not found');
}

header('Content-Type: ' . $type);
header('Content-Length: ' . filesize($abs));
header('Content-Disposition: inline; filename="' . $name . '.' . LT_FILE_TYPES[$type] . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=600');
readfile($abs);
