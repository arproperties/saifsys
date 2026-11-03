-- Let attendance rows say they came from the entrance camera
--
-- Why: the camera PC (tools/attendance_camera/) sends face matches to
-- api/attendance_camera.php, which writes the day's first and last sighting
-- as check-in and check-out with source = 'camera'. HR -> Attendance filters
-- and badges on that value ("Recorded by: camera").
--
-- Safe to re-run: MODIFY to the same definition is a no-op. Existing values
-- (admin, self, import) are kept as they are.

ALTER TABLE attendance
  MODIFY `source` enum('admin','self','import','camera') NOT NULL DEFAULT 'admin';
