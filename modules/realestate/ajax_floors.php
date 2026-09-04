<?php
/**
 * AJAX endpoint for floor management (CRUD operations)
 */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';

header('Content-Type: application/json');

require_login();
$currentCompanyId = current_company_id($conn) ?: 1;

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// Helper function to return JSON error
function jsonError($message, $code = 400) {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

try {
    if ($action === 'list') {
        // Get floors for a building
        $buildingId = !empty($_GET['building_id']) ? (int)$_GET['building_id'] : 0;
        
        if (!$buildingId) {
            echo json_encode(['success' => false, 'error' => 'Building ID required']);
            exit;
        }
        
        // Verify building belongs to company
        $stmt = $conn->prepare("SELECT id FROM re_buildings WHERE id = ? AND company_id = ?");
        $stmt->execute([$buildingId, $currentCompanyId]);
        if (!$stmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Building not found']);
            exit;
        }
        
        $stmt = $conn->prepare("
            SELECT f.*, COUNT(u.id) as units_count
            FROM re_floors f
            LEFT JOIN re_units u ON u.floor_id = f.id
            WHERE f.building_id = ?
            GROUP BY f.id
            ORDER BY f.floor_number ASC
        ");
        $stmt->execute([$buildingId]);
        $floors = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'floors' => $floors]);
        
    } elseif ($action === 'add' || $action === 'update') {
        if (!csrf_verify(false)) {
            jsonError('CSRF token invalid or missing', 419);
        }
        
        $buildingId = !empty($_POST['building_id']) ? (int)$_POST['building_id'] : 0;
        $floorId = !empty($_POST['floor_id']) ? (int)$_POST['floor_id'] : 0;
        $floorNumber = !empty($_POST['floor_number']) ? (int)$_POST['floor_number'] : 0;
        $floorName = trim($_POST['floor_name'] ?? '');
        
        if (!$buildingId) {
            echo json_encode(['success' => false, 'error' => 'Building ID required']);
            exit;
        }
        
        if ($floorNumber <= 0) {
            echo json_encode(['success' => false, 'error' => 'Floor number must be greater than 0']);
            exit;
        }
        
        // Verify building belongs to company
        $stmt = $conn->prepare("SELECT id FROM re_buildings WHERE id = ? AND company_id = ?");
        $stmt->execute([$buildingId, $currentCompanyId]);
        if (!$stmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Building not found']);
            exit;
        }
        
        if ($action === 'add') {
            // Check if floor number already exists for this building
            $stmt = $conn->prepare("SELECT id FROM re_floors WHERE building_id = ? AND floor_number = ?");
            $stmt->execute([$buildingId, $floorNumber]);
            if ($stmt->fetch()) {
                echo json_encode(['success' => false, 'error' => 'Floor number already exists for this building']);
                exit;
            }
            
            // If inserting at a position that would conflict, shift existing floors
            $conn->beginTransaction();
            try {
                // Get max floor number
                $stmt = $conn->prepare("SELECT MAX(floor_number) as max_num FROM re_floors WHERE building_id = ?");
                $stmt->execute([$buildingId]);
                $maxResult = $stmt->fetch(PDO::FETCH_ASSOC);
                $maxFloorNumber = $maxResult['max_num'] ?: 0;
                
                // If inserting at a position that already exists or would be in the middle, shift floors
                if ($floorNumber <= $maxFloorNumber) {
                    // Shift all floors with number >= floorNumber up by 1
                    $stmt = $conn->prepare("
                        UPDATE re_floors 
                        SET floor_number = floor_number + 1 
                        WHERE building_id = ? AND floor_number >= ?
                    ");
                    $stmt->execute([$buildingId, $floorNumber]);
                }
                
                // Insert new floor
                $stmt = $conn->prepare("
                    INSERT INTO re_floors (building_id, floor_number, name, total_units)
                    VALUES (?, ?, ?, 0)
                ");
                $stmt->execute([$buildingId, $floorNumber, $floorName ?: null]);
                $floorId = (int)$conn->lastInsertId();
                
                $conn->commit();
                echo json_encode(['success' => true, 'message' => 'Floor added successfully', 'floor_id' => $floorId]);
            } catch (Exception $e) {
                $conn->rollBack();
                throw $e;
            }
            
        } elseif ($action === 'update') {
            if (!$floorId) {
                echo json_encode(['success' => false, 'error' => 'Floor ID required']);
                exit;
            }
            
            // Verify floor belongs to building
            $stmt = $conn->prepare("SELECT id FROM re_floors WHERE id = ? AND building_id = ?");
            $stmt->execute([$floorId, $buildingId]);
            if (!$stmt->fetch()) {
                echo json_encode(['success' => false, 'error' => 'Floor not found']);
                exit;
            }
            
            // Check if new floor number conflicts with another floor
            $stmt = $conn->prepare("SELECT id FROM re_floors WHERE building_id = ? AND floor_number = ? AND id != ?");
            $stmt->execute([$buildingId, $floorNumber, $floorId]);
            if ($stmt->fetch()) {
                echo json_encode(['success' => false, 'error' => 'Floor number already exists for this building']);
                exit;
            }
            
            $stmt = $conn->prepare("
                UPDATE re_floors 
                SET floor_number = ?, name = ?
                WHERE id = ? AND building_id = ?
            ");
            $stmt->execute([$floorNumber, $floorName ?: null, $floorId, $buildingId]);
            
            echo json_encode(['success' => true, 'message' => 'Floor updated successfully']);
        }
        
    } elseif ($action === 'delete') {
        if (!csrf_verify(false)) {
            jsonError('CSRF token invalid or missing', 419);
        }
        
        $floorId = !empty($_POST['floor_id']) ? (int)$_POST['floor_id'] : 0;
        
        if (!$floorId) {
            echo json_encode(['success' => false, 'error' => 'Floor ID required']);
            exit;
        }
        
        // Verify floor belongs to company's building
        $stmt = $conn->prepare("
            SELECT f.id 
            FROM re_floors f
            JOIN re_buildings b ON b.id = f.building_id
            WHERE f.id = ? AND b.company_id = ?
        ");
        $stmt->execute([$floorId, $currentCompanyId]);
        if (!$stmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Floor not found']);
            exit;
        }
        
        // Check if floor has units assigned
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM re_units WHERE floor_id = ?");
        $stmt->execute([$floorId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result['count'] > 0) {
            echo json_encode(['success' => false, 'error' => 'Cannot delete floor with assigned units. Please reassign units first.']);
            exit;
        }
        
        $stmt = $conn->prepare("DELETE FROM re_floors WHERE id = ?");
        $stmt->execute([$floorId]);
        
        echo json_encode(['success' => true, 'message' => 'Floor deleted successfully']);
        
    } elseif ($action === 'move') {
        if (!csrf_verify(false)) {
            jsonError('CSRF token invalid or missing', 419);
        }
        
        $floorId = !empty($_POST['floor_id']) ? (int)$_POST['floor_id'] : 0;
        $direction = $_POST['direction'] ?? ''; // 'up' or 'down'
        
        if (!$floorId) {
            echo json_encode(['success' => false, 'error' => 'Floor ID required']);
            exit;
        }
        
        if (!in_array($direction, ['up', 'down'])) {
            echo json_encode(['success' => false, 'error' => 'Invalid direction']);
            exit;
        }
        
        // Get current floor info
        $stmt = $conn->prepare("
            SELECT f.*, b.company_id
            FROM re_floors f
            JOIN re_buildings b ON b.id = f.building_id
            WHERE f.id = ?
        ");
        $stmt->execute([$floorId]);
        $currentFloor = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$currentFloor) {
            echo json_encode(['success' => false, 'error' => 'Floor not found']);
            exit;
        }
        
        // Verify building belongs to company
        if ($currentFloor['company_id'] != $currentCompanyId) {
            echo json_encode(['success' => false, 'error' => 'Access denied']);
            exit;
        }
        
        $buildingId = $currentFloor['building_id'];
        $currentFloorNumber = $currentFloor['floor_number'];
        
        // Find adjacent floor
        if ($direction === 'up') {
            // Find floor with next lower number
            $stmt = $conn->prepare("
                SELECT id, floor_number 
                FROM re_floors 
                WHERE building_id = ? AND floor_number < ?
                ORDER BY floor_number DESC
                LIMIT 1
            ");
            $stmt->execute([$buildingId, $currentFloorNumber]);
        } else {
            // Find floor with next higher number
            $stmt = $conn->prepare("
                SELECT id, floor_number 
                FROM re_floors 
                WHERE building_id = ? AND floor_number > ?
                ORDER BY floor_number ASC
                LIMIT 1
            ");
            $stmt->execute([$buildingId, $currentFloorNumber]);
        }
        
        $adjacentFloor = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$adjacentFloor) {
            echo json_encode(['success' => false, 'error' => 'Cannot move floor - no adjacent floor found']);
            exit;
        }
        
        // Swap floor numbers
        $conn->beginTransaction();
        try {
            // Temporarily set current floor to a negative number to avoid unique constraint violation
            $tempNumber = -$currentFloorNumber;
            $stmt = $conn->prepare("UPDATE re_floors SET floor_number = ? WHERE id = ?");
            $stmt->execute([$tempNumber, $floorId]);
            
            // Set adjacent floor to current floor's number
            $stmt = $conn->prepare("UPDATE re_floors SET floor_number = ? WHERE id = ?");
            $stmt->execute([$currentFloorNumber, $adjacentFloor['id']]);
            
            // Set current floor to adjacent floor's number
            $stmt = $conn->prepare("UPDATE re_floors SET floor_number = ? WHERE id = ?");
            $stmt->execute([$adjacentFloor['floor_number'], $floorId]);
            
            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'Floor moved successfully']);
        } catch (Exception $e) {
            $conn->rollBack();
            throw $e;
        }
        
    } elseif ($action === 'move_to_position') {
        if (!csrf_verify(false)) {
            jsonError('CSRF token invalid or missing', 419);
        }
        
        $floorId = !empty($_POST['floor_id']) ? (int)$_POST['floor_id'] : 0;
        $targetPosition = !empty($_POST['target_position']) ? (int)$_POST['target_position'] : 0;
        
        if (!$floorId) {
            echo json_encode(['success' => false, 'error' => 'Floor ID required']);
            exit;
        }
        
        if ($targetPosition <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid target position']);
            exit;
        }
        
        // Get current floor info
        $stmt = $conn->prepare("
            SELECT f.*, b.company_id
            FROM re_floors f
            JOIN re_buildings b ON b.id = f.building_id
            WHERE f.id = ?
        ");
        $stmt->execute([$floorId]);
        $currentFloor = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$currentFloor) {
            echo json_encode(['success' => false, 'error' => 'Floor not found']);
            exit;
        }
        
        // Verify building belongs to company
        if ($currentFloor['company_id'] != $currentCompanyId) {
            echo json_encode(['success' => false, 'error' => 'Access denied']);
            exit;
        }
        
        $buildingId = $currentFloor['building_id'];
        $currentFloorNumber = $currentFloor['floor_number'];
        
        if ($targetPosition == $currentFloorNumber) {
            echo json_encode(['success' => true, 'message' => 'Floor already at target position']);
            exit;
        }
        
        $conn->beginTransaction();
        try {
            // Get all floors ordered by floor_number
            $stmt = $conn->prepare("
                SELECT id, floor_number 
                FROM re_floors 
                WHERE building_id = ?
                ORDER BY floor_number ASC
            ");
            $stmt->execute([$buildingId]);
            $allFloors = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Find current floor and remove it from the list
            $currentFloorData = null;
            $otherFloors = [];
            foreach ($allFloors as $floor) {
                if ($floor['id'] == $floorId) {
                    $currentFloorData = $floor;
                } else {
                    $otherFloors[] = $floor;
                }
            }
            
            if (!$currentFloorData) {
                throw new Exception('Floor not found in building');
            }
            
            // Insert current floor at target position (0-based index)
            $targetIndex = $targetPosition - 1;
            array_splice($otherFloors, $targetIndex, 0, [$currentFloorData]);
            
            // Renumber all floors sequentially
            foreach ($otherFloors as $newPos => $floor) {
                $newFloorNumber = $newPos + 1;
                $stmt = $conn->prepare("UPDATE re_floors SET floor_number = ? WHERE id = ?");
                $stmt->execute([$newFloorNumber, $floor['id']]);
            }
            
            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'Floor moved to position ' . $targetPosition]);
        } catch (Exception $e) {
            $conn->rollBack();
            throw $e;
        }
        
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
