<?php
/**
 * Real Estate - which documents still need an expiry watch.
 *
 * One rule, used by every screen and job that warns about expiring documents
 * (lease Documents list, Compliance dashboard, Documents library counts,
 * unit compliance score, expiry e-mail cron). Change the rule here only.
 *
 * A document is WATCHED when re_doc_unwatched_reason_sql() gives NULL.
 * Otherwise it gives the reason it is not watched:
 *   'no_expiry'     no expiry date
 *   'replaced'      marked renewed/cancelled, or a newer copy of the same
 *                   document exists (newer visa, renewed Ejari, ...)
 *   'lease_closed'  the lease (or, for the tenant's own papers, every lease
 *                   of that tenant) is no longer in a watched status
 *
 * Tenant papers (Emirates ID, visa, passport, trade licence, POA) follow the
 * TENANT, not the lease: on a renewal they are often uploaded on the old lease
 * only, so they stay watched while the tenant has any watched lease, and a
 * newer copy on any of that tenant's leases replaces the older one.
 *
 * Lease papers (contract, Ejari, other) follow their own lease.
 * Unit / building / other documents are always watched.
 */

if (!defined('RE_DOC_WATCH_LEASE_STATUSES')) {
    /** Lease statuses whose documents are watched. Anything not listed is ignored. */
    define('RE_DOC_WATCH_LEASE_STATUSES', ['active']);
}
if (!defined('RE_DOC_PERSON_TYPES')) {
    /** re_documents.document_type values that belong to the tenant as a person. */
    define('RE_DOC_PERSON_TYPES', ['tenant_id', 'tenant_visa', 'tenant_passport']);
}
if (!defined('RE_DOC_PERSON_TYPE_CODES')) {
    /** re_document_types.document_type_code values that belong to the tenant (stored as document_type 'other'). */
    define('RE_DOC_PERSON_TYPE_CODES', ['TRADE_LICENSE', 'POWER_OF_ATTORNEY', 'EMIRATES_ID', 'TENANT_VISA', 'TENANT_PASSPORT']);
}

if (!function_exists('re_doc_sql_list')) {
    function re_doc_sql_list(array $values): string
    {
        return implode(',', array_map(static function ($v) {
            return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], (string)$v) . "'";
        }, $values));
    }
}

if (!function_exists('re_doc_unwatched_reason_sql')) {
    /**
     * SQL expression: NULL when the document under alias $a is watched, else the reason.
     * Use as   (<expr>) IS NULL   in a WHERE, or select it as watch_reason.
     */
    function re_doc_unwatched_reason_sql(string $a = 'd'): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $a)) {
            throw new InvalidArgumentException('Bad table alias');
        }
        $leaseStatuses = re_doc_sql_list(RE_DOC_WATCH_LEASE_STATUSES);
        $personTypes   = re_doc_sql_list(RE_DOC_PERSON_TYPES);
        $personCodes   = re_doc_sql_list(RE_DOC_PERSON_TYPE_CODES);

        // Tenant the document belongs to.
        $tenant = "(CASE {$a}.related_type
                      WHEN 'lease'  THEN (SELECT tl.tenant_id FROM re_leases tl WHERE tl.id = {$a}.related_id)
                      WHEN 'tenant' THEN {$a}.related_id
                    END)";

        $isPerson = "({$a}.related_type IN ('lease','tenant') AND (
                        {$a}.document_type IN ({$personTypes})
                        OR {$a}.document_type_id IN (SELECT pt.id FROM re_document_types pt WHERE pt.document_type_code IN ({$personCodes}))
                     ))";

        $tenantHasWatchedLease = "EXISTS (SELECT 1 FROM re_leases wl
                                  WHERE wl.tenant_id = {$tenant}
                                    AND wl.company_id = {$a}.company_id
                                    AND wl.status IN ({$leaseStatuses})
                                    AND wl.deleted_at IS NULL)";

        // Same kind of document. Generic 'other' files never replace each other,
        // only 'other' files of a type that carries an expiry (trade licence, insurance, POA).
        $sameKind = "n.document_type = {$a}.document_type
                     AND ({$a}.document_type <> 'other'
                          OR ({$a}.document_type_id IS NOT NULL
                              AND n.document_type_id = {$a}.document_type_id
                              AND {$a}.document_type_id IN (SELECT et.id FROM re_document_types et WHERE et.has_expiry = 1)))";

        $isNewer = "n.id <> {$a}.id
                    AND n.company_id = {$a}.company_id
                    AND n.status NOT IN ('renewed','cancelled')
                    AND n.expiry_date IS NOT NULL
                    AND (n.expiry_date > {$a}.expiry_date OR (n.expiry_date = {$a}.expiry_date AND n.id > {$a}.id))";

        $newerForTenant = "EXISTS (SELECT 1 FROM re_documents n
                           WHERE {$isNewer} AND {$sameKind}
                             AND (
                                  (n.related_type = 'tenant' AND n.related_id = {$tenant})
                               OR (n.related_type = 'lease' AND n.related_id IN
                                     (SELECT ol.id FROM re_leases ol WHERE ol.tenant_id = {$tenant} AND ol.deleted_at IS NULL))
                             ))";

        $newerOnLease = "EXISTS (SELECT 1 FROM re_documents n
                         WHERE {$isNewer} AND {$sameKind}
                           AND n.related_type = 'lease' AND n.related_id = {$a}.related_id)";

        $leaseWatched = "EXISTS (SELECT 1 FROM re_leases ll
                         WHERE ll.id = {$a}.related_id
                           AND ll.status IN ({$leaseStatuses})
                           AND ll.deleted_at IS NULL)";

        return "(CASE
            WHEN {$a}.expiry_date IS NULL THEN 'no_expiry'
            WHEN {$a}.status IN ('renewed','cancelled') THEN 'replaced'
            WHEN {$isPerson} THEN
                CASE WHEN NOT {$tenantHasWatchedLease} THEN 'lease_closed'
                     WHEN {$newerForTenant} THEN 'replaced' END
            WHEN {$a}.related_type = 'lease' THEN
                CASE WHEN NOT {$leaseWatched} THEN 'lease_closed'
                     WHEN {$newerOnLease} THEN 'replaced' END
            WHEN {$a}.related_type = 'tenant' THEN
                CASE WHEN NOT {$tenantHasWatchedLease} THEN 'lease_closed' END
            ELSE NULL
        END)";
    }
}

if (!function_exists('re_doc_watched_sql')) {
    /** SQL condition: true when the document under alias $a is watched. */
    function re_doc_watched_sql(string $a = 'd'): string
    {
        return '(' . re_doc_unwatched_reason_sql($a) . ' IS NULL)';
    }
}

if (!function_exists('re_doc_watch_reasons')) {
    /**
     * Watch reason for each document id (null = watched).
     * @param int[] $ids
     * @return array<int, string|null>
     */
    function re_doc_watch_reasons(PDO $conn, int $companyId, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $conn->prepare("SELECT d.id, " . re_doc_unwatched_reason_sql('d') . " AS watch_reason
                                FROM re_documents d WHERE d.company_id = ? AND d.id IN ({$in})");
        $stmt->execute(array_merge([$companyId], $ids));
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['id']] = $r['watch_reason'] !== null ? (string)$r['watch_reason'] : null;
        }
        return $out;
    }
}
