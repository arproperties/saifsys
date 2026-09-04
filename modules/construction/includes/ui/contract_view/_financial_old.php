<!-- Financial Dashboard -->
<div class="card card-round mb-3">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <strong class="shop-section-title">Contract Financial Dashboard</strong>
        <span class="text-muted small">Read-only · live documents</span>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <?php
            $kpis = [
                ['Contract Rent (Net)', co_format_money($contract['rent_amount']), 'Total lease rent · plan ' . ($contract['payment_frequency'] ?? ''), ''],
                ['Monthly Equivalent', co_format_money(co_shop_monthly_equivalent_net($contract)), 'Derived from total ÷ months', ''],
                ['VAT Method', $vatMethodLabel, ucfirst($contract['vat_mode'] ?? 'exclusive'), ''],
                ['Contract Duration', ($dashboard['duration_months'] ?? co_shop_contract_month_count((string)($contract['start_date'] ?? ''), (string)($contract['end_date'] ?? ''))) . ' months', $contract['start_date'] . ' → ' . $contract['end_date'], ''],
                ['Security Deposit', co_format_money($contract['security_deposit']), 'Required', ''],
                ['Total Invoiced', $summary ? co_format_money($summary['invoiced']) : '—', $summary ? ('VAT ' . co_format_money($summary['vat_invoiced'])) : '', ''],
                ['Total Collected', $summary ? co_format_money($summary['collected']) : '—', '', 'ok'],
                ['Outstanding', $summary ? co_format_money($summary['outstanding']) : '—', '', (!empty($summary['outstanding']) && $summary['outstanding'] > 0.005) ? 'warn' : 'ok'],
                ['Overdue', $summary ? co_format_money($summary['overdue']) : '—', '', (!empty($summary['overdue']) && $summary['overdue'] > 0.005) ? 'danger' : 'ok'],
                ['Tenant Credit', $summary ? co_format_money($summary['client_credit']) : '—', '', (!empty($summary['client_credit']) && $summary['client_credit'] > 0.005) ? 'warn' : ''],
                ['Deferred Revenue', $summary ? co_format_money($summary['deferred_revenue']) : '—', !empty($contract['accrual_deferred_rent']) ? 'Balance on 2215' : 'Direct income', ''],
                ['Deposit Held', $summary ? co_format_money($summary['deposit_received']) : co_format_money($contract['deposit_received_amount']), 'of ' . co_format_money($contract['security_deposit']), ((!empty($summary['deposit_outstanding']) && $summary['deposit_outstanding'] > 0.005) ? 'warn' : 'ok')],
                ['Commission (Net)', $commissionStatus ? co_format_money($commissionStatus['net']) : '—', $commissionStatus ? ('VAT ' . co_format_money($commissionStatus['vat']) . ' · ' . $commissionStatus['status']) : 'Run Phase 1.6 migration', $commissionStatus && $commissionStatus['outstanding'] > 0.005 ? 'warn' : ''],
                ['Commission Collected', $commissionStatus ? co_format_money($commissionStatus['collected']) : '—', $commissionStatus ? ('Invoiced ' . co_format_money($commissionStatus['invoiced'])) : '', ''],
                ['Commission Outstanding', $commissionStatus ? co_format_money($commissionStatus['outstanding']) : '—', '', $commissionStatus && $commissionStatus['outstanding'] > 0.005 ? 'warn' : 'ok'],
            ];
            foreach ($kpis as [$label, $value, $sub, $tone]):
            ?>
            <div class="col-6 col-md-4 col-xl-2">
                <div class="shop-kpi p-3 <?= h($tone) ?>">
                    <div class="kpi-label"><?= h($label) ?></div>
                    <div class="kpi-value"><?= h(strip_tags((string)$value)) ?></div>
                    <?php if ($sub !== ''): ?><div class="kpi-sub"><?= h((string)$sub) ?></div><?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
