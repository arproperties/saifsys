<?php
/**
 * Jarvis API: company documents for Reem's Shelf (api/jarvis/v1/modules/company_docs.php).
 *
 *   php tests/jarvis_company_docs_test.php
 *       Builds its own throwaway database (test_jarvis_company_docs, dropped and recreated
 *       on every run) and a throwaway uploads folder. Touches no real data.
 */

declare(strict_types=1);

const TEST_DB = 'test_jarvis_company_docs';

$failed = 0;
$passed = 0;

function check(bool $cond, string $label): void
{
    global $failed, $passed;
    echo ($cond ? 'PASS: ' : 'FAIL: ') . $label . "\n";
    $cond ? $passed++ : $failed++;
}

define('DB_NAME', TEST_DB);
@require_once __DIR__ . '/../includes/config.php'; // its own DB_NAME define is ignored
$root = new PDO('mysql:host=' . DB_HOST . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$root->exec('DROP DATABASE IF EXISTS ' . TEST_DB);
$root->exec('CREATE DATABASE ' . TEST_DB . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$conn = new PDO('mysql:host=' . DB_HOST . ';dbname=' . TEST_DB . ';charset=utf8mb4', DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
check($conn->query('SELECT DATABASE()')->fetchColumn() === TEST_DB, 'connected to throwaway database');

require_once __DIR__ . '/../includes/jarvis_company_docs.php';

$conn->exec("CREATE TABLE companies (id INT PRIMARY KEY, name VARCHAR(100), code VARCHAR(20), is_active TINYINT DEFAULT 1)");
$conn->exec("CREATE TABLE hr_company_documents (
    id INT AUTO_INCREMENT PRIMARY KEY, company_id INT NOT NULL, doc_type VARCHAR(40) NOT NULL, title VARCHAR(200) NOT NULL DEFAULT '',
    doc_number VARCHAR(100) NULL, issuing_authority VARCHAR(160) NULL, issue_date DATE NULL, expiry_date DATE NULL,
    file_path VARCHAR(255) NULL, file_name VARCHAR(255) NULL, file_size INT NULL, mime_type VARCHAR(100) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active', notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP)");
$conn->exec("CREATE TABLE hr_company_document_versions (
    id INT AUTO_INCREMENT PRIMARY KEY, document_id INT NOT NULL, version_no INT NOT NULL DEFAULT 1,
    doc_number VARCHAR(100) NULL, issuing_authority VARCHAR(160) NULL, issue_date DATE NULL, expiry_date DATE NULL,
    file_path VARCHAR(255) NULL, file_name VARCHAR(255) NULL, file_size INT NULL, mime_type VARCHAR(100) NULL, notes TEXT NULL,
    archived_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP)");
$conn->exec("INSERT INTO companies VALUES (1, 'Saif Holding LLC', 'SH', 1), (2, 'Ain Al Reem Properties', 'AR', 1)");

// A throwaway app root with uploads/company_docs, and a file outside it.
$app = sys_get_temp_dir() . '/jarvis_company_docs_' . bin2hex(random_bytes(4));
mkdir($app . '/uploads/company_docs/1', 0777, true);
mkdir($app . '/uploads/docs', 0777, true);
file_put_contents($app . '/uploads/company_docs/1/trade_license_1_1767000000_aaaa1111.pdf', '%PDF new licence');
file_put_contents($app . '/uploads/company_docs/1/trade_license_1_1735000000_bbbb2222.pdf', '%PDF old licence');
file_put_contents($app . '/uploads/docs/passport.pdf', 'an employee file');
file_put_contents($app . '/secret.txt', 'not a document');

$conn->exec("INSERT INTO hr_company_documents (company_id, doc_type, title, doc_number, issuing_authority, issue_date, expiry_date, file_path, file_name, notes, updated_at) VALUES
    (1, 'trade_license', '', '123456', 'DED', '2026-01-10', '2027-01-09', 'uploads/company_docs/1/trade_license_1_1767000000_aaaa1111.pdf', 'Trade Licence 2026.pdf', 'renewed', '2026-11-01 09:00:00'),
    (2, 'moa', 'MOA amended', NULL, NULL, '0000-00-00', NULL, 'uploads/company_docs/2/gone.pdf', 'moa.pdf', '', '2026-10-01 09:00:00'),
    (2, 'other', 'Chamber', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-02 09:00:00')");
$conn->exec("INSERT INTO hr_company_document_versions (document_id, version_no, doc_number, issuing_authority, issue_date, expiry_date, file_path, file_name, archived_at) VALUES
    (1, 2, '123456', 'DED', '2025-01-10', '2026-01-09', 'uploads/company_docs/1/trade_license_1_1735000000_bbbb2222.pdf', 'Trade Licence 2025.pdf', '2026-01-12 10:00:00'),
    (1, 1, '123456', 'DED', '2024-01-10', '2025-01-09', NULL, NULL, '2025-01-12 10:00:00')");

// ---- the list ----------------------------------------------------------------
$page = jarvis_company_docs_list($conn, $app, 0, 500);
check(count($page['documents']) === 3, 'every company document is listed, the ones without a file too');
$d = $page['documents'][0];
check($d['company_id'] === 1 && $d['company_name'] === 'Saif Holding LLC' && $d['company_code'] === 'SH', 'with its company');
check($d['type'] === 'trade_license' && $d['title'] === null && $d['number'] === '123456' && $d['authority'] === 'DED', 'with type, number and authority; an empty title is null');
check($d['issued_at'] === '2026-01-10' && $d['expires_at'] === '2027-01-09', 'with its dates');
check($d['has_file'] === true && $d['file_name'] === 'Trade Licence 2026.pdf' && $d['file_key'] === 'trade_license_1_1767000000_aaaa1111.pdf', 'a file on disk is offered by its upload name, with the stored name as its key');
check($d['updated_at'] === '2026-11-01 09:00:00', 'a change time');
check(!array_key_exists('file_path', $d), 'never the stored path');
check(array_column($d['versions'], 'version_no') === [1, 2], 'its earlier issues, oldest first');
$v2 = $d['versions'][1];
check($v2['expires_at'] === '2026-01-09' && $v2['has_file'] === true && $v2['file_key'] === 'trade_license_1_1735000000_bbbb2222.pdf', 'an earlier issue has its dates and file');
check($d['versions'][0]['has_file'] === false && $d['versions'][0]['file_name'] === null, 'an earlier issue with no file says so');
$moa = $page['documents'][1];
check($moa['title'] === 'MOA amended' && $moa['issued_at'] === null && $moa['notes'] === null, 'a zero date and empty notes are null');
check($moa['has_file'] === false && $moa['file_key'] === null, 'a file the row names but the disk does not have is not offered');
check($page['documents'][2]['versions'] === [] && $page['documents'][2]['has_file'] === false, 'no versions and no file');
check($page['next_after_id'] === null, 'one page, so nothing after it');

$first = jarvis_company_docs_list($conn, $app, 0, 2);
check(count($first['documents']) === 2 && $first['next_after_id'] === 2, 'pages by id');
$second = jarvis_company_docs_list($conn, $app, $first['next_after_id'], 2);
check(array_column($second['documents'], 'id') === [3], 'the next page starts after the last id');
check(count(jarvis_company_docs_list($conn, $app, 0, 99999)['documents']) === 3, 'a huge limit is capped, not refused');

// ---- the file ----------------------------------------------------------------
check(jarvis_company_docs_file($conn, $app, 1) === realpath($app . '/uploads/company_docs/1/trade_license_1_1767000000_aaaa1111.pdf'), 'the current file by id');
check(jarvis_company_docs_file($conn, $app, 1, 2) === realpath($app . '/uploads/company_docs/1/trade_license_1_1735000000_bbbb2222.pdf'), 'an earlier issue by its version number');
check(jarvis_company_docs_file($conn, $app, 1, 1) === null, 'an earlier issue with no file is null');
check(jarvis_company_docs_file($conn, $app, 1, 9) === null, 'an unknown version is null');
check(jarvis_company_docs_file($conn, $app, 2) === null, 'a missing file is null');
check(jarvis_company_docs_file($conn, $app, 99) === null, 'an unknown id is null');
$conn->exec("UPDATE hr_company_documents SET file_path = 'uploads/company_docs/../../secret.txt' WHERE id = 3");
check(jarvis_company_docs_file($conn, $app, 3) === null, 'a path that climbs out of uploads/company_docs is refused');
$conn->exec("UPDATE hr_company_documents SET file_path = 'uploads/docs/passport.pdf' WHERE id = 3");
check(jarvis_company_docs_file($conn, $app, 3) === null, 'another uploads folder is refused');

// ---- tidy up -----------------------------------------------------------------
$root->exec('DROP DATABASE IF EXISTS ' . TEST_DB);
array_map('unlink', array_filter(glob($app . '/{,*/,*/*/,*/*/*/}*', GLOB_BRACE), 'is_file'));

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed ? 1 : 0);
