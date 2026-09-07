-- Fix: lease view showed the system placeholder (CHQ-<lease id>-<n>) instead of the
-- cheque number entered on the lease form.
--
-- Cause: when a schedule row is created without a cheque number the code stores a
-- placeholder, and the lease edit form re-posted that placeholder into
-- reference_number. Lease view printed reference_number first, so it kept showing
-- the placeholder after data entry typed the real cheque numbers.
--
-- This clears the stale placeholder from reference_number wherever a real cheque
-- number exists. Rows whose cheque_number is still a placeholder are left untouched.

-- Preview first (expect the rows you saw on the lease view):
-- SELECT id, lease_id, installment_id, cheque_number, reference_number
-- FROM re_post_dated_cheques
-- WHERE reference_number REGEXP '^CHQ-[0-9]+-[0-9]+$'
--   AND cheque_number IS NOT NULL AND cheque_number <> ''
--   AND cheque_number NOT REGEXP '^CHQ-[0-9]+-[0-9]+$';

UPDATE re_post_dated_cheques
SET reference_number = cheque_number
WHERE reference_number REGEXP '^CHQ-[0-9]+-[0-9]+$'
  AND cheque_number IS NOT NULL AND cheque_number <> ''
  AND cheque_number NOT REGEXP '^CHQ-[0-9]+-[0-9]+$';

UPDATE re_lease_cheques
SET reference_number = cheque_number
WHERE reference_number REGEXP '^CHQ-[0-9]+-[0-9]+$'
  AND cheque_number IS NOT NULL AND cheque_number <> ''
  AND cheque_number NOT REGEXP '^CHQ-[0-9]+-[0-9]+$';
