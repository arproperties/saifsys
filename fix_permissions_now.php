<?php
/**
 * Quick fix script for upload directory permissions
 * Access this file in your browser: http://localhost/herosys/fix_permissions_now.php
 */

$baseDir = __DIR__;
$uploadDir = $baseDir . '/uploads';
$brandingDir = $baseDir . '/uploads/branding';

header('Content-Type: text/plain; charset=utf-8');

echo "Fixing Upload Directory Permissions\n";
echo "====================================\n\n";

// Create directories if they don't exist
if (!is_dir($uploadDir)) {
    if (@mkdir($uploadDir, 0777, true)) {
        echo "✓ Created directory: uploads\n";
    } else {
        echo "✗ Failed to create directory: uploads\n";
    }
} else {
    echo "✓ Directory exists: uploads\n";
}

if (!is_dir($brandingDir)) {
    if (@mkdir($brandingDir, 0777, true)) {
        echo "✓ Created directory: uploads/branding\n";
    } else {
        echo "✗ Failed to create directory: uploads/branding\n";
    }
} else {
    echo "✓ Directory exists: uploads/branding\n";
}

echo "\n";

// Set permissions on uploads directory
echo "Setting permissions on uploads/...\n";
$currentPerms = substr(sprintf('%o', @fileperms($uploadDir)), -4);
echo "Current permissions: $currentPerms\n";

if (@chmod($uploadDir, 0777)) {
    $newPerms = substr(sprintf('%o', @fileperms($uploadDir)), -4);
    echo "✓ Changed permissions to: $newPerms\n";
} else {
    echo "✗ Failed to change permissions\n";
}

echo "\n";

// Set permissions on branding directory
echo "Setting permissions on uploads/branding/...\n";
if (is_dir($brandingDir)) {
    $currentPerms = substr(sprintf('%o', @fileperms($brandingDir)), -4);
    echo "Current permissions: $currentPerms\n";
    
    if (@chmod($brandingDir, 0777)) {
        $newPerms = substr(sprintf('%o', @fileperms($brandingDir)), -4);
        echo "✓ Changed permissions to: $newPerms\n";
    } else {
        echo "✗ Failed to change permissions\n";
    }
    
    // Verify writable
    echo "\n";
    if (is_writable($brandingDir)) {
        echo "✓ Directory is now writable!\n";
    } else {
        echo "✗ Directory is still NOT writable\n";
        echo "\nYou may need to manually set permissions via Terminal:\n";
        echo "  chmod -R 777 " . escapeshellarg($baseDir . '/uploads') . "\n";
    }
} else {
    echo "✗ Directory does not exist: uploads/branding\n";
}

echo "\n";
echo "Done! You can now try uploading your logo again.\n";
echo "\n";
echo "Note: This file can be deleted after fixing permissions.\n";

