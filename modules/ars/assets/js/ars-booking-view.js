/**
 * ARS Booking Details — workspace tabs, payments, deposits, activity.
 * Loaded mid-page so it does not depend on shell pageScripts stashing.
 */
(function (window, document) {
  'use strict';

  if (window.__arsBookingViewReady) return;
  window.__arsBookingViewReady = true;

  var HASH_BY_TAB = {
    overview: 'ws-overview',
    money: 'ws-money',
    deposit: 'ws-deposit',
    timeline: 'ws-activity',
    documents: 'ws-docs'
  };
  var TAB_BY_HASH = {
    '#ws-overview': 'overview',
    '#ws-money': 'money',
    '#ws-deposit': 'deposit',
    '#security-deposit': 'deposit',
    '#ws-activity': 'timeline',
    '#activity-center': 'timeline',
    '#ws-docs': 'documents',
    '#documents': 'documents'
  };

  var activityState = { filter: 'all', page: 1, loading: false, hasMore: false };

  function bookingId() {
    if (window.ARS_BOOKING_ID) return window.ARS_BOOKING_ID;
    var root = document.getElementById('ars-booking-view-root');
    if (root && root.getAttribute('data-booking-id')) {
      return parseInt(root.getAttribute('data-booking-id'), 10) || 0;
    }
    return 0;
  }

  function receiptAccountCatalog() {
    var root = document.getElementById('ars-booking-view-root');
    if (!root) return { cash: [], bank_transfer: [] };
    try {
      var raw = root.getAttribute('data-receipt-accounts') || '{}';
      var parsed = JSON.parse(raw);
      return {
        cash: parsed.cash || [],
        bank_transfer: parsed.bank_transfer || []
      };
    } catch (e) {
      return { cash: [], bank_transfer: [] };
    }
  }

  function receiptOptionsForMethod(method) {
    var cat = receiptAccountCatalog();
    var usesBank = method === 'bank_transfer' || method === 'card' || method === 'online';
    return usesBank ? cat.bank_transfer : cat.cash;
  }

  function fillReceiptAccountSelect(selectEl, method, preferredCode) {
    if (!selectEl) return;
    var opts = receiptOptionsForMethod(method);
    var prev = preferredCode || selectEl.value || '';
    selectEl.innerHTML = '';
    var blank = document.createElement('option');
    blank.value = '';
    blank.textContent = opts.length ? 'Select account…' : 'No accounts configured';
    selectEl.appendChild(blank);
    opts.forEach(function (row) {
      var o = document.createElement('option');
      o.value = row.account_code;
      o.textContent = row.account_code + ' — ' + row.account_name;
      selectEl.appendChild(o);
    });
    if (prev) {
      selectEl.value = prev;
    }
    if (!selectEl.value && opts.length === 1) {
      selectEl.value = opts[0].account_code;
    }
  }

  function bindReceiptAccountPickers(scope) {
    var root = scope || document;
    var methods = root.querySelectorAll('[data-ars-receipt-method]');
    methods.forEach(function (methodEl) {
      if (methodEl._arsReceiptBound) return;
      methodEl._arsReceiptBound = true;
      var form = methodEl.closest('.modal-body') || methodEl.closest('form') || methodEl.parentElement;
      var accountEl = form ? form.querySelector('[data-ars-receipt-account]') : null;
      if (!accountEl) return;
      var sync = function () {
        fillReceiptAccountSelect(accountEl, methodEl.value || 'cash');
      };
      methodEl.addEventListener('change', sync);
      sync();
    });
  }

  function csrfToken() {
    if (window.ARS_CSRF) return window.ARS_CSRF;
    var meta = document.querySelector('meta[name="ars-csrf"]');
    return meta && meta.content ? meta.content : '';
  }

  function showAlert(msg, type, modalAlertId) {
    type = type || 'danger';
    var icon =
      type === 'success'
        ? 'check-circle'
        : type === 'info'
          ? 'hourglass-split'
          : 'exclamation-triangle';
    var html =
      '<div class="alert alert-' +
      type +
      ' alert-dismissible fade show mb-3"><i class="bi bi-' +
      icon +
      ' me-2"></i>' +
      msg +
      '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
    var modalEl = modalAlertId ? document.getElementById(modalAlertId) : null;
    if (modalEl) {
      modalEl.classList.remove('d-none');
      modalEl.innerHTML = html;
      return;
    }
    var openModal = document.querySelector('.modal.show .modal-body');
    if (openModal && type === 'danger') {
      var wrap = openModal.querySelector('[data-ars-modal-alert]');
      if (!wrap) {
        wrap = document.createElement('div');
        wrap.setAttribute('data-ars-modal-alert', '1');
        openModal.insertBefore(wrap, openModal.firstChild);
      }
      wrap.innerHTML = html;
      return;
    }
    var el = document.getElementById('actionAlert');
    if (!el) {
      window.alert(msg);
      return;
    }
    el.innerHTML = html;
    try {
      el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    } catch (e) {}
  }

  function ajaxPost(action, extra) {
    var fd = new FormData();
    fd.append('action', action);
    fd.append('booking_id', String(bookingId()));
    fd.append('_csrf', csrfToken());
    if (extra) {
      Object.keys(extra).forEach(function (k) {
        fd.append(k, extra[k] == null ? '' : extra[k]);
      });
    }
    return fetch('ajax_booking_actions.php', {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-Token': csrfToken()
      }
    }).then(function (r) {
      return r.text().then(function (text) {
        var data = null;
        try {
          data = text ? JSON.parse(text) : null;
        } catch (e) {
          data = null;
        }
        if (!data && text) {
          var start = text.indexOf('{');
          var end = text.lastIndexOf('}');
          if (start >= 0 && end > start) {
            try {
              data = JSON.parse(text.slice(start, end + 1));
            } catch (e2) {
              data = null;
            }
          }
        }
        if (!data || typeof data !== 'object') {
          throw new Error(
            r.status === 419
              ? 'Security token expired — please refresh the page and try again.'
              : r.status === 302 || r.status === 401
                ? 'Session expired — please refresh and login again.'
                : 'Server error (' + r.status + '). Please refresh and try again.'
          );
        }
        if (r.status === 419) {
          data.success = false;
          data.error = data.error || 'Security token expired — please refresh the page and try again.';
        }
        if (!r.ok && data.success !== false) {
          data.success = false;
          data.error = data.error || 'Request failed (' + r.status + ')';
        }
        return data;
      });
    });
  }

  function selectWorkspaceTab(id, updateHash) {
    if (updateHash === undefined) updateHash = true;
    var selected = document.querySelector('[data-ars-ws-tab="' + id + '"]');
    if (!selected) return;

    document.querySelectorAll('[data-ars-ws-tab]').forEach(function (btn) {
      var active = btn === selected;
      btn.classList.toggle('is-active', active);
      btn.setAttribute('aria-selected', active ? 'true' : 'false');
    });
    document.querySelectorAll('.ars-ws-panel').forEach(function (panel) {
      var active = panel.getAttribute('data-ars-ws-panel') === id;
      panel.classList.toggle('is-active', active);
      panel.style.display = active ? 'block' : 'none';
    });
    if (updateHash && HASH_BY_TAB[id]) {
      try {
        history.replaceState(null, '', '#' + HASH_BY_TAB[id]);
      } catch (e) {}
    }
  }

  function openWorkspaceTab(id, targetId) {
    selectWorkspaceTab(id, true);
    if (targetId) {
      window.setTimeout(function () {
        var el = document.getElementById(targetId);
        if (el && el.scrollIntoView) {
          el.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
      }, 0);
    }
  }

  function plannedCheckOut() {
    var root = document.getElementById('ars-booking-view-root');
    return root && root.getAttribute('data-planned-check-out')
      ? root.getAttribute('data-planned-check-out')
      : '';
  }

  function checkoutBooking() {
    var planned = plannedCheckOut();
    var today = new Date();
    var yyyy = today.getFullYear();
    var mm = String(today.getMonth() + 1).padStart(2, '0');
    var dd = String(today.getDate()).padStart(2, '0');
    var actual = yyyy + '-' + mm + '-' + dd;
    var early = planned !== '' && actual < planned;
    var msg;
    if (early) {
      msg =
        'Early check-out\n\n' +
        'Planned check-out: ' +
        planned +
        '\nActual check-out: ' +
        actual +
        '\n\n' +
        'No stay money will be refunded for unused nights.\n' +
        'Security deposit is handled separately on the Deposit tab.\n' +
        'Cleaning will be scheduled for ' +
        actual +
        '.\n\nContinue with check-out?';
    } else {
      msg = 'Check out this guest now?';
    }
    if (!window.confirm(msg)) return;
    var btns = document.querySelectorAll('[data-ars-action="checkout"]');
    btns.forEach(function (b) {
      b.disabled = true;
    });
    ajaxPost('checkout')
      .then(function (d) {
        if (d.success) {
          var flash = d.is_early_checkout ? 'early_checkout' : 'checked_out';
          location.href = 'booking_view.php?id=' + bookingId() + '&flash=' + flash;
          return;
        }
        btns.forEach(function (b) {
          b.disabled = false;
        });
        showAlert(d.error || 'Check-out failed', 'danger');
      })
      .catch(function (err) {
        btns.forEach(function (b) {
          b.disabled = false;
        });
        showAlert(err && err.message ? err.message : 'Network error', 'danger');
      });
  }

  function bookingAction(action) {
    if (action === 'checkout') {
      checkoutBooking();
      return;
    }
    ajaxPost(action)
      .then(function (d) {
        if (d.success) location.reload();
        else showAlert(d.error || 'Action failed', 'danger');
      })
      .catch(function (err) {
        showAlert(err && err.message ? err.message : 'Network error', 'danger');
      });
  }

  function confirmBooking() {
    if (
      !window.confirm(
        'Confirm this booking?\n\nThis will:\n- Lock the stay dates\n- Post the stay revenue journal (AR / Revenue / VAT)\n\nContinue?'
      )
    ) {
      return;
    }
    var btns = document.querySelectorAll('[data-ars-action="confirm"]');
    btns.forEach(function (b) {
      b.disabled = true;
    });
    ajaxPost('confirm')
      .then(function (d) {
        if (d.success) {
          location.href = 'booking_view.php?id=' + bookingId() + '&flash=booking_confirmed';
          return;
        }
        btns.forEach(function (b) {
          b.disabled = false;
        });
        showAlert(d.error || 'Confirm failed', 'danger');
      })
      .catch(function (err) {
        btns.forEach(function (b) {
          b.disabled = false;
        });
        showAlert(err && err.message ? err.message : 'Network error', 'danger');
      });
  }

  function addCharge() {
    ajaxPost('add_charge', {
      charge_type: document.getElementById('chargeType').value,
      description: document.getElementById('chargeDesc').value,
      quantity: document.getElementById('chargeQty').value,
      unit_price: document.getElementById('chargePrice').value
    })
      .then(function (d) {
        if (d.success) location.reload();
        else showAlert(d.error || 'Failed', 'danger');
      })
      .catch(function (err) {
        showAlert(err && err.message ? err.message : 'Network error', 'danger');
      });
  }

  function markLinkPaid(paymentId) {
    if (!window.confirm('Mark this payment link as paid?')) return;
    ajaxPost('mark_link_paid', { payment_id: paymentId })
      .then(function (d) {
        if (d.success) location.reload();
        else showAlert(d.error || 'Failed', 'danger');
      })
      .catch(function () {
        showAlert('Network error', 'danger');
      });
  }

  function voidBooking(bookingNumber) {
    var reason = window.prompt(
      'Void ' + bookingNumber + ' as a wrong entry?\n\n' +
      '- All its payments are removed (journals reversed)\n' +
      '- Its invoices are voided (revenue journals reversed)\n' +
      '- The booking is marked cancelled; the guest is not notified\n\n' +
      'Reason (required):'
    );
    if (reason === null) return;
    reason = reason.trim();
    if (!reason) {
      showAlert('A reason is required to void a booking.', 'danger');
      return;
    }
    ajaxPost('void_booking', { reason: reason })
      .then(function (d) {
        if (d.success) {
          location.reload();
          return;
        }
        showAlert(d.error || 'Void failed', 'danger');
      })
      .catch(function (err) {
        showAlert(err && err.message ? err.message : 'Network error', 'danger');
      });
  }

  function deletePayment(paymentId, amountLabel) {
    var reason = window.prompt('Delete payment #' + paymentId + ' (AED ' + amountLabel + ')?\n\nIts journal is reversed and the amount goes back onto the invoices.\n\nReason (required):');
    if (reason === null) return;
    reason = reason.trim();
    if (!reason) {
      showAlert('A reason is required to delete a payment.', 'danger');
      return;
    }
    ajaxPost('delete_payment', { payment_id: paymentId, reason: reason })
      .then(function (d) {
        if (d.success) {
          location.reload();
          return;
        }
        showAlert(d.error || 'Delete failed', 'danger');
      })
      .catch(function () {
        showAlert('Network error', 'danger');
      });
  }

  function initEditPaymentModal() {
    var modalEl = document.getElementById('editPaymentModal');
    if (!modalEl) return;
    var busy = false;
    var val = function (id) { return (document.getElementById(id) || {}).value || ''; };
    var set = function (id, v) { var el = document.getElementById(id); if (el) el.value = v == null ? '' : v; };

    document.addEventListener('click', function (e) {
      var btn = e.target.closest ? e.target.closest('[data-ars-pay-edit]') : null;
      if (!btn) return;
      set('editPayId', btn.getAttribute('data-ars-pay-edit'));
      document.getElementById('editPayIdLabel').textContent = '#' + btn.getAttribute('data-ars-pay-edit');
      set('editPayAmount', btn.getAttribute('data-amount'));
      set('editPayMethod', btn.getAttribute('data-method'));
      fillReceiptAccountSelect(document.getElementById('editPayReceiptAccount'), btn.getAttribute('data-method'), btn.getAttribute('data-account'));
      set('editPayDate', btn.getAttribute('data-date'));
      set('editPayRef', btn.getAttribute('data-reference'));
      set('editPayNotes', btn.getAttribute('data-notes'));
      var alertEl = document.getElementById('editPayModalAlert');
      if (alertEl) { alertEl.classList.add('d-none'); alertEl.innerHTML = ''; }
      if (window.bootstrap && window.bootstrap.Modal) {
        window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
      }
    });

    var saveBtn = document.getElementById('editPaySaveBtn');
    if (!saveBtn) return;
    saveBtn.addEventListener('click', function () {
      if (busy) return;
      if (!(parseFloat(val('editPayAmount')) > 0)) {
        showAlert('Enter an amount greater than zero.', 'danger', 'editPayModalAlert');
        return;
      }
      if (!val('editPayReceiptAccount')) {
        showAlert('Select the RE cash or bank GL account.', 'danger', 'editPayModalAlert');
        return;
      }
      if (!val('editPayDate')) {
        showAlert('Choose a date.', 'danger', 'editPayModalAlert');
        return;
      }
      busy = true;
      saveBtn.disabled = true;
      ajaxPost('edit_payment', {
        payment_id: val('editPayId'),
        amount: val('editPayAmount'),
        payment_method: val('editPayMethod'),
        receipt_account_code: val('editPayReceiptAccount'),
        payment_date: val('editPayDate'),
        reference_number: val('editPayRef'),
        notes: val('editPayNotes')
      })
        .then(function (d) {
          if (d.success) {
            location.reload();
            return;
          }
          busy = false;
          saveBtn.disabled = false;
          showAlert(d.error || 'Save failed', 'danger', 'editPayModalAlert');
        })
        .catch(function (err) {
          busy = false;
          saveBtn.disabled = false;
          showAlert(err && err.message ? err.message : 'Network error', 'danger', 'editPayModalAlert');
        });
    });
  }

  function toggleLinkStatus() {
    var link = document.getElementById('payLink');
    var status = document.getElementById('payLinkStatus');
    if (!status) return;
    status.disabled = !(link && link.value && link.value.trim());
  }

  var paymentBusy = false;
  var paymentRecordArmed = false;
  var paymentArmTimer = null;

  function armPaymentRecordButton(armed) {
    paymentRecordArmed = !!armed;
    var btn = document.getElementById('payRecordBtn')
      || document.querySelector('#addPaymentModal [data-ars-action="record-payment"]');
    if (btn) {
      btn.disabled = !paymentRecordArmed || paymentBusy;
    }
  }

  function recordPayment() {
    if (paymentBusy) return;
    // Block ghost-clicks that land on Record while the modal is still opening.
    if (!paymentRecordArmed) {
      return;
    }
    var modal = document.getElementById('addPaymentModal');
    if (modal && !modal.classList.contains('show')) {
      return;
    }
    var amountEl = document.getElementById('payAmount');
    var amount = amountEl ? parseFloat(amountEl.value) : 0;
    if (!(amount > 0)) {
      showAlert('Enter a payment amount greater than zero.', 'danger', 'payModalAlert');
      return;
    }
    var method = (document.getElementById('payMethod') || {}).value || 'cash';
    var receiptAccount = (document.getElementById('payReceiptAccount') || {}).value || '';
    if (!receiptAccount) {
      showAlert('Select the RE cash or bank GL account.', 'danger', 'payModalAlert');
      return;
    }
    var payDate = (document.getElementById('payDate') || {}).value || '';
    if (
      !window.confirm(
        'Record stay payment of AED ' + amount.toFixed(2) + ' (' + method + ')'
          + ' to ' + receiptAccount
          + (payDate ? ' on ' + payDate : '')
          + '?\n\nThis posts a cash/bank journal on the Real Estate company and financially locks the booking.'
      )
    ) {
      return;
    }
    var btn = document.getElementById('payRecordBtn')
      || document.querySelector('[data-ars-action="record-payment"]');
    paymentBusy = true;
    if (btn) btn.disabled = true;
    ajaxPost('record_payment', {
      amount: amountEl.value,
      payment_method: method,
      receipt_account_code: receiptAccount,
      payment_date: payDate,
      reference_number: (document.getElementById('payRef') || {}).value || '',
      payment_link_url: (document.getElementById('payLink') || {}).value || '',
      payment_link_status: (document.getElementById('payLinkStatus') || {}).value || 'paid',
      notes: (document.getElementById('payNotes') || {}).value || ''
    })
      .then(function (d) {
        if (d.success) {
          location.href = 'booking_view.php?id=' + bookingId() + '&flash=payment_recorded#ws-money';
          return;
        }
        paymentBusy = false;
        armPaymentRecordButton(true);
        showAlert(d.error || 'Payment failed', 'danger', 'payModalAlert');
      })
      .catch(function (err) {
        paymentBusy = false;
        armPaymentRecordButton(true);
        showAlert(err && err.message ? err.message : 'Network error', 'danger', 'payModalAlert');
      });
  }

  function initPaymentModalGuards() {
    var modal = document.getElementById('addPaymentModal');
    if (!modal) return;

    modal.addEventListener('show.bs.modal', function () {
      paymentBusy = false;
      armPaymentRecordButton(false);
      if (paymentArmTimer) {
        window.clearTimeout(paymentArmTimer);
        paymentArmTimer = null;
      }
      var alertEl = document.getElementById('payModalAlert');
      if (alertEl) {
        alertEl.className = 'd-none';
        alertEl.textContent = '';
      }
    });

    modal.addEventListener('shown.bs.modal', function () {
      // Delay arming so the opening click / Enter cannot hit Record.
      paymentArmTimer = window.setTimeout(function () {
        paymentArmTimer = null;
        if (modal.classList.contains('show')) {
          armPaymentRecordButton(true);
        }
      }, 400);
    });

    modal.addEventListener('hidden.bs.modal', function () {
      if (paymentArmTimer) {
        window.clearTimeout(paymentArmTimer);
        paymentArmTimer = null;
      }
      paymentBusy = false;
      armPaymentRecordButton(false);
    });

    // Enter in amount/date fields must not silently post a payment.
    ['payAmount', 'payDate', 'payRef', 'payLink', 'payNotes'].forEach(function (id) {
      var el = document.getElementById(id);
      if (!el) return;
      el.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
          e.preventDefault();
          e.stopPropagation();
        }
      });
    });
  }

  function receiveDeposit() {
    var btn = document.querySelector('[data-ars-action="receive-deposit"]');
    var receiptAccount = (document.getElementById('depRecReceiptAccount') || {}).value || '';
    if (!receiptAccount) {
      showAlert('Select the RE cash or bank GL account.', 'danger', 'depRecModalAlert');
      return;
    }
    if (btn) btn.disabled = true;
    ajaxPost('receive_deposit', {
      amount: (document.getElementById('depRecAmount') || {}).value || '',
      method: (document.getElementById('depRecMethod') || {}).value || 'cash',
      date: (document.getElementById('depRecDate') || {}).value || '',
      receipt_account_code: receiptAccount
    })
      .then(function (d) {
        if (d.success) {
          location.href = 'booking_view.php?id=' + bookingId() + '&flash=deposit_received#ws-deposit';
          return;
        }
        if (btn) btn.disabled = false;
        showAlert(d.error || 'Deposit receive failed', 'danger', 'depRecModalAlert');
      })
      .catch(function (err) {
        if (btn) btn.disabled = false;
        showAlert(err && err.message ? err.message : 'Network error', 'danger', 'depRecModalAlert');
      });
  }

  function heldRemaining() {
    var el = document.getElementById('depRefAmount');
    var v = el ? parseFloat(el.getAttribute('data-held-remaining') || el.getAttribute('max') || '0') : 0;
    return isFinite(v) ? v : 0;
  }

  function money(n) {
    return 'AED ' + (Math.round(n * 100) / 100).toFixed(2);
  }

  function collectDeductions() {
    var rows = document.querySelectorAll('#depDeductionRows .dep-deduction-row');
    var out = [];
    rows.forEach(function (row) {
      var typeEl = row.querySelector('.dep-deduct-type');
      var amtEl = row.querySelector('.dep-deduct-amount');
      var noteEl = row.querySelector('.dep-deduct-note');
      var amount = parseFloat(amtEl ? amtEl.value : '0') || 0;
      if (amount <= 0) return;
      out.push({
        type: typeEl ? typeEl.value : 'other',
        amount: amount,
        note: noteEl ? noteEl.value.trim() : ''
      });
    });
    return out;
  }

  function deductionTotal() {
    return collectDeductions().reduce(function (s, d) {
      return s + (parseFloat(d.amount) || 0);
    }, 0);
  }

  var depRefundManual = false;

  function syncDepositSettleTotals(fromDeductionChange) {
    var held = heldRemaining();
    var deduct = deductionTotal();
    var amountEl = document.getElementById('depRefAmount');
    var refund = amountEl ? parseFloat(amountEl.value) || 0 : 0;
    if (fromDeductionChange && !depRefundManual) {
      refund = Math.max(0, Math.round((held - deduct) * 100) / 100);
      if (amountEl) amountEl.value = refund.toFixed(2);
    }
    var still = Math.max(0, Math.round((held - deduct - refund) * 100) / 100);
    var dLbl = document.getElementById('depDeductTotalLbl');
    var rLbl = document.getElementById('depRefundTotalLbl');
    var sLbl = document.getElementById('depStillHeldLbl');
    if (dLbl) dLbl.textContent = money(deduct);
    if (rLbl) rLbl.textContent = money(refund);
    if (sLbl) sLbl.textContent = money(still);
  }

  function addDeductionRow(preset) {
    var wrap = document.getElementById('depDeductionRows');
    if (!wrap) return;
    var row = document.createElement('div');
    row.className = 'dep-deduction-row row g-2 align-items-end';
    row.innerHTML =
      '<div class="col-md-3">' +
      '<label class="form-label small mb-0">Type</label>' +
      '<select class="form-select form-select-sm dep-deduct-type">' +
      '<option value="damage">Damage</option>' +
      '<option value="lost_item">Lost item</option>' +
      '<option value="other">Other</option>' +
      '</select></div>' +
      '<div class="col-md-3">' +
      '<label class="form-label small mb-0">Amount</label>' +
      '<input type="number" step="0.01" min="0.01" class="form-control form-control-sm dep-deduct-amount" placeholder="0.00">' +
      '</div>' +
      '<div class="col-md-5">' +
      '<label class="form-label small mb-0">Note</label>' +
      '<input type="text" class="form-control form-control-sm dep-deduct-note" placeholder="Required" maxlength="200">' +
      '</div>' +
      '<div class="col-md-1">' +
      '<button type="button" class="btn btn-sm btn-outline-danger dep-deduct-remove" title="Remove">&times;</button>' +
      '</div>';
    wrap.appendChild(row);
    if (preset) {
      if (preset.type) row.querySelector('.dep-deduct-type').value = preset.type;
      if (preset.amount) row.querySelector('.dep-deduct-amount').value = preset.amount;
      if (preset.note) row.querySelector('.dep-deduct-note').value = preset.note;
    }
    row.querySelector('.dep-deduct-remove').addEventListener('click', function () {
      row.remove();
      syncDepositSettleTotals(true);
    });
    row.querySelector('.dep-deduct-amount').addEventListener('input', function () {
      syncDepositSettleTotals(true);
    });
    row.querySelector('.dep-deduct-type').addEventListener('change', function () {
      syncDepositSettleTotals(true);
    });
    syncDepositSettleTotals(true);
  }

  function initDepositSettleModal() {
    var addBtn = document.getElementById('depAddDeductionBtn');
    if (addBtn && !addBtn._arsBound) {
      addBtn._arsBound = true;
      addBtn.addEventListener('click', function () {
        addDeductionRow();
      });
    }
    var amountEl = document.getElementById('depRefAmount');
    if (amountEl && !amountEl._arsBound) {
      amountEl._arsBound = true;
      amountEl.addEventListener('input', function () {
        depRefundManual = true;
        syncDepositSettleTotals(false);
      });
    }
    var modal = document.getElementById('depositRefundModal');
    if (modal && !modal._arsBound) {
      modal._arsBound = true;
      modal.addEventListener('show.bs.modal', function () {
        depRefundManual = false;
        var rows = document.getElementById('depDeductionRows');
        if (rows) rows.innerHTML = '';
        var held = heldRemaining();
        if (amountEl) amountEl.value = held.toFixed(2);
        syncDepositSettleTotals(true);
      });
    }
  }

  function settleDeposit() {
    var btn = document.querySelector('[data-ars-action="settle-deposit"]');
    var deductions = collectDeductions();
    for (var i = 0; i < deductions.length; i++) {
      if (!deductions[i].note) {
        showAlert('Each deduction requires a note.', 'danger', 'depSettleModalAlert');
        return;
      }
    }
    var refund = parseFloat((document.getElementById('depRefAmount') || {}).value || '0') || 0;
    var held = heldRemaining();
    var deduct = deductions.reduce(function (s, d) {
      return s + d.amount;
    }, 0);
    if (refund < 0 || deduct < 0) {
      showAlert('Amounts cannot be negative.', 'danger', 'depSettleModalAlert');
      return;
    }
    if (refund <= 0 && deduct <= 0) {
      showAlert('Enter a refund amount and/or at least one deduction.', 'danger', 'depSettleModalAlert');
      return;
    }
    if (refund + deduct > held + 0.009) {
      showAlert('Refund + deductions exceed held remaining (AED ' + held.toFixed(2) + ').', 'danger', 'depSettleModalAlert');
      return;
    }
    var receiptAccount = (document.getElementById('depRefReceiptAccount') || {}).value || '';
    if (refund > 0.009 && !receiptAccount) {
      showAlert('Select the RE cash or bank GL account for the refund.', 'danger', 'depSettleModalAlert');
      return;
    }
    if (btn) btn.disabled = true;
    ajaxPost('settle_deposit', {
      amount: refund.toFixed(2),
      method: (document.getElementById('depRefMethod') || {}).value || 'cash',
      date: (document.getElementById('depRefDate') || {}).value || '',
      receipt_account_code: receiptAccount,
      deductions: JSON.stringify(deductions)
    })
      .then(function (d) {
        if (d.success) {
          location.href = 'booking_view.php?id=' + bookingId() + '&flash=deposit_settled#ws-deposit';
          return;
        }
        if (btn) btn.disabled = false;
        showAlert(d.error || 'Settlement failed', 'danger', 'depSettleModalAlert');
      })
      .catch(function (err) {
        if (btn) btn.disabled = false;
        showAlert(err && err.message ? err.message : 'Network error', 'danger', 'depSettleModalAlert');
      });
  }

  /** @deprecated use settleDeposit — kept for any leftover onclick */
  function refundDeposit() {
    settleDeposit();
  }

  function saveSecurityDeposit() {
    var amountEl = document.getElementById('depositAmountInput');
    ajaxPost('set_security_deposit', { deposit_amount: amountEl ? amountEl.value : '' })
      .then(function (d) {
        if (d.success) location.reload();
        else showAlert(d.error || 'Failed', 'danger');
      })
      .catch(function () {
        showAlert('Network error', 'danger');
      });
  }

  function reviewLifecycleRequest(requestId, action) {
    if (action === 'reapply_lifecycle_request') {
      if (!window.confirm('Apply this approved request to the booking (dates, pricing, balance)?')) return;
      ajaxPost(action, { request_id: requestId })
        .then(function (d) {
          if (d.success) location.reload();
          else showAlert(d.error || 'Failed', 'danger');
        })
        .catch(function () {
          showAlert('Network error', 'danger');
        });
      return;
    }
    var note = window.prompt('Optional admin note to send to guest:') || '';
    ajaxPost(action, { request_id: requestId, admin_note: note })
      .then(function (d) {
        if (d.success) location.reload();
        else showAlert(d.error || 'Failed', 'danger');
      })
      .catch(function () {
        showAlert('Network error', 'danger');
      });
  }

  function escHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function activityAjax(action, extra) {
    var fd = new FormData();
    fd.append('action', action);
    fd.append('booking_id', String(bookingId()));
    fd.append('_csrf', csrfToken());
    if (extra) {
      Object.keys(extra).forEach(function (k) {
        fd.append(k, extra[k]);
      });
    }
    return fetch('ajax_activity_actions.php', {
      method: 'POST',
      body: fd,
      credentials: 'same-origin'
    }).then(function (r) {
      return r.json();
    });
  }

  function renderActivityItems(items, append) {
    var feed = document.getElementById('activityFeed');
    if (!feed) return;
    if (!append) feed.innerHTML = '';
    if (!items.length && !append) {
      feed.innerHTML =
        '<div class="ars-activity-empty text-center text-muted py-4"><i class="bi bi-inbox fs-3 d-block mb-2"></i>No activity for this filter yet.<br><small>Operational and financial events will appear here chronologically.</small></div>';
      return;
    }
    items.forEach(function (item) {
      var backfill = item.is_backfill
        ? '<span class="badge bg-light text-dark border ms-1">Historical</span>'
        : '';
      var status = item.status
        ? '<span class="badge bg-secondary ms-1">' + escHtml(item.status) + '</span>'
        : '';
      var looksLikeCode = function (v) {
        if (!v || typeof v !== 'string') return false;
        var t = v.trim();
        return t.charAt(0) === '{' || t.charAt(0) === '[';
      };
      var prevVal = looksLikeCode(item.previous_value) ? null : item.previous_value;
      var nextVal = looksLikeCode(item.new_value) ? null : item.new_value;
      // Stay-date corrections already have a full operator description — avoid a second change line.
      if (item.event_type === 'stay_dates_corrected' && item.description) {
        prevVal = null;
        nextVal = null;
      }
      var change =
        prevVal || nextVal
          ? '<div class="ars-activity-change small mt-1"><span class="text-muted">' +
            escHtml(prevVal || '—') +
            '</span> <i class="bi bi-arrow-right mx-1"></i> <strong>' +
            escHtml(nextVal || '—') +
            '</strong></div>'
          : '';
      var desc = item.description
        ? '<div class="small text-muted mt-1">' + escHtml(item.description) + '</div>'
        : '';
      var ref = item.related_document_number
        ? '<div class="small mt-1"><span class="text-muted">Ref:</span> <code>' +
          escHtml(item.related_document_number) +
          '</code></div>'
        : '';
      var link =
        item.deep_link && item.deep_link.url
          ? '<a class="btn btn-sm btn-ars-outline mt-2" href="' +
            escHtml(item.deep_link.url) +
            '">' +
            escHtml(item.deep_link.label || 'Open') +
            '</a>'
          : '';
      var el = document.createElement('div');
      el.className = 'ars-activity-item';
      el.innerHTML =
        '<div class="ars-activity-icon"><i class="bi ' +
        escHtml(item.icon || 'bi-activity') +
        '"></i></div>' +
        '<div class="ars-activity-body">' +
        '<div class="d-flex justify-content-between gap-2 flex-wrap">' +
        '<div class="fw-semibold">' +
        escHtml(item.title) +
        backfill +
        status +
        '</div>' +
        '<div class="small text-muted text-nowrap">' +
        escHtml(item.created_at) +
        '</div>' +
        '</div>' +
        '<div class="small text-muted">' +
        escHtml(item.created_by_name) +
        ' · ' +
        escHtml(item.event_category) +
        '</div>' +
        desc +
        change +
        ref +
        link +
        '</div>';
      feed.appendChild(el);
    });
  }

  function loadActivities(reset) {
    if (activityState.loading) return;
    if (reset) activityState.page = 1;
    activityState.loading = true;
    var feed = document.getElementById('activityFeed');
    if (!feed) {
      activityState.loading = false;
      return;
    }
    if (reset) {
      feed.innerHTML =
        '<div class="text-center text-muted py-4"><div class="spinner-border spinner-border-sm me-2"></div>Loading activity…</div>';
    }
    activityAjax('list', {
      filter: activityState.filter,
      page: String(activityState.page),
      per_page: '20'
    })
      .then(function (d) {
        activityState.loading = false;
        if (!d.success) {
          feed.innerHTML =
            '<div class="alert alert-danger mb-0">' + escHtml(d.error || 'Failed to load activity') + '</div>';
          return;
        }
        var badge = document.getElementById('activityTotalBadge');
        if (badge) {
          badge.textContent = d.total + ' event' + (d.total === 1 ? '' : 's');
        }
        renderActivityItems(d.items || [], !reset && activityState.page > 1);
        activityState.hasMore = !!d.has_more;
        var more = document.getElementById('activityLoadMore');
        if (more) more.classList.toggle('d-none', !activityState.hasMore);
      })
      .catch(function () {
        activityState.loading = false;
        feed.innerHTML = '<div class="alert alert-danger mb-0">Network error loading activity.</div>';
      });
  }

  function saveInternalNote() {
    var noteEl = document.getElementById('internalNoteText');
    var note = noteEl ? noteEl.value.trim() : '';
    if (!note) {
      showAlert('Note is required', 'danger');
      return;
    }
    activityAjax('add_internal_note', { note: note })
      .then(function (d) {
        if (d.success) location.reload();
        else showAlert(d.error || 'Failed', 'danger');
      })
      .catch(function () {
        showAlert('Network error', 'danger');
      });
  }

  var docsModalMode = 'print';
  var amendActiveTab = 'service';

  function docLabel(docType) {
    if (docType === 'tax_invoice') return 'Tax invoice';
    if (docType === 'payment_receipt') return 'Payment receipt';
    if (docType === 'booking_confirmation') return 'Booking confirmation';
    return docType || 'Document';
  }

  function loadDocumentsCatalog() {
    var list = document.getElementById('docsActionList');
    if (!list) return;
    list.innerHTML = '<div class="text-muted small py-3 text-center">Loading…</div>';
    ajaxPost('list_booking_documents', {})
      .then(function (d) {
        if (!d.success) {
          list.innerHTML = '<div class="alert alert-danger mb-0">' + (d.error || 'Failed to load') + '</div>';
          return;
        }
        var docs = d.documents || [];
        if (!docs.length) {
          list.innerHTML = '<div class="text-muted small py-3 text-center">No documents available yet.</div>';
          return;
        }
        list.innerHTML = '';
        docs.forEach(function (item) {
          var row = document.createElement('div');
          row.className = 'list-group-item d-flex justify-content-between align-items-center gap-2 flex-wrap';
          var left = document.createElement('div');
          left.innerHTML =
            '<div class="fw-semibold">' +
            String(item.title || docLabel(item.doc_type)).replace(/</g, '&lt;') +
            (item.subtitle
              ? ' <span class="text-muted fw-normal">— ' + String(item.subtitle).replace(/</g, '&lt;') + '</span>'
              : '') +
            '</div>' +
            (item.available
              ? '<small class="text-success">Ready</small>'
              : '<small class="text-muted">' + (item.unavailable_reason || 'Not available') + '</small>');
          var actions = document.createElement('div');
          actions.className = 'd-flex gap-1';
          if (item.available && item.staff_download_url) {
            var dl = document.createElement('a');
            dl.className = 'btn btn-sm btn-outline-secondary';
            dl.href = item.staff_download_url;
            dl.target = '_blank';
            dl.rel = 'noopener';
            dl.innerHTML = '<i class="bi bi-download"></i>';
            dl.title = 'Download PDF';
            actions.appendChild(dl);
          }
          if (docsModalMode === 'send' && item.available) {
            var sendBtn = document.createElement('button');
            sendBtn.type = 'button';
            sendBtn.className = 'btn btn-sm btn-ars';
            sendBtn.setAttribute('data-ars-send-doc', '1');
            sendBtn.innerHTML = '<i class="bi bi-envelope me-1"></i>Send';
            sendBtn.addEventListener('click', function () {
              sendBookingDocument(item.doc_type, item.payment_id || '', sendBtn);
            });
            actions.appendChild(sendBtn);
          }
          row.appendChild(left);
          row.appendChild(actions);
          list.appendChild(row);
        });
      })
      .catch(function () {
        list.innerHTML = '<div class="alert alert-danger mb-0">Network error</div>';
      });
  }

  var docsSendBusy = false;

  function setDocsSendBusy(busy, activeBtn) {
    docsSendBusy = !!busy;
    var modal = document.getElementById('documentsActionModal');
    var buttons = modal
      ? modal.querySelectorAll('[data-ars-send-doc]')
      : document.querySelectorAll('[data-ars-send-doc]');
    buttons.forEach(function (btn) {
      btn.disabled = docsSendBusy;
      if (!docsSendBusy) {
        btn.innerHTML = '<i class="bi bi-envelope me-1"></i>Send';
      }
    });
    if (docsSendBusy && activeBtn) {
      activeBtn.innerHTML =
        '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>Sending…';
    }
    var closeBtns = modal
      ? modal.querySelectorAll('[data-bs-dismiss="modal"]')
      : [];
    closeBtns.forEach(function (btn) {
      btn.disabled = docsSendBusy;
    });
  }

  function sendBookingDocument(docType, paymentId, triggerBtn) {
    if (docsSendBusy) {
      return;
    }
    // Send buttons now exist both in the modal and inline in the Documents tab.
    // Only route feedback into the modal's alert box when the modal is open,
    // otherwise it would land in a hidden container.
    var modalEl = document.getElementById('documentsActionModal');
    var inModal = !!(modalEl && modalEl.classList.contains('show'));
    var alertTarget = inModal ? 'docsActionAlert' : undefined;

    var root = document.getElementById('ars-booking-view-root');
    var email = root ? root.getAttribute('data-guest-email') || '' : '';
    if (!email) {
      showAlert('Guest has no email on file.', 'danger', alertTarget);
      return;
    }
    if (!window.confirm('Email ' + docLabel(docType) + ' to ' + email + '?')) return;

    var inlineHtml = null;
    if (!inModal && triggerBtn) {
      inlineHtml = triggerBtn.innerHTML;
      triggerBtn.disabled = true;
      triggerBtn.innerHTML =
        '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>';
    }
    var restoreInline = function () {
      if (inlineHtml !== null && triggerBtn) {
        triggerBtn.disabled = false;
        triggerBtn.innerHTML = inlineHtml;
      }
    };

    setDocsSendBusy(true, inModal ? triggerBtn || null : null);
    showAlert(
      'Sending ' +
        docLabel(docType) +
        ' to ' +
        email +
        '… This can take up to a minute. Please wait.',
      'info',
      alertTarget
    );

    ajaxPost('send_booking_document', {
      doc_type: docType,
      payment_id: paymentId || ''
    })
      .then(function (d) {
        setDocsSendBusy(false);
        restoreInline();
        if (d.success) {
          showAlert('Sent to ' + (d.sent_to || email), 'success', alertTarget);
        } else {
          showAlert(d.error || 'Send failed', 'danger', alertTarget);
        }
      })
      .catch(function (err) {
        setDocsSendBusy(false);
        restoreInline();
        showAlert(
          (err && err.message) ? err.message : 'Network error sending document.',
          'danger',
          alertTarget
        );
      });
  }

  function loadAttachments() {
    var list = document.getElementById('attachList');
    if (!list) return;
    list.innerHTML = '<div class="text-muted py-2">Loading…</div>';
    ajaxPost('list_attachments', {})
      .then(function (d) {
        if (!d.success) {
          list.innerHTML = '<div class="alert alert-danger mb-0">' + (d.error || 'Failed') + '</div>';
          return;
        }
        var rows = d.attachments || [];
        if (!rows.length) {
          list.innerHTML = '<div class="text-muted py-2">No attachments yet.</div>';
          return;
        }
        list.innerHTML = '';
        rows.forEach(function (a) {
          var item = document.createElement('a');
          item.className = 'list-group-item list-group-item-action d-flex justify-content-between';
          item.href = a.download_url || '#';
          item.target = '_blank';
          item.rel = 'noopener';
          item.innerHTML =
            '<span>' +
            String(a.original_name || 'file').replace(/</g, '&lt;') +
            ' <span class="badge bg-light text-dark border ms-1">' +
            docCategoryLabel(a.doc_category) +
            '</span></span><span class="text-muted">' +
            (a.created_at || '') +
            '</span>';
          list.appendChild(item);
        });
      })
      .catch(function () {
        list.innerHTML = '<div class="alert alert-danger mb-0">Network error</div>';
      });
  }

  function uploadAttachment() {
    var input = document.getElementById('attachFileInput');
    if (!input || !input.files || !input.files[0]) {
      showAlert('Choose a file first.', 'danger', 'attachModalAlert');
      return;
    }
    var catEl = document.getElementById('attachCategory');
    var fd = new FormData();
    fd.append('action', 'upload_attachment');
    fd.append('booking_id', String(bookingId()));
    fd.append('_csrf', csrfToken());
    fd.append('doc_category', catEl ? catEl.value : 'other');
    fd.append('file', input.files[0]);
    var btn = document.getElementById('attachUploadBtn');
    if (btn) btn.disabled = true;
    fetch('ajax_booking_actions.php', {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-Token': csrfToken()
      }
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (d) {
        if (btn) btn.disabled = false;
        if (d.success) {
          input.value = '';
          showAlert('Uploaded. Refreshing Documents…', 'success', 'attachModalAlert');
          // Reload so the unified Documents tab picks the new file up in its
          // category. The #ws-docs hash keeps the user on the same tab.
          window.setTimeout(function () {
            location.hash = 'ws-docs';
            location.reload();
          }, 600);
        } else {
          showAlert(d.error || 'Upload failed', 'danger', 'attachModalAlert');
        }
      })
      .catch(function () {
        if (btn) btn.disabled = false;
        showAlert('Network error', 'danger', 'attachModalAlert');
      });
  }

  function setAmendTab(tab) {
    amendActiveTab = tab || 'service';
    document.querySelectorAll('[data-ars-amend-pane]').forEach(function (btn) {
      btn.classList.toggle('active', btn.getAttribute('data-ars-amend-pane') === amendActiveTab);
    });
    document.querySelectorAll('[data-ars-amend-panel]').forEach(function (panel) {
      var match = panel.getAttribute('data-ars-amend-panel') === amendActiveTab;
      panel.classList.toggle('d-none', !match);
    });
    var alertEl = document.getElementById('amendModalAlert');
    if (alertEl) {
      alertEl.classList.add('d-none');
      alertEl.innerHTML = '';
    }
  }

  function submitAmendment() {
    var btn = document.getElementById('amendSubmitBtn');
    if (btn) btn.disabled = true;
    var payload = {};
    var action = '';
    if (amendActiveTab === 'service' || amendActiveTab === 'damage') {
      action = 'create_service_invoice';
      if (amendActiveTab === 'service') {
        payload = {
          line_type: 'service',
          description: (document.getElementById('amendSvcDesc') || {}).value || '',
          amount_net: (document.getElementById('amendSvcNet') || {}).value || '',
          quantity: (document.getElementById('amendSvcQty') || {}).value || '1'
        };
      } else {
        var applyDep = document.getElementById('amendDmgApplyDep');
        payload = {
          line_type: 'damage',
          description: (document.getElementById('amendDmgDesc') || {}).value || '',
          amount_net: (document.getElementById('amendDmgNet') || {}).value || '',
          apply_deposit: applyDep && applyDep.checked ? '1' : '',
          deposit_apply_amount: (document.getElementById('amendDmgDepAmt') || {}).value || ''
        };
      }
    } else if (amendActiveTab === 'extension') {
      action = 'create_extension_invoice';
      payload = { new_check_out: (document.getElementById('amendExtOut') || {}).value || '' };
    } else if (amendActiveTab === 'adjustment') {
      action = 'create_adjustment_invoice';
      payload = {
        description: (document.getElementById('amendAdjDesc') || {}).value || 'Price adjustment',
        amount_net: (document.getElementById('amendAdjNet') || {}).value || ''
      };
    } else {
      action = 'create_credit_note';
      var toCredit = document.getElementById('amendCnGuestCredit');
      payload = {
        description: (document.getElementById('amendCnDesc') || {}).value || 'Credit note',
        amount_net: (document.getElementById('amendCnNet') || {}).value || '',
        to_guest_credit: toCredit && toCredit.checked ? '1' : ''
      };
    }
    if (
      !window.confirm(
        'Post this amendment document now?\n\nThis creates a new Financial Adapter document and may post a journal on the Real Estate company. It will not rewrite the original invoice.'
      )
    ) {
      if (btn) btn.disabled = false;
      return;
    }
    ajaxPost(action, payload)
      .then(function (d) {
        if (btn) btn.disabled = false;
        if (d.success) {
          var extra = d.document_number ? ' (' + d.document_number + ')' : '';
          showAlert('Posted' + extra + '. Reloading…', 'success', 'amendModalAlert');
          setTimeout(function () {
            location.reload();
          }, 700);
        } else {
          showAlert(d.error || 'Failed', 'danger', 'amendModalAlert');
        }
      })
      .catch(function () {
        if (btn) btn.disabled = false;
        showAlert('Network error', 'danger', 'amendModalAlert');
      });
  }

  function initDocumentsModal() {
    var modal = document.getElementById('documentsActionModal');
    if (!modal) return;
    modal.addEventListener('show.bs.modal', function (ev) {
      setDocsSendBusy(false);
      var trigger = ev.relatedTarget;
      docsModalMode =
        (trigger && trigger.getAttribute('data-ars-docs-mode')) || 'print';
      var title = document.getElementById('documentsActionTitle');
      var hint = document.getElementById('docsActionHint');
      if (title) {
        title.innerHTML =
          docsModalMode === 'send'
            ? '<i class="bi bi-envelope-check me-2"></i>Send documents'
            : '<i class="bi bi-printer me-2"></i>Print / PDF';
      }
      if (hint) {
        hint.textContent =
          docsModalMode === 'send'
            ? 'Email generates the PDF and sends it to the guest address on file. Sending can take up to a minute — wait for the confirmation.'
            : 'Download PDF or print this booking page. No journals are created.';
      }
      var alertEl = document.getElementById('docsActionAlert');
      if (alertEl) {
        alertEl.classList.add('d-none');
        alertEl.innerHTML = '';
      }
      loadDocumentsCatalog();
    });
    modal.addEventListener('hide.bs.modal', function (ev) {
      if (docsSendBusy) {
        ev.preventDefault();
        showAlert(
          'Please wait — the email is still sending.',
          'info',
          'docsActionAlert'
        );
      }
    });
    var printBtn = document.getElementById('docsPrintPageBtn');
    if (printBtn && !printBtn._arsBound) {
      printBtn._arsBound = true;
      printBtn.addEventListener('click', function () {
        window.print();
      });
    }
  }

  var DOC_CATEGORY_LABELS = {
    contract: 'Short-Term Contract',
    payment_receipt: 'Payment Receipts',
    security_deposit: 'Security Deposit',
    other: 'Other'
  };

  function docCategoryLabel(key) {
    return DOC_CATEGORY_LABELS[key] || DOC_CATEGORY_LABELS.other;
  }

  // Send / re-file controls that live in the unified Documents tab (not a modal).
  function initUnifiedDocuments() {
    document.querySelectorAll('[data-ars-doc-send]').forEach(function (btn) {
      if (btn._arsBound) return;
      btn._arsBound = true;
      btn.addEventListener('click', function () {
        sendBookingDocument(
          btn.getAttribute('data-ars-doc-send'),
          btn.getAttribute('data-ars-doc-payment') || '',
          btn
        );
      });
    });

    document.querySelectorAll('.ars-udoc-refile').forEach(function (sel) {
      if (sel._arsBound) return;
      sel._arsBound = true;
      sel._arsPrev = sel.value;
      sel.addEventListener('change', function () {
        var id = sel.getAttribute('data-ars-attachment-id');
        var next = sel.value;
        sel.disabled = true;
        ajaxPost('set_attachment_category', {
          attachment_id: id,
          doc_category: next
        })
          .then(function (d) {
            if (d.success) {
              location.hash = 'ws-docs';
              location.reload();
            } else {
              sel.disabled = false;
              sel.value = sel._arsPrev;
              showAlert(d.error || 'Could not move the document.', 'danger');
            }
          })
          .catch(function () {
            sel.disabled = false;
            sel.value = sel._arsPrev;
            showAlert('Network error moving the document.', 'danger');
          });
      });
    });

    document.querySelectorAll('[data-ars-attachment-delete]').forEach(function (btn) {
      if (btn._arsBound) return;
      btn._arsBound = true;
      btn.addEventListener('click', function () {
        var name = btn.getAttribute('data-ars-attachment-name') || 'this file';
        if (!window.confirm('Delete "' + name + '"?\n\nThe file is removed from this booking and cannot be recovered.')) return;
        btn.disabled = true;
        ajaxPost('delete_attachment', {
          attachment_id: btn.getAttribute('data-ars-attachment-delete')
        })
          .then(function (d) {
            if (d.success) {
              location.hash = 'ws-docs';
              location.reload();
            } else {
              btn.disabled = false;
              showAlert(d.error || 'Could not delete the document.', 'danger');
            }
          })
          .catch(function () {
            btn.disabled = false;
            showAlert('Network error deleting the document.', 'danger');
          });
      });
    });
  }

  function initAttachmentModal() {
    var modal = document.getElementById('attachmentModal');
    if (!modal) return;
    modal.addEventListener('show.bs.modal', function (ev) {
      var alertEl = document.getElementById('attachModalAlert');
      if (alertEl) {
        alertEl.classList.add('d-none');
        alertEl.innerHTML = '';
      }
      // "Upload here" buttons preselect the category they sit under.
      var trigger = ev.relatedTarget;
      var preset = trigger && trigger.getAttribute('data-ars-doc-category');
      var catEl = document.getElementById('attachCategory');
      if (catEl && preset && DOC_CATEGORY_LABELS[preset]) {
        catEl.value = preset;
      }
      loadAttachments();
    });
    var up = document.getElementById('attachUploadBtn');
    if (up && !up._arsBound) {
      up._arsBound = true;
      up.addEventListener('click', uploadAttachment);
    }
  }

  function initAmendmentModal() {
    var modal = document.getElementById('amendmentModal');
    if (!modal) return;
    modal.addEventListener('show.bs.modal', function (ev) {
      var trigger = ev.relatedTarget;
      var tab =
        (trigger && trigger.getAttribute('data-ars-amend-tab')) || 'service';
      setAmendTab(tab);
      var root = document.getElementById('ars-booking-view-root');
      var co = root ? root.getAttribute('data-check-out') || '' : '';
      var ext = document.getElementById('amendExtOut');
      if (ext && co) {
        var d = new Date(co + 'T12:00:00');
        if (!isNaN(d.getTime())) {
          d.setDate(d.getDate() + 1);
          var y = d.getFullYear();
          var m = String(d.getMonth() + 1).padStart(2, '0');
          var day = String(d.getDate()).padStart(2, '0');
          ext.min = y + '-' + m + '-' + day;
          if (!ext.value || ext.value <= co) ext.value = ext.min;
        }
      }
    });
    document.querySelectorAll('[data-ars-amend-pane]').forEach(function (btn) {
      if (btn._arsBound) return;
      btn._arsBound = true;
      btn.addEventListener('click', function () {
        setAmendTab(btn.getAttribute('data-ars-amend-pane'));
      });
    });
    var depChk = document.getElementById('amendDmgApplyDep');
    if (depChk && !depChk._arsBound) {
      depChk._arsBound = true;
      depChk.addEventListener('change', function () {
        var wrap = document.getElementById('amendDmgDepWrap');
        if (wrap) wrap.classList.toggle('d-none', !depChk.checked);
      });
    }
    var submit = document.getElementById('amendSubmitBtn');
    if (submit && !submit._arsBound) {
      submit._arsBound = true;
      submit.addEventListener('click', submitAmendment);
    }
  }

  function onDocClick(e) {
    // Never intercept Bootstrap collapse / modal toggles or normal links.
    if (e.target && e.target.closest) {
      if (e.target.closest('[data-bs-toggle="collapse"], [data-bs-toggle="modal"], .ars-journal-lines-toggle')) {
        return;
      }
      var anchor = e.target.closest('a[href]');
      if (anchor && !anchor.hasAttribute('data-ars-ws-tab') && !anchor.hasAttribute('data-ars-action')) {
        return;
      }
    }

    var tab = e.target && e.target.closest ? e.target.closest('[data-ars-ws-tab]') : null;
    if (tab) {
      e.preventDefault();
      openWorkspaceTab(tab.getAttribute('data-ars-ws-tab'));
      return;
    }

    var actionBtn = e.target && e.target.closest ? e.target.closest('[data-ars-action]') : null;
    if (!actionBtn || actionBtn.disabled) return;
    var action = actionBtn.getAttribute('data-ars-action');
    if (action === 'confirm') {
      e.preventDefault();
      e.stopPropagation();
      confirmBooking();
    } else if (action === 'record-payment') {
      e.preventDefault();
      e.stopPropagation();
      if (!paymentRecordArmed || paymentBusy) return;
      recordPayment();
    } else if (action === 'receive-deposit') {
      e.preventDefault();
      e.stopPropagation();
      receiveDeposit();
    } else if (action === 'checkout') {
      e.preventDefault();
      e.stopPropagation();
      checkoutBooking();
    }
  }

  function initActivityFilters() {
    document.querySelectorAll('.ars-activity-filter').forEach(function (btn) {
      btn.addEventListener('click', function () {
        document.querySelectorAll('.ars-activity-filter').forEach(function (b) {
          b.classList.remove('active');
        });
        btn.classList.add('active');
        activityState.filter = btn.getAttribute('data-filter') || 'all';
        loadActivities(true);
      });
    });
    var more = document.getElementById('activityLoadMore');
    if (more) {
      more.addEventListener('click', function () {
        if (!activityState.hasMore) return;
        activityState.page += 1;
        loadActivities(false);
      });
    }
    loadActivities(true);
  }

  function moneyFmt(n) {
    var v = parseFloat(n);
    if (isNaN(v)) return '—';
    return v.toFixed(2);
  }

  function editStayShowAlert(msg, isError) {
    var el = document.getElementById('editStayDatesAlert');
    if (!el) return;
    el.className = 'alert alert-' + (isError ? 'danger' : 'success') + ' small';
    el.textContent = msg || '';
    el.classList.remove('d-none');
  }

  function editStayClearAlert() {
    var el = document.getElementById('editStayDatesAlert');
    if (!el) return;
    el.className = 'd-none';
    el.textContent = '';
  }

  function editStayDepositMode() {
    var checked = document.querySelector('input[name="editStayDepositMode"]:checked');
    return checked ? checked.value : 'keep';
  }

  function syncEditStayDepositUi() {
    var wrap = document.getElementById('editStayDepAmountWrap');
    if (!wrap) return;
    wrap.style.display = editStayDepositMode() === 'set' ? 'block' : 'none';
  }

  function resetEditStayPreview() {
    var box = document.getElementById('editStayPreviewBox');
    var applyBtn = document.getElementById('editStayApplyBtn');
    if (box) box.classList.add('d-none');
    if (applyBtn) applyBtn.disabled = true;
  }

  function previewStayDates() {
    editStayClearAlert();
    var checkIn = (document.getElementById('editStayCheckIn') || {}).value || '';
    var checkOut = (document.getElementById('editStayCheckOut') || {}).value || '';
    if (!checkIn || !checkOut) {
      editStayShowAlert('Select check-in and check-out dates.', true);
      return;
    }
    var btn = document.getElementById('editStayPreviewBtn');
    if (btn) btn.disabled = true;
    ajaxPost('preview_stay_dates', { check_in: checkIn, check_out: checkOut })
      .then(function (data) {
        if (!data.success) {
          resetEditStayPreview();
          editStayShowAlert(data.error || 'Preview failed.', true);
          return;
        }
        var p = data.preview || {};
        var old = p.old || {};
        var neu = p.new || {};
        var setText = function (id, val) {
          var el = document.getElementById(id);
          if (el) el.textContent = val;
        };
        setText('editStayOldNights', String(old.nights != null ? old.nights : '—'));
        setText('editStayNewNights', String(neu.nights != null ? neu.nights : '—'));
        setText('editStayOldTotal', moneyFmt(old.total_amount));
        setText('editStayNewTotal', moneyFmt(neu.total_amount));
        setText('editStayNewSubtotal', 'AED ' + moneyFmt(neu.subtotal));
        setText('editStayNewVat', 'AED ' + moneyFmt(neu.vat_amount));
        var box = document.getElementById('editStayPreviewBox');
        if (box) box.classList.remove('d-none');
        var applyBtn = document.getElementById('editStayApplyBtn');
        if (applyBtn) applyBtn.disabled = false;
      })
      .catch(function (err) {
        resetEditStayPreview();
        editStayShowAlert(err.message || 'Preview failed.', true);
      })
      .finally(function () {
        if (btn) btn.disabled = false;
      });
  }

  function applyStayDates() {
    editStayClearAlert();
    var checkIn = (document.getElementById('editStayCheckIn') || {}).value || '';
    var checkOut = (document.getElementById('editStayCheckOut') || {}).value || '';
    var mode = editStayDepositMode();
    var extra = {
      check_in: checkIn,
      check_out: checkOut,
      deposit_mode: mode
    };
    if (mode === 'set') {
      extra.deposit_amount = (document.getElementById('editStayDepAmount') || {}).value || '0';
    }
    var applyBtn = document.getElementById('editStayApplyBtn');
    if (applyBtn) applyBtn.disabled = true;
    ajaxPost('apply_stay_dates', extra)
      .then(function (data) {
        if (!data.success) {
          editStayShowAlert(data.error || 'Could not apply stay dates.', true);
          if (applyBtn) applyBtn.disabled = false;
          return;
        }
        var modalEl = document.getElementById('editStayDatesModal');
        if (modalEl && window.bootstrap && bootstrap.Modal) {
          var inst = bootstrap.Modal.getInstance(modalEl);
          if (inst) inst.hide();
        }
        var url = new URL(window.location.href);
        url.searchParams.set('flash', 'stay_dates_updated');
        window.location.href = url.toString();
      })
      .catch(function (err) {
        editStayShowAlert(err.message || 'Could not apply stay dates.', true);
        if (applyBtn) applyBtn.disabled = false;
      });
  }

  function initEditStayDatesModal() {
    var modal = document.getElementById('editStayDatesModal');
    if (!modal) return;
    var root = document.getElementById('ars-booking-view-root');
    document.querySelectorAll('input[name="editStayDepositMode"]').forEach(function (r) {
      r.addEventListener('change', syncEditStayDepositUi);
    });
    syncEditStayDepositUi();
    var previewBtn = document.getElementById('editStayPreviewBtn');
    if (previewBtn) previewBtn.addEventListener('click', previewStayDates);
    var applyBtn = document.getElementById('editStayApplyBtn');
    if (applyBtn) applyBtn.addEventListener('click', applyStayDates);
    ['editStayCheckIn', 'editStayCheckOut'].forEach(function (id) {
      var el = document.getElementById(id);
      if (el) el.addEventListener('change', resetEditStayPreview);
    });
    modal.addEventListener('show.bs.modal', function () {
      editStayClearAlert();
      resetEditStayPreview();
      if (root) {
        var ci = root.getAttribute('data-check-in') || '';
        var co = root.getAttribute('data-check-out') || '';
        var dep = root.getAttribute('data-deposit-amount') || '0';
        var ciEl = document.getElementById('editStayCheckIn');
        var coEl = document.getElementById('editStayCheckOut');
        var depEl = document.getElementById('editStayDepAmount');
        if (ciEl && ci) ciEl.value = ci;
        if (coEl && co) coEl.value = co;
        if (depEl) depEl.value = dep;
      }
      var keep = document.getElementById('editStayDepKeep');
      if (keep) keep.checked = true;
      syncEditStayDepositUi();
    });
  }

  function init() {
    document.querySelectorAll('.ars-ws-panel').forEach(function (panel) {
      if (!panel.classList.contains('is-active')) {
        panel.style.display = 'none';
      } else {
        panel.style.display = 'block';
      }
    });
    selectWorkspaceTab(TAB_BY_HASH[location.hash] || 'overview', false);
    initActivityFilters();
    initDepositSettleModal();
    initEditStayDatesModal();
    initPaymentModalGuards();
    initEditPaymentModal();
    initDocumentsModal();
    initAttachmentModal();
    initUnifiedDocuments();
    initAmendmentModal();
    bindReceiptAccountPickers(document);
  }

  // Public API for inline onclick handlers still used in the PHP markup
  window.selectWorkspaceTab = selectWorkspaceTab;
  window.openWorkspaceTab = openWorkspaceTab;
  window.bookingAction = bookingAction;
  window.checkoutBooking = checkoutBooking;
  window.confirmBooking = confirmBooking;
  window.addCharge = addCharge;
  window.markLinkPaid = markLinkPaid;
  window.deletePayment = deletePayment;
  window.voidBooking = voidBooking;
  window.toggleLinkStatus = toggleLinkStatus;
  window.recordPayment = recordPayment;
  window.receiveDeposit = receiveDeposit;
  window.refundDeposit = refundDeposit;
  window.settleDeposit = settleDeposit;
  window.saveSecurityDeposit = saveSecurityDeposit;
  window.reviewLifecycleRequest = reviewLifecycleRequest;
  window.saveInternalNote = saveInternalNote;
  window.showAlert = showAlert;

  // Capture phase so tabs/actions work even if other handlers stop bubbling
  document.addEventListener('click', onDocClick, true);

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})(window, document);
