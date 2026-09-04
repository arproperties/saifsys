-- Unified Customer Firebase Push Notifications — Step 2 Automatic Triggers
-- Adds automatic guest document/receipt event types used by ARS push triggers.

ALTER TABLE `ars_guest_notifications`
  MODIFY `event_type` ENUM(
    'booking_created',
    'booking_confirmed',
    'payment_succeeded',
    'payment_failed',
    'booking_cancelled',
    'booking_expired',
    'document_available',
    'receipt_available',
    'reminder_checkin',
    'reminder_checkout',
    'request_approved',
    'request_rejected',
    'request_cancelled',
    'deposit_required',
    'deposit_received',
    'deposit_refunded',
    'deposit_cash_selected',
    'manual_general',
    'manual_booking',
    'manual_payment',
    'manual_lifecycle_request',
    'manual_document',
    'manual_receipt',
    'manual_checkin',
    'manual_checkout'
  ) NOT NULL;
