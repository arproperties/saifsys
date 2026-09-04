-- =============================================================================
-- BR-ARS-FIN-001 — Role map (company 2) + receipt account columns
-- =============================================================================
-- Prerequisite: migrations/ars_br_fin_001_seed_hh_coa_company2.sql already applied.
-- Additive only. Safe to re-run. Does not modify journals or accounting_engine.
-- =============================================================================

SET NAMES utf8mb4;
SET @re_company_id := 2;

-- Guard
SELECT
  id, name, business_type,
  CASE WHEN COALESCE(business_type,'') = 'realestate' THEN 'OK' ELSE 'STOP' END AS safety_check
FROM companies WHERE id = @re_company_id;

-- -----------------------------------------------------------------------------
-- Persist operator-selected cash/bank account on money rows
-- -----------------------------------------------------------------------------
SET @col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ars_booking_payments'
    AND COLUMN_NAME = 'receipt_account_code'
);
SET @sql := IF(@col = 0,
  'ALTER TABLE ars_booking_payments ADD COLUMN receipt_account_code VARCHAR(32) NULL DEFAULT NULL AFTER payment_method',
  'SELECT ''ars_booking_payments.receipt_account_code already exists'' AS notice'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ars_security_deposits'
    AND COLUMN_NAME = 'receipt_account_code'
);
SET @sql := IF(@col = 0,
  'ALTER TABLE ars_security_deposits ADD COLUMN receipt_account_code VARCHAR(32) NULL DEFAULT NULL AFTER amount',
  'SELECT ''ars_security_deposits.receipt_account_code already exists'' AS notice'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- Role → COA map for RE financial company (GL posting)
-- CASH/BANK defaults are fallbacks only; UI picker overrides per transaction.
-- -----------------------------------------------------------------------------
INSERT INTO ars_account_role_map (company_id, role_code, account_code, is_active, notes)
SELECT @re_company_id, v.role_code, v.account_code, 1, v.notes
FROM (
  SELECT 'AR_GUEST' AS role_code, '1340' AS account_code, 'HH guest AR' AS notes
  UNION ALL SELECT 'ROOM_REVENUE', '4130', 'HH room revenue'
  UNION ALL SELECT 'VAT_OUTPUT', '2310', 'Shared Output VAT'
  UNION ALL SELECT 'SECURITY_DEPOSIT', '2210', 'HH guest deposits held'
  UNION ALL SELECT 'GUEST_CREDIT', '2220', 'HH guest credit liability'
  UNION ALL SELECT 'ADDITIONAL_SERVICE_REVENUE', '4140', 'HH extra services'
  UNION ALL SELECT 'DAMAGE_REVENUE', '4140', 'HH damage recovery'
  UNION ALL SELECT 'LATE_FEE_REVENUE', '4150', 'HH late fees'
  UNION ALL SELECT 'FORFEIT_REVENUE', '4410', 'HH forfeit / other'
  UNION ALL SELECT 'DEFERRED_REVENUE', '2420', 'HH unearned'
  UNION ALL SELECT 'CASH', '1110', 'Default cash (picker overrides)'
  UNION ALL SELECT 'BANK', '1220', 'Default bank Ayla (picker overrides)'
  UNION ALL SELECT 'REFUND', '1110', 'Default refund cash (picker overrides)'
  UNION ALL SELECT 'STRIPE_CLEARING', '1140', 'HH Stripe clearing'
  UNION ALL SELECT 'STRIPE_FEE', '5510', 'HH Stripe fees'
  UNION ALL SELECT 'BAD_DEBT', '4130', 'Map to HH revenue until dedicated'
  UNION ALL SELECT 'DISCOUNT', '4130', 'Map to HH revenue until dedicated'
  UNION ALL SELECT 'ROUNDING', '4130', 'Map to HH revenue until dedicated'
) v
WHERE EXISTS (
  SELECT 1 FROM companies c
  WHERE c.id = @re_company_id AND COALESCE(c.business_type,'') = 'realestate'
)
AND NOT EXISTS (
  SELECT 1 FROM ars_account_role_map m
  WHERE m.company_id = @re_company_id AND m.role_code = v.role_code
);

-- Ensure codes if rows already existed with wrong values
UPDATE ars_account_role_map m
INNER JOIN companies c ON c.id = m.company_id AND c.id = @re_company_id AND COALESCE(c.business_type,'') = 'realestate'
SET m.account_code = CASE m.role_code
  WHEN 'AR_GUEST' THEN '1340'
  WHEN 'ROOM_REVENUE' THEN '4130'
  WHEN 'VAT_OUTPUT' THEN '2310'
  WHEN 'SECURITY_DEPOSIT' THEN '2210'
  WHEN 'GUEST_CREDIT' THEN '2220'
  WHEN 'ADDITIONAL_SERVICE_REVENUE' THEN '4140'
  WHEN 'DAMAGE_REVENUE' THEN '4140'
  WHEN 'LATE_FEE_REVENUE' THEN '4150'
  WHEN 'FORFEIT_REVENUE' THEN '4410'
  WHEN 'DEFERRED_REVENUE' THEN '2420'
  WHEN 'CASH' THEN '1110'
  WHEN 'BANK' THEN '1220'
  WHEN 'REFUND' THEN '1110'
  WHEN 'STRIPE_CLEARING' THEN '1140'
  WHEN 'STRIPE_FEE' THEN '5510'
  ELSE m.account_code
END,
m.is_active = 1
WHERE m.role_code IN (
  'AR_GUEST','ROOM_REVENUE','VAT_OUTPUT','SECURITY_DEPOSIT','GUEST_CREDIT',
  'ADDITIONAL_SERVICE_REVENUE','DAMAGE_REVENUE','LATE_FEE_REVENUE','FORFEIT_REVENUE',
  'DEFERRED_REVENUE','CASH','BANK','REFUND','STRIPE_CLEARING','STRIPE_FEE'
);

SELECT role_code, account_code, is_active
FROM ars_account_role_map
WHERE company_id = @re_company_id
ORDER BY role_code;

SELECT 'BR-ARS-FIN-001 role map + receipt columns complete.' AS notice;
