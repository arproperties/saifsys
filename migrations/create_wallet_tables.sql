-- Create Wallet Tables for Mobile App

-- User Wallet Table
CREATE TABLE IF NOT EXISTS `mobile_user_wallet` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `client_id` int(11) NOT NULL,
  `balance` decimal(10,2) NOT NULL DEFAULT 0.00,
  `currency` varchar(3) NOT NULL DEFAULT 'AED',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_client_wallet` (`client_id`),
  KEY `idx_client_id` (`client_id`),
  CONSTRAINT `fk_wallet_client` FOREIGN KEY (`client_id`) REFERENCES `client` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Wallet Transactions Table
CREATE TABLE IF NOT EXISTS `mobile_wallet_transactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `wallet_id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `transaction_type` enum('credit','debit') NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `balance_before` decimal(10,2) NOT NULL,
  `balance_after` decimal(10,2) NOT NULL,
  `transaction_category` varchar(50) NOT NULL COMMENT 'topup, payment, refund, bonus, compensation, adjustment',
  `reference_id` varchar(100) DEFAULT NULL COMMENT 'booking_id, payment_id, etc.',
  `reference_type` varchar(50) DEFAULT NULL COMMENT 'booking, topup, admin_adjustment, etc.',
  `description` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL COMMENT 'admin user_id if created by admin',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_wallet_id` (`wallet_id`),
  KEY `idx_client_id` (`client_id`),
  KEY `idx_transaction_type` (`transaction_type`),
  KEY `idx_category` (`transaction_category`),
  KEY `idx_reference` (`reference_type`, `reference_id`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `fk_transaction_wallet` FOREIGN KEY (`wallet_id`) REFERENCES `mobile_user_wallet` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_transaction_client` FOREIGN KEY (`client_id`) REFERENCES `client` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_transaction_created_by` FOREIGN KEY (`created_by`) REFERENCES `user` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Top-Up Packages Table
CREATE TABLE IF NOT EXISTS `mobile_wallet_topup_packages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `bonus_amount` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Bonus credit given with this package',
  `is_popular` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_active` (`is_active`),
  KEY `idx_sort` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default top-up packages
INSERT INTO `mobile_wallet_topup_packages` (`name`, `amount`, `bonus_amount`, `is_popular`, `sort_order`) VALUES
('AED 100', 100.00, 0.00, 0, 1),
('AED 200', 200.00, 10.00, 0, 2),
('AED 500', 500.00, 50.00, 1, 3),
('AED 1000', 1000.00, 150.00, 0, 4);

