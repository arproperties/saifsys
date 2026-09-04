<?php
require_once __DIR__ . '/re_bank_reco_bootstrap.php';
re_bank_reco_require_tables($conn);
re_bank_reco_guard($conn, 'realestate.bank_reconciliation.view');

require_once __DIR__ . '/../../includes/re_bank_reco_rules.php';

$line_id = (int) ($_GET['line_id'] ?? 0);
if ($line_id <= 0) {
    re_bank_reco_json_error('line_id required');
}

try {
    $line = re_bank_get_statement_line($conn, $line_id, $cid);
    if (!$line) {
        re_bank_reco_json_error('Statement line not found');
    }

    $suggestions = re_bank_reco_suggestions_for_workbench($conn, $cid, $line_id);
    $primary = $suggestions[0] ?? null;
    $alternatives = array_slice($suggestions, 1, 5);
    $glCandidates = re_bank_reco_gl_candidates_for_line($conn, $cid, $line, 50);

    $notes = [];
    if (re_db_table_exists($conn, 're_bank_line_notes')) {
        $userJoin = re_db_table_exists($conn, 'user') ? 'user' : (re_db_table_exists($conn, 'users') ? 'users' : null);
        $nameCol = 'username';
        if ($userJoin === 'user') {
            $nameCol = re_db_column_exists($conn, 'user', 'fullname') ? 'fullname' : 'username';
        } elseif ($userJoin === 'users' && re_db_column_exists($conn, 'users', 'full_name')) {
            $nameCol = 'full_name';
        }
        if ($userJoin) {
            $st = $conn->prepare("
                SELECT n.*, u.{$nameCol} AS author_name
                FROM re_bank_line_notes n
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
        SELECT m.* FROM re_bank_reconciliation_matches m
        WHERE m.statement_line_id = ? AND m.company_id = ?
        ORDER BY m.id DESC
    ');
    $st->execute([$line_id, $cid]);
    $matches = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $ruleSuggestion = null;
    if (re_bank_rules_table_ready($conn)) {
        $ruleMatch = re_bank_rule_match_line($conn, $cid, $line, (int) $line['bank_account_id']);
        if ($ruleMatch && !empty($ruleMatch['auto_suggest'])) {
            $ruleSuggestion = [
                'rule_id' => $ruleMatch['rule_id'],
                'rule_name' => $ruleMatch['rule_name'],
                'prefill' => $ruleMatch['prefill'],
            ];
        }
    }

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
    error_log('re_bank_reco_line_detail: ' . $e->getMessage());
    re_bank_reco_json_error('Could not load line details: ' . $e->getMessage());
}
