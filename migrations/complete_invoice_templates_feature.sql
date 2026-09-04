-- Complete Invoice Templates Feature - Database Migration
-- Run this SQL file on your live database to add the entire invoice templates system

-- Create invoice_templates table
CREATE TABLE IF NOT EXISTS invoice_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL DEFAULT 'Default Template',
    is_default TINYINT(1) DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    primary_color VARCHAR(7) DEFAULT '#0b2a4a',
    accent_color VARCHAR(7) DEFAULT '#e53935',
    background_color VARCHAR(7) DEFAULT '#ffffff',
    text_color VARCHAR(7) DEFAULT '#333333',
    border_color VARCHAR(7) DEFAULT '#e6e7eb',
    show_company_name TINYINT(1) DEFAULT 1,
    show_logo TINYINT(1) DEFAULT 1,
    header_layout ENUM('logo_left', 'logo_center', 'logo_right') DEFAULT 'logo_left',
    invoice_title VARCHAR(50) DEFAULT 'TAX INVOICE',
    show_invoice_number TINYINT(1) DEFAULT 1,
    show_invoice_date TINYINT(1) DEFAULT 1,
    show_due_date TINYINT(1) DEFAULT 1,
    show_bill_to TINYINT(1) DEFAULT 1,
    show_company_info TINYINT(1) DEFAULT 1,
    show_bank_details TINYINT(1) DEFAULT 1,
    show_terms TINYINT(1) DEFAULT 1,
    show_signature TINYINT(1) DEFAULT 1,
    signature_path VARCHAR(255) DEFAULT NULL,
    table_header_bg VARCHAR(7) DEFAULT '#eef0f3',
    table_stripe_bg VARCHAR(7) DEFAULT '#f5f6f8',
    table_border_color VARCHAR(7) DEFAULT '#dee2e6',
    footer_text VARCHAR(255) DEFAULT 'Thank you for your business',
    show_amount_in_words TINYINT(1) DEFAULT 1,
    font_family VARCHAR(100) DEFAULT 'system-ui, -apple-system, Segoe UI, Roboto, "Helvetica Neue", Arial, sans-serif',
    font_size_base VARCHAR(10) DEFAULT '14px',
    font_size_title VARCHAR(10) DEFAULT '24px',
    font_size_header VARCHAR(10) DEFAULT '18px',
    page_margin VARCHAR(10) DEFAULT '28px',
    section_spacing VARCHAR(10) DEFAULT '20px',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Insert default template
INSERT IGNORE INTO invoice_templates (
    name, is_default, primary_color, accent_color, background_color, text_color, border_color, 
    show_company_name, show_logo, header_layout, invoice_title, show_invoice_number, show_invoice_date, 
    show_due_date, show_bill_to, show_company_info, show_bank_details, show_terms, show_signature, 
    table_header_bg, table_stripe_bg, table_border_color, footer_text, show_amount_in_words, 
    font_family, font_size_base, font_size_title, font_size_header, page_margin, section_spacing
) VALUES (
    'Default Template', 1, '#0b2a4a', '#e53935', '#ffffff', '#333333', '#e6e7eb', 
    1, 1, 'logo_left', 'TAX INVOICE', 1, 1, 1, 1, 1, 1, 1, 1, 
    '#eef0f3', '#f5f6f8', '#dee2e6', 'Thank you for your business', 1, 
    'system-ui, -apple-system, Segoe UI, Roboto, "Helvetica Neue", Arial, sans-serif', 
    '14px', '24px', '18px', '28px', '20px'
);

-- Verify the table was created
DESCRIBE invoice_templates;
