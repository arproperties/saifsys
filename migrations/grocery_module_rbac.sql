-- Grocery module (supermarket retail POS & back office) — RBAC seed
-- Run after code defines MODULE_GROCERY and DEPT_GROCERY_POS / DEPT_GROCERY_BACKOFFICE.
-- Mirrors inventory access for roles that already have the inventory department.

INSERT IGNORE INTO role_departments (role_id, module, department)
SELECT role_id, 'grocery', 'grocery_pos'
FROM role_departments
WHERE module = 'inventory' AND department = 'inventory';

INSERT IGNORE INTO role_departments (role_id, module, department)
SELECT role_id, 'grocery', 'grocery_backoffice'
FROM role_departments
WHERE module = 'inventory' AND department = 'inventory';

INSERT INTO role_modules (role_id, module, permissions)
SELECT rm.role_id, 'grocery',
       '{"grocery_pos":["view","post"],"grocery_reports":["view","export"]}'
FROM role_modules rm
WHERE rm.module = 'inventory'
  AND NOT EXISTS (
    SELECT 1 FROM role_modules x WHERE x.role_id = rm.role_id AND x.module = 'grocery'
  );
