-- ONE-TIME repair: job start/finish times saved 4 hours early on LIVE.
--
-- The field app's Start/Finish and the office "Change status" stamped
-- started_at / finished_at with MySQL NOW(). The live MySQL runs on UTC while
-- PHP shows everything in Dubai time, so those times are 4h behind and
-- duration_minutes (worked out in PHP against its own clock) is 240 too high.
--
-- 1. Check first. NOW() must be 4 hours behind Dubai time. If NOW() already
--    shows Dubai time, DO NOT run this.
--      SELECT @@global.time_zone, @@system_time_zone, NOW();
--
-- 2. Run it ONCE, right BEFORE uploading the fixed PHP files. Running it after
--    the upload would push jobs started with the fixed code 4h too late, and
--    running it twice shifts everything twice.

START TRANSACTION;

UPDATE ops_jobs
SET
    -- Worked out from the start/finish gap, which was right. Jobs closed by the
    -- office have no duration and stay that way.
    duration_minutes = CASE
        WHEN duration_minutes IS NOT NULL AND started_at IS NOT NULL AND finished_at IS NOT NULL
        THEN GREATEST(0, ROUND(TIMESTAMPDIFF(SECOND, started_at, finished_at) / 60))
        ELSE duration_minutes
    END,
    started_at  = started_at  + INTERVAL 4 HOUR,
    finished_at = finished_at + INTERVAL 4 HOUR,
    updated_at  = updated_at
WHERE started_at IS NOT NULL OR finished_at IS NOT NULL;

COMMIT;
