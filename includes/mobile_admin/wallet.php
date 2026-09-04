<?php
/**
 * Wallet Management Page
 * View and manage user wallets, balances, and transactions
 */

// Pagination
$perPage = 20;
$pageNum = isset($_GET['p']) ? (int)$_GET['p'] : 1;
$offset = ($pageNum - 1) * $perPage;

// Search filter
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$whereClause = "1=1";
$params = [];

if ($search) {
    $whereClause .= " AND (c.client_name LIKE ? OR c.mobile_num LIKE ? OR c.email LIKE ?)";
    $searchTerm = "%{$search}%";
    $params = [$searchTerm, $searchTerm, $searchTerm];
}

// Get all mobile users (from mobile_user table) with their wallet info
$countStmt = $conn->prepare("
    SELECT COUNT(DISTINCT mu.client_id) 
    FROM mobile_user mu
    INNER JOIN client c ON mu.client_id = c.id
    WHERE $whereClause
");
$countStmt->execute($params);
$totalUsers = $countStmt->fetchColumn();
$totalPages = ceil($totalUsers / $perPage);

// Get users with wallet info (LEFT JOIN to include users without wallets)
$stmt = $conn->prepare("
    SELECT 
        mu.client_id,
        c.client_name,
        c.mobile_num,
        c.email,
        COALESCE(w.id, 0) as wallet_id,
        COALESCE(w.balance, 0.00) as balance,
        COALESCE(w.updated_at, mu.created_at) as last_updated,
        (SELECT COUNT(*) FROM mobile_wallet_transactions WHERE client_id = mu.client_id) as transaction_count,
        (SELECT SUM(amount) FROM mobile_wallet_transactions WHERE client_id = mu.client_id AND transaction_type = 'credit') as total_credited,
        (SELECT SUM(amount) FROM mobile_wallet_transactions WHERE client_id = mu.client_id AND transaction_type = 'debit') as total_debited
    FROM mobile_user mu
    INNER JOIN client c ON mu.client_id = c.id
    LEFT JOIN mobile_user_wallet w ON mu.client_id = w.client_id
    WHERE $whereClause
    ORDER BY COALESCE(w.balance, 0) DESC, mu.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Statistics
$statsStmt = $conn->query("
    SELECT 
        COUNT(DISTINCT w.id) as total_wallets,
        COALESCE(SUM(w.balance), 0) as total_balance,
        COUNT(CASE WHEN w.balance > 0 THEN 1 END) as active_wallets,
        COUNT(DISTINCT mu.client_id) as total_mobile_users
    FROM mobile_user mu
    LEFT JOIN mobile_user_wallet w ON mu.client_id = w.client_id
");
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
?>

<div class="row mb-4">
    <div class="col-md-3">
        <div class="card stats-card">
            <div class="card-body">
                <h6 class="text-muted mb-2">Total Users</h6>
                <h3 class="mb-0"><?= number_format($stats['total_mobile_users']) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stats-card">
            <div class="card-body">
                <h6 class="text-muted mb-2">Total Wallets</h6>
                <h3 class="mb-0"><?= number_format($stats['total_wallets']) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stats-card">
            <div class="card-body">
                <h6 class="text-muted mb-2">Total Balance</h6>
                <h3 class="mb-0">AED <?= number_format($stats['total_balance'] ?? 0, 2) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stats-card">
            <div class="card-body">
                <h6 class="text-muted mb-2">Active Wallets</h6>
                <h3 class="mb-0"><?= number_format($stats['active_wallets']) ?></h3>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fas fa-wallet"></i> Wallet Management</h5>
        <div class="d-flex gap-2">
            <button class="btn btn-success btn-sm" onclick="quickAddBalance()">
                <i class="fas fa-plus"></i> Quick Add Balance
            </button>
            <form method="GET" class="d-flex">
                <input type="hidden" name="page" value="wallet">
                <input type="text" name="search" class="form-control form-control-sm" 
                       placeholder="Search by name, phone, email..." 
                       value="<?= htmlspecialchars($search) ?>">
                <button type="submit" class="btn btn-light btn-sm">
                    <i class="fas fa-search"></i>
                </button>
                <?php if ($search): ?>
                    <a href="?page=wallet" class="btn btn-light btn-sm">
                        <i class="fas fa-times"></i>
                    </a>
                <?php endif; ?>
            </form>
        </div>
    </div>
    <div class="card-body">
        <?php if (empty($users)): ?>
            <div class="text-center py-5">
                <i class="fas fa-wallet fa-3x text-muted mb-3"></i>
                <p class="text-muted">No users found</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Balance</th>
                            <th>Transactions</th>
                            <th>Total Credited</th>
                            <th>Total Debited</th>
                            <th>Last Updated</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                            <tr>
                                <td>
                                    <div>
                                        <strong><?= htmlspecialchars($user['client_name']) ?></strong><br>
                                        <small class="text-muted">
                                            <?= htmlspecialchars($user['mobile_num']) ?><br>
                                            <?= htmlspecialchars($user['email'] ?: 'No email') ?>
                                        </small>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($user['wallet_id'] > 0): ?>
                                        <span class="badge bg-<?= $user['balance'] > 0 ? 'success' : 'secondary' ?>">
                                            AED <?= number_format($user['balance'], 2) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">No Wallet</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= number_format($user['transaction_count']) ?></td>
                                <td class="text-success">+AED <?= number_format($user['total_credited'] ?? 0, 2) ?></td>
                                <td class="text-danger">-AED <?= number_format($user['total_debited'] ?? 0, 2) ?></td>
                                <td>
                                    <small><?= $user['last_updated'] ? date('M d, Y H:i', strtotime($user['last_updated'])) : 'Never' ?></small>
                                </td>
                                <td>
                                    <?php if ($user['wallet_id'] > 0): ?>
                                        <button class="btn btn-sm btn-primary" 
                                                onclick="viewWallet(<?= $user['client_id'] ?>, '<?= htmlspecialchars($user['client_name']) ?>')">
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                    <?php endif; ?>
                                    <button class="btn btn-sm btn-success" 
                                            onclick="addBalance(<?= $user['client_id'] ?>, '<?= htmlspecialchars($user['client_name']) ?>', <?= $user['balance'] ?>)">
                                        <i class="fas fa-plus"></i> Add Balance
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <nav>
                    <ul class="pagination justify-content-center">
                        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                            <li class="page-item <?= $i == $pageNum ? 'active' : '' ?>">
                                <a class="page-link" href="?page=wallet&p=<?= $i ?><?= $search ? '&search=' . urlencode($search) : '' ?>">
                                    <?= $i ?>
                                </a>
                            </li>
                        <?php endfor; ?>
                    </ul>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Quick Add Balance Modal -->
<div class="modal fade" id="quickAddModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Quick Add Balance</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="quickAddForm">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Search User</label>
                        <input type="text" class="form-control" id="quickSearchUser" 
                               placeholder="Type name, phone, or email..." autocomplete="off">
                        <div id="quickUserResults" class="mt-2"></div>
                    </div>
                    <input type="hidden" id="quickClientId" name="client_id">
                    <div class="mb-3" id="quickUserInfo" style="display: none;">
                        <div class="alert alert-info">
                            <strong>Selected User:</strong> <span id="quickUserName"></span><br>
                            <small>Current Balance: <span id="quickCurrentBalance">AED 0.00</span></small>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Amount to Add <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" name="amount" step="0.01" min="0.01" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Category</label>
                        <select class="form-select" name="category" required>
                            <option value="bonus">Welcome Bonus</option>
                            <option value="compensation">Compensation</option>
                            <option value="refund">Refund</option>
                            <option value="adjustment">Manual Adjustment</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" rows="3" 
                                  placeholder="Reason for adding balance..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-plus"></i> Add Balance
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Wallet Modal -->
<div class="modal fade" id="viewWalletModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Wallet Details - <span id="modalUserName"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="walletDetails">
                <div class="text-center">
                    <div class="spinner-border" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add Balance Modal -->
<div class="modal fade" id="addBalanceModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Balance - <span id="addBalanceUserName"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="addBalanceForm">
                <div class="modal-body">
                    <input type="hidden" id="addBalanceClientId" name="client_id">
                    <div class="mb-3">
                        <label class="form-label">Current Balance</label>
                        <input type="text" class="form-control" id="currentBalance" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Amount to Add <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" name="amount" step="0.01" min="0.01" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Category</label>
                        <select class="form-select" name="category" required>
                            <option value="bonus">Welcome Bonus</option>
                            <option value="compensation">Compensation</option>
                            <option value="refund">Refund</option>
                            <option value="adjustment">Manual Adjustment</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" rows="3" 
                                  placeholder="Reason for adding balance..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-plus"></i> Add Balance
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
let searchTimeout;

// Quick Add Balance - Search users
function quickAddBalance() {
    const modal = new bootstrap.Modal(document.getElementById('quickAddModal'));
    modal.show();
    document.getElementById('quickAddForm').reset();
    document.getElementById('quickUserInfo').style.display = 'none';
    document.getElementById('quickUserResults').innerHTML = '';
}

document.getElementById('quickSearchUser').addEventListener('input', function() {
    clearTimeout(searchTimeout);
    const query = this.value.trim();
    
    if (query.length < 2) {
        document.getElementById('quickUserResults').innerHTML = '';
        return;
    }
    
    searchTimeout = setTimeout(() => {
        fetch('includes/mobile_admin/ajax_wallet.php?action=search_users&q=' + encodeURIComponent(query))
            .then(response => response.json())
            .then(data => {
                if (data.success && data.users) {
                    let html = '<div class="list-group">';
                    data.users.forEach(user => {
                        html += `
                            <a href="#" class="list-group-item list-group-item-action" 
                               onclick="selectQuickUser(${user.client_id}, '${user.client_name}', ${user.balance || 0})">
                                <strong>${user.client_name}</strong><br>
                                <small>${user.mobile_num} | Balance: AED ${parseFloat(user.balance || 0).toFixed(2)}</small>
                            </a>
                        `;
                    });
                    html += '</div>';
                    document.getElementById('quickUserResults').innerHTML = html;
                } else {
                    document.getElementById('quickUserResults').innerHTML = 
                        '<div class="text-muted">No users found</div>';
                }
            });
    }, 300);
});

function selectQuickUser(clientId, userName, balance) {
    document.getElementById('quickClientId').value = clientId;
    document.getElementById('quickUserName').textContent = userName;
    document.getElementById('quickCurrentBalance').textContent = 'AED ' + parseFloat(balance).toFixed(2);
    document.getElementById('quickUserInfo').style.display = 'block';
    document.getElementById('quickUserResults').innerHTML = '';
    document.getElementById('quickSearchUser').value = userName;
}

document.getElementById('quickAddForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const clientId = document.getElementById('quickClientId').value;
    if (!clientId) {
        alert('Please select a user first');
        return;
    }
    
    const formData = new FormData(this);
    formData.append('action', 'add_balance');
    
    fetch('includes/mobile_admin/ajax_wallet.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Balance added successfully!');
            location.reload();
        } else {
            alert('Error: ' + data.error);
        }
    })
    .catch(error => {
        alert('Error adding balance');
    });
});

function viewWallet(clientId, userName) {
    document.getElementById('modalUserName').textContent = userName;
    document.getElementById('walletDetails').innerHTML = '<div class="text-center"><div class="spinner-border"></div></div>';
    
    const modal = new bootstrap.Modal(document.getElementById('viewWalletModal'));
    modal.show();
    
    fetch('includes/mobile_admin/ajax_wallet.php?action=view&client_id=' + clientId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                let html = `
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <div class="card bg-primary text-white">
                                <div class="card-body text-center">
                                    <h6>Current Balance</h6>
                                    <h3>AED ${parseFloat(data.wallet.balance).toFixed(2)}</h3>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card bg-success text-white">
                                <div class="card-body text-center">
                                    <h6>Total Credited</h6>
                                    <h3>AED ${parseFloat(data.stats.total_credited || 0).toFixed(2)}</h3>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card bg-danger text-white">
                                <div class="card-body text-center">
                                    <h6>Total Debited</h6>
                                    <h3>AED ${parseFloat(data.stats.total_debited || 0).toFixed(2)}</h3>
                                </div>
                            </div>
                        </div>
                    </div>
                    <h6>Recent Transactions</h6>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Type</th>
                                    <th>Amount</th>
                                    <th>Balance</th>
                                    <th>Category</th>
                                    <th>Description</th>
                                </tr>
                            </thead>
                            <tbody>
                `;
                
                if (data.transactions && data.transactions.length > 0) {
                    data.transactions.forEach(t => {
                        html += `
                            <tr>
                                <td><small>${new Date(t.created_at).toLocaleDateString()}</small></td>
                                <td><span class="badge bg-${t.transaction_type === 'credit' ? 'success' : 'danger'}">${t.transaction_type}</span></td>
                                <td class="${t.transaction_type === 'credit' ? 'text-success' : 'text-danger'}">
                                    ${t.transaction_type === 'credit' ? '+' : '-'}AED ${parseFloat(t.amount).toFixed(2)}
                                </td>
                                <td>AED ${parseFloat(t.balance_after).toFixed(2)}</td>
                                <td><small>${t.transaction_category}</small></td>
                                <td><small>${t.description || '-'}</small></td>
                            </tr>
                        `;
                    });
                } else {
                    html += '<tr><td colspan="6" class="text-center text-muted">No transactions yet</td></tr>';
                }
                
                html += `
                            </tbody>
                        </table>
                    </div>
                `;
                
                document.getElementById('walletDetails').innerHTML = html;
            } else {
                document.getElementById('walletDetails').innerHTML = 
                    '<div class="alert alert-danger">' + data.error + '</div>';
            }
        })
        .catch(error => {
            document.getElementById('walletDetails').innerHTML = 
                '<div class="alert alert-danger">Error loading wallet details</div>';
        });
}

function addBalance(clientId, userName, currentBalance) {
    document.getElementById('addBalanceUserName').textContent = userName;
    document.getElementById('addBalanceClientId').value = clientId;
    document.getElementById('currentBalance').value = 'AED ' + parseFloat(currentBalance).toFixed(2);
    document.getElementById('addBalanceForm').reset();
    document.getElementById('addBalanceClientId').value = clientId;
    document.getElementById('currentBalance').value = 'AED ' + parseFloat(currentBalance).toFixed(2);
    
    const modal = new bootstrap.Modal(document.getElementById('addBalanceModal'));
    modal.show();
}

document.getElementById('addBalanceForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    formData.append('action', 'add_balance');
    
    fetch('includes/mobile_admin/ajax_wallet.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Balance added successfully!');
            location.reload();
        } else {
            alert('Error: ' + data.error);
        }
    })
    .catch(error => {
        alert('Error adding balance');
    });
});
</script>
