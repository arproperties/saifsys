-- Phase 2B — safe additive indexes for Commercial Leasing analytics / lists.
-- No schema behaviour change. No posting changes.
-- Run after phase2a. Idempotent where MySQL/MariaDB supports CREATE INDEX IF NOT EXISTS.

CREATE INDEX IF NOT EXISTS `idx_co_shop_contracts_status_end`
  ON `co_shop_rental_contracts` (`company_id`, `status`, `end_date`);

CREATE INDEX IF NOT EXISTS `idx_co_shop_contracts_client_status`
  ON `co_shop_rental_contracts` (`company_id`, `client_id`, `status`);

CREATE INDEX IF NOT EXISTS `idx_co_shop_schedules_status_due`
  ON `co_shop_rent_schedules` (`company_id`, `status`, `due_date`);

CREATE INDEX IF NOT EXISTS `idx_co_shop_schedules_type_status`
  ON `co_shop_rent_schedules` (`company_id`, `schedule_type`, `status`);

CREATE INDEX IF NOT EXISTS `idx_co_client_invoices_source_date`
  ON `co_client_invoices` (`company_id`, `source_type`, `invoice_date`);

CREATE INDEX IF NOT EXISTS `idx_co_cp_alloc_company_invoice`
  ON `co_client_payment_allocations` (`company_id`, `invoice_id`);

CREATE INDEX IF NOT EXISTS `idx_co_shop_units_status`
  ON `co_shop_units` (`company_id`, `status`);
