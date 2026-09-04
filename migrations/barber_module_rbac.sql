-- Barber module — RBAC seed (run after barber_phase_b1_schema.sql)
-- Grants barber POS + back office to roles that already have grocery or inventory access.

INSERT IGNORE INTO role_departments (role_id, module, department)
SELECT DISTINCT rd.role_id, 'barber', 'barber_pos'
FROM role_departments rd
WHERE (rd.module = 'grocery' AND rd.department IN ('grocery_pos', 'grocery_backoffice', 'grocery'))
   OR (rd.module = 'inventory' AND rd.department = 'inventory');

INSERT IGNORE INTO role_departments (role_id, module, department)
SELECT DISTINCT rd.role_id, 'barber', 'barber_backoffice'
FROM role_departments rd
WHERE (rd.module = 'grocery' AND rd.department IN ('grocery_pos', 'grocery_backoffice', 'grocery'))
   OR (rd.module = 'inventory' AND rd.department = 'inventory');

INSERT INTO role_modules (role_id, module, permissions)
SELECT DISTINCT rd.role_id, 'barber',
       '{"barber_pos":["view","post"],"barber_backoffice":["view","manage_services","manage_team","export"]}'
FROM role_departments rd
WHERE rd.module = 'barber' AND rd.department IN ('barber_pos', 'barber_backoffice', 'barber')
  AND NOT EXISTS (
    SELECT 1 FROM role_modules x WHERE x.role_id = rd.role_id AND x.module = 'barber'
  );

INSERT IGNORE INTO role_departments (role_id, module, department)
SELECT r.id, 'barber', 'barber_pos'
FROM roles r
WHERE r.name IN ('Owner', 'Admin');

INSERT IGNORE INTO role_departments (role_id, module, department)
SELECT r.id, 'barber', 'barber_backoffice'
FROM roles r
WHERE r.name IN ('Owner', 'Admin');

INSERT INTO role_modules (role_id, module, permissions)
SELECT r.id, 'barber',
       '{"barber_pos":["view","post"],"barber_backoffice":["view","manage_services","manage_team","export"]}'
FROM roles r
WHERE r.name IN ('Owner', 'Admin')
  AND NOT EXISTS (SELECT 1 FROM role_modules x WHERE x.role_id = r.id AND x.module = 'barber');
