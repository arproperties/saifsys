-- Finance > Partners: a ledger line can carry the tenant or supplier it belongs
-- to, so one account (Sundry Debtors, Sundry Creditors) gives a balance for each.
-- partner_type names where the partner is kept: tenant (re_tenants), re_vendor
-- (re_vendors), co_supplier (co_suppliers) or vendor (vendors).
-- Run after fin_lease_posting.sql. Safe to re-run.
ALTER TABLE `fin_ledger_entries`
  ADD COLUMN IF NOT EXISTS `partner_type` varchar(20) DEFAULT NULL AFTER `description`,
  ADD COLUMN IF NOT EXISTS `partner_id` int(11) DEFAULT NULL AFTER `partner_type`,
  ADD KEY IF NOT EXISTS `idx_fin_le_partner` (`company_id`,`partner_type`,`partner_id`);

-- Leases posted before this: the debit under Sundry Debtors is the tenant's.
UPDATE `fin_ledger_entries` e
  JOIN `fin_vouchers` v ON v.id = e.voucher_id
  JOIN `re_leases` l ON l.id = v.lease_id
  JOIN `re_tenants` t ON t.id = l.tenant_id AND t.company_id = e.company_id
  JOIN `fin_coa_accounts` a ON a.id = e.account_id AND a.code = '1121005'
   SET e.partner_type = 'tenant', e.partner_id = l.tenant_id
 WHERE e.partner_type IS NULL AND e.debit > 0;
