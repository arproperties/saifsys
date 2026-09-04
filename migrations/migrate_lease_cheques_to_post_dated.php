<?php
/**
 * Migration Script: Create Post-Dated Cheques from Existing Lease Installments
 * 
 * This script creates cheques in re_post_dated_cheques table for existing leases
 * that have cheque payment method and installments but no cheques in re_post_dated_cheques.
 * 
 * Usage: php migrate_lease_cheques_to_post_dated.php
 */

// Try to use web-based includes first
if (file_exists(__DIR__ . '/../includes/db_connect.php')) {
    require_once __DIR__ . '/../includes/db_connect.php';
} elseif (file_exists(__DIR__ . '/../../includes/db_connect.php')) {
    require_once __DIR__ . '/../../includes/db_connect.php';
} else {
    // Direct database connection for CLI
    $host = 'localhost';
    $dbname = 'bestsys'; // Adjust based on your database name
    $username = 'root'; // Adjust based on your MySQL username
    $password = ''; // Adjust based on your MySQL password
    
    try {
        $conn = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (PDOException $e) {
        die("Database connection failed: " . $e->getMessage() . "\n");
    }
}

echo "Starting migration: Creating Post-Dated Cheques from Lease Installments...\n\n";

try {
    $conn->beginTransaction();
    
    // Find leases with cheque payment method that don't have cheques in re_post_dated_cheques
    $stmt = $conn->prepare("
        SELECT DISTINCT l.id as lease_id, l.company_id, l.payment_method, l.lease_number,
               t.first_name, t.last_name, l.created_by
        FROM re_leases l
        JOIN re_tenants t ON t.id = l.tenant_id
        LEFT JOIN re_post_dated_cheques c ON c.lease_id = l.id
        WHERE l.payment_method = 'cheque'
        AND c.id IS NULL
        ORDER BY l.id
    ");
    $stmt->execute();
    $leases = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $totalLeases = count($leases);
    echo "Found {$totalLeases} leases with cheque payment method that need cheques created.\n\n";
    
    if ($totalLeases === 0) {
        echo "No leases found. Migration not needed.\n";
        $conn->rollBack();
        exit(0);
    }
    
    $totalChequesCreated = 0;
    
    foreach ($leases as $lease) {
        echo "Processing Lease #{$lease['lease_id']} ({$lease['lease_number']})...\n";
        
        // Get all installments for this lease
        $installmentsStmt = $conn->prepare("
            SELECT id, installment_date, amount 
            FROM re_lease_installments 
            WHERE lease_id = ? 
            ORDER BY installment_date ASC
        ");
        $installmentsStmt->execute([$lease['lease_id']]);
        $installments = $installmentsStmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($installments)) {
            echo "  No installments found. Skipping...\n";
            continue;
        }
        
        $tenantName = trim($lease['first_name'] . ' ' . $lease['last_name']);
        $chequesCreatedForLease = 0;
        
        foreach ($installments as $index => $installment) {
            // Check if cheque already exists in re_post_dated_cheques
            $checkStmt = $conn->prepare("
                SELECT id FROM re_post_dated_cheques 
                WHERE lease_id = ? AND installment_id = ?
            ");
            $checkStmt->execute([$lease['lease_id'], $installment['id']]);
            if ($checkStmt->fetch()) {
                echo "  Cheque already exists for installment #{$installment['id']}. Skipping...\n";
                continue;
            }
            
            // Generate cheque number
            $chequeNumber = 'CHQ-' . $lease['lease_id'] . '-' . ($index + 1);
            
            // Insert into re_post_dated_cheques
            $insertStmt = $conn->prepare("
                INSERT INTO re_post_dated_cheques 
                (company_id, lease_id, installment_id, cheque_number, cheque_date, cheque_amount, 
                 account_holder_name, received_date, status, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, CURDATE(), 'pending', ?)
            ");
            $insertStmt->execute([
                $lease['company_id'],
                $lease['lease_id'],
                $installment['id'],
                $chequeNumber,
                $installment['installment_date'],
                $installment['amount'],
                $tenantName,
                $lease['created_by']
            ]);
            
            // Also check if it exists in re_lease_cheques, if not create it
            $checkLeaseChequeStmt = $conn->prepare("
                SELECT id FROM re_lease_cheques 
                WHERE lease_id = ? AND installment_id = ?
            ");
            $checkLeaseChequeStmt->execute([$lease['lease_id'], $installment['id']]);
            if (!$checkLeaseChequeStmt->fetch()) {
                $insertLeaseChequeStmt = $conn->prepare("
                    INSERT INTO re_lease_cheques 
                    (lease_id, installment_id, cheque_number, cheque_date, cheque_amount, cheque_holder_name, 
                     payment_method, status)
                    VALUES (?, ?, ?, ?, ?, ?, 'cheque', 'pending')
                ");
                $insertLeaseChequeStmt->execute([
                    $lease['lease_id'],
                    $installment['id'],
                    $chequeNumber,
                    $installment['installment_date'],
                    $installment['amount'],
                    $tenantName
                ]);
            }
            
            $chequesCreatedForLease++;
            $totalChequesCreated++;
            
            echo "  ✓ Created cheque #{$chequeNumber} for installment {$installment['installment_date']} ({$installment['amount']} AED)\n";
        }
        
        echo "  Created {$chequesCreatedForLease} cheques for this lease.\n\n";
    }
    
    $conn->commit();
    
    echo "\n========================================\n";
    echo "Migration completed successfully!\n";
    echo "Total leases processed: {$totalLeases}\n";
    echo "Total cheques created: {$totalChequesCreated}\n";
    echo "========================================\n";
    
} catch (Exception $e) {
    $conn->rollBack();
    echo "\nERROR: Migration failed!\n";
    echo "Error: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
    exit(1);
}

