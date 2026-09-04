<?php
/**
 * AJAX Handler for Mobile Users Management
 */

require_once __DIR__ . '/../includes/db_connect.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

try {
    switch ($action) {
        case 'toggle_status':
            $clientId = (int)($input['client_id'] ?? 0);
            $status = (int)($input['status'] ?? 0);
            
            if (!$clientId) {
                throw new Exception('Invalid client ID');
            }
            
            $stmt = $conn->prepare("UPDATE mobile_user SET is_active = ? WHERE client_id = ?");
            $stmt->execute([$status, $clientId]);
            
            echo json_encode([
                'success' => true,
                'message' => 'User status updated successfully'
            ]);
            break;
            
        case 'delete_user':
            $clientId = (int)($input['client_id'] ?? 0);
            
            if (!$clientId) {
                throw new Exception('Invalid client ID');
            }
            
            // Soft delete - just disable the user
            $stmt = $conn->prepare("UPDATE mobile_user SET is_active = 0 WHERE client_id = ?");
            $stmt->execute([$clientId]);
            
            echo json_encode([
                'success' => true,
                'message' => 'User deactivated successfully'
            ]);
            break;
            
        default:
            throw new Exception('Invalid action');
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>

