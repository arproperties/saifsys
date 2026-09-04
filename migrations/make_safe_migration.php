<?php
/**
 * Convert COMPREHENSIVE_LIVE_UPDATE.sql to a safe version that checks
 * for existing columns and indexes before adding them
 */

$inputFile = __DIR__ . '/COMPREHENSIVE_LIVE_UPDATE.sql';
$outputFile = __DIR__ . '/COMPREHENSIVE_LIVE_UPDATE_SAFE.sql';

$sql = file_get_contents($inputFile);

// Pattern 1: ALTER TABLE `table` ADD COLUMN `column` definition;
$sql = preg_replace_callback(
    '/ALTER TABLE `([^`]+)` ADD COLUMN `([^`]+)` ([^;]+);/',
    function($matches) {
        $table = $matches[1];
        $column = $matches[2];
        $definition = $matches[3];
        
        return "-- Add column: $column (safe check)\n" .
               "SET @col_exists = (\n" .
               "    SELECT COUNT(*) \n" .
               "    FROM INFORMATION_SCHEMA.COLUMNS \n" .
               "    WHERE TABLE_SCHEMA = DATABASE() \n" .
               "    AND TABLE_NAME = '$table' \n" .
               "    AND COLUMN_NAME = '$column'\n" .
               ");\n" .
               "SET @sql = IF(@col_exists = 0,\n" .
               "    'ALTER TABLE `$table` ADD COLUMN `$column` $definition',\n" .
               "    'SELECT \"Column $column already exists in $table\" AS message'\n" .
               ");\n" .
               "PREPARE stmt FROM @sql;\n" .
               "EXECUTE stmt;\n" .
               "DEALLOCATE PREPARE stmt;\n";
    },
    $sql
);

// Pattern 2: ALTER TABLE `table` ADD INDEX `index` (columns);
$sql = preg_replace_callback(
    '/ALTER TABLE `([^`]+)` ADD INDEX `([^`]+)` \(([^)]+)\);/',
    function($matches) {
        $table = $matches[1];
        $index = $matches[2];
        $columns = $matches[3];
        
        return "-- Add index: $index (safe check)\n" .
               "SET @idx_exists = (\n" .
               "    SELECT COUNT(*) \n" .
               "    FROM INFORMATION_SCHEMA.STATISTICS \n" .
               "    WHERE TABLE_SCHEMA = DATABASE() \n" .
               "    AND TABLE_NAME = '$table' \n" .
               "    AND INDEX_NAME = '$index'\n" .
               ");\n" .
               "SET @sql = IF(@idx_exists = 0,\n" .
               "    'ALTER TABLE `$table` ADD INDEX `$index` ($columns)',\n" .
               "    'SELECT \"Index $index already exists in $table\" AS message'\n" .
               ");\n" .
               "PREPARE stmt FROM @sql;\n" .
               "EXECUTE stmt;\n" .
               "DEALLOCATE PREPARE stmt;\n";
    },
    $sql
);

// Update header comment
$sql = str_replace(
    '-- IMPORTANT: This script is SAFE to run on live database',
    '-- IMPORTANT: This script is SAFE to run MULTIPLE TIMES on live database',
    $sql
);

$sql = str_replace(
    '-- - Adds missing columns (with IF NOT EXISTS where supported)',
    '-- - Adds missing columns (checks existence first - safe to rerun)',
    $sql
);

$sql = str_replace(
    '-- - Adds missing indexes (with IF NOT EXISTS)',
    '-- - Adds missing indexes (checks existence first - safe to rerun)',
    $sql
);

file_put_contents($outputFile, $sql);
echo "✅ Safe migration script created: $outputFile\n";
echo "   This version checks for existing columns/indexes before adding them.\n";
echo "   Safe to run multiple times!\n";
