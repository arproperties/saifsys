<?php
/**
 * Helper script to fix upload directory permissions
 * Run this once to ensure upload directories have correct permissions
 */

$baseDir = __DIR__;
$directories = [
    'uploads',
    'uploads/branding',
    'uploads/inventory',
    'uploads/inventory/items',
    'uploads/temp/inventory_items',
];

echo "Fixing upload directory permissions...\n\n";

foreach ($directories as $dir) {
    $fullPath = $baseDir . '/' . $dir;
    
    // Create directory if it doesn't exist
    if (!is_dir($fullPath)) {
        if (mkdir($fullPath, 0777, true)) {
            echo "✓ Created directory: $dir\n";
        } else {
            echo "✗ Failed to create directory: $dir\n";
            continue;
        }
    } else {
        echo "✓ Directory exists: $dir\n";
    }
    
    // Set permissions
    if (chmod($fullPath, 0777)) {
        echo "✓ Set permissions to 777 for: $dir\n";
    } else {
        echo "✗ Failed to set permissions for: $dir\n";
    }
    
    // Check if writable
    if (is_writable($fullPath)) {
        echo "✓ Directory is writable: $dir\n";
    } else {
        echo "✗ Directory is NOT writable: $dir\n";
        echo "  Current permissions: " . substr(sprintf('%o', fileperms($fullPath)), -4) . "\n";
    }
    
    echo "\n";
}

echo "Done!\n";
echo "\nIf directories are still not writable, you may need to run:\n";
echo "  chmod -R 777 uploads/\n";
echo "from the command line, or manually set permissions via Finder.\n";

