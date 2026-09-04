<?php
/**
 * Construction bank reconciliation — company settings & Phase 4 auto-reconcile.
 */
declare(strict_types=1);

require_once __DIR__ . '/construction_bank_reconciliation.php';
require_once __DIR__ . '/construction_bank_reco_rules.php';
require_once __DIR__ . '/construction_bank_reco_engine.php';

function co_bank_reco_settings_table_ready(PDO $conn): bool
{
    return co_db_table_exists($conn, 'co_bank_reco_settings');
}

function co_bank_feed_table_ready(PDO $conn): bool
{
    return co_db_table_exists($conn, 'co_bank_feed_connections');
}

/** @return array{auto_reconcile_rule_matches:bool} */
function co_bank_reco_get_settings(PDO $conn, int $companyId): array
{
    $defaults = ['auto_reconcile_rule_matches' => false];
    if (!co_bank_reco_settings_table_ready($conn)) {
        return $defaults;
    }
    $st = $conn->prepare('SELECT auto_reconcile_rule_matches FROM co_bank_reco_settings WHERE company_id = ? LIMIT 1');
    $st->execute([$companyId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return $defaults;
    }
    return [
        'auto_reconcile_rule_matches' => (int) ($row['auto_reconcile_rule_matches'] ?? 0) === 1,
    ];
}

/** @param array<string,mixed> $settings */
function co_bank_reco_save_settings(PDO $conn, int $companyId, array $settings, ?int $userId): array
{
    if (!co_bank_reco_settings_table_ready($conn)) {
        return ['success' => false, 'error' => 'Settings table not installed. Run migrations/construction_bank_reconciliation_v3.sql'];
    }
    $auto = !empty($settings['auto_reconcile_rule_matches']) ? 1 : 0;
    try {
        $st = $conn->prepare('
            INSERT INTO co_bank_reco_settings (company_id, auto_reconcile_rule_matches, updated_by)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE auto_reconcile_rule_matches = VALUES(auto_reconcile_rule_matches), updated_by = VALUES(updated_by), updated_at = NOW()
        ');
        $st->execute([$companyId, $auto, $userId ?: null]);
        co_bank_reco_audit($conn, $companyId, 0, null, null, 'reco_settings_save', null, [
            'auto_reconcile_rule_matches' => (bool) $auto,
        ], $userId);
        return ['success' => true, 'error' => null];
    } catch (Throwable $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Auto-confirm GL matches when a bank rule applies and suggestion score ≥ 90.
 * Never auto-creates journals (auto_create_draft rules are skipped).
 *
 * @return array{enabled:bool,confirmed:int,skipped:list<array<string,mixed>>}
 */
function co_bank_reco_auto_reconcile_with_rules(
    PDO $conn,
    int $companyId,
    int $bankAccountId,
    string $from,
    string $to,
    ?int $userId = null
): array {
    $settings = co_bank_reco_get_settings($conn, $companyId);
    if (!$settings['auto_reconcile_rule_matches']) {
        return ['enabled' => false, 'confirmed' => 0, 'skipped' => []];
    }
    if (!co_bank_rules_table_ready($conn)) {
        return ['enabled' => true, 'confirmed' => 0, 'skipped' => [['reason' => 'rules_table_missing']]];
    }

    $bank = co_bank_verify_account($conn, $bankAccountId, $companyId);
    if (!$bank) {
        return ['enabled' => true, 'confirmed' => 0, 'skipped' => [['reason' => 'invalid_bank']]];
    }

    $st = $conn->prepare('
        SELECT l.*, ? AS gl_account_id
        FROM co_bank_statement_lines l
        WHERE l.bank_account_id = ? AND l.company_id = ? AND l.txn_date BETWEEN ? AND ?
        ORDER BY l.txn_date ASC, l.id ASC
    ');
    $st->execute([(int) $bank['gl_account_id'], $bankAccountId, $companyId, $from, $to]);
    $lines = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $confirmed = 0;
    $skipped = [];

    foreach ($lines as $line) {
        $lineId = (int) $line['id'];
        if (co_bank_is_period_locked($conn, $bankAccountId, $line['txn_date'])) {
            $skipped[] = ['line_id' => $lineId, 'reason' => 'period_locked'];
            continue;
        }
        if (co_bank_line_remaining($conn, $line) <= 0.009) {
            continue;
        }

        $existing = $conn->prepare("SELECT COUNT(*) FROM co_reconciliation_matches WHERE bank_statement_line_id = ? AND status IN ('proposed','confirmed')");
        $existing->execute([$lineId]);
        if ((int) $existing->fetchColumn() > 0) {
            continue;
        }

        $rule = co_bank_rule_match_line($conn, $companyId, $line, $bankAccountId);
        if (!$rule) {
            $skipped[] = ['line_id' => $lineId, 'reason' => 'no_rule'];
            continue;
        }
        if (!empty($rule['auto_create_draft'])) {
            $skipped[] = ['line_id' => $lineId, 'reason' => 'rule_create_draft'];
            continue;
        }
        if (empty($rule['auto_suggest'])) {
            $skipped[] = ['line_id' => $lineId, 'reason' => 'rule_auto_suggest_off'];
            continue;
        }

        $suggestions = co_bank_reco_suggestions_for_line($conn, $companyId, $line, 90);
        $top = $suggestions[0] ?? null;
        if (!$top || (float) ($top['score'] ?? 0) < 90) {
            $skipped[] = ['line_id' => $lineId, 'reason' => 'score_below_90', 'top_score' => $top['score'] ?? null];
            continue;
        }

        $rem = co_bank_line_remaining($conn, $line);
        $amt = round(min($rem, (float) $top['remaining']), 2);
        if ($amt <= 0 || abs($amt - $rem) > 0.02) {
            $skipped[] = ['line_id' => $lineId, 'reason' => 'partial_match_not_allowed'];
            continue;
        }

        try {
            co_bank_reco_insert_and_confirm_match(
                $conn,
                $companyId,
                $bankAccountId,
                $lineId,
                (string) $top['system_type'],
                (int) $top['system_id'],
                $amt,
                'rule',
                $userId
            );
            co_bank_refresh_line_status($conn, $lineId, $companyId);
            co_bank_reco_audit($conn, $companyId, $bankAccountId, $lineId, null, 'auto_reconcile_rule', null, [
                'rule_id' => $rule['rule_id'],
                'rule_name' => $rule['rule_name'],
                'score' => $top['score'],
                'system_id' => $top['system_id'],
            ], $userId, (int) $top['system_id']);
            $confirmed++;
        } catch (Throwable $e) {
            $skipped[] = ['line_id' => $lineId, 'reason' => 'confirm_failed', 'error' => $e->getMessage()];
        }
    }

    return ['enabled' => true, 'confirmed' => $confirmed, 'skipped' => $skipped];
}

/** @return list<array<string,mixed>> */
function co_bank_feed_connections_list(PDO $conn, int $companyId): array
{
    if (!co_bank_feed_table_ready($conn)) {
        return [];
    }
    $st = $conn->prepare('
        SELECT f.*, b.account_name AS bank_name, coa.account_code
        FROM co_bank_feed_connections f
        LEFT JOIN re_bank_accounts b ON b.id = f.bank_account_id AND b.company_id = f.company_id
        LEFT JOIN re_chart_of_accounts coa ON coa.id = b.gl_account_id AND coa.company_id = b.company_id
        WHERE f.company_id = ?
        ORDER BY f.id DESC
    ');
    $st->execute([$companyId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
