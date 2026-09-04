<?php
/**
 * Database Comparison Tool
 * Compares live database structure with localhost structure
 * Generates a safe migration script for missing tables
 * 
 * Usage:
 * 1. Place live_database_structure.sql in project root
 * 2. Place localhost_database_structure.sql in project root
 * 3. Run: php migrations/GENERATE_MIGRATION_FROM_COMPARISON.php
 * 4. Output: migrations/COMPREHENSIVE_LIVE_UPDATE.sql
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "==========================================\n";
echo "Database Comparison Tool\n";
echo "==========================================\n\n";

// File paths
$liveFile = __DIR__ . '/../live_database_structure.sql';
$localhostFile = __DIR__ . '/../localhost_database_structure.sql';
$outputFile = __DIR__ . '/COMPREHENSIVE_LIVE_UPDATE.sql';

// Check if files exist
if (!file_exists($liveFile)) {
    die("❌ ERROR: live_database_structure.sql not found!\n   Place it in the project root directory.\n");
}

if (!file_exists($localhostFile)) {
    die("❌ ERROR: localhost_database_structure.sql not found!\n   Place it in the project root directory.\n");
}

echo "✅ Found live_database_structure.sql\n";
echo "✅ Found localhost_database_structure.sql\n\n";

// Read files
echo "Reading files...\n";
$liveContent = file_get_contents($liveFile);
$localhostContent = file_get_contents($localhostFile);

// Extract table names from SQL
function extractTableNames($sql) {
    $tables = [];
    
    // Match CREATE TABLE statements
    preg_match_all('/CREATE TABLE (?:IF NOT EXISTS )?`?(\w+)`?/i', $sql, $matches);
    if (!empty($matches[1])) {
        $tables = array_unique($matches[1]);
    }
    
    return $tables;
}

// Extract CREATE TABLE statements
function extractTableDefinitions($sql, $tableName) {
    $pattern = '/CREATE TABLE (?:IF NOT EXISTS )?`?' . preg_quote($tableName, '/') . '`?[^;]+;/is';
    preg_match($pattern, $sql, $matches);
    return !empty($matches[0]) ? $matches[0] : null;
}

echo "Extracting table names...\n";
$liveTables = extractTableNames($liveContent);
$localhostTables = extractTableNames($localhostContent);

echo "Live database has " . count($liveTables) . " tables\n";
echo "Localhost database has " . count($localhostTables) . " tables\n\n";

// Find missing tables
$missingTables = array_diff($localhostTables, $liveTables);

if (empty($missingTables)) {
    echo "✅ No missing tables! All tables from localhost exist in live database.\n";
    exit(0);
}

echo "Found " . count($missingTables) . " missing tables:\n";
foreach ($missingTables as $table) {
    echo "  - $table\n";
}
echo "\n";

// Generate migration script
echo "Generating migration script...\n";

$migration = <<<'HEADER'
-- ============================================================================
-- COMPREHENSIVE DATABASE UPDATE FOR LIVE SERVER (Hostinger)
-- Generated: {DATE}
-- Description: Adds all missing tables from localhost to live database
-- ============================================================================
-- 
-- IMPORTANT: This script is SAFE to run on live database
-- - Only creates NEW tables (IF NOT EXISTS)
-- - Does NOT modify or delete existing tables
-- - Does NOT modify or delete existing data
--
-- BEFORE RUNNING:
-- 1. BACKUP your live database first!
-- 2. Test on a staging environment if possible
-- 3. Run during low-traffic hours
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';

-- ============================================================================
-- MISSING TABLES
-- ============================================================================

HEADER;

$migration = str_replace('{DATE}', date('Y-m-d H:i:s'), $migration);

// Add each missing table
foreach ($missingTables as $table) {
    $definition = extractTableDefinitions($localhostContent, $table);
    
    if ($definition) {
        // Convert to IF NOT EXISTS format
        $definition = preg_replace(
            '/CREATE TABLE (?:IF NOT EXISTS )?/i',
            'CREATE TABLE IF NOT EXISTS ',
            $definition
        );
        
        $migration .= "\n-- Table: $table\n";
        $migration .= $definition . "\n\n";
    } else {
        echo "⚠️  Warning: Could not extract definition for table: $table\n";
    }
}

$migration .= <<<'FOOTER'
SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- VERIFICATION
-- ============================================================================
-- Run these queries after migration to verify:

-- Check all tables exist
-- SELECT table_name 
-- FROM information_schema.tables 
-- WHERE table_schema = DATABASE() 
-- AND table_name IN ({TABLES})
-- ORDER BY table_name;

-- ============================================================================
-- MIGRATION COMPLETE
-- ============================================================================

SELECT '✅ Comprehensive migration completed successfully!' AS status;
SELECT 'Please verify all tables were created correctly.' AS reminder;

FOOTER;

// Replace {TABLES} with actual table list
$tableList = "'" . implode("', '", $missingTables) . "'";
$migration = str_replace('{TABLES}', $tableList, $migration);

// Write migration file
file_put_contents($outputFile, $migration);

echo "\n✅ Migration script generated!\n";
echo "   File: migrations/COMPREHENSIVE_LIVE_UPDATE.sql\n";
echo "   Tables to add: " . count($missingTables) . "\n\n";

echo "Next steps:\n";
echo "1. Review the migration file: migrations/COMPREHENSIVE_LIVE_UPDATE.sql\n";
echo "2. Backup your live database\n";
echo "3. Run the migration on live server\n";
echo "4. Verify all tables were created\n\n";
