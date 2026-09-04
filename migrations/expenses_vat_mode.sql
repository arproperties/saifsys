-- Expenses: document-level VAT basis (exclusive vs inclusive). Safe to re-run.
ALTER TABLE `expenses`
  ADD COLUMN IF NOT EXISTS `vat_mode` VARCHAR(20) NOT NULL DEFAULT 'exclusive'
    COMMENT 'exclusive = unit cost ex VAT; inclusive = unit cost incl VAT, VAT extracted'
    AFTER `total`;
