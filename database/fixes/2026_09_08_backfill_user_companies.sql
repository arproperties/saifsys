-- Backfill user_companies from user.company_id
--
-- Why: ops_assignable_users() (modules/operations/includes/ops_helper.php)
-- inner-joins user_companies, so any staff account that only ever had
-- user.company_id set — every login created before HR's "create login" flow
-- started writing the link row — is invisible in the "Who does it?" dropdown
-- and cannot be assigned a job.
--
-- Safe to re-run: uq_user_company (user_id, company_id) makes INSERT IGNORE
-- a no-op for rows that already exist.

-- 1. Staff with no company link at all -> their home company, marked primary.
INSERT IGNORE INTO user_companies (user_id, company_id, is_primary, created_at)
SELECT u.id, u.company_id, 1, NOW()
FROM `user` u
LEFT JOIN user_companies uc ON uc.user_id = u.id
WHERE uc.user_id IS NULL
  AND u.user_type = 'internal'
  AND u.company_id > 0;

-- 2. Staff who are linked to other companies but not their own home company.
--    Not primary — whatever they already had keeps that flag.
INSERT IGNORE INTO user_companies (user_id, company_id, is_primary, created_at)
SELECT u.id, u.company_id, 0, NOW()
FROM `user` u
WHERE u.user_type = 'internal'
  AND u.company_id > 0
  AND NOT EXISTS (
        SELECT 1 FROM user_companies uc
        WHERE uc.user_id = u.id AND uc.company_id = u.company_id
      );
