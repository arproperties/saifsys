<?php
// accounts/fix_duplicate_invoice_journals.php
// Script to fix existing duplicate invoice journals in GL
// Run this once to clean up existing duplicates

require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db_connect.php';
require_once __DIR__.'/../includes/gl_posting.php';
require_role(['Owner','Admin','Account'], $conn);

header('Content-Type: text/html; charset=utf-8');

echo "<h2>Fixing Duplicate Invoice Journals</h2>";
echo "<pre>";

// Check if user wants to diagnose a specific invoice
$diagnose_invoice = $_GET['invoice'] ?? '';
$diagnose_invoice_id = null;

if ($diagnose_invoice) {
    // Find invoice by number
    $invStmt = $conn->prepare("SELECT id, invoice_no FROM invoices WHERE invoice_no = ? LIMIT 1");
    $invStmt->execute([$diagnose_invoice]);
    $inv = $invStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($inv) {
        $diagnose_invoice_id = (int)$inv['id'];
        echo "=== DIAGNOSTIC MODE ===\n";
        echo "Analyzing invoice: {$inv['invoice_no']} (ID: {$diagnose_invoice_id})\n\n";
        
        // Get ALL journals for this invoice (including reversed)
        $allJournals = $conn->prepare("
            SELECT 
                j.id,
                j.journal_no,
                j.journal_date,
                j.is_reversed,
                j.memo,
                (SELECT COUNT(*) FROM gl_journals rev WHERE rev.source='reversal' AND rev.source_id=j.id AND rev.is_posted=1) as reversal_count
            FROM gl_journals j
            WHERE j.source = 'invoice' AND j.source_id = ?
            ORDER BY j.id DESC
        ");
        $allJournals->execute([$diagnose_invoice_id]);
        $journals = $allJournals->fetchAll(PDO::FETCH_ASSOC);
        
        echo "Found " . count($journals) . " journal(s) for this invoice:\n";
        foreach ($journals as $j) {
            $status = (int)$j['is_reversed'] === 1 ? 'REVERSED' : 'ACTIVE';
            $revCount = (int)$j['reversal_count'];
            echo "  Journal #{$j['id']} ({$j['journal_no']}) - {$status}";
            if ($revCount > 0) {
                echo " - Has {$revCount} reversal(s)";
            }
            echo "\n";
            echo "    Date: {$j['journal_date']}\n";
            echo "    Memo: {$j['memo']}\n";
        }
        
        // Count non-reversed journals
        $nonReversed = array_filter($journals, function($j) { return (int)$j['is_reversed'] === 0; });
        echo "\nNon-reversed journals: " . count($nonReversed) . "\n";
        
        if (count($nonReversed) > 1) {
            echo "\n⚠️  DUPLICATE DETECTED: This invoice has " . count($nonReversed) . " non-reversed journals!\n";
            echo "These will be fixed below.\n\n";
        } else {
            echo "\n✓ No duplicates found (only " . count($nonReversed) . " non-reversed journal).\n";
            echo "If you're seeing duplicates in the GL report, they may be from reversed journals that weren't properly filtered.\n\n";
        }
        
        echo "=== END DIAGNOSTIC ===\n\n";
    } else {
        echo "Invoice '{$diagnose_invoice}' not found.\n\n";
    }
}

try {
    $conn->beginTransaction();
    
    // Find all invoices with multiple non-reversed journals (including orphaned - deleted invoices)
    $duplicates = $conn->query("
        SELECT 
            j.source_id as invoice_id,
            COUNT(*) as journal_count,
            GROUP_CONCAT(j.id ORDER BY j.id DESC SEPARATOR ',') as journal_ids,
            MAX(i.invoice_no) as invoice_no
        FROM gl_journals j
        LEFT JOIN invoices i ON i.id = j.source_id
        WHERE j.source = 'invoice'
          AND j.is_posted = 1
          AND j.is_reversed = 0
        GROUP BY j.source_id
        HAVING journal_count > 1
        ORDER BY j.source_id
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    // Also find orphaned journals (invoices that don't exist anymore)
    $orphaned = $conn->query("
        SELECT 
            j.source_id as invoice_id,
            COUNT(*) as journal_count,
            GROUP_CONCAT(j.id ORDER BY j.id DESC SEPARATOR ',') as journal_ids,
            'ORPHANED (Invoice deleted)' as invoice_no
        FROM gl_journals j
        LEFT JOIN invoices i ON i.id = j.source_id
        WHERE j.source = 'invoice'
          AND j.is_posted = 1
          AND j.is_reversed = 0
          AND i.id IS NULL
        GROUP BY j.source_id
        HAVING journal_count > 0
        ORDER BY j.source_id
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    // Merge duplicates and orphaned
    $allDuplicates = array_merge($duplicates, $orphaned);
    
    if (empty($allDuplicates)) {
        echo "No duplicate invoice journals found (non-reversed journals).\n\n";
        
        // Also check for invoices with multiple journals total (including reversed)
        $allDuplicatesTotal = $conn->query("
            SELECT 
                j.source_id as invoice_id,
                COUNT(*) as journal_count,
                SUM(CASE WHEN j.is_reversed=0 THEN 1 ELSE 0 END) as non_reversed_count,
                GROUP_CONCAT(j.id ORDER BY j.id DESC SEPARATOR ',') as journal_ids,
                MAX(i.invoice_no) as invoice_no
            FROM gl_journals j
            LEFT JOIN invoices i ON i.id = j.source_id
            WHERE j.source = 'invoice'
              AND j.is_posted = 1
            GROUP BY j.source_id
            HAVING journal_count > 1
            ORDER BY j.source_id
            LIMIT 20
        ")->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($allDuplicatesTotal)) {
            echo "However, found " . count($allDuplicatesTotal) . " invoices with multiple journals (including reversed):\n";
            echo "These may need manual review if they're causing issues in the GL report.\n\n";
            
            foreach ($allDuplicatesTotal as $dup) {
                $invName = $dup['invoice_no'] ?: "ORPHANED (ID: {$dup['invoice_id']})";
                echo "  - {$invName} (ID: {$dup['invoice_id']}): {$dup['journal_count']} journals total, {$dup['non_reversed_count']} non-reversed\n";
            }
            echo "\n";
        }
        
        if (!$diagnose_invoice_id) {
            echo "To diagnose a specific invoice, add ?invoice=INV-2025-000044 to the URL\n";
            echo "Example: fix_duplicate_invoice_journals.php?invoice=INV-2025-000044\n\n";
        }
        
        $conn->commit();
        exit;
    }
    
    echo "Found " . count($allDuplicates) . " invoices with duplicate journals:\n\n";
    
    $fixed = 0;
    $reversed = 0;
    
    foreach ($allDuplicates as $dup) {
        $invoiceId = (int)$dup['invoice_id'];
        $journalIds = explode(',', $dup['journal_ids']);
        $journalIds = array_map('intval', $journalIds);
        
        // Get invoice info (may be null if orphaned)
        $inv = $conn->prepare("SELECT invoice_no, status FROM invoices WHERE id = ?");
        $inv->execute([$invoiceId]);
        $invoice = $inv->fetch(PDO::FETCH_ASSOC);
        
        $invoiceNo = $dup['invoice_no'] ?: ($invoice['invoice_no'] ?? "ORPHANED (Invoice #{$invoiceId} deleted)");
        $isOrphaned = empty($invoice);
        
        echo "Invoice {$invoiceNo} (ID: {$invoiceId})" . ($isOrphaned ? " - ORPHANED" : "") . ":\n";
        echo "  Found " . count($journalIds) . " duplicate journals: " . implode(', ', $journalIds) . "\n";
        
        // Keep the most recent journal (highest ID), reverse all others
        $keepJournalId = max($journalIds);
        $reverseJournalIds = array_filter($journalIds, function($id) use ($keepJournalId) {
            return $id !== $keepJournalId;
        });
        
        echo "  Keeping journal #{$keepJournalId}\n";
        echo "  Reversing journals: " . implode(', ', $reverseJournalIds) . "\n";
        
        foreach ($reverseJournalIds as $jid) {
            try {
                // Check if already reversed
                $check = $conn->prepare("SELECT is_reversed FROM gl_journals WHERE id = ?");
                $check->execute([$jid]);
                $journal = $check->fetch(PDO::FETCH_ASSOC);
                
                if ($journal && (int)$journal['is_reversed'] === 0) {
                    // Check if reversal already exists
                    $revCheck = $conn->prepare("SELECT COUNT(*) FROM gl_journals WHERE source='reversal' AND source_id=? AND is_posted=1");
                    $revCheck->execute([$jid]);
                    
                    if ($revCheck->fetchColumn() == 0) {
                        gl_reverse_journal($conn, $jid);
                        $reversed++;
                        echo "    ✓ Reversed journal #{$jid}\n";
                    } else {
                        // Just mark as reversed
                        $conn->prepare("UPDATE gl_journals SET is_reversed=1 WHERE id=?")->execute([$jid]);
                        echo "    ✓ Marked journal #{$jid} as reversed (reversal already exists)\n";
                    }
                } else {
                    echo "    - Journal #{$jid} already reversed, skipping\n";
                }
            } catch (Exception $e) {
                echo "    ✗ Error reversing journal #{$jid}: " . $e->getMessage() . "\n";
            }
        }
        
        // Update invoice to point to the kept journal (if invoice still exists)
        if (!$isOrphaned) {
            $conn->prepare("UPDATE invoices SET gl_journal_id=? WHERE id=?")->execute([$keepJournalId, $invoiceId]);
            echo "  ✓ Updated invoice to point to journal #{$keepJournalId}\n";
        } else {
            echo "  ✓ Orphaned invoice - no invoice record to update\n";
        }
        echo "\n";
        
        $fixed++;
    }
    
    // Now find and delete orphaned reversals (reversals without original invoice journals)
    echo "\n=== Finding Orphaned Reversals ===\n";
    echo "Looking for reversal journals that don't have a corresponding invoice journal...\n\n";
    
    // Find orphaned reversals: reversals that don't have a valid invoice journal to reverse
    // This includes:
    // 1. Reversals where the original journal doesn't exist
    // 2. Reversals where the original journal is not an invoice (wrong type)
    // 3. Reversals where the original invoice journal exists but is not reversed (data inconsistency)
    // 4. Reversals where the original invoice journal exists but the invoice itself was deleted
    $orphanedReversals = $conn->query("
        SELECT 
            rev.id as reversal_id,
            rev.journal_no as reversal_no,
            rev.journal_date,
            rev.source_id as original_journal_id,
            rev.memo,
            orig.id as orig_exists,
            orig.source as orig_source,
            orig.source_id as orig_invoice_id,
            orig.is_reversed as orig_is_reversed,
            inv.id as invoice_exists
        FROM gl_journals rev
        LEFT JOIN gl_journals orig ON orig.id = rev.source_id
        LEFT JOIN invoices inv ON inv.id = orig.source_id AND orig.source = 'invoice'
        WHERE rev.source = 'reversal'
          AND rev.is_posted = 1
          AND (
            -- Case 1: Original journal doesn't exist (completely orphaned)
            orig.id IS NULL 
            -- Case 2: Original journal exists but is not an invoice (wrong type - e.g., receipt, expense)
            OR orig.source != 'invoice'
            -- Case 3: Original journal is an invoice but is not reversed (reversal shouldn't exist)
            OR (orig.source = 'invoice' AND (orig.is_reversed = 0 OR orig.is_reversed IS NULL))
            -- Case 4: Original journal is an invoice but the invoice itself was deleted
            OR (orig.source = 'invoice' AND orig.source_id IS NOT NULL AND inv.id IS NULL)
          )
        ORDER BY rev.journal_date DESC, rev.id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    $deletedReversals = 0;
    $deletedReversalLines = 0;
    
    if (!empty($orphanedReversals)) {
        echo "Found " . count($orphanedReversals) . " orphaned reversal(s):\n\n";
        
        foreach ($orphanedReversals as $rev) {
            $reversalId = (int)$rev['reversal_id'];
            $originalId = (int)$rev['original_journal_id'];
            
            $reason = '';
            if (empty($rev['orig_exists'])) {
                $reason = "Original journal #{$originalId} doesn't exist (completely orphaned)";
            } elseif ($rev['orig_source'] === 'invoice' && empty($rev['invoice_exists'])) {
                $reason = "Original invoice journal #{$originalId} exists but invoice #{$rev['orig_invoice_id']} was deleted - no opposite invoice record";
            } elseif ($rev['orig_source'] !== 'invoice') {
                $reason = "Original journal #{$originalId} is not an invoice (source: {$rev['orig_source']}) - no opposite invoice record";
            } elseif ($rev['orig_source'] === 'invoice' && (int)($rev['orig_is_reversed'] ?? 0) === 0) {
                $reason = "Original invoice journal #{$originalId} exists but is not reversed (reversal shouldn't exist)";
            } else {
                // This shouldn't happen with the updated query, but just in case
                continue; // Skip this one - it's valid (original invoice journal exists, invoice exists, and is reversed)
            }
            
            echo "Reversal {$rev['reversal_no']} (ID: {$reversalId}):\n";
            echo "  Date: {$rev['journal_date']}\n";
            echo "  Reason: {$reason}\n";
            echo "  Memo: {$rev['memo']}\n";
            
            try {
                // Get reversal lines to reverse the balances
                $revLines = $conn->prepare("
                    SELECT l.account_id, l.debit, l.credit, SUBSTRING(rev.journal_date, 1, 7) as period
                    FROM gl_journal_lines l
                    JOIN gl_journals rev ON rev.id = l.journal_id
                    WHERE l.journal_id = ?
                ");
                $revLines->execute([$reversalId]);
                $lines = $revLines->fetchAll(PDO::FETCH_ASSOC);
                
                // Reverse the balances (subtract what was added by the reversal)
                $balUpdate = $conn->prepare("
                    UPDATE gl_account_balances 
                    SET debit = debit - ?, credit = credit - ?
                    WHERE account_id = ? AND period = ?
                ");
                
                foreach ($lines as $line) {
                    // Reverse: subtract the reversal's debit/credit from balances
                    $balUpdate->execute([
                        (float)$line['debit'],
                        (float)$line['credit'],
                        (int)$line['account_id'],
                        $line['period']
                    ]);
                }
                
                // Delete reversal journal lines
                $deleteLinesStmt = $conn->prepare("DELETE FROM gl_journal_lines WHERE journal_id = ?");
                $deleteLinesStmt->execute([$reversalId]);
                $deletedReversalLines += $deleteLinesStmt->rowCount();
                
                // Delete reversal journal
                $deleteJournalStmt = $conn->prepare("DELETE FROM gl_journals WHERE id = ?");
                $deleteJournalStmt->execute([$reversalId]);
                
                echo "  ✓ Deleted orphaned reversal journal and reversed balances\n\n";
                $deletedReversals++;
                
            } catch (Exception $e) {
                echo "  ✗ Error deleting reversal: " . $e->getMessage() . "\n\n";
            }
        }
    } else {
        echo "No orphaned reversals found. All reversals have valid original journals.\n\n";
    }
    
    $conn->commit();
    
    echo "\n=== Summary ===\n";
    echo "Fixed {$fixed} invoices with duplicate journals\n";
    echo "Reversed {$reversed} duplicate journal entries\n";
    echo "Deleted {$deletedReversals} orphaned reversal journals\n";
    echo "\nDone! The GL report should now show correct balances.\n";
    
} catch (Exception $e) {
    $conn->rollBack();
    echo "\nERROR: " . $e->getMessage() . "\n";
    echo "Rolled back all changes.\n";
}

echo "</pre>";
echo "<p><a href='report_general_ledger.php'>Back to General Ledger</a></p>";
echo "<p><a href='?invoice=INV-2025-000044'>Diagnose Invoice INV-2025-000044</a></p>";

