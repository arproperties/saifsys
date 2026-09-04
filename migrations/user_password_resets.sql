-- Main ERP user password reset tokens (Forgot password on /login).
-- Token is stored hashed (sha256); raw token is only emailed once.

CREATE TABLE IF NOT EXISTS user_password_resets (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT NOT NULL,
  email VARCHAR(255) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  request_ip VARCHAR(45) NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_user_password_resets_token (token_hash),
  KEY idx_user_password_resets_user (user_id),
  KEY idx_user_password_resets_email (email),
  KEY idx_user_password_resets_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
