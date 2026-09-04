-- Phase 5A — widen funding method labels (operational trail only; no GL change).
-- One receiving GL debit per payment remains; methods explain how tenant paid.

ALTER TABLE `co_client_payment_funding_sources`
  MODIFY COLUMN `method` ENUM(
    'cash',
    'bank_transfer',
    'card',
    'online',
    'cheque'
  ) NOT NULL DEFAULT 'bank_transfer';
