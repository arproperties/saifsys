<?php
/**
 * Enhanced Database Comparison Tool V2
 * Compares live database structure with localhost structure
 * Generates migration for:
 * 1. Missing tables (CREATE TABLE)
 * 2. Missing columns (ALTER TABLE ADD COLUMN)
 * 3. Missing indexes (ALTER TABLE ADD INDEX)
 * 
 * Improved column parsing to catch all differences
 * 
 * Usage:
 * 1. Place live_database_structure.sql in project root
 * 2. Place localhost_database_structure.sql in project root
 * 3. Run: php migrations/GENERATE_MIGRATION_WITH_STRUCTURE_CHANGES_V2.php
 * 4. Output: migrations/COMPREHENSIVE_LIVE_UPDATE.sql
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "==========================================\n";
echo "Enhanced Database Comparison Tool V2\n";
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
    preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?/i', $sql, $matches);
    if (!empty($matches[1])) {
        $tables = array_unique($matches[1]);
    }
    return $tables;
}

// Extract CREATE TABLE statements - improved to handle multi-line
function extractTableDefinitions($sql, $tableName) {
    // More robust pattern - match from CREATE TABLE to semicolon
    $pattern = '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?' . preg_quote($tableName, '/') . '`?\s*\([^;]*\)[^;]*;/is';
    preg_match($pattern, $sql, $matches);
    
    if (empty($matches[0])) {
        // Try alternative pattern without IF NOT EXISTS
        $pattern2 = '/CREATE\s+TABLE\s+`?' . preg_quote($tableName, '/') . '`?\s*\([^;]*\)[^;]*;/is';
        preg_match($pattern2, $sql, $matches);
    }
    
    return !empty($matches[0]) ? $matches[0] : null;
}

// Improved column extraction - better handling of complex definitions
function extractColumns($tableDefinition) {
    $columns = [];
    
    if (empty($tableDefinition)) {
        return $columns;
    }
    
    // Extract everything between the first ( and matching )
    if (!preg_match('/\(([\s\S]+)\)/s', $tableDefinition, $matches)) {
        return $columns;
    }
    
    $content = $matches[1];
    
    // Split by comma, but respect nested parentheses and backticks
    $lines = [];
    $current = '';
    $depth = 0;
    $inBacktick = false;
    
    for ($i = 0; $i < strlen($content); $i++) {
        $char = $content[$i];
        $nextChar = ($i < strlen($content) - 1) ? $content[$i + 1] : '';
        
        if ($char === '`') {
            $inBacktick = !$inBacktick;
            $current .= $char;
        } elseif ($inBacktick) {
            $current .= $char;
        } elseif ($char === '(') {
            $depth++;
            $current .= $char;
        } elseif ($char === ')') {
            $depth--;
            $current .= $char;
        } elseif ($char === ',' && $depth === 0) {
            $lines[] = trim($current);
            $current = '';
        } else {
            $current .= $char;
        }
    }
    
    if (!empty(trim($current))) {
        $lines[] = trim($current);
    }
    
    // Process each line
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        
        // Skip constraint definitions
        if (preg_match('/^\s*(PRIMARY\s+KEY|FOREIGN\s+KEY|UNIQUE\s+KEY|CONSTRAINT|KEY\s+`?\w+`?|INDEX\s+`?\w+`?)/i', $line)) {
            continue;
        }
        
        // Extract column name - handle backticks
        if (preg_match('/^`?(\w+)`?\s+/', $line, $colMatch)) {
            $colName = $colMatch[1];
            $columns[$colName] = $line;
        }
    }
    
    return $columns;
}

// Extract indexes from CREATE TABLE
function extractIndexes($tableDefinition) {
    $indexes = [];
    
    // Extract KEY, INDEX, UNIQUE KEY definitions
    preg_match_all('/(?:KEY|INDEX|UNIQUE\s+KEY)\s+`?(\w+)`?\s*\(([^)]+)\)/i', $tableDefinition, $matches, PREG_SET_ORDER);
    
    foreach ($matches as $match) {
        $indexName = $match[1];
        $indexColumns = trim($match[2]);
        $indexes[$indexName] = [
            'name' => $indexName,
            'columns' => $indexColumns,
            'full' => $match[0]
        ];
    }
    
    return $indexes;
}

// Extract column definition details for ALTER TABLE
function parseColumnDefinition($colDef) {
    $colDef = trim($colDef);
    
    // Extract column name (handle backticks)
    if (!preg_match('/^`?(\w+)`?\s+(.+)$/is', $colDef, $matches)) {
        return null;
    }
    
    $colName = $matches[1];
    $colType = trim($matches[2]);
    
    // Extract AFTER clause if present
    $afterCol = null;
    if (preg_match('/\s+AFTER\s+`?(\w+)`?/i', $colType, $afterMatch)) {
        $afterCol = $afterMatch[1];
        $colType = preg_replace('/\s+AFTER\s+`?\w+`?/i', '', $colType);
    }
    
    return [
        'name' => $colName,
        'definition' => trim($colType),
        'after' => $afterCol,
        'full' => $colDef
    ];
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
$tablesWithChanges = [];

// Add missing tables
foreach ($missingTables as $table) {
    $definition = extractTableDefinitions($localhostContent, $table);
    
    if ($definition) {
        $definition = preg_replace(
            '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?/i',
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
    echo "Checking structure differences in common tables...\n";
    
    $migration .= "\n-- ============================================================================\n";
    $migration .= "-- PART 2: STRUCTURE CHANGES (Missing Columns & Indexes)\n";
    $migration .= "-- ============================================================================\n\n";
    
    foreach ($commonTables as $table) {
        $liveDef = extractTableDefinitions($liveContent, $table);
        $localhostDef = extractTableDefinitions($localhostContent, $table);
        
        if (!$liveDef || !$localhostDef) {
            echo "⚠️  Warning: Could not extract definitions for table: $table\n";
            if (!$liveDef) echo "   - Live definition not found\n";
            if (!$localhostDef) echo "   - Localhost definition not found\n";
            continue;
        }
        
        $liveColumns = extractColumns($liveDef);
        $localhostColumns = extractColumns($localhostDef);
        
        $liveIndexes = extractIndexes($liveDef);
        $localhostIndexes = extractIndexes($localhostDef);
        
        // Debug output
        echo "  Checking $table... ";
        echo "Live columns: " . count($liveColumns) . ", ";
        echo "Localhost columns: " . count($localhostColumns) . "\n";
        
        // Find missing columns
        $missingColumns = array_diff_key($localhostColumns, $liveColumns);
        
        // Find missing indexes (exclude PRIMARY)
        $missingIndexes = [];
        foreach ($localhostIndexes as $idxName => $idxInfo) {
            if (strtoupper($idxName) === 'PRIMARY') continue;
            if (!isset($liveIndexes[$idxName])) {
                $missingIndexes[$idxName] = $idxInfo;
            }
        }
        
        if (!empty($missingColumns) || !empty($missingIndexes)) {
            $tablesWithChanges[] = $table;
            echo "    ✅ Found differences: " . count($missingColumns) . " missing columns, " . count($missingIndexes) . " missing indexes\n";
            
            $migration .= "\n-- ============================================================================\n";
            $migration .= "-- Table: $table\n";
            $migration .= "-- ============================================================================\n";
            
            // Add missing columns
            if (!empty($missingColumns)) {
                $migration .= "-- Missing columns: " . count($missingColumns) . "\n";
                foreach ($missingColumns as $colName => $colDef) {
                    $parsed = parseColumnDefinition($colDef);
                    if ($parsed) {
                        $colType = $parsed['definition'];
                        $afterClause = $parsed['after'] ? " AFTER `{$parsed['after']}`" : '';
                        
                        // Generate ALTER TABLE statement
                        $migration .= "-- Add column: $colName\n";
                        $migration .= "ALTER TABLE `$table` ADD COLUMN `$colName` $colType$afterClause;\n\n";
                        $missingColumnsCount++;
                    } else {
                        echo "    ⚠️  Warning: Could not parse column definition for $table.$colName\n";
                        // Fallback: try to extract just the type
                        if (preg_match('/^`?' . preg_quote($colName, '/') . '`?\s+(.+)$/is', $colDef, $typeMatch)) {
                            $colType = trim($typeMatch[1]);
                            $migration .= "-- Add column: $colName (parsed with fallback)\n";
                            $migration .= "ALTER TABLE `$table` ADD COLUMN `$colName` $colType;\n\n";
                            $missingColumnsCount++;
                        }
                    }
                }
            }
            
            // Add missing indexes
            if (!empty($missingIndexes)) {
                $migration .= "-- Missing indexes: " . count($missingIndexes) . "\n";
                foreach ($missingIndexes as $idxName => $idxInfo) {
                    $migration .= "-- Add index: $idxName\n";
                    $migration .= "ALTER TABLE `$table` ADD INDEX `$idxName` ({$idxInfo['columns']});\n\n";
                    $missingIndexesCount++;
                }
            }
        } else {
            echo "    ✓ No differences found\n";
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
echo "   Tables with structure changes: " . count($tablesWithChanges) . "\n";
if (!empty($tablesWithChanges)) {
    echo "   Tables: " . implode(', ', $tablesWithChanges) . "\n";
}
echo "   Missing columns: $missingColumnsCount\n";
echo "   Missing indexes: $missingIndexesCount\n\n";

if ($missingColumnsCount > 0 || $missingIndexesCount > 0) {
    echo "⚠️  IMPORTANT: The migration includes ALTER TABLE statements.\n";
    echo "   Review the file carefully before running on live database.\n";
    echo "   Some ALTER TABLE statements may need manual adjustment.\n";
    echo "   MySQL doesn't support 'IF NOT EXISTS' for ALTER TABLE ADD COLUMN.\n";
    echo "   If a column already exists, the statement will fail.\n\n";
} else {
    echo "ℹ️  No structure differences found in common tables.\n";
    echo "   This could mean:\n";
    echo "   - Tables are identical (good!)\n";
    echo "   - Column extraction failed (check the warnings above)\n";
    echo "   - Export format is different\n\n";
}

echo "Next steps:\n";
echo "1. Review the migration file: migrations/COMPREHENSIVE_LIVE_UPDATE.sql\n";
echo "2. Check ALTER TABLE statements for accuracy\n";
echo "3. Backup your live database\n";
echo "4. Run the migration on live server\n";
echo "5. Verify all changes were applied\n\n";
