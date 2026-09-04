-- Service charge payment schedule and allocations
-- Creates allocation rows for service-charge / billing-item payments so service fees can be paid partially.

CREATE TABLE IF NOT EXISTS `re_billing_item_payment_allocations` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `payment_id` INT(11) NOT NULL,
  `billing_item_id` INT(11) NOT NULL,
  `amount_allocated` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payment_billing_item` (`payment_id`, `billing_item_id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_payment` (`payment_id`),
  KEY `idx_billing_item` (`billing_item_id`),
  CONSTRAINT `fk_re_bipa_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_re_bipa_payment` FOREIGN KEY (`payment_id`) REFERENCES `re_payments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_re_bipa_billing_item` FOREIGN KEY (`billing_item_id`) REFERENCES `re_billing_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

