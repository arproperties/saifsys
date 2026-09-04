<?php
/**
 * Tenant Portal — Move-out notice (tenant-initiated)
 */

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/includes/tenant_auth.php';

require_tenant_login($conn);
require_once __DIR__ . '/includes/tenant_lease_loader.php';

if (!$lease) {
    header('Location: login.php');
    exit;
}

$lease_id = (int)$lease['lease_id'];
$company_id = (int)$lease['company_id'];

$message = '';
$messageType = '';
$existingNotice = null;

// Check existing (non-cancelled) move-out notice for this lease
try {
    $stmt = $conn->prepare("
        SELECT id, notice_date, intended_move_out_date, notice_type, status, notice_reason
        FROM re_move_out_notices
        WHERE lease_id = ? AND status != 'cancelled'
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$lease_id]);
    $existingNotice = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $existingNotice = null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$existingNotice) {
    csrf_verify();
    $intended = trim($_POST['intended_move_out_date'] ?? '');
    $reason = trim($_POST['notice_reason'] ?? '');

    if ($intended === '') {
        $message = 'Please choose your intended move-out date.';
        $messageType = 'danger';
    } else {
        try {
            $intendedDate = new DateTimeImmutable($intended);
            $noticeDate = new DateTimeImmutable('today');
            if ($intendedDate < $noticeDate) {
                throw new Exception('Intended move-out date cannot be in the past.');
            }

            $conn->beginTransaction();

            // Create move-out notice
            $stmt = $conn->prepare("
                INSERT INTO re_move_out_notices
                    (company_id, lease_id, notice_date, intended_move_out_date, notice_type, notice_reason, notice_delivered_by, status, created_by)
                VALUES
                    (?, ?, ?, ?, 'tenant', ?, 'tenant_portal', 'pending', NULL)
            ");
            $stmt->execute([
                $company_id,
                $lease_id,
                $noticeDate->format('Y-m-d'),
                $intendedDate->format('Y-m-d'),
                $reason ?: null,
            ]);
            $noticeId = (int)$conn->lastInsertId();

            // Create move-out record
            $stmt = $conn->prepare("
                INSERT INTO re_move_outs
                    (company_id, lease_id, move_out_notice_id, actual_move_out_date, status, created_by)
                VALUES
                    (?, ?, ?, ?, 'pending', NULL)
            ");
            $stmt->execute([
                $company_id,
                $lease_id,
                $noticeId,
                $intendedDate->format('Y-m-d'),
            ]);
            $moveOutId = (int)$conn->lastInsertId();

            // Email management (if helper available)
            try {
                if (file_exists(__DIR__ . '/../modules/realestate/includes/re_email_helper.php')) {
                    require_once __DIR__ . '/../modules/realestate/includes/re_email_helper.php';
                    $tenantName = trim(($lease['first_name'] ?? '') . ' ' . ($lease['last_name'] ?? ''));
                    $leaseNumber = $lease['lease_number'] ?? '';
                    $subject = '[Tenant Portal] Move-out notice for lease ' . $leaseNumber;
                    $htmlBody = "
                    <html><body style='font-family: Arial, sans-serif; line-height:1.6;'>
                        <p><strong>Tenant Portal — Move-out notice submitted</strong></p>
                        <p>The tenant <strong>" . htmlspecialchars($tenantName ?: 'Unknown tenant', ENT_QUOTES, 'UTF-8') . "</strong>"
                        . " has submitted a move-out notice for lease <strong>" . htmlspecialchars($leaseNumber, ENT_QUOTES, 'UTF-8') . "</strong>.</p>
                        <p><strong>Intended move-out date:</strong> " . htmlspecialchars($intendedDate->format('M j, Y'), ENT_QUOTES, 'UTF-8') . "</p>";
                    if ($reason !== '') {
                        $htmlBody .= "<p><strong>Reason:</strong> " . nl2br(htmlspecialchars($reason, ENT_QUOTES, 'UTF-8')) . "</p>";
                    }
                    $htmlBody .= "
                        <p>You can manage this move-out in the Real Estate module.</p>
                    </body></html>";

                    send_re_email_notification(
                        $conn,
                        $company_id,
                        'move_out_notice',
                        $subject,
                        $htmlBody,
                        [],
                        $moveOutId,
                        'move_out'
                    );
                }
            } catch (Throwable $e) {
                // Email errors should not block the notice
            }

            $conn->commit();
            $message = 'Your move-out notice has been submitted. Management will contact you to arrange the move-out process.';
            $messageType = 'success';

            // Reload existing notice for display
            $existingNotice = [
                'id' => $noticeId,
                'notice_date' => $noticeDate->format('Y-m-d'),
                'intended_move_out_date' => $intendedDate->format('Y-m-d'),
                'notice_type' => 'tenant',
                'status' => 'pending',
                'notice_reason' => $reason ?: null,
            ];
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            $message = (defined('APP_ENV') && APP_ENV === 'dev')
                ? 'Could not submit move-out notice: ' . htmlspecialchars($e->getMessage())
                : 'We could not submit your move-out notice. Please try again later or contact management.';
            $messageType = 'danger';
        }
    }
}

$pageTitle = 'Move-out notice';
require_once __DIR__ . '/includes/tenant_layout_header.php';
?>

<h4 class="page-title">Move-out notice</h4>

<?php if ($message): ?>
    <div class="alert alert-<?= htmlspecialchars($messageType) ?>"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<div class="portal-card card mb-4">
    <div class="card-body">
        <p class="mb-2"><strong>Lease:</strong> <?= htmlspecialchars($lease['lease_number']) ?></p>
        <p class="mb-2"><strong>Unit:</strong> <?= htmlspecialchars($lease['building_name'] . ' — ' . $lease['unit_number']) ?></p>
        <p class="mb-0 text-muted small">If you intend to move out, you can submit a notice here. Management will follow up to schedule inspection and finalize details.</p>
    </div>
<?php if ($existingNotice): ?>
    <div class="card-body border-top">
        <h6 class="fw-semibold mb-2">Existing move-out notice</h6>
        <p class="mb-1"><strong>Notice date:</strong> <?= htmlspecialchars(date('M j, Y', strtotime($existingNotice['notice_date']))) ?></p>
        <p class="mb-1"><strong>Intended move-out:</strong> <?= htmlspecialchars(date('M j, Y', strtotime($existingNotice['intended_move_out_date']))) ?></p>
        <p class="mb-1"><strong>Status:</strong> <span class="badge bg-secondary"><?= htmlspecialchars($existingNotice['status']) ?></span></p>
        <?php if (!empty($existingNotice['notice_reason'])): ?>
            <p class="mb-0"><strong>Reason:</strong> <?= nl2br(htmlspecialchars($existingNotice['notice_reason'], ENT_QUOTES, 'UTF-8')) ?></p>
        <?php endif; ?>
        <p class="mt-2 mb-0 text-muted small">If you need to change your move-out plan, please contact management.</p>
    </div>
<?php else: ?>
    <div class="card-body border-top">
        <h6 class="fw-semibold mb-3">Submit move-out notice</h6>
        <form method="post">
            <?php csrf_field(); ?>
            <div class="mb-3">
                <label for="intended_move_out_date" class="form-label">Intended move-out date</label>
                <input type="date" class="form-control" id="intended_move_out_date" name="intended_move_out_date" required>
            </div>
            <div class="mb-3">
                <label for="notice_reason" class="form-label">Reason (optional)</label>
                <textarea class="form-control" id="notice_reason" name="notice_reason" rows="3" placeholder="Why are you moving out?"></textarea>
            </div>
            <button type="submit" class="btn btn-primary">Submit move-out notice</button>
        </form>
    </div>
<?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/tenant_layout_footer.php'; ?>

