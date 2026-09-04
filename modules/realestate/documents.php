<?php
/**
 * Real Estate Module - Enhanced Document Management
 * Comprehensive document storage and tracking system
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';

$isLegalLayout = defined('LEGAL_DOCUMENTS_LAYOUT') && LEGAL_DOCUMENTS_LAYOUT;
if ($isLegalLayout) {
    require_once __DIR__ . '/../legal/includes/legal_helper.php';
}

require_login();
if ($isLegalLayout) {
    if (!legal_can_manage($conn)) {
        legal_require_access($conn);
    }
} elseif (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_COMPLIANCE, $conn)) {
    require_module_access($conn, MODULE_REALESTATE);
}

$reBase = $isLegalLayout ? '../realestate/' : '';
$pageSelf = $isLegalLayout ? 'legal_documents.php' : 'documents.php';

$brand = getBrandSettings($conn);

// Get current company context - prefer realestate company for realestate module
$currentCompanyId = current_company_id($conn);
if (!$currentCompanyId) {
    // If no company set, get user's companies and prefer realestate
    $userId = current_user_id();
    if ($userId) {
        $userCompanies = get_user_companies($conn, $userId);
        if (!empty($userCompanies)) {
            // Find realestate company, or primary company, or first company
            $realestateCompany = null;
            $primaryCompany = null;
            
            foreach ($userCompanies as $company) {
                if ($company['business_type'] === 'realestate' && !$realestateCompany) {
                    $realestateCompany = $company;
                }
                if (!empty($company['is_primary']) && !$primaryCompany) {
                    $primaryCompany = $company;
                }
            }
            
            // Prefer realestate company for realestate module
            if ($realestateCompany) {
                $currentCompanyId = $realestateCompany['id'];
            } elseif ($primaryCompany) {
                $currentCompanyId = $primaryCompany['id'];
            } else {
                $currentCompanyId = $userCompanies[0]['id'];
            }
            
            set_current_company($currentCompanyId);
        }
    }
}
// Verify the company is a realestate company (for realestate module)
if ($currentCompanyId) {
    $company = get_company($conn, $currentCompanyId);
    if ($company && $company['business_type'] !== 'realestate') {
        // Wrong company type, find realestate company
        $userId = current_user_id();
        if ($userId) {
            $userCompanies = get_user_companies($conn, $userId);
            foreach ($userCompanies as $comp) {
                if ($comp['business_type'] === 'realestate') {
                    $currentCompanyId = $comp['id'];
                    set_current_company($currentCompanyId);
                    break;
                }
            }
        }
    }
}
// Fallback to 1 if still no company (for backward compatibility)
if (!$currentCompanyId) {
    $currentCompanyId = 1;
}

$currentUserId = $_SESSION['user_id'] ?? null;

// Get filter parameters
$relatedTypeFilter = $_GET['related_type'] ?? 'all';
$documentTypeFilter = $_GET['document_type'] ?? 'all';
$statusFilter = $_GET['status'] ?? 'all';
$tagFilter = $_GET['tag'] ?? '';
$searchQuery = $_GET['search'] ?? '';
$sortBy = $_GET['sort'] ?? 'created_at';
$sortOrder = $_GET['order'] ?? 'desc';
$showFavorites = !empty($_GET['favorites']);

// Context filter: show documents tied to a specific lease, tenant, or legal case
$leaseIdFilter = !empty($_GET['lease_id']) ? (int)$_GET['lease_id'] : 0;
$tenantIdFilter = !empty($_GET['tenant_id']) ? (int)$_GET['tenant_id'] : 0;
$caseIdFilter = !empty($_GET['case_id']) ? (int)$_GET['case_id'] : 0;
$hasContextFilter = ($leaseIdFilter > 0 || $tenantIdFilter > 0 || $caseIdFilter > 0);

// Build query
$where = ["d.company_id = ?"];
$params = [$currentCompanyId];

if ($hasContextFilter) {
    $contextConds = [];
    if ($leaseIdFilter > 0) {
        $contextConds[] = "(d.related_type = 'lease' AND d.related_id = ?)";
        $params[] = $leaseIdFilter;
    }
    if ($tenantIdFilter > 0) {
        $contextConds[] = "(d.related_type = 'tenant' AND d.related_id = ?)";
        $params[] = $tenantIdFilter;
    }
    if ($caseIdFilter > 0) {
        $contextConds[] = "(d.related_type = 'legal_case' AND d.related_id = ?)";
        $params[] = $caseIdFilter;
    }
    $where[] = '(' . implode(' OR ', $contextConds) . ')';
}

// Legal module: focus on case, lease, and tenant documents unless a specific filter is set.
if ($isLegalLayout && !$hasContextFilter && $relatedTypeFilter === 'all') {
    $where[] = "d.related_type IN ('legal_case', 'lease', 'tenant')";
}

// Resolve labels for the context banner
$contextLeaseLabel = '';
$contextTenantLabel = '';
$contextCaseLabel = '';
if ($leaseIdFilter > 0) {
    $stmtCtx = $conn->prepare("
        SELECT CONCAT(b.name, ' - ', u2.unit_number, ' (', l.lease_number, ')') AS label
        FROM re_leases l
        JOIN re_units u2 ON u2.id = l.unit_id
        JOIN re_buildings b ON b.id = u2.building_id
        WHERE l.id = ? AND l.company_id = ?
        LIMIT 1
    ");
    $stmtCtx->execute([$leaseIdFilter, $currentCompanyId]);
    $contextLeaseLabel = (string)($stmtCtx->fetchColumn() ?: ('Lease #' . $leaseIdFilter));
}
if ($tenantIdFilter > 0) {
    $stmtCtx = $conn->prepare("
        SELECT CONCAT(first_name, ' ', last_name) AS label
        FROM re_tenants WHERE id = ? AND company_id = ? LIMIT 1
    ");
    $stmtCtx->execute([$tenantIdFilter, $currentCompanyId]);
    $contextTenantLabel = (string)($stmtCtx->fetchColumn() ?: ('Tenant #' . $tenantIdFilter));
}
if ($caseIdFilter > 0) {
    $stmtCtx = $conn->prepare("
        SELECT CONCAT(case_number, ' — ', title) AS label
        FROM re_legal_cases WHERE id = ? AND company_id = ? AND deleted_at IS NULL LIMIT 1
    ");
    $stmtCtx->execute([$caseIdFilter, $currentCompanyId]);
    $contextCaseLabel = (string)($stmtCtx->fetchColumn() ?: ('Case #' . $caseIdFilter));
}

if ($relatedTypeFilter !== 'all') {
    $where[] = "d.related_type = ?";
    $params[] = $relatedTypeFilter;
}

if ($documentTypeFilter !== 'all') {
    if (is_numeric($documentTypeFilter)) {
        $where[] = "d.document_type_id = ?";
        $params[] = $documentTypeFilter;
    } else {
        $where[] = "d.document_type = ?";
        $params[] = $documentTypeFilter;
    }
}

if ($statusFilter !== 'all') {
    $where[] = "d.status = ?";
    $params[] = $statusFilter;
}

$documentEntitySearchSql = "
    EXISTS (
        SELECT 1 FROM re_leases l
        JOIN re_units u ON u.id = l.unit_id AND u.company_id = l.company_id
        JOIN re_tenants t ON t.id = l.tenant_id AND t.company_id = l.company_id
        WHERE d.related_type = 'lease' AND l.id = d.related_id AND l.company_id = d.company_id
        AND (
            l.lease_number LIKE ?
            OR u.unit_number LIKE ?
            OR CONCAT(t.first_name, ' ', t.last_name) LIKE ?
            OR IFNULL(t.company_name, '') LIKE ?
            OR IFNULL(t.phone, '') LIKE ?
            OR IFNULL(t.phone_alt, '') LIKE ?
        )
    )
    OR EXISTS (
        SELECT 1 FROM re_tenants t
        WHERE d.related_type = 'tenant' AND t.id = d.related_id AND t.company_id = d.company_id
        AND (
            CONCAT(t.first_name, ' ', t.last_name) LIKE ?
            OR IFNULL(t.company_name, '') LIKE ?
            OR IFNULL(t.phone, '') LIKE ?
            OR IFNULL(t.phone_alt, '') LIKE ?
        )
    )
    OR EXISTS (
        SELECT 1 FROM re_units u
        WHERE d.related_type = 'unit' AND u.id = d.related_id AND u.company_id = d.company_id
        AND u.unit_number LIKE ?
    )
    OR EXISTS (
        SELECT 1 FROM re_legal_cases lc
        LEFT JOIN re_leases l ON l.id = lc.lease_id AND l.company_id = lc.company_id
        LEFT JOIN re_units u ON u.id = COALESCE(lc.unit_id, l.unit_id) AND u.company_id = lc.company_id
        LEFT JOIN re_tenants t ON t.id = COALESCE(lc.tenant_id, l.tenant_id) AND t.company_id = lc.company_id
        WHERE d.related_type = 'legal_case' AND lc.id = d.related_id AND lc.company_id = d.company_id AND lc.deleted_at IS NULL
        AND (
            IFNULL(l.lease_number, '') LIKE ?
            OR IFNULL(u.unit_number, '') LIKE ?
            OR CONCAT(IFNULL(t.first_name, ''), ' ', IFNULL(t.last_name, '')) LIKE ?
            OR IFNULL(t.company_name, '') LIKE ?
            OR IFNULL(t.phone, '') LIKE ?
            OR IFNULL(t.phone_alt, '') LIKE ?
            OR lc.case_number LIKE ?
            OR lc.title LIKE ?
        )
    )
";

$documentSearchClause = null;
$documentSearchParams = [];
if ($searchQuery !== '') {
    $searchParam = '%' . trim($searchQuery) . '%';
    $documentSearchParams = array_merge(
        array_fill(0, 4, $searchParam),
        array_fill(0, 6, $searchParam),
        array_fill(0, 4, $searchParam),
        [$searchParam],
        array_fill(0, 8, $searchParam)
    );
    $documentSearchClause = "(
        d.document_name LIKE ? OR d.file_name LIKE ? OR d.document_number LIKE ? OR d.notes LIKE ?
        OR {$documentEntitySearchSql}
    )";
    $where[] = $documentSearchClause;
    $params = array_merge($params, $documentSearchParams);
}

if ($tagFilter) {
    $where[] = "EXISTS (
        SELECT 1 FROM re_document_tag_relations dtr
        JOIN re_document_tags dt ON dt.id = dtr.tag_id
        WHERE dtr.document_id = d.id AND dt.tag_name = ?
    )";
    $params[] = $tagFilter;
}

if ($showFavorites) {
    $where[] = "EXISTS (
        SELECT 1 FROM re_document_favorites df
        WHERE df.document_id = d.id AND df.user_id = ?
    )";
    $params[] = $currentUserId;
}

// Statistics - scoped for legal layout
$statsWhere = ['d.company_id = ?'];
$statsParams = [$currentCompanyId];
if ($hasContextFilter) {
    $contextConds = [];
    if ($leaseIdFilter > 0) {
        $contextConds[] = "(d.related_type = 'lease' AND d.related_id = ?)";
        $statsParams[] = $leaseIdFilter;
    }
    if ($tenantIdFilter > 0) {
        $contextConds[] = "(d.related_type = 'tenant' AND d.related_id = ?)";
        $statsParams[] = $tenantIdFilter;
    }
    if ($caseIdFilter > 0) {
        $contextConds[] = "(d.related_type = 'legal_case' AND d.related_id = ?)";
        $statsParams[] = $caseIdFilter;
    }
    $statsWhere[] = '(' . implode(' OR ', $contextConds) . ')';
} elseif ($isLegalLayout && $relatedTypeFilter === 'all') {
    $statsWhere[] = "d.related_type IN ('legal_case', 'lease', 'tenant')";
} elseif ($relatedTypeFilter !== 'all') {
    $statsWhere[] = "d.related_type = ?";
    $statsParams[] = $relatedTypeFilter;
}

if ($documentSearchClause !== null) {
    $statsWhere[] = $documentSearchClause;
    $statsParams = array_merge($statsParams, $documentSearchParams);
}

$stats = $conn->prepare("
    SELECT 
        COUNT(*) as total,
        COUNT(CASE WHEN d.status = 'active' THEN 1 END) as active,
        COUNT(CASE WHEN d.expiry_date IS NOT NULL AND d.expiry_date < CURDATE() THEN 1 END) as expired,
        COUNT(CASE WHEN d.expiry_date IS NOT NULL AND d.expiry_date >= CURDATE() AND d.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 1 END) as expiring_soon,
        SUM(d.file_size) as total_size
    FROM re_documents d
    WHERE " . implode(' AND ', $statsWhere) . "
");
$stats->execute($statsParams);
$stats = $stats->fetch(PDO::FETCH_ASSOC);
if (!$stats) {
    $stats = ['total' => 0, 'active' => 0, 'expired' => 0, 'expiring_soon' => 0, 'total_size' => 0];
}

// Get documents
$orderBy = "d.{$sortBy} " . strtoupper($sortOrder);

// Ensure currentUserId is set (fallback to 0 if not logged in)
if (!$currentUserId) {
    $currentUserId = 0;
}

// Build the query with proper parameter binding
// Note: is_favorite subquery uses user_id, which needs to be in params
$documentsQuery = "
    SELECT 
        d.*,
        dt.document_type_name,
        u.username as uploaded_by_name,
        CASE d.related_type
            WHEN 'lease' THEN COALESCE((SELECT CONCAT(b.name, ' - ', u2.unit_number, ' (', l.lease_number, ')') FROM re_leases l JOIN re_units u2 ON u2.id = l.unit_id JOIN re_buildings b ON b.id = u2.building_id WHERE l.id = d.related_id AND l.company_id = d.company_id LIMIT 1), 'Unknown Lease')
            WHEN 'tenant' THEN COALESCE((SELECT CONCAT(first_name, ' ', last_name) FROM re_tenants WHERE id = d.related_id AND company_id = d.company_id LIMIT 1), 'Unknown Tenant')
            WHEN 'unit' THEN COALESCE((SELECT CONCAT(b.name, ' - ', u2.unit_number) FROM re_units u2 JOIN re_buildings b ON b.id = u2.building_id WHERE u2.id = d.related_id AND u2.company_id = d.company_id LIMIT 1), 'Unknown Unit')
            WHEN 'building' THEN COALESCE((SELECT name FROM re_buildings WHERE id = d.related_id AND company_id = d.company_id LIMIT 1), 'Unknown Building')
            WHEN 'maintenance' THEN COALESCE((SELECT CONCAT('MR-', id) FROM re_maintenance_requests WHERE id = d.related_id AND company_id = d.company_id LIMIT 1), 'Unknown Request')
            WHEN 'legal_case' THEN COALESCE((SELECT CONCAT(case_number, ' — ', title) FROM re_legal_cases WHERE id = d.related_id AND company_id = d.company_id AND deleted_at IS NULL LIMIT 1), 'Unknown Case')
            ELSE 'Other'
        END as related_name,
        COALESCE((SELECT COUNT(*) FROM re_document_favorites WHERE document_id = d.id AND user_id = ?), 0) as is_favorite,
        (SELECT GROUP_CONCAT(dt2.tag_name) FROM re_document_tag_relations dtr2 
         JOIN re_document_tags dt2 ON dt2.id = dtr2.tag_id 
         WHERE dtr2.document_id = d.id) as tags_list,
        DATEDIFF(COALESCE(d.expiry_date, '2099-12-31'), CURDATE()) as days_until_expiry
    FROM re_documents d
    LEFT JOIN re_document_types dt ON dt.id = d.document_type_id
    LEFT JOIN user u ON u.id = d.uploaded_by
    WHERE " . implode(' AND ', $where) . "
    ORDER BY {$orderBy}
    LIMIT 100
";

// The is_favorite subquery's `user_id = ?` placeholder appears in the SELECT,
// i.e. BEFORE the WHERE placeholders, so its value must be the FIRST param.
array_unshift($params, $currentUserId);

$documents = $conn->prepare($documentsQuery);

try {
    $documents->execute($params);
    $documents = $documents->fetchAll(PDO::FETCH_ASSOC);
    
    // If query returns empty but stats show documents, try simpler query.
    // Skip when a lease/tenant context filter is active, since an empty result
    // there is legitimate (that lease may simply have no documents).
    if (empty($documents) && $stats['total'] > 0 && !$hasContextFilter) {
        error_log("=== COMPLEX QUERY RETURNED EMPTY, TRYING SIMPLE QUERY ===");
        
        // Build simpler query without complex CASE statements
        $simpleWhere = ["d.company_id = ?"];
        $simpleParams = [$currentCompanyId];
        
        if ($statusFilter !== 'all') {
            $simpleWhere[] = "d.status = ?";
            $simpleParams[] = $statusFilter;
        }
        
        $simpleQuery = $conn->prepare("
            SELECT 
                d.*,
                dt.document_type_name,
                u.username as uploaded_by_name,
                d.related_type,
                CASE d.related_type
                    WHEN 'lease' THEN 'Lease'
                    WHEN 'tenant' THEN 'Tenant'
                    WHEN 'unit' THEN 'Unit'
                    WHEN 'building' THEN 'Building'
                    WHEN 'maintenance' THEN 'Maintenance'
                    WHEN 'legal_case' THEN 'Legal Case'
                    ELSE 'Other'
                END as related_name,
                0 as is_favorite,
                NULL as tags_list,
                DATEDIFF(COALESCE(d.expiry_date, '2099-12-31'), CURDATE()) as days_until_expiry
            FROM re_documents d
            LEFT JOIN re_document_types dt ON dt.id = d.document_type_id
            LEFT JOIN user u ON u.id = d.uploaded_by
            WHERE " . implode(' AND ', $simpleWhere) . "
            ORDER BY d.created_at DESC
            LIMIT 100
        ");
        
        $simpleQuery->execute($simpleParams);
        $documents = $simpleQuery->fetchAll(PDO::FETCH_ASSOC);
        
        error_log("Simple query found " . count($documents) . " documents");
    }
} catch (PDOException $e) {
    error_log("=== DOCUMENTS QUERY ERROR ===");
    error_log("Error: " . $e->getMessage());
    error_log("Error Code: " . $e->getCode());
    error_log("Company ID: $currentCompanyId");
    error_log("Where clause: " . implode(' AND ', $where));
    error_log("Params count: " . count($params));
    error_log("Params: " . print_r($params, true));
    
    // Fallback to simple query on error
    try {
        $simpleQuery = $conn->prepare("
            SELECT d.*, dt.document_type_name, u.username as uploaded_by_name
            FROM re_documents d
            LEFT JOIN re_document_types dt ON dt.id = d.document_type_id
            LEFT JOIN user u ON u.id = d.uploaded_by
            WHERE d.company_id = ?
            ORDER BY d.created_at DESC
            LIMIT 100
        ");
        $simpleQuery->execute([$currentCompanyId]);
        $documents = $simpleQuery->fetchAll(PDO::FETCH_ASSOC);
        error_log("Fallback simple query found " . count($documents) . " documents");
    } catch (PDOException $e2) {
        error_log("Fallback query also failed: " . $e2->getMessage());
        $documents = [];
    }
}

// Get document types for filter
$documentTypes = $conn->prepare("
    SELECT id, document_type_name FROM re_document_types
    WHERE company_id = ? AND is_active = 1
    ORDER BY document_type_name
");
$documentTypes->execute([$currentCompanyId]);
$documentTypes = $documentTypes->fetchAll(PDO::FETCH_ASSOC);

// Get tags for filter
$tags = $conn->prepare("
    SELECT DISTINCT dt.tag_name, dt.tag_color
    FROM re_document_tags dt
    JOIN re_document_tag_relations dtr ON dtr.tag_id = dt.id
    JOIN re_documents d ON d.id = dtr.document_id
    WHERE d.company_id = ?
    ORDER BY dt.tag_name
");
$tags->execute([$currentCompanyId]);
$tags = $tags->fetchAll(PDO::FETCH_ASSOC);

// Statistics already fetched above

function formatFileSize($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' bytes';
    }
}

if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

// Set page title and include layout
$pageTitle = 'Document Management';
$pageStyles = '
    .document-card {
        transition: transform 0.2s;
    }
    .document-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(0,0,0,0.1);
    }
    .tag-badge {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 12px;
        font-size: 0.75rem;
        margin: 2px;
    }
';
if ($isLegalLayout) {
    $pageHead = '<style>' . $pageStyles . '</style>';
    require_once __DIR__ . '/../legal/includes/legal_layout_header.php';
} else {
    require_once __DIR__ . '/includes/re_layout_header.php';
}
?>

        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h3 mb-0"><i class="bi bi-folder"></i> <?= $isLegalLayout ? 'Legal Documents' : 'Document Management' ?></h1>
            <div>
                <a href="<?= h($reBase) ?>documents_upload.php<?= $isLegalLayout ? '?return=legal' : '' ?>" class="btn btn-primary">
                    <i class="bi bi-upload"></i> Upload Document
                </a>
                <a href="<?= h($reBase) ?>documents_tags.php" class="btn btn-outline-secondary">
                    <i class="bi bi-tags"></i> Manage Tags
                </a>
            </div>
        </div>

        <?php if (!empty($_GET['uploaded'])): ?>
            <div class="alert alert-success">Document uploaded successfully.</div>
        <?php endif; ?>

        <?php if ($hasContextFilter): ?>
            <div class="alert alert-info d-flex justify-content-between align-items-center">
                <div>
                    <i class="bi bi-funnel-fill"></i>
                    Showing documents for:
                    <?php if ($contextLeaseLabel): ?>
                        <strong>Lease</strong> <?= h($contextLeaseLabel) ?>
                    <?php endif; ?>
                    <?php if ($contextLeaseLabel && $contextTenantLabel): ?> &nbsp;|&nbsp; <?php endif; ?>
                    <?php if ($contextTenantLabel): ?>
                        <strong>Tenant</strong> <?= h($contextTenantLabel) ?>
                    <?php endif; ?>
                    <?php if ($contextCaseLabel): ?>
                        <?php if ($contextLeaseLabel || $contextTenantLabel): ?> &nbsp;|&nbsp; <?php endif; ?>
                        <strong>Legal Case</strong> <?= h($contextCaseLabel) ?>
                    <?php endif; ?>
                </div>
                <a href="<?= h($pageSelf) ?>" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-x-circle"></i> Clear filter
                </a>
            </div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="text-muted">Total Documents</h5>
                        <h2 class="mb-0"><?= $stats['total'] ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center border-success">
                    <div class="card-body">
                        <h5 class="text-muted">Active</h5>
                        <h2 class="mb-0 text-success"><?= $stats['active'] ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center border-danger">
                    <div class="card-body">
                        <h5 class="text-muted">Expired</h5>
                        <h2 class="mb-0 text-danger"><?= $stats['expired'] ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center border-warning">
                    <div class="card-body">
                        <h5 class="text-muted">Expiring Soon</h5>
                        <h2 class="mb-0 text-warning"><?= $stats['expiring_soon'] ?></h2>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <?php if ($leaseIdFilter > 0): ?>
                        <input type="hidden" name="lease_id" value="<?= (int)$leaseIdFilter ?>">
                    <?php endif; ?>
                    <?php if ($tenantIdFilter > 0): ?>
                        <input type="hidden" name="tenant_id" value="<?= (int)$tenantIdFilter ?>">
                    <?php endif; ?>
                    <?php if ($caseIdFilter > 0): ?>
                        <input type="hidden" name="case_id" value="<?= (int)$caseIdFilter ?>">
                    <?php endif; ?>
                    <div class="col-md-3">
                        <label class="form-label">Search</label>
                        <input type="text" name="search" class="form-control form-control-sm" 
                               value="<?= h($searchQuery) ?>" placeholder="<?= $isLegalLayout ? 'Tenant, unit, lease #, mobile, file…' : 'Search documents…' ?>">
                        <?php if ($isLegalLayout): ?>
                        <small class="text-muted">Matches tenant name, unit, lease #, phone, and file details.</small>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Related To</label>
                        <select name="related_type" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="all" <?= $relatedTypeFilter === 'all' ? 'selected' : '' ?>>All Types</option>
                            <option value="lease" <?= $relatedTypeFilter === 'lease' ? 'selected' : '' ?>>Lease</option>
                            <option value="tenant" <?= $relatedTypeFilter === 'tenant' ? 'selected' : '' ?>>Tenant</option>
                            <option value="unit" <?= $relatedTypeFilter === 'unit' ? 'selected' : '' ?>>Unit</option>
                            <option value="building" <?= $relatedTypeFilter === 'building' ? 'selected' : '' ?>>Building</option>
                            <option value="maintenance" <?= $relatedTypeFilter === 'maintenance' ? 'selected' : '' ?>>Maintenance</option>
                            <option value="legal_case" <?= $relatedTypeFilter === 'legal_case' ? 'selected' : '' ?>>Legal Case</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Document Type</label>
                        <select name="document_type" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="all" <?= $documentTypeFilter === 'all' ? 'selected' : '' ?>>All Types</option>
                            <?php foreach ($documentTypes as $type): ?>
                                <option value="<?= $type['id'] ?>" <?= $documentTypeFilter == $type['id'] ? 'selected' : '' ?>>
                                    <?= h($type['document_type_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Statuses</option>
                            <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="expired" <?= $statusFilter === 'expired' ? 'selected' : '' ?>>Expired</option>
                            <option value="renewed" <?= $statusFilter === 'renewed' ? 'selected' : '' ?>>Renewed</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Tag</label>
                        <select name="tag" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="">All Tags</option>
                            <?php foreach ($tags as $tag): ?>
                                <option value="<?= h($tag['tag_name']) ?>" <?= $tagFilter === $tag['tag_name'] ? 'selected' : '' ?>>
                                    <?= h($tag['tag_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-1">
                        <label class="form-label">&nbsp;</label>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="favorites" id="favorites" 
                                   <?= $showFavorites ? 'checked' : '' ?> onchange="this.form.submit()">
                            <label class="form-check-label" for="favorites">Favorites</label>
                        </div>
                    </div>
                    <div class="col-md-12">
                        <button type="submit" class="btn btn-sm btn-primary">Apply Filters</button>
                        <a href="<?= h($pageSelf) ?>" class="btn btn-sm btn-secondary">Clear</a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Documents Grid -->
        <div class="row">
            <?php if (empty($documents)): ?>
                <div class="col-12">
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> No documents found. 
                        <a href="<?= h($reBase) ?>documents_upload.php<?= $isLegalLayout ? '?return=legal' : '' ?>">Upload your first document</a>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($documents as $doc): ?>
                    <div class="col-md-4 mb-4">
                        <div class="card document-card h-100">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <div>
                                    <i class="bi bi-file-earmark-<?= 
                                        strpos($doc['mime_type'] ?? '', 'pdf') !== false ? 'pdf' : 
                                        (strpos($doc['mime_type'] ?? '', 'image') !== false ? 'image' : 'text') 
                                    ?>"></i>
                                    <strong><?= h($doc['document_name'] ?: $doc['file_name']) ?></strong>
                                </div>
                                <?php if ($doc['is_favorite']): ?>
                                    <i class="bi bi-star-fill text-warning"></i>
                                <?php endif; ?>
                            </div>
                            <div class="card-body">
                                <p class="mb-2">
                                    <small class="text-muted">
                                        <i class="bi bi-tag"></i> <?= h($doc['document_type_name'] ?: ucfirst(str_replace('_', ' ', $doc['document_type'] ?? 'other'))) ?>
                                    </small>
                                </p>
                                <p class="mb-2">
                                    <small class="text-muted">
                                        <i class="bi bi-link-45deg"></i> <?= ucfirst($doc['related_type']) ?>: 
                                        <?= h($doc['related_name'] ?: '-') ?>
                                    </small>
                                </p>
                                <?php if ($doc['tags_list']): ?>
                                    <p class="mb-2">
                                        <?php foreach (explode(',', $doc['tags_list']) as $tag): ?>
                                            <span class="badge bg-secondary tag-badge"><?= h(trim($tag)) ?></span>
                                        <?php endforeach; ?>
                                    </p>
                                <?php endif; ?>
                                <?php if ($doc['expiry_date']): ?>
                                    <p class="mb-2">
                                        <small class="text-<?= 
                                            $doc['days_until_expiry'] < 0 ? 'danger' : 
                                            ($doc['days_until_expiry'] <= 30 ? 'warning' : 'muted') 
                                        ?>">
                                            <i class="bi bi-calendar"></i> 
                                            Expires: <?= date('M d, Y', strtotime($doc['expiry_date'])) ?>
                                            <?php if ($doc['days_until_expiry'] !== null): ?>
                                                (<?= $doc['days_until_expiry'] < 0 ? abs($doc['days_until_expiry']) . ' days expired' : $doc['days_until_expiry'] . ' days left' ?>)
                                            <?php endif; ?>
                                        </small>
                                    </p>
                                <?php endif; ?>
                                <p class="mb-2">
                                    <small class="text-muted">
                                        <i class="bi bi-file"></i> <?= formatFileSize($doc['file_size'] ?? 0) ?>
                                    </small>
                                </p>
                                <p class="mb-0">
                                    <small class="text-muted">
                                        <i class="bi bi-person"></i> <?= h($doc['uploaded_by_name'] ?? 'Unknown') ?>
                                        <br>
                                        <i class="bi bi-clock"></i> <?= date('M d, Y', strtotime($doc['created_at'])) ?>
                                    </small>
                                </p>
                            </div>
                            <div class="card-footer">
                                <div class="btn-group w-100" role="group">
                                    <a href="<?= h($reBase) ?>documents_view.php?id=<?= $doc['id'] ?>" class="btn btn-sm btn-primary">
                                        <i class="bi bi-eye"></i> View
                                    </a>
                                    <a href="<?= h($reBase) ?>documents_file.php?id=<?= $doc['id'] ?>&mode=view" target="_blank" rel="noopener" class="btn btn-sm btn-outline-info">
                                        <i class="bi bi-eye"></i> Open
                                    </a>
                                    <a href="<?= h($reBase) ?>documents_file.php?id=<?= $doc['id'] ?>&mode=download" class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-download"></i> Download
                                    </a>
                                    <button type="button" class="btn btn-sm btn-outline-warning" 
                                            onclick="toggleFavorite(<?= $doc['id'] ?>, <?= $doc['is_favorite'] ? 'true' : 'false' ?>)">
                                        <i class="bi bi-star<?= $doc['is_favorite'] ? '-fill' : '' ?>"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function logDocumentAccess(docId, action) {
            fetch('<?= h($reBase) ?>ajax_log_document_access.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({document_id: docId, action: action})
            });
        }
        
        function toggleFavorite(docId, isFavorite) {
            fetch('<?= h($reBase) ?>ajax_toggle_favorite.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({document_id: docId, is_favorite: isFavorite})
            }).then(() => {
                location.reload();
            });
        }
    </script>

<?php
if ($isLegalLayout) {
    require_once __DIR__ . '/../legal/includes/legal_layout_footer.php';
} else {
    require_once __DIR__ . '/includes/re_layout_footer.php';
}
?>

