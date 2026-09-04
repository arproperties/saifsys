-- ARS guest booking documents (PDF cache metadata)

CREATE TABLE IF NOT EXISTS ars_booking_documents (
    id INT(11) NOT NULL AUTO_INCREMENT,
    company_id INT(11) NOT NULL,
    booking_id INT(11) NOT NULL,
    guest_id INT(11) NOT NULL,
    doc_type ENUM('booking_confirmation','payment_receipt','tax_invoice') NOT NULL,
    payment_id INT(11) DEFAULT NULL,
    file_token VARCHAR(64) NOT NULL,
    content_hash VARCHAR(64) NOT NULL,
    file_size INT(11) NOT NULL DEFAULT 0,
    mime_type VARCHAR(80) NOT NULL DEFAULT 'application/pdf',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ars_doc_token (file_token),
    UNIQUE KEY uq_ars_doc_booking_type_payment (booking_id, doc_type, payment_id),
    KEY idx_ars_doc_guest (guest_id, booking_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
