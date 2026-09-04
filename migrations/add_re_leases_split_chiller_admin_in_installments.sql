-- Add checkbox: when 1, Chiller + Admin + Annual Additional Parking/Store are split into all installments;
-- only Ejari + Security Deposit + Commission are added to 1st installment.
-- Run once per environment. If column already exists, skip or run: ALTER TABLE re_leases DROP COLUMN split_chiller_admin_in_installments; then re-run.

ALTER TABLE re_leases
ADD COLUMN split_chiller_admin_in_installments TINYINT(1) NOT NULL DEFAULT 0
COMMENT '1 = split chiller, admin, parking, store into installments; only Ejari+Security+Commission to 1st';
