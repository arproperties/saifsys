<?php
/**
 * Promotional Banners API
 * GET /banners.php - Get active banners for home page
 */

require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        errorResponse('Method not allowed', 405);
    }

    // Get active banners within date range
    $query = "
        SELECT 
            id,
            title,
            description,
            image_url,
            link_type,
            link_url,
            service_id,
            sort_order
        FROM promotional_banners
        WHERE is_active = 1
            AND (start_date IS NULL OR start_date <= NOW())
            AND (end_date IS NULL OR end_date >= NOW())
        ORDER BY sort_order ASC
    ";
    
    $stmt = $conn->query($query);
    $banners = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Normalize media URLs
    foreach ($banners as &$banner) {
        $banner['image_url'] = buildMediaUrl($banner['image_url'] ?? null);
        if (isset($banner['link_url']) && !empty($banner['link_url'])) {
            $banner['link_url'] = buildMediaUrl($banner['link_url']) ?? $banner['link_url'];
        }
    }
    unset($banner);

    successResponse([
        'banners' => $banners,
        'total' => count($banners)
    ]);

} catch (PDOException $e) {
    error_log("Banners API Error: " . $e->getMessage());
    errorResponse('Database error occurred', 500);
} catch (Exception $e) {
    error_log("Banners API Error: " . $e->getMessage());
    errorResponse('An error occurred', 500);
}

