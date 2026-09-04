<?php
/**
 * Profile API Endpoint
 * GET /api/mobile/profile.php - Get user profile
 * PUT /api/mobile/profile.php - Update user profile
 */

require_once __DIR__ . '/config.php';

try {
    $method = $_SERVER['REQUEST_METHOD'];

    // GET - Retrieve user profile (requires authentication)
    if ($method === 'GET') {
        $auth = requireAuth();
        $clientId = $auth['client_id'] ?? null;

        if (!$clientId) {
            errorResponse('Invalid authentication', 401);
        }

        // Check if mobile_user exists for this client
        $userStmt = $conn->prepare("
            SELECT 
                mu.*,
                c.client_name as name,
                c.mobile_num as phone,
                c.email,
                c.address
            FROM mobile_user mu
            INNER JOIN client c ON mu.client_id = c.id
            WHERE mu.client_id = ?
        ");
        $userStmt->execute([$clientId]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);

        // If mobile_user doesn't exist, create it from client data
        if (!$user) {
            $clientStmt = $conn->prepare("SELECT * FROM client WHERE id = ?");
            $clientStmt->execute([$clientId]);
            $client = $clientStmt->fetch(PDO::FETCH_ASSOC);

            if (!$client) {
                errorResponse('Client not found', 404);
            }

            // Create mobile_user record
            $createUserStmt = $conn->prepare("
                INSERT INTO mobile_user (
                    client_id, phone, email, is_active
                ) VALUES (?, ?, ?, 1)
            ");
            $createUserStmt->execute([
                $clientId,
                $client['mobile_num'],
                $client['email']
            ]);

            $mobileUserId = $conn->lastInsertId();

            // Fetch the newly created user
            $userStmt->execute([$clientId]);
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);
        }

        successResponse($user, 'Profile retrieved successfully', 'profile');
    }

    // PUT - Update user profile
    elseif ($method === 'PUT') {
        error_log("📝 Profile UPDATE request started");
        $auth = requireAuth();
        $clientId = $auth['client_id'] ?? null;

        if (!$clientId) {
            error_log("❌ Invalid authentication - no client_id");
            errorResponse('Invalid authentication', 401);
        }

        $input = json_decode(file_get_contents('php://input'), true);
        error_log("📝 Update data: " . json_encode($input));
        
        if (!$input) {
            error_log("❌ Invalid JSON input");
            errorResponse('Invalid JSON input');
        }

        // Get current user
        $userStmt = $conn->prepare("SELECT id FROM mobile_user WHERE client_id = ?");
        $userStmt->execute([$clientId]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            errorResponse('User profile not found', 404);
        }

        // Build update query for mobile_user
        $updateFields = [];
        $params = [];

        if (isset($input['email'])) {
            $updateFields[] = "email = ?";
            $params[] = sanitizeInput($input['email']);
        }
        if (isset($input['date_of_birth'])) {
            $updateFields[] = "date_of_birth = ?";
            $params[] = sanitizeInput($input['date_of_birth']);
        }
        if (isset($input['gender'])) {
            $updateFields[] = "gender = ?";
            $params[] = sanitizeInput($input['gender']);
        }
        if (isset($input['language_preference'])) {
            $updateFields[] = "language_preference = ?";
            $params[] = sanitizeInput($input['language_preference']);
        }
        if (isset($input['notification_enabled'])) {
            $updateFields[] = "notification_enabled = ?";
            $params[] = (int)$input['notification_enabled'];
        }
        if (isset($input['email_notification'])) {
            $updateFields[] = "email_notification = ?";
            $params[] = (int)$input['email_notification'];
        }
        if (isset($input['sms_notification'])) {
            $updateFields[] = "sms_notification = ?";
            $params[] = (int)$input['sms_notification'];
        }
        if (isset($input['push_notification'])) {
            $updateFields[] = "push_notification = ?";
            $params[] = (int)$input['push_notification'];
        }
        if (isset($input['device_token'])) {
            $updateFields[] = "device_token = ?";
            $params[] = sanitizeInput($input['device_token']);
        }

        if (!empty($updateFields)) {
            $updateFields[] = "updated_at = NOW()";
            $params[] = $user['id'];

            $sql = "UPDATE mobile_user SET " . implode(', ', $updateFields) . " WHERE id = ?";
            error_log("📝 Executing mobile_user update: " . $sql);
            $updateStmt = $conn->prepare($sql);
            $updateStmt->execute($params);
            error_log("✅ mobile_user updated successfully");
        }

        // Also update client table if name/email/address changed
        $clientUpdateFields = [];
        $clientParams = [];

        if (isset($input['name'])) {
            $clientUpdateFields[] = "client_name = ?";
            $clientParams[] = sanitizeInput($input['name']);
        }
        if (isset($input['email'])) {
            $clientUpdateFields[] = "email = ?";
            $clientParams[] = sanitizeInput($input['email']);
        }
        if (isset($input['address'])) {
            $clientUpdateFields[] = "address = ?";
            $clientParams[] = sanitizeInput($input['address']);
        }

        if (!empty($clientUpdateFields)) {
            $clientParams[] = $clientId;

            $clientSql = "UPDATE client SET " . implode(', ', $clientUpdateFields) . " WHERE id = ?";
            error_log("📝 Executing client update: " . $clientSql);
            $clientStmt = $conn->prepare($clientSql);
            $clientStmt->execute($clientParams);
            error_log("✅ client table updated successfully");
        }

        // Update last active timestamp
        $conn->prepare("UPDATE mobile_user SET last_active_at = NOW() WHERE client_id = ?")
            ->execute([$clientId]);

        error_log("✅ Profile update completed successfully for client ID: $clientId");
        successResponse(['updated' => true], 'Profile updated successfully');
    }

    else {
        errorResponse('Method not allowed', 405);
    }

} catch (PDOException $e) {
    error_log("Profile API Error: " . $e->getMessage());
    errorResponse('Database error occurred', 500);
} catch (Exception $e) {
    error_log("Profile API Error: " . $e->getMessage());
    errorResponse('An error occurred: ' . $e->getMessage(), 500);
}

