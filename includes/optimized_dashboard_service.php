<?php
/**
 * Optimized Dashboard Service
 * Provides optimized data access for dashboard KPIs using caching and optimized queries
 */

require_once __DIR__ . '/service_management_settings.php';
require_once __DIR__ . '/service_accounting_service.php';
require_once __DIR__ . '/ar_helpers.php';

class OptimizedDashboardService {
    private $conn;
    private $cache;
    private $accountingService;
    
    public function __construct($conn) {
        $this->conn = $conn;
        $this->cache = new CachingService($conn);
    }

    private function accountingService(): ServiceAccountingService
    {
        if ($this->accountingService === null) {
            $this->accountingService = new ServiceAccountingService($this->conn);
        }
        return $this->accountingService;
    }
    
    /**
     * Get all dashboard data efficiently
     */
    public function getDashboardData() {
        return [
            'summary' => $this->getSummaryData(),
            'ageing' => $this->getAgeingData(),
            'top_overdue' => $this->getTopOverdueData(),
            'top_clients_balance' => $this->getTopClientsByBalanceData(),
            'top_unbilled' => $this->getTopUnbilledData(),
            'payment_trends' => $this->getPaymentTrendsData(),
            'client_credit' => $this->getClientCreditData(),
            'charts' => $this->getChartsData()
        ];
    }
    
    /**
     * Get summary KPIs with caching
     */
    public function getSummaryData() {
        try {
            return $this->accountingService()->getARSummary();
        } catch (Exception $e) {
            error_log("AR summary error: " . $e->getMessage());
            return $this->getSummaryDataFallback();
        }
    }
    
    /**
     * Get ageing data with caching
     */
    public function getAgeingData() {
        try {
            return $this->normalizeArAgeingBuckets($this->accountingService()->getAgeingBuckets());
        } catch (Exception $e) {
            error_log("AR ageing error: " . $e->getMessage());
            return $this->normalizeArAgeingBuckets($this->getAgeingDataFallback());
        }
    }

    /**
     * Ensure ageing row always has bucket_* keys (avoids undefined index when cache/view returns partial rows).
     *
     * @param array $row Raw row from v_ar_ageing_optimized or cache
     * @return array{bucket_0: float, bucket_30: float, bucket_60: float, bucket_90: float, bucket_120: float, ...}
     */
    private function normalizeArAgeingBuckets(array $row): array {
        $keys = ['bucket_0', 'bucket_30', 'bucket_60', 'bucket_90', 'bucket_120'];
        foreach ($keys as $k) {
            if (!array_key_exists($k, $row)) {
                $row[$k] = 0.0;
            } else {
                $row[$k] = (float)$row[$k];
            }
        }
        return $row;
    }
    
    /**
     * Get top overdue invoices with caching
     */
    public function getTopOverdueData($limit = 10) {
        try {
            return $this->accountingService()->getTopOverdue((int)$limit);
        } catch (Exception $e) {
            error_log("Top overdue error: " . $e->getMessage());
            return $this->getTopOverdueDataFallback($limit);
        }
    }
    
    /**
     * Get top clients by outstanding balance with caching
     */
    public function getTopClientsByBalanceData($limit = 10) {
        try {
            return $this->accountingService()->getTopClientsByBalance((int)$limit);
        } catch (Exception $e) {
            error_log("Top clients balance error: " . $e->getMessage());
            return $this->getTopClientsByBalanceDataFallback($limit);
        }
    }
    
    /**
     * Get top unbilled clients with caching
     */
    public function getTopUnbilledData($limit = 10) {
        try {
            return $this->cache->getTopUnbilled($limit);
        } catch (Exception $e) {
            error_log("Cache error in getTopUnbilledData: " . $e->getMessage());
            return $this->getTopUnbilledDataFallback($limit);
        }
    }
    
    /**
     * Get payment trends with caching
     */
    public function getPaymentTrendsData($months = 6) {
        try {
            return $this->cache->getPaymentTrends($months);
        } catch (Exception $e) {
            error_log("Cache error in getPaymentTrendsData: " . $e->getMessage());
            return $this->getPaymentTrendsDataFallback($months);
        }
    }
    
    /**
     * Get client credit data with caching
     */
    public function getClientCreditData() {
        try {
            return $this->cache->getClientCredit();
        } catch (Exception $e) {
            error_log("Cache error in getClientCreditData: " . $e->getMessage());
            return $this->getClientCreditDataFallback();
        }
    }
    
    /**
     * Get chart data with caching
     */
    public function getChartsData() {
        return $this->cache->remember('charts_data', function() {
            return [
                'aging_distribution' => $this->getAgingDistributionData(),
                'top_clients' => $this->getTopClientsData(),
                'payment_trends_chart' => $this->getPaymentTrendsChartData()
            ];
        }, 'dashboard');
    }
    
    /**
     * Get aging distribution for pie chart
     */
    private function getAgingDistributionData() {
        $ageing = $this->getAgeingData();
        return [
            'labels' => ['Current', '1-30 days', '31-60 days', '61-90 days', '90+ days'],
            'data' => [
                (float)($ageing['bucket_0'] ?? 0),
                (float)($ageing['bucket_30'] ?? 0),
                (float)($ageing['bucket_60'] ?? 0),
                (float)($ageing['bucket_90'] ?? 0),
                (float)($ageing['bucket_120'] ?? 0)
            ],
            'colors' => ['#28a745', '#ffc107', '#fd7e14', '#dc3545', '#6f42c1']
        ];
    }
    
    /**
     * Get top clients data for bar chart
     */
    private function getTopClientsData() {
        $overdue = $this->getTopOverdueData(5);
        return [
            'labels' => array_column($overdue, 'invoice_no'),
            'data' => array_column($overdue, 'balance_due')
        ];
    }
    
    /**
     * Get payment trends for line chart
     */
    private function getPaymentTrendsChartData() {
        $trends = $this->getPaymentTrendsData(6);
        return [
            'labels' => array_column($trends, 'month'),
            'data' => array_column($trends, 'total_amount')
        ];
    }
    
    /**
     * Get optimized invoice list with pagination
     */
    public function getInvoicesList($filters = [], $page = 1, $per_page = 50) {
        $cache_key = 'invoices_list_' . md5(json_encode($filters) . $page . $per_page);
        
        return $this->cache->remember($cache_key, function() use ($filters, $page, $per_page) {
            $where_conditions = ["1=1"];
            $params = [];
            
            // Apply filters
            if (!empty($filters['status'])) {
                $where_conditions[] = "i.status = ?";
                $params[] = $filters['status'];
            }
            
            if (!empty($filters['search'])) {
                $where_conditions[] = "(i.invoice_no LIKE ? OR c.client_name LIKE ?)";
                $search_term = "%{$filters['search']}%";
                $params[] = $search_term;
                $params[] = $search_term;
            }
            
            if (!empty($filters['date_from'])) {
                $where_conditions[] = "i.issue_date >= ?";
                $params[] = $filters['date_from'];
            }
            
            if (!empty($filters['date_to'])) {
                $where_conditions[] = "i.issue_date <= ?";
                $params[] = $filters['date_to'];
            }
            
            if (!empty($filters['overdue'])) {
                $where_conditions[] = "i.due_date < CURDATE() AND i.status IN ('issued', 'partially_paid')";
            }
            
            $where_clause = implode(' AND ', $where_conditions);
            $offset = ($page - 1) * $per_page;
            
            // Count total records
            $count_sql = "
                SELECT COUNT(*)
                FROM invoices i
                LEFT JOIN client c ON c.id = i.client_id
                WHERE {$where_clause}
            ";
            $count_stmt = $this->conn->prepare($count_sql);
            $count_stmt->execute($params);
            $total_records = $count_stmt->fetchColumn();
            
            // Get paginated results
            $sql = "
                SELECT
                    i.*,
                    c.client_name,
                    COALESCE(pa.amount_paid, 0) AS amount_paid,
                    GREATEST(ROUND(COALESCE(i.total,0) - COALESCE(pa.amount_paid,0), 2), 0) AS balance_due
                FROM invoices i
                LEFT JOIN client c ON c.id = i.client_id
                LEFT JOIN (
                    SELECT ra.invoice_id, ROUND(SUM(ra.amount_applied), 2) AS amount_paid
                    FROM receipt_allocations ra
                    GROUP BY ra.invoice_id
                ) pa ON pa.invoice_id = i.id
                WHERE {$where_clause}
                ORDER BY i.issue_date DESC, i.id DESC
                LIMIT " . (int)$per_page . " OFFSET " . (int)$offset;
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return [
                'invoices' => $invoices,
                'total_records' => $total_records,
                'total_pages' => ceil($total_records / $per_page),
                'current_page' => $page,
                'per_page' => $per_page
            ];
        }, 'search');
    }
    
    /**
     * Get optimized payments list with pagination
     */
    public function getPaymentsList($filters = [], $page = 1, $per_page = 50) {
        $cache_key = 'payments_list_' . md5(json_encode($filters) . $page . $per_page);
        
        return $this->cache->remember($cache_key, function() use ($filters, $page, $per_page) {
            $where_conditions = ["1=1"];
            $params = [];
            
            // Apply filters
            if (!empty($filters['search'])) {
                $where_conditions[] = "(c.client_name LIKE ? OR i.invoice_no LIKE ? OR r.receipt_no LIKE ?)";
                $search_term = "%{$filters['search']}%";
                $params[] = $search_term;
                $params[] = $search_term;
                $params[] = $search_term;
            }
            
            if (!empty($filters['date_from'])) {
                $where_conditions[] = "r.receipt_date >= ?";
                $params[] = $filters['date_from'];
            }
            
            if (!empty($filters['date_to'])) {
                $where_conditions[] = "r.receipt_date <= ?";
                $params[] = $filters['date_to'];
            }
            
            if (!empty($filters['payment_method'])) {
                $where_conditions[] = "r.method = ?";
                $params[] = $filters['payment_method'];
            }
            
            $where_clause = implode(' AND ', $where_conditions);
            $offset = ($page - 1) * $per_page;
            
            // Count total records
            $count_sql = "
                SELECT COUNT(*)
                FROM receipts r
                LEFT JOIN receipt_allocations ra ON ra.receipt_id = r.id
                LEFT JOIN invoices i ON i.id = ra.invoice_id
                LEFT JOIN client c ON c.id = i.client_id
                WHERE {$where_clause}
            ";
            $count_stmt = $this->conn->prepare($count_sql);
            $count_stmt->execute($params);
            $total_records = $count_stmt->fetchColumn();
            
            // Get paginated results
            $sql = "
                SELECT
                    r.id AS receipt_id,
                    r.receipt_no,
                    r.receipt_date AS payment_date,
                    r.method AS payment_method,
                    r.notes,
                    COALESCE(ra.amount_applied, r.amount) AS amount,
                    i.id AS invoice_id,
                    i.invoice_no,
                    COALESCE(c.client_name, c2.client_name) AS client_name
                FROM receipts r
                LEFT JOIN receipt_allocations ra ON ra.receipt_id = r.id
                LEFT JOIN invoices i ON i.id = ra.invoice_id
                LEFT JOIN client c ON c.id = i.client_id
                LEFT JOIN client c2 ON c2.id = r.client_id
                WHERE {$where_clause}
                ORDER BY r.receipt_date DESC, r.id DESC, ra.id DESC
                LIMIT " . (int)$per_page . " OFFSET " . (int)$offset;
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return [
                'payments' => $payments,
                'total_records' => $total_records,
                'total_pages' => ceil($total_records / $per_page),
                'current_page' => $page,
                'per_page' => $per_page
            ];
        }, 'search');
    }
    
    /**
     * Fallback methods when cache fails
     */
    private function getSummaryDataFallback() {
        try {
            return $this->accountingService()->getARSummary();
        } catch (Exception $e) {
            error_log("Fallback summary data error: " . $e->getMessage());
            return [];
        }
    }
    
    private function getAgeingDataFallback() {
        try {
            return $this->accountingService()->getAgeingBuckets();
        } catch (Exception $e) {
            error_log("Fallback ageing data error: " . $e->getMessage());
            return [];
        }
    }
    
    private function getTopOverdueDataFallback($limit = 10) {
        try {
            return $this->accountingService()->getTopOverdue((int)$limit);
        } catch (Exception $e) {
            error_log("Fallback top overdue data error: " . $e->getMessage());
            return [];
        }
    }
    
    private function getTopUnbilledDataFallback($limit = 10) {
        try {
            $stmt = $this->conn->query("SELECT * FROM v_ar_client_credit WHERE unbilled_total > 0 ORDER BY unbilled_total DESC LIMIT " . (int)$limit);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Exception $e) {
            error_log("Fallback top unbilled data error: " . $e->getMessage());
            return [];
        }
    }
    
    private function getTopClientsByBalanceDataFallback($limit = 10) {
        try {
            $stmt = $this->conn->prepare("
                SELECT 
                    c.client_name,
                    c.id as client_id,
                    COUNT(i.id) as invoice_count,
                    SUM(i.total - COALESCE(pa.amount_paid, 0)) as total_balance
                FROM invoices i
                LEFT JOIN client c ON c.id = i.client_id
                LEFT JOIN (
                    SELECT ra.invoice_id, SUM(ra.amount_applied) as amount_paid
                    FROM receipt_allocations ra
                    GROUP BY ra.invoice_id
                ) pa ON pa.invoice_id = i.id
                WHERE i.status IN ('issued', 'partially_paid')
                  AND " . ar_collectible_invoice_sql('i') . "
                  AND i.total > COALESCE(pa.amount_paid, 0)
                GROUP BY c.id, c.client_name
                HAVING total_balance > 0
                ORDER BY total_balance DESC
                LIMIT " . (int)$limit
            );
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Exception $e) {
            error_log("Fallback top clients by balance data error: " . $e->getMessage());
            return [];
        }
    }
    
    private function getPaymentTrendsDataFallback($months = 6) {
        try {
            $stmt = $this->conn->query("
                SELECT 
                    DATE_FORMAT(receipt_date, '%Y-%m') as month,
                    SUM(amount) as total_amount
                FROM receipts 
                WHERE receipt_date >= DATE_SUB(CURDATE(), INTERVAL " . (int)$months . " MONTH)
                GROUP BY DATE_FORMAT(receipt_date, '%Y-%m')
                ORDER BY month
            ");
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Exception $e) {
            error_log("Fallback payment trends data error: " . $e->getMessage());
            return [];
        }
    }
    
    private function getClientCreditDataFallback() {
        try {
            $stmt = $this->conn->query("SELECT * FROM v_ar_client_credit ORDER BY ar_total DESC");
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Exception $e) {
            error_log("Fallback client credit data error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Invalidate dashboard cache
     */
    public function invalidateDashboardCache() {
        $this->cache->forget('ar_summary');
        $this->cache->forget('ar_ageing');
        $this->cache->forget('top_overdue');
        $this->cache->forget('top_unbilled');
        $this->cache->forget('payment_trends');
        $this->cache->forget('client_credit');
        $this->cache->forget('charts_data');
        
        return true;
    }
    
    /**
     * Warm up all dashboard caches
     */
    public function warmUpDashboard() {
        return $this->cache->warmUp();
    }
    
    /**
     * Get cache statistics
     */
    public function getCacheStats() {
        return $this->cache->getStats();
    }
    
    /**
     * Clean expired cache entries
     */
    public function cleanExpiredCache() {
        return $this->cache->cleanExpired();
    }
}
?>
