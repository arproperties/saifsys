<?php
/**
 * Clear Dashboard Cache
 * This script clears all cached data that might be preventing the Accounting menu from showing
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';

require_login();

try {
    // Clear all dashboard cache entries
    $stmt = $conn->prepare("DELETE FROM dashboard_cache");
    $stmt->execute();
    $deleted = $stmt->rowCount();
    
    // Also clear expired cache
    $stmt2 = $conn->prepare("DELETE FROM dashboard_cache WHERE expires_at < NOW()");
    $stmt2->execute();
    $expired = $stmt2->rowCount();
    
    echo "<!DOCTYPE html><html><head><title>Cache Cleared</title><style>body{font-family:Arial;padding:20px;background:#f5f5f5;} .container{background:white;padding:30px;border-radius:8px;max-width:600px;margin:50px auto;box-shadow:0 2px 10px rgba(0,0,0,0.1);} h1{color:#28a745;} .success{color:#28a745;font-size:18px;margin:20px 0;} a{color:#007bff;text-decoration:none;padding:10px 20px;background:#007bff;color:white;border-radius:4px;display:inline-block;margin-top:20px;} a:hover{background:#0056b3;}</style></head><body>";
    echo "<div class='container'>";
    echo "<h1>✅ Cache Cleared Successfully!</h1>";
    echo "<div class='success'>Deleted <strong>$deleted</strong> cache entries.</div>";
    echo "<div class='success'>Expired entries cleaned: <strong>$expired</strong></div>";
    echo "<hr>";
    echo "<p><strong>Next Steps:</strong></p>";
    echo "<ol>";
    echo "<li>Hard refresh your browser (Cmd+Shift+R or Ctrl+Shift+R)</li>";
    echo "<li>Check the sidebar - Accounting menu should now be visible</li>";
    echo "<li>If still not visible, restart Apache in XAMPP</li>";
    echo "</ol>";
    echo "<a href='index.php'>← Back to Dashboard</a>";
    echo "</div></body></html>";
    
} catch (Exception $e) {
    echo "<!DOCTYPE html><html><head><title>Error</title></head><body>";
    echo "<h1>❌ Error Clearing Cache</h1>";
    echo "<p>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><a href='index.php'>← Back to Dashboard</a></p>";
    echo "</body></html>";
}
