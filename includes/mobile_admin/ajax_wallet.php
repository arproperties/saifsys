<?php
/**
 * AJAX Handler for Wallet Management
 */

require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json');

// Check authentication
require_login('login');
$currentUser = current_user();
if (!$currentUser) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    if ($action === 'search_users') {
        $query = trim($_GET['q'] ?? '');
        
        if (strlen($query) < 2) {
            echo json_encode(['success' => false, 'error' => 'Query too short']);
            exit;
        }
        
        $searchTerm = "%{$query}%";
        $stmt = $conn->prepare("
            SELECT 
                mu.client_id,
                c.client_name,
                c.mobile_num,
                c.email,
                COALESCE(w.balance, 0.00) as balance
            FROM mobile_user mu
            INNER JOIN client c ON mu.client_id = c.id
            LEFT JOIN mobile_user_wallet w ON mu.client_id = w.client_id
            WHERE c.client_name LIKE ? OR c.mobile_num LIKE ? OR c.email LIKE ?
            ORDER BY c.client_name
            LIMIT 10
        ");
        $stmt->execute([$searchTerm, $searchTerm, $searchTerm]);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'users' => array_map(function($u) {
                return [
                    'client_id' => (int)$u['client_id'],
                    'client_name' => $u['client_name'],
                    'mobile_num' => $u['mobile_num'],
                    'email' => $u['email'],
                    'balance' => (float)$u['balance'],
                ];
            }, $users),
        ]);
        exit;
    }
    
    if ($action === 'view') {
        $clientId = filter_var($_GET['client_id'] ?? 0, FILTER_VALIDATE_INT);
        
        if (!$clientId) {
            echo json_encode(['success' => false, 'error' => 'Invalid client ID']);
            exit;
        }
        
        // Get wallet
        $walletStmt = $conn->prepare("
            SELECT w.*, c.client_name, c.mobile_num, c.email
            FROM mobile_user_wallet w
            INNER JOIN client c ON w.client_id = c.id
            WHERE w.client_id = ?
        ");
        $walletStmt->execute([$clientId]);
        $wallet = $walletStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$wallet) {
            echo json_encode(['success' => false, 'error' => 'Wallet not found']);
            exit;
        }
        
        // Get transactions
        $transStmt = $conn->prepare("
            SELECT * FROM mobile_wallet_transactions
            WHERE client_id = ?
            ORDER BY created_at DESC
            LIMIT 50
        ");
        $transStmt->execute([$clientId]);
        $transactions = $transStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get statistics
        $statsStmt = $conn->prepare("
            SELECT 
                COUNT(*) as total_transactions,
                SUM(CASE WHEN transaction_type = 'credit' THEN amount ELSE 0 END) as total_credited,
                SUM(CASE WHEN transaction_type = 'debit' THEN amount ELSE 0 END) as total_debited
            FROM mobile_wallet_transactions
            WHERE client_id = ?
        ");
        $statsStmt->execute([$clientId]);
        $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'wallet' => [
                'id' => (int)$wallet['id'],
                'balance' => (float)$wallet['balance'],
                'currency' => $wallet['currency'],
                'client_name' => $wallet['client_name'],
                'mobile_num' => $wallet['mobile_num'],
                'email' => $wallet['email'],
            ],
            'transactions' => array_map(function($t) {
                return [
                    'id' => (int)$t['id'],
                    'transaction_type' => $t['transaction_type'],
                    'amount' => (float)$t['amount'],
                    'balance_before' => (float)$t['balance_before'],
                    'balance_after' => (float)$t['balance_after'],
                    'transaction_category' => $t['transaction_category'],
                    'description' => $t['description'],
                    'reference_id' => $t['reference_id'],
                    'reference_type' => $t['reference_type'],
                    'created_at' => $t['created_at'],
                ];
            }, $transactions),
            'stats' => [
                'total_transactions' => (int)$stats['total_transactions'],
                'total_credited' => (float)($stats['total_credited'] ?? 0),
                'total_debited' => (float)($stats['total_debited'] ?? 0),
            ],
        ]);
    }
    
    elseif ($action === 'add_balance') {
        $clientId = filter_var($_POST['client_id'] ?? 0, FILTER_VALIDATE_INT);
        $amount = filter_var($_POST['amount'] ?? 0, FILTER_VALIDATE_FLOAT);
        $category = $_POST['category'] ?? 'adjustment';
        $description = $_POST['description'] ?? '';
        
        if (!$clientId || !$amount || $amount <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid amount or client ID']);
            exit;
        }
        
        // Get or create wallet
        $walletStmt = $conn->prepare("SELECT * FROM mobile_user_wallet WHERE client_id = ?");
        $walletStmt->execute([$clientId]);
        $wallet = $walletStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$wallet) {
            // Create wallet if it doesn't exist
            $createStmt = $conn->prepare("
                INSERT INTO mobile_user_wallet (client_id, balance, currency)
                VALUES (?, 0.00, 'AED')
            ");
            $createStmt->execute([$clientId]);
            $walletId = $conn->lastInsertId();
            
            // Fetch the newly created wallet
            $walletStmt->execute([$clientId]);
            $wallet = $walletStmt->fetch(PDO::FETCH_ASSOC);
        }
        
        // Ensure wallet exists
        if (!$wallet) {
            echo json_encode(['success' => false, 'error' => 'Failed to create wallet']);
            exit;
        }
        
        // Update balance
        $newBalance = $wallet['balance'] + $amount;
        $updateStmt = $conn->prepare("UPDATE mobile_user_wallet SET balance = ? WHERE id = ?");
        $updateStmt->execute([$newBalance, $wallet['id']]);
        
        // Create transaction
        $transactionStmt = $conn->prepare("
            INSERT INTO mobile_wallet_transactions (
                wallet_id, client_id, transaction_type, amount,
                balance_before, balance_after, transaction_category,
                description, reference_type, created_by
            ) VALUES (?, ?, 'credit', ?, ?, ?, ?, ?, 'admin_adjustment', ?)
        ");
        $transactionStmt->execute([
            $wallet['id'],
            $clientId,
            $amount,
            $wallet['balance'],
            $newBalance,
            $category,
            $description ?: "Balance added by admin",
            $currentUser['id'] ?? null,
        ]);
        
        error_log("✅ Admin added AED {$amount} to client {$clientId} wallet (New balance: {$newBalance})");
        
        echo json_encode([
            'success' => true,
            'message' => 'Balance added successfully',
            'new_balance' => (float)$newBalance,
        ]);
    }
    
    else {
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }

} catch (PDOException $e) {
    error_log("Wallet AJAX Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error occurred']);
} catch (Exception $e) {
    error_log("Wallet AJAX Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>

