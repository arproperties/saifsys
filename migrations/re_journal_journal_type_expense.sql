-- ERP expenses: allow journal_type 'expense' on re_journal_headers (create_and_post_journal in accounting_engine.php).
-- Run after accounting_phase_3_5.sql (or any migration that set journal_type to the credit_note list).
-- Safe to run once; if 'expense' already exists, MySQL will error — ignore or comment the ALTER.

ALTER TABLE `re_journal_headers`
  MODIFY COLUMN `journal_type` ENUM(
    'manual', 'invoice', 'payment', 'deposit', 'refund', 'adjustment',
    'recurring', 'reversal', 'opening_balance', 'closing', 'credit_note', 'expense'
  ) NOT NULL;
