<?php
/**
 * Loan Tracker — a file of an investment, Owner only.
 *   investment_file.php?k=proof&id=INV   proof of payment of the investment
 *   investment_file.php?k=sec&id=ROW     a security item's document
 *   investment_file.php?k=doc&id=ROW     a document
 *   investment_file.php?k=ret&id=ROW     proof of a return received
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/url_helper.php';
require_once __DIR__ . '/includes/lt_invest.php';

$companyId = lt_inv_boot($conn);
session_write_close();

$id = (int)($_GET['id'] ?? 0);
$sql = [
    'proof' => "SELECT i.proof_path AS p, i.proof_name AS n FROM lt_investments i WHERE i.id = ? AND i.company_id = ?",
    'sec' => "SELECT s.file_path AS p, s.original_name AS n FROM lt_investment_security s JOIN lt_investments i ON i.id = s.investment_id WHERE s.id = ? AND i.company_id = ?",
    'doc' => "SELECT d.file_path AS p, d.original_name AS n FROM lt_investment_documents d JOIN lt_investments i ON i.id = d.investment_id WHERE d.id = ? AND i.company_id = ?",
    'ret' => "SELECT r.file_path AS p, r.original_name AS n FROM lt_investment_returns r JOIN lt_investments i ON i.id = r.investment_id WHERE r.id = ? AND i.company_id = ?",
][(string)($_GET['k'] ?? '')] ?? null;

$abs = '';
$name = 'file';
if ($sql) {
    $q = $conn->prepare($sql);
    $q->execute([$id, $companyId]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if ($row && !empty($row['p'])) {
        $abs = lt_doc_abs_path(['source' => 'upload', 'file_path' => $row['p']]);
        $name = preg_replace('/[^A-Za-z0-9 _.-]/', '', pathinfo((string)$row['n'], PATHINFO_FILENAME)) ?: 'file';
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
