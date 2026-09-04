<?php
/**
 * OTP Login API Endpoint
 * POST /api/mobile/auth_login.php - Send OTP to phone number
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/sms_service.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        errorResponse('Method not allowed', 405);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['phone'])) {
        errorResponse('Phone number is required');
    }

    $phone = sanitizeInput($input['phone']);
    
    // Normalize phone format - remove spaces, dashes, and ensure consistent format
    $phone = preg_replace('/[\s\-\(\)]/', '', $phone);
    
    // Remove country code +971 if present and add back consistently
    $phone = preg_replace('/^\+?971/', '', $phone);
    
    // Remove leading zero if present
    $phone = ltrim($phone, '0');
    
    // Now we have just the digits - validate length
    if (strlen($phone) < 9) {
        errorResponse('Invalid phone number');
    }
    
    // Store as just the digits without country code for consistency
    $phone = '0' . $phone;

    // Generate 6-digit OTP
    $otp = sprintf('%06d', mt_rand(0, 999999));
    $expiresAt = date('Y-m-d H:i:s', strtotime('+10 minutes'));

    // Check if client exists (management system integration)
    $checkStmt = $conn->prepare("SELECT id, client_name FROM client WHERE mobile_num = ?");
    $checkStmt->execute([$phone]);
    $client = $checkStmt->fetch(PDO::FETCH_ASSOC);

    // Always update OTP in customers table (for OTP verification)
    $custStmt = $conn->prepare("SELECT id FROM customers WHERE phone = ?");
    $custStmt->execute([$phone]);
    $cust = $custStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($cust) {
        // Update existing customer with new OTP
        $updateStmt = $conn->prepare("
            UPDATE customers 
            SET otp_code = ?, otp_expires_at = ?
            WHERE phone = ?
        ");
        $result = $updateStmt->execute([$otp, $expiresAt, $phone]);
        error_log("OTP updated for existing customer. Result: " . ($result ? 'success' : 'failed'));
    } else {
        // Create new customer record for OTP
        $clientName = $client ? $client['client_name'] : 'Mobile User - ' . $phone;
        $insertStmt = $conn->prepare("
            INSERT INTO customers (name, phone, otp_code, otp_expires_at)
            VALUES (?, ?, ?, ?)
        ");
        $result = $insertStmt->execute([$clientName, $phone, $otp, $expiresAt]);
        error_log("OTP customer created. Result: " . ($result ? 'success' : 'failed'));
    }
    
    // If client doesn't exist in management system, create it
    if (!$client) {
        $insertClientStmt = $conn->prepare("
            INSERT INTO client (client_name, mobile_num, cell_num, email, rate, payment, terms, is_active, address, currency, client_status)
            VALUES (?, ?, '', '', 0.00, 'D', 'cash', 1, '', 'AED', 'active')
        ");
        $insertClientStmt->execute(['Mobile User - ' . $phone, $phone]);
        error_log("New client created in management system for: " . $phone);
    }

    // Send OTP via SMS
    $smsSent = sendOTPSMS($phone, $otp, 'HeroSys');
    
    if (!$smsSent && SMS_ENABLED) {
        errorResponse('Failed to send OTP. Please try again.', 500);
    }

    successResponse([
        'phone' => $phone,
        'otp_sent' => true,
        'expires_in_minutes' => 10,
        // Remove this in production - only for development
        'dev_otp' => $otp
    ], 'OTP sent successfully');

} catch (PDOException $e) {
    error_log("Auth Login API Error: " . $e->getMessage());
    errorResponse('Database error occurred', 500);
} catch (Exception $e) {
    error_log("Auth Login API Error: " . $e->getMessage());
    errorResponse('An error occurred', 500);
}

