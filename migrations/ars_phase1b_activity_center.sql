-- ============================================================================
-- ARS Phase 1B — Booking Activity Center foundations
-- Additive only. Safe for re-run (IF NOT EXISTS / information_schema guards).
-- Does NOT create Option B financial document tables.
-- Does NOT modify accounting_engine, re_invoices, re_leases, re_tenants.
-- Live: do not run without separate human approval.
-- ============================================================================

SET @db := DATABASE();

-- ---------------------------------------------------------------------------
-- 1. Additive columns on ars_booking_activities
-- ---------------------------------------------------------------------------
SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'ars_booking_activities' AND COLUMN_NAME = 'source'
    ),
    'SELECT 1',
    "ALTER TABLE `ars_booking_activities`
       ADD COLUMN `source` VARCHAR(40) NOT NULL DEFAULT 'system'
         COMMENT 'user|system|guest|backfill|import'
         AFTER `status`"
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'ars_booking_activities' AND COLUMN_NAME = 'is_backfill'
    ),
    'SELECT 1',
    "ALTER TABLE `ars_booking_activities`
       ADD COLUMN `is_backfill` TINYINT(1) NOT NULL DEFAULT 0
         COMMENT '1 = conservative historical snapshot, not live workflow evidence'
         AFTER `source`"
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'ars_booking_activities' AND COLUMN_NAME = 'dedupe_key'
    ),
    'SELECT 1',
    "ALTER TABLE `ars_booking_activities`
       ADD COLUMN `dedupe_key` VARCHAR(120) DEFAULT NULL
         COMMENT 'Optional idempotency key to prevent duplicate events'
         AFTER `is_backfill`"
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'ars_booking_activities' AND INDEX_NAME = 'uq_ars_act_dedupe'
    ),
    'SELECT 1',
    'ALTER TABLE `ars_booking_activities` ADD UNIQUE KEY `uq_ars_act_dedupe` (`booking_id`, `dedupe_key`)'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 2. Conservative historical backfill (system snapshots only)
--    Uses dedupe_key so re-runs are idempotent. Does not invent users/timestamps
--    beyond evidence on ars_bookings / payments / journals / documents.
-- ---------------------------------------------------------------------------

-- Booking existed snapshot
INSERT IGNORE INTO `ars_booking_activities`
  (company_id, booking_id, booking_number, event_category, event_type, title, description,
   previous_value, new_value, related_entity_type, related_entity_id, related_document_number,
   related_journal_id, status, source, is_backfill, dedupe_key, created_by, created_at)
SELECT
  b.company_id, b.id, b.booking_number, 'system', 'historical_snapshot',
  'Booking existed before Activity Center',
  'Conservative historical snapshot imported for Activity Center. Exact intermediate lifecycle steps are not inferred.',
  NULL, b.status, 'ars_booking', b.id, b.booking_number, NULL, b.status,
  'backfill', 1, CONCAT('backfill:existed:', b.id), NULL,
  COALESCE(b.created_at, NOW())
FROM `ars_bookings` b
WHERE NOT EXISTS (
  SELECT 1 FROM `ars_booking_activities` a
  WHERE a.booking_id = b.id AND a.dedupe_key = CONCAT('backfill:existed:', b.id)
);

-- Current operational status snapshot
INSERT IGNORE INTO `ars_booking_activities`
  (company_id, booking_id, booking_number, event_category, event_type, title, description,
   previous_value, new_value, related_entity_type, related_entity_id, related_document_number,
   related_journal_id, status, source, is_backfill, dedupe_key, created_by, created_at)
SELECT
  b.company_id, b.id, b.booking_number, 'operational', 'status_snapshot',
  CONCAT('Current status: ', REPLACE(b.status, '_', ' ')),
  'Snapshot of operational status at Activity Center enablement. Intermediate transitions were not reconstructed.',
  NULL, b.status, 'ars_booking', b.id, b.booking_number, NULL, b.status,
  'backfill', 1, CONCAT('backfill:status:', b.id, ':', b.status), NULL,
  COALESCE(b.updated_at, b.created_at, NOW())
FROM `ars_bookings` b
WHERE NOT EXISTS (
  SELECT 1 FROM `ars_booking_activities` a
  WHERE a.booking_id = b.id AND a.dedupe_key = CONCAT('backfill:status:', b.id, ':', b.status)
);

-- Existing revenue journal reference
INSERT IGNORE INTO `ars_booking_activities`
  (company_id, booking_id, booking_number, event_category, event_type, title, description,
   previous_value, new_value, related_entity_type, related_entity_id, related_document_number,
   related_journal_id, status, source, is_backfill, dedupe_key, created_by, created_at)
SELECT
  b.company_id, b.id, b.booking_number, 'accounting', 'journal_reference',
  'Existing revenue journal reference',
  'Historical journal link preserved for Activity Center deep-linking. Amounts were not rewritten.',
  NULL, CAST(b.journal_id AS CHAR), 're_journal_header', b.journal_id,
  (SELECT h.journal_number FROM re_journal_headers h WHERE h.id = b.journal_id LIMIT 1),
  b.journal_id, 'posted',
  'backfill', 1, CONCAT('backfill:journal:', b.id, ':', b.journal_id), NULL,
  COALESCE((SELECT h.posted_at FROM re_journal_headers h WHERE h.id = b.journal_id LIMIT 1), b.updated_at, NOW())
FROM `ars_bookings` b
WHERE b.journal_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM `ars_booking_activities` a
    WHERE a.booking_id = b.id AND a.dedupe_key = CONCAT('backfill:journal:', b.id, ':', b.journal_id)
  );

-- Existing payment references
INSERT IGNORE INTO `ars_booking_activities`
  (company_id, booking_id, booking_number, event_category, event_type, title, description,
   previous_value, new_value, related_entity_type, related_entity_id, related_document_number,
   related_journal_id, status, source, is_backfill, dedupe_key, created_by, created_at)
SELECT
  p.company_id, p.booking_id, b.booking_number, 'payment', 'payment_reference',
  'Existing payment reference',
  CONCAT('Historical payment of ', FORMAT(p.amount, 2), ' via ', COALESCE(p.payment_method, 'unknown'), '.'),
  NULL, FORMAT(p.amount, 2), 'ars_booking_payment', p.id, p.reference_number, p.journal_id, 'recorded',
  'backfill', 1, CONCAT('backfill:payment:', p.id), p.recorded_by,
  COALESCE(CONCAT(p.payment_date, ' 12:00:00'), p.created_at, NOW())
FROM `ars_booking_payments` p
JOIN `ars_bookings` b ON b.id = p.booking_id AND b.company_id = p.company_id
WHERE NOT EXISTS (
  SELECT 1 FROM `ars_booking_activities` a
  WHERE a.booking_id = p.booking_id AND a.dedupe_key = CONCAT('backfill:payment:', p.id)
);

-- Financial lock state snapshot
INSERT IGNORE INTO `ars_booking_activities`
  (company_id, booking_id, booking_number, event_category, event_type, title, description,
   previous_value, new_value, related_entity_type, related_entity_id, related_document_number,
   related_journal_id, status, source, is_backfill, dedupe_key, created_by, created_at)
SELECT
  b.company_id, b.id, b.booking_number, 'financial', 'financial_lock_snapshot',
  'Financial lock state (historical)',
  COALESCE(b.financial_lock_reason, 'Booking was financially locked before Activity Center UI.'),
  'draft', COALESCE(b.financial_status, 'invoice_created'), 'ars_booking', b.id, b.booking_number, NULL,
  COALESCE(b.financial_status, 'locked'),
  'backfill', 1, CONCAT('backfill:lock:', b.id), b.financial_locked_by,
  COALESCE(b.financial_locked_at, b.updated_at, NOW())
FROM `ars_bookings` b
WHERE b.is_financially_locked = 1
  AND NOT EXISTS (
    SELECT 1 FROM `ars_booking_activities` a
    WHERE a.booking_id = b.id AND a.dedupe_key = CONCAT('backfill:lock:', b.id)
  );

-- Existing deposit journal reference
INSERT IGNORE INTO `ars_booking_activities`
  (company_id, booking_id, booking_number, event_category, event_type, title, description,
   previous_value, new_value, related_entity_type, related_entity_id, related_document_number,
   related_journal_id, status, source, is_backfill, dedupe_key, created_by, created_at)
SELECT
  b.company_id, b.id, b.booking_number, 'financial', 'deposit_journal_reference',
  'Existing security deposit journal',
  CONCAT('Deposit amount ', FORMAT(COALESCE(b.deposit_amount,0), 2), '; status ', COALESCE(b.deposit_status,'none'), '.'),
  NULL, CAST(b.deposit_journal_id AS CHAR), 're_journal_header', b.deposit_journal_id,
  (SELECT h.journal_number FROM re_journal_headers h WHERE h.id = b.deposit_journal_id LIMIT 1),
  b.deposit_journal_id, COALESCE(b.deposit_status, 'received'),
  'backfill', 1, CONCAT('backfill:deposit_jv:', b.id, ':', b.deposit_journal_id), NULL,
  COALESCE(b.deposit_received_date, DATE(b.updated_at), CURDATE())
FROM `ars_bookings` b
WHERE b.deposit_journal_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM `ars_booking_activities` a
    WHERE a.booking_id = b.id AND a.dedupe_key = CONCAT('backfill:deposit_jv:', b.id, ':', b.deposit_journal_id)
  );

-- Existing booking documents (if table present)
SET @has_docs := (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'ars_booking_documents'
);
SET @sql := IF(@has_docs > 0,
  "INSERT IGNORE INTO `ars_booking_activities`
    (company_id, booking_id, booking_number, event_category, event_type, title, description,
     previous_value, new_value, related_entity_type, related_entity_id, related_document_number,
     related_journal_id, status, source, is_backfill, dedupe_key, created_by, created_at)
  SELECT
    b.company_id, d.booking_id, b.booking_number, 'documents', 'document_reference',
    CONCAT('Existing document: ', COALESCE(d.doc_type, 'document')),
    'Historical document reference imported for Activity Center.',
    NULL, COALESCE(d.doc_type, 'document'), 'ars_booking_document', d.id, d.doc_type, NULL, 'available',
    'backfill', 1, CONCAT('backfill:doc:', d.id), NULL,
    COALESCE(d.created_at, NOW())
  FROM `ars_booking_documents` d
  JOIN `ars_bookings` b ON b.id = d.booking_id
  WHERE NOT EXISTS (
    SELECT 1 FROM `ars_booking_activities` a
    WHERE a.booking_id = d.booking_id AND a.dedupe_key = CONCAT('backfill:doc:', d.id)
  )",
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT 'ARS Phase 1B Activity Center migration complete.' AS notice;
