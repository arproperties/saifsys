<?php
if (!function_exists('safe')) {
    function safe($s) { return clients_page_safe($s); }
}
if (!function_exists('money2')) {
    function money2($n) { return clients_page_money2($n); }
}
if (!function_exists('money')) {
    function money($n) { return clients_page_money($n); }
}
if (!function_exists('getHealthScoreClass')) {
    function getHealthScoreClass($score) { return clients_page_health_class($score); }
}
?>
        <div class="p-3 border-bottom d-flex align-items-center">
          <div>
            <h5 class="mb-0"><?= safe($client['client_name']) ?></h5>
            <div class="text-muted small">
              <?= safe($client['email'] ?? '-') ?> • <?= safe($client['mobile_num'] ?? '-') ?> • TRN: <?= safe($client['trn'] ?? '-') ?>
            </div>
          </div>
          <div class="ms-auto d-flex gap-2">
            <a class="btn btn-outline-primary-soft btn-sm" href="operation/order_add.php?client_id=<?= (int)$client_id ?>">
              <i class="bi bi-briefcase"></i> New Work Order
            </a>
            <div class="dropdown">
              <button class="btn btn-outline-success btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown">
                <i class="bi bi-download"></i> Export
              </button>
              <ul class="dropdown-menu">
                <li><a class="dropdown-item" href="#" onclick="exportClient('csv', 'complete')">
                  <i class="bi bi-file-earmark-text"></i> Complete Report (CSV)
                </a></li>
                <li><a class="dropdown-item" href="#" onclick="exportClient('csv', 'profile')">
                  <i class="bi bi-person"></i> Client Profile (CSV)
                </a></li>
                <li><a class="dropdown-item" href="#" onclick="exportClient('csv', 'orders')">
                  <i class="bi bi-list-check"></i> Orders History (CSV)
                </a></li>
                <li><a class="dropdown-item" href="#" onclick="exportClient('csv', 'invoices')">
                  <i class="bi bi-receipt"></i> Invoices (CSV)
                </a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="#" onclick="exportClient('pdf', 'profile')">
                  <i class="bi bi-file-earmark-pdf"></i> Profile PDF
                </a></li>
                <li><a class="dropdown-item" href="#" onclick="exportClient('excel', 'complete')">
                  <i class="bi bi-file-earmark-excel"></i> Complete Excel
                </a></li>
              </ul>
            </div>
            <button class="btn btn-secondary" onclick="showEditClientModal(<?= (int)$client['id'] ?>)">Edit</button>
            <div class="dropdown position-relative">
              <button class="btn btn-outline-secondary dropdown-toggle" type="button" onclick="toggleStatusDropdown()">
                <i class="bi bi-gear"></i> Status
              </button>
              <ul class="dropdown-menu position-absolute" id="statusDropdown" style="display: none; top: 100%; left: 0; z-index: 1000; min-width: 200px;">
                <li><a class="dropdown-item" href="javascript:void(0)" onclick="setClientStatus(<?= (int)$client['id'] ?>, 'active')">
                  <i class="bi bi-check-circle"></i> Set as Active
                </a></li>
                <li><a class="dropdown-item" href="javascript:void(0)" onclick="setClientStatus(<?= (int)$client['id'] ?>, 'vip')">
                  <i class="bi bi-star"></i> Set as VIP
                </a></li>
                <li><a class="dropdown-item" href="javascript:void(0)" onclick="setClientStatus(<?= (int)$client['id'] ?>, 'inactive')">
                  <i class="bi bi-pause-circle"></i> Set as Inactive
                </a></li>
                <li><a class="dropdown-item" href="javascript:void(0)" onclick="setClientStatus(<?= (int)$client['id'] ?>, 'at_risk')">
                  <i class="bi bi-exclamation-triangle"></i> Set as At Risk
                </a></li>
              </ul>
            </div>
            <button class="btn btn-danger" onclick="confirmDeleteClient(<?= (int)$client['id'] ?>)">Delete</button>
            <button class="btn btn-outline-dark btn-sm" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
          </div>
        </div>

        <!-- Enhanced KPIs -->
        <div class="p-4">
          <div class="row g-4">
            <div class="col-lg-4 col-md-6">
              <div class="kpi primary">
                <div class="subtitle">Open Balance</div>
                <div class="val">AED <?= money2($sum_open) ?></div>
                <?php if ($pending_invoicing > 0): ?>
                  <div class="small text-muted mt-1">
                    Invoiced: AED <?= money2($invoiced_balance) ?><br>
                    Pending: AED <?= money2($pending_invoicing) ?>
                  </div>
                <?php endif; ?>
                <div class="trend <?= $sum_open>0?'negative':'positive' ?>">
                  <i class="bi bi-<?= $sum_open>0?'arrow-up':'check-circle' ?>"></i>
                  <?= $sum_open>0?'Outstanding invoices':'No dues' ?>
              </div>
            </div>
            </div>
            <div class="col-lg-4 col-md-6">
              <div class="kpi success">
                <div class="subtitle">Total Invoiced<?= ($from||$to)?' (range)':'' ?></div>
                <div class="val">AED <?= money2($sum_total) ?></div>
                <div class="trend positive">
                  <i class="bi bi-arrow-up"></i>
                  Paid: AED <?= money2($sum_paid) ?>
              </div>
            </div>
            </div>
            <div class="col-lg-4 col-md-6">
              <div class="kpi warning">
                <div class="subtitle">Service Hours<?= ($from||$to)?' (range)':'' ?></div>
                <div class="val"><?= number_format($total_hours,2) ?></div>
                <div class="trend positive">
                  <i class="bi bi-clock"></i>
                  Value: AED <?= money2($orders_amount) ?>
              </div>
            </div>
                </div>
            <div class="col-lg-4 col-md-6">
              <div class="kpi info">
                <div class="subtitle">Available Credit</div>
                <div class="val">AED <?= money($available_credit) ?></div>
                <div class="trend neutral">
                  <i class="bi bi-wallet2"></i>
                  Unapplied receipts
              </div>
            </div>
            </div>
            <div class="col-lg-4 col-md-6">
              <div class="kpi muted">
                <div class="subtitle">Credit Limit</div>
                <div class="val">AED <?= money2((float)($client['credit_limit'] ?? 0)) ?></div>
                <div class="trend neutral">
                  <i class="bi bi-shield-check"></i>
                  Terms: <?= safe($client['terms'] ?? 'cash') ?>
                </div>
              </div>
            </div>
            <div class="col-lg-4 col-md-6">
              <div class="kpi <?= $client['health_score']>=80?'success':($client['health_score']>=60?'warning':'danger') ?>">
                <div class="subtitle">Health Score</div>
                <div class="val"><?= (int)($client['health_score'] ?? 50) ?></div>
                <div class="trend <?= $client['health_score']>=60?'positive':'negative' ?>">
                  <i class="bi bi-heart-pulse"></i>
                  <?= getHealthScoreClass($client['health_score'] ?? 50) ?>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Filters -->
        <form class="px-3 pb-3" id="clientDateFilterForm" onsubmit="return applyClientDateFilter(event)">
          <input type="hidden" name="client_id" value="<?= (int)$client_id ?>">
          <div class="row g-2 align-items-end">
            <div class="col-md-3">
              <label class="form-label small text-muted mb-1">From</label>
              <input type="date" class="form-control" name="from" id="clientFilterFrom" value="<?= safe($from) ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label small text-muted mb-1">To</label>
              <input type="date" class="form-control" name="to" id="clientFilterTo" value="<?= safe($to) ?>">
            </div>
            <div class="col-md-6 text-md-end">
              <button type="submit" class="btn btn-primary"><i class="bi bi-funnel"></i> Apply</button>
              <button type="button" class="btn btn-outline-secondary" onclick="clearClientDateFilter()">Clear</button>
            </div>
          </div>
        </form>

        <!-- Enhanced Tabs -->
        <ul class="nav nav-tabs px-3" role="tablist">
          <li class="nav-item">
            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-orders" type="button">
              <i class="bi bi-briefcase me-1"></i> Orders
            </button>
          </li>
          <li class="nav-item">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-analytics" type="button">
              <i class="bi bi-graph-up me-1"></i> Analytics
            </button>
          </li>
          <li class="nav-item">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-timeline" type="button">
              <i class="bi bi-clock-history me-1"></i> Timeline
            </button>
          </li>
          <li class="nav-item">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-invoices" type="button">
              <i class="bi bi-receipt me-1"></i> Invoices
            </button>
          </li>
          <li class="nav-item">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-payments" type="button">
              <i class="bi bi-cash-coin me-1"></i> Payments
            </button>
          </li>
          <li class="nav-item">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-advbalance" type="button">
              <i class="bi bi-wallet2 me-1"></i> Balance
            </button>
          </li>
          <li class="nav-item">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-documents" type="button">
              <i class="bi bi-file-earmark-text me-1"></i> Documents
            </button>
          </li>
          <li class="nav-item">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-communications" type="button">
              <i class="bi bi-chat-dots me-1"></i> Communications
            </button>
          </li>
          <li class="nav-item">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-notes" type="button">
              <i class="bi bi-journal-text me-1"></i> Notes & Tasks
            </button>
          </li>
          <li class="nav-item">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-service" type="button">
              <i class="bi bi-gear-wide-connected me-1"></i> Service & Sites
            </button>
          </li>
        </ul>

        <div class="tab-content p-3">
          <!-- Orders -->
          <div class="tab-pane fade show active" id="tab-orders">
            <?php if($orders): ?>
            <div class="table-responsive">
              <table class="table table-hover align-middle">
                <thead class="table-light">
                  <tr>
                    <th>ID</th><th>Date</th><th>Time</th><th>Workers</th>
                    <th class="text-end">Hours</th><th class="text-end">Value</th>
                    <th>Driver</th><th>Invoice</th><th></th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach($orders as $o): ?>
                  <tr>
                    <td><?= (int)$o['id'] ?></td>
                    <td><?= safe($o['service_date'] ?: $o['date']) ?></td>
                    <td><?= safe($o['time']) ?></td>
                    <td><?= safe($o['worker_name']) ?></td>
                    <td class="text-end"><?= money2($o['hours']) ?></td>
                    <td class="text-end">AED <?= money2($o['invoice_total'] ?? ($o['grand_total'] ?? $o['total'] ?? 0)) ?></td>
                    <td><?= safe($o['driver_name']) ?></td>
                    <td>
                      <?php if($o['invoice_id']): ?>
                        <a class="btn btn-sm btn-outline-primary" target="_blank" href="accounts/invoice_view.php?id=<?= (int)$o['invoice_id'] ?>">
                          <?= safe($o['invoice_no']) ?>
                        </a>
                        <?php if($o['invoice_status']): ?>
                          <span class="tag bg-light text-dark border"><?= safe(str_replace('_',' ',$o['invoice_status'])) ?></span>
                        <?php endif; ?>
                      <?php else: ?>
                        <span class="text-muted">—</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <button class="btn btn-sm btn-link" onclick="togglePayments(this, <?= (int)$o['id'] ?>)">
                        <i class="bi bi-chevron-down"></i> Details
                      </button>
                    </td>
                  </tr>
                  <tr id="payment-row-<?= (int)$o['id'] ?>" class="payment-row" style="display:none;">
                    <td colspan="9"><div id="payments-container-<?= (int)$o['id'] ?>"></div></td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <?php else: ?>
              <div class="text-muted">No orders<?= ($from||$to)?' in selected range':'' ?>.</div>
            <?php endif; ?>
          </div>

          <!-- Analytics -->
          <div class="tab-pane fade" id="tab-analytics">
            <div class="row g-4">
              <!-- Smart Insights Panel -->
              <div class="col-12">
                <div class="insights-container">
                  <div class="chart-title">Smart Insights</div>
                  <div id="insightsContainer">
                    <div class="text-center py-4">
                      <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                      </div>
                      <p class="mt-2 text-muted">Analyzing client behavior...</p>
                    </div>
                  </div>
                </div>
              </div>
              
              <!-- Chart Controls -->
              <div class="col-12">
                <div class="filter-panel">
                  <div class="d-flex align-items-center justify-content-between mb-3">
                    <h6 class="mb-0">Analytics Dashboard</h6>
                    <div class="d-flex gap-2">
                      <select id="analyticsPeriod" class="form-select form-select-sm" style="width: auto;">
                        <option value="3months">Last 3 Months</option>
                        <option value="6months">Last 6 Months</option>
                        <option value="12months" selected>Last 12 Months</option>
                        <option value="24months">Last 24 Months</option>
                      </select>
                      <button id="refreshAnalytics" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-arrow-clockwise"></i> Refresh
                      </button>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Revenue Trend Chart -->
              <div class="col-lg-8">
                <div class="chart-container">
                  <div class="chart-title">Revenue Trend</div>
                  <canvas id="revenueChart"></canvas>
                </div>
              </div>

              <!-- Client Health Gauge -->
              <div class="col-lg-4">
                <div class="chart-container">
                  <div class="chart-title">Client Health Score</div>
                  <canvas id="healthChart"></canvas>
                </div>
              </div>

              <!-- Order Distribution -->
              <div class="col-lg-6">
                <div class="chart-container">
                  <div class="chart-title">Order Distribution by Status</div>
                  <canvas id="orderDistributionChart"></canvas>
                </div>
              </div>

              <!-- Payment Patterns -->
              <div class="col-lg-6">
                <div class="chart-container">
                  <div class="chart-title">Payment Methods</div>
                  <canvas id="paymentChart"></canvas>
                </div>
              </div>

              <!-- Hours vs Revenue -->
              <div class="col-lg-8">
                <div class="chart-container">
                  <div class="chart-title">Hours vs Revenue Correlation</div>
                  <canvas id="hoursRevenueChart"></canvas>
                </div>
              </div>

              <!-- AR Aging -->
              <div class="col-lg-4">
                <div class="chart-container">
                  <div class="chart-title">AR Aging Analysis</div>
                  <canvas id="arAgingChart"></canvas>
                </div>
              </div>

              <!-- Summary Stats -->
              <div class="col-12">
                <div class="chart-container">
                  <div class="chart-title">Analytics Summary</div>
                  <div id="analyticsSummary" class="row g-3">
                    <!-- Will be populated by JavaScript -->
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Timeline -->
          <div class="tab-pane fade" id="tab-timeline">
            <div class="row">
              <div class="col-12">
                <div class="d-flex justify-content-between align-items-center mb-3">
                  <h6 class="mb-0">Client Activity Timeline</h6>
                  <div class="d-flex gap-2">
                    <select id="timelineFilter" class="form-select form-select-sm" style="width: auto;">
                      <option value="">All Activities</option>
                      <option value="orders">Orders</option>
                      <option value="invoices">Invoices</option>
                      <option value="payments">Payments</option>
                      <option value="communications">Communications</option>
                      <option value="notes">Notes</option>
                      <option value="tasks">Tasks</option>
                      <option value="documents">Documents</option>
                    </select>
                    <button id="refreshTimeline" class="btn btn-sm btn-outline-primary">
                      <i class="bi bi-arrow-clockwise"></i> Refresh
                    </button>
                  </div>
                </div>
                <div id="timelineContainer">
                  <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                      <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2 text-muted">Loading timeline...</p>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Invoices -->
          <div class="tab-pane fade" id="tab-invoices">
            <?php if($invoices): ?>
            <div class="table-responsive">
              <table class="table table-hover align-middle">
                <thead class="table-light">
                  <tr>
                    <th>Invoice #</th><th>Date</th>
                    <th class="text-end">Total</th><th class="text-end">Paid</th><th class="text-end">Balance</th>
                    <th>Status</th><th></th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach($invoices as $iv): ?>
                  <tr>
                    <td><?= safe($iv['invoice_no']) ?></td>
                    <td><?= safe($iv['issue_date']) ?></td>
                    <td class="text-end"><?= money2($iv['total']) ?></td>
                    <td class="text-end"><?= money2($iv['paid']) ?></td>
                    <td class="text-end"><?= money2(max(0,$iv['balance'])) ?></td>
                    <td>
                      <span class="badge text-bg-<?= ($iv['status']==='paid'?'success':($iv['status']==='partially_paid'?'warning':($iv['status']==='void'?'secondary':'info'))) ?>">
                        <?= safe(str_replace('_',' ',$iv['status'])) ?>
                      </span>
                    </td>
                    <td class="text-end">
                      <a class="btn btn-sm btn-outline-primary" target="_blank" href="accounts/invoice_view.php?id=<?= (int)$iv['id'] ?>">Open</a>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <?php else: ?>
              <div class="text-muted">No invoices<?= ($from||$to)?' in selected range':'' ?>.</div>
            <?php endif; ?>
          </div>

          <!-- Payments -->
          <div class="tab-pane fade" id="tab-payments">
            <?php if($payments): ?>
            <div class="table-responsive">
              <table class="table table-hover align-middle">
                <thead class="table-light">
                  <tr>
                    <th>Date</th><th>Receipt #</th><th>Method</th>
                    <th class="text-end">Amount</th><th>Allocated To</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach($payments as $p): ?>
                  <tr>
                    <td><?= safe($p['receipt_date']) ?></td>
                    <td><?= safe($p['receipt_no']) ?></td>
                    <td><?= safe($p['method']) ?></td>
                    <td class="text-end"><?= money2($p['amount']) ?></td>
                    <td><?= safe($p['allocations'] ?: '—') ?></td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <?php else: ?>
              <div class="text-muted">No payments<?= ($from||$to)?' in selected range':'' ?>.</div>
            <?php endif; ?>
            <?php if($last_payment): ?>
              <div class="small text-muted mt-2">Last payment: <?= safe($last_payment['receipt_date']) ?> • <?= safe($last_payment['method']) ?> • AED <?= money2($last_payment['amount']) ?></div>
            <?php endif; ?>
          </div>

          <!-- Advanced Balance (Unapplied Receipts) -->
          <div class="tab-pane fade" id="tab-advbalance">
            <div class="card border-0 shadow-sm">
              <div class="card-header bg-light d-flex align-items-center">
                <strong>Unapplied Receipts</strong>
                <span class="badge text-bg-info ms-2">AED <?= money($available_credit) ?> available</span>
              </div>
              <div class="card-body">
                <?php if (!$unapplied_receipts): ?>
                  <div class="text-muted">No unapplied receipts.</div>
                <?php else: ?>
                  <div class="table-responsive">
                    <table class="table table-sm align-middle">
                      <thead class="table-light">
                        <tr>
                          <th>Date</th><th>Receipt</th><th>Method</th>
                          <th class="text-end">Remaining</th>
                          <th style="width:26rem">Apply to Invoice</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($unapplied_receipts as $r): ?>
                          <tr>
                            <td><?= h($r['receipt_date']) ?></td>
                            <td>
                              <a class="btn btn-sm btn-outline-secondary"
                                 href="accounts/receipt_view.php?id=<?= (int)$r['id'] ?>" target="_blank">
                                <?= h($r['receipt_no']) ?>
                              </a>
                            </td>
                            <td><?= h($r['method']) ?></td>
                            <td class="text-end"><?= money($r['remaining']) ?></td>
                            <td>
                              <?php if (!$open_invoices): ?>
                                <span class="text-muted">No open invoices.</span>
                              <?php else: ?>
                                <form class="d-flex gap-2 align-items-center"
                                      action="accounts/invoice_view.php" method="get" target="_blank">
                                  <input type="hidden" name="applyCredit" value="1">
                                  <select class="form-select form-select-sm" name="id" required style="max-width:260px">
                                    <option value="">Select invoice…</option>
                                    <?php foreach ($open_invoices as $inv): ?>
                                      <option value="<?= (int)$inv['id'] ?>">
                                        <?= h($inv['invoice_no']) ?> — AED <?= money($inv['balance']) ?>
                                      </option>
                                    <?php endforeach; ?>
                                  </select>
                                  <input type="hidden" name="credit_receipt_id" value="<?= (int)$r['id'] ?>">
                                  <button class="btn btn-sm btn-primary">Apply</button>
                                </form>
                              <?php endif; ?>
                            </td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          </div> <!-- /#tab-advbalance -->

          <!-- Documents -->
          <div class="tab-pane fade" id="tab-documents">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <h6 class="mb-0">Client Documents</h6>
              <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#uploadDocumentModal">
                <i class="bi bi-cloud-upload"></i> Upload Document
              </button>
            </div>
            <div id="documentsContainer">
              <div class="text-center py-4">
                <div class="spinner-border text-primary" role="status">
                  <span class="visually-hidden">Loading...</span>
                </div>
                <p class="mt-2 text-muted">Loading documents...</p>
              </div>
            </div>
          </div>

          <!-- Communications -->
          <div class="tab-pane fade" id="tab-communications">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <h6 class="mb-0">Communication History</h6>
              <div class="d-flex gap-2">
                <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#sendEmailModal">
                  <i class="bi bi-envelope"></i> Send Email
                </button>
                <button class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#sendSMSModal">
                  <i class="bi bi-chat-text"></i> Send SMS
                </button>
              </div>
            </div>
            <div id="communicationsContainer">
              <div class="text-center py-4">
                <div class="spinner-border text-primary" role="status">
                  <span class="visually-hidden">Loading...</span>
                </div>
                <p class="mt-2 text-muted">Loading communications...</p>
              </div>
            </div>
          </div>

          <!-- Notes & Tasks -->
          <div class="tab-pane fade" id="tab-notes">
            <div class="row">
              <div class="col-md-8">
                <div class="d-flex justify-content-between align-items-center mb-3">
                  <h6 class="mb-0">Notes</h6>
                  <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addNoteModal">
                    <i class="bi bi-plus-circle"></i> Add Note
                  </button>
                </div>
                <div id="notesContainer">
                  <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                      <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2 text-muted">Loading notes...</p>
                  </div>
                </div>
              </div>
              <div class="col-md-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                  <h6 class="mb-0">Tasks</h6>
                  <button class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#addTaskModal">
                    <i class="bi bi-plus-circle"></i> Add Task
                  </button>
                </div>
                <div id="tasksContainer">
                  <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                      <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2 text-muted">Loading tasks...</p>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Service & Sites -->
          <div class="tab-pane fade" id="tab-service">
            <div class="row g-4">
              <!-- Service Locations/Sites -->
              <div class="col-md-6">
                <div class="d-flex justify-content-between align-items-center mb-3">
                  <h6 class="mb-0">Service Locations</h6>
                  <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addSiteModal">
                    <i class="bi bi-plus-circle"></i> Add Site
                  </button>
                </div>
                <div id="sitesContainer">
                  <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                      <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2 text-muted">Loading sites...</p>
                  </div>
                </div>
              </div>

              <!-- Service Preferences -->
              <div class="col-md-6">
                <div class="d-flex justify-content-between align-items-center mb-3">
                  <h6 class="mb-0">Service Preferences</h6>
                  <button class="btn btn-sm btn-outline-primary" onclick="editPreferences()">
                    <i class="bi bi-pencil"></i> Edit
                  </button>
                </div>
                <div id="preferencesContainer">
                  <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                      <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2 text-muted">Loading preferences...</p>
                  </div>
                </div>
              </div>

              <!-- Worker Preferences -->
              <div class="col-12">
                <div class="chart-container">
                  <div class="chart-title">Worker Preference Analytics</div>
                  <div id="workerPreferencesContainer">
                    <div class="text-center py-4">
                      <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Quality Ratings -->
              <div class="col-12">
                <div class="chart-container">
                  <div class="d-flex justify-content-between align-items-center mb-3">
                    <div class="chart-title mb-0">Quality & Service Ratings</div>
                    <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#addRatingModal">
                      <i class="bi bi-star"></i> Add Rating
                    </button>
                  </div>
                  <div id="qualityRatingsContainer">
                    <div class="text-center py-4">
                      <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div> <!-- /.tab-content -->

