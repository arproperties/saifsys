<?php
// operation/ajax_client_sites.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';

header('Content-Type: application/json');

$client_id = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
$action = $_POST['action'] ?? $_GET['action'] ?? 'list';

if ($client_id <= 0 && !in_array($action, ['create'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid client ID']);
    exit;
}

try {
    switch ($action) {
        case 'list':
            $result = listClientSites($conn, $client_id);
            break;
            
        case 'create':
        case 'add':
            $result = createSite($conn, $_POST);
            break;
            
        case 'update':
            $result = updateSite($conn, $_POST);
            break;
            
        case 'delete':
            $result = deleteSite($conn, (int)($_POST['site_id'] ?? 0));
            break;
            
        case 'set_primary':
            $result = setPrimarySite($conn, (int)($_POST['site_id'] ?? 0), (int)($_POST['client_id'] ?? 0));
            break;
            
        case 'get':
            $result = getSite($conn, (int)($_GET['site_id'] ?? 0));
            break;
            
        default:
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
            exit;
    }
    
    echo json_encode($result);
    
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => 'Action failed: ' . $e->getMessage()]);
}

function listClientSites(PDO $conn, int $client_id): array {
    $sql = "
        SELECT 
            cs.*,
            0 as total_orders,
            NULL as last_service_date
        FROM client_sites cs
        WHERE cs.client_id = ?
        ORDER BY cs.is_primary DESC, cs.site_name ASC
    ";
    
    $st = $conn->prepare($sql);
    $st->execute([$client_id]);
    $sites = $st->fetchAll(PDO::FETCH_ASSOC);
    
    // Format dates
    foreach ($sites as &$site) {
        if ($site['last_service_date']) {
            $site['formatted_last_service'] = date('M j, Y', strtotime($site['last_service_date']));
        }
    }
    
    return [
        'success' => true,
        'sites' => $sites
    ];
}

function createSite(PDO $conn, array $post): array {
    $client_id = (int)($post['client_id'] ?? 0);
    $site_name = trim($post['site_name'] ?? '');
    $address = trim($post['address'] ?? '');
    $contact_person = trim($post['contact_person'] ?? '');
    $contact_phone = trim($post['contact_phone'] ?? '');
    $notes = trim($post['notes'] ?? '');
    $is_primary = isset($post['is_primary']) ? 1 : 0;
    
    if ($client_id <= 0) {
        return ['success' => false, 'error' => 'Invalid client ID'];
    }
    
    if (empty($site_name)) {
        return ['success' => false, 'error' => 'Site name is required'];
    }
    
    // If setting as primary, unset other primary sites for this client
    if ($is_primary) {
        $st = $conn->prepare("UPDATE client_sites SET is_primary = 0 WHERE client_id = ?");
        $st->execute([$client_id]);
    }
    
    $sql = "
        INSERT INTO client_sites 
        (client_id, site_name, address, contact_person, contact_phone, notes, is_primary)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ";
    
    $st = $conn->prepare($sql);
    $ok = $st->execute([
        $client_id,
        $site_name,
        $address,
        $contact_person,
        $contact_phone,
        $notes,
        $is_primary
    ]);
    
    if (!$ok) {
        return ['success' => false, 'error' => 'Failed to create site'];
    }
    
    $site_id = (int)$conn->lastInsertId();
    
    return [
        'success' => true,
        'message' => 'Site created successfully',
        'site_id' => $site_id
    ];
}

function updateSite(PDO $conn, array $post): array {
    $site_id = (int)($post['site_id'] ?? 0);
    $site_name = trim($post['site_name'] ?? '');
    $address = trim($post['address'] ?? '');
    $contact_person = trim($post['contact_person'] ?? '');
    $contact_phone = trim($post['contact_phone'] ?? '');
    $notes = trim($post['notes'] ?? '');
    
    if ($site_id <= 0) {
        return ['success' => false, 'error' => 'Invalid site ID'];
    }
    
    if (empty($site_name)) {
        return ['success' => false, 'error' => 'Site name is required'];
    }
    
    $sql = "
        UPDATE client_sites 
        SET site_name = ?, address = ?, contact_person = ?, contact_phone = ?, notes = ?
        WHERE id = ?
    ";
    
    $st = $conn->prepare($sql);
    $ok = $st->execute([
        $site_name,
        $address,
        $contact_person,
        $contact_phone,
        $notes,
        $site_id
    ]);
    
    if (!$ok) {
        return ['success' => false, 'error' => 'Failed to update site'];
    }
    
    return [
        'success' => true,
        'message' => 'Site updated successfully'
    ];
}

function deleteSite(PDO $conn, int $site_id): array {
    if ($site_id <= 0) {
        return ['success' => false, 'error' => 'Invalid site ID'];
    }
    
    // Check if site has associated orders
    $st = $conn->prepare("SELECT COUNT(*) FROM make_order WHERE site_id = ?");
    $st->execute([$site_id]);
    $order_count = (int)$st->fetchColumn();
    
    if ($order_count > 0) {
        return [
            'success' => false,
            'error' => "Cannot delete site with {$order_count} associated orders"
        ];
    }
    
    $st = $conn->prepare("DELETE FROM client_sites WHERE id = ?");
    $ok = $st->execute([$site_id]);
    
    if (!$ok) {
        return ['success' => false, 'error' => 'Failed to delete site'];
    }
    
    return [
        'success' => true,
        'message' => 'Site deleted successfully'
    ];
}

function setPrimarySite(PDO $conn, int $site_id, int $client_id): array {
    if ($site_id <= 0 || $client_id <= 0) {
        return ['success' => false, 'error' => 'Invalid parameters'];
    }
    
    // Unset all primary sites for this client
    $st = $conn->prepare("UPDATE client_sites SET is_primary = 0 WHERE client_id = ?");
    $st->execute([$client_id]);
    
    // Set the selected site as primary
    $st = $conn->prepare("UPDATE client_sites SET is_primary = 1 WHERE id = ? AND client_id = ?");
    $ok = $st->execute([$site_id, $client_id]);
    
    if (!$ok) {
        return ['success' => false, 'error' => 'Failed to set primary site'];
    }
    
    return [
        'success' => true,
        'message' => 'Primary site updated successfully'
    ];
}

function getSite(PDO $conn, int $site_id): array {
    if ($site_id <= 0) {
        return ['success' => false, 'error' => 'Invalid site ID'];
    }
    
    $sql = "
        SELECT * FROM client_sites 
        WHERE id = ?
    ";
    
    $st = $conn->prepare($sql);
    $st->execute([$site_id]);
    $site = $st->fetch(PDO::FETCH_ASSOC);
    
    if (!$site) {
        return ['success' => false, 'error' => 'Site not found'];
    }
    
    return [
        'success' => true,
        'site' => $site
    ];
}
