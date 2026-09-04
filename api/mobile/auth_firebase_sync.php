<?php
/**
 * Firebase Authentication Sync API
 * 
 * This endpoint syncs Firebase authenticated users with the backend client system.
 * After Firebase verifies the phone number, this creates/retrieves the client
 * record and issues a JWT token for API authentication.
 */

require_once __DIR__ . '/config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Method not allowed', 405);
}

try {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data) {
        $data = $_POST;
    }
    
    $phone = $data['phone'] ?? '';
    $firebaseUid = $data['firebase_uid'] ?? '';
    $firebasePhone = $data['firebase_phone'] ?? '';
    
    // Validate input
    if (empty($phone)) {
        errorResponse('Phone number is required');
    }
    
    if (empty($firebaseUid)) {
        errorResponse('Firebase UID is required');
    }
    
    // Normalize phone number (remove +971, keep 05XXXXXXXX format)
    $normalizedPhone = normalizePhoneNumber($phone);
    
    error_log("🔥 Firebase Sync - Phone: $phone → $normalizedPhone, Firebase UID: $firebaseUid");
    
    // Check if client exists
    $stmt = $conn->prepare("
        SELECT * FROM client 
        WHERE mobile_num = :phone OR mobile_num = :firebase_phone OR firebase_uid = :firebase_uid
        LIMIT 1
    ");
    $stmt->execute([
        'phone' => $normalizedPhone,
        'firebase_phone' => $firebasePhone,
        'firebase_uid' => $firebaseUid
    ]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($client) {
        // Update existing client with Firebase UID if not set
        $stmt = $conn->prepare("
            UPDATE client 
            SET firebase_uid = :firebase_uid
            WHERE id = :id
        ");
        $stmt->execute([
            'firebase_uid' => $firebaseUid,
            'id' => $client['id']
        ]);
        
        error_log("✅ Updated existing client ID: {$client['id']}");
    } else {
        // Create new client
        $stmt = $conn->prepare("
            INSERT INTO client (
                client_name, 
                mobile_num,
                cell_num,
                email,
                firebase_uid,
                payment,
                address
            ) VALUES (
                :name, 
                :phone,
                :phone,
                '',
                :firebase_uid,
                'cash',
                ''
            )
        ");
        
        $defaultName = "User " . substr($normalizedPhone, -4);
        
        $stmt->execute([
            'name' => $defaultName,
            'phone' => $normalizedPhone,
            'firebase_uid' => $firebaseUid
        ]);
        
        $clientId = $conn->lastInsertId();
        
        // Fetch the newly created client
        $stmt = $conn->prepare("SELECT * FROM client WHERE id = :id");
        $stmt->execute(['id' => $clientId]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);
        
        error_log("✅ Created new client ID: $clientId");
    }
    
    // Check if mobile_user record exists
    $stmt = $conn->prepare("SELECT * FROM mobile_user WHERE client_id = :client_id");
    $stmt->execute(['client_id' => $client['id']]);
    $mobileUser = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$mobileUser) {
        // Create mobile_user record
        $stmt = $conn->prepare("
            INSERT INTO mobile_user (
                client_id,
                phone,
                firebase_uid
            ) VALUES (
                :client_id,
                :phone,
                :firebase_uid
            )
        ");
        
        $stmt->execute([
            'client_id' => $client['id'],
            'phone' => $normalizedPhone,
            'firebase_uid' => $firebaseUid
        ]);
        
        error_log("✅ Created mobile_user for client ID: {$client['id']}");
    }
    
    // Generate JWT token
    $token = generateJWT([
        'client_id' => $client['id'],
        'phone' => $normalizedPhone,
        'firebase_uid' => $firebaseUid
    ]);
    
    // Prepare customer data for response
    $customerData = [
        'id' => (int)$client['id'],
        'name' => $client['client_name'] ?? "User " . substr($normalizedPhone, -4),
        'phone' => $normalizedPhone,
        'email' => $client['email'] ?? null,
        'firebase_uid' => $firebaseUid,
        'created_at' => $client['created_at'] ?? null,
    ];
    
    error_log("✅ Firebase sync successful - Client ID: {$client['id']}");
    
    // Return response in the format expected by mobile app
    jsonResponse([
        'success' => true,
        'token' => $token,
        'customer' => $customerData
    ]);
    
} catch (PDOException $e) {
    error_log("❌ Database error in Firebase sync: " . $e->getMessage());
    errorResponse('Database error: ' . $e->getMessage(), 500);
} catch (Exception $e) {
    error_log("❌ Error in Firebase sync: " . $e->getMessage());
    errorResponse('Server error: ' . $e->getMessage(), 500);
}

