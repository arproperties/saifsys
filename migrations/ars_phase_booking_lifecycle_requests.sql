-- ARS Guest booking lifecycle requests

CREATE TABLE IF NOT EXISTS ars_booking_lifecycle_requests (
    id INT(11) NOT NULL AUTO_INCREMENT,
    company_id INT(11) NOT NULL,
    booking_id INT(11) NOT NULL,
    guest_id INT(11) NOT NULL,
    request_type ENUM('cancellation','extension','early_checkin','late_checkout','support') NOT NULL,
    status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
    guest_note TEXT DEFAULT NULL,
    admin_note TEXT DEFAULT NULL,
    requested_check_in DATE DEFAULT NULL,
    requested_check_out DATE DEFAULT NULL,
    requested_time VARCHAR(20) DEFAULT NULL,
    requested_nights INT(11) DEFAULT NULL,
    contact_subject VARCHAR(255) DEFAULT NULL,
    payload_json JSON DEFAULT NULL,
    reviewed_by INT(11) DEFAULT NULL,
    reviewed_at DATETIME DEFAULT NULL,
    cancelled_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ars_req_booking (company_id, booking_id, created_at),
    KEY idx_ars_req_guest (company_id, guest_id, created_at),
    KEY idx_ars_req_status (company_id, status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
        'request_cancelled'
    ) NOT NULL;
