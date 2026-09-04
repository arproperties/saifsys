<?php
/**
 * Owner-only utilities previously listed on Reports.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_role(['Owner'], $conn);

if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Tools</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
  body{background:#f6f7f9}
  .hero{background:#fff;border-radius:18px;box-shadow:0 10px 24px rgba(0,0,0,.06);padding:18px 22px;margin-bottom:18px}
  .report-card{
    border:0;border-radius:18px;background:#fff; box-shadow:0 10px 24px rgba(0,0,0,.06);
    transition: transform .08s ease, box-shadow .08s ease;
  }
  .report-card:hover{ transform: translateY(-2px); box-shadow:0 14px 28px rgba(0,0,0,.09); }
  .r-icon{width:48px;height:48px;border-radius:12px;display:grid;place-items:center;background:#80000010;color:#800000}
  .r-title{font-weight:700;margin-bottom:.2rem}
  .r-desc{color:#6b7280;font-size:.92rem}
  .badge-soon{background:#fff3cd;color:#8a6d3b;border:1px solid #ffe8a1}
</style>
</head>
<body>
<div class="container my-4">

  <div class="hero d-flex align-items-center">
    <div>
      <div class="text-uppercase small text-muted">Accounting</div>
      <h3 class="mb-0">Tools</h3>
      <div class="text-muted">Diagnostic and maintenance utilities (Owner access).</div>
    </div>
    <div class="ms-auto">
      <a href="reports.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Reports</a>
    </div>
  </div>

  <div class="row g-3">
    <!-- Live Data Repair -->
    <div class="col-xl-4 col-lg-6">
      <div class="report-card p-3 h-100 border-danger" style="border-width: 2px !important;">
        <div class="d-flex align-items-start">
          <div class="r-icon me-3" style="background:#f8d7da;color:#721c24"><i class="bi bi-lightning-charge fs-4"></i></div>
          <div class="flex-grow-1">
            <div class="d-flex align-items-center">
              <div class="r-title">Live Data Repair</div>
              <span class="badge bg-danger ms-2">Go-live</span>
            </div>
            <div class="r-desc">Batch-fix legacy health issues: allocations, missing GL, WO sync. Dry-run first, then execute on production.</div>
          </div>
        </div>
        <div class="mt-3 d-flex gap-2">
          <a class="btn btn-danger" href="sm_live_data_repair.php"><i class="bi bi-lightning-charge"></i> Open Repair Console</a>
        </div>
      </div>
    </div>

    <!-- System Health Check -->
    <div class="col-xl-4 col-lg-6">
      <div class="report-card p-3 h-100 border-success" style="border-width: 2px !important;">
        <div class="d-flex align-items-start">
          <div class="r-icon me-3" style="background:#d1e7dd;color:#0f5132"><i class="bi bi-shield-check fs-4"></i></div>
          <div class="flex-grow-1">
            <div class="d-flex align-items-center">
              <div class="r-title">System Health Check</div>
              <span class="badge bg-success ms-2">New</span>
            </div>
            <div class="r-desc">WO/invoice/GL/dashboard integrity scan with suggested actions (read-only).</div>
          </div>
        </div>
        <div class="mt-3 d-flex gap-2">
          <a class="btn btn-success" href="system_health_check.php"><i class="bi bi-shield-check"></i> Open</a>
          <a class="btn btn-outline-primary" href="report_wo_invoice_reconciliation.php"><i class="bi bi-table"></i> WO vs Invoice</a>
        </div>
      </div>
    </div>

    <!-- Accounting Health -->
    <div class="col-xl-4 col-lg-6">
      <div class="report-card p-3 h-100 border-success" style="border-width: 2px !important;">
        <div class="d-flex align-items-start">
          <div class="r-icon me-3" style="background:#d1e7dd;color:#0f5132"><i class="bi bi-heart-pulse fs-4"></i></div>
          <div class="flex-grow-1">
            <div class="d-flex align-items-center">
              <div class="r-title">Accounting Health</div>
              <span class="badge bg-success ms-2">Dashboard</span>
            </div>
            <div class="r-desc">Read-only checks for invoice journals, receipt journals, allocations, reversals and Trade Receivable integrity.</div>
          </div>
        </div>
        <div class="mt-3 d-flex gap-2">
          <a class="btn btn-success" href="accounting_health.php"><i class="bi bi-heart-pulse"></i> Open Dashboard</a>
        </div>
      </div>
    </div>

    <!-- Opening Balances -->
    <div class="col-xl-4 col-lg-6">
      <div class="report-card p-3 h-100 border-primary" style="border-width: 2px !important;">
        <div class="d-flex align-items-start">
          <div class="r-icon me-3" style="background:#e7f1ff;color:#0d6efd"><i class="bi bi-journal-plus fs-4"></i></div>
          <div class="flex-grow-1">
            <div class="d-flex align-items-center">
              <div class="r-title">Opening Balances</div>
              <span class="badge badge-soon ms-2">Setup</span>
            </div>
            <div class="r-desc">Post opening balances through balanced manual journals with an offset account.</div>
          </div>
        </div>
        <div class="mt-3 d-flex gap-2">
          <a class="btn btn-primary" href="opening_balances.php"><i class="bi bi-journal-plus"></i> Open</a>
        </div>
      </div>
    </div>

    <!-- AR Discrepancy Diagnostic -->
    <div class="col-xl-4 col-lg-6">
      <div class="report-card p-3 h-100 border-info" style="border-width: 2px !important;">
        <div class="d-flex align-items-start">
          <div class="r-icon me-3" style="background:#d1ecf1;color:#0c5460"><i class="bi bi-search fs-4"></i></div>
          <div class="flex-grow-1">
            <div class="d-flex align-items-center">
              <div class="r-title">AR Discrepancy Diagnostic</div>
              <span class="badge badge-soon ms-2">Tool</span>
            </div>
            <div class="r-desc">Compare AR Ageing Report vs Trade Receivables (1110) GL balance. Find and explain differences.</div>
          </div>
        </div>
        <div class="mt-3 d-flex gap-2">
          <a class="btn btn-info" href="diagnose_ar_discrepancy.php"><i class="bi bi-search"></i> Open Tool</a>
        </div>
      </div>
    </div>

    <!-- Reversal Diagnostic & Repair -->
    <div class="col-xl-4 col-lg-6">
      <div class="report-card p-3 h-100 border-warning" style="border-width: 2px !important;">
        <div class="d-flex align-items-start">
          <div class="r-icon me-3" style="background:#fff3cd;color:#856404"><i class="bi bi-bug fs-4"></i></div>
          <div class="flex-grow-1">
            <div class="d-flex align-items-center">
              <div class="r-title">Reversal Diagnostic</div>
              <span class="badge badge-soon ms-2">Tool</span>
            </div>
            <div class="r-desc">Find and fix duplicate reversal issues. Void extra reversals automatically.</div>
          </div>
        </div>
        <div class="mt-3 d-flex gap-2">
          <a class="btn btn-warning" href="diagnose_reversals.php"><i class="bi bi-bug"></i> Open Tool</a>
        </div>
      </div>
    </div>

    <!-- Bank Transfer Tool -->
    <div class="col-xl-4 col-lg-6">
      <div class="report-card p-3 h-100 border-info" style="border-width: 2px !important;">
        <div class="d-flex align-items-start">
          <div class="r-icon me-3" style="background:#d1ecf1;color:#0c5460"><i class="bi bi-arrow-left-right fs-4"></i></div>
          <div class="flex-grow-1">
            <div class="d-flex align-items-center">
              <div class="r-title">Bank Transfer</div>
              <span class="badge badge-soon ms-2">Tool</span>
            </div>
            <div class="r-desc">Transfer funds from Secondary Bank (1030) to Main Bank (1020). One-time adjustment tool.</div>
          </div>
        </div>
        <div class="mt-3 d-flex gap-2">
          <a class="btn btn-info" href="bank_transfer.php"><i class="bi bi-arrow-left-right"></i> Open Tool</a>
        </div>
      </div>
    </div>

    <!-- Duplicate Invoice Journals Fix Tool -->
    <div class="col-xl-4 col-lg-6">
      <div class="report-card p-3 h-100 border-danger" style="border-width: 2px !important;">
        <div class="d-flex align-items-start">
          <div class="r-icon me-3" style="background:#f8d7da;color:#721c24"><i class="bi bi-exclamation-triangle fs-4"></i></div>
          <div class="flex-grow-1">
            <div class="d-flex align-items-center">
              <div class="r-title">Fix Duplicate Journals</div>
              <span class="badge badge-soon ms-2">Tool</span>
            </div>
            <div class="r-desc">Find and fix duplicate invoice journals. Handles orphaned journals from deleted invoices and orphaned reversals without original invoices.</div>
          </div>
        </div>
        <div class="mt-3 d-flex gap-2">
          <a class="btn btn-danger" href="fix_duplicate_invoice_journals.php"><i class="bi bi-tools"></i> Open Tool</a>
        </div>
      </div>
    </div>

    <!-- Validate Trade Receivable Tool -->
    <div class="col-xl-4 col-lg-6">
      <div class="report-card p-3 h-100 border-success" style="border-width: 2px !important;">
        <div class="d-flex align-items-start">
          <div class="r-icon me-3" style="background:#d1e7dd;color:#0f5132"><i class="bi bi-shield-check fs-4"></i></div>
          <div class="flex-grow-1">
            <div class="d-flex align-items-center">
              <div class="r-title">Validate Trade Receivable</div>
              <span class="badge badge-soon ms-2">Tool</span>
            </div>
            <div class="r-desc">Validates Trade Receivable (1110) entries. Ensures only valid invoice debits, receipt/reversal credits exist.</div>
          </div>
        </div>
        <div class="mt-3 d-flex gap-2">
          <a class="btn btn-success" href="validate_trade_receivable.php"><i class="bi bi-shield-check"></i> Open Tool</a>
        </div>
      </div>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
