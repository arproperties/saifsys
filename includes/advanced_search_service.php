<?php
/**
 * Advanced Search Service
 * Provides comprehensive search functionality with saved filters and search history
 */

class AdvancedSearchService {
    private $conn;
    
    public function __construct($conn) {
        $this->conn = $conn;
    }
    
    /**
     * Save a search filter
     */
    public function saveFilter($user_id, $filter_name, $page_type, $filter_criteria, $is_global = false, $is_default = false) {
        try {
            // If setting as default, unset other defaults for this page type
            if ($is_default) {
                $stmt = $this->conn->prepare("
                    UPDATE saved_search_filters 
                    SET is_default = FALSE 
                    WHERE page_type = ? AND (user_id = ? OR is_global = TRUE)
                ");
                $stmt->execute([$page_type, $user_id]);
            }
            
            $stmt = $this->conn->prepare("
                INSERT INTO saved_search_filters 
                (user_id, filter_name, page_type, filter_criteria, is_global, is_default)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $user_id,
                $filter_name,
                $page_type,
                json_encode($filter_criteria),
                $is_global ? 1 : 0,
                $is_default ? 1 : 0
            ]);
            
            return ['success' => true, 'filter_id' => $this->conn->lastInsertId()];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Get saved filters for a page type
     */
    public function getSavedFilters($user_id, $page_type) {
        try {
            $stmt = $this->conn->prepare("
                SELECT id, filter_name, filter_criteria, is_global, is_default, created_at
                FROM saved_search_filters 
                WHERE page_type = ? AND (user_id = ? OR is_global = TRUE)
                ORDER BY is_default DESC, created_at DESC
            ");
            
            $stmt->execute([$page_type, $user_id]);
            $filters = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($filters as &$filter) {
                $filter['filter_criteria'] = json_decode($filter['filter_criteria'], true);
            }
            
            return ['success' => true, 'filters' => $filters];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Load a saved filter
     */
    public function loadFilter($filter_id, $user_id) {
        try {
            $stmt = $this->conn->prepare("
                SELECT filter_name, filter_criteria, page_type
                FROM saved_search_filters 
                WHERE id = ? AND (user_id = ? OR is_global = TRUE)
            ");
            
            $stmt->execute([$filter_id, $user_id]);
            $filter = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$filter) {
                return ['success' => false, 'error' => 'Filter not found'];
            }
            
            $filter['filter_criteria'] = json_decode($filter['filter_criteria'], true);
            
            return ['success' => true, 'filter' => $filter];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Delete a saved filter
     */
    public function deleteFilter($filter_id, $user_id) {
        try {
            $stmt = $this->conn->prepare("
                DELETE FROM saved_search_filters 
                WHERE id = ? AND user_id = ?
            ");
            
            $stmt->execute([$filter_id, $user_id]);
            
            if ($stmt->rowCount() === 0) {
                return ['success' => false, 'error' => 'Filter not found or access denied'];
            }
            
            return ['success' => true];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Record search history
     */
    public function recordSearch($user_id, $page_type, $search_query = null, $filter_criteria = null, $results_count = 0) {
        try {
            $stmt = $this->conn->prepare("
                INSERT INTO search_history 
                (user_id, page_type, search_query, filter_criteria, results_count)
                VALUES (?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $user_id,
                $page_type,
                $search_query,
                $filter_criteria ? json_encode($filter_criteria) : null,
                $results_count
            ]);
            
            return ['success' => true];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Get search history for a page type
     */
    public function getSearchHistory($user_id, $page_type, $limit = 10) {
        try {
            $stmt = $this->conn->prepare("
                SELECT search_query, filter_criteria, results_count, search_timestamp
                FROM search_history 
                WHERE user_id = ? AND page_type = ?
                ORDER BY search_timestamp DESC
                LIMIT ?
            ");
            
            $stmt->execute([$user_id, $page_type, $limit]);
            $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($history as &$item) {
                $item['filter_criteria'] = $item['filter_criteria'] ? json_decode($item['filter_criteria'], true) : null;
            }
            
            return ['success' => true, 'history' => $history];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Get search suggestions for autocomplete
     */
    public function getSuggestions($page_type, $query, $suggestion_type = null, $limit = 10) {
        try {
            $sql = "
                SELECT suggestion_text, suggestion_type, usage_count
                FROM search_suggestions 
                WHERE page_type = ? AND suggestion_text LIKE ?
            ";
            $params = [$page_type, "%$query%"];
            
            if ($suggestion_type) {
                $sql .= " AND suggestion_type = ?";
                $params[] = $suggestion_type;
            }
            
            $sql .= " ORDER BY usage_count DESC, last_used DESC LIMIT ?";
            $params[] = $limit;
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            
            return ['success' => true, 'suggestions' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Add or update search suggestion
     */
    public function addSuggestion($page_type, $suggestion_text, $suggestion_type) {
        try {
            $stmt = $this->conn->prepare("
                INSERT INTO search_suggestions (page_type, suggestion_text, suggestion_type, usage_count)
                VALUES (?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE 
                usage_count = usage_count + 1,
                last_used = CURRENT_TIMESTAMP
            ");
            
            $stmt->execute([$page_type, $suggestion_text, $suggestion_type]);
            
            return ['success' => true];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Build search query for invoices
     */
    public function buildInvoiceSearchQuery($criteria) {
        $where_conditions = [];
        $params = [];
        
        // Search query
        if (!empty($criteria['search'])) {
            $where_conditions[] = "(i.invoice_no LIKE ? OR c.client_name LIKE ? OR c.email LIKE ?)";
            $search_term = "%{$criteria['search']}%";
            $params = array_merge($params, [$search_term, $search_term, $search_term]);
        }
        
        // Status filter
        if (!empty($criteria['status'])) {
            if (is_array($criteria['status'])) {
                $placeholders = str_repeat('?,', count($criteria['status']) - 1) . '?';
                $where_conditions[] = "i.status IN ($placeholders)";
                $params = array_merge($params, $criteria['status']);
            } else {
                $where_conditions[] = "i.status = ?";
                $params[] = $criteria['status'];
            }
        }
        
        // Date range filter
        if (!empty($criteria['date_from'])) {
            $where_conditions[] = "i.issue_date >= ?";
            $params[] = $criteria['date_from'];
        }
        
        if (!empty($criteria['date_to'])) {
            $where_conditions[] = "i.issue_date <= ?";
            $params[] = $criteria['date_to'];
        }

        // Due date range filter
        if (!empty($criteria['due_from'])) {
            $where_conditions[] = "i.due_date >= ?";
            $params[] = $criteria['due_from'];
        }

        if (!empty($criteria['due_to'])) {
            $where_conditions[] = "i.due_date <= ?";
            $params[] = $criteria['due_to'];
        }
        
        // Amount range filter
        if (!empty($criteria['amount_min'])) {
            $where_conditions[] = "i.total >= ?";
            $params[] = $criteria['amount_min'];
        }
        
        if (!empty($criteria['amount_max'])) {
            $where_conditions[] = "i.total <= ?";
            $params[] = $criteria['amount_max'];
        }
        
        // Overdue filter
        if (!empty($criteria['overdue'])) {
            $where_conditions[] = "i.due_date < CURDATE() AND i.status IN ('issued', 'partially_paid')";
        }
        
        // Client status filter
        if (!empty($criteria['client_status'])) {
            $where_conditions[] = "c.client_status = ?";
            $params[] = $criteria['client_status'];
        }
        
        $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';
        
        return [
            'where_clause' => $where_clause,
            'params' => $params
        ];
    }
    
    /**
     * Build search query for payments
     * Note: The actual query uses 'r' for receipts table, 'i' for invoices, 'c'/'c2' for clients
     */
    public function buildPaymentSearchQuery($criteria) {
        $where_conditions = [];
        $params = [];
        
        // Search query - search in receipt notes, invoice numbers, and client names
        if (!empty($criteria['search'])) {
            $where_conditions[] = "(r.notes LIKE ? OR i.invoice_no LIKE ? OR c.client_name LIKE ? OR c2.client_name LIKE ?)";
            $search_term = "%{$criteria['search']}%";
            $params = array_merge($params, [$search_term, $search_term, $search_term, $search_term]);
        }
        
        // Payment method filter - use r.method (the actual column name).
        // Compare case-insensitively because older rows may use Cash/Bank while
        // newer forms submit cash/bank.
        $method_map = [
            'cash' => 'cash',
            'card' => 'card',
            'bank' => 'bank',
            'bank_transfer' => 'bank',
            'cheque' => 'cheque',
            'check' => 'cheque'
        ];
        
        if (!empty($criteria['payment_method'])) {
            if (is_array($criteria['payment_method'])) {
                // Flatten nested arrays (e.g., [['bank']] becomes ['bank'])
                $flattened_methods = [];
                array_walk_recursive($criteria['payment_method'], function($value) use (&$flattened_methods, $method_map) {
                    if (!empty($value) && is_string($value)) {
                        $normalized = trim(strtolower($value));
                        $mapped = $method_map[$normalized] ?? $normalized;
                        $flattened_methods[] = $mapped;
                    }
                });
                $flattened_methods = array_filter(array_unique($flattened_methods));
                
                if (!empty($flattened_methods)) {
                    if (count($flattened_methods) === 1) {
                        // Single value
                        $where_conditions[] = "LOWER(r.method) = ?";
                        $params[] = $flattened_methods[0];
                    } else {
                        // Multiple values - use IN clause
                        $placeholders = str_repeat('?,', count($flattened_methods) - 1) . '?';
                        $where_conditions[] = "LOWER(r.method) IN ($placeholders)";
                        $params = array_merge($params, $flattened_methods);
                    }
                }
            } else {
                // Single value - normalize and map
                $normalized = trim(strtolower($criteria['payment_method']));
                $mapped = $method_map[$normalized] ?? $normalized;
                $where_conditions[] = "LOWER(r.method) = ?";
                $params[] = $mapped;
            }
        }
        
        // Date range filter - use r.receipt_date (the actual column name)
        // Use DATE() function to ensure date-only comparison (ignore time component)
        if (!empty($criteria['date_from'])) {
            $where_conditions[] = "DATE(r.receipt_date) >= ?";
            $params[] = $criteria['date_from'];
        }
        
        if (!empty($criteria['date_to'])) {
            $where_conditions[] = "DATE(r.receipt_date) <= ?";
            $params[] = $criteria['date_to'];
        }
        
        // Amount range filter - use the same logic as the main query (COALESCE)
        if (!empty($criteria['amount_min'])) {
            $where_conditions[] = "COALESCE(ra.amount_applied, r.amount) >= ?";
            $params[] = $criteria['amount_min'];
        }
        
        if (!empty($criteria['amount_max'])) {
            $where_conditions[] = "COALESCE(ra.amount_applied, r.amount) <= ?";
            $params[] = $criteria['amount_max'];
        }
        
        $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';
        
        return [
            'where_clause' => $where_clause,
            'params' => $params
        ];
    }
    
    /**
     * Build search query for expenses
     */
    public function buildExpenseSearchQuery($criteria) {
        $where_conditions = [];
        $params = [];
        
        // Search query
        if (!empty($criteria['search'])) {
            $where_conditions[] = "(e.description LIKE ? OR e.vendor_name LIKE ? OR e.reference LIKE ?)";
            $search_term = "%{$criteria['search']}%";
            $params = array_merge($params, [$search_term, $search_term, $search_term]);
        }
        
        // Status filter
        if (!empty($criteria['status'])) {
            if (is_array($criteria['status'])) {
                $placeholders = str_repeat('?,', count($criteria['status']) - 1) . '?';
                $where_conditions[] = "e.status IN ($placeholders)";
                $params = array_merge($params, $criteria['status']);
            } else {
                $where_conditions[] = "e.status = ?";
                $params[] = $criteria['status'];
            }
        }
        
        // Date range filter
        if (!empty($criteria['date_from'])) {
            $where_conditions[] = "e.expense_date >= ?";
            $params[] = $criteria['date_from'];
        }
        
        if (!empty($criteria['date_to'])) {
            $where_conditions[] = "e.expense_date <= ?";
            $params[] = $criteria['date_to'];
        }
        
        // Amount range filter
        if (!empty($criteria['amount_min'])) {
            $where_conditions[] = "e.total >= ?";
            $params[] = $criteria['amount_min'];
        }
        
        if (!empty($criteria['amount_max'])) {
            $where_conditions[] = "e.total <= ?";
            $params[] = $criteria['amount_max'];
        }
        
        // Vendor filter
        if (!empty($criteria['vendor_id'])) {
            $where_conditions[] = "e.vendor_id = ?";
            $params[] = $criteria['vendor_id'];
        }
        
        $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';
        
        return [
            'where_clause' => $where_clause,
            'params' => $params
        ];
    }
}
?>
