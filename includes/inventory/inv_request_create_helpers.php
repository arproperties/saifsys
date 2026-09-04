<?php
/**
 * Material request form: context visibility, display labels, embed paths.
 */

/**
 * Context field keys to show for a source module (hide unrelated modules' fields).
 *
 * @return list<string>
 */
function inv_material_request_visible_context_keys(string $sourceModule): array {
    $sm = strtolower(str_replace(['_', ' '], '', trim($sourceModule)));
    if ($sm === 'realestate') {
        return ['context_building_id', 'context_unit_id', 'context_work_order_id'];
    }
    if ($sm === 'construction') {
        return ['context_project_id'];
    }
    if ($sm === 'ars') {
        return ['context_building_id', 'context_unit_id', 'context_booking_id', 'context_housekeeping_id'];
    }
    if ($sm === 'cleaning') {
        return ['context_cleaning_job_id'];
    }
    return [
        'context_building_id',
        'context_unit_id',
        'context_project_id',
        'context_booking_id',
        'context_work_order_id',
        'context_housekeeping_id',
        'context_cleaning_job_id',
    ];
}

/**
 * Human-readable labels for context IDs (for display next to hidden inputs).
 *
 * @param array<string,int|null> $ctx
 * @return array<string,string|null> key => display text or null
 */
function inv_request_resolve_context_labels(PDO $conn, int $companyId, array $ctx): array {
    $labels = [];
    $bid = !empty($ctx['context_building_id']) ? (int)$ctx['context_building_id'] : 0;
    if ($bid) {
        try {
            $st = $conn->prepare("SELECT name FROM re_buildings WHERE id = ? AND company_id = ? LIMIT 1");
            $st->execute([$bid, $companyId]);
            $labels['context_building_id'] = $st->fetchColumn() ?: ('Building #' . $bid);
        } catch (Throwable $e) {
            $labels['context_building_id'] = 'Building #' . $bid;
        }
    }
    $uid = !empty($ctx['context_unit_id']) ? (int)$ctx['context_unit_id'] : 0;
    if ($uid) {
        try {
            $st = $conn->prepare("
                SELECT u.unit_number, b.name AS building_name
                FROM re_units u
                LEFT JOIN re_buildings b ON b.id = u.building_id AND b.company_id = u.company_id
                WHERE u.id = ? AND u.company_id = ?
                LIMIT 1
            ");
            $st->execute([$uid, $companyId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $labels['context_unit_id'] = trim(($row['building_name'] ? $row['building_name'] . ' · ' : '') . 'Unit ' . ($row['unit_number'] ?? ('#' . $uid)));
            } else {
                $labels['context_unit_id'] = 'Unit #' . $uid;
            }
        } catch (Throwable $e) {
            $labels['context_unit_id'] = 'Unit #' . $uid;
        }
    }
    $pid = !empty($ctx['context_project_id']) ? (int)$ctx['context_project_id'] : 0;
    if ($pid) {
        try {
            $st = $conn->prepare("SELECT project_code, project_name FROM co_projects WHERE id = ? AND company_id = ? LIMIT 1");
            $st->execute([$pid, $companyId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $labels['context_project_id'] = trim(($row['project_code'] ?? '') . ' — ' . ($row['project_name'] ?? ''), ' —');
            } else {
                $labels['context_project_id'] = 'Project #' . $pid;
            }
        } catch (Throwable $e) {
            $labels['context_project_id'] = 'Project #' . $pid;
        }
    }
    $bookId = !empty($ctx['context_booking_id']) ? (int)$ctx['context_booking_id'] : 0;
    if ($bookId) {
        try {
            $st = $conn->prepare("SELECT booking_number FROM ars_bookings WHERE id = ? AND company_id = ? LIMIT 1");
            $st->execute([$bookId, $companyId]);
            $bn = $st->fetchColumn();
            $labels['context_booking_id'] = $bn ? (string)$bn : ('Booking #' . $bookId);
        } catch (Throwable $e) {
            $labels['context_booking_id'] = 'Booking #' . $bookId;
        }
    }
    $wo = !empty($ctx['context_work_order_id']) ? (int)$ctx['context_work_order_id'] : 0;
    if ($wo) {
        $labels['context_work_order_id'] = 'Maintenance / WO ref #' . $wo;
    }
    $hk = !empty($ctx['context_housekeeping_id']) ? (int)$ctx['context_housekeeping_id'] : 0;
    if ($hk) {
        $labels['context_housekeeping_id'] = 'Housekeeping #' . $hk;
    }
    $cj = !empty($ctx['context_cleaning_job_id']) ? (int)$ctx['context_cleaning_job_id'] : 0;
    if ($cj) {
        $labels['context_cleaning_job_id'] = 'Work order #' . $cj;
    }
    return $labels;
}

/**
 * Friendly titles for context keys.
 *
 * @return array<string,string>
 */
function inv_material_request_context_field_titles(): array {
    return [
        'context_building_id' => 'Building',
        'context_unit_id' => 'Unit',
        'context_project_id' => 'Project',
        'context_booking_id' => 'Booking',
        'context_work_order_id' => 'Work order',
        'context_housekeeping_id' => 'Housekeeping',
        'context_cleaning_job_id' => 'Cleaning job',
    ];
}
