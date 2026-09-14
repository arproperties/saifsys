-- Pickup points and routes for the Driver app. Run AFTER fleet_tracking.sql.
--
-- The office sets it up once:
--   pickup point  a place (Mall of Emirates, a manager's home) + who is picked up there
--   route         the points in order with times, on one vehicle
--
-- When that vehicle's driver presses Start, the route is copied onto the trip
-- (fleet_trip_stops). Before 12:00 it runs as the morning pickup; after 12:00
-- as the evening drop-off, stops reversed. The server marks each stop reached
-- from the GPS points. The driver ticks nothing.

CREATE TABLE IF NOT EXISTS `fleet_pickup_points` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `lat` DECIMAL(10,7) NOT NULL,
  `lng` DECIMAL(10,7) NOT NULL,
  `radius_m` SMALLINT(6) NOT NULL DEFAULT 150 COMMENT 'Inside this = the van has reached the point',
  `notes` VARCHAR(255) NULL,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` INT(11) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_fleet_pickup_points_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Each employee has at most one pickup point (the primary key says so).
CREATE TABLE IF NOT EXISTS `fleet_point_employees` (
  `employee_id` INT(11) NOT NULL,
  `point_id` INT(11) NOT NULL,
  `assigned_by` INT(11) NULL,
  `assigned_at` DATETIME NULL,
  PRIMARY KEY (`employee_id`),
  KEY `idx_fleet_point_employees_point` (`point_id`),
  CONSTRAINT `fk_fleet_point_employees_point`
    FOREIGN KEY (`point_id`) REFERENCES `fleet_pickup_points`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `fleet_routes` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `vehicle_id` INT(11) NULL COMMENT 'One active route per vehicle, checked on save',
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` INT(11) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_fleet_routes_vehicle` (`vehicle_id`, `status`),
  CONSTRAINT `fk_fleet_routes_vehicle`
    FOREIGN KEY (`vehicle_id`) REFERENCES `fleet_vehicles`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `fleet_route_stops` (
  `route_id` INT(11) NOT NULL,
  `stop_order` SMALLINT(6) NOT NULL,
  `point_id` INT(11) NOT NULL,
  `pickup_time` TIME NULL COMMENT 'Morning',
  `dropoff_time` TIME NULL COMMENT 'Evening',
  PRIMARY KEY (`route_id`, `stop_order`),
  KEY `idx_fleet_route_stops_point` (`point_id`),
  CONSTRAINT `fk_fleet_route_stops_route`
    FOREIGN KEY (`route_id`) REFERENCES `fleet_routes`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_fleet_route_stops_point`
    FOREIGN KEY (`point_id`) REFERENCES `fleet_pickup_points`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- The route as it was on the day: copied at Start, so editing a route later
-- never rewrites a past trip.
CREATE TABLE IF NOT EXISTS `fleet_trip_stops` (
  `trip_id` INT(11) NOT NULL,
  `stop_order` SMALLINT(6) NOT NULL,
  `point_id` INT(11) NULL,
  `name` VARCHAR(100) NOT NULL,
  `lat` DECIMAL(10,7) NOT NULL,
  `lng` DECIMAL(10,7) NOT NULL,
  `radius_m` SMALLINT(6) NOT NULL,
  `planned_time` TIME NULL,
  `arrived_at` DATETIME NULL COMMENT 'First GPS point inside the radius',
  `left_at` DATETIME NULL COMMENT 'First GPS point back outside it',
  PRIMARY KEY (`trip_id`, `stop_order`),
  CONSTRAINT `fk_fleet_trip_stops_trip`
    FOREIGN KEY (`trip_id`) REFERENCES `fleet_trips`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `fleet_trips`
  ADD COLUMN IF NOT EXISTS `route_id` INT(11) NULL AFTER `driver_name`,
  ADD COLUMN IF NOT EXISTS `route_name` VARCHAR(100) NULL AFTER `route_id`,
  ADD COLUMN IF NOT EXISTS `direction` ENUM('pickup','dropoff') NULL AFTER `route_name`;
