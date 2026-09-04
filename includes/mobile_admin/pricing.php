<?php
/**
 * Pricing Rules Management (Read-only, for reference)
 */

$pricingRules = $conn->query("SELECT * FROM pricing_rules ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="card">
    <div class="card-header">
        <h5 class="mb-0"><i class="fas fa-dollar-sign"></i> Pricing Rules Reference</h5>
    </div>
    <div class="card-body">
        <div class="alert alert-info">
            <i class="fas fa-info-circle"></i> These are the available pricing calculation methods used when creating services.
        </div>
        
        <div class="table-responsive">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Rule Name</th>
                        <th>Calculation Type</th>
                        <th>Description</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pricingRules as $rule): ?>
                        <tr>
                            <td><?= $rule['id'] ?></td>
                            <td><strong><?= htmlspecialchars($rule['name'] ?? '') ?></strong></td>
                            <td><span class="badge bg-primary"><?= $rule['calculation_type'] ?? '' ?></span></td>
                            <td><?= htmlspecialchars($rule['description'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <div class="mt-4">
            <h6>How Pricing Works:</h6>
            <ul class="list-group">
                <li class="list-group-item">
                    <strong>Hourly Rate:</strong> Base Price + (Hours × Professionals × Price Per Unit)
                    <br><small class="text-muted">Example: 80 AED base + (3 hours × 2 workers × 40 AED) = 320 AED</small>
                </li>
                <li class="list-group-item">
                    <strong>Fixed Price:</strong> Single price regardless of duration or options
                    <br><small class="text-muted">Example: Pest Control = 250 AED flat rate</small>
                </li>
                <li class="list-group-item">
                    <strong>Per Square Meter:</strong> Base Price + (Area × Price Per Unit)
                    <br><small class="text-muted">Example: 50 AED base + (100 sqm × 2 AED) = 250 AED</small>
                </li>
                <li class="list-group-item">
                    <strong>Per Room:</strong> Base Price + (Number of Rooms × Price Per Unit)
                    <br><small class="text-muted">Example: 100 AED base + (3 rooms × 50 AED) = 250 AED</small>
                </li>
            </ul>
        </div>
    </div>
</div>

