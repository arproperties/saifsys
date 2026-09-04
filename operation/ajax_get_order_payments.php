<?php
require_once __DIR__.'/../includes/db_connect.php';

$order_id = intval($_GET['order_id'] ?? 0);
if (!$order_id) {
    echo '<div class="text-danger">Invalid order.</div>'; exit;
}

// Get payments
$stmt = $conn->prepare("SELECT * FROM order_payment WHERE order_id = ? ORDER BY payment_date DESC, id DESC");
$stmt->execute([$order_id]);
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get order total
$order = $conn->prepare("SELECT total FROM make_order WHERE id = ?");
$order->execute([$order_id]);
$order_total = $order->fetchColumn();

$paid = 0;
foreach ($payments as $p) $paid += floatval($p['amount']);
$outstanding = $order_total - $paid;

?>
<strong>Payments:</strong>
<ul class="list-group mb-2">
<?php if ($payments): ?>
    <?php foreach ($payments as $p): ?>
        <li class="list-group-item">
            <?= htmlspecialchars($p['payment_date']) ?> -
            <?= number_format($p['amount'],2) ?> AED -
            <?= htmlspecialchars($p['payment_method']) ?>
            <?php if ($p['notes']): ?><br><small><?= htmlspecialchars($p['notes']) ?></small><?php endif; ?>
        </li>
    <?php endforeach; ?>
<?php else: ?>
    <li class="list-group-item text-muted">No payments yet.</li>
<?php endif; ?>
</ul>
<div>
    <strong>Outstanding:</strong>
    <span style="color:<?= $outstanding > 0 ? 'red' : 'green' ?>">
        <?= number_format($outstanding,2) ?> AED
    </span>
</div>
<button class="btn btn-sm btn-outline-success mt-2" onclick="showAddPaymentModal(<?= $order_id ?>, <?= $outstanding ?>)">Add Payment</button>
