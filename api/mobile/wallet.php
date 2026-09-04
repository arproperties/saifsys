<?php
/**
 * Wallet API Endpoint
 * GET /api/mobile/wallet.php - Get wallet balance and transactions
 * POST /api/mobile/wallet.php - Top-up wallet or process payment
 */

require_once __DIR__ . '/config.php';

try {
    $method = $_SERVER['REQUEST_METHOD'];

    // GET - Get wallet balance and transactions
    if ($method === 'GET') {
        $auth = requireAuth();
        $clientId = $auth['client_id'] ?? null;

        if (!$clientId) {
            errorResponse('Invalid authentication', 401);
        }

        // Get or create wallet
        $walletStmt = $conn->prepare("SELECT * FROM mobile_user_wallet WHERE client_id = ?");
        $walletStmt->execute([$clientId]);
        $wallet = $walletStmt->fetch(PDO::FETCH_ASSOC);

        if (!$wallet) {
            // Create wallet for user
            $createStmt = $conn->prepare("
                INSERT INTO mobile_user_wallet (client_id, balance, currency)
                VALUES (?, 0.00, 'AED')
            ");
            $createStmt->execute([$clientId]);
            $walletId = $conn->lastInsertId();
            
            $walletStmt->execute([$clientId]);
            $wallet = $walletStmt->fetch(PDO::FETCH_ASSOC);
        }

        // Get recent transactions
        $transactionsStmt = $conn->prepare("
            SELECT * FROM mobile_wallet_transactions
            WHERE client_id = ?
            ORDER BY created_at DESC
            LIMIT 50
        ");
        $transactionsStmt->execute([$clientId]);
        $transactions = $transactionsStmt->fetchAll(PDO::FETCH_ASSOC);

        // Get top-up packages
        $packagesStmt = $conn->prepare("
            SELECT * FROM mobile_wallet_topup_packages
            WHERE is_active = 1
            ORDER BY sort_order ASC, amount ASC
        ");
        $packagesStmt->execute();
        $packages = $packagesStmt->fetchAll(PDO::FETCH_ASSOC);

        // Format response
        $response = [
            'balance' => (float)$wallet['balance'],
            'currency' => $wallet['currency'],
            'transactions' => array_map(function($t) {
                return [
                    'id' => (int)$t['id'],
                    'type' => $t['transaction_type'],
                    'amount' => (float)$t['amount'],
                    'balance_before' => (float)$t['balance_before'],
                    'balance_after' => (float)$t['balance_after'],
                    'category' => $t['transaction_category'],
                    'description' => $t['description'],
                    'reference_id' => $t['reference_id'],
                    'reference_type' => $t['reference_type'],
                    'created_at' => $t['created_at'],
                ];
            }, $transactions),
            'topup_packages' => array_map(function($p) {
                return [
                    'id' => (int)$p['id'],
                    'name' => $p['name'],
                    'amount' => (float)$p['amount'],
                    'bonus_amount' => (float)$p['bonus_amount'],
                    'total_amount' => (float)$p['amount'] + (float)$p['bonus_amount'],
                    'is_popular' => (bool)$p['is_popular'],
                ];
            }, $packages),
        ];

        successResponse($response);
    }

    // POST - Top-up wallet or process payment
    elseif ($method === 'POST') {
        $auth = requireAuth();
        $clientId = $auth['client_id'] ?? null;

        if (!$clientId) {
            errorResponse('Invalid authentication', 401);
        }

        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input || !isset($input['action'])) {
            errorResponse('Action is required');
        }

        $action = $input['action'];

        // Get or create wallet
        $walletStmt = $conn->prepare("SELECT * FROM mobile_user_wallet WHERE client_id = ?");
        $walletStmt->execute([$clientId]);
        $wallet = $walletStmt->fetch(PDO::FETCH_ASSOC);

        if (!$wallet) {
            $createStmt = $conn->prepare("
                INSERT INTO mobile_user_wallet (client_id, balance, currency)
                VALUES (?, 0.00, 'AED')
            ");
            $createStmt->execute([$clientId]);
            $walletId = $conn->lastInsertId();
            
            $walletStmt->execute([$clientId]);
            $wallet = $walletStmt->fetch(PDO::FETCH_ASSOC);
        }

        // Handle top-up
        if ($action === 'topup') {
            if (!isset($input['package_id'])) {
                errorResponse('Package ID is required');
            }

            $packageId = filter_var($input['package_id'], FILTER_VALIDATE_INT);
            $packageStmt = $conn->prepare("SELECT * FROM mobile_wallet_topup_packages WHERE id = ? AND is_active = 1");
            $packageStmt->execute([$packageId]);
            $package = $packageStmt->fetch(PDO::FETCH_ASSOC);

            if (!$package) {
                errorResponse('Invalid top-up package', 404);
            }

            $topupAmount = $package['amount'];
            $bonusAmount = $package['bonus_amount'];
            $totalAmount = $topupAmount + $bonusAmount;

            // Update wallet balance
            $newBalance = $wallet['balance'] + $totalAmount;
            $updateStmt = $conn->prepare("
                UPDATE mobile_user_wallet 
                SET balance = ? 
                WHERE id = ?
            ");
            $updateStmt->execute([$newBalance, $wallet['id']]);

            // Create transaction
            $transactionStmt = $conn->prepare("
                INSERT INTO mobile_wallet_transactions (
                    wallet_id, client_id, transaction_type, amount,
                    balance_before, balance_after, transaction_category,
                    description, reference_id, reference_type
                ) VALUES (?, ?, 'credit', ?, ?, ?, 'topup', ?, ?, 'topup')
            ");
            $transactionStmt->execute([
                $wallet['id'],
                $clientId,
                $totalAmount,
                $wallet['balance'],
                $newBalance,
                "Top-up: {$package['name']} (AED {$topupAmount}" . ($bonusAmount > 0 ? " + Bonus: AED {$bonusAmount}" : "") . ")",
                $packageId,
            ]);

            successResponse([
                'new_balance' => (float)$newBalance,
                'topup_amount' => (float)$topupAmount,
                'bonus_amount' => (float)$bonusAmount,
                'total_added' => (float)$totalAmount,
            ], 'Wallet topped up successfully');
        }

        // Handle payment (debit from wallet)
        elseif ($action === 'payment') {
            if (!isset($input['amount']) || !isset($input['booking_id'])) {
                errorResponse('Amount and booking_id are required');
            }

            $amount = filter_var($input['amount'], FILTER_VALIDATE_FLOAT);
            $bookingId = filter_var($input['booking_id'], FILTER_VALIDATE_INT);

            if ($amount <= 0) {
                errorResponse('Invalid amount');
            }

            if ($wallet['balance'] < $amount) {
                errorResponse('Insufficient wallet balance', 400);
            }

            // Update wallet balance
            $newBalance = $wallet['balance'] - $amount;
            $updateStmt = $conn->prepare("
                UPDATE mobile_user_wallet 
                SET balance = ? 
                WHERE id = ?
            ");
            $updateStmt->execute([$newBalance, $wallet['id']]);

            // Create transaction
            $transactionStmt = $conn->prepare("
                INSERT INTO mobile_wallet_transactions (
                    wallet_id, client_id, transaction_type, amount,
                    balance_before, balance_after, transaction_category,
                    description, reference_id, reference_type
                ) VALUES (?, ?, 'debit', ?, ?, ?, 'payment', ?, ?, 'booking')
            ");
            $transactionStmt->execute([
                $wallet['id'],
                $clientId,
                $amount,
                $wallet['balance'],
                $newBalance,
                "Payment for booking #{$bookingId}",
                $bookingId,
            ]);

            successResponse([
                'new_balance' => (float)$newBalance,
                'amount_paid' => (float)$amount,
            ], 'Payment processed successfully');
        }

        else {
            errorResponse('Invalid action', 400);
        }
    }

    else {
        errorResponse('Method not allowed', 405);
    }

} catch (PDOException $e) {
    error_log("Wallet API Error: " . $e->getMessage());
    errorResponse('Database error occurred', 500);
} catch (Exception $e) {
    error_log("Wallet API Error: " . $e->getMessage());
    errorResponse($e->getMessage(), 400);
}
?>

