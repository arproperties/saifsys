-- Subcontractors under main contractor: hierarchy and coordination fee
-- Run this once on your database. If columns already exist, skip or comment out.

ALTER TABLE `co_project_contractors`
  ADD COLUMN `parent_project_contractor_id` INT(11) DEFAULT NULL AFTER `contractor_id`,
  ADD COLUMN `coordination_fee` DECIMAL(15,2) DEFAULT NULL COMMENT 'Amount owner pays to main contractor (AED) for his coordination/mobilization of this subcontractor' AFTER `contract_value`;

ALTER TABLE `co_project_contractors`
  ADD KEY `idx_parent` (`parent_project_contractor_id`);

ALTER TABLE `co_project_contractors`
  ADD CONSTRAINT `fk_pc_parent` FOREIGN KEY (`parent_project_contractor_id`) REFERENCES `co_project_contractors` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- Optional: if coordination_fee column already exists and you only want to update its comment, run this:
-- ALTER TABLE `co_project_contractors`
--   MODIFY COLUMN `coordination_fee` DECIMAL(15,2) DEFAULT NULL COMMENT 'Amount owner pays to main contractor (AED) for his coordination/mobilization of this subcontractor';
