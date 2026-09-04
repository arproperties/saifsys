<?php
/**
 * Addresses API Endpoint
 * GET /api/mobile/addresses.php - Get all user addresses
 * POST /api/mobile/addresses.php - Add new address
 * PUT /api/mobile/addresses.php?id={id} - Update address
 * DELETE /api/mobile/addresses.php?id={id} - Delete address
 */

require_once __DIR__ . '/config.php';

try {
    $method = $_SERVER['REQUEST_METHOD'];

    // GET - Retrieve all user addresses
    if ($method === 'GET') {
        $auth = requireAuth();
        $clientId = $auth['client_id'] ?? null;

        if (!$clientId) {
            errorResponse('Invalid authentication', 401);
        }

        // Get mobile_user ID
        $userStmt = $conn->prepare("SELECT id FROM mobile_user WHERE client_id = ?");
        $userStmt->execute([$clientId]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            errorResponse('User not found', 404);
        }

        // Get all addresses
        $stmt = $conn->prepare("
            SELECT * FROM mobile_user_addresses 
            WHERE mobile_user_id = ? 
            ORDER BY is_default DESC, created_at DESC
        ");
        $stmt->execute([$user['id']]);
        $addresses = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Format addresses
        $formattedAddresses = array_map(function($addr) {
            return [
                'id' => (int)$addr['id'],
                'address_type' => $addr['address_type'],
                'label' => $addr['label'],
                'full_address' => $addr['full_address'],
                'building_name' => $addr['building_name'],
                'floor_number' => $addr['floor_number'],
                'apartment_number' => $addr['apartment_number'],
                'street' => $addr['street'],
                'area' => $addr['area'],
                'city' => $addr['city'],
                'landmark' => $addr['landmark'],
                'latitude' => $addr['latitude'] ? (float)$addr['latitude'] : null,
                'longitude' => $addr['longitude'] ? (float)$addr['longitude'] : null,
                'is_default' => (bool)$addr['is_default'],
                'created_at' => $addr['created_at'],
                'updated_at' => $addr['updated_at'],
            ];
        }, $addresses);

        successResponse($formattedAddresses, 'Addresses retrieved successfully', 'addresses');
    }

    // POST - Add new address
    elseif ($method === 'POST') {
        $auth = requireAuth();
        $clientId = $auth['client_id'] ?? null;

        if (!$clientId) {
            errorResponse('Invalid authentication', 401);
        }

        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input) {
            errorResponse('Invalid JSON input');
        }

        // Validate required fields
        $required = ['label', 'full_address', 'city'];
        validateRequired($input, $required);

        // Get mobile_user ID
        $userStmt = $conn->prepare("SELECT id FROM mobile_user WHERE client_id = ?");
        $userStmt->execute([$clientId]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            errorResponse('User not found', 404);
        }

        // If this is set as default, unset other defaults
        if (isset($input['is_default']) && $input['is_default']) {
            $conn->prepare("UPDATE mobile_user_addresses SET is_default = 0 WHERE mobile_user_id = ?")
                ->execute([$user['id']]);
        }

        // Insert new address
        $stmt = $conn->prepare("
            INSERT INTO mobile_user_addresses (
                mobile_user_id, address_type, label, full_address,
                building_name, floor_number, apartment_number,
                street, area, city, landmark,
                latitude, longitude, is_default
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $user['id'],
            sanitizeInput($input['address_type'] ?? 'home'),
            sanitizeInput($input['label']),
            sanitizeInput($input['full_address']),
            sanitizeInput($input['building_name'] ?? ''),
            sanitizeInput($input['floor_number'] ?? ''),
            sanitizeInput($input['apartment_number'] ?? ''),
            sanitizeInput($input['street'] ?? ''),
            sanitizeInput($input['area'] ?? ''),
            sanitizeInput($input['city']),
            sanitizeInput($input['landmark'] ?? ''),
            $input['latitude'] ?? null,
            $input['longitude'] ?? null,
            isset($input['is_default']) && $input['is_default'] ? 1 : 0
        ]);

        $addressId = $conn->lastInsertId();

        error_log("✅ New address added for client ID: $clientId, Address ID: $addressId");

        successResponse(['id' => $addressId], 'Address added successfully');
    }

    // PUT - Update address
    elseif ($method === 'PUT') {
        $auth = requireAuth();
        $clientId = $auth['client_id'] ?? null;

        if (!$clientId) {
            errorResponse('Invalid authentication', 401);
        }

        $addressId = $_GET['id'] ?? null;
        if (!$addressId) {
            errorResponse('Address ID is required');
        }

        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input) {
            errorResponse('Invalid JSON input');
        }

        // Get mobile_user ID
        $userStmt = $conn->prepare("SELECT id FROM mobile_user WHERE client_id = ?");
        $userStmt->execute([$clientId]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            errorResponse('User not found', 404);
        }

        // Check if address belongs to user
        $checkStmt = $conn->prepare("SELECT id FROM mobile_user_addresses WHERE id = ? AND mobile_user_id = ?");
        $checkStmt->execute([$addressId, $user['id']]);
        if (!$checkStmt->fetch()) {
            errorResponse('Address not found or unauthorized', 404);
        }

        // If this is set as default, unset other defaults
        if (isset($input['is_default']) && $input['is_default']) {
            $conn->prepare("UPDATE mobile_user_addresses SET is_default = 0 WHERE mobile_user_id = ? AND id != ?")
                ->execute([$user['id'], $addressId]);
        }

        // Build update query
        $updateFields = [];
        $params = [];

        $fields = ['address_type', 'label', 'full_address', 'building_name', 'floor_number', 
                   'apartment_number', 'street', 'area', 'city', 'landmark', 'latitude', 'longitude'];
        foreach ($fields as $field) {
            if (isset($input[$field])) {
                $updateFields[] = "$field = ?";
                $params[] = in_array($field, ['latitude', 'longitude']) ? $input[$field] : sanitizeInput($input[$field]);
            }
        }

        if (isset($input['is_default'])) {
            $updateFields[] = "is_default = ?";
            $params[] = $input['is_default'] ? 1 : 0;
        }

        if (!empty($updateFields)) {
            $updateFields[] = "updated_at = NOW()";
            $params[] = $addressId;

            $sql = "UPDATE mobile_user_addresses SET " . implode(', ', $updateFields) . " WHERE id = ?";
            $updateStmt = $conn->prepare($sql);
            $updateStmt->execute($params);

            error_log("✅ Address updated - Address ID: $addressId");
        }

        successResponse(['updated' => true], 'Address updated successfully');
    }

    // DELETE - Delete address
    elseif ($method === 'DELETE') {
        $auth = requireAuth();
        $clientId = $auth['client_id'] ?? null;

        if (!$clientId) {
            errorResponse('Invalid authentication', 401);
        }

        $addressId = $_GET['id'] ?? null;
        if (!$addressId) {
            errorResponse('Address ID is required');
        }

        // Get mobile_user ID
        $userStmt = $conn->prepare("SELECT id FROM mobile_user WHERE client_id = ?");
        $userStmt->execute([$clientId]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            errorResponse('User not found', 404);
        }

        // Delete address (with ownership check)
        $stmt = $conn->prepare("DELETE FROM mobile_user_addresses WHERE id = ? AND mobile_user_id = ?");
        $stmt->execute([$addressId, $user['id']]);

        if ($stmt->rowCount() === 0) {
            errorResponse('Address not found or unauthorized', 404);
        }

        error_log("✅ Address deleted - Address ID: $addressId");

        successResponse(['deleted' => true], 'Address deleted successfully');
    }

    else {
        errorResponse('Method not allowed', 405);
    }

} catch (PDOException $e) {
    error_log("Addresses API Error: " . $e->getMessage());
    errorResponse('Database error occurred', 500);
} catch (Exception $e) {
    error_log("Addresses API Error: " . $e->getMessage());
    errorResponse($e->getMessage(), 400);
}
?>
