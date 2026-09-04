<?php
/**
 * Enhanced Database Comparison Tool
 * Compares live database structure with localhost structure
 * Generates migration for:
 * 1. Missing tables (CREATE TABLE)
 * 2. Missing columns (ALTER TABLE ADD COLUMN)
 * 3. Missing indexes (ALTER TABLE ADD INDEX)
 * 
 * Usage:
 * 1. Place live_database_structure.sql in project root
 * 2. Place localhost_database_structure.sql in project root
 * 3. Run: php migrations/GENERATE_MIGRATION_WITH_STRUCTURE_CHANGES.php
 * 4. Output: migrations/COMPREHENSIVE_LIVE_UPDATE.sql
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "==========================================\n";
echo "Enhanced Database Comparison Tool\n";
echo "Checks for missing tables AND structure changes\n";
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

// Extract column definitions from CREATE TABLE
function extractColumns($tableDefinition) {
    $columns = [];
    
    // Extract column definitions (between parentheses)
    if (preg_match('/\(([^)]+)\)/s', $tableDefinition, $matches)) {
        $columnDefs = $matches[1];
        
        // Split by comma, but be careful with nested parentheses
        $lines = preg_split('/,\s*(?=\w+)/', $columnDefs);
        
        foreach ($lines as $line) {
            $line = trim($line);
            
            // Skip if it's a constraint (PRIMARY KEY, FOREIGN KEY, etc.)
            if (preg_match('/^\s*(PRIMARY|FOREIGN|UNIQUE|KEY|INDEX|CONSTRAINT)/i', $line)) {
                continue;
            }
            
            // Extract column name (first word)
            if (preg_match('/^`?(\w+)`?\s+/', $line, $colMatch)) {
                $colName = $colMatch[1];
                $columns[$colName] = $line;
            }
        }
    }
    
    return $columns;
}

// Extract indexes from CREATE TABLE
function extractIndexes($tableDefinition) {
    $indexes = [];
    
    // Extract KEY, INDEX, UNIQUE KEY definitions
    preg_match_all('/(?:KEY|INDEX|UNIQUE KEY)\s+`?(\w+)`?\s*\(([^)]+)\)/i', $tableDefinition, $matches, PREG_SET_ORDER);
    
    foreach ($matches as $match) {
        $indexName = $match[1];
        $indexColumns = $match[2];
        $indexes[$indexName] = [
            'name' => $indexName,
            'columns' => $indexColumns,
            'full' => $match[0]
        ];
    }
    
    return $indexes;
}

echo "Extracting table names...\n";
$liveTables = extractTableNames($liveContent);
$localhostTables = extractTableNames($localhostContent);

echo "Live database has " . count($liveTables) . " tables\n";
echo "Localhost database has " . count($localhostTables) . " tables\n\n";

// Find missing tables
$missingTables = array_diff($localhostTables, $liveTables);
$commonTables = array_intersect($liveTables, $localhostTables);

echo "Missing tables: " . count($missingTables) . "\n";
echo "Common tables (to check for structure differences): " . count($commonTables) . "\n\n";

// Generate migration script
echo "Generating migration script...\n";

$migration = <<<'HEADER'
-- ============================================================================
-- COMPREHENSIVE DATABASE UPDATE FOR LIVE SERVER (Hostinger)
-- Generated: {DATE}
-- Description: Adds missing tables AND updates existing table structures
-- ============================================================================
-- 
-- IMPORTANT: This script is SAFE to run on live database
-- - Creates NEW tables (IF NOT EXISTS)
-- - Adds missing columns (with IF NOT EXISTS where supported)
-- - Adds missing indexes (with IF NOT EXISTS)
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
-- PART 1: MISSING TABLES
-- ============================================================================

HEADER;

$migration = str_replace('{DATE}', date('Y-m-d H:i:s'), $migration);

$structureChanges = [];
$missingColumnsCount = 0;
$missingIndexesCount = 0;

// Add missing tables
foreach ($missingTables as $table) {
    $definition = extractTableDefinitions($localhostContent, $table);
    
    if ($definition) {
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

// Check common tables for structure differences
if (!empty($commonTables)) {
    $migration .= "\n-- ============================================================================\n";
    $migration .= "-- PART 2: STRUCTURE CHANGES (Missing Columns & Indexes)\n";
    $migration .= "-- ============================================================================\n\n";
    
    foreach ($commonTables as $table) {
        $liveDef = extractTableDefinitions($liveContent, $table);
        $localhostDef = extractTableDefinitions($localhostContent, $table);
        
        if (!$liveDef || !$localhostDef) {
            continue;
        }
        
        $liveColumns = extractColumns($liveDef);
        $localhostColumns = extractColumns($localhostDef);
        
        $liveIndexes = extractIndexes($liveDef);
        $localhostIndexes = extractIndexes($localhostDef);
        
        // Find missing columns
        $missingColumns = array_diff_key($localhostColumns, $liveColumns);
        
        // Find missing indexes
        $missingIndexes = array_diff_key($localhostIndexes, $liveIndexes);
        
        if (!empty($missingColumns) || !empty($missingIndexes)) {
            $migration .= "\n-- Table: $table\n";
            
            // Add missing columns
            if (!empty($missingColumns)) {
                foreach ($missingColumns as $colName => $colDef) {
                    // Extract column definition (remove column name)
                    $colDefClean = preg_replace('/^`?' . preg_quote($colName, '/') . '`?\s+/i', '', $colDef);
                    
                    // Try to find position (AFTER column)
                    $afterCol = null;
                    if (preg_match('/AFTER\s+`?(\w+)`?/i', $colDef, $afterMatch)) {
                        $afterCol = $afterMatch[1];
                    }
                    
                    // Generate ALTER TABLE statement
                    $alterStmt = "ALTER TABLE `$table` ADD COLUMN `$colName` $colDefClean";
                    if ($afterCol && isset($liveColumns[$afterCol])) {
                        $alterStmt .= " AFTER `$afterCol`";
                    }
                    $alterStmt .= ";\n";
                    
                    $migration .= "-- Missing column: $colName\n";
                    $migration .= $alterStmt;
                    $missingColumnsCount++;
                }
            }
            
            // Add missing indexes
            if (!empty($missingIndexes)) {
                foreach ($missingIndexes as $idxName => $idxInfo) {
                    // Skip PRIMARY KEY
                    if (strtolower($idxName) === 'primary') {
                        continue;
                    }
                    
                    $migration .= "-- Missing index: $idxName\n";
                    $migration .= "ALTER TABLE `$table` ADD INDEX `$idxName` ({$idxInfo['columns']});\n";
                    $missingIndexesCount++;
                }
            }
            
            $migration .= "\n";
        }
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
SELECT 'Please verify all tables and columns were created correctly.' AS reminder;

FOOTER;

// Replace {TABLES} with actual table list
$allTables = array_merge($missingTables, $commonTables);
$tableList = "'" . implode("', '", $allTables) . "'";
$migration = str_replace('{TABLES}', $tableList, $migration);

// Write migration file
file_put_contents($outputFile, $migration);

echo "\n✅ Enhanced migration script generated!\n";
echo "   File: migrations/COMPREHENSIVE_LIVE_UPDATE.sql\n";
echo "   Missing tables: " . count($missingTables) . "\n";
echo "   Missing columns: $missingColumnsCount\n";
echo "   Missing indexes: $missingIndexesCount\n\n";

if ($missingColumnsCount > 0 || $missingIndexesCount > 0) {
    echo "⚠️  IMPORTANT: The migration includes ALTER TABLE statements.\n";
    echo "   Review the file carefully before running on live database.\n";
    echo "   Some ALTER TABLE statements may need manual adjustment.\n\n";
}

echo "Next steps:\n";
echo "1. Review the migration file: migrations/COMPREHENSIVE_LIVE_UPDATE.sql\n";
echo "2. Check ALTER TABLE statements for accuracy\n";
echo "3. Backup your live database\n";
echo "4. Run the migration on live server\n";
echo "5. Verify all changes were applied\n\n";
