-- Add 'partial' status to re_lease_installments table
ALTER TABLE `re_lease_installments` 
MODIFY COLUMN `status` ENUM('pending', 'paid', 'overdue', 'waived', 'partial') NOT NULL DEFAULT 'pending';
