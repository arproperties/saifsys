-- ============================================================
-- Penalty Income Account (4300)
-- Required for accounting entries when penalty charges
-- (late fees, bounced cheque fees) are collected.
--
-- Account 4300 "Penalty Income" should already exist in the
-- live chart of accounts. This script ensures it is present
-- for all companies in case it is missing on any install.
-- ============================================================

INSERT IGNORE INTO re_chart_of_accounts
    (company_id, account_code, account_name, account_type, normal_balance, is_active, description)
SELECT
    c.id,
    '4300',
    'Penalty Income',
    'income',
    'credit',
    1,
    'Late fees and penalties collected'
FROM (SELECT DISTINCT company_id AS id FROM re_chart_of_accounts) c
WHERE NOT EXISTS (
    SELECT 1 FROM re_chart_of_accounts x
    WHERE x.company_id = c.id AND x.account_code = '4300'
);
