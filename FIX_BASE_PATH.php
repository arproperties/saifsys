<?php
/**
 * Diagnostic and Fix Script for Base Path Issues
 * 
 * This script will:
 * 1. Show you what your current base path should be
 * 2. Help you fix the .htaccess and other files
 * 
 * Upload this to your Hostinger server and access it via browser:
 * https://yourdomain.com/FIX_BASE_PATH.php
 */

// Prevent direct access in production (remove this line temporarily to run)
// if (php_sapi_name() !== 'cli') {
//     die('This script should be run from command line or remove this check temporarily');
// }

?>
<!DOCTYPE html>
<html>
<head>
    <title>Base Path Diagnostic Tool</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 800px; margin: 50px auto; padding: 20px; }
        .info { background: #e3f2fd; padding: 15px; border-left: 4px solid #2196F3; margin: 10px 0; }
        .success { background: #e8f5e9; padding: 15px; border-left: 4px solid #4CAF50; margin: 10px 0; }
        .error { background: #ffebee; padding: 15px; border-left: 4px solid #f44336; margin: 10px 0; }
        .warning { background: #fff3e0; padding: 15px; border-left: 4px solid #FF9800; margin: 10px 0; }
        code { background: #f5f5f5; padding: 2px 6px; border-radius: 3px; }
        pre { background: #f5f5f5; padding: 15px; border-radius: 5px; overflow-x: auto; }
        .button { display: inline-block; padding: 10px 20px; background: #2196F3; color: white; text-decoration: none; border-radius: 5px; margin: 10px 5px 10px 0; }
        .button:hover { background: #1976D2; }
    </style>
</head>
<body>
    <h1>🔧 Base Path Diagnostic Tool</h1>
    
    <?php
    // Get server information
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    $scriptDir = dirname($scriptName);
    $scriptDir = rtrim(str_replace('\\', '/', $scriptDir), '/');
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    $httpHost = $_SERVER['HTTP_HOST'] ?? '';
    $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
    $serverName = $_SERVER['SERVER_NAME'] ?? '';
    
    // Determine base path
    $detectedBasePath = $scriptDir;
    if ($detectedBasePath === '/' || $detectedBasePath === '' || $detectedBasePath === '.') {
        $detectedBasePath = '/';
    }
    
    // Check current .htaccess
    $htaccessPath = __DIR__ . '/.htaccess';
    $htaccessContent = file_exists($htaccessPath) ? file_get_contents($htaccessPath) : '';
    $currentRewriteBase = '';
    if (preg_match('/RewriteBase\s+(.+)/', $htaccessContent, $matches)) {
        $currentRewriteBase = trim($matches[1]);
    }
    
    echo '<div class="info">';
    echo '<h2>📊 Server Information</h2>';
    echo '<pre>';
    echo "HTTP Host: " . htmlspecialchars($httpHost) . "\n";
    echo "Server Name: " . htmlspecialchars($serverName) . "\n";
    echo "Document Root: " . htmlspecialchars($documentRoot) . "\n";
    echo "Script Name: " . htmlspecialchars($scriptName) . "\n";
    echo "Script Directory: " . htmlspecialchars($scriptDir) . "\n";
    echo "Request URI: " . htmlspecialchars($requestUri) . "\n";
    echo "</pre>";
    echo '</div>';
    
    echo '<div class="success">';
    echo '<h2>✅ Detected Base Path</h2>';
    echo '<p><strong>Your base path should be:</strong></p>';
    echo '<pre><code>' . htmlspecialchars($detectedBasePath) . '</code></pre>';
    
    if ($detectedBasePath === '/') {
        echo '<p>✅ Your site is in the <strong>root directory</strong>.</p>';
        echo '<p>This means your .htaccess should have: <code>RewriteBase /</code></p>';
    } else {
        echo '<p>✅ Your site is in a <strong>subdirectory</strong>: <code>' . htmlspecialchars($detectedBasePath) . '</code></p>';
        echo '<p>This means your .htaccess should have: <code>RewriteBase ' . htmlspecialchars($detectedBasePath) . '/</code></p>';
    }
    echo '</div>';
    
    echo '<div class="' . ($currentRewriteBase === $detectedBasePath . '/' || ($detectedBasePath === '/' && $currentRewriteBase === '/')) ? 'success' : 'error' . '">';
    echo '<h2>🔍 Current .htaccess Status</h2>';
    if ($htaccessContent) {
        echo '<p><strong>Current RewriteBase:</strong> <code>' . htmlspecialchars($currentRewriteBase ?: 'Not found') . '</code></p>';
        
        if ($currentRewriteBase !== $detectedBasePath . '/' && !($detectedBasePath === '/' && $currentRewriteBase === '/')) {
            echo '<p class="error">❌ <strong>MISMATCH!</strong> Your .htaccess RewriteBase is incorrect.</p>';
            echo '<p>It should be: <code>RewriteBase ' . htmlspecialchars($detectedBasePath) . ($detectedBasePath !== '/' ? '/' : '') . '</code></p>';
        } else {
            echo '<p class="success">✅ Your .htaccess RewriteBase is correct!</p>';
        }
    } else {
        echo '<p class="error">❌ .htaccess file not found!</p>';
    }
    echo '</div>';
    
    // Show fix instructions
    if ($htaccessContent && ($currentRewriteBase !== $detectedBasePath . '/' && !($detectedBasePath === '/' && $currentRewriteBase === '/'))) {
        echo '<div class="warning">';
        echo '<h2>🔧 How to Fix</h2>';
        echo '<p><strong>Option 1: Manual Fix</strong></p>';
        echo '<ol>';
        echo '<li>Open <code>.htaccess</code> file on your server</li>';
        echo '<li>Find the line: <code>RewriteBase ' . htmlspecialchars($currentRewriteBase) . '</code></li>';
        echo '<li>Change it to: <code>RewriteBase ' . htmlspecialchars($detectedBasePath) . ($detectedBasePath !== '/' ? '/' : '') . '</code></li>';
        echo '<li>Save the file</li>';
        echo '</ol>';
        
        echo '<p><strong>Option 2: Download Fixed .htaccess</strong></p>';
        echo '<p>Click the button below to download a corrected .htaccess file:</p>';
        
        // Generate fixed .htaccess
        $fixedHtaccess = $htaccessContent;
        $newRewriteBase = $detectedBasePath . ($detectedBasePath !== '/' ? '/' : '');
        $fixedHtaccess = preg_replace('/RewriteBase\s+.+/', 'RewriteBase ' . $newRewriteBase, $fixedHtaccess);
        
        // Create download link
        $downloadFile = 'fixed_htaccess.txt';
        file_put_contents($downloadFile, $fixedHtaccess);
        echo '<a href="' . htmlspecialchars($downloadFile) . '" download=".htaccess" class="button">📥 Download Fixed .htaccess</a>';
        echo '</div>';
    }
    
    // Check PHP files
    echo '<div class="info">';
    echo '<h2>📝 PHP Files Check</h2>';
    echo '<p>Check these files for hardcoded paths:</p>';
    echo '<ul>';
    echo '<li><code>includes/module_access.php</code> - Lines 197, 234 (should auto-detect, but has fallback)</li>';
    echo '<li><code>operation.php</code> - Navigation links</li>';
    echo '<li><code>account.php</code> - Navigation links</li>';
    echo '</ul>';
    echo '<p><strong>Note:</strong> The PHP files use auto-detection, but if you see hardcoded <code>/herosysgro/</code> paths, they need to be updated.</p>';
    echo '</div>';
    
    // Final recommendations
    echo '<div class="info">';
    echo '<h2>💡 Recommendations</h2>';
    echo '<ol>';
    echo '<li><strong>Fix .htaccess first</strong> - This is the most critical file</li>';
    echo '<li><strong>Test your site</strong> - Visit your domain and check if pages load</li>';
    echo '<li><strong>Check navigation links</strong> - Make sure all menu links work</li>';
    echo '<li><strong>If still broken</strong> - Check PHP files for hardcoded paths</li>';
    echo '</ol>';
    echo '</div>';
    
    echo '<div class="warning">';
    echo '<h2>⚠️ Security Note</h2>';
    echo '<p><strong>Delete this file after fixing the issue!</strong></p>';
    echo '<p>This diagnostic tool exposes server information and should not be left on a production server.</p>';
    echo '</div>';
    ?>
</body>
</html>
