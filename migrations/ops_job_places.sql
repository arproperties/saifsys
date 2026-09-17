-- Operations — a job happens in real places, not in a sentence.
--
-- `ops_jobs.location` was free text, and the jobs already on the system show
-- why that is not enough: "Lobby + Service Area + Podium", "Office 104 + 122",
-- "Pool + Gym", "Podium + Corrider 1 to 5". One job, several places, units and
-- common areas mixed together — and nothing that can count any of it. You
-- could not ask what was cleaned in Park Place last month, because the answer
-- was spread across four spellings of "corridor".
--
-- So a job carries a list of places instead. Each row is one place: a unit
-- (re_units) or a common area (re_building_common_areas), both of which hang
-- off a building. A job covering a flat and two corridors is three rows.
--
-- WHY THE NAME IS STORED AS WELL AS THE ID
-- ----------------------------------------
-- The id is the link; the label is what happened. A unit renumbered or a
-- common area renamed next year must not silently rewrite what a job last
-- March says it cleaned — the same reason the old module keeps `worker_name`
-- next to the `order_workers` rows. Reports join on the id. People read the
-- label.
--
-- The unique key is the rule that one place cannot be on one job twice, which
-- is otherwise very easy to do in a picker with a search box.
--
-- `location` is deliberately left on ops_jobs. It holds the free text of every
-- job raised before this, and there is nothing to gain from destroying it.

CREATE TABLE IF NOT EXISTS `ops_job_places` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `job_id` INT(11) NOT NULL,
  `company_id` INT(11) NOT NULL COMMENT 'Copied from the job, so place queries need no join to scope',
  `place_kind` ENUM('unit','common_area') NOT NULL,
  `place_id` INT(11) NOT NULL COMMENT 're_units.id or re_building_common_areas.id, per place_kind',
  `building_id` INT(11) DEFAULT NULL COMMENT 're_buildings.id — the grouping both kinds share',
  `label` VARCHAR(255) NOT NULL COMMENT 'What it was called when the job was done',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_ops_job_place` (`job_id`, `place_kind`, `place_id`),
  KEY `idx_ops_job_places_job` (`job_id`),
  KEY `idx_ops_job_places_place` (`place_kind`, `place_id`),
  KEY `idx_ops_job_places_building` (`building_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
