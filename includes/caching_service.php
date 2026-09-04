<?php
/**
 * Caching Service
 * Provides intelligent caching for KPIs and frequently accessed data
 */

require_once __DIR__ . '/ar_helpers.php';
require_once __DIR__ . '/service_accounting_service.php';

class CachingService {
    private $conn;
    private $cache_duration = [
        'dashboard' => 5,      // 5 minutes
        'reports' => 15,       // 15 minutes
        'search' => 30,        // 30 minutes
        'static' => 60         // 1 hour
    ];
    
    public function __construct($conn) {
        $this->conn = $conn;
    }
    
    /**
     * Get cached data
     */
    public function get($key, $type = 'dashboard') {
        try {
            $stmt = $this->conn->prepare("
                SELECT cache_data, expires_at, is_compressed 
                FROM dashboard_cache 
                WHERE cache_key = ? AND expires_at > NOW()
            ");
            $stmt->execute([$key]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                $data = $result['cache_data'];
                if ($result['is_compressed']) {
                    $data = gzuncompress(base64_decode($data));
                }
                return json_decode($data, true);
            }
            
            return null;
        } catch (Exception $e) {
            error_log("Cache get error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Set cached data
     */
    public function set($key, $data, $type = 'dashboard') {
        try {
            $duration = $this->cache_duration[$type] ?? 5;
            $expires_at = date('Y-m-d H:i:s', strtotime("+{$duration} minutes"));
            
            $stmt = $this->conn->prepare("
                INSERT INTO dashboard_cache (cache_key, cache_data, expires_at, is_compressed)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    cache_data = VALUES(cache_data),
                    expires_at = VALUES(expires_at),
                    is_compressed = VALUES(is_compressed),
                    updated_at = NOW()
            ");
            
            // Compress large data to avoid packet size issues
            $jsonData = json_encode($data);
            if (strlen($jsonData) > 500000) { // If larger than 500KB, compress
                $jsonData = base64_encode(gzcompress($jsonData, 6));
                $isCompressed = 1;
            } else {
                $isCompressed = 0;
            }
            
            $stmt->execute([
                $key,
                $jsonData,
                $expires_at,
                $isCompressed
            ]);
            
            return true;
        } catch (PDOException $e) {
            // If we get a "MySQL server has gone away" error, try to reconnect
            if (strpos($e->getMessage(), 'MySQL server has gone away') !== false) {
                $this->reconnect();
                try {
                    $duration = $this->cache_duration[$type] ?? 5;
                    $expires_at = date('Y-m-d H:i:s', strtotime("+{$duration} minutes"));
                    
                    $stmt = $this->conn->prepare("
                        INSERT INTO dashboard_cache (cache_key, cache_data, expires_at, is_compressed)
                        VALUES (?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE
                            cache_data = VALUES(cache_data),
                            expires_at = VALUES(expires_at),
                            is_compressed = VALUES(is_compressed),
                            updated_at = NOW()
                    ");
                    
                    // Compress large data to avoid packet size issues
                    $jsonData = json_encode($data);
                    if (strlen($jsonData) > 500000) { // If larger than 500KB, compress
                        $jsonData = base64_encode(gzcompress($jsonData, 6));
                        $isCompressed = 1;
                    } else {
                        $isCompressed = 0;
                    }
                    
                    $stmt->execute([
                        $key,
                        $jsonData,
                        $expires_at,
                        $isCompressed
                    ]);
                    
                    return true;
                } catch (Exception $retryException) {
                    error_log("Cache set retry failed: " . $retryException->getMessage());
                    return false;
                }
            }
            error_log("Cache set error: " . $e->getMessage());
            return false;
        } catch (Exception $e) {
            error_log("Cache set error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get or set cached data with callback
     */
    public function remember($key, $callback, $type = 'dashboard') {
        $cached = $this->get($key, $type);
        
        if ($cached !== null) {
            return $cached;
        }
        
        try {
            $data = $callback();
            $this->set($key, $data, $type);
            return $data;
        } catch (PDOException $e) {
            // If we get a "MySQL server has gone away" error, try to reconnect
            if (strpos($e->getMessage(), 'MySQL server has gone away') !== false) {
                $this->reconnect();
                try {
                    $data = $callback();
                    $this->set($key, $data, $type);
                    return $data;
                } catch (Exception $retryException) {
                    error_log("Cache retry failed: " . $retryException->getMessage());
                    // Return empty data to prevent complete failure
                    return [];
                }
            }
            throw $e;
        }
    }
    
    /**
     * Reconnect to database
     */
    private function reconnect() {
        try {
            $this->conn = null;
            require_once __DIR__ . '/db_connect.php';
            $this->conn = $GLOBALS['conn'];
        } catch (Exception $e) {
            error_log("Database reconnection failed: " . $e->getMessage());
        }
    }
    
    /**
     * Clear cache by key
     */
    public function forget($key) {
        try {
            $stmt = $this->conn->prepare("DELETE FROM dashboard_cache WHERE cache_key = ?");
            $stmt->execute([$key]);
            return true;
        } catch (Exception $e) {
            error_log("Cache forget error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Clear all expired cache
     */
    public function cleanExpired() {
        try {
            $stmt = $this->conn->prepare("DELETE FROM dashboard_cache WHERE expires_at < NOW()");
            $stmt->execute();
            return $stmt->rowCount();
        } catch (Exception $e) {
            error_log("Cache clean error: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Get cached AR summary data
     */
    public function getARSummary() {
        return $this->remember('ar_summary', function() {
            $svc = new ServiceAccountingService($this->conn);
            return $svc->getARSummary();
        }, 'dashboard');
    }
    
    /**
     * Get cached AR ageing data
     */
    public function getARAgeing() {
        return $this->remember('ar_ageing', function() {
            $svc = new ServiceAccountingService($this->conn);
            return $svc->getAgeingBuckets();
        }, 'dashboard');
    }
    
    /**
     * Get cached top overdue invoices
     */
    public function getTopOverdue($limit = 10) {
        return $this->remember("top_overdue_{$limit}", function() use ($limit) {
            $svc = new ServiceAccountingService($this->conn);
            return $svc->getTopOverdue((int)$limit);
        }, 'dashboard');
    }
    
    /**
     * Get cached top clients by outstanding balance (grouped by client)
     */
    public function getTopClientsByBalance($limit = 10) {
        return $this->remember("top_clients_balance_{$limit}", function() use ($limit) {
            $svc = new ServiceAccountingService($this->conn);
            return $svc->getTopClientsByBalance((int)$limit);
        }, 'dashboard');
    }
    
    /**
     * Get cached top unbilled clients
     */
    public function getTopUnbilled($limit = 10) {
        return $this->remember("top_unbilled_{$limit}", function() use ($limit) {
            $stmt = $this->conn->prepare("
                SELECT c.client_name, u.client_id, u.orders_cnt, u.hours, u.grand_total
                FROM v_client_unbilled_summary u
                JOIN client c ON c.id = u.client_id
                WHERE u.grand_total > 0
                ORDER BY u.grand_total DESC
                LIMIT " . (int)$limit
            );
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }, 'dashboard');
    }
    
    /**
     * Get cached payment trends data
     */
    public function getPaymentTrends($months = 6) {
        return $this->remember("payment_trends_{$months}", function() use ($months) {
            $stmt = $this->conn->prepare("
                SELECT 
                    DATE_FORMAT(r.receipt_date, '%Y-%m') as month,
                    SUM(r.amount) as total_amount,
                    COUNT(r.id) as payment_count
                FROM receipts r
                WHERE r.receipt_date >= DATE_SUB(CURDATE(), INTERVAL " . (int)$months . " MONTH)
                GROUP BY DATE_FORMAT(r.receipt_date, '%Y-%m')
                ORDER BY month
            ");
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }, 'dashboard');
    }
    
    /**
     * Get cached client credit data
     */
    public function getClientCredit() {
        return $this->remember('client_credit', function() {
            $stmt = $this->conn->query("
                SELECT client_id, client_name, terms, credit_limit, ar_total, available_credit
                FROM v_ar_client_credit
                ORDER BY available_credit ASC
            ");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }, 'dashboard');
    }
    
    /**
     * Get cached report data
     */
    public function getReportData($report_type, $parameters = []) {
        $params_hash = md5(json_encode($parameters));
        $key = "report_{$report_type}_{$params_hash}";
        
        return $this->remember($key, function() use ($report_type, $parameters) {
            return $this->generateReportData($report_type, $parameters);
        }, 'reports');
    }
    
    /**
     * Generate report data (placeholder for actual report generation)
     */
    private function generateReportData($report_type, $parameters) {
        // This would contain the actual report generation logic
        // For now, return empty array as placeholder
        return [];
    }
    
    /**
     * Invalidate cache by pattern
     */
    public function invalidatePattern($pattern) {
        try {
            $stmt = $this->conn->prepare("DELETE FROM dashboard_cache WHERE cache_key LIKE ?");
            $stmt->execute(["%{$pattern}%"]);
            return $stmt->rowCount();
        } catch (Exception $e) {
            error_log("Cache invalidate pattern error: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Get cache statistics
     */
    public function getStats() {
        try {
            $stmt = $this->conn->query("
                SELECT 
                    COUNT(*) as total_entries,
                    COUNT(CASE WHEN expires_at > NOW() THEN 1 END) as active_entries,
                    COUNT(CASE WHEN expires_at <= NOW() THEN 1 END) as expired_entries,
                    AVG(TIMESTAMPDIFF(MINUTE, created_at, expires_at)) as avg_duration_minutes
                FROM dashboard_cache
            ");
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Cache stats error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Warm up cache with frequently accessed data
     */
    public function warmUp() {
        $this->getARSummary();
        $this->getARAgeing();
        $this->getTopOverdue();
        $this->getTopUnbilled();
        $this->getPaymentTrends();
        $this->getClientCredit();
        
        return true;
    }
}
?>
