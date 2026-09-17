-- Operations field app — jobs created on the phone.
--
-- Staff now raise their own jobs where they are standing instead of the office
-- scheduling them in advance, so `POST ops/jobs` is a write like start and
-- finish, and it needs the same replay protection they have.
--
-- Start and finish are safe to answer twice because they name a job that
-- already exists: the guard can reload it and hand it back. A create cannot —
-- the first attempt is the thing that made the id. Without somewhere to keep
-- that id, a create whose response was lost on the way back is answered with a
-- shrug, and the person taps again and books the same job twice.
--
-- So the request row remembers what it produced. A repeat of a create is then
-- answered with the job the first attempt made, which is what the phone was
-- waiting to hear.
--
-- Safe to re-run. IF NOT EXISTS on ADD COLUMN is MariaDB (this server is
-- MariaDB), matching migrations/ops_comment_material_request.sql.

ALTER TABLE `ops_api_requests`
  ADD COLUMN IF NOT EXISTS `job_id` INT(11) DEFAULT NULL
  COMMENT 'The job a create produced, so a replay returns it instead of making another'
  AFTER `route`;
