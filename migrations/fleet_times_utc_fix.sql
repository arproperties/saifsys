-- ONE-TIME repair: trip times saved 4 hours early on LIVE.
--
-- The Driver app's API stamps every trip time with PHP's date(), and the live
-- PHP runs on UTC, so trips, GPS points and stop arrivals are all 4h behind
-- Dubai time. The code fix sets Asia/Dubai in ops_helper.php and
-- hr_fleet_ui.php.
--
-- Run it ONCE on LIVE, right BEFORE uploading those two PHP files. Running it
-- after the upload would push trips recorded with the fixed code 4h too late,
-- and running it twice shifts everything twice. Do NOT run it locally: local
-- PHP is already on Dubai time.
--
-- Check first: Ajmal Khan's 14 Sep trip should show 12:30 before and 16:30
-- after.
--   SELECT id, driver_name, started_at, ended_at FROM fleet_trips ORDER BY id;

START TRANSACTION;

UPDATE fleet_trips
SET
    started_at     = started_at     + INTERVAL 4 HOUR,
    ended_at       = ended_at       + INTERVAL 4 HOUR,
    last_point_at  = last_point_at  + INTERVAL 4 HOUR,
    last_seen_at   = last_seen_at   + INTERVAL 4 HOUR,
    dist_anchor_at = dist_anchor_at + INTERVAL 4 HOUR;

UPDATE fleet_trip_points
SET
    recorded_at = recorded_at + INTERVAL 4 HOUR,
    received_at = received_at + INTERVAL 4 HOUR;

UPDATE fleet_trip_stops
SET
    arrived_at = arrived_at + INTERVAL 4 HOUR,
    left_at    = left_at    + INTERVAL 4 HOUR;

UPDATE fleet_point_employees
SET assigned_at = assigned_at + INTERVAL 4 HOUR
WHERE assigned_at IS NOT NULL;

COMMIT;
