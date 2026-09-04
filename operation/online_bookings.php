<?php
/**
 * Online Bookings Management Page
 * View, filter, confirm, assign, and convert bookings to work orders
 */

require __DIR__.'/../includes/auth.php';
require __DIR__.'/../includes/db_connect.php';
require __DIR__.'/../includes/branding.php';

$brand = getBrandSettings($conn);
require_role(['Owner','Admin','HR'], $conn);

// User info
$U = $_SESSION['user'] ?? [];
$fullName = $U['full_name'] ?? $U['fullname'] ?? ($_SESSION['fullname'] ?? 'User');
$userName = $U['username']  ?? ($_SESSION['username'] ?? '');
$avatarInitial = strtoupper(substr(trim($fullName ?: $userName ?: 'U'), 0, 1));

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <title>Online Bookings | <?= h($brand['system_name']) ?></title>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <style>
    :root {
      --primary: <?= $brand['primary_color'] ?>;
      --primary-light: <?= $brand['primary_light'] ?>;
      --primary-dark: <?= $brand['primary_dark'] ?>;
      --accent: <?= $brand['accent_color'] ?>;
    }
    
    body{ background:#f6f7f9; }
    .page-header{ font-size:1.85rem; font-weight:700; color:var(--primary); margin-bottom:1.5rem; }
    .stat-card{ background:#fff; border-radius:12px; padding:1.25rem; box-shadow:0 2px 8px rgba(0,0,0,.05); }
    .stat-card .label{ font-size:0.85rem; color:#6c757d; text-transform:uppercase; }
    .stat-card .value{ font-size:1.75rem; font-weight:700; color:var(--primary); }
    .badge-status{ padding:0.35rem 0.75rem; border-radius:20px; font-size:0.75rem; font-weight:600; }
    .badge-pending{ background:#fff3cd; color:#856404; }
    .badge-confirmed{ background:#d1ecf1; color:#0c5460; }
    .badge-assigned{ background:#d4edda; color:#155724; }
    .badge-completed{ background:#e2e3e5; color:#383d41; }
    .badge-cancelled{ background:#f8d7da; color:#721c24; }
  </style>
</head>
<body<?= $brand['dark_mode_enabled'] ? ' class="dark-mode"' : '' ?>>
<nav class="navbar navbar-expand navbar-light bg-white shadow-sm mb-4">
  <div class="container-fluid">
    <a href="../index" class="navbar-brand fw-bold" style="color:var(--primary)"><?= h($brand['system_name']) ?></a>
    <div class="dropdown ms-auto">
      <a href="#" class="d-flex align-items-center text-dark text-decoration-none dropdown-toggle" data-bs-toggle="dropdown">
        <div class="avatar me-2" style="width:36px;height:36px;border-radius:50%;background:#eee;display:grid;place-items:center;font-weight:700;color:var(--primary);">
          <?= h($avatarInitial) ?>
        </div>
        <span class="me-2"><?= h($fullName) ?></span>
      </a>
      <ul class="dropdown-menu dropdown-menu-end shadow">
        <li><span class="dropdown-item-text"><strong><?= h($userName) ?></strong></span></li>
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item text-danger" href="../logout.php"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
      </ul>
    </div>
  </div>
</nav>

<div class="container-fluid px-4">
  <div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="page-header">Online Bookings</h1>
    <a href="../operation.php" class="btn btn-outline-secondary">
      <i class="bi bi-arrow-left me-2"></i>Back to Operations
    </a>
  </div>

  <!-- Stats Cards -->
  <div class="row g-3 mb-4" id="statsCards">
    <div class="col-md-3">
      <div class="stat-card">
        <div class="label">Pending</div>
        <div class="value" id="stat-pending">-</div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="stat-card">
        <div class="label">Confirmed</div>
        <div class="value" id="stat-confirmed">-</div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="stat-card">
        <div class="label">Today</div>
        <div class="value" id="stat-today">-</div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="stat-card">
        <div class="label">This Week</div>
        <div class="value" id="stat-week">-</div>
      </div>
    </div>
  </div>

  <!-- Filters -->
  <div class="card mb-4">
    <div class="card-body">
      <form id="filterForm" class="row g-3">
        <div class="col-md-3">
          <label class="form-label">Status</label>
          <select class="form-select" name="status" id="filterStatus">
            <option value="">All Statuses</option>
            <option value="pending">Pending</option>
            <option value="confirmed">Confirmed</option>
            <option value="assigned">Assigned</option>
            <option value="completed">Completed</option>
            <option value="cancelled">Cancelled</option>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Date From</label>
          <input type="date" class="form-control" name="date_from" id="filterDateFrom">
        </div>
        <div class="col-md-3">
          <label class="form-label">Date To</label>
          <input type="date" class="form-control" name="date_to" id="filterDateTo">
        </div>
        <div class="col-md-3">
          <label class="form-label">&nbsp;</label>
          <button type="submit" class="btn btn-primary w-100">
            <i class="bi bi-funnel me-2"></i>Filter
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- Bookings Table -->
  <div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
      <h5 class="mb-0">Bookings List</h5>
      <button class="btn btn-sm btn-outline-primary" onclick="loadBookings()">
        <i class="bi bi-arrow-clockwise"></i> Refresh
      </button>
    </div>
    <div class="card-body">
      <div id="loadingSpinner" class="text-center py-5">
        <div class="spinner-border text-primary" role="status">
          <span class="visually-hidden">Loading...</span>
        </div>
      </div>
      <div id="bookingsTable" style="display:none;">
        <div class="table-responsive">
          <table class="table table-hover" id="bookingsTableContent">
            <thead>
              <tr>
                <th>ID</th>
                <th>Customer</th>
                <th>Service</th>
                <th>Date & Time</th>
                <th>Worker</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody id="bookingsBody">
            </tbody>
          </table>
        </div>
      </div>
      <div id="emptyState" style="display:none;" class="text-center py-5 text-muted">
        <i class="bi bi-inbox" style="font-size:3rem;"></i>
        <p class="mt-3">No bookings found</p>
      </div>
    </div>
  </div>
</div>

<!-- Booking Details Modal -->
<div class="modal fade" id="bookingModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Booking Details</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="bookingDetails">
        <div class="text-center py-5">
          <div class="spinner-border text-primary"></div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- Worker Assignment Modal -->
<div class="modal fade" id="assignWorkerModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Assign Worker(s)</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="assignWorkerLoading" class="text-center py-5">
          <div class="spinner-border text-primary"></div>
          <p class="mt-2">Loading booking details...</p>
        </div>
        <div id="assignWorkerContent" style="display:none;">
          <div class="mb-3">
            <label class="form-label"><strong>Booking Information</strong></label>
            <div class="card bg-light">
              <div class="card-body">
                <p class="mb-1"><strong>Customer:</strong> <span id="assignCustomerName"></span></p>
                <p class="mb-1"><strong>Service:</strong> <span id="assignServiceName"></span></p>
                <p class="mb-1"><strong>Date & Time:</strong> <span id="assignDateTime"></span></p>
                <p class="mb-0"><strong>Duration:</strong> <span id="assignDuration"></span> hour(s) | <strong>Workers Needed:</strong> <span id="assignWorkersNeeded"></span></p>
              </div>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label"><strong>Select Worker(s)</strong> <span class="text-muted">(Select <span id="workersNeededCount">1</span> worker(s))</span></label>
            <div id="workersList" class="list-group" style="max-height: 300px; overflow-y: auto;">
              <!-- Workers will be loaded here -->
            </div>
            <small class="text-muted">Select the worker(s) to assign to this booking. An order will be created in the Work Order system.</small>
          </div>
          <div class="mb-3">
            <label class="form-label">Driver (Optional)</label>
            <select class="form-select" id="assignDriverId">
              <option value="">No Driver</option>
              <!-- Drivers will be loaded here -->
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Hourly Rate (AED)</label>
            <input type="number" step="0.01" class="form-control" id="assignHourlyRate" placeholder="Auto-filled from client rate">
            <small class="text-muted">Leave empty to use client's default rate</small>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="confirmAssignBtn" onclick="confirmAssignWorker()" disabled>
          <i class="bi bi-check-circle me-2"></i>Assign & Create Order
        </button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Load statistics
async function loadStats() {
  try {
    const response = await fetch('ajax_online_bookings.php?action=stats');
    const data = await response.json();
    if (data.success) {
      document.getElementById('stat-pending').textContent = data.stats.pending;
      document.getElementById('stat-confirmed').textContent = data.stats.confirmed;
      document.getElementById('stat-today').textContent = data.stats.today;
      document.getElementById('stat-week').textContent = data.stats.week;
    }
  } catch (error) {
    console.error('Failed to load stats:', error);
  }
}

// Load bookings
async function loadBookings() {
  const form = document.getElementById('filterForm');
  const formData = new FormData(form);
  const params = new URLSearchParams(formData);
  params.append('action', 'list');

  document.getElementById('loadingSpinner').style.display = 'block';
  document.getElementById('bookingsTable').style.display = 'none';
  document.getElementById('emptyState').style.display = 'none';

  try {
    const response = await fetch('ajax_online_bookings.php?' + params);
    const data = await response.json();

    document.getElementById('loadingSpinner').style.display = 'none';

    if (data.success && data.bookings.length > 0) {
      renderBookings(data.bookings);
      document.getElementById('bookingsTable').style.display = 'block';
    } else {
      document.getElementById('emptyState').style.display = 'block';
    }
  } catch (error) {
    console.error('Failed to load bookings:', error);
    document.getElementById('loadingSpinner').style.display = 'none';
    alert('Failed to load bookings');
  }
}

// Render bookings table
function renderBookings(bookings) {
  const tbody = document.getElementById('bookingsBody');
  tbody.innerHTML = '';

  bookings.forEach(booking => {
    const row = document.createElement('tr');
    row.innerHTML = `
      <td>${booking.id}</td>
      <td>
        <strong>${escapeHtml(booking.customer_name)}</strong><br>
        <small class="text-muted">${escapeHtml(booking.customer_phone)}</small>
      </td>
      <td>${escapeHtml(booking.service_name)}</td>
      <td>${formatDateTime(booking.scheduled_date, booking.scheduled_time)}</td>
      <td>${booking.employee_name ? escapeHtml(booking.employee_name) : '<span class="text-muted">Unassigned</span>'}</td>
      <td><span class="badge-status badge-${booking.status}">${booking.status}</span></td>
      <td>
        <button class="btn btn-sm btn-outline-primary" onclick="viewBooking(${booking.id})">
          <i class="bi bi-eye"></i>
        </button>
        ${booking.status === 'pending' ? `
          <button class="btn btn-sm btn-success" onclick="confirmBooking(${booking.id})">
            <i class="bi bi-check"></i>
          </button>
        ` : ''}
        ${booking.status === 'confirmed' ? `
          <button class="btn btn-sm btn-primary" onclick="assignWorker(${booking.id})">
            <i class="bi bi-person-plus"></i>
          </button>
        ` : ''}
        ${['confirmed', 'assigned', 'in_progress'].includes(booking.status) ? `
          <div class="btn-group">
            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
              <i class="bi bi-arrow-repeat"></i> Status
            </button>
            <ul class="dropdown-menu">
              <li><a class="dropdown-item" href="#" onclick="updateBookingStatus(${booking.id}, 'confirmed'); return false;">Confirmed</a></li>
              <li><a class="dropdown-item" href="#" onclick="updateBookingStatus(${booking.id}, 'assigned'); return false;">Assigned</a></li>
              <li><a class="dropdown-item" href="#" onclick="updateBookingStatus(${booking.id}, 'in_progress'); return false;">In Progress</a></li>
              <li><a class="dropdown-item" href="#" onclick="updateBookingStatus(${booking.id}, 'completed'); return false;">Completed</a></li>
              <li><hr class="dropdown-divider"></li>
              <li><a class="dropdown-item text-danger" href="#" onclick="updateBookingStatus(${booking.id}, 'cancelled'); return false;">Cancel</a></li>
            </ul>
          </div>
        ` : ''}
      </td>
    `;
    tbody.appendChild(row);
  });
}

// View booking details
async function viewBooking(id) {
  const modal = new bootstrap.Modal(document.getElementById('bookingModal'));
  modal.show();

  try {
    const response = await fetch(`ajax_online_bookings.php?action=view&id=${id}`);
    const data = await response.json();

    if (data.success) {
      renderBookingDetails(data.booking);
    } else {
      document.getElementById('bookingDetails').innerHTML = '<p class="text-danger">Failed to load booking details</p>';
    }
  } catch (error) {
    console.error('Failed to view booking:', error);
  }
}

// Render booking details in modal
function renderBookingDetails(booking) {
  // Helper function for frequency labels
  const getFrequencyLabel = (freq) => {
    const labels = {
      'one_time': 'One Time Service',
      'weekly': 'Once a Week (10% Off)',
      'biweekly': 'Every Two Weeks (5% Off)',
      'multiple': 'Multiple Times a Week (25% Off)'
    };
    return labels[freq] || freq;
  };

  const items = Array.isArray(booking.items) ? booking.items : [];
  const hasItemSelections = items.length > 0;
  const totalItemQuantity = hasItemSelections
    ? items.reduce((sum, item) => {
        const qty = parseInt(item.quantity, 10);
        return sum + (Number.isNaN(qty) ? 0 : qty);
      }, 0)
    : 0;

  const assignedWorkers = Array.isArray(booking.assigned_workers) ? booking.assigned_workers : [];

  // Calculate discount label based on frequency
  const getDiscountLabel = () => {
    if (!booking.frequency || booking.frequency === 'one_time') {
      return 'Discount';
    }
    const freqLabels = {
      'weekly': 'Once a Week',
      'biweekly': 'Every Two Weeks',
      'multiple': 'Multiple Times a Week'
    };
    return `Discount (${freqLabels[booking.frequency] || booking.frequency})`;
  };
  const discountLabel = getDiscountLabel();

  const selectedItemsHtml = hasItemSelections
    ? `
          <div class="mt-3 p-3 bg-white border rounded shadow-sm">
            <div class="d-flex align-items-center text-primary fw-semibold mb-2">
              <i class="bi bi-list-check me-2"></i>Selected Items
            </div>
            ${items
              .map((item, index) => {
                const isLast = index === items.length - 1;
                const lineTotal = parseFloat(item.line_total ?? (item.unit_price || 0) * (item.quantity || 0)) || 0;
                return `
                  <div class="d-flex justify-content-between align-items-start ${isLast ? '' : 'border-bottom pb-2 mb-2'}">
                    <div>
                      <div class="fw-semibold">${escapeHtml(item.quantity)} × ${escapeHtml(item.item_name || item.name || '')}</div>
                      ${item.group_name ? `<small class="text-muted">${escapeHtml(item.group_name)}</small>` : ''}
                    </div>
                    <div class="fw-semibold text-primary">AED ${lineTotal.toFixed(2)}</div>
                  </div>
                `;
              })
              .join('')}
          </div>
        `
    : '';

  const html = `
    <div class="row g-3">
      <!-- Customer Information -->
      <div class="col-md-6">
        <div class="card border-0 bg-light">
          <div class="card-body">
            <h6 class="card-title text-primary"><i class="bi bi-person-circle"></i> Customer Information</h6>
            <p class="mb-1"><strong>Name:</strong> ${escapeHtml(booking.customer_name)}</p>
            <p class="mb-1"><strong>Phone:</strong> <a href="tel:${booking.customer_phone}">${escapeHtml(booking.customer_phone)}</a></p>
            <p class="mb-0"><strong>Email:</strong> <a href="mailto:${booking.customer_email}">${escapeHtml(booking.customer_email)}</a></p>
          </div>
        </div>
      </div>

      <!-- Service Details -->
      <div class="col-md-6">
        <div class="card border-0 bg-light">
          <div class="card-body">
            <h6 class="card-title text-primary"><i class="bi bi-clipboard-check"></i> Service Details</h6>
            <p class="mb-1"><strong>Service:</strong> ${escapeHtml(booking.service_name)}</p>
            ${hasItemSelections ? `<p class="mb-1"><strong>Total Items:</strong> ${totalItemQuantity}</p>` : ''}
            <p class="mb-1"><strong>Duration:</strong> ${booking.hours || 2} hour${booking.hours > 1 ? 's' : ''}</p>
            <p class="mb-1"><strong>Professionals:</strong> ${booking.professionals || 1} worker${booking.professionals > 1 ? 's' : ''}</p>
            <p class="mb-0"><strong>Materials:</strong> ${booking.materials_included == 1 ? '<span class="badge bg-success">Included</span>' : '<span class="badge bg-secondary">Customer Provides</span>'}</p>
            ${selectedItemsHtml}
          </div>
        </div>
      </div>

      <!-- Scheduling Information -->
      <div class="col-md-6">
        <div class="card border-0 bg-light">
          <div class="card-body">
            <h6 class="card-title text-primary"><i class="bi bi-calendar-event"></i> Scheduling</h6>
            ${booking.frequency === 'multiple' ? '' : `<p class="mb-1"><strong>Date:</strong> ${booking.scheduled_date}</p>`}
            ${booking.frequency === 'multiple' ? '' : `<p class="mb-1"><strong>Time:</strong> ${booking.scheduled_time}</p>`}
            <p class="mb-1"><strong>Frequency:</strong> ${getFrequencyLabel(booking.frequency || 'one_time')}</p>
            ${booking.weekly_schedule ? `
              <div class="mt-2 p-2 bg-info bg-opacity-10 border border-info rounded">
                <strong class="text-info d-block mb-2"><i class="bi bi-calendar-week"></i> Weekly Schedule:</strong>
                ${(() => {
                  try {
                    const schedule = typeof booking.weekly_schedule === 'string' 
                      ? JSON.parse(booking.weekly_schedule) 
                      : booking.weekly_schedule;
                    if (Array.isArray(schedule) && schedule.length > 0) {
                      return schedule.map(entry => `
                        <div class="d-flex justify-content-between align-items-center mb-1">
                          <span><strong>${entry.day}:</strong></span>
                          <span class="badge bg-primary">${entry.time}</span>
                        </div>
                      `).join('');
                    }
                  } catch(e) {
                    console.error('Error parsing weekly_schedule:', e);
                  }
                  return '<p class="mb-0 text-muted">Invalid schedule data</p>';
                })()}
              </div>
            ` : ''}
            <p class="mb-0"><strong>Status:</strong> <span class="badge-status badge-${booking.status}">${booking.status}</span></p>
          </div>
        </div>
      </div>

      <!-- Price Breakdown -->
      <div class="col-md-6">
        <div class="card border-0 bg-light">
          <div class="card-body">
            <h6 class="card-title text-primary"><i class="bi bi-cash-stack"></i> Price Breakdown</h6>
            <div class="d-flex justify-content-between mb-1">
              <span>Subtotal:</span>
              <span><strong>AED ${parseFloat(booking.subtotal || booking.total_price || 0).toFixed(2)}</strong></span>
            </div>
            ${(booking.promotional_discount_amount > 0) ? `
              <div class="d-flex justify-content-between mb-1 text-success">
                <span>${discountLabel}:</span>
                <span><strong>-AED ${parseFloat(booking.promotional_discount_amount).toFixed(2)}</strong></span>
              </div>
            ` : ''}
            ${(booking.coupon_discount_amount > 0 && booking.coupon_code) ? `
              <div class="d-flex justify-content-between mb-1 text-success">
                <span>Coupon Discount (${escapeHtml(booking.coupon_code)}):</span>
                <span><strong>-AED ${parseFloat(booking.coupon_discount_amount).toFixed(2)}</strong></span>
              </div>
            ` : ''}
            <div class="d-flex justify-content-between mb-1">
              <span>Service Fee:</span>
              <span><strong>AED ${parseFloat(booking.service_fee || 0).toFixed(2)}</strong></span>
            </div>
            ${(booking.vat > 0) ? `
              <div class="d-flex justify-content-between mb-1">
                <span>VAT:</span>
                <span><strong>AED ${parseFloat(booking.vat).toFixed(2)}</strong></span>
              </div>
            ` : ''}
            <hr class="my-2">
            <div class="d-flex justify-content-between">
              <span class="fw-bold">Total:</span>
              <span class="fw-bold text-primary fs-5">AED ${parseFloat(booking.total_price).toFixed(2)}</span>
            </div>
            ${booking.payment_method && booking.payment_method !== 'cash' ? `
              <hr class="my-2">
              <div class="mt-2 p-2 bg-light rounded">
                <small class="text-muted d-block mb-1"><strong>Payment Method:</strong></small>
                ${booking.payment_method === 'wallet' ? '<span class="badge bg-success">Paid by Wallet</span>' : ''}
                ${booking.payment_method === 'wallet_partial' ? '<span class="badge bg-primary">Wallet + Cash</span>' : ''}
                ${booking.wallet_amount_used > 0 ? `
                  <div class="mt-2">
                    <small class="text-success"><strong>Paid by Wallet:</strong> AED ${parseFloat(booking.wallet_amount_used).toFixed(2)}</small>
                  </div>
                ` : ''}
                ${booking.payment_method === 'wallet_partial' && booking.wallet_amount_used > 0 ? `
                  <div class="mt-1">
                    <small class="text-muted"><strong>Paid by Cash:</strong> AED ${(parseFloat(booking.total_price) - parseFloat(booking.wallet_amount_used)).toFixed(2)}</small>
                  </div>
                ` : ''}
              </div>
            ` : booking.payment_method === 'cash' || !booking.payment_method ? `
              <hr class="my-2">
              <div class="mt-2">
                <small class="text-muted"><strong>Payment Method:</strong> <span class="badge bg-secondary">Cash on Delivery</span></small>
              </div>
            ` : ''}
          </div>
        </div>
      </div>

      <!-- Address -->
      <div class="col-12">
        <div class="card border-0 bg-light">
          <div class="card-body">
            <h6 class="card-title text-primary"><i class="bi bi-geo-alt"></i> Service Address</h6>
            <p class="mb-0">${escapeHtml(booking.address || 'N/A')}</p>
            ${(() => {
              const hasCoordinates =
                booking.latitude !== null &&
                booking.latitude !== undefined &&
                booking.latitude !== '' &&
                booking.longitude !== null &&
                booking.longitude !== undefined &&
                booking.longitude !== '';
              const mapQuery = hasCoordinates
                ? encodeURIComponent(`${booking.latitude},${booking.longitude}`)
                : (booking.address ? encodeURIComponent(booking.address) : '');

              if (!mapQuery) {
                return '';
              }

              const embedUrl = hasCoordinates
                ? `https://maps.google.com/maps?q=${mapQuery}&z=16&output=embed`
                : `https://maps.google.com/maps?q=${mapQuery}&z=15&output=embed`;
              const linkUrl = `https://www.google.com/maps/search/?api=1&query=${mapQuery}`;

              return `
                <div class="ratio ratio-16x9 mt-3 rounded overflow-hidden border">
                  <iframe 
                    src="${embedUrl}"
                    allowfullscreen
                    loading="lazy"
                    referrerpolicy="no-referrer-when-downgrade"
                  ></iframe>
                </div>
                <div class="mt-3 d-flex flex-wrap gap-2">
                  <a class="btn btn-sm btn-outline-primary" href="${linkUrl}" target="_blank" rel="noopener">
                    <i class="bi bi-geo-alt-fill me-1"></i> Open in Google Maps
                  </a>
                  ${hasCoordinates ? `
                    <span class="badge bg-light text-dark">
                      Lat: ${parseFloat(booking.latitude).toFixed(5)}, Lng: ${parseFloat(booking.longitude).toFixed(5)}
                    </span>
                  ` : ''}
                </div>
              `;
            })()}
          </div>
        </div>
      </div>

      <!-- Special Instructions -->
      ${booking.instructions ? `
        <div class="col-12">
          <div class="card border-0 bg-warning bg-opacity-10 border-warning">
            <div class="card-body">
              <h6 class="card-title text-warning"><i class="bi bi-info-circle"></i> Special Instructions</h6>
              <p class="mb-0">${escapeHtml(booking.instructions)}</p>
            </div>
          </div>
        </div>
      ` : ''}

      <!-- Notes -->
      ${booking.notes ? `
        <div class="col-12">
          <div class="card border-0 bg-light">
            <div class="card-body">
              <h6 class="card-title text-primary"><i class="bi bi-sticky"></i> Internal Notes</h6>
              <p class="mb-0">${escapeHtml(booking.notes)}</p>
            </div>
          </div>
        </div>
      ` : ''}

      <!-- Worker Assignment -->
      ${
        assignedWorkers.length > 0
          ? `
        <div class="col-12">
          <div class="card border-0 bg-success bg-opacity-10 border-success">
            <div class="card-body">
              <h6 class="card-title text-success"><i class="bi bi-person-check"></i> Assigned Workers</h6>
              <ul class="mb-0 ps-3">
                ${assignedWorkers
                  .map(worker => `<li>${escapeHtml(worker.name)}</li>`)
                  .join('')}
              </ul>
            </div>
          </div>
        </div>
      `
          : booking.employee_name
              ? `
        <div class="col-12">
          <div class="card border-0 bg-success bg-opacity-10 border-success">
            <div class="card-body">
              <h6 class="card-title text-success"><i class="bi bi-person-check"></i> Assigned Worker</h6>
              <p class="mb-0">${escapeHtml(booking.employee_name)}</p>
            </div>
          </div>
        </div>
      `
              : ''
      }
    </div>
  `;
  document.getElementById('bookingDetails').innerHTML = html;
}

// Confirm booking
async function confirmBooking(id) {
  if (!confirm('Confirm this booking?')) return;

  try {
    const response = await fetch('ajax_online_bookings.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: `action=confirm&id=${id}`
    });
    const data = await response.json();

    if (data.success) {
      alert('Booking confirmed successfully');
      loadBookings();
      loadStats();
    } else {
      alert('Failed to confirm booking: ' + data.error);
    }
  } catch (error) {
    console.error('Failed to confirm booking:', error);
    alert('Failed to confirm booking');
  }
}

// Assign worker
let currentBookingId = null;
let currentBookingData = null;

async function assignWorker(id) {
  currentBookingId = id;
  const modal = new bootstrap.Modal(document.getElementById('assignWorkerModal'));
  modal.show();

  // Reset UI
  document.getElementById('assignWorkerLoading').style.display = 'block';
  document.getElementById('assignWorkerContent').style.display = 'none';
  document.getElementById('confirmAssignBtn').disabled = true;
  document.getElementById('workersList').innerHTML = '';
  document.getElementById('assignDriverId').innerHTML = '<option value="">No Driver</option>';

  try {
    // Load booking details
    const bookingResponse = await fetch(`ajax_online_bookings.php?action=view&id=${id}`);
    const bookingData = await bookingResponse.json();

    if (!bookingData.success) {
      alert('Failed to load booking details');
      modal.hide();
      return;
    }

    currentBookingData = bookingData.booking;

    // Load workers with availability for this booking date
    const workersResponse = await fetch(`ajax_online_bookings.php?action=get_workers&date=${currentBookingData.scheduled_date}&hours=${currentBookingData.hours || 2}`);
    const workersData = await workersResponse.json();

    // Load drivers
    const driversResponse = await fetch('ajax_online_bookings.php?action=get_drivers');
    const driversData = await driversResponse.json();

    // Populate booking info
    document.getElementById('assignCustomerName').textContent = currentBookingData.customer_name;
    document.getElementById('assignServiceName').textContent = currentBookingData.service_name || 'N/A';
    document.getElementById('assignDateTime').textContent = `${currentBookingData.scheduled_date} at ${currentBookingData.scheduled_time}`;
    document.getElementById('assignDuration').textContent = currentBookingData.hours || 2;
    const workersNeeded = currentBookingData.professionals || 1;
    document.getElementById('assignWorkersNeeded').textContent = workersNeeded;
    document.getElementById('workersNeededCount').textContent = workersNeeded;

    // Populate workers list with availability
    if (workersData.success && workersData.workers) {
      const workersList = document.getElementById('workersList');
      workersList.innerHTML = '';
      
      workersData.workers.forEach(worker => {
        const workerItem = document.createElement('div');
        workerItem.className = 'list-group-item';
        
        // Build availability info
        let availabilityHtml = '';
        if (worker.appointments && worker.appointments.length > 0) {
          availabilityHtml = `
            <div class="mt-2">
              <small class="text-muted d-block"><strong>Shift:</strong> ${worker.shift_start || '07:00'} - ${worker.shift_end || '19:00'}</small>
              <small class="text-warning d-block"><strong>Booked:</strong> ${worker.appointments.join(', ')}</small>
            </div>
          `;
        } else if (worker.shift_start) {
          availabilityHtml = `
            <div class="mt-2">
              <small class="text-success d-block"><strong>Available:</strong> ${worker.shift_start} - ${worker.shift_end || '19:00'}</small>
            </div>
          `;
        }
        
        workerItem.innerHTML = `
          <div class="form-check">
            <input class="form-check-input worker-checkbox" type="checkbox" 
                   value="${worker.id}" id="worker_${worker.id}" 
                   onchange="updateAssignButton()">
            <label class="form-check-label w-100" for="worker_${worker.id}">
              <div class="d-flex justify-content-between align-items-start">
                <div>
                  <strong>${escapeHtml(worker.name || worker.nickname || worker.full_name)}</strong>
                  ${worker.daily_cap_hours ? `<small class="text-muted ms-2">(${worker.daily_cap_hours}h/day capacity)</small>` : ''}
                </div>
                ${worker.appointments && worker.appointments.length > 0 ? 
                  '<span class="badge bg-warning text-dark">Busy</span>' : 
                  '<span class="badge bg-success">Available</span>'}
              </div>
              ${availabilityHtml}
            </label>
          </div>
        `;
        workersList.appendChild(workerItem);
      });
    }

    // Populate drivers
    if (driversData.success && driversData.drivers) {
      const driverSelect = document.getElementById('assignDriverId');
      driversData.drivers.forEach(driver => {
        const option = document.createElement('option');
        option.value = driver.id;
        option.textContent = driver.nickname || driver.name;
        driverSelect.appendChild(option);
      });
    }

    // Load service hourly rate (price_per_unit) - this is the primary source
    if (currentBookingData.service_id) {
      // Get service details to get price_per_unit
      const serviceResponse = await fetch(`ajax_online_bookings.php?action=get_service_rate&service_id=${currentBookingData.service_id}`);
      const serviceData = await serviceResponse.json();
      if (serviceData.success && serviceData.price_per_unit) {
        document.getElementById('assignHourlyRate').value = serviceData.price_per_unit;
      } else if (serviceData.success && serviceData.price) {
        // Fallback to service price if price_per_unit not available
        document.getElementById('assignHourlyRate').value = serviceData.price;
      }
    }
    
    // Fallback to client rate if service rate not available
    if (!document.getElementById('assignHourlyRate').value && currentBookingData.client_id) {
      const clientResponse = await fetch(`ajax_online_bookings.php?action=get_client_rate&client_id=${currentBookingData.client_id}`);
      const clientData = await clientResponse.json();
      if (clientData.success && clientData.rate) {
        document.getElementById('assignHourlyRate').value = clientData.rate;
      }
    }

    document.getElementById('assignWorkerLoading').style.display = 'none';
    document.getElementById('assignWorkerContent').style.display = 'block';
  } catch (error) {
    console.error('Failed to load assignment data:', error);
    alert('Failed to load assignment data');
    modal.hide();
  }
}

function updateAssignButton() {
  const checked = document.querySelectorAll('.worker-checkbox:checked');
  const workersNeeded = currentBookingData ? (currentBookingData.professionals || 1) : 1;
  document.getElementById('confirmAssignBtn').disabled = checked.length < workersNeeded;
}

// Update booking status
async function updateBookingStatus(id, newStatus) {
  if (!confirm(`Change booking status to "${newStatus}"?`)) {
    return;
  }

  try {
    const response = await fetch('ajax_online_bookings.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: `action=update_status&id=${id}&status=${newStatus}`
    });
    const data = await response.json();

    if (data.success) {
      alert('Status updated successfully');
      loadBookings();
      loadStats();
    } else {
      alert('Failed to update status: ' + (data.error || 'Unknown error'));
    }
  } catch (error) {
    console.error('Failed to update status:', error);
    alert('Failed to update status');
  }
}

async function confirmAssignWorker() {
  const checked = document.querySelectorAll('.worker-checkbox:checked');
  const workersNeeded = currentBookingData ? (currentBookingData.professionals || 1) : 1;
  
  if (checked.length < workersNeeded) {
    alert(`Please select at least ${workersNeeded} worker(s)`);
    return;
  }

  const workerIds = Array.from(checked).map(cb => parseInt(cb.value));
  const driverId = document.getElementById('assignDriverId').value || null;
  const hourlyRate = document.getElementById('assignHourlyRate').value || null;

  if (!confirm(`Assign ${workerIds.length} worker(s) to this booking and create order?`)) {
    return;
  }

  try {
    const response = await fetch('ajax_online_bookings.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: `action=assign&id=${currentBookingId}&worker_ids=${JSON.stringify(workerIds)}&driver_id=${driverId || ''}&hourly_rate=${hourlyRate || ''}`
    });
    const data = await response.json();

    if (data.success) {
      alert('Worker(s) assigned successfully! Order created in Work Order system.');
      bootstrap.Modal.getInstance(document.getElementById('assignWorkerModal')).hide();
      loadBookings();
      loadStats();
    } else {
      alert('Failed to assign worker: ' + (data.error || 'Unknown error'));
    }
  } catch (error) {
    console.error('Failed to assign worker:', error);
    alert('Failed to assign worker');
  }
}

// Helper functions
function escapeHtml(text) {
  const div = document.createElement('div');
  div.textContent = text;
  return div.innerHTML;
}

function formatDateTime(date, time) {
  const d = new Date(date + ' ' + time);
  return d.toLocaleString('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  });
}

// Initialize
document.getElementById('filterForm').addEventListener('submit', (e) => {
  e.preventDefault();
  loadBookings();
});

loadStats();
loadBookings();
</script>
</body>
</html>

