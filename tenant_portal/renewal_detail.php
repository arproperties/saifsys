<?php
/**
 * Tenant Portal — Renewal notice detail: view, acknowledge, respond, uploads, e-sign.
 */

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/includes/tenant_auth.php';

require_tenant_login($conn);
require_once __DIR__ . '/includes/tenant_lease_loader.php';
require_once __DIR__ . '/includes/renewal_portal_helper.php';
require_once __DIR__ . '/../includes/renewal_workflow_transitions.php';
require_once __DIR__ . '/../includes/renewal_negotiation_thread.php';

if (!$lease) {
    header('Location: login.php');
    exit;
}

$leaseId = (int)$lease['lease_id'];
$companyId = (int)$lease['company_id'];
$workflowId = !empty($_GET['id']) ? (int)$_GET['id'] : 0;

$success = '';
$error = '';
$basePath = dirname(__DIR__);

$wf = $workflowId ? tenant_renewal_fetch_workflow($conn, $workflowId, $leaseId, $companyId) : null;
if (!$wf) {
    header('Location: renewals.php');
    exit;
}

// Hide if notice never sent (edge case)
if (($wf['status'] ?? '') === 'initiated' && empty($wf['notice_sent_at']) && empty($wf['renewal_notice_pdf_path'])) {
    header('Location: renewals.php');
    exit;
}

// First open → viewed_by_tenant
try {
    $wf = tenant_renewal_on_first_open($conn, $wf, $leaseId, $companyId);
} catch (Throwable $e) {
    // migration missing — continue with stale $wf
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    $actor = tenant_renewal_actor_ids();
    try {
        if ($action === 'acknowledge') {
            $ackChk = renewal_wf_can_tenant_acknowledge(
                (string)($wf['status'] ?? ''),
                $wf['tenant_acknowledged_at'] ?? null
            );
            if (($ackChk['reason'] ?? '') === 'already_acknowledged') {
                $success = 'Already acknowledged.';
            } elseif (!$ackChk['ok']) {
                throw new Exception($ackChk['reason'] ?? 'Acknowledgement is not available.');
            } else {
                $ackStmt = $conn->prepare("
                    UPDATE re_lease_renewal_workflows
                    SET tenant_acknowledged_at = NOW(),
                        tenant_acknowledged_ip = ?,
                        tenant_acknowledged_by_tpu_id = ?,
                        status = 'acknowledged'
                    WHERE id = ? AND (lease_id = ? OR new_lease_id = ?)
                      AND tenant_acknowledged_at IS NULL
                      AND status IN ('notice_sent','viewed_by_tenant','pending_response','negotiation')
                ");
                $ackStmt->execute([
                    tenant_renewal_client_ip(),
                    $actor['tpu_id'] ?: null,
                    $workflowId,
                    $leaseId,
                    $leaseId,
                ]);
                if ($ackStmt->rowCount() === 0) {
                    $success = 'Already acknowledged.';
                } else {
                    tenant_renewal_log_event($conn, $workflowId, $companyId, 'acknowledged', 'Tenant acknowledged receipt of renewal notice.');
                    $success = 'Thank you — your receipt has been recorded.';
                }
            }
        } elseif ($action === 'decision') {
            $existingDec = trim((string)($wf['tenant_portal_decision'] ?? ''));
            $decChk = renewal_wf_can_tenant_decide((string)($wf['status'] ?? ''), $existingDec !== '' ? $existingDec : null);
            if (($decChk['reason'] ?? '') === 'already_decided') {
                $success = 'Your response was already submitted.';
            } elseif (!$decChk['ok']) {
                throw new Exception($decChk['reason'] ?? 'You cannot submit this response now.');
            }
            $dec = $_POST['decision'] ?? '';
            if (!in_array($dec, ['accept', 'negotiate', 'reject'], true)) {
                throw new Exception('Please choose a valid option.');
            }
            $msg = trim((string)($_POST['message'] ?? ''));
            if ($dec === 'negotiate' && $msg === '') {
                throw new Exception('Please add a short message for negotiation.');
            }
            if ($dec === 'accept') {
                $newStatus = 'accepted';
            } elseif ($dec === 'negotiate') {
                $newStatus = 'negotiation';
            } elseif ($dec === 'reject') {
                $newStatus = 'rejected';
            } else {
                $newStatus = 'pending_response';
            }
            $tenantResp = $msg !== '' ? $msg : ($dec === 'accept' ? 'Tenant accepted the renewal proposal via portal.' : '');
            $negNotes = $dec === 'negotiate' ? $msg : null;

            $decStmt = $conn->prepare("
                UPDATE re_lease_renewal_workflows
                SET tenant_portal_decision = ?,
                    tenant_portal_decision_at = NOW(),
                    tenant_portal_decision_ip = ?,
                    tenant_portal_decision_by_tpu_id = ?,
                    tenant_response = ?,
                    tenant_response_date = CURDATE(),
                    negotiation_notes = COALESCE(?, negotiation_notes),
                    status = ?
                WHERE id = ? AND (lease_id = ? OR new_lease_id = ?)
                  AND (tenant_portal_decision IS NULL OR tenant_portal_decision = '')
                  AND status IN ('notice_sent','viewed_by_tenant','acknowledged','pending_response','negotiation')
            ");
            $decStmt->execute([
                $dec,
                tenant_renewal_client_ip(),
                $actor['tpu_id'] ?: null,
                $tenantResp,
                $negNotes,
                $newStatus,
                $workflowId,
                $leaseId,
                $leaseId,
            ]);
            if ($decStmt->rowCount() === 0) {
                throw new Exception('Your response could not be saved (it may have already been submitted). Please refresh the page.');
            }
            tenant_renewal_log_event(
                $conn,
                $workflowId,
                $companyId,
                'decision_' . $dec,
                $msg !== '' ? $msg : 'Decision: ' . $dec
            );
            if ($dec === 'negotiate' && $msg !== '') {
                try {
                    renewal_negotiation_insert_tenant_message(
                        $conn,
                        $workflowId,
                        $companyId,
                        $msg,
                        $actor['tpu_id'],
                        $actor['legacy_user_id'],
                        tenant_renewal_client_ip() ?: null
                    );
                } catch (Throwable $e) {
                    error_log('renewal negotiation seed message: ' . $e->getMessage());
                }
            }
            $success = 'Your response has been sent to management.';
        } elseif ($action === 'negotiation_message') {
            $body = trim((string)($_POST['negotiation_body'] ?? ''));
            if (strlen($body) < 2) {
                throw new Exception('Please enter a message (at least 2 characters).');
            }
            if (strlen($body) > 8000) {
                throw new Exception('Message is too long.');
            }
            $stN = (string)($wf['status'] ?? '');
            $decN = (string)($wf['tenant_portal_decision'] ?? '');
            if (!renewal_wf_can_tenant_post_negotiation_message($stN, $decN)) {
                throw new Exception('You cannot send a negotiation message at this stage.');
            }
            renewal_negotiation_insert_tenant_message(
                $conn,
                $workflowId,
                $companyId,
                $body,
                $actor['tpu_id'],
                $actor['legacy_user_id'],
                tenant_renewal_client_ip() ?: null
            );
            tenant_renewal_log_event($conn, $workflowId, $companyId, 'negotiation_message_tenant', $body);
            $success = 'Your message was sent to management.';
        } elseif ($action === 'negotiation_finalize') {
            $fin = (string)($_POST['finalize'] ?? '');
            if (!in_array($fin, ['accept', 'reject'], true)) {
                throw new Exception('Invalid choice.');
            }
            $stF = (string)($wf['status'] ?? '');
            $decF = (string)($wf['tenant_portal_decision'] ?? '');
            if (!renewal_wf_can_tenant_finalize_negotiation($stF, $decF)) {
                throw new Exception('You cannot finalize from negotiation at this stage.');
            }
            if ($fin === 'accept') {
                $newDec = 'accept';
                $newSt = 'accepted';
                $note = 'Tenant accepted the renewal after negotiation (portal).';
            } else {
                $newDec = 'reject';
                $newSt = 'rejected';
                $note = 'Tenant rejected the renewal after negotiation (portal).';
            }
            $finStmt = $conn->prepare("
                UPDATE re_lease_renewal_workflows
                SET tenant_portal_decision = ?,
                    tenant_portal_decision_at = NOW(),
                    tenant_portal_decision_ip = ?,
                    tenant_portal_decision_by_tpu_id = ?,
                    status = ?
                WHERE id = ? AND (lease_id = ? OR new_lease_id = ?)
                  AND tenant_portal_decision = 'negotiate'
                  AND status = 'negotiation'
            ");
            $finStmt->execute([
                $newDec,
                tenant_renewal_client_ip(),
                $actor['tpu_id'] ?: null,
                $newSt,
                $workflowId,
                $leaseId,
                $leaseId,
            ]);
            if ($finStmt->rowCount() === 0) {
                throw new Exception('Could not update your decision. Please refresh the page.');
            }
            tenant_renewal_log_event($conn, $workflowId, $companyId, 'negotiation_finalize_' . $fin, $note);
            $success = $fin === 'accept'
                ? 'You have accepted the renewal. Management will proceed with the next steps.'
                : 'You have declined this renewal.';
        } elseif ($action === 'upload_doc') {
            if (!renewal_wf_can_tenant_upload((string)($wf['status'] ?? ''))) {
                throw new Exception('Uploads are closed for this renewal.');
            }
            $docType = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($_POST['document_type'] ?? 'other')));
            $allowedTypes = ['passport', 'visa', 'trade_license', 'poa', 'other'];
            if (!in_array($docType, $allowedTypes, true)) {
                $docType = 'other';
            }
            if (empty($_FILES['file']['tmp_name']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new Exception('Please choose a file to upload.');
            }
            if (($_FILES['file']['size'] ?? 0) > TENANT_RENEWAL_UPLOAD_MAX_BYTES) {
                throw new Exception('File is too large (max 8 MB).');
            }
            $orig = (string)($_FILES['file']['name'] ?? 'document');
            $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
            $mime = tenant_renewal_allowed_mime($ext);
            if ($mime === null) {
                throw new Exception('Allowed types: PDF, PNG, JPG, WEBP.');
            }
            $dir = $basePath . '/uploads/renewal_tenant_docs/' . $companyId . '/' . $workflowId;
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $safe = 'doc_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $abs = $dir . '/' . $safe;
            if (!move_uploaded_file($_FILES['file']['tmp_name'], $abs)) {
                throw new Exception('Could not save file.');
            }
            $rel = 'uploads/renewal_tenant_docs/' . $companyId . '/' . $workflowId . '/' . $safe;
            $conn->prepare("
                INSERT INTO re_renewal_tenant_uploads
                (workflow_id, company_id, tenant_portal_user_id, legacy_user_id, document_type, original_filename, stored_path, mime_type, size_bytes, ip_address)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $workflowId,
                $companyId,
                $actor['tpu_id'] ?: null,
                $actor['legacy_user_id'] ?: null,
                $docType,
                substr($orig, 0, 240),
                $rel,
                $mime,
                (int)($_FILES['file']['size'] ?? 0),
                tenant_renewal_client_ip() ?: null,
            ]);
            tenant_renewal_log_event($conn, $workflowId, $companyId, 'document_uploaded', $docType . ': ' . $orig);
            $success = 'Document uploaded.';
        } elseif ($action === 'electronic_sign') {
            $chk = $conn->prepare("SELECT COUNT(*) FROM re_renewal_electronic_signatures WHERE workflow_id = ?");
            $chk->execute([$workflowId]);
            $hasSig = (int)$chk->fetchColumn() > 0;
            if (!renewal_wf_can_tenant_electronic_sign((string)($wf['status'] ?? ''), $hasSig)) {
                throw new Exception($hasSig ? 'You have already signed this renewal.' : 'Contract is not ready for signature yet.');
            }
            $nlid = (int)($wf['new_lease_id'] ?? 0);
            if (!$nlid) {
                throw new Exception('No draft lease linked.');
            }
            $typed = trim((string)($_POST['typed_full_name'] ?? ''));
            $accept = !empty($_POST['accept_terms']);
            if (strlen($typed) < 3) {
                throw new Exception('Please type your full name as shown on the lease.');
            }
            if (!$accept) {
                throw new Exception('Please confirm acceptance of the contract terms.');
            }
            $leaseFn = trim((string)($lease['first_name'] ?? ''));
            $leaseLn = trim((string)($lease['last_name'] ?? ''));
            if (!tenant_renewal_typed_name_matches_lease_tenant($typed, $leaseFn, $leaseLn)) {
                $mustMatch = tenant_renewal_expected_signer_display_name($leaseFn, $leaseLn);
                throw new Exception(
                    'The name you typed does not match our records. Enter your full legal name exactly as registered'
                    . ($mustMatch !== '' ? ': ' . $mustMatch : '') . '.'
                );
            }
            $conn->beginTransaction();
            try {
                $conn->prepare("
                    INSERT INTO re_renewal_electronic_signatures
                    (workflow_id, company_id, new_lease_id, typed_full_name, terms_accepted, tenant_portal_user_id, legacy_user_id, ip_address, user_agent)
                    VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?)
                ")->execute([
                    $workflowId,
                    $companyId,
                    $nlid,
                    $typed,
                    $actor['tpu_id'] ?: null,
                    $actor['legacy_user_id'] ?: null,
                    tenant_renewal_client_ip() ?: null,
                    tenant_renewal_user_agent(),
                ]);
            } catch (Throwable $insEx) {
                $conn->rollBack();
                if (strpos($insEx->getMessage(), 'Duplicate') !== false || strpos($insEx->getMessage(), '1062') !== false) {
                    throw new Exception('You have already signed this renewal.');
                }
                throw $insEx;
            }
            $updSign = $conn->prepare("
                UPDATE re_lease_renewal_workflows
                SET status = 'signed'
                WHERE id = ? AND (lease_id = ? OR new_lease_id = ?) AND status = 'contract_ready'
            ");
            $updSign->execute([$workflowId, $leaseId, $leaseId]);
            if ($updSign->rowCount() === 0) {
                $conn->rollBack();
                throw new Exception('Signature could not be finalized (workflow status changed). Please refresh the page.');
            }
            $conn->commit();
            tenant_renewal_log_event($conn, $workflowId, $companyId, 'electronic_sign', 'Typed name: ' . $typed);
            $success = 'Your electronic signature has been recorded. Management will finalize the renewal.';
        } else {
            throw new Exception('Unknown action.');
        }
        $wf = tenant_renewal_fetch_workflow($conn, $workflowId, $leaseId, $companyId) ?: $wf;
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        $error = $e->getMessage();
    }
}

$uploads = [];
$hasSignature = false;
$draftLease = null;
try {
    $u = $conn->prepare("SELECT * FROM re_renewal_tenant_uploads WHERE workflow_id = ? ORDER BY uploaded_at DESC");
    $u->execute([$workflowId]);
    $uploads = $u->fetchAll(PDO::FETCH_ASSOC);
    $s = $conn->prepare("SELECT id FROM re_renewal_electronic_signatures WHERE workflow_id = ? LIMIT 1");
    $s->execute([$workflowId]);
    $hasSignature = (bool)$s->fetchColumn();
    if (!empty($wf['new_lease_id'])) {
        $dl = $conn->prepare("SELECT id, lease_number, status, generated_contract_path, annual_rent, start_date, end_date FROM re_leases WHERE id = ? AND company_id = ? LIMIT 1");
        $dl->execute([(int)$wf['new_lease_id'], $companyId]);
        $draftLease = $dl->fetch(PDO::FETCH_ASSOC) ?: null;
    }
} catch (Throwable $e) {
    // tables missing
}

$negotiationThreadDisplay = [];
try {
    $negotiationThreadDisplay = renewal_negotiation_thread_for_display($conn, $workflowId, $wf);
} catch (Throwable $e) {
    $negotiationThreadDisplay = [];
}

$pageTitle = 'Renewal notice';
require_once __DIR__ . '/includes/tenant_layout_header.php';
?>

<h4 class="page-title">Renewal notice</h4>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show"><?= h($success) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show"><?= h($error) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<div class="portal-card card mb-4">
    <div class="card-body">
        <p class="text-muted small mb-2">Status: <strong><?= h(tenant_renewal_status_label((string)($wf['status'] ?? ''))) ?></strong></p>
        <h5 class="card-title">Your current lease</h5>
        <p class="mb-1"><?= h($lease['lease_number']) ?> — <?= h($lease['building_name'] . ', unit ' . $lease['unit_number']) ?></p>
        <p class="small text-muted mb-0">Until <?= h(date('M j, Y', strtotime($lease['end_date']))) ?></p>
    </div>
</div>

<div class="portal-card card mb-4">
    <div class="card-body">
        <h5 class="card-title">Proposed renewal</h5>
        <table class="table table-sm table-borderless mb-0">
            <tr><th class="w-50">Proposed annual rent</th><td><?= isset($wf['proposed_rent']) ? number_format((float)$wf['proposed_rent'], 2) . ' AED' : '—' ?></td></tr>
            <tr><th>Period</th><td>
                <?php if (!empty($wf['proposed_start_date']) && !empty($wf['proposed_end_date'])): ?>
                    <?= h(date('M j, Y', strtotime($wf['proposed_start_date']))) ?> — <?= h(date('M j, Y', strtotime($wf['proposed_end_date']))) ?>
                <?php else: ?>—<?php endif; ?>
            </td></tr>
            <tr><th>Cheques</th><td><?= (int)($wf['number_of_cheques'] ?? 0) ?: '—' ?></td></tr>
            <tr><th>Chiller</th><td><?= number_format((float)($wf['chiller_charges'] ?? 0), 2) ?> AED</td></tr>
            <tr><th>Admin / renewal fees</th><td><?= number_format((float)($wf['admin_fees'] ?? 0), 2) ?> AED</td></tr>
            <tr><th>VAT (extras)</th><td><?= number_format((float)($wf['vat_extra_charges'] ?? 0), 2) ?> AED</td></tr>
        </table>
    </div>
</div>

<?php if (!empty($wf['renewal_notice_pdf_path'])): ?>
    <div class="portal-card card mb-4">
        <div class="card-body">
            <h5 class="card-title">Renewal notice PDF</h5>
            <a class="btn btn-outline-primary" target="_blank" rel="noopener" href="renewal_download_notice.php?id=<?= (int)$workflowId ?>">
                <i class="bi bi-file-earmark-pdf"></i> View / download PDF
            </a>
        </div>
    </div>
<?php endif; ?>

<?php if (!in_array($wf['status'], ['converted', 'rejected'], true)): ?>
    <div class="portal-card card mb-4">
        <div class="card-body">
            <h5 class="card-title">1. Acknowledge receipt</h5>
            <?php if (!empty($wf['tenant_acknowledged_at'])): ?>
                <p class="text-success mb-0"><i class="bi bi-check-circle"></i> Acknowledged on <?= h(date('M j, Y g:i A', strtotime($wf['tenant_acknowledged_at']))) ?></p>
            <?php else: ?>
                <form method="post" class="d-inline">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="acknowledge">
                    <button type="submit" class="btn btn-primary">Acknowledge receipt</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="portal-card card mb-4">
        <div class="card-body">
            <h5 class="card-title">2. Your response</h5>
            <?php
            $portalDec = (string)($wf['tenant_portal_decision'] ?? '');
            $wfStatus = (string)($wf['status'] ?? '');
            ?>
            <?php if ($portalDec === ''): ?>
                <form method="post">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="decision">
                    <div class="mb-3">
                        <div class="form-check"><input class="form-check-input" type="radio" name="decision" value="accept" id="d_acc" required><label class="form-check-label" for="d_acc">Accept renewal</label></div>
                        <div class="form-check"><input class="form-check-input" type="radio" name="decision" value="negotiate" id="d_neg"><label class="form-check-label" for="d_neg">Request discussion / negotiation</label></div>
                        <div class="form-check"><input class="form-check-input" type="radio" name="decision" value="reject" id="d_rej"><label class="form-check-label" for="d_rej">Reject renewal</label></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Comments (required if negotiating)</label>
                        <textarea class="form-control" name="message" rows="3" placeholder="Optional message to management"></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary">Submit response</button>
                </form>
            <?php else: ?>
                <p class="mb-2">Submitted: <strong><?= h(ucfirst($portalDec)) ?></strong>
                    <?php if (!empty($wf['tenant_portal_decision_at'])): ?>
                        on <?= h(date('M j, Y', strtotime($wf['tenant_portal_decision_at']))) ?>
                    <?php endif; ?>
                </p>
                <?php if ($portalDec !== 'negotiate' && !empty($wf['tenant_response'])): ?>
                    <p class="small text-muted mb-0"><?= nl2br(h((string)$wf['tenant_response'])) ?></p>
                <?php endif; ?>

                <?php
                $showDiscussionBlock = count($negotiationThreadDisplay) > 0 || $portalDec === 'negotiate'
                    || ($portalDec === 'accept' && count($negotiationThreadDisplay) > 0);
                ?>
                <?php if ($showDiscussionBlock): ?>
                    <hr class="my-3">
                    <h6 class="mb-3">Discussion with management</h6>
                    <?php if (empty($negotiationThreadDisplay)): ?>
                        <p class="small text-muted">Your first message was recorded. When management replies, it will appear here.</p>
                    <?php else: ?>
                        <div class="d-flex flex-column gap-3 mb-3">
                            <?php foreach ($negotiationThreadDisplay as $it): ?>
                                <?php if (($it['kind'] ?? '') === 'legacy_tenant'): ?>
                                    <div class="border rounded p-3 bg-light">
                                        <div class="small text-muted mb-1">You (initial request)</div>
                                        <?= nl2br(h((string)$it['body'])) ?>
                                        <?php if (!empty($it['at'])): ?>
                                            <div class="small text-muted mt-2"><?= h(date('M j, Y g:i A', strtotime((string)$it['at']))) ?></div>
                                        <?php endif; ?>
                                    </div>
                                <?php elseif (($it['kind'] ?? '') === 'legacy_admin'): ?>
                                    <div class="border rounded p-3 border-primary bg-primary bg-opacity-10">
                                        <div class="small text-muted mb-1">Property management</div>
                                        <?= nl2br(h((string)$it['body'])) ?>
                                    </div>
                                <?php elseif (($it['kind'] ?? '') === 'msg'): ?>
                                    <?php $r = $it['row']; ?>
                                    <?php if (($r['author_role'] ?? '') === 'tenant'): ?>
                                        <div class="border rounded p-3 bg-light">
                                            <div class="small text-muted mb-1">You<?= !empty($r['created_at']) ? ' · ' . h(date('M j, Y g:i A', strtotime((string)$r['created_at']))) : '' ?></div>
                                            <?= nl2br(h((string)($r['body'] ?? ''))) ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="border rounded p-3 border-primary bg-primary bg-opacity-10">
                                            <div class="small text-muted mb-1">Property management<?= !empty($r['created_at']) ? ' · ' . h(date('M j, Y g:i A', strtotime((string)$r['created_at']))) : '' ?></div>
                                            <?= nl2br(h((string)($r['body'] ?? ''))) ?>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (renewal_wf_can_tenant_post_negotiation_message($wfStatus, $portalDec)): ?>
                        <form method="post" class="mb-3">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="action" value="negotiation_message">
                            <label class="form-label">Add a message</label>
                            <textarea name="negotiation_body" class="form-control mb-2" rows="3" required placeholder="Reply to management"></textarea>
                            <button type="submit" class="btn btn-primary btn-sm">Send message</button>
                        </form>
                    <?php endif; ?>

                    <?php if (renewal_wf_can_tenant_finalize_negotiation($wfStatus, $portalDec)): ?>
                        <p class="small text-muted mb-2">When you agree with the updated terms, accept below. You can also reject if you do not wish to continue.</p>
                        <div class="d-flex flex-wrap gap-2">
                            <form method="post" class="d-inline" onsubmit="return confirm('Accept the renewal on the proposed terms shown above?');">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="action" value="negotiation_finalize">
                                <input type="hidden" name="finalize" value="accept">
                                <button type="submit" class="btn btn-success btn-sm">Accept renewal</button>
                            </form>
                            <form method="post" class="d-inline" onsubmit="return confirm('Reject this renewal? This will close the renewal in the portal.');">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="action" value="negotiation_finalize">
                                <input type="hidden" name="finalize" value="reject">
                                <button type="submit" class="btn btn-outline-danger btn-sm">Reject renewal</button>
                            </form>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="portal-card card mb-4">
        <div class="card-body">
            <h5 class="card-title">3. Upload documents</h5>
            <p class="small text-muted">Passport, visa, trade license, POA, etc. (PDF or images, max 8 MB)</p>
            <form method="post" enctype="multipart/form-data">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="upload_doc">
                <div class="row g-2 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label">Document type</label>
                        <select name="document_type" class="form-select">
                            <option value="passport">Passport copy</option>
                            <option value="visa">Visa page</option>
                            <option value="trade_license">Trade license</option>
                            <option value="poa">Power of attorney</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label">File</label>
                        <input type="file" name="file" class="form-control" required accept=".pdf,.png,.jpg,.jpeg,.webp">
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-outline-primary w-100">Upload</button>
                    </div>
                </div>
            </form>
            <?php if (!empty($uploads)): ?>
                <ul class="list-group list-group-flush mt-3 small">
                    <?php foreach ($uploads as $up): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                            <span><?= h($up['document_type']) ?> — <?= h($up['original_filename']) ?></span>
                            <a href="renewal_download_upload.php?w=<?= (int)$workflowId ?>&u=<?= (int)$up['id'] ?>" class="btn btn-sm btn-link">Download</a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php if ($wf['status'] === 'contract_ready' && $draftLease && ($draftLease['status'] ?? '') === 'draft'): ?>
    <div class="portal-card card mb-4 border-primary">
        <div class="card-body">
            <h5 class="card-title">4. Review &amp; sign renewal contract</h5>
            <?php if (!empty($draftLease['generated_contract_path'])): ?>
                <p><a class="btn btn-outline-primary" target="_blank" href="renewal_download_contract.php?id=<?= (int)$workflowId ?>"><i class="bi bi-file-earmark-pdf"></i> Open contract PDF</a></p>
            <?php else: ?>
                <p class="text-muted">Contract PDF is not generated yet. Please review the proposed terms above; management may upload the contract shortly.</p>
            <?php endif; ?>
            <?php if ($hasSignature): ?>
                <p class="text-success mb-0"><i class="bi bi-pen"></i> Signed electronically.</p>
            <?php else: ?>
                <form method="post" class="mt-3">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="electronic_sign">
                    <?php $expectedSignName = tenant_renewal_expected_signer_display_name((string)($lease['first_name'] ?? ''), (string)($lease['last_name'] ?? '')); ?>
                    <div class="mb-3">
                        <label class="form-label">Type your full name (as on the tenancy)</label>
                        <input type="text" name="typed_full_name" class="form-control" required autocomplete="name"
                               placeholder="<?= h($expectedSignName) ?>"
                               aria-describedby="signNameHint">
                        <p id="signNameHint" class="form-text text-muted mb-0">
                            Must match the registered tenant name exactly (same words; order can be first–last or last–first). Example: <strong><?= h($expectedSignName) ?></strong>
                        </p>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="accept_terms" value="1" id="accT" required>
                        <label class="form-check-label" for="accT">I confirm that I have reviewed the renewal contract and accept its terms.</label>
                    </div>
                    <button type="submit" class="btn btn-success">Sign electronically</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<p><a href="renewals.php" class="btn btn-outline-secondary">&larr; Back to renewals</a></p>

<?php require_once __DIR__ . '/includes/tenant_layout_footer.php'; ?>
