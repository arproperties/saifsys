<?php
/**
 * Legacy import URL — redirects to bank_reconciliation_import.php
 */
$query = $_SERVER['QUERY_STRING'] ?? '';
header('Location: bank_reconciliation_import.php' . ($query !== '' ? '?' . $query : ''));
exit;
