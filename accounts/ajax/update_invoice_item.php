<?php
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/db_connect.php';
require_once __DIR__.'/../../includes/ar_helpers.php';
require_once __DIR__.'/../../includes/work_order_financial_guard.php';
require_role(['Owner','Admin','Account'], $conn);

header('Content-Type: application/json');

$item_id = $_POST['item_id'] ?? '';
$field = $_POST['field'] ?? '';
$value = $_POST['value'] ?? '';

if (empty($item_id) || empty($field) || !in_array($field, ['qty', 'unit_price'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
    exit;
}

try {
    $conn->beginTransaction();
    
    // Get current item details
    $stmt = $conn->prepare("SELECT * FROM invoice_items WHERE id = ?");
    $stmt->execute([$item_id]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$item) {
        throw new Exception('Invoice item not found');
    }
    
    // Get invoice details
    $stmt = $conn->prepare("SELECT * FROM invoices WHERE id = ?");
    $stmt->execute([$item['invoice_id']]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$invoice) {
        throw new Exception('Invoice not found');
    }
    
    // Check if invoice can be edited
    if (!in_array($invoice['status'], ['draft', 'issued', 'partially_paid'])) {
        throw new Exception('Cannot edit items for paid or void invoices');
    }

    $opsLock = wo_invoice_from_operations_locked($conn, (int)$invoice['id']);
    if ($opsLock['locked']) {
        throw new Exception($opsLock['reason']);
    }
    
    // Update the field
    $update_sql = "UPDATE invoice_items SET {$field} = ? WHERE id = ?";
    $stmt = $conn->prepare($update_sql);
    $stmt->execute([$value, $item_id]);
    
    // Recalculate line totals
    $stmt = $conn->prepare("
        UPDATE invoice_items 
        SET line_subtotal = qty * unit_price,
            line_total = line_subtotal + (line_subtotal * vat_rate / 100)
        WHERE id = ?
    ");
    $stmt->execute([$item_id]);
    
    // Get updated item
    $stmt = $conn->prepare("SELECT * FROM invoice_items WHERE id = ?");
    $stmt->execute([$item_id]);
    $updated_item = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Recalculate invoice totals
    $stmt = $conn->prepare("
        SELECT 
            SUM(line_subtotal) as subtotal,
            SUM(line_total - line_subtotal) as vat_amount,
            SUM(line_total) as total
        FROM invoice_items 
        WHERE invoice_id = ?
    ");
    $stmt->execute([$invoice['id']]);
    $totals = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Update invoice totals
    $stmt = $conn->prepare("
        UPDATE invoices 
        SET subtotal = ?, vat_amount = ?, total = ?, updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([
        $totals['subtotal'],
        $totals['vat_amount'],
        $totals['total'],
        $invoice['id']
    ]);

    // Refresh status + repost to GL (keeps balance/paid accurate)
    ar_refresh_status_from_allocations($conn, $invoice['id']);
    ar_post_or_repost_invoice($conn, $invoice['id']);

    $updatedInvoice = ar_get_invoice($conn, $invoice['id']);
    $amountPaid = isset($updatedInvoice['amount_paid']) ? (float)$updatedInvoice['amount_paid'] : 0.0;
    $balanceDue = isset($updatedInvoice['balance_due']) ? (float)$updatedInvoice['balance_due'] : max(0.0, (float)$totals['total'] - $amountPaid);
    
    // Log audit trail
    require_once __DIR__ . '/../../includes/AuditService.php';
    AuditService::log([
        'action' => 'update',
        'object_type' => 'invoice_items',
        'object_id' => (string)$item_id,
        'summary' => "Updated invoice item {$field} to {$value}",
        'old_data' => [$field => $item[$field]],
        'new_data' => [$field => $value],
        'success' => true
    ]);
    
    $conn->commit();
    
    echo json_encode([
        'success' => true,
        'item' => [
            'qty' => $updated_item['qty'],
            'unit_price' => $updated_item['unit_price'],
            'line_subtotal' => $updated_item['line_subtotal'],
            'line_total' => $updated_item['line_total']
        ],
        'invoice_totals' => [
            'subtotal' => $totals['subtotal'],
            'vat_amount' => $totals['vat_amount'],
            'total' => $totals['total'],
            'amount_paid' => $amountPaid,
            'balance_due' => $balanceDue
        ]
    ]);
    
} catch (Exception $e) {
    $conn->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
