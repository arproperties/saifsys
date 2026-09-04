-- Deduplicate post-dated cheque reminder emails (cron). Run once per environment.

CREATE TABLE IF NOT EXISTS `re_cheque_reminder_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `cheque_id` int(11) NOT NULL,
  `reminder_key` varchar(48) NOT NULL COMMENT 'e.g. d7, d3, d1, due, overdue_2026_W15',
  `sent_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cheque_reminder` (`cheque_id`,`reminder_key`),
  KEY `idx_company` (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
