-- Per-user task access overrides (shared tasks module)
-- Allows granting task access without full Real Estate module access.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `user_task_permissions` (
  `user_id` int(11) NOT NULL,
  `can_view` tinyint(1) NOT NULL DEFAULT 0,
  `can_admin_view` tinyint(1) NOT NULL DEFAULT 0,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`user_id`),
  KEY `idx_user_task_can_view` (`can_view`),
  KEY `idx_user_task_admin` (`can_admin_view`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

