<?php
/**
 * OTP Verification API Endpoint
 * POST /api/mobile/auth_verify_otp.php - Verify OTP and return JWT token
 */

require_once __DIR__ . '/config.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        errorResponse('Method not allowed', 405);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['phone']) || !isset($input['otp'])) {
        errorResponse('Phone number and OTP are required');
    }

    $phone = sanitizeInput($input['phone']);
    $otp = sanitizeInput($input['otp']);
    
    // Normalize phone format to match auth_login.php
    $phone = preg_replace('/[\s\-\(\)]/', '', $phone);
    $phone = preg_replace('/^\+?971/', '', $phone);
    $phone = ltrim($phone, '0');
    $phone = '0' . $phone;

    // Validate OTP format
    if (!preg_match('/^\d{6}$/', $otp)) {
        errorResponse('Invalid OTP format');
    }

    // Check OTP in customers table (temporary storage)
    $stmt = $conn->prepare("
        SELECT id, name, phone, email, address, otp_code, otp_expires_at
        FROM customers
        WHERE phone = ?
    ");
    $stmt->execute([$phone]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$customer) {
        errorResponse('User not found');
    }

    // Verify OTP
    if ($customer['otp_code'] !== $otp) {
        errorResponse('Invalid OTP');
    }

    // Check if OTP expired
    if (strtotime($customer['otp_expires_at']) < time()) {
        errorResponse('OTP has expired. Please request a new one.');
    }

    // Mark customer as verified and clear OTP
    $updateStmt = $conn->prepare("
        UPDATE customers 
        SET is_verified = 1, otp_code = NULL, otp_expires_at = NULL
        WHERE id = ?
    ");
    $updateStmt->execute([$customer['id']]);

    // Get or create client record (management system integration)
    $clientStmt = $conn->prepare("SELECT id, client_name, mobile_num, email, address FROM client WHERE mobile_num = ?");
    $clientStmt->execute([$phone]);
    $client = $clientStmt->fetch(PDO::FETCH_ASSOC);

    if (!$client) {
        // Create client if doesn't exist
        $insertClientStmt = $conn->prepare("
            INSERT INTO client (client_name, mobile_num, cell_num, email, rate, payment, terms, is_active, address, currency, client_status)
            VALUES (?, ?, '', '', 0.00, 'D', 'cash', 1, '', 'AED', 'active')
        ");
        $insertClientStmt->execute([$customer['name'], $phone]);
        $clientId = $conn->lastInsertId();
        
        $clientStmt->execute([$phone]);
        $client = $clientStmt->fetch(PDO::FETCH_ASSOC);
    }

    // Generate JWT token with client_id
    $token = generateJWT([
        'client_id' => $client['id'],
        'phone' => $client['mobile_num'],
        'type' => 'mobile_user'
    ]);

    // Update last login time
    $conn->prepare("UPDATE mobile_user SET last_login_at = NOW() WHERE client_id = ?")->execute([$client['id']]);

    // Return client data and token (using 'customer' key for mobile app compatibility)
    // Ensure no null values for required fields
    $responseData = [
        'token' => $token,
        'customer' => [
            'id' => $client['id'],
            'name' => $client['client_name'] ?: 'User',
            'phone' => $client['mobile_num'] ?: $phone,
            'email' => $client['email'] ?: null,
            'address' => $client['address'] ?: null
        ]
    ];
    
    error_log("OTP verification successful. Returning: " . json_encode($responseData));
    successResponse($responseData, 'Login successful');

} catch (PDOException $e) {
    error_log("Auth Verify OTP API Error: " . $e->getMessage());
    errorResponse('Database error occurred', 500);
} catch (Exception $e) {
    error_log("Auth Verify OTP API Error: " . $e->getMessage());
    errorResponse('An error occurred', 500);
}

