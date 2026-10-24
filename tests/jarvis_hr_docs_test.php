<?php
/**
 * Jarvis API: employee documents for Reem's Document Renewals (api/jarvis/v1/modules/hr_docs.php).
 *
 *   php tests/jarvis_hr_docs_test.php
 *       Builds its own throwaway database (test_jarvis_hr_docs, dropped and recreated
 *       on every run) and a throwaway uploads folder. Touches no real data.
 */

declare(strict_types=1);

const TEST_DB = 'test_jarvis_hr_docs';

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

require_once __DIR__ . '/../includes/jarvis_hr_docs.php';

$conn->exec("CREATE TABLE employees (id INT PRIMARY KEY, employee_code VARCHAR(20), full_name VARCHAR(100), status VARCHAR(20))");
$conn->exec("CREATE TABLE employee_documents (
    id INT AUTO_INCREMENT PRIMARY KEY, employee_id INT NOT NULL, doc_type VARCHAR(50) NOT NULL, doc_type_id INT NULL,
    doc_number VARCHAR(100) NULL, issued_at DATE NULL, expires_at DATE NULL, notes VARCHAR(255) NULL, file_path VARCHAR(255) NULL,
    uploaded_by INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NULL, company_id INT NOT NULL DEFAULT 1)");
$conn->exec("INSERT INTO employees VALUES (1, 'E00007', 'Sohail Khan', 'Active'), (2, 'E00018', 'Sohaib Asghar', 'Terminated')");

// A throwaway app root with uploads/docs, and a file outside it.
$app = sys_get_temp_dir() . '/jarvis_hr_docs_' . bin2hex(random_bytes(4));
mkdir($app . '/uploads/docs/E00007', 0777, true);
file_put_contents($app . '/uploads/docs/E00007/passport.pdf', '%PDF passport');
file_put_contents($app . '/uploads/docs/doc_2_1764867264.jpg', 'jpeg bytes');
file_put_contents($app . '/secret.txt', 'not a document');

$conn->exec("INSERT INTO employee_documents (employee_id, doc_type, doc_number, issued_at, expires_at, notes, file_path, updated_at) VALUES
    (1, 'Passport', 'P1234567', '2020-01-01', '2030-01-01', 'renewed', 'uploads/docs/E00007/passport.pdf', '2026-10-01 10:00:00'),
    (2, 'Visa', NULL, NULL, '2026-11-01', NULL, 'doc_2_1764867264.jpg', NULL),
    (1, 'Contract', NULL, NULL, NULL, NULL, 'uploads/docs/E00007/gone.pdf', NULL),
    (1, 'Other', NULL, NULL, NULL, NULL, '', NULL)");

// ---- the list ----------------------------------------------------------------
$page = jarvis_hr_docs_list($conn, $app, 0, 500);
check(count($page['documents']) === 4, 'every employee document is listed, the ones without a file too');
$p = $page['documents'][0];
check($p['employee_code'] === 'E00007' && $p['employee_name'] === 'Sohail Khan', 'with the employee\'s code and name');
check($p['type'] === 'Passport' && $p['number'] === 'P1234567' && $p['issued_at'] === '2020-01-01' && $p['expires_at'] === '2030-01-01', 'with type, number and dates');
check($p['has_file'] === true && $p['file_name'] === 'passport.pdf', 'a file on disk is offered by name');
check($page['documents'][1]['has_file'] === true, 'a bare file name is found under uploads/docs');
check($page['documents'][2]['has_file'] === false, 'a file the row names but the disk does not have is not offered');
check($page['documents'][3]['has_file'] === false, 'an empty path has no file');
check($page['documents'][1]['employee_left'] === true && $p['employee_left'] === false, 'an employee who left is marked');
check(!array_key_exists('file_path', $p), 'never the stored path');
check($p['updated_at'] === '2026-10-01 10:00:00' && $page['documents'][1]['updated_at'] !== null, 'a change time, falling back to when it was made');
check($page['next_after_id'] === null, 'one page, so nothing after it');

$first = jarvis_hr_docs_list($conn, $app, 0, 2);
check(count($first['documents']) === 2 && $first['next_after_id'] === 2, 'pages by id');
$second = jarvis_hr_docs_list($conn, $app, $first['next_after_id'], 2);
check(array_column($second['documents'], 'id') === [3, 4], 'the next page starts after the last id');
check(count(jarvis_hr_docs_list($conn, $app, 0, 99999)['documents']) === 4, 'a huge limit is capped, not refused');

// ---- the file ----------------------------------------------------------------
check(jarvis_hr_docs_file($conn, $app, 1) === realpath($app . '/uploads/docs/E00007/passport.pdf'), 'a document\'s file is found by its id');
check(jarvis_hr_docs_file($conn, $app, 2) === realpath($app . '/uploads/docs/doc_2_1764867264.jpg'), 'and a bare name under uploads/docs');
check(jarvis_hr_docs_file($conn, $app, 3) === null, 'a missing file is null');
check(jarvis_hr_docs_file($conn, $app, 99) === null, 'an unknown id is null');
$conn->exec("UPDATE employee_documents SET file_path = 'uploads/docs/../../secret.txt' WHERE id = 4");
check(jarvis_hr_docs_file($conn, $app, 4) === null, 'a path that climbs out of uploads/docs is refused');
$conn->exec("UPDATE employee_documents SET file_path = '../secret.txt' WHERE id = 4");
check(jarvis_hr_docs_file($conn, $app, 4) === null, 'a bare name that climbs out is refused');

// ---- payroll -----------------------------------------------------------------
$conn->exec("CREATE TABLE payroll_runs (id INT PRIMARY KEY, period_from DATE NOT NULL, period_to DATE NOT NULL,
    status VARCHAR(20) NOT NULL, payroll_type VARCHAR(10) NOT NULL DEFAULT 'wps', company_id INT NOT NULL DEFAULT 1)");
$conn->exec("CREATE TABLE payroll_items (id INT AUTO_INCREMENT PRIMARY KEY, payroll_run_id INT NOT NULL, employee_id INT NOT NULL,
    base_pay DECIMAL(12,2) NOT NULL DEFAULT 0, allowance DECIMAL(12,2) NOT NULL DEFAULT 0, bonus DECIMAL(12,2) NOT NULL DEFAULT 0,
    overtime DECIMAL(10,2) NULL, deductions DECIMAL(12,2) NOT NULL DEFAULT 0, gross_pay DECIMAL(10,2) NULL,
    total_deductions DECIMAL(10,2) NULL, net_pay DECIMAL(12,2) NOT NULL DEFAULT 0)");
$conn->exec("INSERT INTO payroll_runs VALUES
    (1, '2026-06-01', '2026-06-30', 'paid', 'wps', 1), (2, '2026-07-01', '2026-07-31', 'paid', 'wps', 1),
    (3, '2026-08-01', '2026-08-31', 'finalized', 'cash', 1), (4, '2026-09-01', '2026-09-30', 'draft', 'wps', 1),
    (5, '2026-01-01', '2026-01-31', 'paid', 'wps', 1), (6, '2026-08-01', '2026-08-31', 'cancelled', 'wps', 1)");
$conn->exec("INSERT INTO payroll_items (payroll_run_id, employee_id, base_pay, allowance, bonus, overtime, gross_pay, total_deductions, net_pay) VALUES
    (1, 1, 800, 700, 0, 50, 1550, 0, 1550), (2, 1, 800, 700, 100, NULL, 1600, 20, 1580), (3, 1, 900, 700, 0, NULL, 1600, 0, 1600),
    (4, 1, 900, 700, 0, NULL, 1600, 0, 1600), (5, 1, 700, 700, 0, NULL, 1400, 0, 1400), (6, 1, 1, 1, 1, NULL, 3, 0, 3),
    (2, 2, 2000, 0, 0, NULL, 2000, 0, 2000)");

$pay = jarvis_hr_docs_payroll($conn, 'E00007', 6, '2026-10-24');
check($pay !== null && $pay['employee_code'] === 'E00007', 'payroll is found by employee code');
check(array_column($pay['items'], 'run_id') === [3, 2, 1], 'payroll is newest first, finalized or paid only, within the months asked');
$aug = $pay['items'][0];
check($aug['period_from'] === '2026-08-01' && $aug['period_to'] === '2026-08-31' && $aug['payroll_type'] === 'cash', 'each line says its period and whether it was WPS or cash');
check($aug['base_pay'] === 900.0 && $aug['allowance'] === 700.0 && $aug['net_pay'] === 1600.0, 'with its components as numbers');
check($pay['items'][1]['bonus'] === 100.0 && $pay['items'][1]['deductions'] === 20.0, 'bonus and deductions');
check(count(jarvis_hr_docs_payroll($conn, 'E00007', 12, '2026-10-24')['items']) === 4, 'a longer window reaches the January run');
check(jarvis_hr_docs_payroll($conn, 'E99999', 6, '2026-10-24') === null, 'an unknown employee is null');
check(count(jarvis_hr_docs_payroll($conn, 'E00007', 999, '2026-10-24')['items']) === 4, 'months are capped, not refused');

// ---- tidy up -----------------------------------------------------------------
$root->exec('DROP DATABASE IF EXISTS ' . TEST_DB);
array_map('unlink', array_filter(glob($app . '/{,*/,*/*/,*/*/*/}*', GLOB_BRACE), 'is_file'));

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed ? 1 : 0);
