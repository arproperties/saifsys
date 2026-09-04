<?php
/**
 * Cache Cleanup Cron Job
 * This script should be run every 5 minutes to clean expired cache entries
 * and warm up frequently accessed data
 */

require_once __DIR__.'/../includes/db_connect.php';
require_once __DIR__.'/../includes/caching_service.php';
require_once __DIR__.'/../includes/optimized_dashboard_service.php';

// Set time limit for long-running script
set_time_limit(300); // 5 minutes

try {
    $cache = new CachingService($conn);
    $dashboard = new OptimizedDashboardService($conn);
    
    // Clean expired cache entries
    $cleaned = $cache->cleanExpired();
    echo "Cleaned {$cleaned} expired cache entries\n";
    
    // Warm up dashboard cache
    $dashboard->warmUpDashboard();
    echo "Dashboard cache warmed up\n";
    
    // Get cache statistics
    $stats = $cache->getStats();
    echo "Cache stats: " . json_encode($stats) . "\n";
    
    echo "Cache cleanup completed successfully at " . date('Y-m-d H:i:s') . "\n";
    
} catch (Exception $e) {
    error_log("Cache cleanup error: " . $e->getMessage());
    echo "Cache cleanup failed: " . $e->getMessage() . "\n";
    exit(1);
}
?>
