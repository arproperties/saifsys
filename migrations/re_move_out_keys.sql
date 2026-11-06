-- Move-Out Key Return (its own step on the move-out page)
--
-- One row per key / access card the tenant has to give back, copied from the
-- keys handed over at move-in (re_move_in_keys of the tenancy's move-in, also
-- for a renewal lease). When the move-in recorded no keys, the usual list is
-- offered with nothing issued. Staff enter how many came back; confirming the
-- key return sets re_move_outs.keys_returned.
--
-- Safe to run more than once.

CREATE TABLE IF NOT EXISTS `re_move_out_keys` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `move_out_id` INT(11) NOT NULL,
  `key_label` VARCHAR(100) NOT NULL,
  `issued_qty` INT(11) DEFAULT NULL COMMENT 'handed over at move-in; NULL = not recorded',
  `card_numbers` VARCHAR(255) DEFAULT NULL COMMENT 'from the move-in',
  `returned_qty` INT(11) DEFAULT NULL COMMENT 'NULL = not entered yet',
  `note` VARCHAR(255) DEFAULT NULL,
  `is_custom` TINYINT(1) NOT NULL DEFAULT 0,
  `display_order` INT(11) NOT NULL DEFAULT 0,
  `updated_by` INT(11) DEFAULT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_rmok_move_out` (`move_out_id`, `display_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
