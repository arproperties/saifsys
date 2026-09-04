-- Database Performance Optimization Script
-- This script adds indexes, optimizes queries, and sets up caching infrastructure

-- =============================================
-- INDEXES FOR FREQUENTLY QUERIED COLUMNS
-- =============================================

-- Invoices table indexes
CREATE INDEX idx_invoices_status ON invoices(status);
CREATE INDEX idx_invoices_issue_date ON invoices(issue_date);
CREATE INDEX idx_invoices_due_date ON invoices(due_date);
CREATE INDEX idx_invoices_client_id ON invoices(client_id);
CREATE INDEX idx_invoices_total ON invoices(total);
CREATE INDEX idx_invoices_created_at ON invoices(created_at);
CREATE INDEX idx_invoices_updated_at ON invoices(updated_at);

-- Composite indexes for common query patterns
CREATE INDEX idx_invoices_status_date ON invoices(status, issue_date);
CREATE INDEX idx_invoices_client_status ON invoices(client_id, status);
CREATE INDEX idx_invoices_due_status ON invoices(due_date, status);
CREATE INDEX idx_invoices_overdue ON invoices(due_date, status) WHERE status IN ('issued', 'partially_paid') AND due_date < CURDATE();

-- Invoice items indexes
CREATE INDEX idx_invoice_items_invoice_id ON invoice_items(invoice_id);
CREATE INDEX idx_invoice_items_description ON invoice_items(description(100));

-- Payments and receipts indexes
CREATE INDEX idx_invoice_payments_invoice_id ON invoice_payments(invoice_id);
CREATE INDEX idx_invoice_payments_payment_date ON invoice_payments(payment_date);
CREATE INDEX idx_invoice_payments_method ON invoice_payments(payment_method);

CREATE INDEX idx_receipts_client_id ON receipts(client_id);
CREATE INDEX idx_receipts_receipt_date ON receipts(receipt_date);
CREATE INDEX idx_receipts_method ON receipts(method);
CREATE INDEX idx_receipts_amount ON receipts(amount);

-- Receipt allocations indexes
CREATE INDEX idx_receipt_allocations_receipt_id ON receipt_allocations(receipt_id);
CREATE INDEX idx_receipt_allocations_invoice_id ON receipt_allocations(invoice_id);

-- Client table indexes
CREATE INDEX idx_client_name ON client(client_name);
CREATE INDEX idx_client_type ON client(client_type);
CREATE INDEX idx_client_active ON client(is_active);

-- Expenses table indexes
CREATE INDEX idx_expenses_date ON expenses(expense_date);
CREATE INDEX idx_expenses_status ON expenses(status);
CREATE INDEX idx_expenses_vendor_id ON expenses(vendor_id);
CREATE INDEX idx_expenses_total ON expenses(total);
CREATE INDEX idx_expenses_created_at ON expenses(created_at);

-- Chart of accounts indexes
CREATE INDEX idx_coa_account_no ON chart_of_accounts(account_no);
CREATE INDEX idx_coa_type ON chart_of_accounts(type);
CREATE INDEX idx_coa_active ON chart_of_accounts(is_active);
CREATE INDEX idx_coa_header ON chart_of_accounts(is_header);

-- GL postings indexes
CREATE INDEX idx_gl_postings_account_id ON gl_postings(account_id);
CREATE INDEX idx_gl_postings_date ON gl_postings.posting_date;
CREATE INDEX idx_gl_postings_reference ON gl_postings(reference);
CREATE INDEX idx_gl_postings_type ON gl_postings.posting_type;

-- GL account balances indexes
CREATE INDEX idx_gl_balances_account_period ON gl_account_balances(account_id, period);
CREATE INDEX idx_gl_balances_period ON gl_account_balances(period);

-- Email system indexes
CREATE INDEX idx_email_log_object_type ON email_log(object_type);
CREATE INDEX idx_email_log_object_id ON email_log(object_id);
CREATE INDEX idx_email_log_status ON email_log(status);
CREATE INDEX idx_email_log_created_at ON email_log(created_at);

CREATE INDEX idx_email_queue_status ON email_queue(status);
CREATE INDEX idx_email_queue_created_at ON email_queue(created_at);
CREATE INDEX idx_email_queue_priority ON email_queue(priority);

-- Search system indexes
CREATE INDEX idx_search_history_user_page ON search_history(user_id, page_type);
CREATE INDEX idx_search_history_timestamp ON search_history(search_timestamp);

CREATE INDEX idx_saved_filters_user_page ON saved_search_filters(user_id, page_type);
CREATE INDEX idx_saved_filters_global ON saved_search_filters(is_global, page_type);

CREATE INDEX idx_search_suggestions_page_type ON search_suggestions(page_type);
CREATE INDEX idx_search_suggestions_usage ON search_suggestions(usage_count);

-- Audit log indexes
CREATE INDEX idx_audit_log_object_type ON audit_log(object_type);
CREATE INDEX idx_audit_log_object_id ON audit_log(object_id);
CREATE INDEX idx_audit_log_action ON audit_log(action);
CREATE INDEX idx_audit_log_created_at ON audit_log(created_at);
CREATE INDEX idx_audit_log_user_id ON audit_log(user_id);

-- Credit notes indexes
CREATE INDEX idx_credit_notes_client_id ON credit_notes(client_id);
CREATE INDEX idx_credit_notes_date ON credit_notes(credit_note_date);
CREATE INDEX idx_credit_notes_status ON credit_notes(status);
CREATE INDEX idx_credit_notes_total ON credit_notes(total);

-- Refunds indexes
CREATE INDEX idx_refunds_receipt_id ON refunds(receipt_id);
CREATE INDEX idx_refunds_date ON refunds(refund_date);
CREATE INDEX idx_refunds_status ON refunds(status);

-- Scheduled reports indexes
CREATE INDEX idx_scheduled_reports_active ON scheduled_reports(is_active);
CREATE INDEX idx_scheduled_reports_next_run ON scheduled_reports(next_run_at);
CREATE INDEX idx_scheduled_reports_frequency ON scheduled_reports(frequency);

-- =============================================
-- OPTIMIZED VIEWS FOR DASHBOARD KPIs
-- =============================================

-- Drop existing views if they exist
DROP VIEW IF EXISTS v_ar_summary_optimized;
DROP VIEW IF EXISTS v_ar_ageing_optimized;
DROP VIEW IF EXISTS v_ar_invoices_open_optimized;
DROP VIEW IF EXISTS v_client_unbilled_summary_optimized;
DROP VIEW IF EXISTS v_ar_client_credit_optimized;

-- Optimized AR Summary View
CREATE VIEW v_ar_summary_optimized AS
SELECT 
    COUNT(i.id) as open_count,
    COALESCE(SUM(i.total), 0) as ar_total,
    COALESCE(SUM(CASE WHEN i.due_date < CURDATE() AND i.status IN ('issued', 'partially_paid') THEN i.total ELSE 0 END), 0) as overdue_total,
    COALESCE(SUM(CASE WHEN i.status = 'paid' AND i.issue_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN i.total ELSE 0 END), 0) as paid_this_month,
    COALESCE(SUM(CASE WHEN i.status = 'paid' AND i.issue_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN i.total ELSE 0 END), 0) as paid_last_30_days,
    COALESCE(SUM(CASE WHEN i.status = 'paid' AND i.issue_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN i.total ELSE 0 END), 0) as paid_last_7_days
FROM invoices i
WHERE i.status IN ('issued', 'partially_paid', 'paid');

-- Optimized AR Ageing View
CREATE VIEW v_ar_ageing_optimized AS
SELECT 
    COALESCE(SUM(CASE WHEN i.due_date >= CURDATE() THEN i.total ELSE 0 END), 0) as bucket_0,
    COALESCE(SUM(CASE WHEN i.due_date < CURDATE() AND DATEDIFF(CURDATE(), i.due_date) BETWEEN 1 AND 30 THEN i.total ELSE 0 END), 0) as bucket_30,
    COALESCE(SUM(CASE WHEN i.due_date < CURDATE() AND DATEDIFF(CURDATE(), i.due_date) BETWEEN 31 AND 60 THEN i.total ELSE 0 END), 0) as bucket_60,
    COALESCE(SUM(CASE WHEN i.due_date < CURDATE() AND DATEDIFF(CURDATE(), i.due_date) BETWEEN 61 AND 90 THEN i.total ELSE 0 END), 0) as bucket_90,
    COALESCE(SUM(CASE WHEN i.due_date < CURDATE() AND DATEDIFF(CURDATE(), i.due_date) > 90 THEN i.total ELSE 0 END), 0) as bucket_120
FROM invoices i
WHERE i.status IN ('issued', 'partially_paid');

-- Optimized Open Invoices View
CREATE VIEW v_ar_invoices_open_optimized AS
SELECT 
    i.id,
    i.client_id,
    i.invoice_no,
    i.issue_date,
    i.due_date,
    i.terms,
    i.total,
    COALESCE(pa.amount_paid, 0) as amount_paid,
    GREATEST(ROUND(i.total - COALESCE(pa.amount_paid, 0), 2), 0) as balance_due,
    CASE 
        WHEN i.due_date >= CURDATE() THEN 0
        ELSE DATEDIFF(CURDATE(), i.due_date)
    END as days_overdue,
    CASE 
        WHEN i.due_date >= CURDATE() THEN 'Current'
        WHEN DATEDIFF(CURDATE(), i.due_date) BETWEEN 1 AND 30 THEN '1-30'
        WHEN DATEDIFF(CURDATE(), i.due_date) BETWEEN 31 AND 60 THEN '31-60'
        WHEN DATEDIFF(CURDATE(), i.due_date) BETWEEN 61 AND 90 THEN '61-90'
        ELSE '90+'
    END as aging_bucket
FROM invoices i
LEFT JOIN (
    SELECT 
        ra.invoice_id, 
        ROUND(SUM(ra.amount_applied), 2) AS amount_paid
    FROM receipt_allocations ra
    GROUP BY ra.invoice_id
) pa ON pa.invoice_id = i.id
WHERE i.status IN ('issued', 'partially_paid')
    AND i.total > COALESCE(pa.amount_paid, 0);

-- =============================================
-- CACHING INFRASTRUCTURE
-- =============================================

-- Create cache table for dashboard KPIs
CREATE TABLE IF NOT EXISTS dashboard_cache (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cache_key VARCHAR(255) NOT NULL UNIQUE,
    cache_data JSON NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_cache_key (cache_key),
    INDEX idx_expires_at (expires_at)
);

-- Create cache table for report data
CREATE TABLE IF NOT EXISTS report_cache (
    id INT AUTO_INCREMENT PRIMARY KEY,
    report_type VARCHAR(50) NOT NULL,
    parameters_hash VARCHAR(64) NOT NULL,
    cache_data JSON NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_report_cache (report_type, parameters_hash),
    INDEX idx_expires_at (expires_at)
);

-- =============================================
-- STORED PROCEDURES FOR CACHED QUERIES
-- =============================================

DELIMITER //

-- Procedure to get cached dashboard data
CREATE PROCEDURE GetDashboardCache(IN cache_key VARCHAR(255))
BEGIN
    SELECT cache_data, expires_at
    FROM dashboard_cache 
    WHERE cache_key = cache_key 
        AND expires_at > NOW();
END //

-- Procedure to set dashboard cache
CREATE PROCEDURE SetDashboardCache(
    IN cache_key VARCHAR(255),
    IN cache_data JSON,
    IN expires_minutes INT
)
BEGIN
    INSERT INTO dashboard_cache (cache_key, cache_data, expires_at)
    VALUES (cache_key, cache_data, DATE_ADD(NOW(), INTERVAL expires_minutes MINUTE))
    ON DUPLICATE KEY UPDATE
        cache_data = VALUES(cache_data),
        expires_at = VALUES(expires_at),
        updated_at = NOW();
END //

-- Procedure to get cached report data
CREATE PROCEDURE GetReportCache(
    IN report_type VARCHAR(50),
    IN parameters_hash VARCHAR(64)
)
BEGIN
    SELECT cache_data, expires_at
    FROM report_cache 
    WHERE report_type = report_type 
        AND parameters_hash = parameters_hash
        AND expires_at > NOW();
END //

-- Procedure to set report cache
CREATE PROCEDURE SetReportCache(
    IN report_type VARCHAR(50),
    IN parameters_hash VARCHAR(64),
    IN cache_data JSON,
    IN expires_minutes INT
)
BEGIN
    INSERT INTO report_cache (report_type, parameters_hash, cache_data, expires_at)
    VALUES (report_type, parameters_hash, cache_data, DATE_ADD(NOW(), INTERVAL expires_minutes MINUTE))
    ON DUPLICATE KEY UPDATE
        cache_data = VALUES(cache_data),
        expires_at = VALUES(expires_at),
        updated_at = NOW();
END //

-- Procedure to clean expired cache
CREATE PROCEDURE CleanExpiredCache()
BEGIN
    DELETE FROM dashboard_cache WHERE expires_at < NOW();
    DELETE FROM report_cache WHERE expires_at < NOW();
END //

DELIMITER ;

-- =============================================
-- QUERY OPTIMIZATION SETTINGS
-- =============================================

-- Set MySQL query cache settings (if supported)
-- Note: These may need to be set in my.cnf or via SET GLOBAL commands
-- SET GLOBAL query_cache_type = 1;
-- SET GLOBAL query_cache_size = 268435456; -- 256MB
-- SET GLOBAL query_cache_limit = 1048576;  -- 1MB

-- =============================================
-- PERFORMANCE MONITORING VIEWS
-- =============================================

-- View to monitor slow queries (requires slow query log to be enabled)
CREATE VIEW v_slow_queries AS
SELECT 
    'Enable slow query log to see performance data' as message;

-- View to monitor table sizes
CREATE VIEW v_table_sizes AS
SELECT 
    table_name,
    ROUND(((data_length + index_length) / 1024 / 1024), 2) AS 'Size (MB)',
    table_rows
FROM information_schema.tables 
WHERE table_schema = DATABASE()
ORDER BY (data_length + index_length) DESC;

-- View to monitor index usage
CREATE VIEW v_index_usage AS
SELECT 
    table_name,
    index_name,
    cardinality,
    sub_part,
    packed,
    nullable,
    index_type
FROM information_schema.statistics 
WHERE table_schema = DATABASE()
ORDER BY table_name, seq_in_index;

-- =============================================
-- INITIAL CACHE POPULATION
-- =============================================

-- Insert initial dashboard cache entries
INSERT INTO dashboard_cache (cache_key, cache_data, expires_at) VALUES
('ar_summary', '{}', DATE_ADD(NOW(), INTERVAL 5 MINUTE)),
('ar_ageing', '{}', DATE_ADD(NOW(), INTERVAL 5 MINUTE)),
('top_overdue', '{}', DATE_ADD(NOW(), INTERVAL 10 MINUTE)),
('top_unbilled', '{}', DATE_ADD(NOW(), INTERVAL 10 MINUTE)),
('payment_trends', '{}', DATE_ADD(NOW(), INTERVAL 15 MINUTE))
ON DUPLICATE KEY UPDATE expires_at = DATE_ADD(NOW(), INTERVAL 5 MINUTE);
