-- Operations field app API — replay protection.
--
-- The app never sends a write straight to the server: it queues it and retries
-- until the server confirms. That is what makes the app usable in a stairwell,
-- and it has one failure mode — a request that SUCCEEDS but whose response is
-- lost on the way back. The phone never hears the confirmation, so it sends the
-- same thing again, and the job ends up with two of everything.
--
-- Each queued item carries a stable id for its whole life, sent as
-- X-Ops-Request-Id. The first time the server sees one it records it here; a
-- repeat is recognised and answered with the current state instead of being
-- applied a second time.
--
-- Rows are disposable: they only need to outlive the queue's retry window.
-- api/mobile/ops/ops_api.php prunes anything older than 7 days.

CREATE TABLE IF NOT EXISTS `ops_api_requests` (
  `request_id` VARCHAR(64) NOT NULL COMMENT 'Client-generated, stable across retries',
  `user_id` INT(11) NOT NULL,
  `route` VARCHAR(191) NOT NULL COMMENT 'What was being done, for tracing only',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`request_id`),
  KEY `idx_ops_api_requests_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
