-- 2025-11-11: Add direct link from user -> employees
ALTER TABLE `user`
  ADD COLUMN `employee_id` INT NULL AFTER `signature_path`,
  ADD KEY `idx_user_employee_id` (`employee_id`);

-- Populate employee_id where employees.user_id is already set
UPDATE `user` u
JOIN employees e ON e.user_id = u.id
SET u.employee_id = e.id
WHERE u.employee_id IS NULL;

-- Enforce referential integrity (nullable for legacy/unmapped accounts)
ALTER TABLE `user`
  ADD CONSTRAINT `fk_user_employee`
    FOREIGN KEY (`employee_id`)
    REFERENCES `employees`(`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL;

