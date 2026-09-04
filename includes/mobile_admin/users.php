<?php
/**
 * Mobile App Users Management
 * View and manage all mobile app users
 */

// Get filter parameters
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? 'all';
$users_page = isset($_GET['users_page']) ? (int)$_GET['users_page'] : 1;
$perPage = 15;
$offset = ($users_page - 1) * $perPage;

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

if ($status_filter !== 'all') {
    if ($status_filter === 'active') {
        $where[] = "mu.is_active = 1";
    } elseif ($status_filter === 'inactive') {
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
        (SELECT SUM(total_price) FROM online_bookings WHERE client_id = mu.client_id AND status = 'completed') as total_spent
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
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }
    .stat-card {
        background: white;
        padding: 20px;
        border-radius: 12px;
        text-align: center;
        box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        border-left: 4px solid #667eea;
    }
    .stat-value {
        font-size: 36px;
        font-weight: bold;
        color: #667eea;
        margin-bottom: 5px;
    }
    .stat-label {
        color: #8898aa;
        font-size: 14px;
    }
    .user-avatar {
        width: 45px;
        height: 45px;
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
        padding: 5px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
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
        border-radius: 6px;
        border: none;
        cursor: pointer;
        font-size: 12px;
        margin: 0 3px;
        transition: all 0.3s;
    }
    .btn-action:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(0,0,0,0.15);
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
</style>

<!-- Statistics Cards -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($stats['total_users']); ?></div>
        <div class="stat-label"><i class="fas fa-users"></i> Total Users</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($stats['active_users']); ?></div>
        <div class="stat-label"><i class="fas fa-user-check"></i> Active Users</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($stats['new_users_30d']); ?></div>
        <div class="stat-label"><i class="fas fa-user-plus"></i> New (30 Days)</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($stats['active_7d']); ?></div>
        <div class="stat-label"><i class="fas fa-chart-line"></i> Active (7 Days)</div>
    </div>
</div>

<!-- Users Table Card -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fas fa-users"></i> Mobile App Users (<?php echo $totalUsers; ?>)</h5>
        <a href="?page=users" class="btn btn-light btn-sm">
            <i class="fas fa-sync-alt"></i> Refresh
        </a>
    </div>
    
    <div class="card-body">
        <!-- Search & Filter -->
        <form method="GET" class="row g-3 mb-4">
            <input type="hidden" name="page" value="users">
            <div class="col-md-6">
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                    <input type="text" name="search" class="form-control" placeholder="Search by name, phone, or email..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
            </div>
            <div class="col-md-3">
                <select name="status" class="form-select">
                    <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                    <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                </select>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-gradient w-100">
                    <i class="fas fa-filter"></i> Apply Filter
                </button>
            </div>
        </form>

        <!-- Users Table -->
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th>User</th>
                        <th>Contact</th>
                        <th>Bookings</th>
                        <th>Total Spent</th>
                        <th>Last Login</th>
                        <th>Joined</th>
                        <th>Status</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users)): ?>
                        <tr>
                            <td colspan="8" class="text-center py-5">
                                <i class="fas fa-users fa-3x text-muted mb-3"></i>
                                <p class="text-muted">No users found</p>
                            </td>
                        </tr>
                    <?php else: ?>
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
                                            <small class="text-muted">
                                                ID: <?php echo $user['client_id']; ?>
                                                <?php if ($user['firebase_uid']): ?>
                                                    <i class="fas fa-fire text-warning" title="Firebase Auth"></i>
                                                <?php endif; ?>
                                            </small>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div><i class="fas fa-phone text-muted"></i> <?php echo htmlspecialchars($user['phone']); ?></div>
                                    <?php if ($user['email']): ?>
                                        <div><i class="fas fa-envelope text-muted"></i> <small><?php echo htmlspecialchars($user['email']); ?></small></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-primary"><?php echo $user['total_bookings']; ?></span>
                                </td>
                                <td>
                                    <?php if ($user['total_spent']): ?>
                                        <strong>AED <?php echo number_format($user['total_spent'], 2); ?></strong>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($user['last_login_at']): ?>
                                        <small><?php echo date('M d, Y', strtotime($user['last_login_at'])); ?></small>
                                    <?php else: ?>
                                        <small class="text-muted">Never</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <small><?php echo date('M d, Y', strtotime($user['created_at'])); ?></small>
                                </td>
                                <td>
                                    <span class="status-badge <?php echo $user['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                                        <?php echo $user['is_active'] ? 'Active' : 'Inactive'; ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <button class="btn-action btn-view" onclick="viewUserBookings(<?php echo $user['client_id']; ?>)" title="View Bookings">
                                        <i class="fas fa-calendar-alt"></i>
                                    </button>
                                    <a href="operation.php?tab=clients&client_id=<?php echo $user['client_id']; ?>" class="btn-action btn-edit" title="Edit Client">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <?php if ($user['is_active']): ?>
                                        <button class="btn-action btn-disable" onclick="toggleUserStatus(<?php echo $user['client_id']; ?>, 0)" title="Disable">
                                            <i class="fas fa-ban"></i>
                                        </button>
                                    <?php else: ?>
                                        <button class="btn-action btn-edit" onclick="toggleUserStatus(<?php echo $user['client_id']; ?>, 1)" title="Enable">
                                            <i class="fas fa-check"></i>
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <nav aria-label="Users pagination">
                <ul class="pagination justify-content-center mt-4">
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <li class="page-item <?php echo $i === $users_page ? 'active' : ''; ?>">
                            <a class="page-link" href="?page=users&users_page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo $status_filter; ?>">
                                <?php echo $i; ?>
                            </a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        <?php endif; ?>
    </div>
</div>

<script>
function viewUserBookings(clientId) {
    window.location.href = 'online_bookings.php?client_id=' + clientId;
}

function toggleUserStatus(clientId, status) {
    if (confirm('Are you sure you want to ' + (status ? 'enable' : 'disable') + ' this user?')) {
        fetch('includes/mobile_admin/ajax_users.php', {
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
        })
        .catch(error => {
            alert('Error: ' + error);
        });
    }
}
</script>

