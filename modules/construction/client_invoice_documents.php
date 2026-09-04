<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/includes/construction_income_helpers.php';

require_login();
require_module_access($conn, MODULE_CONSTRUCTION);
require_once __DIR__ . '/../../includes/branding.php';

$brand = getBrandSettings($conn);
$cid = current_company_id($conn) ?: 1;
$userId = current_user_id();
$invoiceId = (int)($_GET['invoice_id'] ?? $_POST['invoice_id'] ?? 0);
if ($invoiceId <= 0) { header('Location: client_invoices.php'); exit; }
co_client_invoice_full_schema($conn);

$stmt = $conn->prepare("SELECT i.*, c.client_name FROM co_client_invoices i JOIN co_clients c ON c.id = i.client_id WHERE i.id = ? AND i.company_id = ?");
$stmt->execute([$invoiceId, $cid]);
$invoice = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$invoice) { header('Location: client_invoices.php'); exit; }

$error = '';
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_verify();
        if (($_POST['action'] ?? '') === 'delete') {
            $docId = (int)($_POST['document_id'] ?? 0);
            $doc = $conn->prepare("SELECT file_path FROM co_client_invoice_documents WHERE id = ? AND company_id = ? AND client_invoice_id = ?");
            $doc->execute([$docId, $cid, $invoiceId]);
            $path = $doc->fetchColumn();
            if ($path) {
                $abs = dirname(__DIR__, 2) . '/' . ltrim((string)$path, '/');
                if (is_file($abs)) @unlink($abs);
                $conn->prepare("DELETE FROM co_client_invoice_documents WHERE id = ? AND company_id = ? AND client_invoice_id = ?")->execute([$docId, $cid, $invoiceId]);
                $success = 'Document deleted.';
            }
        } else {
            $title = trim((string)($_POST['title'] ?? '')) ?: 'Invoice Attachment';
            $upload = co_handle_document_upload('document_file');
            if (!empty($upload['error'])) throw new RuntimeException($upload['error']);
            $conn->prepare("INSERT INTO co_client_invoice_documents (company_id, client_invoice_id, title, file_path, uploaded_by) VALUES (?,?,?,?,?)")
                ->execute([$cid, $invoiceId, $title, $upload['path'], $userId]);
            $success = 'Document uploaded.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$docs = $conn->prepare("SELECT * FROM co_client_invoice_documents WHERE company_id = ? AND client_invoice_id = ? ORDER BY uploaded_at DESC, id DESC");
$docs->execute([$cid, $invoiceId]);
$docs = $docs->fetchAll(PDO::FETCH_ASSOC) ?: [];
$appBase = (strpos($_SERVER['PHP_SELF'] ?? '', '/herosysgro') !== false) ? '/herosysgro' : '';

$pageTitle = 'Client Invoice Documents';
require_once __DIR__ . '/includes/construction_layout_header.php';
?>
<div class="mb-4">
    <a href="client_invoices.php" class="btn btn-outline-secondary btn-sm mb-2">Back to Invoices</a>
    <h1 class="h4 mb-0">Client Invoice Documents</h1>
    <p class="text-muted mb-0"><?= h($invoice['client_name']) ?> — <?= h($invoice['invoice_number']) ?></p>
</div>
<?php if ($success): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
<div class="row g-4">
    <div class="col-lg-5">
        <form method="post" enctype="multipart/form-data" class="card card-round">
            <div class="card-header bg-white">Upload Document</div>
            <div class="card-body">
                <?php csrf_field(); ?><input type="hidden" name="invoice_id" value="<?= (int)$invoiceId ?>">
                <label class="form-label">Title</label>
                <input type="text" name="title" class="form-control mb-3" value="Invoice Attachment">
                <label class="form-label">File</label>
                <input type="file" name="document_file" class="form-control mb-3" accept=".pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg,.webp" required>
                <button class="btn btn-primary">Upload</button>
            </div>
        </form>
    </div>
    <div class="col-lg-7">
        <div class="card card-round">
            <div class="card-header bg-white">Documents</div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead class="table-light"><tr><th>Title</th><th>Uploaded</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($docs as $doc): ?>
                        <tr>
                            <td><a href="<?= h($appBase . '/' . ltrim($doc['file_path'], '/')) ?>" target="_blank"><?= h($doc['title']) ?></a></td>
                            <td><?= h($doc['uploaded_at']) ?></td>
                            <td class="text-end"><form method="post" onsubmit="return confirm('Delete this document?');"><?php csrf_field(); ?><input type="hidden" name="invoice_id" value="<?= (int)$invoiceId ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="document_id" value="<?= (int)$doc['id'] ?>"><button class="btn btn-sm btn-outline-danger">Delete</button></form></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$docs): ?><tr><td colspan="3" class="text-center text-muted py-4">No documents uploaded.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
