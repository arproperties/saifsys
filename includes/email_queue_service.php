<?php
// includes/email_queue_service.php
// Email queue service for managing bulk email operations

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/email_service.php';

class EmailQueueService {
    private $conn;
    private $emailService;
    private $settings;

    public function __construct(PDO $conn) {
        $this->conn = $conn;
        $this->emailService = new EmailService($conn);
        $this->loadSettings();
    }

    private function loadSettings() {
        $stmt = $this->conn->query("SELECT setting_key, setting_value FROM email_queue_settings");
        $this->settings = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $this->settings[$row['setting_key']] = $row['setting_value'];
        }
    }

    /**
     * Create a new email queue for bulk operations
     */
    public function createQueue($queueName, $entityType, $entityIds, $templateId = null, $recipientEmails = null, $customMessage = null, $createdBy = null) {
        try {
            $this->conn->beginTransaction();

            // Create queue record
            $stmt = $this->conn->prepare("
                INSERT INTO email_queue (queue_name, entity_type, entity_ids, template_id, recipient_emails, custom_message, total_items, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $entityIdsJson = json_encode($entityIds);
            $recipientEmailsJson = $recipientEmails ? json_encode($recipientEmails) : null;
            
            $stmt->execute([
                $queueName,
                $entityType,
                $entityIdsJson,
                $templateId,
                $recipientEmailsJson,
                $customMessage,
                count($entityIds),
                $createdBy
            ]);
            
            $queueId = $this->conn->lastInsertId();

            // Create queue items
            $this->createQueueItems($queueId, $entityIds, $entityType, $recipientEmails);

            $this->conn->commit();
            return ['success' => true, 'queue_id' => $queueId];

        } catch (Exception $e) {
            $this->conn->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Create individual queue items for tracking
     */
    private function createQueueItems($queueId, $entityIds, $entityType, $recipientEmails = null) {
        $stmt = $this->conn->prepare("
            INSERT INTO email_queue_items (queue_id, entity_id, recipient_email, status)
            VALUES (?, ?, ?, 'pending')
        ");

        foreach ($entityIds as $entityId) {
            // Get recipient email for this entity
            $email = $this->getEntityEmail($entityType, $entityId, $recipientEmails);
            if ($email) {
                $stmt->execute([$queueId, $entityId, $email]);
            }
        }
    }

    /**
     * Get email address for an entity
     */
    private function getEntityEmail($entityType, $entityId, $recipientEmails = null) {
        if ($recipientEmails && isset($recipientEmails[$entityId])) {
            return $recipientEmails[$entityId];
        }

        switch ($entityType) {
            case 'invoice':
                $stmt = $this->conn->prepare("
                    SELECT c.email 
                    FROM invoices i 
                    LEFT JOIN client c ON c.id = i.client_id 
                    WHERE i.id = ?
                ");
                $stmt->execute([$entityId]);
                return $stmt->fetchColumn();
                
            case 'statement':
                $stmt = $this->conn->prepare("
                    SELECT email 
                    FROM client 
                    WHERE id = ?
                ");
                $stmt->execute([$entityId]);
                return $stmt->fetchColumn();
                
            default:
                return null;
        }
    }

    /**
     * Process a queue (send emails)
     */
    public function processQueue($queueId) {
        try {
            // Get queue details
            $stmt = $this->conn->prepare("SELECT * FROM email_queue WHERE id = ?");
            $stmt->execute([$queueId]);
            $queue = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$queue) {
                return ['success' => false, 'error' => 'Queue not found'];
            }

            if ($queue['status'] !== 'pending') {
                return ['success' => false, 'error' => 'Queue is not in pending status'];
            }

            // Update queue status to processing
            $this->conn->prepare("
                UPDATE email_queue 
                SET status = 'processing', started_at = NOW() 
                WHERE id = ?
            ")->execute([$queueId]);

            // Get pending items
            $stmt = $this->conn->prepare("
                SELECT * FROM email_queue_items 
                WHERE queue_id = ? AND status = 'pending' 
                ORDER BY id
            ");
            $stmt->execute([$queueId]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $processed = 0;
            $failed = 0;

            foreach ($items as $item) {
                $result = $this->processQueueItem($queue, $item);
                
                if ($result['success']) {
                    $processed++;
                } else {
                    $failed++;
                }
            }

            // Update queue status
            $status = $failed > 0 ? ($processed > 0 ? 'completed' : 'failed') : 'completed';
            $this->conn->prepare("
                UPDATE email_queue 
                SET status = ?, processed_items = ?, failed_items = ?, completed_at = NOW()
                WHERE id = ?
            ")->execute([$status, $processed, $failed, $queueId]);

            return [
                'success' => true, 
                'processed' => $processed, 
                'failed' => $failed,
                'status' => $status
            ];

        } catch (Exception $e) {
            // Mark queue as failed
            $this->conn->prepare("
                UPDATE email_queue 
                SET status = 'failed', error_message = ?, completed_at = NOW()
                WHERE id = ?
            ")->execute([$e->getMessage(), $queueId]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Process a single queue item
     */
    private function processQueueItem($queue, $item) {
        try {
            $result = null;

            switch ($queue['entity_type']) {
                case 'invoice':
                    $result = $this->emailService->sendInvoiceEmail(
                        $item['entity_id'], 
                        $item['recipient_email'], 
                        $queue['template_id']
                    );
                    break;
                    
                case 'statement':
                    // For statements, we need to get the date range from queue data
                    $entityIds = json_decode($queue['entity_ids'], true);
                    $fromDate = $entityIds[$item['entity_id']]['from_date'] ?? date('Y-m-01');
                    $toDate = $entityIds[$item['entity_id']]['to_date'] ?? date('Y-m-t');
                    
                    $result = $this->emailService->sendStatementEmail(
                        $item['entity_id'],
                        $fromDate,
                        $toDate,
                        $item['recipient_email'],
                        $queue['template_id']
                    );
                    break;
            }

            if ($result && $result['success']) {
                // Mark item as sent
                $this->conn->prepare("
                    UPDATE email_queue_items 
                    SET status = 'sent', sent_at = NOW() 
                    WHERE id = ?
                ")->execute([$item['id']]);
                
                return ['success' => true];
            } else {
                // Mark item as failed
                $error = $result['error'] ?? 'Unknown error';
                $this->conn->prepare("
                    UPDATE email_queue_items 
                    SET status = 'failed', error_message = ? 
                    WHERE id = ?
                ")->execute([$error, $item['id']]);
                
                return ['success' => false, 'error' => $error];
            }

        } catch (Exception $e) {
            // Mark item as failed
            $this->conn->prepare("
                UPDATE email_queue_items 
                SET status = 'failed', error_message = ? 
                WHERE id = ?
            ")->execute([$e->getMessage(), $item['id']]);
            
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get queue status and progress
     */
    public function getQueueStatus($queueId) {
        $stmt = $this->conn->prepare("
            SELECT 
                q.*,
                COUNT(qi.id) as total_items,
                SUM(CASE WHEN qi.status = 'sent' THEN 1 ELSE 0 END) as sent_items,
                SUM(CASE WHEN qi.status = 'failed' THEN 1 ELSE 0 END) as failed_items,
                SUM(CASE WHEN qi.status = 'pending' THEN 1 ELSE 0 END) as pending_items
            FROM email_queue q
            LEFT JOIN email_queue_items qi ON qi.queue_id = q.id
            WHERE q.id = ?
            GROUP BY q.id
        ");
        $stmt->execute([$queueId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Get all queues with pagination
     */
    public function getQueues($limit = 20, $offset = 0, $status = null) {
        // Cast limit and offset to integers
        $limit = (int)$limit;
        $offset = (int)$offset;
        
        // Build the query with proper integer binding for LIMIT and OFFSET
        $sql = "
            SELECT 
                q.*,
                COUNT(qi.id) as total_items,
                SUM(CASE WHEN qi.status = 'sent' THEN 1 ELSE 0 END) as sent_items,
                SUM(CASE WHEN qi.status = 'failed' THEN 1 ELSE 0 END) as failed_items,
                SUM(CASE WHEN qi.status = 'pending' THEN 1 ELSE 0 END) as pending_items
            FROM email_queue q
            LEFT JOIN email_queue_items qi ON qi.queue_id = q.id
        ";
        
        $params = [];
        
        if ($status) {
            $sql .= " WHERE q.status = ?";
            $params[] = $status;
        }
        
        $sql .= " GROUP BY q.id ORDER BY q.created_at DESC LIMIT " . (int)$limit . " OFFSET " . (int)$offset;
        
        $stmt = $this->conn->prepare($sql);
        
        if (!empty($params)) {
            $stmt->execute($params);
        } else {
            $stmt->execute();
        }
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Cancel a queue
     */
    public function cancelQueue($queueId) {
        try {
            $this->conn->beginTransaction();

            // Update queue status
            $this->conn->prepare("
                UPDATE email_queue 
                SET status = 'cancelled', completed_at = NOW()
                WHERE id = ? AND status = 'pending'
            ")->execute([$queueId]);

            // Update pending items to skipped
            $this->conn->prepare("
                UPDATE email_queue_items 
                SET status = 'skipped'
                WHERE queue_id = ? AND status = 'pending'
            ")->execute([$queueId]);

            $this->conn->commit();
            return ['success' => true];

        } catch (Exception $e) {
            $this->conn->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Retry failed items in a queue
     */
    public function retryFailedItems($queueId) {
        try {
            // Reset failed items to pending
            $this->conn->prepare("
                UPDATE email_queue_items 
                SET status = 'pending', error_message = NULL, retry_count = retry_count + 1
                WHERE queue_id = ? AND status = 'failed'
            ")->execute([$queueId]);

            // Reset queue status to pending
            $this->conn->prepare("
                UPDATE email_queue 
                SET status = 'pending', started_at = NULL, completed_at = NULL, error_message = NULL
                WHERE id = ?
            ")->execute([$queueId]);

            return ['success' => true];

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Clean up old completed queues
     */
    public function cleanupOldQueues() {
        // Delete completed/failed/cancelled queues older than 7 days
        $cleanupDays = 7;
        
        $stmt = $this->conn->prepare("
            DELETE FROM email_queue 
            WHERE status IN ('completed', 'failed', 'cancelled') 
            AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        $stmt->execute([$cleanupDays]);
        
        $deletedCompleted = $stmt->rowCount();
        
        // Also delete processing queues that are stuck for more than 1 day (definitely stuck/frozen)
        $stmt = $this->conn->prepare("
            DELETE FROM email_queue 
            WHERE status = 'processing' 
            AND created_at < DATE_SUB(NOW(), INTERVAL 1 DAY)
        ");
        $stmt->execute();
        
        $deletedProcessing = $stmt->rowCount();
        
        // Delete pending queues that are very old (more than 7 days) and never started
        $stmt = $this->conn->prepare("
            DELETE FROM email_queue 
            WHERE status = 'pending' 
            AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
            AND (started_at IS NULL OR started_at = '0000-00-00 00:00:00')
        ");
        $stmt->execute();
        
        $deletedPending = $stmt->rowCount();
        
        return $deletedCompleted + $deletedProcessing + $deletedPending;
    }

    /**
     * Get queue statistics
     */
    public function getQueueStats() {
        $stmt = $this->conn->query("
            SELECT 
                COUNT(*) as total_queues,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_queues,
                SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing_queues,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_queues,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_queues,
                SUM(total_items) as total_emails,
                SUM(processed_items) as processed_emails,
                SUM(failed_items) as failed_emails
            FROM email_queue
        ");
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
