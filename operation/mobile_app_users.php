<?php
/**
 * Mobile App Users Management Page
 * View and manage all mobile app users
 */

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/header.php';

// Check user permissions
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login');
    exit;
}

// Get filter parameters
$search = $_GET['search'] ?? '';
$status = $_GET['status'] ?? 'all';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Build query
$where = ["1=1"];
$params = [];

if (!empty($search)) {
    $where[] = "(c.client_name LIKE ? OR c.mobile_num LIKE ? OR c.email LIKE ?)";
    $searchParam = "%$search%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
}

if ($status !== 'all') {
    if ($status === 'active') {
        $where[] = "mu.is_active = 1";
    } elseif ($status === 'inactive') {
        $where[] = "mu.is_active = 0";
    }
}

$whereClause = implode(' AND ', $where);

// Get total count
$countStmt = $conn->prepare("
    SELECT COUNT(DISTINCT mu.id) as total
    FROM mobile_user mu
    INNER JOIN client c ON mu.client_id = c.id
    WHERE $whereClause
");
$countStmt->execute($params);
$totalUsers = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
$totalPages = ceil($totalUsers / $perPage);

// Get users
$stmt = $conn->prepare("
    SELECT 
        mu.*,
        c.client_name as name,
        c.mobile_num as phone,
        c.email,
        c.address,
        c.firebase_uid,
        (SELECT COUNT(*) FROM online_bookings WHERE client_id = mu.client_id) as total_bookings,
        (SELECT SUM(total_amount) FROM online_bookings WHERE client_id = mu.client_id AND status = 'completed') as total_spent
    FROM mobile_user mu
    INNER JOIN client c ON mu.client_id = c.id
    WHERE $whereClause
    ORDER BY mu.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$statsStmt = $conn->prepare("
    SELECT 
        COUNT(DISTINCT mu.id) as total_users,
        COUNT(DISTINCT CASE WHEN mu.is_active = 1 THEN mu.id END) as active_users,
        COUNT(DISTINCT CASE WHEN mu.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN mu.id END) as new_users_30d,
        COUNT(DISTINCT CASE WHEN mu.last_login_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN mu.id END) as active_7d
    FROM mobile_user mu
");
$statsStmt->execute();
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
?>

<style>
    .stats-card {
        background: white;
        padding: 20px;
        border-radius: 8px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        margin-bottom: 20px;
    }
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }
    .stat-item {
        text-align: center;
        padding: 15px;
        background: #f8f9fa;
        border-radius: 8px;
    }
    .stat-value {
        font-size: 32px;
        font-weight: bold;
        color: #5e72e4;
    }
    .stat-label {
        color: #8898aa;
        font-size: 14px;
        margin-top: 5px;
    }
    .user-table {
        background: white;
        border-radius: 8px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        overflow: hidden;
    }
    .table-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 20px;
        border-bottom: 1px solid #e9ecef;
    }
    .search-filter {
        display: flex;
        gap: 10px;
        align-items: center;
    }
    .user-avatar {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: bold;
        font-size: 16px;
    }
    .status-badge {
        padding: 4px 12px;
        border-radius: 12px;
        font-size: 12px;
        font-weight: 500;
    }
    .status-active {
        background: #d4edda;
        color: #155724;
    }
    .status-inactive {
        background: #f8d7da;
        color: #721c24;
    }
    .btn-action {
        padding: 6px 12px;
        border-radius: 4px;
        border: none;
        cursor: pointer;
        font-size: 12px;
        margin: 0 2px;
    }
    .btn-view {
        background: #5e72e4;
        color: white;
    }
    .btn-edit {
        background: #2dce89;
        color: white;
    }
    .btn-disable {
        background: #f5365c;
        color: white;
    }
    .pagination {
        display: flex;
        justify-content: center;
        padding: 20px;
        gap: 5px;
    }
    .page-link {
        padding: 8px 12px;
        border: 1px solid #dee2e6;
        background: white;
        color: #5e72e4;
        text-decoration: none;
        border-radius: 4px;
    }
    .page-link.active {
        background: #5e72e4;
        color: white;
    }
</style>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>📱 Mobile App Users</h2>
        <a href="?tab=mobile_app_users" class="btn btn-primary">Refresh</a>
    </div>

    <!-- Statistics -->
    <div class="stats-grid">
        <div class="stat-item">
            <div class="stat-value"><?php echo number_format($stats['total_users']); ?></div>
            <div class="stat-label">Total Users</div>
        </div>
        <div class="stat-item">
            <div class="stat-value"><?php echo number_format($stats['active_users']); ?></div>
            <div class="stat-label">Active Users</div>
        </div>
        <div class="stat-item">
            <div class="stat-value"><?php echo number_format($stats['new_users_30d']); ?></div>
            <div class="stat-label">New (30 Days)</div>
        </div>
        <div class="stat-item">
            <div class="stat-value"><?php echo number_format($stats['active_7d']); ?></div>
            <div class="stat-label">Active (7 Days)</div>
        </div>
    </div>

    <!-- Users Table -->
    <div class="user-table">
        <div class="table-header">
            <h5 class="mb-0">All Users (<?php echo $totalUsers; ?>)</h5>
            <div class="search-filter">
                <form method="GET" class="d-flex gap-2">
                    <input type="hidden" name="tab" value="mobile_app_users">
                    <input type="text" name="search" class="form-control" placeholder="Search users..." value="<?php echo htmlspecialchars($search); ?>">
                    <select name="status" class="form-select">
                        <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>All Status</option>
                        <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo $status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                    <button type="submit" class="btn btn-primary">Filter</button>
                </form>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover">
                <thead class="table-light">
                    <tr>
                        <th>User</th>
                        <th>Phone</th>
                        <th>Email</th>
                        <th>Bookings</th>
                        <th>Total Spent</th>
                        <th>Last Login</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                        <?php
                        $initials = '';
                        $nameParts = explode(' ', $user['name']);
                        if (count($nameParts) >= 2) {
                            $initials = strtoupper($nameParts[0][0] . $nameParts[count($nameParts) - 1][0]);
                        } else {
                            $initials = strtoupper(substr($user['name'], 0, 2));
                        }
                        ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center">
                                    <div class="user-avatar"><?php echo $initials; ?></div>
                                    <div class="ms-3">
                                        <div class="fw-bold"><?php echo htmlspecialchars($user['name']); ?></div>
                                        <small class="text-muted">ID: <?php echo $user['client_id']; ?></small>
                                    </div>
                                </div>
                            </td>
                            <td><?php echo htmlspecialchars($user['phone']); ?></td>
                            <td><?php echo htmlspecialchars($user['email'] ?: '-'); ?></td>
                            <td><?php echo $user['total_bookings']; ?></td>
                            <td><?php echo $user['total_spent'] ? 'AED ' . number_format($user['total_spent'], 2) : '-'; ?></td>
                            <td>
                                <?php if ($user['last_login_at']): ?>
                                    <small><?php echo date('M d, Y', strtotime($user['last_login_at'])); ?></small>
                                <?php else: ?>
                                    <small class="text-muted">Never</small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="status-badge <?php echo $user['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                                    <?php echo $user['is_active'] ? 'Active' : 'Inactive'; ?>
                                </span>
                            </td>
                            <td>
                                <button class="btn-action btn-view" onclick="viewUser(<?php echo $user['client_id']; ?>)">
                                    <i class="fas fa-eye"></i> View
                                </button>
                                <button class="btn-action btn-edit" onclick="editUser(<?php echo $user['client_id']; ?>)">
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                                <?php if ($user['is_active']): ?>
                                    <button class="btn-action btn-disable" onclick="toggleUserStatus(<?php echo $user['client_id']; ?>, 0)">
                                        <i class="fas fa-ban"></i> Disable
                                    </button>
                                <?php else: ?>
                                    <button class="btn-action btn-edit" onclick="toggleUserStatus(<?php echo $user['client_id']; ?>, 1)">
                                        <i class="fas fa-check"></i> Enable
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <a href="?tab=mobile_app_users&page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo $status; ?>" 
                       class="page-link <?php echo $i === $page ? 'active' : ''; ?>">
                        <?php echo $i; ?>
                    </a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
function viewUser(clientId) {
    window.location.href = 'mobile_user_details.php?id=' + clientId;
}

function editUser(clientId) {
    window.location.href = '../operation.php?tab=clients&client_id=' + clientId;
}

function toggleUserStatus(clientId, status) {
    if (confirm('Are you sure you want to ' + (status ? 'enable' : 'disable') + ' this user?')) {
        fetch('ajax_mobile_users.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action: 'toggle_status', client_id: clientId, status: status})
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert('Error: ' + data.message);
            }
        });
    }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

