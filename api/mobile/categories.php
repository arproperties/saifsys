<?php
/**
 * Categories API - Mobile Booking System
 * Handles service categories with parent/child relationships
 */

require_once __DIR__ . '/config.php';

// Ensure buildMediaUrl is available
if (!function_exists('buildMediaUrl')) {
    function buildMediaUrl($path) {
        if (empty($path)) {
            return null;
        }
        if (preg_match('#^https?://#i', $path) || strpos($path, '//') === 0) {
            return $path;
        }
        return getBaseUrl() . ltrim($path, '/');
    }
}

try {
    $method = $_SERVER['REQUEST_METHOD'];
    
    if ($method === 'GET') {
        handleGet();
    } else {
        http_response_code(405);
        echo json_encode([
            'success' => false,
            'error' => 'Method not allowed'
        ]);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    $code = $e->getCode();
    $statusCode = ($code >= 100 && $code < 600) ? $code : 500;
    http_response_code($statusCode);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

function handleGet() {
    global $conn;
    
    $type = $_GET['type'] ?? 'all';
    $parentId = $_GET['parent_id'] ?? null;
    $showOnHome = isset($_GET['show_on_home']) ? (int)$_GET['show_on_home'] : null;
    
    // Build WHERE clause
    $where = ['c.is_active = 1'];
    $params = [];
    
    if ($type === 'main') {
        $where[] = 'c.parent_id IS NULL';
    } elseif ($type === 'sub') {
        $where[] = 'c.parent_id IS NOT NULL';
    }
    
    if ($parentId !== null) {
        $where[] = 'c.parent_id = ?';
        $params[] = $parentId;
    }
    
    if ($showOnHome !== null) {
        $where[] = 'c.show_on_home = ?';
        $params[] = $showOnHome;
    }
    
    $whereClause = implode(' AND ', $where);
    
    // Get categories
    $query = "
        SELECT 
            c.id,
            c.parent_id,
            c.name,
            c.name_ar,
            c.description,
            c.icon_url,
            c.image_url,
            c.sort_order,
            c.is_active,
            c.show_on_home,
            (SELECT COUNT(*) FROM service_categories WHERE parent_id = c.id AND is_active = 1) as children_count,
            (
                SELECT COUNT(*) 
                FROM services s
                WHERE s.is_active = 1 
                AND (
                    s.category_id = c.id 
                    OR s.category_id IN (
                        SELECT id FROM service_categories 
                        WHERE parent_id = c.id AND is_active = 1
                    )
                )
            ) as services_count
        FROM service_categories c
        WHERE $whereClause
        ORDER BY c.sort_order ASC, c.name ASC
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->execute($params);
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Build media URLs for all categories
    foreach ($categories as &$category) {
        $category['image_url'] = buildMediaUrl($category['image_url'] ?? null);
    }
    unset($category);
    
    // If getting main categories, include their children
    if (($type === 'main' || $showOnHome === 1) && !empty($categories)) {
        foreach ($categories as &$category) {
            $childStmt = $conn->prepare("
                SELECT 
                    c.id,
                    c.parent_id,
                    c.name,
                    c.name_ar,
                    c.description,
                    c.icon_url,
                    c.image_url,
                    c.sort_order,
                    c.is_active,
                    c.show_on_home,
                    (SELECT COUNT(*) FROM services WHERE category_id = c.id AND is_active = 1) as services_count
                FROM service_categories c
                WHERE c.parent_id = ? AND c.is_active = 1
                ORDER BY c.sort_order ASC, c.name ASC
            ");
            $childStmt->execute([$category['id']]);
            $children = $childStmt->fetchAll(PDO::FETCH_ASSOC);
            // Build media URLs for children
            foreach ($children as &$child) {
                $child['image_url'] = buildMediaUrl($child['image_url'] ?? null);
            }
            unset($child);
            $category['children'] = $children;
        }
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'categories' => $categories,
            'total' => count($categories)
        ]
    ]);
}
