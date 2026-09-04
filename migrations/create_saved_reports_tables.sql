-- Create saved report configurations table
CREATE TABLE IF NOT EXISTS saved_report_configs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    config_name VARCHAR(100) NOT NULL,
    report_type VARCHAR(50) NOT NULL,
    from_date DATE,
    to_date DATE,
    comparison_period ENUM('none', 'yoy', 'mom') DEFAULT 'none',
    filters JSON,
    chart_preferences JSON,
    is_default BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES user(id) ON DELETE CASCADE,
    INDEX idx_user_report (user_id, report_type),
    INDEX idx_user_default (user_id, is_default)
);

-- Insert some default configurations
INSERT INTO saved_report_configs (user_id, config_name, report_type, from_date, to_date, comparison_period, is_default) VALUES
(1, 'Monthly P&L', 'pnl', DATE_FORMAT(NOW(), '%Y-%m-01'), CURDATE(), 'yoy', TRUE),
(1, 'Quarterly Balance Sheet', 'balance_sheet', DATE_SUB(DATE_FORMAT(NOW(), '%Y-%m-01'), INTERVAL 3 MONTH), CURDATE(), 'yoy', FALSE),
(1, 'Current AR Ageing', 'ar_ageing', CURDATE(), CURDATE(), 'mom', FALSE),
(1, 'VAT Report - Current Month', 'vat', DATE_FORMAT(NOW(), '%Y-%m-01'), CURDATE(), 'yoy', FALSE);
