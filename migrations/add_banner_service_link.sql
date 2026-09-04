-- Add service link option to promotional banners
ALTER TABLE `promotional_banners` 
ADD COLUMN `link_type` ENUM('url', 'service') DEFAULT 'url' AFTER `link_url`,
ADD COLUMN `service_id` INT(11) DEFAULT NULL AFTER `link_type`,
ADD INDEX `idx_service_id` (`service_id`),
ADD CONSTRAINT `fk_banner_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;
