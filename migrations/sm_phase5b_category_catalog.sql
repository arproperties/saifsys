-- Phase 5b — category catalog settings + multi-service bookings
ALTER TABLE sm_service_categories
  ADD COLUMN IF NOT EXISTS materials_rate_per_hour DECIMAL(10,2) NOT NULL DEFAULT 0.00
  COMMENT 'Cleaning only: AED per hour per worker for materials surcharge';

ALTER TABLE make_order
  ADD COLUMN IF NOT EXISTS booking_categories JSON NULL
  COMMENT 'JSON array of sm_service_categories.id when multiple services in one order';
