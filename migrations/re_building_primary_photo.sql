-- Building primary photo (ONE image per building)
-- Reusable by Customer App, Owner App, Website, AI, dashboards.
-- Apply via schema sync / DBA; ensure_* also adds columns on page load.

ALTER TABLE `re_buildings`
  ADD COLUMN IF NOT EXISTS `primary_photo_path` VARCHAR(500) NULL DEFAULT NULL
    COMMENT 'Relative path e.g. uploads/realestate/buildings/{id}/primary_....jpg'
    AFTER `facilities_notes`;

ALTER TABLE `re_buildings`
  ADD COLUMN IF NOT EXISTS `primary_photo_mime` VARCHAR(100) NULL DEFAULT NULL
    AFTER `primary_photo_path`;

ALTER TABLE `re_buildings`
  ADD COLUMN IF NOT EXISTS `primary_photo_updated_at` DATETIME NULL DEFAULT NULL
    AFTER `primary_photo_mime`;
