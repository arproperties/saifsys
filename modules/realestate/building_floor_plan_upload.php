<?php
/**
 * Real Estate Module - Upload Floor Plan
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';

require_login();
require_module_access($conn, MODULE_REALESTATE);

$currentCompanyId = current_company_id($conn) ?: 1;
$userId = current_user_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    
    $buildingId = (int)$_POST['building_id'];
    $planName = trim($_POST['plan_name'] ?? '');
    $floorNumber = !empty($_POST['floor_number']) ? (int)$_POST['floor_number'] : null;
    $description = trim($_POST['description'] ?? '');
    $isPrimary = isset($_POST['is_primary']) ? 1 : 0;
    
    // Verify building belongs to company
    $stmt = $conn->prepare("SELECT id FROM re_buildings WHERE id = ? AND company_id = ?");
    $stmt->execute([$buildingId, $currentCompanyId]);
    if (!$stmt->fetch()) {
        header('Location: buildings.php');
        exit;
    }
    
    if ($planName && isset($_FILES['floor_plan_file']) && $_FILES['floor_plan_file']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['floor_plan_file'];
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];
        
        if (!in_array($file['type'], $allowedTypes)) {
            $_SESSION['error'] = 'Invalid file type. Only images and PDF files are allowed.';
            header('Location: building_view.php?id=' . $buildingId);
            exit;
        }
        
        // Create uploads directory if it doesn't exist
        $uploadDir = __DIR__ . '/../../uploads/building_floor_plans/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        // Generate unique filename
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = 'building_' . $buildingId . '_' . time() . '_' . uniqid() . '.' . $extension;
        $filePath = $uploadDir . $filename;
        $relativePath = 'uploads/building_floor_plans/' . $filename;
        
        if (move_uploaded_file($file['tmp_name'], $filePath)) {
            // If this is set as primary, unset other primary plans for this floor
            if ($isPrimary) {
                $stmt = $conn->prepare("
                    UPDATE re_building_floor_plans 
                    SET is_primary = 0 
                    WHERE building_id = ? AND floor_number = ?
                ");
                $stmt->execute([$buildingId, $floorNumber]);
            }
            
            // Insert floor plan record
            $stmt = $conn->prepare("
                INSERT INTO re_building_floor_plans 
                (building_id, floor_number, plan_name, file_path, file_size, file_type, description, is_primary, uploaded_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $buildingId, 
                $floorNumber, 
                $planName, 
                $relativePath, 
                $file['size'], 
                $file['type'], 
                $description ?: null, 
                $isPrimary, 
                $userId
            ]);
            
            $_SESSION['success'] = 'Floor plan uploaded successfully';
        } else {
            $_SESSION['error'] = 'Failed to upload file. Please try again.';
        }
    } else {
        $_SESSION['error'] = 'Please provide a plan name and select a file.';
    }
    
    header('Location: building_view.php?id=' . $buildingId);
    exit;
}

header('Location: buildings.php');
exit;

