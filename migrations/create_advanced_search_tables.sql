-- Advanced Search System Tables
-- This migration creates tables for saved searches and search history

-- Saved search filters table
CREATE TABLE saved_search_filters (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    filter_name VARCHAR(255) NOT NULL,
    page_type VARCHAR(50) NOT NULL, -- 'invoices', 'payments', 'expenses', 'statements', etc.
    filter_criteria JSON NOT NULL, -- Store all filter parameters as JSON
    is_global BOOLEAN DEFAULT FALSE, -- Whether this filter is available to all users
    is_default BOOLEAN DEFAULT FALSE, -- Whether this is the default filter for this page type
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES user(id) ON DELETE CASCADE,
    INDEX idx_user_page (user_id, page_type),
    INDEX idx_global_page (is_global, page_type),
    INDEX idx_default_page (is_default, page_type)
);

-- Search history table
CREATE TABLE search_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    page_type VARCHAR(50) NOT NULL,
    search_query TEXT,
    filter_criteria JSON,
    results_count INT DEFAULT 0,
    search_timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES user(id) ON DELETE CASCADE,
    INDEX idx_user_page (user_id, page_type),
    INDEX idx_timestamp (search_timestamp),
    INDEX idx_query (search_query(100))
);

-- Search suggestions table (for autocomplete)
CREATE TABLE search_suggestions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    page_type VARCHAR(50) NOT NULL,
    suggestion_text VARCHAR(255) NOT NULL,
    suggestion_type VARCHAR(50) NOT NULL, -- 'client', 'invoice_no', 'description', etc.
    usage_count INT DEFAULT 1,
    last_used TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_page_type (page_type),
    INDEX idx_suggestion_type (suggestion_type),
    INDEX idx_usage_count (usage_count),
    UNIQUE KEY unique_suggestion (page_type, suggestion_text, suggestion_type)
);

-- Insert some default saved filters
INSERT INTO saved_search_filters (user_id, filter_name, page_type, filter_criteria, is_global, is_default) VALUES
(1, 'Overdue Invoices', 'invoices', '{"status": ["issued", "partially_paid"], "overdue": true}', TRUE, TRUE),
(1, 'Recent Payments', 'payments', '{"date_range": "last_30_days"}', TRUE, FALSE),
(1, 'High Value Invoices', 'invoices', '{"amount_min": 10000}', TRUE, FALSE),
(1, 'Pending Expenses', 'expenses', '{"status": ["pending"]}', TRUE, FALSE),
(1, 'This Month', 'invoices', '{"date_range": "this_month"}', TRUE, FALSE);
