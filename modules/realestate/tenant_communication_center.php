<?php
/**
 * Tenant Communication Center — building announcements hub.
 * Quick Push remains on customer_push_notifications.php (same center tabs).
 */

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/../../includes/re_tenant_announcements_helper.php';
require_once __DIR__ . '/../../includes/tenant_notifications.php';
require_once __DIR__ . '/../../includes/customer_push_notifications.php';

require_login();
if (!has_role('Owner', $conn) && !has_role('Admin', $conn) && !user_has_module_access($conn, current_user_id() ?: 0, MODULE_REALESTATE)) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$brand = getBrandSettings($conn);
$currentCompanyId = (int)(current_company_id($conn) ?: 0);
if ($currentCompanyId <= 0) {
    http_response_code(403);
    echo 'Company context is required.';
    exit;
}

$userId = (int)(current_user_id() ?: 0);
re_ann_ensure_schema($conn);
re_ann_seed_categories($conn, $currentCompanyId);
re_ann_promote_scheduled($conn, $currentCompanyId);
customer_push_ensure_schema($conn);

if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

$tab = (string)($_GET['tab'] ?? 'announcements');
if (!in_array($tab, ['announcements', 'categories'], true)) {
    $tab = 'announcements';
}

$flash = $_SESSION['re_comm_flash'] ?? null;
unset($_SESSION['re_comm_flash']);
$error = null;
$success = null;

function re_comm_flash_redirect(string $msg, string $type = 'success', string $tab = 'announcements'): void
{
    $_SESSION['re_comm_flash'] = [$type, $msg];
    header('Location: tenant_communication_center.php?tab=' . urlencode($tab));
    exit;
}

/**
 * Optional fan-out: in-app + push for published announcements.
 */
function re_comm_fanout_notification(PDO $conn, int $companyId, array $ann, array $targets, int $actorUserId): ?int
{
    if (empty($ann['send_in_app']) && empty($ann['send_push'])) {
        return null;
    }
    $recipients = re_ann_resolve_portal_recipients($conn, $companyId, $ann, $targets);
    if ($recipients === []) {
        return null;
    }

    $title = (string)$ann['title'];
    $message = function_exists('mb_substr')
        ? mb_substr(strip_tags((string)$ann['body']), 0, 500, 'UTF-8')
        : substr(strip_tags((string)$ann['body']), 0, 500);

    $batchId = null;
    if (!empty($ann['send_push'])) {
        $ins = $conn->prepare("
            INSERT INTO customer_notification_batches
                (company_id, user_type_scope, target_scope, target_label, action_type, title, message, created_by)
            VALUES (?, 'tenant', ?, ?, 'announcement', ?, ?, ?)
        ");
        $ins->execute([
            $companyId,
            (string)$ann['target_scope'],
            'announcement#' . (int)$ann['id'],
            $title,
            $message,
            $actorUserId ?: null,
        ]);
        $batchId = (int)$conn->lastInsertId();
    }

    $totals = ['tokens' => 0, 'sent' => 0, 'failed' => 0, 'invalid' => 0];
    foreach ($recipients as $r) {
        $portalUserId = (int)($r['tenant_portal_user_id'] ?? 0);
        $tenantId = (int)($r['tenant_id'] ?? 0);
        if ($portalUserId <= 0 || $tenantId <= 0) {
            continue;
        }
        $identity = [
            'company_id' => $companyId,
            'tenant_portal_user_id' => $portalUserId,
            'tenant_id' => $tenantId,
            'lease_id' => null,
        ];
        $notificationId = null;
        if (!empty($ann['send_in_app'])) {
            $notificationId = tenant_notification_create($conn, [
                'company_id' => $companyId,
                'tenant_id' => $tenantId,
                'tenant_portal_user_id' => $portalUserId,
                'type' => 'announcement',
                'title' => $title,
                'body' => $message,
                'entity_type' => 'announcement',
                'entity_id' => (int)$ann['id'],
                'skip_push' => true,
            ]);
        }
        if (!empty($ann['send_push']) && $batchId) {
            $res = customer_push_send_to_identity(
                $conn,
                'tenant',
                $identity,
                $title,
                $message,
                [
                    'action_type' => 'announcement',
                    'entity_type' => 'announcement',
                    'entity_id' => (string)(int)$ann['id'],
                ],
                $notificationId,
                null,
                $batchId,
                'announcement_' . (string)$ann['target_scope'],
                $actorUserId ?: null
            );
            foreach ($totals as $k => $_) {
                $totals[$k] += (int)($res[$k] ?? 0);
            }
        }
    }

    if ($batchId) {
        customer_push_update_batch_counts($conn, $batchId, count($recipients), $totals);
        $conn->prepare('UPDATE re_announcements SET push_batch_id = ? WHERE id = ? AND company_id = ?')
            ->execute([$batchId, (int)$ann['id'], $companyId]);
    }

    return $batchId;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'save_category') {
            $catId = (int)($_POST['category_id'] ?? 0);
            $code = strtolower(trim((string)($_POST['code'] ?? '')));
            $code = preg_replace('/[^a-z0-9_]/', '_', $code) ?: '';
            $name = trim((string)($_POST['name'] ?? ''));
            $sort = (int)($_POST['sort_order'] ?? 0);
            $active = isset($_POST['is_active']) ? 1 : 0;
            if ($code === '' || $name === '') {
                throw new RuntimeException('Category code and name are required.');
            }
            if ($catId > 0) {
                $st = $conn->prepare('
                    UPDATE re_announcement_categories
                    SET code = ?, name = ?, sort_order = ?, is_active = ?
                    WHERE id = ? AND company_id = ?
                ');
                $st->execute([$code, $name, $sort, $active, $catId, $currentCompanyId]);
            } else {
                $st = $conn->prepare('
                    INSERT INTO re_announcement_categories (company_id, code, name, sort_order, is_active)
                    VALUES (?, ?, ?, ?, ?)
                ');
                $st->execute([$currentCompanyId, $code, $name, $sort, $active]);
            }
            re_comm_flash_redirect('Category saved.', 'success', 'categories');
        }

        if ($action === 'archive_announcement') {
            $id = (int)($_POST['id'] ?? 0);
            $st = $conn->prepare("UPDATE re_announcements SET status = 'archived' WHERE id = ? AND company_id = ?");
            $st->execute([$id, $currentCompanyId]);
            re_comm_flash_redirect('Announcement archived.');
        }

        if ($action === 'delete_announcement') {
            $id = (int)($_POST['id'] ?? 0);
            $chk = $conn->prepare("SELECT status FROM re_announcements WHERE id = ? AND company_id = ?");
            $chk->execute([$id, $currentCompanyId]);
            $stt = (string)($chk->fetchColumn() ?: '');
            if (!in_array($stt, ['draft', 'archived'], true)) {
                throw new RuntimeException('Only draft or archived announcements can be deleted.');
            }
            $conn->prepare('DELETE FROM re_announcement_attachments WHERE announcement_id = ? AND company_id = ?')
                ->execute([$id, $currentCompanyId]);
            $conn->prepare('DELETE FROM re_announcement_targets WHERE announcement_id = ? AND company_id = ?')
                ->execute([$id, $currentCompanyId]);
            $conn->prepare('DELETE FROM re_announcements WHERE id = ? AND company_id = ?')
                ->execute([$id, $currentCompanyId]);
            re_comm_flash_redirect('Announcement deleted.');
        }

        if ($action === 'save_announcement') {
            $id = (int)($_POST['id'] ?? 0);
            $title = trim((string)($_POST['title'] ?? ''));
            $body = trim((string)($_POST['body'] ?? ''));
            $categoryId = (int)($_POST['category_id'] ?? 0) ?: null;
            $priority = (string)($_POST['priority'] ?? 'normal');
            if (!isset(re_ann_priorities()[$priority])) {
                $priority = 'normal';
            }
            $scope = (string)($_POST['target_scope'] ?? 'company');
            if (!in_array($scope, ['company', 'buildings', 'units', 'tenants'], true)) {
                $scope = 'company';
            }
            $publishAt = trim((string)($_POST['publish_at'] ?? ''));
            $expireAt = trim((string)($_POST['expire_at'] ?? ''));
            $publishAtSql = $publishAt !== '' ? date('Y-m-d H:i:s', strtotime($publishAt)) : null;
            $expireAtSql = $expireAt !== '' ? date('Y-m-d H:i:s', strtotime($expireAt)) : null;
            $sendInApp = isset($_POST['send_in_app']) ? 1 : 0;
            $sendPush = isset($_POST['send_push']) ? 1 : 0;
            $intent = (string)($_POST['save_intent'] ?? 'draft'); // draft | schedule | publish

            if ($title === '' || $body === '') {
                throw new RuntimeException('Title and description are required.');
            }

            $status = 'draft';
            $publishedAt = null;
            $publishedBy = null;
            if ($intent === 'publish') {
                $status = 'published';
                $publishedAt = date('Y-m-d H:i:s');
                $publishedBy = $userId ?: null;
                if ($publishAtSql === null) {
                    $publishAtSql = $publishedAt;
                }
            } elseif ($intent === 'schedule') {
                if ($publishAtSql === null) {
                    throw new RuntimeException('Publish date is required to schedule.');
                }
                if (strtotime($publishAtSql) > time()) {
                    $status = 'scheduled';
                } else {
                    $status = 'published';
                    $publishedAt = date('Y-m-d H:i:s');
                    $publishedBy = $userId ?: null;
                }
            }

            $targets = [];
            if ($scope === 'buildings') {
                foreach ((array)($_POST['building_ids'] ?? []) as $bid) {
                    $bid = (int)$bid;
                    if ($bid > 0) {
                        $targets[] = ['type' => 'building', 'id' => $bid];
                    }
                }
                if ($targets === []) {
                    throw new RuntimeException('Select at least one building.');
                }
            } elseif ($scope === 'units') {
                foreach ((array)($_POST['unit_ids'] ?? []) as $uid) {
                    $uid = (int)$uid;
                    if ($uid > 0) {
                        $targets[] = ['type' => 'unit', 'id' => $uid];
                    }
                }
                if ($targets === []) {
                    throw new RuntimeException('Select at least one unit.');
                }
            } elseif ($scope === 'tenants') {
                $tid = (int)($_POST['tenant_id'] ?? 0);
                $pid = (int)($_POST['tenant_portal_user_id'] ?? 0);
                if ($pid > 0) {
                    $targets[] = ['type' => 'tenant_portal_user', 'id' => $pid];
                } elseif ($tid > 0) {
                    $targets[] = ['type' => 'tenant', 'id' => $tid];
                } else {
                    throw new RuntimeException('Select a tenant.');
                }
            }

            if ($id > 0) {
                $st = $conn->prepare('
                    UPDATE re_announcements SET
                        category_id = ?, title = ?, body = ?, priority = ?, status = ?,
                        publish_at = ?, expire_at = ?, target_scope = ?,
                        send_in_app = ?, send_push = ?,
                        published_by = COALESCE(?, published_by),
                        published_at = COALESCE(?, published_at)
                    WHERE id = ? AND company_id = ?
                ');
                $st->execute([
                    $categoryId, $title, $body, $priority, $status,
                    $publishAtSql, $expireAtSql, $scope,
                    $sendInApp, $sendPush,
                    $publishedBy, $publishedAt,
                    $id, $currentCompanyId,
                ]);
            } else {
                $st = $conn->prepare('
                    INSERT INTO re_announcements
                        (company_id, category_id, title, body, priority, status, publish_at, expire_at,
                         target_scope, send_in_app, send_push, created_by, published_by, published_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ');
                $st->execute([
                    $currentCompanyId, $categoryId, $title, $body, $priority, $status,
                    $publishAtSql, $expireAtSql, $scope, $sendInApp, $sendPush,
                    $userId ?: null, $publishedBy, $publishedAt,
                ]);
                $id = (int)$conn->lastInsertId();
            }

            re_ann_replace_targets($conn, $currentCompanyId, $id, $scope, $targets);

            if (!empty($_FILES['image']) && (int)($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $img = re_ann_store_image($conn, $currentCompanyId, $id, $_FILES['image']);
                if (empty($img['ok'])) {
                    throw new RuntimeException($img['error'] ?? 'Image upload failed');
                }
            }
            if (!empty($_FILES['attachment']) && (int)($_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $att = re_ann_store_attachment($conn, $currentCompanyId, $id, $_FILES['attachment'], $userId ?: null);
                if (empty($att['ok'])) {
                    throw new RuntimeException($att['error'] ?? 'Attachment upload failed');
                }
            }

            if ($status === 'published') {
                $load = $conn->prepare('SELECT * FROM re_announcements WHERE id = ? AND company_id = ?');
                $load->execute([$id, $currentCompanyId]);
                $ann = $load->fetch(PDO::FETCH_ASSOC) ?: [];
                $tg = re_ann_get_targets($conn, $currentCompanyId, $id);
                re_comm_fanout_notification($conn, $currentCompanyId, $ann, $tg, $userId);
            }

            re_comm_flash_redirect('Announcement saved (' . $status . ').');
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

if ($flash) {
    if ($flash[0] === 'success') {
        $success = $flash[1];
    } else {
        $error = $flash[1];
    }
}

$categories = re_ann_list_categories($conn, $currentCompanyId, false);
$activeCategories = array_values(array_filter($categories, static fn($c) => (int)$c['is_active'] === 1));

$announcements = $conn->prepare("
    SELECT a.*, c.name AS category_name, c.code AS category_code,
           COALESCE(NULLIF(u.fullname, ''), u.username) AS created_by_name
    FROM re_announcements a
    LEFT JOIN re_announcement_categories c ON c.id = a.category_id AND c.company_id = a.company_id
    LEFT JOIN `user` u ON u.id = a.created_by
    WHERE a.company_id = ?
    ORDER BY a.id DESC
    LIMIT 200
");
$announcements->execute([$currentCompanyId]);
$announcementRows = $announcements->fetchAll(PDO::FETCH_ASSOC) ?: [];

$buildingOptions = $conn->prepare('SELECT id, name FROM re_buildings WHERE company_id = ? ORDER BY name');
$buildingOptions->execute([$currentCompanyId]);
$buildingOptions = $buildingOptions->fetchAll(PDO::FETCH_ASSOC) ?: [];

$unitOptions = $conn->prepare("
    SELECT u.id, CONCAT(b.name, ' — ', u.unit_number) AS label
    FROM re_units u
    JOIN re_buildings b ON b.id = u.building_id AND b.company_id = u.company_id
    WHERE u.company_id = ?
    ORDER BY b.name, u.unit_number
    LIMIT 2000
");
$unitOptions->execute([$currentCompanyId]);
$unitOptions = $unitOptions->fetchAll(PDO::FETCH_ASSOC) ?: [];

$tenantOptions = $conn->prepare("
    SELECT tpu.id AS portal_user_id, tpu.tenant_id,
           COALESCE(NULLIF(tpu.display_name, ''), tpu.email, CONCAT('Tenant #', tpu.tenant_id)) AS label
    FROM tenant_portal_users tpu
    WHERE tpu.company_id = ? AND tpu.status = 'approved'
    ORDER BY label
    LIMIT 500
");
$tenantOptions->execute([$currentCompanyId]);
$tenantOptions = $tenantOptions->fetchAll(PDO::FETCH_ASSOC) ?: [];

$editId = (int)($_GET['edit'] ?? 0);
$editRow = null;
$editTargets = [];
$editAttachments = [];
if ($editId > 0) {
    $st = $conn->prepare('SELECT * FROM re_announcements WHERE id = ? AND company_id = ?');
    $st->execute([$editId, $currentCompanyId]);
    $editRow = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($editRow) {
        $editTargets = re_ann_get_targets($conn, $currentCompanyId, $editId);
        $attList = $conn->prepare('
            SELECT id, file_path, file_name, mime_type, file_size
            FROM re_announcement_attachments
            WHERE company_id = ? AND announcement_id = ?
            ORDER BY id ASC
        ');
        $attList->execute([$currentCompanyId, $editId]);
        $editAttachments = $attList->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $tab = 'announcements';
    }
}

$pageTitle = 'Tenant Communication Center';
require_once __DIR__ . '/includes/re_layout_header.php';
$ccTab = $tab === 'categories' ? 'categories' : 'announcements';
?>

<div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
    <div>
        <div class="page-header-label">Tenant Communication Center</div>
        <div class="text-muted">Building announcements, notices, and management messages — reusable by apps, website, and AI.</div>
    </div>
    <?php if ($ccTab === 'announcements'): ?>
        <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#announcementModal" onclick="resetAnnouncementForm()">
            <i class="bi bi-plus-circle"></i> New Announcement
        </button>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/re_communication_center_tabs.php'; ?>

<?php if ($success): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

<?php if ($ccTab === 'categories'): ?>
    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card card-round">
                <div class="card-body">
                    <h5 class="mb-3">Add / Edit Category</h5>
                    <form method="post">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="action" value="save_category">
                        <input type="hidden" name="category_id" id="categoryId" value="0">
                        <div class="mb-3">
                            <label class="form-label">Code *</label>
                            <input type="text" name="code" id="categoryCode" class="form-control" required placeholder="e.g. water_shutdown">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Name *</label>
                            <input type="text" name="name" id="categoryName" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Sort order</label>
                            <input type="number" name="sort_order" id="categorySort" class="form-control" value="100">
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" name="is_active" id="categoryActive" value="1" checked>
                            <label class="form-check-label" for="categoryActive">Active</label>
                        </div>
                        <button class="btn btn-primary" type="submit">Save Category</button>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-lg-7">
            <div class="card card-round">
                <div class="card-body">
                    <h5 class="mb-3">Configured Categories</h5>
                    <div class="table-responsive">
                        <table class="table align-middle">
                            <thead><tr><th>Name</th><th>Code</th><th>Sort</th><th>Status</th><th></th></tr></thead>
                            <tbody>
                            <?php foreach ($categories as $c): ?>
                                <tr>
                                    <td><?= h($c['name']) ?></td>
                                    <td><code><?= h($c['code']) ?></code></td>
                                    <td><?= (int)$c['sort_order'] ?></td>
                                    <td><?= (int)$c['is_active'] ? '<span class="badge text-bg-success">Active</span>' : '<span class="badge text-bg-secondary">Inactive</span>' ?></td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-outline-secondary"
                                            onclick='editCategory(<?= json_encode($c, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>)'>Edit</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php else: ?>
    <div class="card card-round">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th>Title</th>
                            <th>Category</th>
                            <th>Priority</th>
                            <th>Scope</th>
                            <th>Lifecycle</th>
                            <th>Publish</th>
                            <th>Expire</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($announcementRows === []): ?>
                        <tr><td colspan="8" class="text-muted">No announcements yet. Create one to notify tenants by building.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($announcementRows as $row): ?>
                        <?php
                            $life = re_ann_lifecycle_label($row);
                            $prio = (string)$row['priority'];
                            $prioClass = [
                                'critical' => 'text-bg-danger',
                                'high' => 'text-bg-warning',
                                'normal' => 'text-bg-primary',
                                'information' => 'text-bg-success',
                            ][$prio] ?? 'text-bg-secondary';
                        ?>
                        <tr>
                            <td>
                                <div class="fw-semibold"><?= h($row['title']) ?></div>
                                <div class="small text-muted">#<?= (int)$row['id'] ?> · <?= h($row['created_by_name'] ?? '—') ?></div>
                            </td>
                            <td><?= h($row['category_name'] ?? '—') ?></td>
                            <td><span class="badge <?= $prioClass ?>"><?= h(re_ann_priorities()[$prio] ?? $prio) ?></span></td>
                            <td><span class="badge text-bg-light border"><?= h($row['target_scope']) ?></span></td>
                            <td><span class="badge text-bg-secondary"><?= h($life) ?></span></td>
                            <td class="small"><?= h($row['publish_at'] ?? '—') ?></td>
                            <td class="small"><?= h($row['expire_at'] ?? '—') ?></td>
                            <td class="text-nowrap">
                                <a class="btn btn-sm btn-outline-primary" href="?tab=announcements&edit=<?= (int)$row['id'] ?>">Edit</a>
                                <?php if ($life !== 'Archived'): ?>
                                <form method="post" class="d-inline" onsubmit="return confirm('Archive this announcement?');">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="action" value="archive_announcement">
                                    <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                    <button class="btn btn-sm btn-outline-secondary" type="submit">Archive</button>
                                </form>
                                <?php endif; ?>
                                <?php if (in_array((string)$row['status'], ['draft', 'archived'], true)): ?>
                                <form method="post" class="d-inline" onsubmit="return confirm('Delete permanently?');">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="action" value="delete_announcement">
                                    <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Announcement modal -->
<div class="modal fade" id="announcementModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <form method="post" enctype="multipart/form-data" id="announcementForm">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="save_announcement">
                <input type="hidden" name="id" id="annId" value="<?= (int)($editRow['id'] ?? 0) ?>">
                <input type="hidden" name="save_intent" id="saveIntent" value="draft">
                <div class="modal-header">
                    <h5 class="modal-title" id="annModalTitle"><?= $editRow ? 'Edit Announcement' : 'New Announcement' ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label">Title *</label>
                            <input type="text" class="form-control" name="title" id="annTitle" required maxlength="200"
                                   value="<?= h($editRow['title'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Priority</label>
                            <select class="form-select" name="priority" id="annPriority">
                                <?php foreach (re_ann_priorities() as $k => $label): ?>
                                    <option value="<?= h($k) ?>" <?= (($editRow['priority'] ?? 'normal') === $k) ? 'selected' : '' ?>><?= h($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Category</label>
                            <select class="form-select" name="category_id" id="annCategory">
                                <option value="">— None —</option>
                                <?php foreach ($activeCategories as $c): ?>
                                    <option value="<?= (int)$c['id'] ?>" <?= ((int)($editRow['category_id'] ?? 0) === (int)$c['id']) ? 'selected' : '' ?>><?= h($c['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Target scope *</label>
                            <select class="form-select" name="target_scope" id="annScope" onchange="toggleTargetPanels()">
                                <?php
                                $scope = $editRow['target_scope'] ?? 'company';
                                foreach (['company' => 'Entire company', 'buildings' => 'Specific building(s)', 'units' => 'Specific unit(s)', 'tenants' => 'Specific tenant'] as $k => $lab):
                                ?>
                                    <option value="<?= h($k) ?>" <?= $scope === $k ? 'selected' : '' ?>><?= h($lab) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12" id="panelBuildings" style="display:none;">
                            <label class="form-label">Buildings</label>
                            <select class="form-select" name="building_ids[]" id="annBuildings" multiple size="6">
                                <?php
                                $selectedBuildings = [];
                                foreach ($editTargets as $t) {
                                    if ($t['target_type'] === 'building') {
                                        $selectedBuildings[] = (int)$t['target_id'];
                                    }
                                }
                                foreach ($buildingOptions as $b):
                                ?>
                                    <option value="<?= (int)$b['id'] ?>" <?= in_array((int)$b['id'], $selectedBuildings, true) ? 'selected' : '' ?>><?= h($b['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Hold Ctrl/Cmd to select multiple. AYLA-only water shutdown → select AYLA only.</div>
                        </div>
                        <div class="col-12" id="panelUnits" style="display:none;">
                            <label class="form-label">Units</label>
                            <select class="form-select" name="unit_ids[]" id="annUnits" multiple size="8">
                                <?php
                                $selectedUnits = [];
                                foreach ($editTargets as $t) {
                                    if ($t['target_type'] === 'unit') {
                                        $selectedUnits[] = (int)$t['target_id'];
                                    }
                                }
                                foreach ($unitOptions as $u):
                                ?>
                                    <option value="<?= (int)$u['id'] ?>" <?= in_array((int)$u['id'], $selectedUnits, true) ? 'selected' : '' ?>><?= h($u['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12" id="panelTenants" style="display:none;">
                            <label class="form-label">Tenant (portal user)</label>
                            <select class="form-select" name="tenant_portal_user_id" id="annTenant">
                                <option value="">— Select —</option>
                                <?php
                                $selectedPortal = 0;
                                foreach ($editTargets as $t) {
                                    if ($t['target_type'] === 'tenant_portal_user') {
                                        $selectedPortal = (int)$t['target_id'];
                                    }
                                }
                                foreach ($tenantOptions as $t):
                                ?>
                                    <option value="<?= (int)$t['portal_user_id'] ?>" <?= $selectedPortal === (int)$t['portal_user_id'] ? 'selected' : '' ?>><?= h($t['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Description *</label>
                            <textarea class="form-control" name="body" id="annBody" rows="5" required><?= h($editRow['body'] ?? '') ?></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Publish date</label>
                            <input type="datetime-local" class="form-control" name="publish_at" id="annPublish"
                                   value="<?= !empty($editRow['publish_at']) ? h(date('Y-m-d\TH:i', strtotime($editRow['publish_at']))) : '' ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Expiry date</label>
                            <input type="datetime-local" class="form-control" name="expire_at" id="annExpire"
                                   value="<?= !empty($editRow['expire_at']) ? h(date('Y-m-d\TH:i', strtotime($editRow['expire_at']))) : '' ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Optional image</label>
                            <input type="file" class="form-control" name="image" accept="image/jpeg,image/png,image/gif,image/webp">
                            <div class="form-text">Leave empty to keep the current image. Choose a new file to replace it.</div>
                            <?php if (!empty($editRow['image_path'])): ?>
                                <div class="mt-2 d-flex align-items-center gap-3 flex-wrap">
                                    <a href="<?= h(re_ann_media_url($editRow['image_path'])) ?>" target="_blank" rel="noopener">
                                        <img src="<?= h(re_ann_media_url($editRow['image_path'])) ?>"
                                             alt="Current announcement image"
                                             style="max-width:160px;max-height:100px;object-fit:cover;border-radius:8px;border:1px solid #ddd;">
                                    </a>
                                    <div class="small">
                                        <div class="fw-semibold text-success"><i class="bi bi-check-circle"></i> Image attached</div>
                                        <a href="<?= h(re_ann_media_url($editRow['image_path'])) ?>" target="_blank" rel="noopener">Open current image</a>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Optional attachment</label>
                            <input type="file" class="form-control" name="attachment" accept=".pdf,.doc,.docx,.xls,.xlsx,image/*">
                            <div class="form-text">PDF/Office/image. Choosing a file adds another attachment (does not remove existing ones).</div>
                            <?php if (!empty($editAttachments)): ?>
                                <div class="mt-2 small">
                                    <div class="fw-semibold text-success mb-1"><i class="bi bi-paperclip"></i> Current attachment(s)</div>
                                    <ul class="mb-0 ps-3">
                                        <?php foreach ($editAttachments as $att): ?>
                                            <li>
                                                <a href="<?= h(re_ann_media_url($att['file_path'] ?? '')) ?>" target="_blank" rel="noopener">
                                                    <?= h($att['file_name'] ?? 'Attachment') ?>
                                                </a>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="send_in_app" id="annInApp" value="1"
                                    <?= !isset($editRow) || !empty($editRow['send_in_app']) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="annInApp">Create in-app notification on publish</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="send_push" id="annPush" value="1"
                                    <?= !empty($editRow['send_push']) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="annPush">Also send Firebase push on publish</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer flex-wrap gap-2">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-outline-secondary" onclick="document.getElementById('saveIntent').value='draft'">Save draft</button>
                    <button type="submit" class="btn btn-outline-primary" onclick="document.getElementById('saveIntent').value='schedule'">Schedule</button>
                    <button type="submit" class="btn btn-primary" onclick="document.getElementById('saveIntent').value='publish'">Publish now</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function toggleTargetPanels() {
    const scope = document.getElementById('annScope').value;
    document.getElementById('panelBuildings').style.display = scope === 'buildings' ? 'block' : 'none';
    document.getElementById('panelUnits').style.display = scope === 'units' ? 'block' : 'none';
    document.getElementById('panelTenants').style.display = scope === 'tenants' ? 'block' : 'none';
}
function resetAnnouncementForm() {
    document.getElementById('announcementForm').reset();
    document.getElementById('annId').value = '0';
    document.getElementById('annModalTitle').textContent = 'New Announcement';
    document.getElementById('saveIntent').value = 'draft';
    document.getElementById('annInApp').checked = true;
    toggleTargetPanels();
}
function editCategory(c) {
    document.getElementById('categoryId').value = c.id;
    document.getElementById('categoryCode').value = c.code;
    document.getElementById('categoryName').value = c.name;
    document.getElementById('categorySort').value = c.sort_order;
    document.getElementById('categoryActive').checked = Number(c.is_active) === 1;
    window.scrollTo({ top: 0, behavior: 'smooth' });
}
toggleTargetPanels();
<?php if ($editRow): ?>
document.addEventListener('DOMContentLoaded', function() {
    new bootstrap.Modal(document.getElementById('announcementModal')).show();
});
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>
