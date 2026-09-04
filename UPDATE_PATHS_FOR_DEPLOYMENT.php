<?php
/**
 * Helper script to update hardcoded paths for deployment
 * 
 * Usage:
 * 1. Set $oldBasePath and $newBasePath below
 * 2. Run: php UPDATE_PATHS_FOR_DEPLOYMENT.php
 * 3. Review the changes
 * 4. Delete this file after deployment
 */

// ============================================================================
// CONFIGURATION - UPDATE THESE VALUES
// ============================================================================

// Current base path (localhost)
$oldBasePath = '/herosysgro';

// New base path for production
// Options:
//   - Root domain: '/'
//   - Subdirectory: '/herosysgro' (keep same)
//   - Subdomain: '/'
$newBasePath = '/'; // CHANGE THIS TO YOUR ACTUAL BASE PATH

// Files to update (relative to project root)
$filesToUpdate = [
    '.htaccess',
    'operation.php',
    'account.php',
    'includes/module_access.php',
    // Add more files if needed
];

// ============================================================================
// SCRIPT - DO NOT MODIFY BELOW
// ============================================================================

echo "========================================\n";
echo "Path Update Script for Deployment\n";
echo "========================================\n\n";

if ($oldBasePath === $newBasePath) {
    echo "⚠️  WARNING: Old and new base paths are the same!\n";
    echo "   No changes will be made.\n";
    exit(1);
}

echo "Old Base Path: $oldBasePath\n";
echo "New Base Path: $newBasePath\n\n";

$changesMade = 0;
$filesChanged = [];

foreach ($filesToUpdate as $file) {
    if (!file_exists($file)) {
        echo "⚠️  File not found: $file\n";
        continue;
    }
    
    $content = file_get_contents($file);
    $originalContent = $content;
    
    // Replace the base path
    $content = str_replace($oldBasePath, $newBasePath, $content);
    
    if ($content !== $originalContent) {
        // Count replacements
        $count = substr_count($originalContent, $oldBasePath) - substr_count($content, $oldBasePath);
        $changesMade += $count;
        
        // Backup original
        $backupFile = $file . '.backup';
        file_put_contents($backupFile, $originalContent);
        echo "✅ Backed up: $backupFile\n";
        
        // Write updated content
        file_put_contents($file, $content);
        echo "✅ Updated: $file ($count replacements)\n";
        
        $filesChanged[] = $file;
    } else {
        echo "⏭️  No changes: $file\n";
    }
}

echo "\n========================================\n";
echo "Summary\n";
echo "========================================\n";
echo "Files changed: " . count($filesChanged) . "\n";
echo "Total replacements: $changesMade\n\n";

if (count($filesChanged) > 0) {
    echo "✅ Changes completed!\n\n";
    echo "Next steps:\n";
    echo "1. Review the changes in the files listed above\n";
    echo "2. Test locally if possible\n";
    echo "3. Upload to server\n";
    echo "4. Delete .backup files after verification\n";
    echo "5. Delete this script (UPDATE_PATHS_FOR_DEPLOYMENT.php)\n";
} else {
    echo "⚠️  No changes were made.\n";
    echo "   Check that:\n";
    echo "   - Old base path is correct\n";
    echo "   - Files exist and contain the old path\n";
}

echo "\n";
