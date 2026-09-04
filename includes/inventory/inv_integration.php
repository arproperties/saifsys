<?php
/**
 * Inventory Engine - Integration Contract
 *
 * Modules must NOT update stock directly.
 * They create inventory documents + lines and post them, linking back to their source record.
 *
 * Source reference fields:
 * - inv_doc_headers.source_module, source_table, source_id
 * - inv_doc_lines.source_table, source_line_id
 * - inv_stock_moves.source_module, source_table, source_id (copied from header)
 */

require_once __DIR__ . '/inv_posting.php';

function inv_build_source_ref(string $module, string $table, int $id): array {
    return [
        'source_module' => $module,
        'source_table'  => $table,
        'source_id'     => $id,
    ];
}

function inv_create_doc_for_source(PDO $conn, int $companyId, string $docType, string $docDate, ?int $fromLoc, ?int $toLoc, array $sourceRef, array $extraHeader = []): int {
    $hdr = array_merge([
        'company_id' => $companyId,
        'doc_type'   => $docType,
        'doc_date'   => $docDate,
        'status'     => 'draft',
        'location_from_id' => $fromLoc,
        'location_to_id'   => $toLoc,
        'created_by' => current_user_id(),
        'notes'      => $extraHeader['notes'] ?? null,
    ], $sourceRef, $extraHeader);

    return inv_create_doc($conn, $hdr);
}

function inv_add_line_for_source(PDO $conn, int $docId, array $line, ?string $sourceTable = null, ?int $sourceLineId = null): int {
    if ($sourceTable) $line['source_table'] = $sourceTable;
    if ($sourceLineId) $line['source_line_id'] = $sourceLineId;
    return inv_add_line($conn, $docId, $line);
}

function inv_post_doc_for_source(PDO $conn, int $docId, int $userId): array {
    return inv_post_doc($conn, $docId, $userId, false);
}

