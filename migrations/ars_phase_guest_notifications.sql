-- ARS Guest in-app notifications (phase: guest notifications)

CREATE TABLE IF NOT EXISTS ars_guest_notifications (
    id INT(11) NOT NULL AUTO_INCREMENT,
    company_id INT(11) NOT NULL,
    guest_id INT(11) NOT NULL,
    booking_id INT(11) DEFAULT NULL,
    payment_id INT(11) DEFAULT NULL,
    event_type ENUM(
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
        'request_cancelled'
    ) NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT DEFAULT NULL,
    cta_route VARCHAR(255) DEFAULT NULL,
    meta_json JSON DEFAULT NULL,
    is_placeholder TINYINT(1) NOT NULL DEFAULT 0,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    read_at DATETIME DEFAULT NULL,
    scheduled_for DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ars_guest_notif_guest_created (company_id, guest_id, created_at),
    KEY idx_ars_guest_notif_guest_unread (company_id, guest_id, is_read),
    KEY idx_ars_guest_notif_booking_event (booking_id, event_type),
    KEY idx_ars_guest_notif_schedule (is_placeholder, scheduled_for)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
