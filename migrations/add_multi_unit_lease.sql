-- ============================================================
-- Multi-Unit Lease Support
-- Allows one lease to cover multiple units for the same tenant
-- Each unit carries its own annual rent; combined total flows
-- into re_leases.annual_rent and drives installments as before.
-- ============================================================

-- 1. Add flag to re_leases
ALTER TABLE re_leases
    ADD COLUMN IF NOT EXISTS is_multi_unit TINYINT(1) NOT NULL DEFAULT 0
    AFTER unit_id;

-- 2. Per-unit breakdown table
CREATE TABLE IF NOT EXISTS re_lease_units (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    company_id     INT          NOT NULL,
    lease_id       INT          NOT NULL,
    unit_id        INT          NOT NULL,
    annual_rent    DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    sort_order     INT           NOT NULL DEFAULT 0,
    created_at     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (lease_id) REFERENCES re_leases(id) ON DELETE CASCADE,
    UNIQUE KEY uq_lease_unit (lease_id, unit_id)
);

-- 3. Back-fill: seed one row per existing single-unit lease
--    so joins always work regardless of is_multi_unit flag.
INSERT IGNORE INTO re_lease_units (company_id, lease_id, unit_id, annual_rent, sort_order)
SELECT company_id, id, unit_id, COALESCE(annual_rent, 0), 0
FROM re_leases
WHERE unit_id IS NOT NULL;
