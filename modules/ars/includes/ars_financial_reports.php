<?php
/**
 * ARS financial reporting helpers — company-scoped queries for adapter documents.
 * Shared TB/P&L/BS continue via re_* journals; these helpers surface ARS document filters.
 */

require_once __DIR__ . '/ars_financial_adapter.php';

/**
 * @param array<string,mixed> $filters booking_id, guest_id, property_id, unit_id, document_type, status, from, to
 * @return list<array<string,mixed>>
 */
function ars_report_financial_documents(PDO $conn, int $companyId, array $filters = []): array {
    if ($companyId <= 0 || !ars_financial_adapter_tables_ready($conn)) {
        return [];
    }

    $sql = "
        SELECT d.*, b.booking_number, b.unit_id, b.guest_id, g.first_name, g.last_name
        FROM ars_financial_documents d
        INNER JOIN ars_bookings b ON b.id = d.booking_id AND b.company_id = d.company_id
        LEFT JOIN ars_guests g ON g.id = d.guest_id
        WHERE d.company_id = ?
    ";
    $params = [$companyId];

    if (!empty($filters['booking_id'])) {
        $sql .= ' AND d.booking_id = ?';
        $params[] = (int) $filters['booking_id'];
    }
    if (!empty($filters['guest_id'])) {
        $sql .= ' AND d.guest_id = ?';
        $params[] = (int) $filters['guest_id'];
    }
    if (!empty($filters['unit_id'])) {
        $sql .= ' AND b.unit_id = ?';
        $params[] = (int) $filters['unit_id'];
    }
    if (!empty($filters['document_type'])) {
        $sql .= ' AND d.document_type = ?';
        $params[] = $filters['document_type'];
    }
    if (!empty($filters['status'])) {
        $sql .= ' AND d.status = ?';
        $params[] = $filters['status'];
    }
    if (!empty($filters['from'])) {
        $sql .= ' AND d.document_date >= ?';
        $params[] = $filters['from'];
    }
    if (!empty($filters['to'])) {
        $sql .= ' AND d.document_date <= ?';
        $params[] = $filters['to'];
    }

    $sql .= ' ORDER BY d.document_date DESC, d.id DESC LIMIT 500';
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Outstanding receivables from Option B documents.
 * @return array{rows:list<array>,total_balance:float}
 */
function ars_report_outstanding_receivables(PDO $conn, int $companyId): array {
    if ($companyId <= 0 || !ars_financial_adapter_tables_ready($conn)) {
        return ['rows' => [], 'total_balance' => 0.0];
    }
    $stmt = $conn->prepare("
        SELECT d.id, d.document_number, d.document_type, d.status, d.balance_due, d.total_amount,
               d.booking_id, b.booking_number, d.guest_id
        FROM ars_financial_documents d
        INNER JOIN ars_bookings b ON b.id = d.booking_id AND b.company_id = d.company_id
        WHERE d.company_id = ?
          AND d.status IN ('posted','partially_paid')
          AND d.balance_due > 0
          AND d.document_type IN ('original_invoice','extension_invoice','service_invoice','adjustment_invoice')
        ORDER BY d.document_date ASC, d.id ASC
    ");
    $stmt->execute([$companyId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $total = 0.0;
    foreach ($rows as $r) {
        $total += (float) $r['balance_due'];
    }
    return ['rows' => $rows, 'total_balance' => round($total, 2)];
}

/**
 * Deposit liability from posted security deposit events.
 * @return array{rows:list<array>,net_liability:float}
 */
function ars_report_deposit_liability(PDO $conn, int $companyId): array {
    if ($companyId <= 0 || !ars_financial_adapter_tables_ready($conn)) {
        return ['rows' => [], 'net_liability' => 0.0];
    }
    $stmt = $conn->prepare("
        SELECT booking_id,
               SUM(CASE WHEN event_type = 'received' THEN amount ELSE 0 END) AS received,
               SUM(CASE WHEN event_type IN ('full_refund','partial_refund') THEN amount ELSE 0 END) AS refunded,
               SUM(CASE WHEN event_type = 'forfeit' THEN amount ELSE 0 END) AS forfeited
        FROM ars_security_deposits
        WHERE company_id = ? AND status = 'posted'
        GROUP BY booking_id
        HAVING (received - refunded - forfeited) > 0.00001
    ");
    $stmt->execute([$companyId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $net = 0.0;
    foreach ($rows as &$r) {
        $r['open_liability'] = round((float)$r['received'] - (float)$r['refunded'] - (float)$r['forfeited'], 2);
        $net += $r['open_liability'];
    }
    unset($r);
    return ['rows' => $rows, 'net_liability' => round($net, 2)];
}

/**
 * Journal IDs posted by ARS adapter for bank reco / GL filters.
 * @return list<int>
 */
function ars_report_adapter_journal_ids(PDO $conn, int $companyId, ?string $from = null, ?string $to = null): array {
    if ($companyId <= 0 || !ars_financial_adapter_tables_ready($conn)) {
        return [];
    }
    $ids = [];
    $q = $conn->prepare("
        SELECT journal_id FROM ars_financial_documents
        WHERE company_id = ? AND journal_id IS NOT NULL
          AND (? IS NULL OR document_date >= ?)
          AND (? IS NULL OR document_date <= ?)
    ");
    $q->execute([$companyId, $from, $from, $to, $to]);
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $ids[] = (int) $id;
    }
    $q2 = $conn->prepare("
        SELECT journal_id FROM ars_security_deposits
        WHERE company_id = ? AND journal_id IS NOT NULL AND status = 'posted'
    ");
    $q2->execute([$companyId]);
    foreach ($q2->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $ids[] = (int) $id;
    }
    return array_values(array_unique(array_filter($ids)));
}
