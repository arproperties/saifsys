-- Payroll cashback: an amount the employee hands back after being paid.
-- Display only. Net pay, the WPS file, the WPS guards and the accounting journal
-- never read these columns. Shown on the run and payslip only while the run's
-- "Show cashback" toggle is on. Safe to re-run.

-- Per run: the "Show cashback" toggle (0 = off).
ALTER TABLE `payroll_runs`
  ADD COLUMN IF NOT EXISTS `show_cashback` tinyint(1) NOT NULL DEFAULT 0;

-- Per employee in a run: the cashback amount.
ALTER TABLE `payroll_items`
  ADD COLUMN IF NOT EXISTS `cashback` decimal(12,2) NOT NULL DEFAULT 0.00;
