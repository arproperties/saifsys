<?php
/**
 * Real Estate Accounting Engine
 * Core double-entry accounting functions
 * 
 * This file contains all core accounting functions for posting transactions
 * to the general ledger using double-entry accounting principles.
 */

require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/company_helper.php';

/**
 * Log accounting action to domain audit log and bridge a summary to central audit_log.
 */
function accounting_audit_log($companyId, $action, $entityType, $entityId, $userId = null, $details = null) {
    global $conn;
    try {
        $stmt = $conn->prepare("
            INSERT INTO re_accounting_audit_log (company_id, action, entity_type, entity_id, user_id, ip_address, details)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $companyId,
            $action,
            $entityType,
            $entityId ?: null,
            $userId,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $details
        ]);
    } catch (Exception $e) {
        error_log("accounting_audit_log: " . $e->getMessage());
    }

    // Owner-facing central summary (domain table remains forensic SoR)
    try {
        require_once __DIR__ . '/../../../includes/audit_bridge.php';
        $module = 'realestate';
        try {
            $bt = $conn->prepare('SELECT business_type FROM companies WHERE id = ? LIMIT 1');
            $bt->execute([(int)$companyId]);
            $businessType = (string)($bt->fetchColumn() ?: '');
            if ($businessType === 'construction') {
                $module = 'construction';
            } elseif ($businessType === 'short_term_rental' || $businessType === 'ars') {
                $module = 'ars';
            }
        } catch (Throwable $ignored) {
            // keep realestate default
        }
        $bridgeAction = $action;
        if ($action === 'post' || $action === 'posted') {
            $bridgeAction = 'journal_posted';
        } elseif ($action === 'reverse' || $action === 'reversed') {
            $bridgeAction = 'journal_reversed';
        } elseif ($action === 'hard_delete_expense') {
            $bridgeAction = 'hard_delete';
        } elseif (in_array($action, ['delete_expense', 'delete_vendor', 'delete_supplier'], true)) {
            $bridgeAction = 'delete';
        }
        audit_bridge_re_accounting(
            $conn,
            (int)$companyId,
            $bridgeAction,
            (string)$entityType,
            $entityId,
            $userId !== null ? (int)$userId : null,
            $details,
            $module
        );
    } catch (Throwable $e) {
        error_log('accounting_audit_log bridge: ' . $e->getMessage());
    }
}

/**
 * Check if an accounting period is locked for the given date.
 * A period is locked if the date falls within a closed fiscal year.
 *
 * @param int $companyId Company ID
 * @param string $date Date (YYYY-MM-DD)
 * @return bool True if period is locked (no new/changed transactions allowed)
 */
function is_period_locked($companyId, $date) {
    global $conn;
    try {
        $stmt = $conn->prepare("
            SELECT 1 FROM re_fiscal_years
            WHERE company_id = ? AND is_closed = 1
            AND ? BETWEEN start_date AND end_date
            LIMIT 1
        ");
        $stmt->execute([$companyId, $date]);
        return (bool) $stmt->fetch();
    } catch (Exception $e) {
        error_log("is_period_locked: " . $e->getMessage());
        return false;
    }
}

/**
 * Check for duplicate journal by reference (same source document already posted).
 * Only applies when both referenceType and referenceId are non-null.
 *
 * @param int $companyId Company ID
 * @param string $referenceType Source document type (e.g. invoice, payment)
 * @param int|null $referenceId Source document ID
 * @param int|null $excludeJournalId Journal ID to exclude (e.g. when updating)
 * @return bool True if duplicate exists
 */
function journal_duplicate_exists($companyId, $referenceType, $referenceId, $excludeJournalId = null) {
    global $conn;
    if ($referenceType === null || $referenceType === '' || $referenceId === null) {
        return false;
    }
    try {
        $sql = "
            SELECT 1 FROM re_journal_headers
            WHERE company_id = ? AND reference_type = ? AND reference_id = ?
            AND is_posted = 1 AND (is_reversed = 0 OR is_reversed IS NULL)
            AND journal_type != 'reversal'
        ";
        $params = [$companyId, $referenceType, $referenceId];
        if ($excludeJournalId !== null) {
            $sql .= " AND id != ?";
            $params[] = $excludeJournalId;
        }
        $sql .= " LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        return (bool) $stmt->fetch();
    } catch (Exception $e) {
        error_log("journal_duplicate_exists: " . $e->getMessage());
        return false;
    }
}

/**
 * Create a journal entry (unposted)
 * 
 * @param int $companyId Company ID
 * @param string $journalType Type of journal (manual, invoice, payment, deposit, refund, adjustment, recurring, reversal)
 * @param string $referenceType Source document type (invoice, payment, lease, etc.)
 * @param int|null $referenceId ID of source document
 * @param array $lines Array of journal lines [['account_id' => X, 'debit' => Y, 'credit' => Z, 'description' => '...'], ...]
 * @param string $description Journal description
 * @param string|null $journalDate Journal date (defaults to today)
 * @param int|null $createdBy User ID who created the journal
 * @return array ['success' => bool, 'journal_id' => int|null, 'error' => string|null]
 */
function create_journal_entry($companyId, $journalType, $referenceType, $referenceId, $lines, $description = '', $journalDate = null, $createdBy = null) {
    global $conn;
    
    try {
        // Validate company ID
        if (empty($companyId)) {
            return ['success' => false, 'journal_id' => null, 'error' => 'Company ID is required'];
        }
        
        // Validate journal type
        $validTypes = ['manual', 'invoice', 'payment', 'deposit', 'refund', 'adjustment', 'recurring', 'reversal', 'opening_balance', 'closing', 'credit_note', 'expense'];
        if (!in_array($journalType, $validTypes)) {
            return ['success' => false, 'journal_id' => null, 'error' => 'Invalid journal type'];
        }

        // Backdating / period lock: do not allow new entries in a closed period
        if (is_period_locked($companyId, $journalDate)) {
            return ['success' => false, 'journal_id' => null, 'error' => 'Cannot add or change transactions in a closed period. The selected date falls in a locked fiscal year.'];
        }

        // Duplicate prevention: one posted journal per (reference_type, reference_id).
        // Skip for: reversal journals (the original is still posted when the reversal is created),
        // recurring_journal and credit_note references (naturally produce multiple journals).
        $skipDuplicateCheck = ($journalType === 'reversal')
            || in_array($referenceType, ['recurring_journal', 'credit_note'], true);
        if (!$skipDuplicateCheck && journal_duplicate_exists($companyId, $referenceType, $referenceId, null)) {
            return ['success' => false, 'journal_id' => null, 'error' => 'A journal entry for this reference already exists and is posted. Duplicate entries are not allowed.'];
        }
        
        // Validate lines
        if (empty($lines) || !is_array($lines)) {
            return ['success' => false, 'journal_id' => null, 'error' => 'Journal lines are required'];
        }
        
        // Calculate totals and validate double-entry
        $totalDebit = 0;
        $totalCredit = 0;
        $lineNumber = 1;
        
        foreach ($lines as $line) {
            if (empty($line['account_id'])) {
                return ['success' => false, 'journal_id' => null, 'error' => 'Account ID is required for all lines'];
            }
            
            $debit = isset($line['debit']) ? (float)$line['debit'] : 0;
            $credit = isset($line['credit']) ? (float)$line['credit'] : 0;
            
            // Validate: each line must have either debit OR credit, not both
            if ($debit > 0 && $credit > 0) {
                return ['success' => false, 'journal_id' => null, 'error' => "Line $lineNumber: Cannot have both debit and credit"];
            }
            
            // Validate: each line must have at least one amount
            if ($debit == 0 && $credit == 0) {
                return ['success' => false, 'journal_id' => null, 'error' => "Line $lineNumber: Must have either debit or credit amount"];
            }
            
            $totalDebit += $debit;
            $totalCredit += $credit;
            $lineNumber++;
        }
        
        // Validate: Debit must equal Credit (double-entry principle)
        if (abs($totalDebit - $totalCredit) > 0.01) { // Allow small rounding differences
            return ['success' => false, 'journal_id' => null, 'error' => "Journal does not balance. Debit: $totalDebit, Credit: $totalCredit"];
        }
        
        // Set default date
        if (empty($journalDate)) {
            $journalDate = date('Y-m-d');
        }
        
        // Generate journal number
        $journalNumber = generate_journal_number($companyId, $journalDate);
        if (!$journalNumber) {
            return ['success' => false, 'journal_id' => null, 'error' => 'Failed to generate journal number'];
        }
        
        // Transaction-aware: callers such as HR payroll may already be posting
        // inside their own transaction.
        $ownsTransaction = !$conn->inTransaction();
        if ($ownsTransaction) {
            $conn->beginTransaction();
        }
        
        // Insert journal header
        $stmt = $conn->prepare("
            INSERT INTO re_journal_headers 
            (company_id, journal_number, journal_date, journal_type, reference_type, reference_id, 
             description, total_debit, total_credit, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $companyId, $journalNumber, $journalDate, $journalType, $referenceType, $referenceId,
            $description, $totalDebit, $totalCredit, $createdBy
        ]);
        $journalId = $conn->lastInsertId();
        
        // Insert journal lines
        $lineNumber = 1;
        foreach ($lines as $line) {
            $debit = isset($line['debit']) ? (float)$line['debit'] : 0;
            $credit = isset($line['credit']) ? (float)$line['credit'] : 0;
            $lineDescription = $line['description'] ?? '';
            $reference = $line['reference'] ?? null;
            
            $stmt = $conn->prepare("
                INSERT INTO re_journal_lines 
                (company_id, journal_id, account_id, line_number, debit_amount, credit_amount, description, reference)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $companyId, $journalId, $line['account_id'], $lineNumber, $debit, $credit, $lineDescription, $reference
            ]);
            $lineNumber++;
        }
        
        // Commit transaction
        if ($ownsTransaction) {
            $conn->commit();
        }
        
        return ['success' => true, 'journal_id' => $journalId, 'journal_number' => $journalNumber, 'error' => null];
        
    } catch (Exception $e) {
        if (isset($ownsTransaction) && $ownsTransaction && $conn->inTransaction()) {
            $conn->rollBack();
        }
        return ['success' => false, 'journal_id' => null, 'error' => $e->getMessage()];
    }
}

/**
 * Post a journal entry to the general ledger
 * 
 * @param int $journalId Journal ID to post
 * @param int|null $postedBy User ID who posted the journal
 * @return array ['success' => bool, 'error' => string|null]
 */
function post_journal($journalId, $postedBy = null) {
    global $conn;
    
    try {
        // Get journal header
        $stmt = $conn->prepare("
            SELECT * FROM re_journal_headers 
            WHERE id = ? AND is_posted = 0
        ");
        $stmt->execute([$journalId]);
        $journal = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$journal) {
            return ['success' => false, 'error' => 'Journal not found or already posted'];
        }

        // Optional: when approval workflow is used, only approved journals can be posted.
        // To enforce approval, uncomment the next block (then draft/submitted cannot be posted).
        // if (array_key_exists('approval_status', $journal) && $journal['approval_status'] !== 'approved') {
        //     return ['success' => false, 'error' => 'Journal must be approved before it can be posted.'];
        // }

        // Period lock: do not allow posting into a closed period
        if (is_period_locked($journal['company_id'], $journal['journal_date'])) {
            return ['success' => false, 'error' => 'Cannot post this journal: the journal date falls in a closed (locked) period.'];
        }

        // Get journal lines
        $stmt = $conn->prepare("
            SELECT * FROM re_journal_lines 
            WHERE journal_id = ? 
            ORDER BY line_number
        ");
        $stmt->execute([$journalId]);
        $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($lines)) {
            return ['success' => false, 'error' => 'Journal has no lines'];
        }
        
        // Transaction-aware: callers such as HR payroll may already be posting
        // inside their own transaction.
        $ownsTransaction = !$conn->inTransaction();
        if ($ownsTransaction) {
            $conn->beginTransaction();
        }
        
        // Post each line to general ledger
        foreach ($lines as $line) {
            // Get current balance for this account
            $stmt = $conn->prepare("
                SELECT balance FROM re_general_ledger 
                WHERE account_id = ? AND company_id = ?
                ORDER BY entry_date DESC, id DESC 
                LIMIT 1
            ");
            $stmt->execute([$line['account_id'], $journal['company_id']]);
            $lastEntry = $stmt->fetch(PDO::FETCH_ASSOC);
            $previousBalance = $lastEntry ? (float)$lastEntry['balance'] : 0;
            
            // Calculate new balance based on account type
            $account = get_account($line['account_id'], $journal['company_id']);
            if (!$account) {
                throw new Exception("Account not found: {$line['account_id']}");
            }
            
            // Calculate new balance
            if ($account['normal_balance'] == 'debit') {
                // Asset/Expense: Debit increases, Credit decreases
                $newBalance = $previousBalance + $line['debit_amount'] - $line['credit_amount'];
            } else {
                // Liability/Equity/Income: Credit increases, Debit decreases
                $newBalance = $previousBalance - $line['debit_amount'] + $line['credit_amount'];
            }
            
            // Insert into general ledger
            $stmt = $conn->prepare("
                INSERT INTO re_general_ledger 
                (company_id, account_id, journal_id, journal_line_id, entry_date, 
                 debit_amount, credit_amount, balance, description, reference)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $journal['company_id'], $line['account_id'], $journalId, $line['id'],
                $journal['journal_date'], $line['debit_amount'], $line['credit_amount'],
                $newBalance, $line['description'], $line['reference']
            ]);
        }
        
        // Update journal header as posted
        $stmt = $conn->prepare("
            UPDATE re_journal_headers 
            SET is_posted = 1, posted_at = NOW(), posted_by = ?
            WHERE id = ?
        ");
        $stmt->execute([$postedBy, $journalId]);
        
        accounting_audit_log($journal['company_id'], 'post_journal', 'journal_header', $journalId, $postedBy, $journal['journal_number']);
        // Commit transaction
        if ($ownsTransaction) {
            $conn->commit();
        }
        
        return ['success' => true, 'error' => null];
        
    } catch (Exception $e) {
        if (isset($ownsTransaction) && $ownsTransaction && $conn->inTransaction()) {
            $conn->rollBack();
        }
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Submit journal for approval (optional workflow). Only draft journals can be submitted.
 *
 * @param int $journalId Journal ID
 * @param int|null $userId User submitting
 * @return array ['success' => bool, 'error' => string|null]
 */
function submit_journal_for_approval($journalId, $userId = null) {
    global $conn;
    try {
        $stmt = $conn->prepare("
            SELECT id, company_id, is_posted FROM re_journal_headers
            WHERE id = ? AND is_posted = 0
        ");
        $stmt->execute([$journalId]);
        $journal = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$journal) {
            return ['success' => false, 'error' => 'Journal not found or already posted'];
        }
        // Check if approval_status column exists (migration may not be run)
        try {
            $stmt = $conn->prepare("SELECT approval_status FROM re_journal_headers WHERE id = ?");
            $stmt->execute([$journalId]);
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Approval workflow is not enabled (run migration accounting_approval_workflow.sql).'];
        }
        $stmt = $conn->prepare("
            UPDATE re_journal_headers
            SET approval_status = 'submitted', submitted_at = NOW(), submitted_by = ?
            WHERE id = ? AND is_posted = 0 AND approval_status = 'draft'
        ");
        $stmt->execute([$userId, $journalId]);
        if ($stmt->rowCount() === 0) {
            return ['success' => false, 'error' => 'Journal is not in draft status or already submitted'];
        }
        accounting_audit_log($journal['company_id'], 'submit_for_approval', 'journal_header', $journalId, $userId, null);
        return ['success' => true, 'error' => null];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Approve a submitted journal (optional workflow). Only submitted journals can be approved.
 *
 * @param int $journalId Journal ID
 * @param int|null $userId User approving
 * @return array ['success' => bool, 'error' => string|null]
 */
function approve_journal($journalId, $userId = null) {
    global $conn;
    try {
        $stmt = $conn->prepare("
            SELECT id, company_id, is_posted FROM re_journal_headers
            WHERE id = ? AND is_posted = 0
        ");
        $stmt->execute([$journalId]);
        $journal = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$journal) {
            return ['success' => false, 'error' => 'Journal not found or already posted'];
        }
        $stmt = $conn->prepare("
            UPDATE re_journal_headers
            SET approval_status = 'approved', approved_at = NOW(), approved_by = ?
            WHERE id = ? AND is_posted = 0 AND approval_status = 'submitted'
        ");
        $stmt->execute([$userId, $journalId]);
        if ($stmt->rowCount() === 0) {
            return ['success' => false, 'error' => 'Journal is not in submitted status'];
        }
        accounting_audit_log($journal['company_id'], 'approve_journal', 'journal_header', $journalId, $userId, null);
        return ['success' => true, 'error' => null];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Create and post a journal entry in one step
 * 
 * @param int $companyId Company ID
 * @param string $journalType Type of journal
 * @param string $referenceType Source document type
 * @param int|null $referenceId ID of source document
 * @param array $lines Array of journal lines
 * @param string $description Journal description
 * @param string|null $journalDate Journal date
 * @param int|null $createdBy User ID
 * @return array ['success' => bool, 'journal_id' => int|null, 'error' => string|null]
 */
function create_and_post_journal($companyId, $journalType, $referenceType, $referenceId, $lines, $description = '', $journalDate = null, $createdBy = null) {
    // Create journal
    $result = create_journal_entry($companyId, $journalType, $referenceType, $referenceId, $lines, $description, $journalDate, $createdBy);
    
    if (!$result['success']) {
        return $result;
    }
    
    // Post journal
    $postResult = post_journal($result['journal_id'], $createdBy);
    
    if (!$postResult['success']) {
        return ['success' => false, 'journal_id' => $result['journal_id'], 'error' => 'Journal created but posting failed: ' . $postResult['error']];
    }
    
    return $result;
}

/**
 * Reverse a journal entry
 *
 * @param int $journalId Journal ID to reverse
 * @param string $reason Reason for reversal
 * @param int|null $reversedBy User ID who reversed the journal
 * @param string|null $reversalDate Date for the reversal entry (YYYY-MM-DD).
 *        Defaults to the original journal's date so reversals stay in the same period.
 *        Pass date('Y-m-d') explicitly if you want today's date.
 * @return array ['success' => bool, 'reversal_journal_id' => int|null, 'error' => string|null]
 */
function reverse_journal($journalId, $reason = '', $reversedBy = null, $reversalDate = null) {
    global $conn;
    
    try {
        // Get original journal
        $stmt = $conn->prepare("
            SELECT * FROM re_journal_headers 
            WHERE id = ? AND is_posted = 1 AND is_reversed = 0
        ");
        $stmt->execute([$journalId]);
        $originalJournal = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$originalJournal) {
            return ['success' => false, 'reversal_journal_id' => null, 'error' => 'Journal not found, not posted, or already reversed'];
        }
        
        // Get original journal lines
        $stmt = $conn->prepare("
            SELECT * FROM re_journal_lines 
            WHERE journal_id = ? 
            ORDER BY line_number
        ");
        $stmt->execute([$journalId]);
        $originalLines = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Create reversal lines (swap debits and credits)
        $reversalLines = [];
        foreach ($originalLines as $line) {
            $reversalLines[] = [
                'account_id' => $line['account_id'],
                'debit' => $line['credit_amount'],  // Swap
                'credit' => $line['debit_amount'],  // Swap
                'description' => 'Reversal: ' . ($line['description'] ?? ''),
                'reference' => $line['reference']
            ];
        }
        
        // Use provided date, or fall back to the original journal's date (keeps reversal in same period)
        $effectiveReversalDate = $reversalDate ?: $originalJournal['journal_date'];

        // Create reversal journal
        $reversalDescription = "Reversal of {$originalJournal['journal_number']}" . ($reason ? " - $reason" : '');
        $result = create_and_post_journal(
            $originalJournal['company_id'],
            'reversal',
            $originalJournal['reference_type'],
            $originalJournal['reference_id'],
            $reversalLines,
            $reversalDescription,
            $effectiveReversalDate,
            $reversedBy
        );
        
        if (!$result['success']) {
            return $result;
        }
        
        // Mark original journal as reversed
        $stmt = $conn->prepare("
            UPDATE re_journal_headers 
            SET is_reversed = 1, reversal_journal_id = ?
            WHERE id = ?
        ");
        $stmt->execute([$result['journal_id'], $journalId]);
        
        accounting_audit_log($originalJournal['company_id'], 'reverse_journal', 'journal_header', $journalId, $reversedBy, 'Reversal: ' . $result['journal_id']);
        return [
            'success' => true,
            'reversal_journal_id' => $result['journal_id'],
            'error' => null
        ];
        
    } catch (Exception $e) {
        return ['success' => false, 'reversal_journal_id' => null, 'error' => $e->getMessage()];
    }
}

/**
 * Generate journal number
 * 
 * @param int $companyId Company ID
 * @param string $journalDate Journal date (YYYY-MM-DD)
 * @return string|false Journal number or false on error
 */
function generate_journal_number($companyId, $journalDate) {
    global $conn;
    
    try {
        $year = date('Y', strtotime($journalDate));
        
        // Get or create sequence for this year
        $stmt = $conn->prepare("
            SELECT sequence_number FROM re_journal_sequences 
            WHERE company_id = ? AND year = ?
        ");
        $stmt->execute([$companyId, $year]);
        $sequence = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($sequence) {
            // Increment sequence
            $newSequence = $sequence['sequence_number'] + 1;
            $stmt = $conn->prepare("
                UPDATE re_journal_sequences 
                SET sequence_number = ? 
                WHERE company_id = ? AND year = ?
            ");
            $stmt->execute([$newSequence, $companyId, $year]);
        } else {
            // Create new sequence
            $newSequence = 1;
            $stmt = $conn->prepare("
                INSERT INTO re_journal_sequences (company_id, year, sequence_number, prefix)
                VALUES (?, ?, ?, 'JRN')
            ");
            $stmt->execute([$companyId, $year, $newSequence]);
        }
        
        // Format: JRN-YYYY-0001
        return 'JRN-' . $year . '-' . str_pad($newSequence, 4, '0', STR_PAD_LEFT);
        
    } catch (Exception $e) {
        error_log("Error generating journal number: " . $e->getMessage());
        return false;
    }
}

/**
 * Get account details
 * 
 * @param int $accountId Account ID
 * @param int $companyId Company ID
 * @return array|false Account data or false if not found
 */
function get_account($accountId, $companyId) {
    global $conn;
    
    $stmt = $conn->prepare("
        SELECT * FROM re_chart_of_accounts 
        WHERE id = ? AND company_id = ? AND is_active = 1
    ");
    $stmt->execute([$accountId, $companyId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Get account balance as of a specific date
 * 
 * @param int $accountId Account ID
 * @param int $companyId Company ID
 * @param string|null $asOfDate Date (YYYY-MM-DD), defaults to today
 * @return float Account balance
 */
function get_account_balance($accountId, $companyId, $asOfDate = null) {
    global $conn;
    
    if (empty($asOfDate)) {
        $asOfDate = date('Y-m-d');
    }
    
    $stmt = $conn->prepare("
        SELECT balance FROM re_general_ledger 
        WHERE account_id = ? AND company_id = ? AND entry_date <= ?
        ORDER BY entry_date DESC, id DESC 
        LIMIT 1
    ");
    $stmt->execute([$accountId, $companyId, $asOfDate]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return $result ? (float)$result['balance'] : 0;
}

/**
 * Find account by code
 * 
 * @param string $accountCode Account code
 * @param int $companyId Company ID
 * @return array|false Account data or false if not found
 */
function find_account_by_code($accountCode, $companyId) {
    global $conn;
    
    $stmt = $conn->prepare("
        SELECT * FROM re_chart_of_accounts 
        WHERE account_code = ? AND company_id = ? AND is_active = 1
    ");
    $stmt->execute([$accountCode, $companyId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Calculate VAT amount
 * 
 * @param float $amount Base amount
 * @param float $vatRate VAT rate (e.g., 5.00 for 5%)
 * @return array ['base' => float, 'vat' => float, 'total' => float]
 */
function calculate_vat($amount, $vatRate = 5.00) {
    $base = (float)$amount;
    $vat = round($base * ($vatRate / 100), 2);
    $total = $base + $vat;
    
    return [
        'base' => $base,
        'vat' => $vat,
        'total' => $total
    ];
}

/**
 * Close a fiscal year: create closing entries (transfer P&L to Retained Earnings) and mark year closed.
 * Creates one closing journal: closes all Income and Expense to Current Year Earnings, then transfers
 * Current Year Earnings to Retained Earnings.
 *
 * @param int $fiscalYearId re_fiscal_years.id
 * @param int|null $closedBy User ID
 * @return array ['success' => bool, 'journal_id' => int|null, 'error' => string|null]
 */
function close_fiscal_year($fiscalYearId, $closedBy = null) {
    global $conn;

    try {
        $stmt = $conn->prepare("
            SELECT * FROM re_fiscal_years
            WHERE id = ? AND is_closed = 0
        ");
        $stmt->execute([$fiscalYearId]);
        $fy = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$fy) {
            return ['success' => false, 'journal_id' => null, 'error' => 'Fiscal year not found or already closed.'];
        }

        $companyId = (int) $fy['company_id'];
        $endDate = $fy['end_date'];

        $currentYearEarnings = find_account_by_code('3300', $companyId);
        $retainedEarnings = find_account_by_code('3200', $companyId);
        if (!$currentYearEarnings || !$retainedEarnings) {
            return ['success' => false, 'journal_id' => null, 'error' => 'Chart of accounts must include 3200 Retained Earnings and 3300 Current Year Earnings.'];
        }

        // All Income and Expense leaf accounts (is_header = 0)
        $stmt = $conn->prepare("
            SELECT id, account_code, account_name, account_type, normal_balance
            FROM re_chart_of_accounts
            WHERE company_id = ? AND is_active = 1 AND is_header = 0
            AND account_type IN ('Income', 'Expense')
            ORDER BY account_type, account_code
        ");
        $stmt->execute([$companyId]);
        $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $lines = [];
        $incomeTotal = 0.0;
        $expenseTotal = 0.0;

        foreach ($accounts as $acc) {
            $balance = get_account_balance($acc['id'], $companyId, $endDate);
            if (abs($balance) < 0.01) {
                continue;
            }
            if ($acc['account_type'] === 'Income') {
                // Income has credit normal balance; close by debiting the account
                $lines[] = [
                    'account_id' => $acc['id'],
                    'debit' => $balance,
                    'credit' => 0,
                    'description' => 'Year-end closing - ' . $acc['account_name'],
                    'reference' => 'FY Close'
                ];
                $incomeTotal += $balance;
            } else {
                // Expense has debit normal balance; close by crediting the account
                $lines[] = [
                    'account_id' => $acc['id'],
                    'debit' => 0,
                    'credit' => $balance,
                    'description' => 'Year-end closing - ' . $acc['account_name'],
                    'reference' => 'FY Close'
                ];
                $expenseTotal += $balance;
            }
        }

        // Current Year Earnings: Credit for total income closed, Debit for total expenses closed
        if ($incomeTotal > 0) {
            $lines[] = [
                'account_id' => $currentYearEarnings['id'],
                'debit' => 0,
                'credit' => $incomeTotal,
                'description' => 'Year-end closing - Income to CYE',
                'reference' => 'FY Close'
            ];
        }
        if ($expenseTotal > 0) {
            $lines[] = [
                'account_id' => $currentYearEarnings['id'],
                'debit' => $expenseTotal,
                'credit' => 0,
                'description' => 'Year-end closing - Expense to CYE',
                'reference' => 'FY Close'
            ];
        }

        // Net profit (income - expense); transfer CYE to Retained Earnings
        $netProfit = $incomeTotal - $expenseTotal;
        if (abs($netProfit) >= 0.01) {
            if ($netProfit > 0) {
                $lines[] = [
                    'account_id' => $currentYearEarnings['id'],
                    'debit' => $netProfit,
                    'credit' => 0,
                    'description' => 'Transfer to Retained Earnings',
                    'reference' => 'FY Close'
                ];
                $lines[] = [
                    'account_id' => $retainedEarnings['id'],
                    'debit' => 0,
                    'credit' => $netProfit,
                    'description' => 'Net profit - ' . $fy['year_name'],
                    'reference' => 'FY Close'
                ];
            } else {
                $lines[] = [
                    'account_id' => $retainedEarnings['id'],
                    'debit' => -$netProfit,
                    'credit' => 0,
                    'description' => 'Net loss - ' . $fy['year_name'],
                    'reference' => 'FY Close'
                ];
                $lines[] = [
                    'account_id' => $currentYearEarnings['id'],
                    'debit' => 0,
                    'credit' => -$netProfit,
                    'description' => 'Transfer to Retained Earnings',
                    'reference' => 'FY Close'
                ];
            }
        }

        if (empty($lines)) {
            return ['success' => false, 'journal_id' => null, 'error' => 'No income or expense balances to close for this period.'];
        }

        $description = 'Year-end closing - ' . $fy['year_name'] . ' (' . $endDate . ')';
        $result = create_journal_entry(
            $companyId,
            'closing',
            'fiscal_year',
            (int) $fiscalYearId,
            $lines,
            $description,
            $endDate,
            $closedBy
        );

        if (!$result['success']) {
            return $result;
        }

        $postResult = post_journal($result['journal_id'], $closedBy);
        if (!$postResult['success']) {
            return ['success' => false, 'journal_id' => $result['journal_id'], 'error' => 'Closing entry created but posting failed: ' . $postResult['error']];
        }

        $stmt = $conn->prepare("
            UPDATE re_fiscal_years
            SET is_closed = 1, closed_at = NOW(), closed_by = ?
            WHERE id = ? AND company_id = ?
        ");
        $stmt->execute([$closedBy, $fiscalYearId, $companyId]);

        accounting_audit_log($companyId, 'close_period', 'fiscal_year', $fiscalYearId, $closedBy, $fy['year_name']);
        return ['success' => true, 'journal_id' => $result['journal_id'], 'error' => null];
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        return ['success' => false, 'journal_id' => null, 'error' => $e->getMessage()];
    }
}
