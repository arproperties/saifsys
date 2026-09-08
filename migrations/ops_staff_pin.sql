-- Operations field app — 4-digit PIN sign-in.
--
-- Replaces the prototype "pick your name from a list" identity. The phone now
-- sends four digits and nothing else, so the PIN alone has to name one person:
-- that is why `pin_lookup` carries a UNIQUE index. Two people cannot share a
-- PIN, and the office is told so when it tries to set a duplicate.
--
-- Why two columns for one secret:
--
--   pin_hash    password_hash() of the PIN. This is what is actually verified.
--               Slow and salted, so a leaked table is not a list of PINs.
--   pin_lookup  HMAC-SHA256(pin, OPS_MOBILE_PIN_SECRET), hex. A blind index.
--               Nothing can be verified with it — its only jobs are to find
--               the one candidate row in O(1) (a bcrypt sweep over every
--               employee per login attempt would not scale) and to enforce
--               uniqueness. It is useless without the server-side secret,
--               which lives in config, not in the database.
--
-- Four digits is 10,000 possibilities, which is small enough that the lockout
-- in ops_pin_attempts is not optional — it is the other half of the security
-- of this scheme. See ops_pin_throttle_state() in
-- modules/operations/includes/ops_pin.php.

CREATE TABLE IF NOT EXISTS `ops_staff_pins` (
  `user_id` INT(11) NOT NULL,
  `pin_hash` VARCHAR(255) NOT NULL COMMENT 'password_hash of the PIN — what is verified',
  `pin_lookup` CHAR(64) NOT NULL COMMENT 'HMAC of the PIN under the server secret — finds the row, proves nothing',
  `set_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Also the token epoch: changing the PIN invalidates issued tokens',
  `set_by` INT(11) NULL COMMENT 'Which office user set it',
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `uq_ops_staff_pins_lookup` (`pin_lookup`),
  CONSTRAINT `fk_ops_staff_pins_user`
    FOREIGN KEY (`user_id`) REFERENCES `user`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Every sign-in attempt, right or wrong.
--
-- A wrong PIN usually matches nobody, so there is no account to lock — the
-- thing being guessed at is the whole PIN space, not one person. The counting
-- therefore happens per device and per address, not per user.
--
-- Rows are disposable; ops_pin_prune_attempts() drops anything older than a
-- day. Successes are kept for that day too, so "who signed in on this handset"
-- is answerable while it still matters.
CREATE TABLE IF NOT EXISTS `ops_pin_attempts` (
  `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
  `device_id` VARCHAR(64) NOT NULL DEFAULT '' COMMENT 'Stable per install, sent as X-Ops-Device-Id',
  `ip` VARCHAR(45) NOT NULL DEFAULT '',
  `user_id` INT(11) NULL COMMENT 'Only set when the PIN matched someone',
  `succeeded` TINYINT(1) NOT NULL DEFAULT 0,
  `attempted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ops_pin_attempts_device` (`device_id`, `attempted_at`),
  KEY `idx_ops_pin_attempts_ip` (`ip`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
