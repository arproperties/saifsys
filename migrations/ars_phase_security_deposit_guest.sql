-- ARS security deposit: guest online/cash + Stripe payment_type
ALTER TABLE ars_bookings
  ADD COLUMN IF NOT EXISTS deposit_payment_method VARCHAR(30) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS deposit_stripe_payment_id INT(11) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS deposit_cash_requested TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE ars_booking_payments
  MODIFY payment_type ENUM('manual','full','deposit','balance','partial','security_deposit') NOT NULL DEFAULT 'manual';
