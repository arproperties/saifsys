-- Operations -> Store Camera: what a store camera saw, as a daily report.
--
-- The laptop program tools/attendance_camera/store_camera.py watches each camera
-- live all day. When something moves it keeps snapshots, Claude writes one line
-- about it ("door opened, 1 person came in and took a blue can"), and the line
-- comes here with its snapshot through api/store_camera.php. The report page
-- (modules/operations/store_camera.php) shows the hours before the report time,
-- with the quiet stretches as "No events". Safe to run again.
--
-- A camera is entered as on HR -> Attendance Cameras: IP address, verification code,
-- RTSP port and stream path; the program gets the rtsp:// address built from them.

CREATE TABLE IF NOT EXISTS store_cameras (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  ip_address VARCHAR(64) NOT NULL,                -- or its network name, e.g. C8c_BH5929142_EZVIZ.mshome.net
  username VARCHAR(64) NOT NULL DEFAULT 'admin',
  verification_code VARCHAR(64) NOT NULL,         -- the 6 letters on the sticker: never shown again
  rtsp_port SMALLINT UNSIGNED NOT NULL DEFAULT 554,
  stream_path VARCHAR(100) NOT NULL DEFAULT '/H.264',
  report_time TIME NOT NULL DEFAULT '16:00:00',   -- the report is ready at this time each day
  hours_back TINYINT UNSIGNED NOT NULL DEFAULT 12, -- and covers this many hours before it
  active TINYINT(1) NOT NULL DEFAULT 1,           -- off: the laptop program leaves it alone
  last_seen_at DATETIME NULL,                     -- the laptop program last asked for its cameras
  live_wanted_at DATETIME NULL,                   -- someone has the Live tab open: the program sends pictures while this is recent
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS store_camera_events (
  id INT AUTO_INCREMENT PRIMARY KEY,
  camera_id INT NOT NULL,
  started_at DATETIME NOT NULL,
  ended_at DATETIME NOT NULL,
  description VARCHAR(500) NOT NULL,
  snapshot VARCHAR(255) NULL,                     -- under uploads/store_camera_events/, never served directly
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_store_camera_events_time (camera_id, started_at),
  CONSTRAINT fk_store_camera_events_camera FOREIGN KEY (camera_id) REFERENCES store_cameras(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Added after the first version: the Live tab.
ALTER TABLE store_cameras ADD COLUMN IF NOT EXISTS live_wanted_at DATETIME NULL AFTER last_seen_at;

-- Added later: "Count items" on the Live tab. The page asks (status waiting); the laptop
-- program sees it on its next look, has Claude count the main items in the current
-- picture, and sends the list back (done), or why it could not (failed). An estimate.
CREATE TABLE IF NOT EXISTS store_camera_counts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  camera_id INT NOT NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'waiting',  -- waiting / done / failed
  requested_by INT NULL,
  requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  counted_at DATETIME NULL,
  items TEXT NULL,                                -- JSON: [{"item": "Plastic jerry can", "count": 27}, ...]
  note VARCHAR(500) NULL,                         -- what could not be counted, or why it failed
  snapshot VARCHAR(255) NULL,                     -- the picture that was counted, under uploads/store_camera_events/
  KEY idx_store_camera_counts_camera (camera_id, requested_at),
  CONSTRAINT fk_store_camera_counts_camera FOREIGN KEY (camera_id) REFERENCES store_cameras(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Compare two times: each side is a count of the picture at that time (at_time; empty = now).
-- The laptop program sends the picture (read back from the camera's recording for a past time);
-- saifsys asks Reem to count it, as the person who asked. Statuses: waiting -> counting -> done / failed.
CREATE TABLE IF NOT EXISTS store_camera_compares (
  id INT AUTO_INCREMENT PRIMARY KEY,
  camera_id INT NOT NULL,
  requested_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_store_camera_compares_camera (camera_id, id),
  CONSTRAINT fk_store_camera_compares_camera FOREIGN KEY (camera_id) REFERENCES store_cameras(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE store_camera_counts ADD COLUMN IF NOT EXISTS at_time DATETIME NULL AFTER requested_at;
ALTER TABLE store_camera_counts ADD COLUMN IF NOT EXISTS compare_id INT NULL AFTER at_time;
ALTER TABLE store_camera_counts ADD COLUMN IF NOT EXISTS side VARCHAR(4) NULL AFTER compare_id; -- 'from' / 'to'

-- The reference picture: "Use live picture as reference" on the Live tab keeps the live picture
-- on screen at that moment (one row per press) and points the camera at it. Nothing else is kept.
ALTER TABLE store_cameras ADD COLUMN IF NOT EXISTS reference_photo_id INT NULL AFTER hours_back;
CREATE TABLE IF NOT EXISTS store_camera_photos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  camera_id INT NOT NULL,
  taken_at DATETIME NOT NULL,                     -- the camera's local time
  path VARCHAR(255) NOT NULL,                     -- under uploads/store_camera_events/photos/, never served directly
  KEY idx_store_camera_photos_time (camera_id, taken_at),
  CONSTRAINT fk_store_camera_photos_camera FOREIGN KEY (camera_id) REFERENCES store_cameras(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Checks, alerts and the starting stock (all set per camera on the Cameras tab).
--   check_every_hours: a check every N hours (0 = off), only between check_from and check_to
--     (both empty = all day), and only when something moved since the last one (check_only_on_movement).
--     At a check the laptop program sends one picture; Reem counts it against the reference picture
--     and its item list (reference_items, JSON [{"item": "Jerry can", "count": 30}, ...]).
--   check_keep_days: check pictures older than this are deleted (the results stay).
--   alert_*: what puts a line in the Operations bell; stopped_after_minutes: how long the laptop
--     program may be silent before "not running" is rung.
ALTER TABLE store_cameras ADD COLUMN IF NOT EXISTS check_every_hours TINYINT UNSIGNED NOT NULL DEFAULT 2 AFTER reference_photo_id;
ALTER TABLE store_cameras ADD COLUMN IF NOT EXISTS check_from TIME NULL AFTER check_every_hours;
ALTER TABLE store_cameras ADD COLUMN IF NOT EXISTS check_to TIME NULL AFTER check_from;
ALTER TABLE store_cameras ADD COLUMN IF NOT EXISTS check_only_on_movement TINYINT(1) NOT NULL DEFAULT 1 AFTER check_to;
ALTER TABLE store_cameras ADD COLUMN IF NOT EXISTS check_keep_days SMALLINT UNSIGNED NOT NULL DEFAULT 30 AFTER check_only_on_movement;
ALTER TABLE store_cameras ADD COLUMN IF NOT EXISTS last_check_at DATETIME NULL AFTER check_keep_days;
ALTER TABLE store_cameras ADD COLUMN IF NOT EXISTS reference_items TEXT NULL AFTER last_check_at;
ALTER TABLE store_cameras ADD COLUMN IF NOT EXISTS alert_movement TINYINT(1) NOT NULL DEFAULT 1 AFTER reference_items;
ALTER TABLE store_cameras ADD COLUMN IF NOT EXISTS alert_count TINYINT(1) NOT NULL DEFAULT 1 AFTER alert_movement;
ALTER TABLE store_cameras ADD COLUMN IF NOT EXISTS alert_stopped TINYINT(1) NOT NULL DEFAULT 1 AFTER alert_count;
ALTER TABLE store_cameras ADD COLUMN IF NOT EXISTS stopped_after_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 15 AFTER alert_stopped;
ALTER TABLE store_cameras ADD COLUMN IF NOT EXISTS stopped_alert_at DATETIME NULL AFTER stopped_after_minutes;
-- A count is a check (scheduled, or "Count now"); skipped = nothing moved since the last one.
ALTER TABLE store_camera_counts ADD COLUMN IF NOT EXISTS is_check TINYINT(1) NOT NULL DEFAULT 0 AFTER side;
ALTER TABLE store_camera_counts ADD COLUMN IF NOT EXISTS manual TINYINT(1) NOT NULL DEFAULT 0 AFTER is_check;

-- The Operations bell: one row per alert. Who has seen what is kept in each browser.
CREATE TABLE IF NOT EXISTS store_camera_alerts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  camera_id INT NOT NULL,
  kind VARCHAR(10) NOT NULL,                      -- movement / count / stopped
  title VARCHAR(200) NOT NULL,
  detail VARCHAR(500) NULL,
  url VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_store_camera_alerts_time (created_at),
  CONSTRAINT fk_store_camera_alerts_camera FOREIGN KEY (camera_id) REFERENCES store_cameras(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pins on the reference picture: where each item stands, pointed out by hand on the Live tab.
-- JSON [{"x": 41.5, "y": 62.0, "item": "Jerry can", "count": 12}, ...] (x, y in % of the picture).
-- The starting list (reference_items) is their total per item; Reem gets the picture with the
-- numbered pins drawn on it, so it knows which item is where.
ALTER TABLE store_cameras ADD COLUMN IF NOT EXISTS reference_points TEXT NULL AFTER reference_items;

-- Free comparison: a check now compares the picture with the reference picture on the server (no Reem,
-- no cost) and keeps the result here: JSON {"pct": 6.2, "boxes": [[x0,y0,x1,y1] in %], "pins": [{"no": 1,
-- "item": "Jerry can", "count": 12, "pct": 41.0, "changed": true, "now": 9}]}. "now" is filled only when
-- someone presses "Ask how many" on a pin: Reem counts that pin's cut-out with its smallest model.
ALTER TABLE store_camera_counts ADD COLUMN IF NOT EXISTS diff TEXT NULL AFTER items;
