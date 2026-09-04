-- Seed admin-view permission for Real Estate tasks
-- Permission key: realestate_tasks.admin_view
-- Default grants: Owner + Manager roles

SET NAMES utf8mb4;

-- Ensure role_modules rows exist for Owner/Manager in realestate module
INSERT IGNORE INTO role_modules (role_id, module, permissions)
SELECT r.id, 'realestate', NULL
FROM roles r
WHERE r.name IN ('Owner', 'Manager');

-- Merge permission into JSON permissions safely
UPDATE role_modules rm
JOIN roles r ON r.id = rm.role_id
SET rm.permissions = CASE
    WHEN rm.permissions IS NULL OR rm.permissions = '' THEN
        JSON_OBJECT('realestate_tasks', JSON_ARRAY('admin_view'))
    WHEN JSON_VALID(rm.permissions) = 0 THEN
        JSON_OBJECT('realestate_tasks', JSON_ARRAY('admin_view'))
    WHEN JSON_CONTAINS_PATH(rm.permissions, 'one', '$.realestate_tasks') = 0 THEN
        JSON_SET(rm.permissions, '$.realestate_tasks', JSON_ARRAY('admin_view'))
    WHEN JSON_SEARCH(JSON_EXTRACT(rm.permissions, '$.realestate_tasks'), 'one', 'admin_view') IS NOT NULL THEN
        rm.permissions
    ELSE
        JSON_SET(
            rm.permissions,
            '$.realestate_tasks',
            JSON_ARRAY_APPEND(JSON_EXTRACT(rm.permissions, '$.realestate_tasks'), '$', 'admin_view')
        )
END
WHERE rm.module = 'realestate'
  AND r.name IN ('Owner', 'Manager');

