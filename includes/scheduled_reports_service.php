<?php
// includes/scheduled_reports_service.php
// Scheduled reports service for managing automated report generation and delivery

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/email_service.php';
require_once __DIR__ . '/export_helpers.php';
require_once __DIR__ . '/composer_autoload_safe.php';
require_once __DIR__ . '/scheduled_reports_generator.php';

// Load Composer autoloader for Dompdf (safe on PHP 8.2 localhost / strict vendor platform checks)
herosysgro_composer_autoload_safe();

class ScheduledReportsService {
    private $conn;
    private $emailService;
    private $exportService;

    public function __construct(PDO $conn) {
        $this->conn = $conn;
        $this->emailService = new EmailService($conn);
        $this->exportService = new ExportService($conn);
    }

    /**
     * Get all scheduled reports with pagination
     */
    public function getScheduledReports($limit = 20, $offset = 0, $active_only = false) {
        $whereClause = $active_only ? "WHERE is_active = 1" : "";
        
        $stmt = $this->conn->prepare("
            SELECT 
                sr.*,
                COUNT(srr.id) as total_runs,
                MAX(srr.completed_at) as last_successful_run,
                COUNT(CASE WHEN srr.status = 'failed' THEN 1 END) as failed_runs
            FROM scheduled_reports sr
            LEFT JOIN scheduled_report_runs srr ON srr.scheduled_report_id = sr.id
            $whereClause
            GROUP BY sr.id
            ORDER BY sr.created_at DESC
            LIMIT " . (int)$limit . " OFFSET " . (int)$offset . "
        ");
        
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get a single scheduled report by ID
     */
    public function getScheduledReport($id) {
        $stmt = $this->conn->prepare("SELECT * FROM scheduled_reports WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Create a new scheduled report
     */
    public function createScheduledReport($data) {
        try {
            $this->conn->beginTransaction();

            // Calculate next run time
            $nextRun = $this->calculateNextRun($data['frequency'], $data['parameters'] ?? []);

            $stmt = $this->conn->prepare("
                INSERT INTO scheduled_reports 
                (report_name, report_type, frequency, parameters, email_recipients, 
                 export_format, is_active, next_run_at, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $data['report_name'],
                $data['report_type'],
                $data['frequency'],
                json_encode($data['parameters'] ?? []),
                $data['email_recipients'],
                $data['export_format'] ?? 'pdf',
                $data['is_active'] ?? 1,
                $nextRun,
                $data['created_by'] ?? null
            ]);

            $reportId = $this->conn->lastInsertId();
            $this->conn->commit();

            return ['success' => true, 'report_id' => $reportId];

        } catch (Exception $e) {
            $this->conn->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Update a scheduled report
     */
    public function updateScheduledReport($id, $data) {
        try {
            $this->conn->beginTransaction();

            // Calculate next run time if frequency changed
            $nextRun = null;
            if (isset($data['frequency'])) {
                $nextRun = $this->calculateNextRun($data['frequency'], $data['parameters'] ?? []);
            }

            $updateFields = [];
            $params = [];

            $allowedFields = ['report_name', 'report_type', 'frequency', 'parameters', 
                            'email_recipients', 'export_format', 'is_active'];
            
            foreach ($allowedFields as $field) {
                if (isset($data[$field])) {
                    $updateFields[] = "$field = ?";
                    if ($field === 'parameters') {
                        $params[] = json_encode($data[$field]);
                    } else {
                        $params[] = $data[$field];
                    }
                }
            }

            if ($nextRun) {
                $updateFields[] = "next_run_at = ?";
                $params[] = $nextRun;
            }

            if (!empty($updateFields)) {
                $updateFields[] = "updated_at = NOW()";
                $params[] = $id;

                $sql = "UPDATE scheduled_reports SET " . implode(', ', $updateFields) . " WHERE id = ?";
                $stmt = $this->conn->prepare($sql);
                $stmt->execute($params);
            }

            $this->conn->commit();
            return ['success' => true];

        } catch (Exception $e) {
            $this->conn->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Delete a scheduled report
     */
    public function deleteScheduledReport($id) {
        try {
            $this->conn->beginTransaction();

            // Delete related runs and recipients
            $this->conn->prepare("DELETE FROM scheduled_report_recipients WHERE run_id IN (SELECT id FROM scheduled_report_runs WHERE scheduled_report_id = ?)")
                       ->execute([$id]);
            $this->conn->prepare("DELETE FROM scheduled_report_runs WHERE scheduled_report_id = ?")
                       ->execute([$id]);
            $this->conn->prepare("DELETE FROM scheduled_reports WHERE id = ?")
                       ->execute([$id]);

            $this->conn->commit();
            return ['success' => true];

        } catch (Exception $e) {
            $this->conn->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get reports that are due to run
     */
    public function getDueReports() {
        $stmt = $this->conn->prepare("
            SELECT * FROM scheduled_reports 
            WHERE is_active = 1 
            AND next_run_at <= NOW()
            ORDER BY next_run_at ASC
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Process a scheduled report (generate and send)
     * @param bool $emailOnly If false and $downloadOnly true, skip email
     */
    public function processScheduledReport($reportId, bool $downloadOnly = false) {
        try {
            $report = $this->getScheduledReport($reportId);
            if (!$report) {
                return ['success' => false, 'error' => 'Report not found'];
            }

            $runId = $this->createRunRecord($reportId);
            $result = $this->generateReport($report, $runId);
            if (!$result['success']) {
                $this->updateRunStatus($runId, 'failed', null, $result['error']);
                return $result;
            }

            if (!$downloadOnly) {
                $emailResult = $this->sendReportEmails($report, $runId, $result['file_path']);
                if (!$emailResult['success']) {
                    $this->updateRunStatus($runId, 'failed', $result['file_path'], $emailResult['error']);
                    return array_merge($emailResult, [
                        'file_path' => $result['file_path'],
                        'download_hint' => 'Report was generated but email failed. You can download it from history.',
                    ]);
                }
            }

            $this->updateNextRunTime($reportId, $report['frequency'], $report['parameters']);
            $this->updateRunStatus($runId, 'completed', $result['file_path']);

            return [
                'success' => true,
                'run_id' => $runId,
                'file_path' => $result['file_path'],
                'file_size' => $result['file_size'] ?? null,
            ];

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /** @return array<string, array> */
    public function getReportTypeCatalog(): array
    {
        return scheduled_report_type_catalog();
    }

    /**
     * Calculate next run time based on frequency
     */
    private function calculateNextRun($frequency, $parameters = []) {
        if (is_string($parameters)) {
            $parameters = json_decode($parameters, true) ?: [];
        }
        $now = new DateTime();
        
        switch ($frequency) {
            case 'daily':
                return $now->modify('+1 day')->format('Y-m-d H:i:s');
                
            case 'weekly':
                $dayOfWeek = $parameters['day_of_week'] ?? 1; // Monday = 1
                $time = $parameters['time'] ?? '09:00';
                $next = clone $now;
                $next->modify("next Monday +{$dayOfWeek} days");
                $next->setTime(...explode(':', $time));
                return $next->format('Y-m-d H:i:s');
                
            case 'monthly':
                $dayOfMonth = $parameters['day_of_month'] ?? 1;
                $time = $parameters['time'] ?? '09:00';
                $next = clone $now;
                $next->modify("first day of next month +{$dayOfMonth} days");
                $next->setTime(...explode(':', $time));
                return $next->format('Y-m-d H:i:s');
                
            case 'quarterly':
                $dayOfQuarter = $parameters['day_of_quarter'] ?? 1;
                $time = $parameters['time'] ?? '09:00';
                $next = clone $now;
                $next->modify("first day of +3 months");
                $next->setTime(...explode(':', $time));
                return $next->format('Y-m-d H:i:s');
                
            case 'yearly':
                $month = $parameters['month'] ?? 1;
                $day = $parameters['day'] ?? 1;
                $time = $parameters['time'] ?? '09:00';
                $next = clone $now;
                $next->modify("+1 year");
                $next->setDate($next->format('Y'), $month, $day);
                $next->setTime(...explode(':', $time));
                return $next->format('Y-m-d H:i:s');
                
            default:
                return $now->modify('+1 day')->format('Y-m-d H:i:s');
        }
    }

    /**
     * Create a run record
     */
    private function createRunRecord($reportId) {
        $stmt = $this->conn->prepare("
            INSERT INTO scheduled_report_runs (scheduled_report_id, created_by)
            VALUES (?, ?)
        ");
        $stmt->execute([$reportId, $_SESSION['user_id'] ?? null]);
        return $this->conn->lastInsertId();
    }

    /**
     * Generate the actual report file
     */

    /**
     * Generate the actual report file
     */
    private function generateReport($report, $runId) {
        try {
            $parameters = json_decode($report['parameters'] ?? '{}', true) ?: [];
            if (!is_array($parameters)) {
                $parameters = [];
            }
            $format = strtolower((string)($report['export_format'] ?? 'pdf'));
            if ($format === 'xlsx') {
                $format = 'excel';
            }
            if (!in_array($format, ['pdf', 'csv', 'excel'], true)) {
                $format = 'pdf';
            }

            $built = scheduled_reports_build_report(
                $this->conn,
                (string)$report['report_type'],
                $parameters,
                $format === 'excel' ? 'csv' : $format,
                (string)($report['frequency'] ?? 'monthly')
            );

            return ['success' => true] + $built;
        } catch (Throwable $e) {
            error_log('Scheduled report generation failed: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Send report emails to recipients
     */
    private function sendReportEmails($report, $runId, $filePath) {
        try {
            $recipients = array_filter(array_map('trim', explode(',', $report['email_recipients'])));
            $sentCount = 0;

            foreach ($recipients as $email) {
                if (empty($email)) continue;

                // Create recipient record
                $this->createRecipientRecord($runId, $email);

                // Send email with attachment
                $subject = "Scheduled Report: {$report['report_name']}";
                
                // Create proper HTML email body
                $bodyHtml = "<html><body style='font-family: Arial, sans-serif;'>";
                $bodyHtml .= "<h2 style='color: #333;'>Scheduled Report: {$report['report_name']}</h2>";
                $bodyHtml .= "<p>Please find attached the scheduled report.</p>";
                $bodyHtml .= "<div style='background-color: #f5f5f5; padding: 15px; border-radius: 5px; margin: 15px 0;'>";
                $bodyHtml .= "<p><strong>Generated on:</strong> " . date('Y-m-d H:i:s') . "</p>";
                $bodyHtml .= "<p><strong>Report Type:</strong> " . ucfirst($report['report_type']) . "</p>";
                $bodyHtml .= "<p><strong>Export Format:</strong> " . strtoupper($report['export_format']) . "</p>";
                $bodyHtml .= "</div>";
                $bodyHtml .= "<p style='color: #666; font-size: 12px;'>This is an automated email from BMSystem.</p>";
                $bodyHtml .= "</body></html>";
                
                // Create plain text version
                $bodyPlain = "Scheduled Report: {$report['report_name']}\n\n";
                $bodyPlain .= "Please find attached the scheduled report.\n\n";
                $bodyPlain .= "Generated on: " . date('Y-m-d H:i:s') . "\n";
                $bodyPlain .= "Report Type: " . ucfirst($report['report_type']) . "\n";
                $bodyPlain .= "Export Format: " . strtoupper($report['export_format']) . "\n";
                $bodyPlain .= "\nThis is an automated email from BMSystem.";

                // Prepare attachment data
                $attachment = [];
                if (file_exists($filePath) && filesize($filePath) > 0) {
                    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
                    $mime = match ($ext) {
                        'csv' => 'text/csv',
                        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        default => 'application/pdf',
                    };
                    $attachment = [
                        'path' => $filePath,
                        'name' => basename($filePath),
                        'type' => $mime,
                    ];
                    error_log("Attachment prepared for {$email}: {$filePath}, size: " . filesize($filePath) . " bytes");
                } else {
                    error_log("WARNING: Attachment file not found or empty: {$filePath}");
                }
                
                $result = $this->emailService->sendCustomEmail(
                    $email,
                    $subject,
                    $bodyHtml,
                    $bodyPlain,
                    $attachment ? [$attachment] : []
                );

                if ($result['success']) {
                    $this->updateRecipientStatus($runId, $email, 'sent');
                    $sentCount++;
                } else {
                    $this->updateRecipientStatus($runId, $email, 'failed', $result['error']);
                }
            }

            // Update run with recipient count
            $this->conn->prepare("UPDATE scheduled_report_runs SET recipients_count = ? WHERE id = ?")
                       ->execute([$sentCount, $runId]);

            return ['success' => true, 'sent_count' => $sentCount];

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Create recipient record
     */
    private function createRecipientRecord($runId, $email) {
        $stmt = $this->conn->prepare("
            INSERT INTO scheduled_report_recipients (run_id, email_address)
            VALUES (?, ?)
        ");
        $stmt->execute([$runId, $email]);
    }

    /**
     * Update recipient status
     */
    private function updateRecipientStatus($runId, $email, $status, $error = null) {
        $stmt = $this->conn->prepare("
            UPDATE scheduled_report_recipients 
            SET status = ?, sent_at = ?, error_message = ?
            WHERE run_id = ? AND email_address = ?
        ");
        $sentAt = $status === 'sent' ? date('Y-m-d H:i:s') : null;
        $stmt->execute([$status, $sentAt, $error, $runId, $email]);
    }

    /**
     * Update run status
     */
    private function updateRunStatus($runId, $status, $filePath = null, $error = null) {
        $stmt = $this->conn->prepare("
            UPDATE scheduled_report_runs 
            SET status = ?, completed_at = ?, file_path = ?, error_message = ?
            WHERE id = ?
        ");
        $completedAt = in_array($status, ['completed', 'failed']) ? date('Y-m-d H:i:s') : null;
        $stmt->execute([$status, $completedAt, $filePath, $error, $runId]);
    }

    /**
     * Update next run time
     */
    private function updateNextRunTime($reportId, $frequency, $parameters) {
        $nextRun = $this->calculateNextRun($frequency, $parameters);
        $this->conn->prepare("
            UPDATE scheduled_reports 
            SET last_run_at = NOW(), next_run_at = ?
            WHERE id = ?
        ")->execute([$nextRun, $reportId]);
    }

    /**
     * Get run history for a report
     */
    public function getRunHistory($reportId, $limit = 10) {
        $stmt = $this->conn->prepare("
            SELECT srr.*, 
                   COUNT(sr.email_address) as total_recipients,
                   COUNT(CASE WHEN sr.status = 'sent' THEN 1 END) as sent_recipients
            FROM scheduled_report_runs srr
            LEFT JOIN scheduled_report_recipients sr ON sr.run_id = srr.id
            WHERE srr.scheduled_report_id = ?
            GROUP BY srr.id
            ORDER BY srr.started_at DESC
            LIMIT " . (int)$limit . "
        ");
        $stmt->execute([$reportId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get statistics
     */
    public function getStatistics() {
        $stmt = $this->conn->query("
            SELECT 
                COUNT(*) as total_reports,
                SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_reports,
                COUNT(DISTINCT srr.scheduled_report_id) as reports_with_runs,
                SUM(CASE WHEN srr.status = 'completed' THEN 1 ELSE 0 END) as successful_runs,
                SUM(CASE WHEN srr.status = 'failed' THEN 1 ELSE 0 END) as failed_runs
            FROM scheduled_reports sr
            LEFT JOIN scheduled_report_runs srr ON srr.scheduled_report_id = sr.id
        ");
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

}
