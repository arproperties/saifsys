-- Operations — a finished job invoices itself.
--
-- The old cleaning module billed through the office: a work order was typed
-- in, and an Admin or Accountant pressed Finalize to turn it into an invoice.
-- In the thirty days before this, 2 of 420 orders ever got that press. Now the
-- person on site pressing Finish is what creates the invoice — through the very
-- same code, so the invoice, its number, the receivable and the GL journal are
-- exactly what Finalize would have produced. See
-- modules/operations/includes/ops_billing.php.
--
-- 1. Which client a building's cleaning is billed to. Set once per building;
--    the hourly rate, VAT and terms stay on the client, where the old module
--    already keeps them, so there is one rate to change and not two.
--
-- 2. What happened when each job finished, kept on the job: the hours billed,
--    the work order and invoice it produced, and — when it produced none — why,
--    in words the office can act on.
--
-- Safe to re-run. IF NOT EXISTS on ADD COLUMN is MariaDB (this server is
-- MariaDB), matching migrations/ops_comment_material_request.sql.

CREATE TABLE IF NOT EXISTS `ops_building_clients` (
  `building_id` INT(11) NOT NULL COMMENT 're_buildings.id',
  `client_id` INT(11) NOT NULL COMMENT 'client.id — who cleaning in this building is billed to',
  `updated_by` INT(11) DEFAULT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`building_id`),
  KEY `idx_ops_building_clients_client` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `ops_jobs`
  ADD COLUMN IF NOT EXISTS `billing_status` ENUM('billed','not_billable','no_client','failed') DEFAULT NULL
    COMMENT 'Set when the job finishes, NULL until then',
  ADD COLUMN IF NOT EXISTS `billing_note` VARCHAR(255) DEFAULT NULL
    COMMENT 'Why it was not invoiced, in words',
  ADD COLUMN IF NOT EXISTS `billed_hours` DECIMAL(6,2) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `order_id` INT(11) DEFAULT NULL COMMENT 'make_order.id this job produced',
  ADD COLUMN IF NOT EXISTS `invoice_id` INT(11) DEFAULT NULL COMMENT 'invoices.id this job produced';

CREATE UNIQUE INDEX IF NOT EXISTS `uniq_ops_jobs_order` ON `ops_jobs` (`order_id`);
