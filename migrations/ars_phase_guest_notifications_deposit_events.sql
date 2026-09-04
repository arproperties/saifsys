-- Extend guest notification event types for security deposit flows

ALTER TABLE ars_guest_notifications
    MODIFY event_type ENUM(
        'booking_created',
        'booking_confirmed',
        'payment_succeeded',
        'payment_failed',
        'booking_cancelled',
        'booking_expired',
        'reminder_checkin',
        'reminder_checkout',
        'request_approved',
        'request_rejected',
        'request_cancelled',
        'deposit_required',
        'deposit_received',
        'deposit_refunded',
        'deposit_cash_selected'
    ) NOT NULL;
