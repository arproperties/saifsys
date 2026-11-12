<?php
/**
 * Real Estate Module - Legal Case View
 * Details, linked records, timeline, documents, notices, status workflow.
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/includes/legal_helper.php';

require_login();
require_module_access($conn, MODULE_LEGAL);
legal_require_access($conn);

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;
$isOwnerAdmin = has_role('Owner', $conn) || has_role('Admin', $conn);
$caseId = !empty($_GET['id']) ? (int)$_GET['id'] : 0;

function legal_load_case(PDO $conn, int $caseId, int $companyId) {
    $stmt = $conn->prepare("
        SELECT c.*, b.name AS building_name, u.unit_number, l.lease_number,
               t.first_name, t.last_name, t.company_name, t.tenant_type, t.email AS tenant_email, t.phone AS tenant_phone,
               o.name AS owner_name,
               COALESCE(NULLIF(usr.fullname,''), usr.username) AS assigned_name,
               COALESCE(NULLIF(cr.fullname,''), cr.username) AS created_name
        FROM re_legal_cases c
        LEFT JOIN re_buildings b ON b.id = c.building_id
        LEFT JOIN re_units u ON u.id = c.unit_id
        LEFT JOIN re_leases l ON l.id = c.lease_id
        LEFT JOIN re_tenants t ON t.id = c.tenant_id
        LEFT JOIN re_property_owners o ON o.id = c.owner_id
        LEFT JOIN user usr ON usr.id = c.assigned_to
        LEFT JOIN user cr ON cr.id = c.created_by
        WHERE c.id = ? AND c.company_id = ? AND c.deleted_at IS NULL
    ");
    $stmt->execute([$caseId, $companyId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

$case = legal_load_case($conn, $caseId, $currentCompanyId);
if (!$case) {
    $_SESSION['error'] = 'Legal case not found.';
    header('Location: legal_case_list.php');
    exit;
}

// ---- POST actions ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'update_status') {
        $newStatus = $_POST['status'] ?? '';
        $allowed = legal_allowed_transitions($case['status']);
        if (!array_key_exists($newStatus, legal_case_statuses())) {
            $_SESSION['error'] = 'Invalid status.';
        } elseif ($newStatus === $case['status']) {
            $_SESSION['error'] = 'Status unchanged.';
        } elseif (!in_array($newStatus, $allowed, true)) {
            $_SESSION['error'] = 'That status transition is not allowed.';
        } else {
            $sets = "status = ?, updated_at = NOW()";
            $vals = [$newStatus];
            if ($newStatus === 'filed' && empty($case['filed_date'])) { $sets .= ", filed_date = CURDATE()"; }
            if (legal_status_is_terminal($newStatus)) { $sets .= ", closed_date = CURDATE()"; }
            if (!legal_status_is_terminal($newStatus) && $case['status'] !== 'draft' && in_array($case['status'], ['settled','closed','withdrawn'], true)) {
                $sets .= ", closed_date = NULL";
            }
            $vals[] = $caseId; $vals[] = $currentCompanyId;
            $conn->prepare("UPDATE re_legal_cases SET $sets WHERE id = ? AND company_id = ?")->execute($vals);
            legal_log_event($conn, $caseId, 'status_change', 'Status changed', legal_case_statuses()[$case['status']] ?? $case['status'], legal_case_statuses()[$newStatus] ?? $newStatus);
            legal_audit('update', 're_legal_case', $caseId, 'Legal case ' . $case['case_number'] . ' status: ' . $case['status'] . ' -> ' . $newStatus);
            $_SESSION['success'] = 'Status updated.';
        }
        header('Location: legal_case_view.php?id=' . $caseId); exit;
    }

    if ($action === 'add_note') {
        $note = trim($_POST['note'] ?? '');
        if ($note !== '') {
            legal_log_event($conn, $caseId, 'note', $note);
            $_SESSION['success'] = 'Note added.';
        }
        header('Location: legal_case_view.php?id=' . $caseId); exit;
    }

    if ($action === 'add_link') {
        $type = $_POST['link_type'] ?? '';
        $linkId = (int)($_POST['link_id'] ?? 0);
        if (legal_add_link($conn, $caseId, $type, $linkId)) {
            $resolved = legal_resolve_link($conn, $type, $linkId);
            legal_log_event($conn, $caseId, 'link_added', 'Linked ' . (legal_link_types()[$type] ?? $type) . ': ' . $resolved['label']);
            $_SESSION['success'] = 'Link added.';
        } else {
            $_SESSION['error'] = 'Could not add link.';
        }
        header('Location: legal_case_view.php?id=' . $caseId); exit;
    }

    if ($action === 'remove_link') {
        $linkId = (int)($_POST['link_row_id'] ?? 0);
        $conn->prepare("DELETE FROM re_legal_case_links WHERE id = ? AND case_id = ?")->execute([$linkId, $caseId]);
        $_SESSION['success'] = 'Link removed.';
        header('Location: legal_case_view.php?id=' . $caseId); exit;
    }

    if ($action === 'add_cost') {
        $costType = $_POST['cost_type'] ?? 'other';
        $amount = (float)($_POST['amount'] ?? 0);
        if (!array_key_exists($costType, legal_cost_types()) || $amount <= 0) {
            $_SESSION['error'] = 'Valid cost type and amount required.';
        } else {
            $conn->prepare("
                INSERT INTO re_legal_case_costs (company_id, case_id, cost_type, description, amount, cost_date, counsel_id, invoice_reference, is_recoverable, created_by, created_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,NOW())
            ")->execute([
                $currentCompanyId, $caseId, $costType, trim($_POST['description'] ?? '') ?: null, $amount,
                $_POST['cost_date'] ?: date('Y-m-d'),
                !empty($_POST['counsel_id']) ? (int)$_POST['counsel_id'] : null,
                trim($_POST['invoice_reference'] ?? '') ?: null,
                !empty($_POST['is_recoverable']) ? 1 : 0, current_user_id(),
            ]);
            legal_log_event($conn, $caseId, 'payment', 'Cost recorded: ' . (legal_cost_types()[$costType] ?? $costType) . ' ' . number_format($amount, 2) . ' AED');
            $_SESSION['success'] = 'Cost recorded.';
        }
        header('Location: legal_case_view.php?id=' . $caseId); exit;
    }

    // ---- Stages / offers / differences / review (migrations/legal_tenant_timeline.sql) ----
    $schemaReady = legal_timeline_schema_ready($conn);
    $dateOrNull = function ($v) { $v = trim((string)$v); return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null; };
    $amountOrNull = function ($v) { $v = trim((string)$v); return $v === '' ? null : round((float)str_replace(',', '', $v), 2); };
    $back = 'Location: legal_case_view.php?id=' . $caseId;

    if ($schemaReady && $action === 'add_stage') {
        $type = $_POST['stage_type'] ?? 'other';
        if (!array_key_exists($type, legal_stage_types())) $type = 'other';
        $conn->prepare("
            INSERT INTO re_legal_case_stages (company_id, case_id, stage_type, reference_number, stage_date, outcome, awarded_amount, next_date, created_by)
            VALUES (?,?,?,?,?,?,?,?,?)
        ")->execute([$currentCompanyId, $caseId, $type, trim($_POST['reference_number'] ?? '') ?: null,
            $dateOrNull($_POST['stage_date'] ?? ''), trim($_POST['outcome'] ?? '') ?: null,
            $amountOrNull($_POST['awarded_amount'] ?? ''), $dateOrNull($_POST['next_date'] ?? ''), current_user_id()]);
        legal_log_event($conn, $caseId, 'note', 'Stage added: ' . legal_stage_types()[$type] . (trim($_POST['reference_number'] ?? '') !== '' ? ' ' . trim($_POST['reference_number']) : ''));
        $_SESSION['success'] = 'Stage added.';
        header($back . '#stages'); exit;
    }
    if ($schemaReady && $action === 'delete_stage') {
        $conn->prepare("DELETE FROM re_legal_case_stages WHERE id = ? AND case_id = ? AND company_id = ?")->execute([(int)($_POST['stage_id'] ?? 0), $caseId, $currentCompanyId]);
        legal_log_event($conn, $caseId, 'note', 'Stage removed');
        $_SESSION['success'] = 'Stage removed.';
        header($back . '#stages'); exit;
    }
    if ($schemaReady && $action === 'add_offer') {
        $by = $_POST['offered_by'] ?? 'tenant';
        if (!array_key_exists($by, legal_offer_parties())) $by = 'tenant';
        $os = $_POST['offer_status'] ?? 'pending';
        if (!array_key_exists($os, legal_offer_statuses())) $os = 'pending';
        $conn->prepare("
            INSERT INTO re_legal_settlement_offers (company_id, case_id, offered_by, amount, offer_date, status, response_date, notes, created_by)
            VALUES (?,?,?,?,?,?,?,?,?)
        ")->execute([$currentCompanyId, $caseId, $by, $amountOrNull($_POST['amount'] ?? ''), $dateOrNull($_POST['offer_date'] ?? ''),
            $os, $dateOrNull($_POST['response_date'] ?? ''), trim($_POST['notes'] ?? '') ?: null, current_user_id()]);
        legal_log_event($conn, $caseId, 'note', 'Settlement offer recorded (' . legal_offer_parties()[$by] . ', ' . legal_offer_statuses()[$os] . ')');
        $_SESSION['success'] = 'Offer recorded.';
        header($back . '#offers'); exit;
    }
    if ($schemaReady && $action === 'update_offer') {
        $os = $_POST['offer_status'] ?? '';
        if (array_key_exists($os, legal_offer_statuses())) {
            $conn->prepare("UPDATE re_legal_settlement_offers SET status = ?, response_date = COALESCE(?, response_date) WHERE id = ? AND case_id = ? AND company_id = ?")
                 ->execute([$os, $dateOrNull($_POST['response_date'] ?? ''), (int)($_POST['offer_id'] ?? 0), $caseId, $currentCompanyId]);
            legal_log_event($conn, $caseId, 'note', 'Settlement offer marked ' . legal_offer_statuses()[$os]);
            $_SESSION['success'] = 'Offer updated.';
        }
        header($back . '#offers'); exit;
    }
    if ($schemaReady && $action === 'mark_reviewed') {
        legal_clear_case_review($conn, $caseId, $currentCompanyId);
        legal_log_event($conn, $caseId, 'note', 'Imported entries checked and confirmed');
        legal_audit('update', 're_legal_case', $caseId, 'Legal case ' . $case['case_number'] . ' imported data marked checked');
        $_SESSION['success'] = 'Marked as checked.';
        header($back); exit;
    }
    if ($schemaReady && in_array($action, ['add_check', 'resolve_check', 'reopen_check'], true)) {
        if ($action === 'add_check' && trim($_POST['topic'] ?? '') !== '') {
            $conn->prepare("
                INSERT INTO re_legal_data_checks (company_id, tenant_id, lease_id, case_id, topic, source_label, source_value, system_value, created_by)
                VALUES (?,?,?,?,?,?,?,?,?)
            ")->execute([$currentCompanyId, $case['tenant_id'] ?: null, $case['lease_id'] ?: null, $caseId, trim($_POST['topic']),
                trim($_POST['source_label'] ?? '') ?: null, trim($_POST['source_value'] ?? '') ?: null, trim($_POST['system_value'] ?? '') ?: null, current_user_id()]);
            $_SESSION['success'] = 'Difference recorded.';
        } elseif ($action === 'resolve_check') {
            $conn->prepare("UPDATE re_legal_data_checks SET status = 'resolved', resolution = ?, resolved_by = ?, resolved_at = NOW() WHERE id = ? AND case_id = ? AND company_id = ?")
                 ->execute([trim($_POST['resolution'] ?? '') ?: null, current_user_id(), (int)($_POST['check_id'] ?? 0), $caseId, $currentCompanyId]);
            $_SESSION['success'] = 'Marked resolved.';
        } elseif ($action === 'reopen_check') {
            $conn->prepare("UPDATE re_legal_data_checks SET status = 'open', resolved_by = NULL, resolved_at = NULL WHERE id = ? AND case_id = ? AND company_id = ?")
                 ->execute([(int)($_POST['check_id'] ?? 0), $caseId, $currentCompanyId]);
            $_SESSION['success'] = 'Reopened.';
        }
        header($back . '#checks'); exit;
    }

    if ($action === 'delete_case' && $isOwnerAdmin) {
        $conn->prepare("UPDATE re_legal_cases SET deleted_at = NOW(), deleted_by = ? WHERE id = ? AND company_id = ?")
             ->execute([current_user_id(), $caseId, $currentCompanyId]);
        legal_audit('delete', 're_legal_case', $caseId, 'Deleted legal case ' . $case['case_number']);
        $_SESSION['success'] = 'Case deleted.';
        header('Location: legal_case_list.php'); exit;
    }
}

// ---- Load related data ----
$links = legal_fetch_links($conn, $caseId);

$events = $conn->prepare("
    SELECT e.*, COALESCE(NULLIF(usr.fullname,''), usr.username) AS user_name
    FROM re_legal_case_events e
    LEFT JOIN user usr ON usr.id = e.created_by
    WHERE e.case_id = ? ORDER BY e.created_at DESC, e.id DESC
");
$events->execute([$caseId]);
$events = $events->fetchAll(PDO::FETCH_ASSOC);

$notices = $conn->prepare("SELECT * FROM re_legal_notices WHERE case_id = ? ORDER BY created_at DESC");
$notices->execute([$caseId]);
$notices = $notices->fetchAll(PDO::FETCH_ASSOC);

$docs = $conn->prepare("
    SELECT d.*, dt.document_type_name
    FROM re_documents d
    LEFT JOIN re_document_types dt ON dt.id = d.document_type_id
    WHERE d.company_id = ? AND d.related_type = 'legal_case' AND d.related_id = ?
    ORDER BY d.created_at DESC
");
$docs->execute([$currentCompanyId, $caseId]);
$docs = $docs->fetchAll(PDO::FETCH_ASSOC);

$costsStmt = $conn->prepare("
    SELECT co.*, csel.name AS counsel_name FROM re_legal_case_costs co
    LEFT JOIN re_legal_counsel csel ON csel.id = co.counsel_id
    WHERE co.case_id = ? ORDER BY co.cost_date DESC, co.id DESC
");
$costsStmt->execute([$caseId]);
$caseCosts = $costsStmt->fetchAll(PDO::FETCH_ASSOC);
$caseCostTotal = legal_case_cost_total($conn, $caseId);
$counselList = legal_fetch_counsel($conn, $currentCompanyId);

$schemaReady = legal_timeline_schema_ready($conn);
$stages = legal_fetch_case_stages($conn, $caseId);
$offers = legal_fetch_case_offers($conn, $caseId);
$dataChecks = $schemaReady ? legal_fetch_data_checks($conn, $currentCompanyId, $caseId) : [];
$hearingsStmt = $conn->prepare("SELECT * FROM re_legal_hearings WHERE case_id = ? AND company_id = ? ORDER BY scheduled_date, id");
$hearingsStmt->execute([$caseId, $currentCompanyId]);
$hearings = $hearingsStmt->fetchAll(PDO::FETCH_ASSOC);
$needsReview = !empty($case['needs_review'])
    || array_filter($stages, fn($s) => !empty($s['needs_review']))
    || array_filter($offers, fn($o) => !empty($o['needs_review']))
    || array_filter($hearings, fn($x) => !empty($x['needs_review']))
    || array_filter($notices, fn($n) => !empty($n['needs_review']));

// Rooms on the lease (multi-unit leases list every room)
$caseRooms = '';
if (!empty($case['lease_id'])) {
    require_once __DIR__ . '/../realestate/includes/tenant_timeline.php';
    $rs = $conn->prepare("SELECT u.unit_number FROM re_lease_units lu JOIN re_units u ON u.id = lu.unit_id WHERE lu.lease_id = ? ORDER BY lu.sort_order, lu.id");
    $rs->execute([(int)$case['lease_id']]);
    $roomList = $rs->fetchAll(PDO::FETCH_COLUMN);
    if (!$roomList && $case['unit_number'] !== null) $roomList = [$case['unit_number']];
    $caseRooms = $roomList ? (count($roomList) . ' room' . (count($roomList) === 1 ? '' : 's') . ': ' . tt_rooms_label($roomList)) : '';
}

// Primary (denormalized) context as deep links (open Real Estate records in sibling module)
$rePath = legal_re_path();
$primaryLinks = [];
if ($case['building_id']) $primaryLinks[] = ['label' => 'Building: ' . ($case['building_name'] ?: ('#' . $case['building_id'])), 'url' => $rePath . 'buildings.php', 'icon' => 'building'];
if ($case['unit_id'])     $primaryLinks[] = ['label' => 'Unit: ' . ($case['unit_number'] ?: ('#' . $case['unit_id'])), 'url' => $rePath . 'unit_view.php?id=' . $case['unit_id'], 'icon' => 'door-open'];
if ($case['tenant_id'])   $primaryLinks[] = ['label' => 'Tenant: ' . legal_tenant_name($case), 'url' => $rePath . 'tenant_view.php?id=' . $case['tenant_id'], 'icon' => 'person'];
if ($case['lease_id'])    $primaryLinks[] = ['label' => 'Lease: ' . ($case['lease_number'] ?: ('#' . $case['lease_id'])), 'url' => $rePath . 'lease_view.php?id=' . $case['lease_id'], 'icon' => 'file-text'];
if ($case['owner_id'])    $primaryLinks[] = ['label' => 'Owner: ' . ($case['owner_name'] ?: ('#' . $case['owner_id'])), 'url' => null, 'icon' => 'person-badge'];
if ($case['primary_cheque_id']) {
    $r = legal_resolve_link($conn, 'cheque', (int)$case['primary_cheque_id']);
    $primaryLinks[] = ['label' => 'Cheque: ' . $r['label'], 'url' => $r['url'], 'icon' => 'bank'];
}

$deadlineState = legal_deadline_state($case['deadline_date'], (int)$case['reminder_days_before'], $case['status']);
$allowedTransitions = legal_allowed_transitions($case['status']);

$pageTitle = 'Case ' . $case['case_number'];
require_once __DIR__ . '/includes/legal_layout_header.php';
?>
        <div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
            <div>
                <h1 class="mb-1"><i class="bi bi-briefcase"></i> <?= h($case['case_number']) ?></h1>
                <div class="fs-5"><?= h($case['title']) ?></div>
                <div class="mt-2 d-flex flex-wrap gap-2 align-items-center">
                    <span class="badge bg-<?= legal_status_color($case['status']) ?>"><?= h(legal_case_statuses()[$case['status']] ?? $case['status']) ?></span>
                    <span class="badge bg-<?= legal_priority_color($case['priority']) ?>"><?= h(legal_priorities()[$case['priority']] ?? $case['priority']) ?> priority</span>
                    <span class="badge bg-light text-dark border"><?= h(legal_case_types()[$case['case_type']] ?? $case['case_type']) ?></span>
                    <span class="badge bg-light text-dark border">Source: <?= h(legal_case_sources()[$case['case_source']] ?? $case['case_source']) ?></span>
                    <?= legal_deadline_badge($case['deadline_date'], (int)$case['reminder_days_before'], $case['status']) ?>
                </div>
            </div>
            <div class="d-flex gap-2">
                <?php if (!empty($case['tenant_id'])): ?>
                <a href="legal_tenant_timeline.php?tenant_id=<?= (int)$case['tenant_id'] ?>" class="btn btn-outline-secondary"><i class="bi bi-clock-history"></i> Tenant Timeline</a>
                <?php endif; ?>
                <a href="legal_case_edit.php?id=<?= (int)$caseId ?>" class="btn btn-outline-primary"><i class="bi bi-pencil"></i> Edit</a>
                <a href="legal_notice_add.php?case_id=<?= (int)$caseId ?>" class="btn btn-outline-dark"><i class="bi bi-envelope-paper"></i> New Notice</a>
                <a href="legal_case_list.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Back</a>
            </div>
        </div>

        <?php if (!empty($_SESSION['success'])): ?><div class="alert alert-success"><?= h($_SESSION['success']) ?></div><?php unset($_SESSION['success']); endif; ?>
        <?php if (!empty($_SESSION['error'])): ?><div class="alert alert-danger"><?= h($_SESSION['error']) ?></div><?php unset($_SESSION['error']); endif; ?>

        <?php if ($schemaReady && $needsReview): ?>
            <div class="alert alert-warning d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div><i class="bi bi-exclamation-triangle"></i> <strong>Imported — not yet checked.</strong>
                    Entries marked <span class="badge bg-warning text-dark">Check</span> came from <?= h($case['review_note'] ?: 'an outside document') ?>. Check them against the court papers, then confirm.</div>
                <form method="POST" onsubmit="return confirm('Confirm you have checked this case, its stages, offers, hearings and notices?');">
                    <?= csrf_field() ?><input type="hidden" name="action" value="mark_reviewed">
                    <button class="btn btn-sm btn-success"><i class="bi bi-check2-all"></i> I checked it</button>
                </form>
            </div>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-lg-8">
                <!-- Details -->
                <div class="card mb-4"><div class="card-header"><h5 class="mb-0"><i class="bi bi-info-circle"></i> Case Details</h5></div><div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-3"><small class="text-muted">Claim Amount</small><br><strong><?= number_format((float)$case['claim_amount'], 2) ?> AED</strong></div>
                        <div class="col-md-3"><small class="text-muted">Recovered</small><br><strong class="text-success"><?= number_format((float)$case['recovered_amount'], 2) ?> AED</strong></div>
                        <div class="col-md-3"><small class="text-muted">Jurisdiction</small><br><?= h($case['jurisdiction'] ?: '-') ?></div>
                        <div class="col-md-3"><small class="text-muted">External Ref</small><br><?= h($case['external_reference'] ?: '-') ?></div>
                        <div class="col-md-6"><small class="text-muted">Claimant</small><br><?= h(($case['claimant_name'] ?? '') ?: '-') ?></div>
                        <div class="col-md-6"><small class="text-muted">Rooms on lease</small><br><?= h($caseRooms ?: '-') ?></div>
                        <div class="col-md-3"><small class="text-muted">Opened</small><br><?= $case['opened_date'] ? h(date('M d, Y', strtotime($case['opened_date']))) : '-' ?></div>
                        <div class="col-md-3"><small class="text-muted">Filed</small><br><?= $case['filed_date'] ? h(date('M d, Y', strtotime($case['filed_date']))) : '-' ?></div>
                        <div class="col-md-3"><small class="text-muted">Next Action</small><br><?= $case['next_action_date'] ? h(date('M d, Y', strtotime($case['next_action_date']))) : '-' ?></div>
                        <div class="col-md-3">
                            <small class="text-muted">Deadline</small><br>
                            <span class="<?= $deadlineState === 'overdue' ? 'text-danger fw-bold' : ($deadlineState === 'due_soon' ? 'text-warning fw-bold' : '') ?>">
                                <?= $case['deadline_date'] ? h(date('M d, Y', strtotime($case['deadline_date']))) : '-' ?>
                            </span>
                        </div>
                        <div class="col-md-3"><small class="text-muted">Assigned To</small><br><?= h($case['assigned_name'] ?: 'Unassigned') ?></div>
                        <div class="col-md-3"><small class="text-muted">Created By</small><br><?= h($case['created_name'] ?: '-') ?></div>
                        <div class="col-md-6"><small class="text-muted">Closed</small><br><?= $case['closed_date'] ? h(date('M d, Y', strtotime($case['closed_date']))) : '-' ?></div>
                        <?php if ($case['description']): ?>
                        <div class="col-12"><small class="text-muted">Description</small><br><?= nl2br(h($case['description'])) ?></div>
                        <?php endif; ?>
                    </div>
                </div></div>

                <?php if ($schemaReady): ?>
                <!-- Stages -->
                <div class="card mb-4" id="stages"><div class="card-header"><h5 class="mb-0"><i class="bi bi-diagram-3"></i> Case Stages (<?= count($stages) ?>)</h5></div><div class="card-body">
                    <?php if (!$stages): ?><p class="text-muted">No stages yet. Add filing, settlement department, judgment, appeal and execution as they happen.</p>
                    <?php else: ?>
                    <div class="table-responsive mb-3"><table class="table table-sm align-middle mb-0">
                        <thead><tr><th>Stage</th><th>Number</th><th>Date</th><th>Outcome</th><th class="text-end">Awarded</th><th>Next date</th><th></th></tr></thead><tbody>
                        <?php foreach ($stages as $s): ?>
                            <tr>
                                <td><strong><?= h(legal_stage_types()[$s['stage_type']] ?? $s['stage_type']) ?></strong>
                                    <?php if (!empty($s['needs_review'])): ?><span class="badge bg-warning text-dark">Check</span><?php endif; ?></td>
                                <td><?= h($s['reference_number'] ?: '-') ?></td>
                                <td class="text-nowrap"><small><?= $s['stage_date'] ? h(date('d M Y', strtotime($s['stage_date']))) : '<span class="text-muted">not known</span>' ?></small></td>
                                <td><small><?= nl2br(h($s['outcome'] ?: '-')) ?></small></td>
                                <td class="text-end text-nowrap"><?= $s['awarded_amount'] !== null ? number_format((float)$s['awarded_amount'], 2) : '-' ?></td>
                                <td class="text-nowrap"><small><?= $s['next_date'] ? h(date('d M Y', strtotime($s['next_date']))) : '-' ?></small></td>
                                <td class="text-end">
                                    <form method="POST" onsubmit="return confirm('Remove this stage?');"><?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_stage"><input type="hidden" name="stage_id" value="<?= (int)$s['id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger"><i class="bi bi-x"></i></button></form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody></table></div>
                    <?php endif; ?>
                    <form method="POST" class="row g-2 border-top pt-3"><?= csrf_field() ?><input type="hidden" name="action" value="add_stage">
                        <div class="col-md-3"><select name="stage_type" class="form-select form-select-sm"><?php foreach (legal_stage_types() as $k => $v): ?><option value="<?= h($k) ?>"><?= h($v) ?></option><?php endforeach; ?></select></div>
                        <div class="col-md-3"><input type="text" name="reference_number" class="form-control form-control-sm" placeholder="Number e.g. 24694/2026"></div>
                        <div class="col-md-3"><input type="date" name="stage_date" class="form-control form-control-sm" title="Stage date"></div>
                        <div class="col-md-3"><input type="text" name="awarded_amount" class="form-control form-control-sm" placeholder="Awarded AED (optional)"></div>
                        <div class="col-md-6"><input type="text" name="outcome" class="form-control form-control-sm" placeholder="Outcome / what was decided"></div>
                        <div class="col-md-4"><input type="date" name="next_date" class="form-control form-control-sm" title="Next date"></div>
                        <div class="col-md-2"><button class="btn btn-sm btn-primary w-100"><i class="bi bi-plus"></i> Add</button></div>
                    </form>
                </div></div>

                <!-- Hearings & eviction -->
                <div class="card mb-4" id="hearings"><div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-calendar-event"></i> Hearings &amp; Eviction (<?= count($hearings) ?>)</h5>
                    <a href="legal_hearing_add.php?case_id=<?= (int)$caseId ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-plus"></i> Add date</a>
                </div><div class="card-body">
                    <?php if (!$hearings): ?><p class="text-muted mb-0">No hearings or eviction date.</p>
                    <?php else: ?>
                    <div class="table-responsive"><table class="table table-sm align-middle mb-0">
                        <thead><tr><th>Date</th><th>Type</th><th>Title</th><th>Status</th></tr></thead><tbody>
                        <?php foreach ($hearings as $hr): ?>
                            <tr class="<?= $hr['event_type'] === 'eviction' ? 'table-danger' : '' ?>">
                                <td class="text-nowrap"><strong><?= h(date('d M Y', strtotime($hr['scheduled_date']))) ?></strong><?= $hr['scheduled_time'] ? '<br><small>' . h(substr($hr['scheduled_time'], 0, 5)) . '</small>' : '' ?></td>
                                <td><?= h(legal_hearing_types()[$hr['event_type']] ?? $hr['event_type']) ?></td>
                                <td><?= h($hr['title']) ?><?php if (!empty($hr['needs_review'])): ?> <span class="badge bg-warning text-dark">Check</span><?php endif; ?>
                                    <?php if ($hr['description']): ?><br><small class="text-muted"><?= nl2br(h($hr['description'])) ?></small><?php endif; ?></td>
                                <td><span class="badge bg-secondary"><?= h(legal_hearing_statuses()[$hr['status']] ?? $hr['status']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody></table></div>
                    <?php endif; ?>
                </div></div>

                <!-- Settlement offers -->
                <div class="card mb-4" id="offers"><div class="card-header"><h5 class="mb-0"><i class="bi bi-hand-thumbs-up"></i> Settlement Offers (<?= count($offers) ?>)</h5></div><div class="card-body">
                    <?php if (!$offers): ?><p class="text-muted">No offers recorded.</p>
                    <?php else: ?>
                    <div class="table-responsive mb-3"><table class="table table-sm align-middle mb-0">
                        <thead><tr><th>Date</th><th>By</th><th class="text-end">Amount</th><th>Status</th><th>Notes</th><th></th></tr></thead><tbody>
                        <?php foreach ($offers as $o): ?>
                            <tr>
                                <td class="text-nowrap"><small><?= $o['offer_date'] ? h(date('d M Y', strtotime($o['offer_date']))) : '-' ?></small></td>
                                <td><?= h(legal_offer_parties()[$o['offered_by']] ?? $o['offered_by']) ?></td>
                                <td class="text-end text-nowrap"><?= $o['amount'] !== null ? number_format((float)$o['amount'], 2) : '<span class="text-muted">not known</span>' ?></td>
                                <td><span class="badge bg-<?= legal_offer_status_color($o['status']) ?>"><?= h(legal_offer_statuses()[$o['status']] ?? $o['status']) ?></span>
                                    <?= $o['response_date'] ? '<br><small>' . h(date('d M Y', strtotime($o['response_date']))) . '</small>' : '' ?>
                                    <?php if (!empty($o['needs_review'])): ?><span class="badge bg-warning text-dark">Check</span><?php endif; ?></td>
                                <td><small><?= nl2br(h($o['notes'] ?: '-')) ?></small></td>
                                <td style="min-width:190px">
                                    <form method="POST" class="d-flex gap-1"><?= csrf_field() ?>
                                        <input type="hidden" name="action" value="update_offer"><input type="hidden" name="offer_id" value="<?= (int)$o['id'] ?>">
                                        <select name="offer_status" class="form-select form-select-sm"><?php foreach (legal_offer_statuses() as $k => $v): ?><option value="<?= h($k) ?>" <?= $o['status'] === $k ? 'selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?></select>
                                        <button class="btn btn-sm btn-outline-primary">Save</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody></table></div>
                    <?php endif; ?>
                    <form method="POST" class="row g-2 border-top pt-3"><?= csrf_field() ?><input type="hidden" name="action" value="add_offer">
                        <div class="col-md-3"><select name="offered_by" class="form-select form-select-sm"><?php foreach (legal_offer_parties() as $k => $v): ?><option value="<?= h($k) ?>"><?= h($v) ?></option><?php endforeach; ?></select></div>
                        <div class="col-md-3"><input type="text" name="amount" class="form-control form-control-sm" placeholder="Amount AED"></div>
                        <div class="col-md-3"><input type="date" name="offer_date" class="form-control form-control-sm" title="Offer date"></div>
                        <div class="col-md-3"><select name="offer_status" class="form-select form-select-sm"><?php foreach (legal_offer_statuses() as $k => $v): ?><option value="<?= h($k) ?>"><?= h($v) ?></option><?php endforeach; ?></select></div>
                        <div class="col-md-4"><input type="date" name="response_date" class="form-control form-control-sm" title="Our answer date"></div>
                        <div class="col-md-6"><input type="text" name="notes" class="form-control form-control-sm" placeholder="Notes"></div>
                        <div class="col-md-2"><button class="btn btn-sm btn-primary w-100"><i class="bi bi-plus"></i> Add</button></div>
                    </form>
                </div></div>

                <!-- Document vs saifsys -->
                <div class="card mb-4" id="checks"><div class="card-header"><h5 class="mb-0"><i class="bi bi-file-earmark-diff"></i> Document vs saifsys (<?= count(array_filter($dataChecks, fn($c) => $c['status'] === 'open')) ?> open)</h5></div><div class="card-body">
                    <?php if (!$dataChecks): ?><p class="text-muted">No differences recorded for this case.</p>
                    <?php else: ?>
                    <div class="table-responsive mb-3"><table class="table table-sm align-middle mb-0">
                        <thead><tr><th>Topic</th><th>Document says</th><th>saifsys says</th><th>Status</th><th></th></tr></thead><tbody>
                        <?php foreach ($dataChecks as $dc): ?>
                            <tr>
                                <td><strong><?= h($dc['topic']) ?></strong><?= $dc['lease_number'] ? '<br><small class="text-muted">' . h($dc['lease_number']) . '</small>' : '' ?></td>
                                <td class="small" style="background:#fff8e1"><?php if ($dc['source_label']): ?><span class="text-muted d-block"><?= h($dc['source_label']) ?></span><?php endif; ?><?= nl2br(h($dc['source_value'])) ?></td>
                                <td class="small" style="background:#e8f4fd"><?= nl2br(h($dc['system_value'])) ?></td>
                                <td><?php if ($dc['status'] === 'open'): ?><span class="badge bg-warning text-dark">Open</span><?php else: ?><span class="badge bg-success">Resolved</span><?= $dc['resolution'] ? '<div class="small mt-1">' . nl2br(h($dc['resolution'])) . '</div>' : '' ?><?php endif; ?></td>
                                <td style="min-width:200px">
                                    <?php if ($dc['status'] === 'open'): ?>
                                        <form method="POST" class="d-flex gap-1"><?= csrf_field() ?><input type="hidden" name="action" value="resolve_check"><input type="hidden" name="check_id" value="<?= (int)$dc['id'] ?>">
                                            <input type="text" name="resolution" class="form-control form-control-sm" placeholder="What is correct?" required>
                                            <button class="btn btn-sm btn-success"><i class="bi bi-check2"></i></button></form>
                                    <?php else: ?>
                                        <form method="POST"><?= csrf_field() ?><input type="hidden" name="action" value="reopen_check"><input type="hidden" name="check_id" value="<?= (int)$dc['id'] ?>"><button class="btn btn-sm btn-outline-secondary">Reopen</button></form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody></table></div>
                    <?php endif; ?>
                    <form method="POST" class="row g-2 border-top pt-3"><?= csrf_field() ?><input type="hidden" name="action" value="add_check">
                        <div class="col-md-4"><input type="text" name="topic" class="form-control form-control-sm" placeholder="Topic e.g. Number of rooms" required></div>
                        <div class="col-md-4"><input type="text" name="source_label" class="form-control form-control-sm" placeholder="Document e.g. Judgment PDF"></div>
                        <div class="col-md-4"></div>
                        <div class="col-md-5"><textarea name="source_value" rows="2" class="form-control form-control-sm" placeholder="Document says"></textarea></div>
                        <div class="col-md-5"><textarea name="system_value" rows="2" class="form-control form-control-sm" placeholder="saifsys says"></textarea></div>
                        <div class="col-md-2"><button class="btn btn-sm btn-primary w-100"><i class="bi bi-plus"></i> Add</button></div>
                    </form>
                </div></div>
                <?php endif; ?>

                <!-- Linked records -->
                <div class="card mb-4"><div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-link-45deg"></i> Linked Records</h5>
                </div><div class="card-body">
                    <?php if (!empty($primaryLinks)): ?>
                        <div class="mb-3">
                            <small class="text-muted d-block mb-1">Primary context</small>
                            <?php foreach ($primaryLinks as $pl): ?>
                                <?php if ($pl['url']): ?>
                                    <a href="<?= h($pl['url']) ?>" class="btn btn-sm btn-outline-secondary mb-1"><i class="bi bi-<?= h($pl['icon']) ?>"></i> <?= h($pl['label']) ?></a>
                                <?php else: ?>
                                    <span class="btn btn-sm btn-outline-secondary mb-1 disabled"><i class="bi bi-<?= h($pl['icon']) ?>"></i> <?= h($pl['label']) ?></span>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <small class="text-muted d-block mb-1">Additional links</small>
                    <?php if (empty($links)): ?>
                        <p class="text-muted mb-2">No additional links.</p>
                    <?php else: ?>
                        <div class="table-responsive mb-2"><table class="table table-sm align-middle mb-0">
                            <tbody>
                            <?php foreach ($links as $lk): ?>
                                <tr>
                                    <td style="width:140px"><span class="badge bg-light text-dark border"><?= h(legal_link_types()[$lk['link_type']] ?? $lk['link_type']) ?></span></td>
                                    <td>
                                        <?php if ($lk['url']): ?><a href="<?= h($lk['url']) ?>"><?= h($lk['resolved_label']) ?></a>
                                        <?php else: ?><?= h($lk['resolved_label']) ?><?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <form method="POST" onsubmit="return confirm('Remove this link?');" class="d-inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="remove_link">
                                            <input type="hidden" name="link_row_id" value="<?= (int)$lk['id'] ?>">
                                            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-x"></i></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table></div>
                    <?php endif; ?>

                    <!-- Add link -->
                    <form method="POST" class="row g-2 align-items-end border-top pt-3">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="add_link">
                        <div class="col-md-4">
                            <label class="form-label">Type</label>
                            <select name="link_type" id="linkType" class="form-select form-select-sm" required>
                                <option value="">-- Select --</option>
                                <?php foreach (legal_link_types() as $k => $v): ?>
                                    <option value="<?= h($k) ?>"><?= h($v) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Record</label>
                            <select name="link_id" id="linkId" class="form-select form-select-sm" required disabled>
                                <option value="">Select a type first</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button class="btn btn-primary btn-sm w-100"><i class="bi bi-plus"></i> Link</button>
                        </div>
                    </form>
                </div></div>

                <!-- Legal costs -->
                <div class="card mb-4"><div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-cash-stack"></i> Legal Costs (<?= number_format($caseCostTotal, 2) ?> AED)</h5>
                </div><div class="card-body">
                    <?php if (empty($caseCosts)): ?><p class="text-muted">No costs recorded.</p>
                    <?php else: ?>
                    <div class="table-responsive mb-3"><table class="table table-sm mb-0"><thead><tr><th>Date</th><th>Type</th><th>Description</th><th>Counsel</th><th class="text-end">Amount</th></tr></thead><tbody>
                    <?php foreach ($caseCosts as $co): ?>
                        <tr><td><small><?= h($co['cost_date']) ?></small></td><td><small><?= h(legal_cost_types()[$co['cost_type']] ?? $co['cost_type']) ?></small></td><td><?= h($co['description'] ?: '-') ?></td><td><small><?= h($co['counsel_name'] ?: '-') ?></small></td><td class="text-end"><?= number_format((float)$co['amount'], 2) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody></table></div>
                    <?php endif; ?>
                    <form method="POST" class="row g-2 border-top pt-3">
                        <?= csrf_field() ?><input type="hidden" name="action" value="add_cost">
                        <div class="col-md-2"><select name="cost_type" class="form-select form-select-sm"><?php foreach (legal_cost_types() as $k=>$v): ?><option value="<?= h($k) ?>"><?= h($v) ?></option><?php endforeach; ?></select></div>
                        <div class="col-md-2"><input type="number" step="0.01" name="amount" class="form-control form-control-sm" placeholder="Amount" required></div>
                        <div class="col-md-2"><input type="date" name="cost_date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>"></div>
                        <div class="col-md-3"><input type="text" name="description" class="form-control form-control-sm" placeholder="Description"></div>
                        <div class="col-md-2"><select name="counsel_id" class="form-select form-select-sm"><option value="">Counsel</option><?php foreach ($counselList as $cl): ?><option value="<?= (int)$cl['id'] ?>"><?= h($cl['name']) ?></option><?php endforeach; ?></select></div>
                        <div class="col-md-1"><button class="btn btn-sm btn-primary w-100">Add</button></div>
                    </form>
                </div></div>

                <!-- Documents -->
                <div class="card mb-4"><div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-folder"></i> Documents (<?= count($docs) ?>)</h5>
                    <div class="d-flex gap-2">
                        <a href="legal_documents.php?case_id=<?= (int)$caseId ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-folder2-open"></i> All Case Docs</a>
                        <a href="<?= h($rePath) ?>documents_upload.php?related_type=legal_case&related_id=<?= (int)$caseId ?>&return=legal" class="btn btn-sm btn-outline-primary"><i class="bi bi-upload"></i> Attach</a>
                    </div>
                </div><div class="card-body">
                    <?php if (empty($docs)): ?>
                        <p class="text-muted mb-0">No documents attached to this case.</p>
                    <?php else: ?>
                        <div class="table-responsive"><table class="table table-sm align-middle mb-0">
                            <thead><tr><th>Name</th><th>Type</th><th>Uploaded</th><th></th></tr></thead>
                            <tbody>
                            <?php foreach ($docs as $d): ?>
                                <tr>
                                    <td><?= h($d['document_name'] ?: $d['file_name']) ?></td>
                                    <td><small class="text-muted"><?= h($d['document_type_name'] ?: '-') ?></small></td>
                                    <td><small><?= h(date('M d, Y', strtotime($d['created_at']))) ?></small></td>
                                    <td class="text-end">
                                        <a href="<?= h($rePath) ?>documents_file.php?id=<?= (int)$d['id'] ?>&mode=view" target="_blank" rel="noopener" class="btn btn-sm btn-outline-info"><i class="bi bi-eye"></i></a>
                                        <a href="<?= h($rePath) ?>documents_view.php?id=<?= (int)$d['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-info-circle"></i></a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table></div>
                    <?php endif; ?>
                </div></div>

                <!-- Notices -->
                <div class="card mb-4"><div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-envelope-paper"></i> Legal Notices (<?= count($notices) ?>)</h5>
                    <a href="legal_notice_add.php?case_id=<?= (int)$caseId ?>" class="btn btn-sm btn-outline-dark"><i class="bi bi-plus"></i> New Notice</a>
                </div><div class="card-body">
                    <?php if (empty($notices)): ?>
                        <p class="text-muted mb-0">No notices issued for this case.</p>
                    <?php else: ?>
                        <div class="table-responsive"><table class="table table-sm align-middle mb-0">
                            <thead><tr><th>Reference</th><th>Type</th><th>Issue</th><th>Deadline</th><th>Status</th><th></th></tr></thead>
                            <tbody>
                            <?php foreach ($notices as $n): ?>
                                <tr>
                                    <td><strong><?= h($n['reference_number']) ?></strong><?php if (!empty($n['needs_review'])): ?> <span class="badge bg-warning text-dark">Check</span><?php endif; ?></td>
                                    <td><small><?= h(legal_notice_types()[$n['notice_type']] ?? $n['notice_type']) ?></small></td>
                                    <td><small><?= $n['issue_date'] ? h(date('M d, Y', strtotime($n['issue_date']))) : '-' ?></small></td>
                                    <td><small><?= $n['response_deadline'] ? h(date('M d, Y', strtotime($n['response_deadline']))) : '-' ?></small></td>
                                    <td><span class="badge bg-secondary"><?= h(legal_delivery_statuses()[$n['delivery_status']] ?? $n['delivery_status']) ?></span></td>
                                    <td class="text-end">
                                        <a href="legal_notice_view.php?id=<?= (int)$n['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table></div>
                    <?php endif; ?>
                </div></div>
            </div>

            <div class="col-lg-4">
                <!-- Status workflow -->
                <div class="card mb-4"><div class="card-header"><h5 class="mb-0"><i class="bi bi-arrow-repeat"></i> Update Status</h5></div><div class="card-body">
                    <div class="mb-2">Current: <span class="badge bg-<?= legal_status_color($case['status']) ?>"><?= h(legal_case_statuses()[$case['status']] ?? $case['status']) ?></span></div>
                    <?php if (empty($allowedTransitions)): ?>
                        <p class="text-muted mb-0">No further transitions available.</p>
                    <?php else: ?>
                        <form method="POST" class="d-flex gap-2">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="update_status">
                            <select name="status" class="form-select form-select-sm" required>
                                <option value="">-- Move to --</option>
                                <?php foreach ($allowedTransitions as $st): ?>
                                    <option value="<?= h($st) ?>"><?= h(legal_case_statuses()[$st] ?? $st) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn btn-primary btn-sm">Apply</button>
                        </form>
                    <?php endif; ?>
                </div></div>

                <!-- Add note -->
                <div class="card mb-4"><div class="card-header"><h5 class="mb-0"><i class="bi bi-chat-left-text"></i> Add Note</h5></div><div class="card-body">
                    <form method="POST">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="add_note">
                        <textarea name="note" class="form-control form-control-sm mb-2" rows="3" placeholder="Add an update to the timeline..." required></textarea>
                        <button class="btn btn-outline-primary btn-sm w-100"><i class="bi bi-plus"></i> Add Note</button>
                    </form>
                </div></div>

                <!-- Timeline -->
                <div class="card mb-4"><div class="card-header"><h5 class="mb-0"><i class="bi bi-clock-history"></i> Timeline</h5></div><div class="card-body" style="max-height:480px; overflow-y:auto;">
                    <?php if (empty($events)): ?>
                        <p class="text-muted mb-0">No activity yet.</p>
                    <?php else: foreach ($events as $ev): ?>
                        <div class="border-start ps-3 pb-3 mb-1" style="border-width:2px !important;">
                            <div class="small text-muted"><?= h(date('M d, Y H:i', strtotime($ev['created_at']))) ?> &middot; <?= h($ev['user_name'] ?: 'System') ?></div>
                            <div>
                                <span class="badge bg-light text-dark border"><?= h(str_replace('_', ' ', $ev['event_type'])) ?></span>
                                <?php if ($ev['event_type'] === 'status_change'): ?>
                                    <?= h($ev['old_value']) ?> &rarr; <strong><?= h($ev['new_value']) ?></strong>
                                <?php endif; ?>
                            </div>
                            <?php if ($ev['description']): ?><div class="mt-1"><?= nl2br(h($ev['description'])) ?></div><?php endif; ?>
                        </div>
                    <?php endforeach; endif; ?>
                </div></div>

                <?php if ($isOwnerAdmin): ?>
                <div class="card mb-4 border-danger"><div class="card-body">
                    <form method="POST" onsubmit="return confirm('Delete this legal case? This can only be undone by an administrator.');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete_case">
                        <button class="btn btn-outline-danger btn-sm w-100"><i class="bi bi-trash"></i> Delete Case</button>
                    </form>
                </div></div>
                <?php endif; ?>
            </div>
        </div>

<?php
$pageScripts = <<<'HTML'
<script>
(function () {
    var typeSel = document.getElementById('linkType');
    var idSel = document.getElementById('linkId');
    if (!typeSel || !idSel) return;
    typeSel.addEventListener('change', function () {
        var type = this.value;
        idSel.innerHTML = '<option value="">Loading...</option>';
        idSel.disabled = true;
        if (!type) { idSel.innerHTML = '<option value="">Select a type first</option>'; return; }
        fetch('ajax_legal_link_options.php?type=' + encodeURIComponent(type))
            .then(function (r) { return r.json(); })
            .then(function (d) {
                idSel.innerHTML = '<option value="">-- Select --</option>';
                if (d && d.success && Array.isArray(d.items)) {
                    d.items.forEach(function (it) {
                        var opt = document.createElement('option');
                        opt.value = it.id; opt.textContent = it.label;
                        idSel.appendChild(opt);
                    });
                }
                idSel.disabled = false;
            })
            .catch(function () { idSel.innerHTML = '<option value="">Error loading</option>'; idSel.disabled = false; });
    });
})();
</script>
HTML;
require_once __DIR__ . '/includes/legal_layout_footer.php';
?>
