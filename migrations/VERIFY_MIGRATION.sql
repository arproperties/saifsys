-- ============================================================================
-- VERIFICATION QUERIES - Run After Migration
-- Use these to verify the migration was successful
-- ============================================================================

-- Check if role_departments table exists
SELECT 
    CASE 
        WHEN COUNT(*) > 0 THEN '✅ role_departments table exists'
        ELSE '❌ role_departments table NOT found'
    END AS status
FROM information_schema.tables 
WHERE table_schema = DATABASE() 
AND table_name = 'role_departments';

-- Check table structure
DESCRIBE role_departments;

-- Count records in role_departments (should be 0 for new table)
SELECT COUNT(*) AS role_departments_count FROM role_departments;

-- Verify existing critical tables still exist
SELECT 
    'user' AS table_name,
    COUNT(*) AS record_count
FROM user
UNION ALL
SELECT 
    'client' AS table_name,
    COUNT(*) AS record_count
FROM client
UNION ALL
SELECT 
    'companies' AS table_name,
    COUNT(*) AS record_count
FROM companies
UNION ALL
SELECT 
    'user_companies' AS table_name,
    COUNT(*) AS record_count
FROM user_companies
UNION ALL
SELECT 
    'roles' AS table_name,
    COUNT(*) AS record_count
FROM roles;

-- Check if roles table exists (required for role_departments foreign key)
SELECT 
    CASE 
        WHEN COUNT(*) > 0 THEN '✅ roles table exists (required for role_departments)'
        ELSE '❌ roles table NOT found - role_departments will not work!'
    END AS status
FROM information_schema.tables 
WHERE table_schema = DATABASE() 
AND table_name = 'roles';

-- Check foreign key constraint
SELECT 
    CONSTRAINT_NAME,
    TABLE_NAME,
    REFERENCED_TABLE_NAME,
    REFERENCED_COLUMN_NAME
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = DATABASE()
AND TABLE_NAME = 'role_departments'
AND REFERENCED_TABLE_NAME IS NOT NULL;

-- Final status
SELECT '✅ Verification complete! Check results above.' AS message;
