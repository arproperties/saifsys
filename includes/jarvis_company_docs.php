<?php
/**
 * Company documents (HR -> Company Documents: trade licences, MOA, Ejari, establishment cards,
 * POA, VAT and corporate tax certificates) for Reem, read through the Jarvis API
 * (api/jarvis/v1/modules/company_docs.php). Reem copies them onto its Shelf every 15 minutes
 * so its chat answers from the current one, so these only read.
 *
 * Each document is its live row in hr_company_documents (always the current issue) with its
 * earlier issues from hr_company_document_versions (archived on Renew). A stored file_path is
 * never handed out: Reem asks for a file by the document's id (and version), and the file is
 * only served from inside uploads/company_docs.
 */

declare(strict_types=1);

const JARVIS_COMPANY_DOCS_MAX = 500;

/** The file a stored path points at, if it is inside uploads/company_docs and really there. */
function jarvis_company_docs_resolve(string $appRoot, ?string $stored): ?string
{
    $stored = trim(str_replace('\\', '/', (string)$stored));
    if ($stored === '') {
        return null;
    }
    $base = realpath($appRoot . '/uploads/company_docs');
    if ($base === false) {
        return null;
    }
    $abs = realpath($appRoot . '/' . ltrim($stored, '/'));
    if ($abs === false || !is_file($abs) || strpos($abs, $base . DIRECTORY_SEPARATOR) !== 0) {
        return null;
    }
    return $abs;
}

function jarvis_company_docs_date($d): ?string
{
    return $d && $d !== '0000-00-00' ? substr((string)$d, 0, 10) : null;
}

function jarvis_company_docs_text($s): ?string
{
    $s = trim((string)$s);
    return $s === '' ? null : $s;
}

/**
 * What a file is, for Reem: whether there is one, the name it was uploaded as, and file_key -
 * the stored file's own name, which changes whenever a new file replaces it (each upload is
 * saved under a fresh timestamped name), so Reem knows to fetch it again without downloading.
 */
function jarvis_company_docs_file_info(string $appRoot, ?string $path, ?string $name): array
{
    $file = jarvis_company_docs_resolve($appRoot, $path);
    return [
        'has_file' => $file !== null,
        'file_name' => $file !== null ? (jarvis_company_docs_text($name) ?? basename($file)) : null,
        'file_key' => $file !== null ? basename($file) : null,
    ];
}

/** One page of company documents, oldest id first, after `$afterId`, each with its earlier issues. */
function jarvis_company_docs_list(PDO $conn, string $appRoot, int $afterId, int $limit): array
{
    $limit = max(1, min($limit, JARVIS_COMPANY_DOCS_MAX));
    $stmt = $conn->prepare("SELECT d.id, d.company_id, c.name AS company_name, c.code AS company_code, d.doc_type, d.title,
            d.doc_number, d.issuing_authority, d.issue_date, d.expiry_date, d.file_path, d.file_name, d.notes, d.updated_at
        FROM hr_company_documents d JOIN companies c ON c.id = d.company_id
        WHERE d.id > ? ORDER BY d.id LIMIT " . ($limit + 1));
    $stmt->execute([$afterId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $more = count($rows) > $limit;
    $rows = array_slice($rows, 0, $limit);

    $versions = [];
    if ($rows) {
        $ids = array_map(fn($r) => (int)$r['id'], $rows);
        $v = $conn->prepare('SELECT id, document_id, version_no, doc_number, issuing_authority, issue_date, expiry_date,
                file_path, file_name, notes, archived_at
            FROM hr_company_document_versions WHERE document_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
            ORDER BY document_id, version_no');
        $v->execute($ids);
        foreach ($v->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $versions[(int)$r['document_id']][] = [
                'version_no' => (int)$r['version_no'],
                'number' => jarvis_company_docs_text($r['doc_number']),
                'authority' => jarvis_company_docs_text($r['issuing_authority']),
                'issued_at' => jarvis_company_docs_date($r['issue_date']),
                'expires_at' => jarvis_company_docs_date($r['expiry_date']),
                'notes' => jarvis_company_docs_text($r['notes']),
                'archived_at' => $r['archived_at'] !== null ? (string)$r['archived_at'] : null,
            ] + jarvis_company_docs_file_info($appRoot, $r['file_path'], $r['file_name']);
        }
    }

    $docs = array_map(fn(array $r) => [
        'id' => (int)$r['id'],
        'company_id' => (int)$r['company_id'],
        'company_name' => (string)$r['company_name'],
        'company_code' => jarvis_company_docs_text($r['company_code']),
        'type' => (string)$r['doc_type'],
        'title' => jarvis_company_docs_text($r['title']),
        'number' => jarvis_company_docs_text($r['doc_number']),
        'authority' => jarvis_company_docs_text($r['issuing_authority']),
        'issued_at' => jarvis_company_docs_date($r['issue_date']),
        'expires_at' => jarvis_company_docs_date($r['expiry_date']),
        'notes' => jarvis_company_docs_text($r['notes']),
        'updated_at' => $r['updated_at'] !== null ? (string)$r['updated_at'] : null,
        'versions' => $versions[(int)$r['id']] ?? [],
    ] + jarvis_company_docs_file_info($appRoot, $r['file_path'], $r['file_name']), $rows);

    return ['documents' => $docs, 'next_after_id' => $more ? (int)end($rows)['id'] : null];
}

/** The file of a company document, or of one of its earlier issues (`$version` = its version_no); null when there is none. */
function jarvis_company_docs_file(PDO $conn, string $appRoot, int $id, ?int $version = null): ?string
{
    if ($version === null) {
        $stmt = $conn->prepare('SELECT file_path FROM hr_company_documents WHERE id = ?');
        $stmt->execute([$id]);
    } else {
        $stmt = $conn->prepare('SELECT file_path FROM hr_company_document_versions WHERE document_id = ? AND version_no = ?');
        $stmt->execute([$id, $version]);
    }
    $path = $stmt->fetchColumn();
    return $path === false ? null : jarvis_company_docs_resolve($appRoot, (string)$path);
}
