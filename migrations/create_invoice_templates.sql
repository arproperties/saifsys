-- Create invoice_templates table for customizing invoice layouts and branding
CREATE TABLE IF NOT EXISTS invoice_templates (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL DEFAULT 'Default Template',
    is_default TINYINT(1) DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    
    -- Branding Colors
    primary_color VARCHAR(7) DEFAULT '#0b2a4a',
    accent_color VARCHAR(7) DEFAULT '#e53935',
    background_color VARCHAR(7) DEFAULT '#ffffff',
    text_color VARCHAR(7) DEFAULT '#333333',
    border_color VARCHAR(7) DEFAULT '#e6e7eb',
    
    -- Header Settings
    show_company_name TINYINT(1) DEFAULT 1,
    show_logo TINYINT(1) DEFAULT 1,
    header_layout ENUM('logo_left', 'logo_center', 'logo_right') DEFAULT 'logo_left',
    
    -- Invoice Details
    invoice_title VARCHAR(50) DEFAULT 'TAX INVOICE',
    show_invoice_number TINYINT(1) DEFAULT 1,
    show_invoice_date TINYINT(1) DEFAULT 1,
    show_due_date TINYINT(1) DEFAULT 1,
    
    -- Layout Options
    show_bill_to TINYINT(1) DEFAULT 1,
    show_company_info TINYINT(1) DEFAULT 1,
    show_bank_details TINYINT(1) DEFAULT 1,
    show_terms TINYINT(1) DEFAULT 1,
    show_signature TINYINT(1) DEFAULT 1,
    
    -- Table Styling
    table_header_bg VARCHAR(7) DEFAULT '#eef0f3',
    table_stripe_bg VARCHAR(7) DEFAULT '#f5f6f8',
    table_border_color VARCHAR(7) DEFAULT '#dee2e6',
    
    -- Footer Settings
    footer_text VARCHAR(255) DEFAULT 'Thank you for your business',
    show_amount_in_words TINYINT(1) DEFAULT 1,
    
    -- Font Settings
    font_family VARCHAR(100) DEFAULT 'system-ui, -apple-system, Segoe UI, Roboto, "Helvetica Neue", Arial, sans-serif',
    font_size_base VARCHAR(10) DEFAULT '14px',
    font_size_title VARCHAR(10) DEFAULT '24px',
    font_size_header VARCHAR(10) DEFAULT '18px',
    
    -- Spacing
    page_margin VARCHAR(10) DEFAULT '28px',
    section_spacing VARCHAR(10) DEFAULT '20px',
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Insert default template
INSERT INTO invoice_templates (name, is_default, is_active) 
VALUES ('Default Template', 1, 1) 
ON DUPLICATE KEY UPDATE name = name;
