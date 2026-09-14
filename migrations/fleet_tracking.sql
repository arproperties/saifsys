-- Vehicle tracking — HR Fleet pages + the Driver app.
--
-- A trip belongs to a VEHICLE. The driver is written on each trip, so a car
-- keeps one history however many people drive it.
--
-- Sign-in reuses the one mobile PIN in ops_staff_pins (see
-- modules/operations/includes/ops_pin.php). Which apps that PIN opens is
-- decided here, in staff_app_access, one row per person per app.

-- Which company apps a person may open with their PIN.
-- The Operations app does not check this table (any PIN holder uses it, as
-- before). The Driver app requires app = 'driver'. Future apps add their own key.
CREATE TABLE IF NOT EXISTS `staff_app_access` (
  `user_id` INT(11) NOT NULL,
  `app` VARCHAR(30) NOT NULL COMMENT 'driver, …',
  `granted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `granted_by` INT(11) NULL,
  PRIMARY KEY (`user_id`, `app`),
  CONSTRAINT `fk_staff_app_access_user`
    FOREIGN KEY (`user_id`) REFERENCES `user`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `fleet_vehicles` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `plate_no` VARCHAR(30) NOT NULL,
  `name` VARCHAR(100) NULL COMMENT 'Make / model, e.g. Toyota Hiace',
  `vehicle_type` VARCHAR(30) NULL COMMENT 'car, van, pickup, truck, bus, bike',
  `color` VARCHAR(30) NULL,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `notes` VARCHAR(255) NULL,
  `created_by` INT(11) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fleet_vehicles_plate` (`plate_no`),
  KEY `idx_fleet_vehicles_company` (`company_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `fleet_trips` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL COMMENT 'The vehicle''s company when the trip started',
  `vehicle_id` INT(11) NOT NULL,
  `driver_user_id` INT(11) NOT NULL,
  `driver_name` VARCHAR(150) NOT NULL DEFAULT '' COMMENT 'Snapshot, so history survives a renamed or deleted login',
  `started_at` DATETIME NOT NULL,
  `ended_at` DATETIME NULL,
  `ended_by` INT(11) NULL COMMENT 'Office user who ended it from HR; NULL = the driver pressed Stop',
  `distance_m` INT(11) NOT NULL DEFAULT 0,
  `point_count` INT(11) NOT NULL DEFAULT 0,
  `last_lat` DECIMAL(10,7) NULL,
  `last_lng` DECIMAL(10,7) NULL,
  `last_speed_kmh` DECIMAL(6,2) NULL,
  `last_point_at` DATETIME NULL COMMENT 'Phone time of the newest point',
  `last_seen_at` DATETIME NULL COMMENT 'Server time the phone last delivered points',
  -- One open trip per vehicle and per driver, enforced by the database so two
  -- phones pressing Start at the same second cannot both win. NULL once ended,
  -- and NULLs never collide in a UNIQUE index.
  `open_vehicle_id` INT(11) AS (IF(`ended_at` IS NULL, `vehicle_id`, NULL)) STORED,
  `open_driver_id` INT(11) AS (IF(`ended_at` IS NULL, `driver_user_id`, NULL)) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fleet_trips_open_vehicle` (`open_vehicle_id`),
  UNIQUE KEY `uq_fleet_trips_open_driver` (`open_driver_id`),
  KEY `idx_fleet_trips_vehicle` (`vehicle_id`, `started_at`),
  KEY `idx_fleet_trips_driver` (`driver_user_id`, `started_at`),
  KEY `idx_fleet_trips_started` (`started_at`),
  CONSTRAINT `fk_fleet_trips_vehicle`
    FOREIGN KEY (`vehicle_id`) REFERENCES `fleet_vehicles`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `fleet_trip_points` (
  `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
  `trip_id` INT(11) NOT NULL,
  `lat` DECIMAL(10,7) NOT NULL,
  `lng` DECIMAL(10,7) NOT NULL,
  `speed_kmh` DECIMAL(6,2) NULL,
  `heading` SMALLINT(6) NULL,
  `accuracy_m` DECIMAL(7,2) NULL,
  `recorded_at` DATETIME NOT NULL COMMENT 'Phone time of the fix',
  `received_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  -- The phone re-sends a batch whose reply it never heard. This makes the
  -- second copy a no-op (INSERT IGNORE) instead of doubled points and distance.
  UNIQUE KEY `uq_fleet_trip_points_time` (`trip_id`, `recorded_at`),
  CONSTRAINT `fk_fleet_trip_points_trip`
    FOREIGN KEY (`trip_id`) REFERENCES `fleet_trips`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- The last point that counted towards distance. With it, each batch of points
-- updates the trip by reading only its own points, never the whole route —
-- the server's work per send stays the same however long the trip runs.
ALTER TABLE `fleet_trips`
  ADD COLUMN IF NOT EXISTS `dist_anchor_lat` DECIMAL(10,7) NULL AFTER `last_seen_at`,
  ADD COLUMN IF NOT EXISTS `dist_anchor_lng` DECIMAL(10,7) NULL AFTER `dist_anchor_lat`,
  ADD COLUMN IF NOT EXISTS `dist_anchor_at` DATETIME NULL AFTER `dist_anchor_lng`;
