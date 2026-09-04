<?php
// accounts/validate_trade_receivable.php
// Validates and cleans Trade Receivable (1110) account entries
// Should only have: Debit (invoices), Credit (receipts or reversals)

require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db_connect.php';
require_once __DIR__.'/../includes/gl_posting.php';
require_role(['Owner','Admin','Account'], $conn);

header('Content-Type: text/html; charset=utf-8');

$action = $_GET['action'] ?? 'validate';
$fix = isset($_GET['fix']) && $_GET['fix'] === '1';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function money($n){ return number_format((float)$n, 2); }

// Get Trade Receivable account ID
$arAccountId = coa_id($conn, '1110');
$arAccount = $conn->prepare("SELECT account_no, name FROM chart_of_accounts WHERE id = ?");
$arAccount->execute([$arAccountId]);
$arInfo = $arAccount->fetch(PDO::FETCH_ASSOC);

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Validate Trade Receivable</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
  body{background:#f6f7f9}
  .card{border:0;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.1)}
  .invalid-entry{background:#fff3cd}
  .valid-entry{background:#d1e7dd}
  .table th{background:#f8f9fa;font-weight:600}
</style>
</head>
<body>
<div class="container my-4">
  <div class="card">
    <div class="card-header bg-primary text-white">
      <h4 class="mb-0"><i class="bi bi-shield-check"></i> Trade Receivable Account Validation</h4>
      <small>Account: <?= h($arInfo['account_no'] ?? '1110') ?> - <?= h($arInfo['name'] ?? 'Trade Receivables') ?></small>
    </div>
    <div class="card-body">
      <?php
      try {
        // Get ALL entries for Trade Receivable account
        $allEntriesStmt = $conn->prepare("
          SELECT 
            j.id as journal_id,
            j.journal_no,
            j.journal_date,
            j.source,
            j.source_id,
            j.is_reversed,
            j.memo,
            l.description,
            l.debit,
            l.credit,
            -- Get invoice info if source is invoice
            CASE WHEN j.source = 'invoice' THEN 
              (SELECT invoice_no FROM invoices WHERE id = j.source_id)
            ELSE NULL END as invoice_no,
            -- Get receipt info if source is receipt
            CASE WHEN j.source = 'receipt' THEN 
              (SELECT receipt_no FROM receipts WHERE id = j.source_id)
            ELSE NULL END as receipt_no,
            -- Get original invoice for reversals
            CASE WHEN j.source = 'reversal' THEN
              (SELECT i.invoice_no FROM gl_journals orig 
               JOIN invoices i ON i.id = orig.source_id 
               WHERE orig.id = j.source_id AND orig.source = 'invoice')
            ELSE NULL END as reversal_invoice_no
          FROM gl_journal_lines l
          JOIN gl_journals j ON j.id = l.journal_id
          WHERE l.account_id = ?
            AND j.is_posted = 1
          ORDER BY j.journal_date DESC, j.id DESC
        ");
        $allEntriesStmt->execute([$arAccountId]);
        $allEntries = $allEntriesStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // First, identify duplicate invoice DEBIT journals (multiple debit postings for same invoice)
        // NOTE: Multiple CREDIT entries (receipts) for the same invoice are VALID (partial payments scenario)
        // We only check for duplicate DEBIT entries (invoice postings)
        $invoiceDebitJournalCounts = [];
        foreach ($allEntries as $entry) {
          // Only check DEBIT entries from invoices (not credit entries)
          if ($entry['source'] === 'invoice' && !empty($entry['invoice_no']) && (float)$entry['debit'] > 0) {
            $invoiceNo = $entry['invoice_no'];
            if (!isset($invoiceDebitJournalCounts[$invoiceNo])) {
              $invoiceDebitJournalCounts[$invoiceNo] = [];
            }
            $invoiceDebitJournalCounts[$invoiceNo][] = $entry;
          }
        }
        
        // Categorize entries
        $validEntries = [];
        $invalidEntries = [];
        
        foreach ($allEntries as $entry) {
          $isValid = false;
          $reason = '';
          
          // Rule 1: Debit entries should be from invoices
          // IMPORTANT: An invoice should only have ONE debit entry (when issued)
          // Multiple credit entries (receipts) for the same invoice are VALID (partial payments)
          if ((float)$entry['debit'] > 0) {
            if ($entry['source'] === 'invoice' && !empty($entry['invoice_no'])) {
              // Check for duplicate invoice DEBIT journals (not credit entries)
              $invoiceNo = $entry['invoice_no'];
              if (isset($invoiceDebitJournalCounts[$invoiceNo]) && count($invoiceDebitJournalCounts[$invoiceNo]) > 1) {
                // Multiple DEBIT journals for same invoice - keep only the most recent (highest journal_id)
                // This is INVALID because an invoice should only be posted once (one debit entry)
                $journalIds = array_column($invoiceDebitJournalCounts[$invoiceNo], 'journal_id');
                $maxJournalId = max($journalIds);
                
                if ((int)$entry['journal_id'] !== $maxJournalId) {
                  $reason = "INVALID: Duplicate invoice debit journal - invoice {$invoiceNo} has " . count($invoiceDebitJournalCounts[$invoiceNo]) . " debit postings (keep journal #{$maxJournalId}, delete this one)";
                } else {
                  // This is the most recent debit journal, but still check if invoice exists
                  $invCheck = $conn->prepare("SELECT id FROM invoices WHERE invoice_no = ?");
                  $invCheck->execute([$invoiceNo]);
                  if ($invCheck->fetch()) {
                    $isValid = true;
                    $reason = "Valid: Invoice debit entry (most recent debit journal for this invoice)";
                  } else {
                    $reason = "INVALID: Invoice {$invoiceNo} was deleted";
                  }
                }
              } else {
                // Single debit journal for this invoice - check if invoice exists
                $invCheck = $conn->prepare("SELECT id FROM invoices WHERE invoice_no = ?");
                $invCheck->execute([$invoiceNo]);
                if ($invCheck->fetch()) {
                  $isValid = true;
                  $reason = "Valid: Invoice debit entry";
                } else {
                  $reason = "INVALID: Invoice {$invoiceNo} was deleted";
                }
              }
            } else {
              $reason = "INVALID: Debit entry from non-invoice source ({$entry['source']})";
            }
          }
          
          // Rule 2: Credit entries should be from receipts OR reversals
          if ((float)$entry['credit'] > 0) {
            if ($entry['source'] === 'receipt' && !empty($entry['receipt_no'])) {
              // Check if receipt exists
              $recCheck = $conn->prepare("SELECT id FROM receipts WHERE receipt_no = ?");
              $recCheck->execute([$entry['receipt_no']]);
              if ($recCheck->fetch()) {
                $isValid = true;
                $reason = "Valid: Receipt credit entry";
              } else {
                $reason = "INVALID: Receipt {$entry['receipt_no']} was deleted";
              }
            } elseif ($entry['source'] === 'reversal') {
              // Check if reversal has valid original invoice journal
              if (!empty($entry['reversal_invoice_no'])) {
                // Check if original invoice exists and is NOT void
                $origInvCheck = $conn->prepare("SELECT id, status FROM invoices WHERE invoice_no = ?");
                $origInvCheck->execute([$entry['reversal_invoice_no']]);
                $origInv = $origInvCheck->fetch(PDO::FETCH_ASSOC);
                if (!$origInv) {
                  $reason = "INVALID: Reversal for deleted invoice {$entry['reversal_invoice_no']}";
                } elseif ($origInv['status'] === 'void') {
                  // Check if original debit journal exists
                  $origDebitCheck = $conn->prepare("
                    SELECT id, is_reversed 
                    FROM gl_journals 
                    WHERE source = 'invoice' 
                      AND source_id = ? 
                      AND is_posted = 1
                      AND is_reversed = 0
                  ");
                  $origDebitCheck->execute([$origInv['id']]);
                  $origDebit = $origDebitCheck->fetch(PDO::FETCH_ASSOC);
                  
                  if (!$origDebit) {
                    $reason = "INVALID: Reversal for voided invoice {$entry['reversal_invoice_no']} - original debit journal missing (orphaned reversal)";
                  } else {
                    // Original debit exists but invoice is void - check if reversal matches
                    $origJournal = $conn->prepare("SELECT is_reversed FROM gl_journals WHERE id = ?");
                    $origJournal->execute([$entry['source_id']]);
                    $origData = $origJournal->fetch(PDO::FETCH_ASSOC);
                    if ($origData && (int)$origData['is_reversed'] === 1) {
                      $isValid = true;
                      $reason = "Valid: Reversal credit entry for voided invoice {$entry['reversal_invoice_no']} (original debit was reversed)";
                    } else {
                      $reason = "INVALID: Reversal for voided invoice {$entry['reversal_invoice_no']} - original journal not reversed";
                    }
                  }
                } else {
                  // Invoice exists and is not void - check if original journal is reversed
                  $origJournal = $conn->prepare("SELECT is_reversed FROM gl_journals WHERE id = ?");
                  $origJournal->execute([$entry['source_id']]);
                  $origData = $origJournal->fetch(PDO::FETCH_ASSOC);
                  if ($origData && (int)$origData['is_reversed'] === 1) {
                    $isValid = true;
                    $reason = "Valid: Reversal credit entry for invoice {$entry['reversal_invoice_no']}";
                  } else {
                    $reason = "INVALID: Reversal exists but original invoice journal is not reversed";
                  }
                }
              } else {
                // Check if original journal exists and is an invoice
                $origCheck = $conn->prepare("
                  SELECT source, source_id, is_reversed 
                  FROM gl_journals 
                  WHERE id = ?
                ");
                $origCheck->execute([$entry['source_id']]);
                $orig = $origCheck->fetch(PDO::FETCH_ASSOC);
                
                if (!$orig) {
                  $reason = "INVALID: Reversal for non-existent journal #{$entry['source_id']} (orphaned reversal)";
                } elseif ($orig['source'] !== 'invoice') {
                  $reason = "INVALID: Reversal for non-invoice journal (source: {$orig['source']})";
                } elseif ((int)$orig['is_reversed'] === 0) {
                  $reason = "INVALID: Reversal exists but original invoice journal is not reversed";
                } else {
                  // Check if invoice exists and is not void
                  $invIdCheck = $conn->prepare("SELECT invoice_no, status FROM invoices WHERE id = ?");
                  $invIdCheck->execute([$orig['source_id']]);
                  $invData = $invIdCheck->fetch(PDO::FETCH_ASSOC);
                  if (!$invData) {
                    $reason = "INVALID: Reversal for deleted invoice (ID: {$orig['source_id']})";
                  } elseif ($invData['status'] === 'void') {
                    // Check if there's a valid original debit that was reversed
                    $origDebitCheck = $conn->prepare("
                      SELECT id FROM gl_journals 
                      WHERE source = 'invoice' 
                        AND source_id = ? 
                        AND is_posted = 1
                        AND is_reversed = 1
                        AND id = ?
                    ");
                    $origDebitCheck->execute([$orig['source_id'], $entry['source_id']]);
                    if ($origDebitCheck->fetch()) {
                      $isValid = true;
                      $reason = "Valid: Reversal credit entry for voided invoice {$invData['invoice_no']} (original debit was reversed)";
                    } else {
                      $reason = "INVALID: Reversal for voided invoice {$invData['invoice_no']} - original debit journal missing or not properly reversed";
                    }
                  } else {
                    $isValid = true;
                    $reason = "Valid: Reversal credit entry for invoice {$invData['invoice_no']}";
                  }
                }
              }
            } else {
              $reason = "INVALID: Credit entry from non-receipt/non-reversal source ({$entry['source']})";
            }
          }
          
          if ($isValid) {
            $validEntries[] = $entry;
          } else {
            $entry['invalid_reason'] = $reason;
            $invalidEntries[] = $entry;
          }
        }
        
        echo "<div class='alert alert-info'>";
        echo "<strong>Total Entries Found:</strong> " . count($allEntries) . "<br>";
        echo "<strong>Valid Entries:</strong> <span class='text-success'>" . count($validEntries) . "</span><br>";
        echo "<strong>Invalid Entries:</strong> <span class='text-danger'>" . count($invalidEntries) . "</span>";
        echo "</div>";
        
        if (!empty($invalidEntries)) {
          echo "<div class='alert alert-warning'>";
          echo "<h5><i class='bi bi-exclamation-triangle'></i> Invalid Entries Found</h5>";
          echo "<p>The following entries violate the Trade Receivable rules:</p>";
          echo "<ul>";
          echo "<li><strong>Debit entries</strong> must be from invoices only (one debit per invoice when issued)</li>";
          echo "<li><strong>Credit entries</strong> must be from receipts or reversals only (multiple credits per invoice are VALID for partial payments)</li>";
          echo "<li>All referenced invoices and receipts must exist in the database</li>";
          echo "<li><strong>Note:</strong> Multiple credit entries (receipts) for the same invoice are perfectly valid and expected for partial payment scenarios</li>";
          echo "</ul>";
          if ($fix) {
            echo "<p class='text-danger'><strong>FIX MODE ENABLED:</strong> Invalid entries will be deleted below.</p>";
          } else {
            echo "<p><a href='?fix=1' class='btn btn-danger' onclick='return confirm(\"Are you sure you want to delete all invalid entries? This will reverse their balance impacts.\")'>Fix Invalid Entries</a></p>";
          }
          echo "</div>";
          
          echo "<div class='table-responsive'>";
          echo "<table class='table table-sm table-bordered'>";
          echo "<thead class='table-light'>";
          echo "<tr>";
          echo "<th>Date</th><th>Journal</th><th>Source</th><th>Reference</th><th>Debit</th><th>Credit</th><th>Issue</th>";
          if ($fix) {
            echo "<th>Action</th>";
          }
          echo "</tr>";
          echo "</thead>";
          echo "<tbody>";
          
          $fixedCount = 0;
          $deletedJournals = [];
          
          foreach ($invalidEntries as $entry) {
            echo "<tr class='invalid-entry'>";
            echo "<td>" . h($entry['journal_date']) . "</td>";
            echo "<td>" . h($entry['journal_no']) . "</td>";
            echo "<td><span class='badge bg-secondary'>" . h($entry['source']) . "</span></td>";
            echo "<td>";
            if ($entry['invoice_no']) echo "Invoice: " . h($entry['invoice_no']);
            elseif ($entry['receipt_no']) echo "Receipt: " . h($entry['receipt_no']);
            elseif ($entry['reversal_invoice_no']) echo "Reversal for: " . h($entry['reversal_invoice_no']);
            else echo "—";
            echo "</td>";
            echo "<td class='text-end'>" . (($entry['debit'] > 0) ? money($entry['debit']) : '—') . "</td>";
            echo "<td class='text-end'>" . (($entry['credit'] > 0) ? money($entry['credit']) : '—') . "</td>";
            echo "<td><small class='text-danger'>" . h($entry['invalid_reason']) . "</small></td>";
            
            if ($fix) {
              echo "<td>";
              try {
                $conn->beginTransaction();
                
                $journalId = (int)$entry['journal_id'];
                
                // Get all lines for this journal to reverse balances
                $lines = $conn->prepare("
                  SELECT account_id, debit, credit, SUBSTRING(j.journal_date, 1, 7) as period
                  FROM gl_journal_lines l
                  JOIN gl_journals j ON j.id = l.journal_id
                  WHERE l.journal_id = ?
                ");
                $lines->execute([$journalId]);
                $journalLines = $lines->fetchAll(PDO::FETCH_ASSOC);
                
                // Reverse balance impacts
                $balUpdate = $conn->prepare("
                  UPDATE gl_account_balances 
                  SET debit = debit - ?, credit = credit - ?
                  WHERE account_id = ? AND period = ?
                ");
                
                foreach ($journalLines as $line) {
                  $balUpdate->execute([
                    (float)$line['debit'],
                    (float)$line['credit'],
                    (int)$line['account_id'],
                    $line['period']
                  ]);
                }
                
                // Delete journal lines
                $conn->prepare("DELETE FROM gl_journal_lines WHERE journal_id = ?")->execute([$journalId]);
                
                // Delete journal
                $conn->prepare("DELETE FROM gl_journals WHERE id = ?")->execute([$journalId]);
                
                $conn->commit();
                
                echo "<span class='text-success'><i class='bi bi-check-circle'></i> Deleted</span>";
                $fixedCount++;
                $deletedJournals[] = $entry['journal_no'];
                
              } catch (Exception $e) {
                $conn->rollBack();
                echo "<span class='text-danger'><i class='bi bi-x-circle'></i> Error: " . h($e->getMessage()) . "</span>";
              }
              echo "</td>";
            }
            
            echo "</tr>";
          }
          
          echo "</tbody>";
          echo "</table>";
          echo "</div>";
          
          if ($fix && $fixedCount > 0) {
            echo "<div class='alert alert-success mt-3'>";
            echo "<h5><i class='bi bi-check-circle'></i> Fix Complete</h5>";
            echo "<p>Deleted <strong>{$fixedCount}</strong> invalid journal(s) and reversed their balance impacts.</p>";
            echo "<p>Deleted journals: " . implode(', ', $deletedJournals) . "</p>";
            echo "<p><a href='?' class='btn btn-primary'>Re-validate</a></p>";
            echo "</div>";
          }
        } else {
          echo "<div class='alert alert-success'>";
          echo "<h5><i class='bi bi-check-circle'></i> All Entries Valid</h5>";
          echo "<p>All Trade Receivable entries follow the correct pattern:</p>";
          echo "<ul>";
          echo "<li>✓ Debit entries are from invoices only</li>";
          echo "<li>✓ Credit entries are from receipts or reversals only</li>";
          echo "<li>✓ All referenced invoices and receipts exist</li>";
          echo "</ul>";
          echo "</div>";
        }
        
        // Show valid entries summary
        if (!empty($validEntries) && empty($invalidEntries)) {
          echo "<div class='mt-4'>";
          echo "<h5>Valid Entries Summary</h5>";
          echo "<div class='table-responsive'>";
          echo "<table class='table table-sm table-bordered'>";
          echo "<thead class='table-light'>";
          echo "<tr><th>Date</th><th>Journal</th><th>Source</th><th>Reference</th><th>Debit</th><th>Credit</th></tr>";
          echo "</thead>";
          echo "<tbody>";
          foreach (array_slice($validEntries, 0, 20) as $entry) {
            echo "<tr class='valid-entry'>";
            echo "<td>" . h($entry['journal_date']) . "</td>";
            echo "<td>" . h($entry['journal_no']) . "</td>";
            echo "<td><span class='badge bg-primary'>" . h($entry['source']) . "</span></td>";
            echo "<td>";
            if ($entry['invoice_no']) echo "Invoice: " . h($entry['invoice_no']);
            elseif ($entry['receipt_no']) echo "Receipt: " . h($entry['receipt_no']);
            elseif ($entry['reversal_invoice_no']) echo "Reversal: " . h($entry['reversal_invoice_no']);
            else echo "—";
            echo "</td>";
            echo "<td class='text-end'>" . (($entry['debit'] > 0) ? money($entry['debit']) : '—') . "</td>";
            echo "<td class='text-end'>" . (($entry['credit'] > 0) ? money($entry['credit']) : '—') . "</td>";
            echo "</tr>";
          }
          if (count($validEntries) > 20) {
            echo "<tr><td colspan='6' class='text-center text-muted'>... and " . (count($validEntries) - 20) . " more valid entries</td></tr>";
          }
          echo "</tbody>";
          echo "</table>";
          echo "</div>";
          echo "</div>";
        }
        
      } catch (Exception $e) {
        echo "<div class='alert alert-danger'>";
        echo "<h5>Error</h5>";
        echo "<p>" . h($e->getMessage()) . "</p>";
        echo "</div>";
      }
      ?>
      
      <div class="mt-4">
        <a href="reports.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Back to Reports</a>
        <a href="report_general_ledger.php?account_id=<?= $arAccountId ?>" class="btn btn-primary"><i class="bi bi-journal-text"></i> View GL Report</a>
      </div>
    </div>
  </div>
</div>
</body>
</html>

