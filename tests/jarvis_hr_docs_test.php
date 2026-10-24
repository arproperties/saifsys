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

// ---- tidy up -----------------------------------------------------------------
$root->exec('DROP DATABASE IF EXISTS ' . TEST_DB);
array_map('unlink', array_filter(glob($app . '/{,*/,*/*/,*/*/*/}*', GLOB_BRACE), 'is_file'));

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed ? 1 : 0);
