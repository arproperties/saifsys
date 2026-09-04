-- ============================================================
-- Tenant Overpayment Refund Tracking
-- Adds:
--   1. transaction_type column to re_tenant_credit_transactions
--      for clearer audit trail (overpayment / applied / refund)
--   2. re_tenant_refunds table to record cash/transfer refunds
--      issued back to tenants, linked to their lease and
--      accounting journal
-- ============================================================

-- 1. Add transaction_type label to credit transactions log
ALTER TABLE re_tenant_credit_transactions
    ADD COLUMN IF NOT EXISTS transaction_type VARCHAR(50) DEFAULT NULL
    COMMENT 'overpayment | applied_installment | refund_issued | manual_credit | manual_debit'
    AFTER type;

-- Backfill existing rows
UPDATE re_tenant_credit_transactions
SET transaction_type = CASE
    WHEN type = 'credit' THEN 'overpayment'
    WHEN type = 'debit'  THEN 'applied_installment'
    ELSE NULL
END
WHERE transaction_type IS NULL;

-- 2. Create tenant refunds table
CREATE TABLE IF NOT EXISTS `re_tenant_refunds` (
  `id`                   INT(11)         NOT NULL AUTO_INCREMENT,
  `company_id`           INT(11)         NOT NULL,
  `lease_id`             INT(11)         NOT NULL,
  `tenant_id`            INT(11)         NOT NULL,
  `refund_date`          DATE            NOT NULL,
  `amount`               DECIMAL(12,2)   NOT NULL,
  `payment_method`       ENUM('cash','bank_transfer','cheque','auto_debit','cash_deposit')
                                         NOT NULL DEFAULT 'bank_transfer',
  `bank_account_id`      INT(11)         DEFAULT NULL,
  `reference_number`     VARCHAR(100)    DEFAULT NULL,
  `receipt_number`       VARCHAR(50)     DEFAULT NULL,
  `refund_reason`        VARCHAR(255)    DEFAULT NULL
                         COMMENT 'e.g. Overpayment return, Parking fee refund',
  `notes`                TEXT            DEFAULT NULL,
  `credit_transaction_id` INT(11)        DEFAULT NULL
                         COMMENT 'Row in re_tenant_credit_transactions that debited the credit',
  `journal_id`           INT(11)         DEFAULT NULL
                         COMMENT 'Accounting journal posted for this refund',
  `created_by`           INT(11)         DEFAULT NULL,
  `created_at`           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company`  (`company_id`),
  KEY `idx_lease`    (`lease_id`),
  KEY `idx_tenant`   (`tenant_id`),
  KEY `idx_date`     (`refund_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
