<?php
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/db_connect.php';
require_role(['Owner','Admin','Account'], $conn);

header('Content-Type: application/json');

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'list':
            listSavedConfigs($conn);
            break;
        case 'save':
            saveConfig($conn);
            break;
        case 'load':
            loadConfig($conn);
            break;
        case 'delete':
            deleteConfig($conn);
            break;
        case 'set_default':
            setDefaultConfig($conn);
            break;
        default:
            throw new Exception('Invalid action');
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

function listSavedConfigs($conn) {
    $user_id = $_SESSION['user']['id'] ?? 1; // Fallback to user 1 if session not available
    
    $sql = "
        SELECT id, config_name, report_type, from_date, to_date, comparison_period, 
               filters, chart_preferences, is_default, created_at
        FROM saved_report_configs 
        WHERE user_id = ?
        ORDER BY is_default DESC, created_at DESC
    ";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([$user_id]);
    $configs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Decode JSON fields
    foreach ($configs as &$config) {
        $config['filters'] = $config['filters'] ? json_decode($config['filters'], true) : [];
        $config['chart_preferences'] = $config['chart_preferences'] ? json_decode($config['chart_preferences'], true) : [];
    }
    
    echo json_encode(['success' => true, 'configs' => $configs]);
}

function saveConfig($conn) {
    $user_id = $_SESSION['user']['id'] ?? 1;
    $config_name = $_POST['config_name'] ?? '';
    $report_type = $_POST['report_type'] ?? '';
    $from_date = $_POST['from_date'] ?? null;
    $to_date = $_POST['to_date'] ?? null;
    $comparison_period = $_POST['comparison_period'] ?? 'none';
    $filters = $_POST['filters'] ?? [];
    $chart_preferences = $_POST['chart_preferences'] ?? [];
    $is_default = isset($_POST['is_default']) ? (bool)$_POST['is_default'] : false;
    $config_id = $_POST['config_id'] ?? null;
    
    if (empty($config_name) || empty($report_type)) {
        throw new Exception('Config name and report type are required');
    }
    
    // If setting as default, unset other defaults for this user and report type
    if ($is_default) {
        $sql = "UPDATE saved_report_configs SET is_default = FALSE WHERE user_id = ? AND report_type = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$user_id, $report_type]);
    }
    
    if ($config_id) {
        // Update existing config
        $sql = "
            UPDATE saved_report_configs 
            SET config_name = ?, from_date = ?, to_date = ?, comparison_period = ?, 
                filters = ?, chart_preferences = ?, is_default = ?, updated_at = NOW()
            WHERE id = ? AND user_id = ?
        ";
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            $config_name, $from_date, $to_date, $comparison_period,
            json_encode($filters), json_encode($chart_preferences), $is_default,
            $config_id, $user_id
        ]);
        
        if ($stmt->rowCount() === 0) {
            throw new Exception('Config not found or access denied');
        }
        
        $result_id = $config_id;
    } else {
        // Create new config
        $sql = "
            INSERT INTO saved_report_configs 
            (user_id, config_name, report_type, from_date, to_date, comparison_period, 
             filters, chart_preferences, is_default)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ";
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            $user_id, $config_name, $report_type, $from_date, $to_date, $comparison_period,
            json_encode($filters), json_encode($chart_preferences), $is_default
        ]);
        
        $result_id = $conn->lastInsertId();
    }
    
    echo json_encode(['success' => true, 'config_id' => $result_id]);
}

function loadConfig($conn) {
    $user_id = $_SESSION['user']['id'] ?? 1;
    $config_id = $_GET['config_id'] ?? $_POST['config_id'] ?? '';
    
    if (empty($config_id)) {
        throw new Exception('Config ID is required');
    }
    
    $sql = "
        SELECT config_name, report_type, from_date, to_date, comparison_period, 
               filters, chart_preferences, is_default
        FROM saved_report_configs 
        WHERE id = ? AND user_id = ?
    ";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([$config_id, $user_id]);
    $config = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$config) {
        throw new Exception('Config not found or access denied');
    }
    
    // Decode JSON fields
    $config['filters'] = $config['filters'] ? json_decode($config['filters'], true) : [];
    $config['chart_preferences'] = $config['chart_preferences'] ? json_decode($config['chart_preferences'], true) : [];
    
    echo json_encode(['success' => true, 'config' => $config]);
}

function deleteConfig($conn) {
    $user_id = $_SESSION['user']['id'] ?? 1;
    $config_id = $_POST['config_id'] ?? '';
    
    if (empty($config_id)) {
        throw new Exception('Config ID is required');
    }
    
    $sql = "DELETE FROM saved_report_configs WHERE id = ? AND user_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$config_id, $user_id]);
    
    if ($stmt->rowCount() === 0) {
        throw new Exception('Config not found or access denied');
    }
    
    echo json_encode(['success' => true]);
}

function setDefaultConfig($conn) {
    $user_id = $_SESSION['user']['id'] ?? 1;
    $config_id = $_POST['config_id'] ?? '';
    $report_type = $_POST['report_type'] ?? '';
    
    if (empty($config_id) || empty($report_type)) {
        throw new Exception('Config ID and report type are required');
    }
    
    $conn->beginTransaction();
    
    try {
        // Unset other defaults for this user and report type
        $sql = "UPDATE saved_report_configs SET is_default = FALSE WHERE user_id = ? AND report_type = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$user_id, $report_type]);
        
        // Set this config as default
        $sql = "UPDATE saved_report_configs SET is_default = TRUE WHERE id = ? AND user_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$config_id, $user_id]);
        
        if ($stmt->rowCount() === 0) {
            throw new Exception('Config not found or access denied');
        }
        
        $conn->commit();
        echo json_encode(['success' => true]);
        
    } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
    }
}
?>
