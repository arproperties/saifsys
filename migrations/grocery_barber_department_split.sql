-- One-time data migration: legacy single grocery/barber department → POS + back office.
-- Run once on databases that still use department = 'grocery' or 'barber' (combined access).

INSERT IGNORE INTO role_departments (role_id, module, department)
SELECT role_id, 'grocery', 'grocery_backoffice'
FROM role_departments
WHERE module = 'grocery' AND department = 'grocery';

UPDATE role_departments SET department = 'grocery_pos'
WHERE module = 'grocery' AND department = 'grocery';

INSERT IGNORE INTO role_departments (role_id, module, department)
SELECT role_id, 'barber', 'barber_backoffice'
FROM role_departments
WHERE module = 'barber' AND department = 'barber';

UPDATE role_departments SET department = 'barber_pos'
WHERE module = 'barber' AND department = 'barber';
