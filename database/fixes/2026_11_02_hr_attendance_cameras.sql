-- The entrance / room cameras the attendance PC watches
--
-- Why: HR -> Administration -> Cameras keeps each camera's room, address and
-- verification code here, and the camera PC (tools/attendance_camera/) reads
-- the list from api/attendance_camera.php?action=cameras. Changing a code or an
-- address in HR then reaches the PC without anyone editing config.ini.
--
-- Safe to re-run: CREATE TABLE IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS hr_attendance_cameras (
  id                INT NOT NULL AUTO_INCREMENT,
  name              VARCHAR(100) NOT NULL,
  ip_address        VARCHAR(64) NOT NULL,
  rtsp_port         SMALLINT UNSIGNED NOT NULL DEFAULT 554,
  stream_path       VARCHAR(100) NOT NULL DEFAULT '/H.264',
  username          VARCHAR(64) NOT NULL DEFAULT 'admin',
  verification_code VARCHAR(64) NOT NULL,
  -- Reported by the camera PC: the name the camera gives itself on the
  -- network (e.g. C8c_BH5929142_EZVIZ = model + serial), the last time video
  -- came through, and the last time the PC reported at all. Dubai time.
  device_name        VARCHAR(100) NULL,
  last_seen_at       DATETIME NULL,
  status_reported_at DATETIME NULL,
  is_active         TINYINT(1) NOT NULL DEFAULT 1,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by        INT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Who the cameras record. Ticked on HR -> Administration -> Cameras -> People.
-- Everyone not in here records attendance on the staff app as before, and the
-- camera API ignores sightings of them.
CREATE TABLE IF NOT EXISTS hr_attendance_camera_people (
  employee_id INT NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by  INT NULL,
  PRIMARY KEY (employee_id),
  CONSTRAINT fk_hr_cam_people_employee FOREIGN KEY (employee_id) REFERENCES employees (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Which Wi-Fi each camera is on, as the camera PC sees it: the network name,
-- its band (2.4 / 5 GHz) and the address the camera has right now (useful when
-- the camera is kept by name). Shown on the Cameras page, never edited.
-- Safe to re-run: ADD COLUMN IF NOT EXISTS.
ALTER TABLE hr_attendance_cameras
  ADD COLUMN IF NOT EXISTS network_name VARCHAR(100) NULL AFTER status_reported_at,
  ADD COLUMN IF NOT EXISTS network_band VARCHAR(60) NULL AFTER network_name,
  ADD COLUMN IF NOT EXISTS current_ip   VARCHAR(45) NULL AFTER network_band;

-- When a camera last recognised each ticked person, and which camera
-- (HR -> Cameras -> People -> Last detected). Every recognition counts, also
-- one that did not change the day. Safe to re-run.
ALTER TABLE hr_attendance_camera_people
  ADD COLUMN IF NOT EXISTS last_detected_at DATETIME NULL AFTER created_by,
  ADD COLUMN IF NOT EXISTS last_camera_id   INT NULL AFTER last_detected_at;
