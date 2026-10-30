<?php
/**
 * Real Estate Module - Form Header & Footer
 * Letterhead images printed on every page of the Move-In and Move-Out
 * inspection forms. Owner/Admin can replace or reset them; others can view.
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/includes/form_letterhead_helper.php';

require_login();
require_module_access($conn, MODULE_REALESTATE);

$brand = getBrandSettings($conn);
$currentCompanyId = (int)(current_company_id($conn) ?: 1);
$currentUserId = !empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
$canEdit = has_role('Owner', $conn) || has_role('Admin', $conn);
$ready = rfl_ready($conn);

// Where to go back to (move-in / move-out page that sent the user here)
$back = (string)($_GET['back'] ?? $_POST['back'] ?? '');
if (!preg_match('/^move_(in|out)_view\.php\?id=\d+$/', $back)) {
    $back = '';
}
$self = 'form_letterhead.php' . ($back !== '' ? '?back=' . urlencode($back) : '');

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $part = (string)($_POST['part'] ?? '');
    $label = $part === 'footer' ? 'Footer' : 'Header';
    try {
        if (!$canEdit) {
            throw new RuntimeException('Only Owner or Admin can change the form header and footer.');
        }
        if (($_POST['action'] ?? '') === 'upload') {
            rfl_save_upload($conn, $currentCompanyId, $part, $_FILES['image'] ?? [], $currentUserId);
            $_SESSION['success'] = $label . ' updated. New and re-downloaded forms use it from now on.';
        } elseif (($_POST['action'] ?? '') === 'reset') {
            rfl_reset($conn, $currentCompanyId, $part, $currentUserId);
            $_SESSION['success'] = $label . ' set back to the default letterhead.';
        }
    } catch (Throwable $e) {
        $_SESSION['error'] = $e->getMessage();
    }
    header('Location: ' . $self);
    exit;
}

$letterhead = rfl_get($conn, $currentCompanyId);

$pageTitle = 'Form Header & Footer';
require_once __DIR__ . '/includes/re_move_ui.php';
$pageStyles = ($pageStyles ?? '') . re_move_ui_css() . '
    .lh-preview { border: 1px dashed var(--rm-border); border-radius: 12px; background: #fff; padding: .75rem; text-align: center; }
    .lh-preview img { max-width: 100%; height: auto; max-height: 140px; }
    .lh-empty { color: var(--rm-muted); padding: 1.5rem 0; }
    .lh-page { max-width: 420px; margin: 0 auto; aspect-ratio: 210 / 297; background: #fff; border: 1px solid var(--rm-border); border-radius: 8px; box-shadow: var(--shadow-sm); display: flex; flex-direction: column; padding: 4% 5%; }
    .lh-page img { width: 100%; height: auto; }
    .lh-page .body { flex: 1; display: flex; flex-direction: column; gap: 6px; padding: 8% 0; }
    .lh-page .line { height: 6px; border-radius: 3px; background: #e5e7eb; }
';
require_once __DIR__ . '/includes/re_layout_header.php';
?>
<div class="rm-page">
    <div class="rm-narrow">
        <?php if ($back !== ''): ?>
            <a href="<?= h($back) ?>" class="rm-back"><i class="bi bi-arrow-left"></i> Back</a>
        <?php else: ?>
            <a href="move_in.php" class="rm-back"><i class="bi bi-arrow-left"></i> Move-In Management</a>
        <?php endif; ?>
        <div class="rm-hero">
            <div>
                <h1><i class="bi bi-layout-text-window"></i>Form Header &amp; Footer</h1>
                <div class="rm-sub">Printed on every page of the Move-In and Move-Out inspection forms.</div>
            </div>
        </div>

        <?php foreach (['success' => 'success', 'error' => 'danger'] as $k => $cls): if (!empty($_SESSION[$k])): ?>
            <div class="alert alert-<?= $cls ?> alert-dismissible fade show"><?= h($_SESSION[$k]) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php unset($_SESSION[$k]); endif; endforeach; ?>

        <?php if (!$ready): ?>
            <div class="alert alert-warning">Run <code>migrations/re_form_letterheads.sql</code> to change the header and footer. Until then the forms use the contract letterhead.</div>
        <?php elseif (!$canEdit): ?>
            <div class="alert alert-info small"><i class="bi bi-eye"></i> View only. Owner or Admin can change the header and footer.</div>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-lg-7">
                <?php foreach (['header' => 'Header', 'footer' => 'Footer'] as $part => $label): $cur = $letterhead[$part]; ?>
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi <?= $part === 'header' ? 'bi-arrow-bar-up' : 'bi-arrow-bar-down' ?>"></i> <?= $label ?></h5>
                            <?= re_move_ui_pill($cur['custom'] ? 'Custom' : 'Default', $cur['custom'] ? 'primary' : 'secondary') ?>
                        </div>
                        <div class="card-body">
                            <div class="lh-preview mb-3">
                                <?php if ($cur['path']): ?>
                                    <img src="../../<?= h($cur['path']) ?>?v=<?= (int)@filemtime(rfl_project_root() . '/' . $cur['path']) ?>" alt="Current <?= strtolower($label) ?>">
                                <?php else: ?>
                                    <div class="lh-empty">No <?= strtolower($label) ?> image. Forms are printed without one.</div>
                                <?php endif; ?>
                            </div>
                            <?php if ($ready && $canEdit): ?>
                                <form method="post" enctype="multipart/form-data" class="rm-upload-row">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="action" value="upload">
                                    <input type="hidden" name="part" value="<?= $part ?>">
                                    <input type="hidden" name="back" value="<?= h($back) ?>">
                                    <div class="d-flex flex-wrap gap-2">
                                        <input type="file" name="image" class="form-control" style="flex:1 1 220px" accept=".png,.jpg,.jpeg" required>
                                        <button type="submit" class="btn btn-primary text-nowrap"><i class="bi bi-upload"></i> Replace <?= strtolower($label) ?></button>
                                    </div>
                                    <div class="form-text">PNG or JPG, up to 5MB. A wide banner works best, about <?= $part === 'header' ? '1600 × 350' : '1600 × 190' ?> pixels.</div>
                                </form>
                                <?php if ($cur['custom']): ?>
                                    <form method="post" class="mt-2" onsubmit="return confirm('Go back to the default <?= strtolower($label) ?>?');">
                                        <?php csrf_field(); ?>
                                        <input type="hidden" name="action" value="reset">
                                        <input type="hidden" name="part" value="<?= $part ?>">
                                        <input type="hidden" name="back" value="<?= h($back) ?>">
                                        <button type="submit" class="btn btn-sm btn-link text-muted px-0"><i class="bi bi-arrow-counterclockwise"></i> Use the default <?= strtolower($label) ?></button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="col-lg-5">
                <div class="card">
                    <div class="card-header"><h5 class="mb-0"><i class="bi bi-file-earmark"></i> Page preview</h5></div>
                    <div class="card-body">
                        <div class="lh-page" aria-hidden="true">
                            <?php if ($letterhead['header']['path']): ?><img src="../../<?= h($letterhead['header']['path']) ?>" alt=""><?php endif; ?>
                            <div class="body"><?php for ($i = 0; $i < 9; $i++): ?><div class="line" style="width:<?= [92, 80, 96, 60, 88, 94, 72, 85, 50][$i] ?>%"></div><?php endfor; ?></div>
                            <?php if ($letterhead['footer']['path']): ?><img src="../../<?= h($letterhead['footer']['path']) ?>" alt=""><?php endif; ?>
                        </div>
                        <div class="form-text text-center mt-2">The default is the letterhead used on the lease contracts.</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>
