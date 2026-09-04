<?php
require_once __DIR__ . '/bank_reco_bootstrap.php';
require_once __DIR__ . '/../includes/construction_bank_reco_rules.php';
co_bank_reco_require_tables($conn);
co_bank_reco_guard($conn, 'construction.bank_reconciliation.view');

$line_id = (int) ($_GET['line_id'] ?? 0);
if ($line_id <= 0) {
    co_bank_reco_json_error('line_id required');
}

try {
    $line = co_bank_get_statement_line($conn, $line_id, $cid);
    if (!$line) {
        co_bank_reco_json_error('Statement line not found');
    }

    $suggestions = co_bank_reco_gl_suggestions($conn, $cid, $line);
    $primary = $suggestions[0] ?? null;
    $alternatives = array_slice($suggestions, 1, 5);
    $glCandidates = co_bank_reco_gl_candidates_for_line($conn, $cid, $line, 50);

    $ruleSuggestion = null;
    if (co_bank_rules_table_ready($conn)) {
        $ruleSuggestion = co_bank_rule_match_line($conn, $cid, $line, (int) $line['bank_account_id']);
    }

    $notes = [];
    if (co_db_table_exists($conn, 'co_bank_line_notes')) {
        $userJoin = co_db_table_exists($conn, 'user') ? 'user' : (co_db_table_exists($conn, 'users') ? 'users' : null);
        $nameCol = 'username';
        if ($userJoin === 'user') {
            $nameCol = co_db_column_exists($conn, 'user', 'fullname') ? 'fullname' : 'username';
        } elseif ($userJoin === 'users' && co_db_column_exists($conn, 'users', 'full_name')) {
            $nameCol = 'full_name';
        }
        if ($userJoin) {
            $st = $conn->prepare("
                SELECT n.*, u.{$nameCol} AS author_name
                FROM co_bank_line_notes n
                LEFT JOIN {$userJoin} u ON u.id = n.created_by
                WHERE n.statement_line_id = ? AND n.company_id = ?
                ORDER BY n.created_at DESC
                LIMIT 20
            ");
            $st->execute([$line_id, $cid]);
            $notes = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    }

    $st = $conn->prepare('
        SELECT m.* FROM co_reconciliation_matches m
        WHERE m.bank_statement_line_id = ?
        ORDER BY m.id DESC
    ');
    $st->execute([$line_id]);
    $matches = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    echo json_encode([
        'success' => true,
        'line' => $line,
        'primary_suggestion' => $primary,
        'alternative_count' => count($alternatives),
        'alternatives' => $alternatives,
        'gl_candidates' => $glCandidates,
        'rule_suggestion' => $ruleSuggestion,
        'matches' => $matches,
        'notes' => $notes,
    ]);
} catch (Throwable $e) {
    error_log('co bank_reco_line_detail: ' . $e->getMessage());
    co_bank_reco_json_error('Could not load line details: ' . $e->getMessage());
}
