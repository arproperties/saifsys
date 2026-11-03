<?php
/**
 * Required documents check (HR > Employee > Overview).
 * Every employee should hold at least one document of each type below in
 * employee_documents; dates do not matter here. When one is missing, HR can
 * note why (migrations/hr_employee_required_docs.sql).
 */

/**
 * Required types, as the profile stores them in employee_documents.doc_type,
 * with the matching document_types id that HR > Documents stores in doc_type_id.
 */
const HR_REQUIRED_DOC_TYPES = [
    'Passport'    => 1,
    'Visa'        => 2,
    'LaborCard'   => 4,
    'Contract'    => 6,
    'National ID' => 3,
];

const HR_REQUIRED_DOC_REASON_MAX = 500;

/** Whether a document, by its name or HR > Documents type id, is one of the required types. */
function hr_required_docs_is_required_type(string $docType, int $docTypeId = 0): bool
{
    return isset(HR_REQUIRED_DOC_TYPES[trim($docType)]) || in_array($docTypeId, HR_REQUIRED_DOC_TYPES, true);
}

/**
 * Upload forms: a required type needs an expiry date, or "No expiry date (N/A)"
 * ticked on purpose. Returns the message to show, or null when it is fine.
 */
function hr_required_docs_expiry_error(string $docType, int $docTypeId, string $expiresAt, bool $noExpiry): ?string
{
    if (!hr_required_docs_is_required_type($docType, $docTypeId) || trim($expiresAt) !== '' || $noExpiry) {
        return null;
    }
    return 'Please fill in the Expiry Date or tick the N/A field.';
}

/**
 * Wires an upload form's expiry field: inside any element with
 * data-expiry-rule, the [data-req-type] select (name or type id), the
 * [data-req-expiry] date and the [data-req-na] tick box. For a required type
 * the date is required unless N/A is ticked; ticking N/A clears and locks the date.
 */
function hr_required_docs_expiry_field_js(): string
{
    $names = json_encode(array_keys(HR_REQUIRED_DOC_TYPES));
    $ids = json_encode(array_map('strval', array_values(HR_REQUIRED_DOC_TYPES)));
    $msg = json_encode(hr_required_docs_expiry_error('Passport', 0, '', false));
    return <<<JS
<script>
(function () {
  var NAMES = $names, IDS = $ids, MSG = $msg;
  function sync(box) {
    var type = box.querySelector('[data-req-type]');
    var date = box.querySelector('[data-req-expiry]');
    var na = box.querySelector('[data-req-na]');
    if (!type || !date || !na) return;
    var required = NAMES.indexOf(type.value) !== -1 || IDS.indexOf(type.value) !== -1;
    box.querySelectorAll('[data-req-only]').forEach(function (el) { el.classList.toggle('d-none', !required); });
    if (!required) { na.checked = false; }
    if (na.checked) { date.value = ''; }
    date.disabled = na.checked;
    date.required = required && !na.checked;
    // Our wording instead of the browser's "Please fill in this field."
    date.setCustomValidity(date.required && !date.value ? MSG : '');
  }
  window.hrExpiryRuleSync = function () { document.querySelectorAll('[data-expiry-rule]').forEach(sync); };
  ['change', 'input'].forEach(function (ev) {
    document.addEventListener(ev, function (e) {
      var box = e.target.closest && e.target.closest('[data-expiry-rule]');
      if (box) sync(box);
    });
  });
  document.addEventListener('DOMContentLoaded', window.hrExpiryRuleSync);
})();
</script>
JS;
}

function hr_required_docs_notes_ready(PDO $conn): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        $stmt = $conn->query("
            SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_required_doc_notes'
        ");
        $ready = (int)$stmt->fetchColumn() === 1;
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

/**
 * Missing required types for each employee, in HR_REQUIRED_DOC_TYPES order:
 * [employee_id => ['Visa', ...]]; an empty list means all are present.
 * One query for the whole list, so the Employees page can use it per page.
 */
function hr_required_docs_missing_map(PDO $conn, array $employeeIds): array
{
    $employeeIds = array_values(array_unique(array_filter(array_map('intval', $employeeIds))));
    $present = array_fill_keys($employeeIds, []);
    if ($employeeIds) {
        $idToType = array_flip(HR_REQUIRED_DOC_TYPES);
        $stmt = $conn->prepare("
            SELECT employee_id, doc_type, doc_type_id FROM employee_documents
            WHERE employee_id IN (" . implode(',', array_fill(0, count($employeeIds), '?')) . ")
        ");
        $stmt->execute($employeeIds);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $d) {
            $empId = (int)$d['employee_id'];
            $type = trim((string)$d['doc_type']);
            if (isset(HR_REQUIRED_DOC_TYPES[$type])) {
                $present[$empId][$type] = true;
            }
            $typeId = (int)($d['doc_type_id'] ?? 0);
            if (isset($idToType[$typeId])) {
                $present[$empId][$idToType[$typeId]] = true;
            }
        }
    }

    $missing = [];
    foreach ($present as $empId => $types) {
        $missing[$empId] = array_values(array_diff(array_keys(HR_REQUIRED_DOC_TYPES), array_keys($types)));
    }
    return $missing;
}

/** HR_REQUIRED_DOC_TYPES, or just $onlyType when it is one of them. */
function hr_required_docs_types_for(?string $onlyType): array
{
    return ($onlyType !== null && isset(HR_REQUIRED_DOC_TYPES[$onlyType]))
        ? [$onlyType => HR_REQUIRED_DOC_TYPES[$onlyType]]
        : HR_REQUIRED_DOC_TYPES;
}

/** Filters for the Employees list Docs column, value => label. */
const HR_REQUIRED_DOC_FILTERS = [
    'complete'  => 'All present',
    'explained' => 'Reason given',
    'missing'   => 'Missing, no reason',
];

/**
 * SQL condition on employees $alias for a HR_REQUIRED_DOC_FILTERS value, the
 * same rules as the badge: complete = all five on file; explained = not
 * complete, but every one not on file has a reason; missing = at least one not
 * on file with no reason. With $onlyType, the same rules for that one type.
 * Empty string for an unknown value.
 */
function hr_required_docs_filter_sql(PDO $conn, string $filter, string $alias = 'e', ?string $onlyType = null): string
{
    if (!isset(HR_REQUIRED_DOC_FILTERS[$filter])) {
        return '';
    }
    $notesReady = hr_required_docs_notes_ready($conn);
    $hasDoc = [];
    $covered = [];
    foreach (hr_required_docs_types_for($onlyType) as $type => $typeId) {
        $t = $conn->quote($type);
        $doc = "EXISTS (SELECT 1 FROM employee_documents d WHERE d.employee_id = $alias.id AND (d.doc_type = $t OR d.doc_type_id = " . (int)$typeId . "))";
        $hasDoc[] = $doc;
        $covered[] = $notesReady
            ? "($doc OR EXISTS (SELECT 1 FROM employee_required_doc_notes n WHERE n.employee_id = $alias.id AND n.doc_type = $t))"
            : $doc;
    }
    $complete = '(' . implode(' AND ', $hasDoc) . ')';
    $allCovered = '(' . implode(' AND ', $covered) . ')';
    switch ($filter) {
        case 'complete':
            return $complete;
        case 'explained':
            return "(NOT $complete AND $allCovered)";
        default:
            return "(NOT $allCovered)";
    }
}

/** Expiry filters for the Employees list, value => label. */
const HR_REQUIRED_DOC_EXPIRY_FILTERS = [
    'expiring' => 'Expiring',
    'expired'  => 'Expired',
];

/**
 * SQL condition on employees $alias: at least one required document expiring
 * within HR_REQUIRED_DOC_EXPIRING_DAYS ('expiring') or already expired
 * ('expired'), judged on the newest copy that has a date, as the list chips are.
 * Dates come from PHP so they match the chips. With $onlyType, only that type.
 * Empty string for an unknown value.
 */
function hr_required_docs_expiry_filter_sql(PDO $conn, string $filter, string $alias = 'e', ?string $onlyType = null): string
{
    if (!isset(HR_REQUIRED_DOC_EXPIRY_FILTERS[$filter])) {
        return '';
    }
    $today = $conn->quote(date('Y-m-d'));
    $soon = $conn->quote(date('Y-m-d', strtotime('+' . HR_REQUIRED_DOC_EXPIRING_DAYS . ' days')));
    $parts = [];
    foreach (hr_required_docs_types_for($onlyType) as $type => $typeId) {
        // Expiry of the newest copy that has a date, as the chips show it.
        $latest = "(SELECT d.expires_at FROM employee_documents d WHERE d.employee_id = $alias.id AND (d.doc_type = "
            . $conn->quote($type) . " OR d.doc_type_id = " . (int)$typeId . ") AND d.expires_at IS NOT NULL"
            . " ORDER BY d.created_at DESC, d.id DESC LIMIT 1)";
        $parts[] = $filter === 'expiring' ? "$latest BETWEEN $today AND $soon" : "$latest < $today";
    }
    return '(' . implode(' OR ', $parts) . ')';
}

/** How each type is written on the Employees list. */
const HR_REQUIRED_DOC_LABELS = [
    'Passport'    => 'Passport',
    'Visa'        => 'Visa',
    'LaborCard'   => 'Labor Card',
    'Contract'    => 'Contract',
    'National ID' => 'National ID',
];

/** Days before expiry when a document on file shows as expiring soon. */
const HR_REQUIRED_DOC_EXPIRING_DAYS = 60;

/**
 * Per employee, per required type, for the Employees list chips:
 * [employee_id => [type => ['present' => bool, 'expires_at' => ?string,
 *   'doc_number' => string, 'reason' => string, 'state' => string]]].
 * With several documents of a type, the newest one that has an expiry date is
 * shown (a renewal or correction replaces the old one); copies with no date
 * (back sides, second pages, N/A) count as uploaded but never hide a dated
 * copy. state: 'valid', 'expiring', 'expired', 'explained', 'missing'.
 */
function hr_required_docs_details_map(PDO $conn, array $employeeIds): array
{
    $employeeIds = array_values(array_unique(array_filter(array_map('intval', $employeeIds))));
    $out = [];
    foreach ($employeeIds as $empId) {
        foreach (array_keys(HR_REQUIRED_DOC_TYPES) as $type) {
            $out[$empId][$type] = ['present' => false, 'expires_at' => null, 'doc_number' => '', 'reason' => '', 'state' => 'missing'];
        }
    }
    if (!$employeeIds) {
        return $out;
    }
    $in = implode(',', array_fill(0, count($employeeIds), '?'));
    $idToType = array_flip(HR_REQUIRED_DOC_TYPES);

    // Oldest first, so the newest uploaded copy of a type is the one kept.
    $stmt = $conn->prepare("SELECT employee_id, doc_type, doc_type_id, doc_number, expires_at FROM employee_documents
                            WHERE employee_id IN ($in) ORDER BY created_at, id");
    $stmt->execute($employeeIds);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $d) {
        // Same match as the filters: by name, or by the HR > Documents type id.
        $types = [];
        $name = trim((string)$d['doc_type']);
        if (isset(HR_REQUIRED_DOC_TYPES[$name])) {
            $types[$name] = true;
        }
        $byId = $idToType[(int)($d['doc_type_id'] ?? 0)] ?? null;
        if ($byId !== null) {
            $types[$byId] = true;
        }
        foreach (array_keys($types) as $type) {
            $cell = &$out[(int)$d['employee_id']][$type];
            $exp = $d['expires_at'] ?: null;
            // The newest copy WITH a date decides. A copy with no date (an ID's
            // back side, a contract's page 2, N/A) only counts as uploaded.
            if ($exp !== null || $cell['expires_at'] === null) {
                $cell['expires_at'] = $exp ?? $cell['expires_at'];
                $cell['doc_number'] = (string)($d['doc_number'] ?? '');
            }
            $cell['present'] = true;
            unset($cell);
        }
    }

    if (hr_required_docs_notes_ready($conn)) {
        $stmt = $conn->prepare("SELECT employee_id, doc_type, reason FROM employee_required_doc_notes WHERE employee_id IN ($in)");
        $stmt->execute($employeeIds);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $n) {
            if (isset($out[(int)$n['employee_id']][(string)$n['doc_type']])) {
                $out[(int)$n['employee_id']][(string)$n['doc_type']]['reason'] = (string)$n['reason'];
            }
        }
    }

    $today = date('Y-m-d');
    $soon = date('Y-m-d', strtotime('+' . HR_REQUIRED_DOC_EXPIRING_DAYS . ' days'));
    foreach ($out as &$types) {
        foreach ($types as &$cell) {
            if (!$cell['present']) {
                $cell['state'] = $cell['reason'] !== '' ? 'explained' : 'missing';
                continue;
            }
            $cell['reason'] = '';
            $exp = $cell['expires_at'];
            $cell['state'] = $exp === null || $exp > $soon ? 'valid' : ($exp < $today ? 'expired' : 'expiring');
        }
        unset($cell);
    }
    unset($types);
    return $out;
}

/**
 * One row per required type, in HR_REQUIRED_DOC_TYPES order:
 * ['type', 'present' (bool), 'state', 'reason', 'updated_by_name', 'updated_at'].
 * state is 'present', 'explained' (missing, with a reason) or 'missing'.
 * The reason is only filled in for a type that is not present.
 */
function hr_required_docs_status(PDO $conn, int $employeeId): array
{
    $missing = hr_required_docs_missing_map($conn, [$employeeId])[$employeeId];

    $notes = [];
    if (hr_required_docs_notes_ready($conn)) {
        $stmt = $conn->prepare("
            SELECT n.doc_type, n.reason, n.updated_at,
                   COALESCE(NULLIF(TRIM(u.fullname), ''), u.username) AS updated_by_name
            FROM employee_required_doc_notes n
            LEFT JOIN `user` u ON u.id = n.updated_by
            WHERE n.employee_id = ?
        ");
        $stmt->execute([$employeeId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $n) {
            $notes[(string)$n['doc_type']] = $n;
        }
    }

    $rows = [];
    foreach (array_keys(HR_REQUIRED_DOC_TYPES) as $type) {
        $isPresent = !in_array($type, $missing, true);
        $note = $isPresent ? null : ($notes[$type] ?? null);
        $rows[] = [
            'type'            => $type,
            'present'         => $isPresent,
            'state'           => $isPresent ? 'present' : ($note ? 'explained' : 'missing'),
            'reason'          => $note ? (string)$note['reason'] : '',
            'updated_by_name' => $note ? (string)($note['updated_by_name'] ?? '') : '',
            'updated_at'      => $note ? (string)$note['updated_at'] : '',
        ];
    }
    return $rows;
}

/**
 * Save why a required document is missing; an empty reason removes the note.
 * Returns ['ok' => bool, 'message' => string].
 */
function hr_required_docs_save_reason(PDO $conn, int $employeeId, string $type, string $reason, ?int $userId): array
{
    if (!isset(HR_REQUIRED_DOC_TYPES[$type])) {
        return ['ok' => false, 'message' => 'Unknown document type.'];
    }
    if (!hr_required_docs_notes_ready($conn)) {
        return ['ok' => false, 'message' => 'Reasons are not set up on this server yet. Run migrations/hr_employee_required_docs.sql.'];
    }
    $reason = trim($reason);
    if (mb_strlen($reason) > HR_REQUIRED_DOC_REASON_MAX) {
        return ['ok' => false, 'message' => 'The reason is longer than ' . HR_REQUIRED_DOC_REASON_MAX . ' characters.'];
    }

    if ($reason === '') {
        $conn->prepare("DELETE FROM employee_required_doc_notes WHERE employee_id = ? AND doc_type = ?")
             ->execute([$employeeId, $type]);
        return ['ok' => true, 'message' => $type . ': reason removed.'];
    }

    $conn->prepare("
        INSERT INTO employee_required_doc_notes (employee_id, doc_type, reason, updated_by, updated_at)
        VALUES (?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE reason = VALUES(reason), updated_by = VALUES(updated_by), updated_at = NOW()
    ")->execute([$employeeId, $type, $reason, $userId]);
    return ['ok' => true, 'message' => $type . ': reason saved.'];
}
