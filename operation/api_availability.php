<?php
// operation/api_availability.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

try {
  require_once __DIR__ . '/../includes/auth.php';
  require_once __DIR__ . '/../includes/db_connect.php';
  require_once __DIR__ . '/../includes/ar_helpers.php';
  require_once __DIR__ . '/../includes/gl_posting.php';
  require_once __DIR__ . '/../includes/notify.php';
  require_once __DIR__ . '/../includes/cleaning_order_cancellation_helper.php';
  require_once __DIR__ . '/../includes/cleaning_worker_absence_helper.php';
  require_once __DIR__ . '/../includes/work_order_financial_guard.php';
  require_once __DIR__ . '/../includes/work_order_adjustment_service.php';
  require_once __DIR__ . '/../includes/service_category_helper.php';
  require_once __DIR__ . '/../includes/work_order_pricing_service.php';
  cleaning_order_cancel_ensure_schema($conn);
  cleaning_worker_absence_ensure_schema($conn);

  // Mutating calls must be authenticated so audit_log / created_by capture the real actor.
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated. Please sign in again.']);
    exit;
  }

  /* ---------- helpers ---------- */
  function pad2(int $n): string { return str_pad((string)$n, 2, '0', STR_PAD_LEFT); }

  function createOvertimeEntry(PDO $conn, int $worker_id, string $service_date, string $start_time, string $end_time, float $overtime_hours, float $hourly_rate, int $order_id, int $user_id): ?array {
    // Find corresponding employee
    $empQ = $conn->prepare("
      SELECT e.id, e.full_name, e.employee_code
      FROM workers w
      LEFT JOIN employees e ON (
        (e.employee_code IS NOT NULL AND e.employee_code<>'' AND e.employee_code=w.emp_num)
        OR (e.nickname IS NOT NULL AND e.nickname<>'' AND e.nickname=w.nickname)
        OR (e.full_name IS NOT NULL AND e.full_name<>'' AND e.full_name=w.worker_name)
      )
      WHERE w.id = ?
    ");
    $empQ->execute([$worker_id]);
    $employee = $empQ->fetch(PDO::FETCH_ASSOC);
    
    if (!$employee || !$employee['id']) {
      return null; // No matching employee found
    }
    
    // Get default overtime rule
    $ruleQ = $conn->prepare("SELECT id FROM overtime_rules WHERE active=1 ORDER BY id LIMIT 1");
    $ruleQ->execute();
    $rule_id = $ruleQ->fetchColumn();
    
    // For overtime entries, we use manual_hours for the actual overtime amount
    // Set start/end times to NULL so the system uses manual_hours for calculations
    $overtime_start = null;
    $overtime_end = null;
    
    // Insert overtime entry
    $otIns = $conn->prepare("
      INSERT INTO overtime_entries
        (employee_id, ot_date, start_time, end_time, manual_hours, reason,
         status, approver_id, decided_at, attachment_path, notes,
         rule_id, pay_hours, pay_multiplier, pay_rate, pay_amount,
         created_at, updated_at, created_by, updated_by)
      VALUES
        (?, ?, ?, ?, ?, ?, 'pending', NULL, NULL, NULL, ?,
         ?, ?, ?, ?, ?, NOW(), NOW(), ?, ?)
    ");
    
    $reason = "Automatic overtime from Order #{$order_id} - Daily capacity exceeded";
    $notes = "Generated from order creation. Worker exceeded daily capacity by {$overtime_hours}h";
    $pay_rate = 10.00; // Fixed overtime rate as requested
    $pay_multiplier = 1.00; // Standard overtime multiplier
    $pay_amount = round($overtime_hours * $pay_rate * $pay_multiplier, 2);
    
    // Debug logging
    error_log("Creating overtime entry: start=NULL, end=NULL, manual_hours={$overtime_hours}, pay_hours={$overtime_hours}");
    
    $otIns->execute([
      $employee['id'],
      $service_date,
      $overtime_start,
      $overtime_end,
      $overtime_hours, // manual_hours
      $reason,
      $notes,
      $rule_id,
      $overtime_hours, // pay_hours
      $pay_multiplier,
      $pay_rate,
      $pay_amount,
      $user_id,
      $user_id
    ]);
    
    return [
      'employee' => $employee['full_name'],
      'hours' => $overtime_hours,
      'amount' => $pay_amount
    ];
  }

  function timeToLegacy(string $hhmm): string {
    if (!preg_match('/^(\d{1,2}):(\d{2})$/', $hhmm, $m)) return $hhmm;
    $h = (int)$m[1]; $mi = (int)$m[2];
    $dec = ($mi >= 30) ? '.30' : '.00';
    return (string)$h . $dec;
  }

  function hhmmToMin(string $t): int {
    // Accept "H:MM", "HH:MM" and optionally ":SS"
    if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', trim($t), $m)) {
      return ((int)$m[1]) * 60 + (int)$m[2];
    }
    return 0;
  }

  /**
   * Resolve billable rate for a work order.
   * Important: PDO often returns hourly_rate as "0.00" (truthy string) which breaks
   * `$mo['hourly_rate'] ?: $mo['fee_charged']` and zeros flat-fee ARS totals.
   *
   * @return array{rate:float,flat:bool}
   */
  function wo_resolve_order_rate(array $mo): array {
    $hourly = (float)($mo['hourly_rate'] ?? 0);
    $charged = (float)($mo['fee_charged'] ?? 0);
    $client = (float)($mo['client_rate'] ?? 0);
    if ($hourly > 0.00001) {
      return ['rate' => $hourly, 'flat' => false];
    }
    if ($charged > 0.00001) {
      // Flat-fee jobs (ARS checkout cleaning) store amount in fee_charged with hourly_rate=0.
      return ['rate' => $charged, 'flat' => true];
    }
    if ($client > 0.00001) {
      return ['rate' => $client, 'flat' => true];
    }
    return ['rate' => 0.0, 'flat' => false];
  }

  /**
   * Resolve VAT mode for a work order.
   * Prefer posted UI value, then stored vat_included.
   * Do NOT infer from total+vat≈grand — that identity holds for BOTH modes and
   * wrongly flips "fee includes VAT" into "add VAT" (e.g. AED 50 → 52.50).
   */
  function wo_resolve_vat_mode(array $mo, $posted = null): string {
    $candidates = [$posted, $mo['vat_included'] ?? null];
    foreach ($candidates as $raw) {
      if ($raw === null || $raw === '') {
        continue;
      }
      $v = strtolower(trim((string)$raw));
      if ($v === 'yes' || $v === '1') {
        return 'yes';
      }
      if ($v === 'no' || $v === '0') {
        return 'no';
      }
    }
    // Match create-order default when column is missing/blank.
    return 'yes';
  }
    
    // normalize "H:MM / HH:MM" into "HH:MM" (returns null if bad)
    function normHHMM(?string $t): ?string {
      if (!is_string($t)) return null;
      $t = trim($t);
      if (preg_match('/^(\d{1,2}):(\d{2})$/', $t, $m)) {
        $H = max(0, min(23, (int)$m[1]));
        $M = max(0, min(59, (int)$m[2]));
        return pad2($H).':'.pad2($M);
      }
      return null;
    }

  function mo_is_finalized_select(PDO $conn): string {
    return wo_column_exists($conn, 'is_finalized') ? 'mo.is_finalized AS is_finalized' : '0 AS is_finalized';
  }

  function parseLegacyRange(?string $txt): array {
    if (!$txt) return [null, null];
    $parts = preg_split('/\s*(?:to|\-|\–)\s*/i', trim($txt));
    if (!$parts || count($parts) < 2) return [null, null];
    $norm = function (string $p): ?string {
      $p = trim($p);
      if (preg_match('/^(\d{1,2}):(\d{1,2})$/', $p, $m)) {
        return pad2((int)$m[1]).':'.pad2((int)$m[2]);
      }
      if (preg_match('/^(\d{1,2})(?:\.(\d+))?$/', $p, $m)) {
        $H=(int)$m[1]; $dec=$m[2]??''; $M=($dec==='3'||$dec==='30'||$dec==='5'||$dec==='50')?30:0;
        return pad2(max(0,min(23,$H))).':'.pad2($M);
      }
      return null;
    };
    return [$norm($parts[0]), $norm($parts[1])];
  }

  // Return first overlap row (or false)
  function findOverlap(PDO $conn, int $workerId, string $svcDate, string $startHHMM, string $endHHMM, int $ignoreOrderId = 0) {
    $sql = "
      SELECT mo.id AS order_id, mo.start_time, mo.end_time, mo.status,
             COALESCE(mo.service_date, mo.`date`) AS svc_date,
             c.client_name
      FROM make_order mo
      JOIN order_workers ow ON ow.order_id = mo.id
      LEFT JOIN client c     ON c.id = mo.client_id
      WHERE ow.worker_id = :wid
        AND mo.svc_date_calc = :d
        AND COALESCE(mo.status,'') <> 'cancelled'
        ".($ignoreOrderId ? "AND mo.id <> :ignore" : "")."
        AND NOT (mo.end_time <= :s OR mo.start_time >= :e)
      ORDER BY mo.start_time
      LIMIT 1
    ";
    $st = $conn->prepare($sql);
    $params = [':wid'=>$workerId, ':d'=>$svcDate, ':s'=>$startHHMM, ':e'=>$endHHMM];
    if ($ignoreOrderId) $params[':ignore'] = $ignoreOrderId;
    $st->execute($params);
    return $st->fetch(PDO::FETCH_ASSOC);
  }

  /** GLOBAL setting for travel gap (minutes) */
  function getRequiredGapMinutes(PDO $conn, int $workerId): int {
    $st = $conn->prepare("
      SELECT CAST(`value` AS UNSIGNED)
      FROM settings
      WHERE `key`='min_gap_minutes'
      LIMIT 1
    ");
    $st->execute();
    return max(0, (int)($st->fetchColumn() ?: 0));
  }

  /** Return true if the booking [s,e] violates travel-gap vs the worker's adjacent jobs that day */
  function violatesTravelGap(PDO $conn, int $workerId, string $svcDate, string $startHH, string $endHH, int $ignoreOrderId=0): bool {
    $gapMin = getRequiredGapMinutes($conn, $workerId);
    if ($gapMin <= 0) return false;

    // fetch day rows for worker
    $st = $conn->prepare("
      SELECT mo.id,
             DATE_FORMAT(mo.start_time,'%H:%i') AS start_time,
             DATE_FORMAT(mo.end_time  ,'%H:%i') AS end_time
      FROM make_order mo
      JOIN order_workers ow ON ow.order_id = mo.id
      WHERE ow.worker_id = :wid
        AND COALESCE(mo.service_date, mo.`date`) = :d
        AND COALESCE(mo.status,'') <> 'cancelled'
        " . ($ignoreOrderId ? "AND mo.id <> :ignore " : "") . "
      ORDER BY mo.start_time
    ");
    $params = [':wid'=>$workerId, ':d'=>$svcDate];
    if ($ignoreOrderId) $params[':ignore']=$ignoreOrderId;
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $sMin = hhmmToMin($startHH);
    $eMin = hhmmToMin($endHH);

    foreach ($rows as $r) {
      $rs = hhmmToMin($r['start_time']);
      $re = hhmmToMin($r['end_time']);
      if ($re <= $sMin) { // after this job
        if ($sMin - $re < $gapMin) return true;
      } elseif ($rs >= $eMin) { // before next job
        if ($rs - $eMin < $gapMin) return true;
        break;
      }
    }
    return false;
  }

  /** Any unavailability overlap? */
  function hasUnavailability(PDO $conn, int $workerId, string $svcDate, string $startHH, string $endHH): bool {
    $st = $conn->prepare("
      SELECT 1
      FROM worker_unavailability wu
      WHERE wu.worker_id = :wid
        AND wu.`date` = :d
        AND NOT (wu.end_time <= :s OR wu.start_time >= :e)
      LIMIT 1
    ");
    $st->execute([':wid'=>$workerId, ':d'=>$svcDate, ':s'=>$startHH, ':e'=>$endHH]);
    return (bool)$st->fetchColumn();
  }
    
    /* ---------- CREDIT helpers (unified with ar_helpers.php) ---------- */
    /**
     * Returns:
     *  - credit_limit
     *  - outstanding   (sum of open invoice balances)
     *  - unapplied     (total unapplied receipts)
     *  - available     (credit_limit - outstanding + unapplied)
     *
     * If $excludeOrderId is provided, subtract that order's current effect
     * (its invoice, if any; else its grand_total) from outstanding. This avoids
     * double-counting when moving a Scheduled → Confirmed during Edit.
     */
    function credit_snapshot(PDO $conn, int $clientId, ?int $excludeOrderId = null): array {
      // 1) credit limit from client
      $st = $conn->prepare("SELECT COALESCE(credit_limit,0) FROM client WHERE id=?");
      $st->execute([$clientId]);
      $limit = (float)($st->fetchColumn() ?: 0);

      // 2) outstanding = sum of OPEN invoice balances
      $open = ar_client_open_invoices($conn, $clientId);   // from ar_helpers.php
      $outstanding = 0.0;
      foreach ($open as $r) {
        $outstanding += (float)$r['balance'];
      }

      // 3) unapplied = available credit from receipts
      $unapplied = ar_client_available_credit($conn, $clientId); // from ar_helpers.php

      // 4) optionally subtract the order we're editing (avoid double count)
      if ($excludeOrderId) {
        // If the order already has an invoice, subtract that invoice's current open balance.
        $q = $conn->prepare("
          SELECT i.id, (i.total - COALESCE(SUM(ra.amount_applied),0)) AS bal
          FROM invoices i
          LEFT JOIN receipt_allocations ra ON ra.invoice_id = i.id
          WHERE i.order_id = ?
          GROUP BY i.id
          LIMIT 1
        ");
        $q->execute([$excludeOrderId]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if ($row) {
          $outstanding = max(0.0, $outstanding - (float)$row['bal']);
        } else {
          // Fall back to order grand_total (un-invoiced yet)
          $q2 = $conn->prepare("SELECT COALESCE(grand_total,0) FROM make_order WHERE id=? AND client_id=?");
          $q2->execute([$excludeOrderId, $clientId]);
          $gt = (float)($q2->fetchColumn() ?: 0);
          $outstanding = max(0.0, $outstanding - $gt);
        }
      }

      $available = round($limit - $outstanding + $unapplied, 2);

      return [
        'credit_limit' => round($limit, 2),
        'outstanding'  => round($outstanding, 2),
        'unapplied'    => round($unapplied, 2),
        'available'    => $available,
      ];
    }

    /** true if the new charge would push available credit below zero */
    function credit_would_exceed_available(float $available, float $toAdd): bool {
      return ($available - $toAdd) < -0.0001;
    }

  /* ---------- lookups (GET action=...) ---------- */
  if (isset($_GET['action'])) {
    $action = $_GET['action'];

    if ($action === 'clients') {
      $rows = $conn->query("SELECT id, client_name, rate, terms, default_vat_rate, credit_limit FROM client ORDER BY client_name")->fetchAll(PDO::FETCH_ASSOC);
      echo json_encode($rows); exit;
    }
    
    // Get client preferences for Add Order modal
    if ($action === 'client_preferences') {
      $client_id = (int)($_GET['client_id'] ?? 0);
      if ($client_id <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid client ID']);
        exit;
      }
      
      $st = $conn->prepare("
        SELECT 
          preferred_workers,
          service_preferences,
          special_instructions
        FROM client
        WHERE id = ?
      ");
      $st->execute([$client_id]);
      $client = $st->fetch(PDO::FETCH_ASSOC);
      
      if (!$client) {
        echo json_encode(['success' => false, 'error' => 'Client not found']);
        exit;
      }
      
      // Parse JSON service_preferences
      $service_prefs = $client['service_preferences'] ? json_decode($client['service_preferences'], true) : [];
      
      $response = [
        'success' => true,
        'preferred_workers' => $client['preferred_workers'] ? explode(',', $client['preferred_workers']) : [],
        'special_instructions' => $client['special_instructions'] ?? '',
        'vat_mode' => $service_prefs['vat_mode'] ?? 'yes',
        'service_frequency' => $service_prefs['service_frequency'] ?? '',
        'access_instructions' => $service_prefs['access_instructions'] ?? '',
        'time_slots' => $service_prefs['time_slots'] ?? []
      ];
      
      echo json_encode($response);
      exit;
    }
    if ($action === 'workers') {
      try {
        $rows = $conn->query("
          SELECT w.id, w.nickname, w.daily_cap_hours 
          FROM workers w 
          LEFT JOIN employees e ON w.emp_num = e.employee_code 
          WHERE e.status IS NULL OR e.status != 'Inactive'
          ORDER BY w.nickname
        ")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($rows); exit;
      } catch (Exception $e) {
        error_log("Workers query failed: " . $e->getMessage());
        echo json_encode([]); exit;
      }
    }
    if ($action === 'drivers') {
      $rows = $conn->query("SELECT id, nickname FROM driver ORDER BY nickname")->fetchAll(PDO::FETCH_ASSOC);
      echo json_encode($rows); exit;
    }
    if ($action === 'companies') {
      $rows = $conn->query("SELECT name FROM comp_sa ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
      echo json_encode($rows); exit;
    }
    if ($action === 'services') {
      $catId = (int)($_GET['category_id'] ?? 0);
      $hasCatCol = sm_make_order_has_service_category($conn);
      $sql = "SELECT id, name";
      if ($hasCatCol) {
          $sql .= ", service_category_id";
      }
      $sql .= " FROM services WHERE is_active = 1";
      $params = [];
      if ($catId > 0 && $hasCatCol) {
          $sql .= " AND (service_category_id IS NULL OR service_category_id = ?)";
          $params[] = $catId;
      }
      $sql .= " ORDER BY name";
      $st = $params ? $conn->prepare($sql) : $conn->query($sql);
      if ($params) {
          $st->execute($params);
      }
      $rows = $st->fetchAll(PDO::FETCH_ASSOC);
      echo json_encode($rows); exit;
    }
    if ($action === 'service_categories') {
      echo json_encode(sm_categories_for_booking_api($conn)); exit;
    }
    // Shift templates
    if ($action === 'templates') {
      $rows = $conn->query("SELECT id, label, DATE_FORMAT(start_time,'%H:%i') AS start_time, DATE_FORMAT(end_time,'%H:%i') AS end_time FROM shift_templates WHERE is_active=1 ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
      echo json_encode($rows); exit;
    }
    
    if ($action === 'order_templates') {
        $rows = $conn->query("
          SELECT ot.*,
                 c.client_name,
                 d.nickname AS driver_name
          FROM order_templates ot
          LEFT JOIN client c ON c.id = ot.client_id
          LEFT JOIN driver d ON d.id = ot.driver_id
          ORDER BY ot.label
        ")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($rows); exit;
      }

    if ($action === 'order') {
      $id = (int)($_GET['id'] ?? 0);
      if ($id <= 0) throw new Exception('Missing order id');
      // Try to select vat_included if column exists, otherwise use NULL
      $hasVatIncluded = false;
      try {
        $chk = $conn->query("SHOW COLUMNS FROM make_order LIKE 'vat_included'");
        $hasVatIncluded = ($chk && $chk->rowCount() > 0);
      } catch (Exception $e) {
        $hasVatIncluded = false;
      }
      
      $vatIncludedField = $hasVatIncluded ? 'mo.vat_included' : 'NULL AS vat_included';
      $bookingCatField = sm_make_order_has_booking_categories($conn) ? 'mo.booking_categories' : 'NULL AS booking_categories';
      
      $st = $conn->prepare("
        SELECT
          mo.id, mo.client_id, c.client_name, c.rate AS client_rate, c.default_vat_rate,
          mo.status, mo.start_time, mo.end_time, mo.service_date, mo.`date` AS legacy_date,
          mo.fee_charged, mo.hourly_rate, mo.vat_rate, mo.vat_amount, mo.total, mo.grand_total, $vatIncludedField,
          mo.need_materials, mo.materials_note, mo.driver_id, mo.driver_name, mo.remark,
          mo.cancel_reason, mo.cancellation_category, mo.cancellation_details,
          mo.service_type_id, mo.service_category_id, $bookingCatField,
          mo.ars_booking_id,
          sc.name AS service_category_name, sc.code AS service_category_code, sc.icon AS service_category_icon
        FROM make_order mo
        LEFT JOIN client c ON c.id = mo.client_id
        LEFT JOIN sm_service_categories sc ON sc.id = mo.service_category_id
        WHERE mo.id = ?
      ");
      $st->execute([$id]);
      $order = $st->fetch(PDO::FETCH_ASSOC);
      if (!$order) throw new Exception('Order not found');

      $order['booking_category_ids'] = sm_resolve_booking_category_ids(
          $conn,
          $order['booking_categories'] ?? null,
          (int)($order['service_category_id'] ?? 0) ?: null
      );
      $order['category_labels'] = sm_order_category_labels(
          $conn,
          $order['booking_categories'] ?? null,
          (int)($order['service_category_id'] ?? 0) ?: null
      );

      $w = $conn->prepare("SELECT worker_id FROM order_workers WHERE order_id=? ORDER BY id");
      $w->execute([$id]);
      $order['worker_ids'] = array_map('intval', $w->fetchAll(PDO::FETCH_COLUMN));
      
      // Get order_services (for pricing details)
      $st_q = $conn->prepare("SELECT service_id, service_name, qty, unit, unit_price, vat_rate, line_total FROM order_services WHERE order_id=? ORDER BY id");
      $st_q->execute([$id]);
      $order['services'] = $st_q->fetchAll(PDO::FETCH_ASSOC);

      $fullOrder = wo_load_order($conn, $id) ?: $order;
      $financialLocked = wo_financial_is_locked($conn, $id);
      $isFinalized = wo_is_finalized($conn, $fullOrder);
      $opsLocked = wo_ops_is_locked($conn, $fullOrder);
      [$canDirectCancel, $cancelBlockReason] = wo_ops_can_direct_cancel($conn, $id);
      [$finalizeReady, $finalizeReason] = wo_can_finalize($conn, $fullOrder);
      $invoiceRow = sm_get_order_invoice($conn, $id);
      $pendingAdj = sm_pending_adjustment_for_order($conn, $id);
      $statusLower = strtolower((string)($fullOrder['status'] ?? ''));
      $opsBadge = wo_column_exists($conn, 'ops_status')
        ? strtoupper((string)($fullOrder['ops_status'] ?? wo_map_status_to_ops((string)$fullOrder['status'])))
        : strtoupper((string)$fullOrder['status']);

      $order['workflow'] = [
        'ops_status' => $opsBadge,
        'is_finalized' => $isFinalized,
        'ops_locked' => $opsLocked,
        'financially_locked' => $financialLocked,
        'can_direct_cancel' => $canDirectCancel,
        'ops_lock_reason' => $opsLocked ? wo_ops_lock_reason($conn, $fullOrder) : '',
        'financial_lock_reason' => (!$opsLocked && $financialLocked) ? wo_financial_lock_reason($conn, $id) : '',
        'cancel_block_reason' => $canDirectCancel ? '' : $cancelBlockReason,
        'can_finalize' => sm_user_can_finalize($conn) && !$isFinalized,
        'finalize_ready' => $finalizeReady,
        'finalize_reason' => $finalizeReason,
        'can_mark_complete' => sm_user_can_mark_complete($conn) && !$opsLocked
          && !in_array($statusLower, ['completed', 'invoiced', 'cancelled'], true),
        'can_request_adjustment' => sm_user_can_request_adjustment($conn) && ($financialLocked || $isFinalized) && !$pendingAdj,
        'can_approve_adjustment' => sm_user_can_approve_adjustment($conn) && (bool)$pendingAdj,
        'pending_adjustment' => $pendingAdj ? [
          'id' => (int)$pendingAdj['id'],
          'status' => (string)$pendingAdj['status'],
        ] : null,
        'invoice' => $invoiceRow ? [
          'id' => (int)$invoiceRow['id'],
          'invoice_no' => (string)($invoiceRow['invoice_no'] ?? ''),
          'status' => (string)($invoiceRow['status'] ?? ''),
        ] : null,
        'frozen_grand' => round((float)($fullOrder['frozen_grand_total'] ?? $fullOrder['grand_total'] ?? 0), 2),
        'defer_auto_invoice' => sm_defer_auto_invoice($conn),
      ];

      echo json_encode(['success'=>true,'order'=>$order]); exit;
    }
      
      if (($action ?? '') === 'order_notifications') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) throw new Exception('Missing order id');

        $st = $conn->prepare("
          SELECT id, channel, recipient, template_code, variables_json, status, error_text,
                 DATE_FORMAT(created_at,'%Y-%m-%d %H:%i') AS created_at,
                 DATE_FORMAT(sent_at   ,'%Y-%m-%d %H:%i') AS sent_at
          FROM outbox_messages
          WHERE related_order_id = ?
          ORDER BY COALESCE(sent_at, created_at) DESC
          LIMIT 100
        ");
        $st->execute([$id]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success'=>true,'items'=>$rows]); exit;
      }

    // Multi-day range for 3-day / week views
    if ($action === 'range') {
      $from = $_GET['from'] ?? '';
      $to   = $_GET['to']   ?? '';
      $startWin = isset($_GET['start']) && preg_match('/^\d{2}:\d{2}$/', $_GET['start']) ? $_GET['start'] : '07:00';
      $endWin   = isset($_GET['end'])   && preg_match('/^\d{2}:\d{2}$/', $_GET['end'])   ? $_GET['end']   : '22:00';

      if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        throw new Exception('Bad date range.');
      }
      if ($to < $from) throw new Exception('Range end before start.');

      $allWorkers = $conn->query("
        SELECT w.id, w.nickname, w.daily_cap_hours 
        FROM workers w 
        LEFT JOIN employees e ON w.emp_num = e.employee_code 
        WHERE e.status IS NULL OR e.status != 'Inactive'
        ORDER BY w.nickname
      ")->fetchAll(PDO::FETCH_ASSOC);

      // build day list inclusive
      $days = [];
      for ($d = new DateTime($from), $limit = new DateTime($to); $d <= $limit; $d->modify('+1 day')) {
        $days[] = $d->format('Y-m-d');
      }

      $outDays = [];
      foreach ($days as $day) {
        // events (clip to window)
        $st = $conn->prepare("
          SELECT
            mo.id AS order_id, ow.worker_id AS worker_id, mo.status AS status,
            " . mo_is_finalized_select($conn) . ",
            COALESCE(mo.service_date, mo.`date`) AS svc_date,
            mo.start_time, mo.end_time, mo.`time` AS time_text,
            c.client_name AS client_name,
            COALESCE(mo.driver_name, d.nickname) AS driver_name
          FROM make_order mo
          JOIN order_workers ow ON ow.order_id = mo.id
          LEFT JOIN client c ON c.id = mo.client_id
          LEFT JOIN driver d ON d.id = mo.driver_id
          WHERE mo.svc_date_calc = :d
            AND COALESCE(mo.status,'') <> 'cancelled'
        ");
        $st->execute([':d' => $day]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        // Get services for all orders in this day
        $order_ids = array_column($rows, 'order_id');
        $order_services_map = [];
        if (!empty($order_ids)) {
          $placeholders = implode(',', array_fill(0, count($order_ids), '?'));
          $svc_q = $conn->prepare("SELECT order_id, service_name, qty, unit FROM order_services WHERE order_id IN ($placeholders) ORDER BY order_id, id");
          $svc_q->execute($order_ids);
          while ($svc_row = $svc_q->fetch(PDO::FETCH_ASSOC)) {
            $oid = (int)$svc_row['order_id'];
            if (!isset($order_services_map[$oid])) {
              $order_services_map[$oid] = [];
            }
            $order_services_map[$oid][] = $svc_row;
          }
        }

        $events = [];
        $winStartDT = $day . ' ' . $startWin . ':00';
        $winEndDT   = $day . ' ' . $endWin   . ':00';
        foreach ($rows as $r) {
          $s = $r['start_time']; $e = $r['end_time'];
          if (!$s || !$e) { [$s2,$e2] = parseLegacyRange($r['time_text'] ?? ''); $s = $s ?: $s2; $e = $e ?: $e2; }
          if (!$s || !$e) continue;
          $startDT = $r['svc_date'] . ' ' . $s . ':00';
          $endDT   = $r['svc_date'] . ' ' . $e . ':00';
          if (!($endDT > $winStartDT && $startDT < $winEndDT)) continue;

          // Build services text if available
          $services = $order_services_map[$r['order_id']] ?? [];
          $services_text = '';
          if (!empty($services)) {
            $svc_items = [];
            foreach ($services as $svc) {
              $svc_items[] = $svc['service_name'] . ' (' . number_format((float)$svc['qty'], 1) . ' ' . $svc['unit'] . ')';
            }
            $services_text = ' • ' . implode(' + ', $svc_items);
          }

          $events[] = [
            'order_id'   => (int)$r['order_id'],
            'worker_id'  => (int)$r['worker_id'],
            'status'     => (string)$r['status'],
            'is_finalized' => (int)($r['is_finalized'] ?? 0) === 1,
            'start_time' => $s,
            'end_time'   => $e,
            'client'     => $r['client_name'] ?? null,
            'driver'     => $r['driver_name'] ?? null,
            'services'   => $services_text,
          ];
        }

        // headline totals
        $totalsStmt = $conn->prepare("
          SELECT
            COUNT(*) AS orders_count,
            SUM((TIMESTAMPDIFF(MINUTE, mo.start_time, mo.end_time) / 60) * GREATEST(1, owc.cnt)) AS hours_total,
            SUM(COALESCE(mo.total, 0))       AS subtotal_total,
            SUM(COALESCE(mo.vat_amount, 0))  AS vat_total,
            SUM(COALESCE(mo.grand_total, 0)) AS grand_total
          FROM make_order mo
          JOIN (SELECT order_id, COUNT(*) AS cnt FROM order_workers GROUP BY order_id) owc
            ON owc.order_id = mo.id
          WHERE mo.svc_date_calc = :d
            AND COALESCE(mo.status, '') <> 'cancelled'
            AND mo.start_time IS NOT NULL
            AND mo.end_time   IS NOT NULL
        ");
        $totalsStmt->execute([':d' => $day]);
        $head = $totalsStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $hours_total = (float)($head['hours_total'] ?? 0);
        $subtotal    = (float)($head['subtotal_total'] ?? 0);

        // time-off for that day (clip to window)
        $stU = $conn->prepare("
          SELECT id, worker_id, start_time, end_time, reason, absence_type, attendance_id
          FROM worker_unavailability
          WHERE `date` = :d
        ");
        $stU->execute([':d' => $day]);
        $unavail = [];
        foreach ($stU->fetchAll(PDO::FETCH_ASSOC) as $u) {
          if (!($u['end_time'] > $startWin && $u['start_time'] < $endWin)) continue;
          $unavail[] = [
            'id'         => (int)$u['id'],
            'worker_id'  => (int)$u['worker_id'],
            'start_time' => $u['start_time'],
            'end_time'   => $u['end_time'],
            'reason'     => $u['reason'] ?? null,
            'absence_type' => $u['absence_type'] ?? 'time_off',
            'attendance_id' => isset($u['attendance_id']) ? (int)$u['attendance_id'] : null,
          ];
        }

        $outDays[] = [
          'day'    => $day,
          'window' => ['start'=>$startWin,'end'=>$endWin],
          'events' => $events,
          'totals' => [
            'orders_count' => (int)($head['orders_count'] ?? 0),
            'hours_total'  => round($hours_total, 2),
            'money'        => [
              'subtotal'    => round($subtotal, 2),
              'vat'         => round((float)($head['vat_total'] ?? 0), 2),
              'grand_total' => round((float)($head['grand_total'] ?? 0), 2),
              'avg_hourly_rate' => $hours_total>0 ? round($subtotal/$hours_total, 2) : null,
            ]
          ],
          'unavailability' => $unavail
        ];
      }

      echo json_encode([
        'success' => true,
        'range'   => ['from'=>$from,'to'=>$to,'start'=>$startWin,'end'=>$endWin],
        'workers' => $allWorkers,
        'days'    => $outDays
      ]); exit;
    }
  }

  /* ---------- UNAVAILABILITY: CREATE ---------- */
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'unavail_create') {
    $worker_id  = (int)($_POST['worker_id'] ?? 0);
    $date       = $_POST['date']       ?? '';
    $start_time = $_POST['start_time'] ?? '';
    $end_time   = $_POST['end_time']   ?? '';
    $reason     = trim((string)($_POST['reason'] ?? ''));
    $absence_type = ($_POST['absence_type'] ?? 'time_off') === 'absent' ? 'absent' : 'time_off';

    if ($worker_id <= 0) throw new Exception('Worker is required.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new Exception('Bad date.');
    if (!preg_match('/^\d{2}:\d{2}$/', $start_time) || !preg_match('/^\d{2}:\d{2}$/', $end_time)) {
      throw new Exception('Bad time range.');
    }
    if ($end_time <= $start_time) throw new Exception('End must be after start.');
    if ($absence_type === 'absent' && $reason === '') throw new Exception('Reason is required when marking a cleaner absent.');

    // prevent overlaps with existing bookings for that worker
    $conf = findOverlap($conn, $worker_id, $date, $start_time, $end_time, 0);
    if ($conf) {
      throw new Exception("Worker already booked {$conf['start_time']}–{$conf['end_time']} (Order #{$conf['order_id']}).");
    }

    // prevent overlaps with existing time-off blocks
    $st = $conn->prepare("
      SELECT 1 FROM worker_unavailability
      WHERE worker_id = ? AND `date` = ? AND NOT (end_time <= ? OR start_time >= ?) LIMIT 1
    ");
    $st->execute([$worker_id, $date, $start_time, $end_time]);
    if ($st->fetchColumn()) throw new Exception('Overlaps existing time-off.');

    $attendance_id = null;
    if ($absence_type === 'absent') {
      $attendance_id = cleaning_worker_absence_sync_attendance($conn, $worker_id, $date, $reason, current_user_id());
    }

    $ins = $conn->prepare("
      INSERT INTO worker_unavailability (worker_id, `date`, start_time, end_time, reason, absence_type, attendance_id, created_at, created_by)
      VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?)
    ");
    $ins->execute([$worker_id, $date, $start_time, $end_time, $reason, $absence_type, $attendance_id, current_user_id()]);

    echo json_encode(['success'=>true, 'attendance_id'=>$attendance_id]); exit;
  }

  /* ---------- QUICK VALIDATE (pre-check before create) ---------- */
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'validate') {
    $service_date = $_POST['service_date'] ?? '';
    $start_time   = $_POST['start_time']   ?? '';
    $end_time     = $_POST['end_time']     ?? '';
    // PHP normalizes "worker_ids[]" to "worker_ids"
    $worker_ids   = isset($_POST['worker_ids']) ? array_values(array_filter(array_map('intval', (array)$_POST['worker_ids']))) : [];

    $errors = [];
    $warnings = [];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $service_date)) $errors[] = 'Bad date.';
    if (!preg_match('/^\d{2}:\d{2}$/', $start_time) || !preg_match('/^\d{2}:\d{2}$/', $end_time)) $errors[] = 'Bad time range.';
    if ($end_time <= $start_time) $errors[] = 'End must be after start.';
    if (!$worker_ids) $errors[] = 'Select at least one worker.';

    if ($errors) { echo json_encode(['ok'=>false,'errors'=>$errors]); exit; }

    // Check for overtime warnings (capacity exceeded)
    $mins = max(0, hhmmToMin($end_time) - hhmmToMin($start_time));
    $perCleaner = round($mins/60, 2);
    $capQ = $conn->prepare("SELECT COALESCE(daily_cap_hours,0) FROM workers WHERE id = ?");
    $sumQ = $conn->prepare("
      SELECT COALESCE(SUM(mo.hours / ow_count.cnt),0)
      FROM make_order mo
      JOIN order_workers ow ON ow.order_id = mo.id
      JOIN (SELECT order_id, COUNT(*) as cnt FROM order_workers GROUP BY order_id) ow_count ON ow_count.order_id = mo.id
      WHERE ow.worker_id = ?
        AND mo.svc_date_calc = ?
        AND COALESCE(mo.status,'') <> 'cancelled'
    ");
    
    foreach ($worker_ids as $wid) {
      $capQ->execute([$wid]);
      $cap = (float)$capQ->fetchColumn();
      if ($cap > 0) {
        $sumQ->execute([$wid, $service_date]);
        $already = (float)$sumQ->fetchColumn();
        if ($already + $perCleaner > $cap + 0.0001) {
          $overtime = $already + $perCleaner - $cap;
          $warnings[] = "Worker #{$wid}: daily capacity {$cap}h exceeded (would be ".number_format($already+$perCleaner,2)."h). Overtime: ".number_format($overtime,2)."h.";
        }
      }
    }

    foreach ($worker_ids as $wid) {
      if ($c = findOverlap($conn, $wid, $service_date, $start_time, $end_time, 0)) {
        $errors[] = "Worker #{$wid}: overlaps Order #{$c['order_id']} ({$c['start_time']}–{$c['end_time']}).";
        continue;
      }
      if (hasUnavailability($conn, $wid, $service_date, $start_time, $end_time)) {
        $errors[] = "Worker #{$wid}: time-off during {$start_time}–{$end_time}.";
        continue;
      }
      if (violatesTravelGap($conn, $wid, $service_date, $start_time, $end_time, 0)) {
        $gap = getRequiredGapMinutes($conn, $wid);
        $errors[] = "Worker #{$wid}: not enough travel buffer (needs {$gap} min).";
        continue;
      }
    }

    echo json_encode(['ok'=>empty($errors), 'errors'=>$errors, 'warnings'=>$warnings]); exit;
  }

  /* ---------- UNAVAILABILITY: DELETE ---------- */
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'unavail_delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) throw new Exception('Missing time-off id.');

    $st = $conn->prepare("SELECT * FROM worker_unavailability WHERE id = ? LIMIT 1");
    $st->execute([$id]);
    $unavailability = $st->fetch(PDO::FETCH_ASSOC);
    if (!$unavailability) throw new Exception('Time-off entry not found.');
    cleaning_worker_absence_delete_synced_attendance($conn, $unavailability);

    $del = $conn->prepare("DELETE FROM worker_unavailability WHERE id = ?");
    $del->execute([$id]);

    echo json_encode(['success'=>true]); exit;
  }
          
          
          /* ---------- REPEAT: PREVIEW ---------- */
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'repeat_preview') {
            $remark = isset($_POST['remark']) ? trim((string)$_POST['remark']) : '';
      // inputs
            $start_monday = $_POST['start_monday'] ?? '';   // any ISO date (used as base week)
            $weeks        = max(1, min(26, (int)($_POST['weeks'] ?? 4)));
            $dow          = array_values(array_filter(array_map('intval', (array)($_POST['dow'] ?? [])), fn($n)=>$n>=0 && $n<=6));
            $worker_ids   = isset($_POST['worker_ids']) ? array_values(array_filter(array_map('intval', (array)$_POST['worker_ids']))) : [];
            $shift_template_id = (int)($_POST['shift_template_id'] ?? 0);

            // optional direct time if no shift template
            $start_time_in = $_POST['start_time'] ?? '';
            $end_time_in   = $_POST['end_time']   ?? '';

            // optional high-level template
            $order_template_id = (int)($_POST['order_template_id'] ?? 0);

            // overrides
            $client_id     = (int)($_POST['client_id'] ?? 0);
            $hourly_rate   = isset($_POST['hourly_rate']) ? (float)$_POST['hourly_rate'] : null;
            $driver_id     = (int)($_POST['driver_id'] ?? 0);
            $status        = $_POST['status'] ?? null;                 // default later
            $vat_included  = $_POST['vat_included'] ?? null;           // 'yes'|'no'
            $exclude_dates = array_values(array_filter((array)($_POST['exclude_dates'] ?? []), fn($d)=>preg_match('/^\d{4}-\d{2}-\d{2}$/',$d)));

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_monday)) throw new Exception('Bad start date.');
            if (!$dow) throw new Exception('Pick at least one weekday.');
            if (!$worker_ids) throw new Exception('Select at least one worker.');

            // load order_template defaults
            if ($order_template_id > 0) {
              $st = $conn->prepare("SELECT * FROM order_templates WHERE id=?");
              $st->execute([$order_template_id]);
              if ($tpl = $st->fetch(PDO::FETCH_ASSOC)) {
                if (!$client_id)    $client_id    = (int)$tpl['client_id'];
                if ($hourly_rate===null && $tpl['hourly_rate']!==null) $hourly_rate=(float)$tpl['hourly_rate'];
                if (!$driver_id     && $tpl['driver_id'])              $driver_id   =(int)$tpl['driver_id'];
                if ($status===null) $status = (string)($tpl['status'] ?? 'scheduled');
                if ($vat_included===null) $vat_included = (string)($tpl['vat_included'] ?? 'yes');
                if ($shift_template_id===0 && $tpl['shift_template_id']) $shift_template_id=(int)$tpl['shift_template_id'];
                if (!$worker_ids && !empty($tpl['default_workers'])) {
                  $worker_ids = array_values(array_filter(array_map('intval', explode(',', (string)$tpl['default_workers']))));
                }
              }
            }
            if (!$client_id) throw new Exception('Client is required (via template or override).');
            if ($vat_included!== 'yes' && $vat_included!=='no') $vat_included = 'yes';
            if (!$status) $status = 'scheduled';

            // resolve time window
            $start_time = null; $end_time = null;
            if ($shift_template_id > 0) {
              $st = $conn->prepare("SELECT DATE_FORMAT(start_time,'%H:%i') AS s, DATE_FORMAT(end_time,'%H:%i') AS e FROM shift_templates WHERE id=?");
              $st->execute([$shift_template_id]);
              $row = $st->fetch(PDO::FETCH_ASSOC);
              if (!$row) throw new Exception('Shift template not found.');
              $start_time = $row['s']; $end_time = $row['e'];
            } else {
              $start_time = normHHMM($start_time_in);
              $end_time   = normHHMM($end_time_in);
              if (!$start_time || !$end_time) throw new Exception('Provide start/end time or pick a shift template.');
              if ($end_time <= $start_time) throw new Exception('End must be after start.');
            }

            // client defaults
            $cst = $conn->prepare("SELECT client_name, rate, default_vat_rate FROM client WHERE id=?");
            $cst->execute([$client_id]);
            $cinfo = $cst->fetch(PDO::FETCH_ASSOC);
            if (!$cinfo) throw new Exception('Client not found.');
            if ($hourly_rate===null || $hourly_rate<=0) $hourly_rate = (float)($cinfo['rate'] ?? 0);
            if ($hourly_rate<=0) throw new Exception('Hourly rate missing.');

            $client_vr = (float)($cinfo['default_vat_rate'] ?? 5.0);

            // build date list
            $startTs = strtotime($start_monday);
            $dates = [];
            for ($w=0; $w<$weeks; $w++) {
              foreach ($dow as $d) {
                $weekBase = strtotime("+$w week", $startTs);
                $curDow = (int)date('w', (int)$weekBase);
                $delta  = $d - $curDow;
                $dayTs  = strtotime(($delta>=0?"+$delta day":"$delta day"), $weekBase);
                $iso    = date('Y-m-d', (int)$dayTs);
                if (!in_array($iso, $exclude_dates, true)) $dates[] = $iso;
              }
            }
            $dates = array_values(array_unique($dates)); sort($dates);

            // capacity helpers
            $capQ = $conn->prepare("SELECT COALESCE(daily_cap_hours,0) FROM workers WHERE id = ?");
            $sumQ = $conn->prepare("
              SELECT COALESCE(SUM(mo.hours / ow_count.cnt),0)
              FROM make_order mo
              JOIN order_workers ow ON ow.order_id = mo.id
              JOIN (SELECT order_id, COUNT(*) as cnt FROM order_workers GROUP BY order_id) ow_count ON ow_count.order_id = mo.id
              WHERE ow.worker_id = ?
                AND mo.svc_date_calc = ?
                AND COALESCE(mo.status,'') <> 'cancelled'
            ");

            // preview items
            $items = []; $total_errors = 0; $warnings = [];
            foreach ($dates as $svc_date) {
              $errors = [];

              // per cleaner duration
              $mins = max(0, hhmmToMin($end_time) - hhmmToMin($start_time));
              $perCleaner = round($mins/60, 2);
              $num_cleaners = max(1, count($worker_ids));
              $hoursBooking = round($perCleaner * $num_cleaners, 2);

              // capacity + conflicts - show overtime warnings but don't block
              foreach ($worker_ids as $wid) {
                $capQ->execute([$wid]); $cap = (float)$capQ->fetchColumn();
                if ($cap > 0) {
                  $sumQ->execute([$wid, $svc_date]); $already=(float)$sumQ->fetchColumn();
                  if ($already + $perCleaner > $cap + 0.0001) {
                    $overtime = $already + $perCleaner - $cap;
                    $warnings[] = "Worker #{$wid}: daily capacity {$cap}h exceeded (would be ".number_format($already+$perCleaner,2)."h). Overtime: ".number_format($overtime,2)."h.";
                  }
                }
                if ($c = findOverlap($conn, (int)$wid, $svc_date, $start_time, $end_time, 0)) {
                  $errors[] = "Worker #{$wid}: overlaps Order #{$c['order_id']} ({$c['start_time']}–{$c['end_time']}).";
                } elseif (hasUnavailability($conn, $wid, $svc_date, $start_time, $end_time)) {
                  $errors[] = "Worker #{$wid}: time-off during {$start_time}–{$end_time}.";
                } elseif (violatesTravelGap($conn, $wid, $svc_date, $start_time, $end_time, 0)) {
                  $gap = getRequiredGapMinutes($conn, $wid);
                  $errors[] = "Worker #{$wid}: not enough travel buffer (needs {$gap} min).";
                }
              }

              // quote
                $vr = (float)$client_vr;
                if ($vat_included === 'yes') {
                  $gross   = round($hourly_rate * $hoursBooking, 2);
                  $netRate = ($vr > 0) ? round($hourly_rate / (1 + $vr/100), 4) : $hourly_rate;
                  $sub     = round($netRate * $hoursBooking, 2);
                  $vat     = round($gross - $sub, 2);
                  $tot     = $gross;
                } else {
                  $sub = round($hourly_rate * $hoursBooking, 2);
                  $vat = round($sub * ($vr/100), 2);
                  $tot = round($sub + $vat, 2);
                }

              $items[] = [
                'svc_date'     => $svc_date,
                'start_time'   => $start_time,
                'end_time'     => $end_time,
                'worker_ids'   => $worker_ids,
                'client_id'    => $client_id,
                'hourly_rate'  => $hourly_rate,
                'status'       => $status,
                'remark'       => $remark,
                'vat_included' => $vat_included,
                'driver_id'    => $driver_id ?: null,
                'quote'        => ['hours'=>$hoursBooking, 'subtotal'=>$sub, 'vat'=>$vat, 'total'=>$tot],
                'errors'       => $errors,
              ];
              if ($errors) $total_errors++;
            }

            echo json_encode([
              'ok'      => true,
              'items'   => $items,
              'warnings' => $warnings,
              'summary' => ['total_ok' => count($items)-$total_errors, 'total_errors' => $total_errors]
            ]);
            exit;
          }
  
                /* ---------- REPEAT: COMMIT (transactional) ---------- */
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'repeat_commit') {
        $remark = isset($_POST['remark']) ? trim((string)$_POST['remark']) : '';
        $raw = $_POST['items_json'] ?? '';
                  $items = json_decode($raw, true);
                  if (!is_array($items) || !$items) throw new Exception('Missing items_json');
        // 🔴 CREDIT CONTROL (REPEAT pre-check)
        $override = !empty($_POST['override_credit_limit']);

        // Determine the client_id the batch uses (your preview enforces same client)
        $batchClientId = 0;
        foreach ($items as $it) {
          if (!empty($it['client_id'])) { $batchClientId = (int)$it['client_id']; break; }
        }
        if ($batchClientId <= 0) throw new Exception('Missing client_id in batch.');

        // Load client VAT rate once
        $cst = $conn->prepare("SELECT default_vat_rate FROM client WHERE id=?");
        $cst->execute([$batchClientId]);
        $client_vr = (float)($cst->fetchColumn() ?? 5.0);

        // Recompute batch total for rows *that will be inserted as confirmed*
        $batchToAdd = 0.0;
        foreach ($items as $it) {
          $statusB  = strtolower((string)($it['status'] ?? 'scheduled'));
          if ($statusB !== 'confirmed') continue;

          $start_time   = (string)$it['start_time'];
          $end_time     = (string)$it['end_time'];
          $worker_ids   = isset($it['worker_ids']) ? (array)$it['worker_ids'] : [];
          $hourly_rate  = (float)($it['hourly_rate'] ?? 0);
          $vat_included = (string)($it['vat_included'] ?? 'yes');

          $mins = max(0, hhmmToMin($end_time) - hhmmToMin($start_time));
          $perCleaner = round($mins/60, 2);
          $num_cleaners = max(1, count($worker_ids));
          $hoursBooking = round($perCleaner * $num_cleaners, 2);

            $vr = (float)$client_vr;
            if ($vat_included === 'yes') {
              $gross   = round($hourly_rate * $hoursBooking, 2);
              $netRate = ($vr > 0) ? round($hourly_rate / (1 + $vr/100), 4) : $hourly_rate;
              $sub     = round($netRate * $hoursBooking, 2);
              $vat     = round($gross - $sub, 2);
              $tot     = $gross;
            } else {
              $sub = round($hourly_rate * $hoursBooking, 2);
              $vat = round($sub * ($vr/100), 2);
              $tot = round($sub + $vat, 2);
            }
            $batchToAdd += $tot;
        }

        if ($batchToAdd > 0 && !$override) {
            $snap = credit_snapshot($conn, $batchClientId, null);
            if (credit_would_exceed_available($snap['available'], $batchToAdd)) {
              http_response_code(400);
              echo json_encode([
                'success'=>false,
                'error'=>sprintf(
                  'Credit limit warning for batch: outstanding %.2f, unapplied %.2f, available %.2f; batch %.2f would exceed the limit of %.2f.',
                  $snap['outstanding'], $snap['unapplied'], $snap['available'], $batchToAdd, $snap['credit_limit']
                )
              ]);
              exit;
            }
        }
                  $createdOut = [];
                  require_once __DIR__ . '/../includes/company_helper.php';
                  $currentCompanyId = current_company_id($conn) ?: 1;
                  $service_category_id = sm_resolve_service_category_id(
                      $conn,
                      (int)($_POST['service_category_id'] ?? 0) ?: null,
                      (int)$currentCompanyId
                  );
                  $conn->beginTransaction();
                  try {
                    foreach ($items as $it) {
                      $svc_date     = $it['svc_date']     ?? '';
                      $start_time   = $it['start_time']   ?? '';
                      $end_time     = $it['end_time']     ?? '';
                      $worker_ids   = isset($it['worker_ids']) ? array_values(array_filter(array_map('intval',(array)$it['worker_ids']))) : [];
                      $client_id    = (int)($it['client_id'] ?? 0);
                      $hourly_rate  = (float)($it['hourly_rate'] ?? 0);
                      $status       = (string)($it['status'] ?? 'scheduled');
                      $vat_included = (string)($it['vat_included'] ?? 'yes');
                      $driver_id    = (int)($it['driver_id'] ?? 0);
                      $itRemark = isset($it['remark']) ? trim((string)$it['remark']) : $remark;

                      if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $svc_date)) throw new Exception('Bad svc_date.');
                      if (!$worker_ids) throw new Exception('Missing worker_ids.');
                      if (!$client_id) throw new Exception('Missing client_id.');
                      if (!preg_match('/^\d{2}:\d{2}$/',$start_time) || !preg_match('/^\d{2}:\d{2}$/',$end_time)) throw new Exception('Bad time.');
                      if ($end_time <= $start_time) throw new Exception('End must be after start.');

                      // race-safe revalidation
                      foreach ($worker_ids as $wid) {
                        if ($c = findOverlap($conn, $wid, $svc_date, $start_time, $end_time, 0)) {
                          throw new Exception("Worker #{$wid}: overlaps Order #{$c['order_id']} ({$c['start_time']}–{$c['end_time']}).");
                        }
                        if (hasUnavailability($conn, $wid, $svc_date, $start_time, $end_time)) {
                          throw new Exception("Worker #{$wid}: time-off during {$start_time}–{$end_time}.");
                        }
                        if (violatesTravelGap($conn, $wid, $svc_date, $start_time, $end_time, 0)) {
                          $gap = getRequiredGapMinutes($conn, $wid);
                          throw new Exception("Worker #{$wid}: not enough travel buffer (needs {$gap} min).");
                        }
                      }

                      // money (mirror your CREATE)
                      $mins = max(0, hhmmToMin($end_time) - hhmmToMin($start_time));
                      $perCleaner = round($mins/60, 2);
                      $num_cleaners = max(1, count($worker_ids));
                      $hoursBooking = round($perCleaner * $num_cleaners, 2);

                      $cst = $conn->prepare("SELECT client_name, email, address, mobile_num, rate, terms, default_vat_rate FROM client WHERE id=?");
                      $cst->execute([$client_id]);
                      $c = $cst->fetch(PDO::FETCH_ASSOC);
                      if (!$c) throw new Exception('Client not found.');

                      $client_name   = (string)$c['client_name'];
                      $email         = (string)($c['email'] ?? '');
                      $address       = (string)($c['address'] ?? '');
                      $phone         = (string)($c['mobile_num'] ?? '');
                      $fee           = $hourly_rate > 0 ? $hourly_rate : (float)($c['rate'] ?? 0);
                      $terms         = (string)($c['terms'] ?? 'cash');
                      $clientVatRate = (float)($c['default_vat_rate'] ?? 5.00);
                      if ($fee <= 0) throw new Exception('Hourly rate missing.');

                      $driver_name = null;
                      if ($driver_id) {
                        $dn = $conn->prepare("SELECT nickname FROM driver WHERE id=?");
                        $dn->execute([$driver_id]);
                        $driver_name = (string)$dn->fetchColumn() ?: null;
                      }

                      // display worker names
                      $worker_name_str = '';
                      if ($worker_ids) {
                        $ph = implode(',', array_fill(0, count($worker_ids), '?'));
                        $ws = $conn->prepare("SELECT nickname FROM workers WHERE id IN ($ph) ORDER BY nickname");
                        $ws->execute($worker_ids);
                        $worker_name_str = implode(' , ', $ws->fetchAll(PDO::FETCH_COLUMN));
                      }

                        $vr = (float)$clientVatRate;
                        if ($vat_included === 'yes') {
                          $gross   = round($fee * $hoursBooking, 2);
                          $netRate = ($vr > 0) ? round($fee / (1 + $vr/100), 4) : $fee;
                          $sub     = round($netRate * $hoursBooking, 2);
                          $vat     = round($gross - $sub, 2);
                          $tot     = $gross;
                        } else {
                          $sub = round($fee * $hoursBooking, 2);
                          $vat = round($sub * ($vr/100), 2);
                          $tot = round($sub + $vat, 2);
                        }

                      // Get current company_id
                      if (!isset($currentCompanyId)) {
                          $currentCompanyId = current_company_id($conn) ?: 1;
                      }
                      
                      // insert order (same columns as CREATE; invoice deferred until finalize)
                      $insCols = "company_id, client_id, client_name, worker_name, email_o, address_o, mobile_num_o,
                           fee_charged, hourly_rate, payment, `date`, service_date, `time`,
                           start_time, end_time, hours, total, balance, remark, notes,
                           service_type_id, driver_name, driver_id, net_hours, net_amount, amount_afc, discount_amount,
                           vat_rate, vat_amount, grand_total, status, payment_status,
                           created_at, created_by";
                      $insVals = ":company_id, :client_id, :client_name, :worker_name, :email, :addr, :phone,
                           :fee, :fee, :terms, :d, :d, :legacy,
                           :start, :end, :hours, :sub, 0.00, :remark, NULL,
                           :service_type_id, :driver_name, :driver_id, :net_hours, :net_amount, :amount_afc, 0.00,
                           :vat_rate, :vat_amount, :grand_total, :status, 'unpaid',
                           NOW(), :uid";
                      $insParams = [
                        ':company_id'  => $currentCompanyId,
                        ':client_id'   => $client_id,
                        ':client_name' => $client_name,
                        ':worker_name' => $worker_name_str,
                        ':email'       => $email,
                        ':addr'        => $address,
                        ':phone'       => $phone,
                        ':fee'         => $fee,
                        ':terms'       => $terms,
                        ':d'           => $svc_date,
                        ':legacy'      => timeToLegacy($start_time) . ' To ' . timeToLegacy($end_time),
                        ':start'       => $start_time,
                        ':end'         => $end_time,
                        ':hours'       => $hoursBooking,
                        ':sub'         => $sub,
                        ':remark'     => ($itRemark !== null ? $itRemark : ''),
                        ':service_type_id' => null,
                        ':driver_name' => $driver_name,
                        ':driver_id'   => $driver_id ?: null,
                        ':net_hours'   => $perCleaner,
                        ':net_amount'  => round($perCleaner * $fee, 2),
                        ':amount_afc'  => $sub,
                        ':vat_rate'    => $clientVatRate,
                        ':vat_amount'  => $vat,
                        ':grand_total' => $tot,
                        ':status'      => $status,
                        ':uid'         => current_user_id(),
                      ];
                      if (sm_make_order_has_service_category($conn) && $service_category_id) {
                          $insCols = "company_id, service_category_id, client_id, client_name, worker_name, email_o, address_o, mobile_num_o,
                           fee_charged, hourly_rate, payment, `date`, service_date, `time`,
                           start_time, end_time, hours, total, balance, remark, notes,
                           service_type_id, driver_name, driver_id, net_hours, net_amount, amount_afc, discount_amount,
                           vat_rate, vat_amount, grand_total, status, payment_status,
                           created_at, created_by";
                          $insVals = ":company_id, :service_category_id, :client_id, :client_name, :worker_name, :email, :addr, :phone,
                           :fee, :fee, :terms, :d, :d, :legacy,
                           :start, :end, :hours, :sub, 0.00, :remark, NULL,
                           :service_type_id, :driver_name, :driver_id, :net_hours, :net_amount, :amount_afc, 0.00,
                           :vat_rate, :vat_amount, :grand_total, :status, 'unpaid',
                           NOW(), :uid";
                          $insParams[':service_category_id'] = $service_category_id;
                      }
                      $ins = $conn->prepare("INSERT INTO make_order ({$insCols}) VALUES ({$insVals})");
                      $ins->execute($insParams);
                      $order_id = (int)$conn->lastInsertId();

                      // Audit Log: Track batch order creation via API
                      require_once __DIR__ . '/../includes/AuditService.php';
                      $actorId = current_user_id();
                      AuditService::logCreate('make_order', $order_id, [
                        'client_name' => $client_name,
                        'service_date' => $svc_date,
                        'start_time' => $start_time,
                        'end_time' => $end_time,
                        'worker_count' => count($worker_ids),
                        'total' => $tot,
                        'status' => $status
                      ], "Created order #{$order_id} for {$client_name} via batch API", $actorId ? (int)$actorId : null);

                      $insOW = $conn->prepare("INSERT INTO order_workers (order_id, worker_id) VALUES (?, ?)");
                      foreach ($worker_ids as $wid) $insOW->execute([$order_id, $wid]);
                        
                        audit_order($conn, $order_id, $actorId, 'create.repeat', null, $it);
                        nt_notify_driver_for_order($conn, $order_id, 'driver.order.assigned');
                        
                      // Invoice deferred until finalize (Phase 2) unless legacy auto-invoice is on
                      $invoice_id = null;
                      try {
                        $invoice_id = wo_maybe_sync_invoice_after_order_change($conn, $order_id, $actorId, true);
                        if ($invoice_id) {
                          $conn->prepare("UPDATE make_order SET invoice_id = :iid WHERE id = :id")
                               ->execute([':iid'=>$invoice_id, ':id'=>$order_id]);
                        }
                        wo_sync_ops_status_column($conn, $order_id, $status);
                      } catch (Throwable $glx) {
                        if (strpos($glx->getMessage(), 'Duplicate entry') === false) { throw $glx; }
                      }

                      $createdOut[] = [
                        'order_id'   => $order_id,
                        'svc_date'   => $svc_date,
                        'start'      => $start_time,
                        'end'        => $end_time,
                        'workers'    => $worker_ids,
                        'invoice_id' => $invoice_id,
                      ];
                    }

                    $conn->commit();
                    echo json_encode(['success'=>true, 'created'=>$createdOut]); exit;

                  } catch (Throwable $e) {
                    $conn->rollBack();
                    http_response_code(400);
                    echo json_encode(['success'=>false,'error'=>'Repeat commit failed: '.$e->getMessage()]);
                    exit;
                  }
                }

  /* ---------- CREATE order ---------- */
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    $service_date = $_POST['service_date'] ?? date('Y-m-d');
    $start_time   = $_POST['start_time']   ?? '';
    $end_time     = $_POST['end_time']     ?? '';
    $client_id    = (int)($_POST['client_id'] ?? 0);
    $worker_ids   = isset($_POST['worker_ids']) ? array_values(array_filter(array_map('intval', (array)$_POST['worker_ids']))) : [];
    $status       = $_POST['status'] ?? 'confirmed';
    $hourly_rate  = (float)($_POST['hourly_rate'] ?? 0);
    $remark       = $_POST['remark'] ?? '';
    $vat_included = $_POST['vat_included'] ?? 'yes';
    $driver_id    = (int)($_POST['driver_id'] ?? 0);
    $company_name = $_POST['company_name'] ?? '';
    $user_id      = current_user_id();
    $need_materials = isset($_POST['need_materials']) ? (int)$_POST['need_materials'] : 0;
    $materials_note = isset($_POST['materials_note']) ? trim((string)$_POST['materials_note']) : null;
    
    // Service pricing (optional - alternative to hours × rate)
    $svc_ids       = $_POST['svc_service_id'] ?? [];
    $svc_qtys      = $_POST['svc_qty'] ?? [];
    $svc_units     = $_POST['svc_unit'] ?? [];
    $svc_prices    = $_POST['svc_unit_price'] ?? [];

    if (!$client_id) throw new Exception('Client is required.');
    if (!$start_time || !$end_time) throw new Exception('Start and End time are required.');
    if (!$worker_ids) throw new Exception('At least one worker is required.');

    $st = $conn->prepare("SELECT client_name, email, address, mobile_num, rate, terms, default_vat_rate, credit_limit FROM client WHERE id=?");
    $st->execute([$client_id]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c) throw new Exception('Client not found.');

    $client_name   = (string)$c['client_name'];
    $email         = (string)($c['email'] ?? '');
    $address       = (string)($c['address'] ?? '');
    $phone         = (string)($c['mobile_num'] ?? '');
    $fee           = $hourly_rate > 0 ? $hourly_rate : (float)($c['rate'] ?? 0);
    $terms         = (string)($c['terms'] ?? 'cash');
    $clientVatRate = (float)($c['default_vat_rate'] ?? 5.00);
    $bookingCategoryIds = wo_pricing_parse_booking_categories($conn, $_POST);
    $cleaningCat = sm_get_category_by_code($conn, 'cleaning');
    $cleaningCatId = $cleaningCat ? (int)$cleaningCat['id'] : 0;
    $includeCleaning = $cleaningCatId > 0 && in_array($cleaningCatId, $bookingCategoryIds, true);
    $catalogLines = wo_pricing_parse_catalog_lines($conn, $_POST);
    $hasCatalog = !empty($catalogLines);

    if ($includeCleaning) {
        $fee = $hourly_rate > 0 ? $hourly_rate : (float)($c['rate'] ?? 0);
        if ($fee <= 0) {
            throw new Exception('Hourly rate is required for cleaning.');
        }
    } else {
        $fee = $hourly_rate > 0 ? $hourly_rate : (float)($c['rate'] ?? 0);
    }

    if (!$includeCleaning && !$hasCatalog) {
        throw new Exception('Select at least one service and add catalog items, or include Cleaning with a valid hourly rate.');
    }

    $driver_name = null;
    if ($driver_id) {
      $dn = $conn->prepare("SELECT nickname FROM driver WHERE id=?");
      $dn->execute([$driver_id]);
      $driver_name = (string)$dn->fetchColumn();
    }

    $worker_name_str = '';
    if ($worker_ids) {
      $ph = implode(',', array_fill(0, count($worker_ids), '?'));
      $ws = $conn->prepare("SELECT nickname FROM workers WHERE id IN ($ph) ORDER BY nickname");
      $ws->execute($worker_ids);
      $worker_name_str = implode(' , ', $ws->fetchAll(PDO::FETCH_COLUMN));
    }

    $mins = max(0, hhmmToMin($end_time) - hhmmToMin($start_time));
    $durPerCleaner = round($mins / 60, 2);
    $num_cleaners  = max(1, count($worker_ids));
    $hoursBooking  = round($durPerCleaner * $num_cleaners, 2);

    // Calculate totals — hybrid cleaning labour/materials + catalog lines
    $vr = (float)$clientVatRate;
    $materialsRate = 0.0;
    if ($includeCleaning && $cleaningCat) {
        $materialsRate = round((float)($cleaningCat['materials_rate_per_hour'] ?? 0), 2);
    }

    $pricing = wo_pricing_compute_booking([
        'vat_rate' => $vr,
        'vat_included' => $vat_included,
        'hourly_rate' => $fee,
        'hours_booking' => $hoursBooking,
        'need_materials' => (bool)$need_materials,
        'materials_rate_per_hour' => $materialsRate,
        'include_cleaning' => $includeCleaning,
        'catalog_lines' => $catalogLines,
    ]);

    $sub = $pricing['subtotal'];
    $vat = $pricing['vat_amount'];
    $tot = $pricing['grand_total'];
    $orderServiceRows = $pricing['order_service_rows'];
    $useServices = !empty($orderServiceRows);

    // daily capacity check per worker - allow overtime
    $capQ = $conn->prepare("SELECT COALESCE(daily_cap_hours,0) FROM workers WHERE id = ?");
    $sumQ = $conn->prepare("
      SELECT COALESCE(SUM(mo.hours / ow_count.cnt),0)
      FROM make_order mo
      JOIN order_workers ow ON ow.order_id = mo.id
      JOIN (SELECT order_id, COUNT(*) as cnt FROM order_workers GROUP BY order_id) ow_count ON ow_count.order_id = mo.id
      WHERE ow.worker_id = ?
        AND mo.svc_date_calc = ?
        AND COALESCE(mo.status,'') <> 'cancelled'
    ");
    $perCleanerHours = $durPerCleaner; // This is the per-cleaner duration (10 hours for 8 AM to 6 PM)
    $overtime_hours = 0;
    foreach ($worker_ids as $wid) {
      $capQ->execute([$wid]);
      $cap = (float)$capQ->fetchColumn();
      if ($cap > 0) {
        $sumQ->execute([$wid, $service_date]);
        $already = (float)$sumQ->fetchColumn();
        
        // Debug: Check what orders are being found and test the new query
        $debugQ = $conn->prepare("
          SELECT mo.id, mo.hours, mo.status, mo.svc_date_calc, ow_count.cnt as worker_count
          FROM make_order mo
          JOIN order_workers ow ON ow.order_id = mo.id
          JOIN (SELECT order_id, COUNT(*) as cnt FROM order_workers GROUP BY order_id) ow_count ON ow_count.order_id = mo.id
          WHERE ow.worker_id = ?
            AND mo.svc_date_calc = ?
            AND COALESCE(mo.status,'') <> 'cancelled'
        ");
        $debugQ->execute([$wid, $service_date]);
        $debugOrders = $debugQ->fetchAll(PDO::FETCH_ASSOC);
        error_log("DEBUG: Found " . count($debugOrders) . " existing orders for worker {$wid} on {$service_date}: " . json_encode($debugOrders));
        
        // Test the old query too
        $oldQ = $conn->prepare("
          SELECT COALESCE(SUM(mo.hours),0)
          FROM make_order mo
          JOIN order_workers ow ON ow.order_id = mo.id
          WHERE ow.worker_id = ?
            AND mo.svc_date_calc = ?
            AND COALESCE(mo.status,'') <> 'cancelled'
        ");
        $oldQ->execute([$wid, $service_date]);
        $oldResult = (float)$oldQ->fetchColumn();
        error_log("DEBUG: Old query result: {$oldResult}, New query result: {$already}");
        
        if ($already + $perCleanerHours > $cap + 0.0001) {
          $overtime_hours += ($already + $perCleanerHours) - $cap;
          // Log overtime but don't block the order
          error_log("Worker #{$wid}: Overtime detected - Daily capacity {$cap}h exceeded (would be " . number_format($already + $perCleanerHours, 2) . "h). Overtime: " . number_format(($already + $perCleanerHours) - $cap, 2) . "h.");
        }
      }
    }

      // 🔴 CREDIT CONTROL (CREATE)
      $override = !empty($_POST['override_credit_limit']); // from UI checkbox
      if (strtolower((string)$status) === 'confirmed' && !$override) {
          $snap = credit_snapshot($conn, $client_id, null);
          if (credit_would_exceed_available($snap['available'], (float)$tot)) {
            http_response_code(400);
            echo json_encode([
              'success'=>false,
              'error'  => sprintf(
                'Credit limit warning: outstanding %.2f, unapplied %.2f, available %.2f; this order %.2f would exceed the limit of %.2f.',
                $snap['outstanding'], $snap['unapplied'], $snap['available'], (float)$tot, $snap['credit_limit']
              )
            ]);
            exit;
          }
      }
      
    // conflict checks
    foreach ($worker_ids as $wid) {
      if ($conf = findOverlap($conn, (int)$wid, $service_date, $start_time, $end_time, 0)) {
        $oid  = (int)$conf['order_id'];
        $cNm  = $conf['client_name'] ?: 'another client';
        $cSt  = $conf['start_time'] ?: '?';
        $cEn  = $conf['end_time']   ?: '?';
        $stt  = $conf['status'] ?: '—';
        throw new Exception("Overlap: {$start_time}–{$end_time} conflicts with Order #{$oid} ({$cNm}, {$cSt}–{$cEn}, status: {$stt}).");
      }
      if (hasUnavailability($conn, $wid, $service_date, $start_time, $end_time)) {
        throw new Exception("{$worker_name_str}: time-off/unavailability during {$start_time}–{$end_time}.");
      }
      if (violatesTravelGap($conn, $wid, $service_date, $start_time, $end_time, 0)) {
        throw new Exception("{$worker_name_str}: not enough travel buffer before/after this job.");
      }
    }

    // Add overtime info to remark if applicable
    $final_remark = $remark;
    if ($overtime_hours > 0) {
      $overtime_text = " [OVERTIME: " . number_format($overtime_hours, 2) . "h]";
      $final_remark = ($remark ? $remark . " " : "") . $overtime_text;
    }

    // Get current company_id
    require_once __DIR__ . '/../includes/company_helper.php';
    $currentCompanyId = current_company_id($conn) ?: 1;
    $service_category_id = sm_resolve_primary_category_id($conn, $bookingCategoryIds)
        ?: sm_resolve_service_category_id($conn, (int)($_POST['service_category_id'] ?? 0) ?: null, (int)$currentCompanyId);
    $bookingCategoriesJson = $bookingCategoryIds
        ? json_encode(array_values($bookingCategoryIds), JSON_THROW_ON_ERROR)
        : null;
    
    $insCols = "company_id, client_id, client_name, worker_name, email_o, address_o, mobile_num_o,
         fee_charged, hourly_rate, payment, `date`, service_date, `time`,
         start_time, end_time, hours, total, balance, remark, notes,
         need_materials, materials_note, service_type_id, driver_name, driver_id, net_hours, net_amount,amount_afc, discount_amount,
         vat_rate, vat_amount, grand_total, status, payment_status,
         created_at, created_by";
    $insVals = ":company_id, :client_id, :client_name, :worker_name, :email, :addr, :phone,
         :fee, :fee, :terms, :d, :d, :legacy,
         :start, :end, :hours, :sub, 0.00, :remark, NULL,
         :need_materials, :materials_note, :service_type_id, :driver_name, :driver_id, :net_hours, :net_amount, :amount_afc, 0.00,
         :vat_rate, :vat_amount, :grand_total, :status, 'unpaid', NOW(), :uid";
    $insParams = [
      ':company_id'  => $currentCompanyId,
      ':client_id'   => $client_id,
      ':client_name' => $client_name,
      ':worker_name' => $worker_name_str,
      ':email'       => $email,
      ':addr'        => $address,
      ':phone'       => $phone,
      ':fee'         => $fee,
      ':terms'       => $terms,
      ':d'           => $service_date,
      ':legacy'      => timeToLegacy($start_time) . ' To ' . timeToLegacy($end_time),
      ':start'       => $start_time,
      ':end'         => $end_time,
      ':hours'       => $hoursBooking,
      ':sub'         => $sub,
      ':remark'         => $final_remark,
      ':need_materials' => $need_materials ? 1 : 0,
      ':materials_note' => $materials_note ?: null,
      ':service_type_id' => null,
      ':driver_name' => $driver_name,
      ':driver_id'   => $driver_id ?: null,
      ':net_hours'   => $durPerCleaner,
      ':net_amount'  => round($durPerCleaner * $fee, 2),
      ':amount_afc'  => $sub,
      ':vat_rate'    => $clientVatRate,
      ':vat_amount'  => $vat,
      ':grand_total' => $tot,
      ':status'      => $status,
      ':uid'         => $user_id,
    ];
    if (sm_make_order_has_service_category($conn) && $service_category_id) {
        $insCols = "company_id, service_category_id, client_id, client_name, worker_name, email_o, address_o, mobile_num_o,
         fee_charged, hourly_rate, payment, `date`, service_date, `time`,
         start_time, end_time, hours, total, balance, remark, notes,
         need_materials, materials_note, service_type_id, driver_name, driver_id, net_hours, net_amount,amount_afc, discount_amount,
         vat_rate, vat_amount, grand_total, status, payment_status,
         created_at, created_by";
        $insVals = ":company_id, :service_category_id, :client_id, :client_name, :worker_name, :email, :addr, :phone,
         :fee, :fee, :terms, :d, :d, :legacy,
         :start, :end, :hours, :sub, 0.00, :remark, NULL,
         :need_materials, :materials_note, :service_type_id, :driver_name, :driver_id, :net_hours, :net_amount, :amount_afc, 0.00,
         :vat_rate, :vat_amount, :grand_total, :status, 'unpaid', NOW(), :uid";
        $insParams[':service_category_id'] = $service_category_id;
        if (sm_make_order_has_booking_categories($conn) && $bookingCategoriesJson) {
            $insCols = str_replace('service_category_id,', 'service_category_id, booking_categories,', $insCols);
            $insVals = str_replace(':service_category_id,', ':service_category_id, :booking_categories,', $insVals);
            $insParams[':booking_categories'] = $bookingCategoriesJson;
        }
    }
    $ins = $conn->prepare("INSERT INTO make_order ({$insCols}) VALUES ({$insVals})");
    $ins->execute($insParams);
    $order_id = (int)$conn->lastInsertId();

    // Audit Log: Track order creation via API
    require_once __DIR__ . '/../includes/AuditService.php';
    AuditService::logCreate('make_order', $order_id, [
      'client_name' => $client_name,
      'service_date' => $service_date,
      'start_time' => $start_time,
      'end_time' => $end_time,
      'worker_count' => count($worker_ids),
      'total' => $tot,
      'status' => $status,
      'used_services' => $useServices
    ], "Created order #{$order_id} for {$client_name} via API", $user_id ? (int)$user_id : null);

    $insOW = $conn->prepare("INSERT INTO order_workers (order_id, worker_id) VALUES (?, ?)");
    foreach ($worker_ids as $wid) $insOW->execute([$order_id, $wid]);
    
    // Insert order_services for invoice line items at finalize
    if ($useServices && !empty($orderServiceRows)) {
      $insSvc = $conn->prepare("
        INSERT INTO order_services (order_id, service_id, service_name, description, qty, unit, unit_price, vat_rate)
        VALUES (?,?,?,?,?,?,?,?)
      ");
      foreach ($orderServiceRows as $sr) {
        $insSvc->execute([
          $order_id,
          $sr['service_id'] ?? null,
          $sr['service_name'],
          $sr['description'] ?? null,
          $sr['qty'],
          $sr['unit'],
          $sr['unit_price'],
          $sr['vat_rate']
        ]);
      }
    }
      
    // Create overtime entries for workers who exceeded daily capacity
    $overtime_entries_created = [];
    if ($overtime_hours > 0) {
      foreach ($worker_ids as $wid) {
        $capQ->execute([$wid]);
        $cap = (float)$capQ->fetchColumn();
        if ($cap > 0) {
          // Exclude the current order from the calculation
          $sumQExclude = $conn->prepare("
            SELECT COALESCE(SUM(mo.hours / ow_count.cnt),0)
            FROM make_order mo
            JOIN order_workers ow ON ow.order_id = mo.id
            JOIN (SELECT order_id, COUNT(*) as cnt FROM order_workers GROUP BY order_id) ow_count ON ow_count.order_id = mo.id
            WHERE ow.worker_id = ?
              AND mo.svc_date_calc = ?
              AND COALESCE(mo.status,'') <> 'cancelled'
              AND mo.id != ?
          ");
          $sumQExclude->execute([$wid, $service_date, $order_id]);
          $already = (float)$sumQExclude->fetchColumn();
          if ($already + $perCleanerHours > $cap + 0.0001) {
            $worker_overtime = ($already + $perCleanerHours) - $cap;
            
            // Debug: Log the calculated overtime before creating entry
            error_log("DEBUG: Worker {$wid} - cap: {$cap}, already: {$already}, perCleanerHours: {$perCleanerHours}, calculated overtime: {$worker_overtime}");
            error_log("DEBUG: Formula: ({$already} + {$perCleanerHours}) - {$cap} = {$worker_overtime}");
            
            $overtime_entry = createOvertimeEntry(
              $conn, 
              $wid, 
              $service_date, 
              $start_time, 
              $end_time, 
              $worker_overtime, 
              $fee, 
              $order_id, 
              $user_id
            );
            
            if ($overtime_entry) {
              $overtime_entries_created[] = $overtime_entry;
              error_log("Overtime entry created for worker {$wid}: {$overtime_entry['employee']} - {$overtime_entry['hours']}h");
            } else {
              error_log("Failed to create overtime entry for worker {$wid} - no matching employee found");
            }
          }
        }
      }
    }

    // Invoice deferred until finalize (Phase 2) unless legacy auto-invoice is on
      $invoice_id = null;
      try {
        $invoice_id = wo_maybe_sync_invoice_after_order_change($conn, $order_id, current_user_id(), true);
        if ($invoice_id) {
          $conn->prepare("UPDATE make_order SET invoice_id = :iid WHERE id = :id")
               ->execute([':iid'=>$invoice_id, ':id'=>$order_id]);
        }
        wo_sync_ops_status_column($conn, $order_id, (string)$status);
      } catch (Throwable $glx) {
        if (strpos($glx->getMessage(), 'Duplicate entry') === false) { 
          error_log("Invoice creation failed for order {$order_id}: " . $glx->getMessage());
        }
      }
      
      // after $order_id is created and invoice sync done
      audit_order($conn, $order_id, $user_id, 'create', null, [
        'svc_date'=>$service_date, 'start'=>$start_time, 'end'=>$end_time,
        'status'=>$status, 'client_id'=>$client_id, 'workers'=>$worker_ids
      ]);

      // notify DRIVER (not workers)
      nt_notify_driver_for_order($conn, $order_id, 'driver.order.assigned');

    echo json_encode([
      'success'=>true,
      'order_id'=>$order_id,
      'invoice_id'=>$invoice_id,
      'overtime_entries'=>$overtime_entries_created
    ]); exit;
  }

  /* ---------- CALCULATE OVERTIME ---------- */
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'calculate_overtime') {
    $order_id = (int)($_POST['order_id'] ?? 0);
    $start_time = $_POST['start_time'] ?? '';
    $end_time = $_POST['end_time'] ?? '';
    
    if ($order_id <= 0 || !$start_time || !$end_time) {
      echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
      exit;
    }
    
    // Get order details
    $st = $conn->prepare("SELECT * FROM make_order WHERE id = ?");
    $st->execute([$order_id]);
    $order = $st->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
      echo json_encode(['success' => false, 'error' => 'Order not found']);
      exit;
    }
    
    // Get current workers for this order
    $wq = $conn->prepare("SELECT worker_id FROM order_workers WHERE order_id = ?");
    $wq->execute([$order_id]);
    $worker_ids = array_map('intval', $wq->fetchAll(PDO::FETCH_COLUMN));
    
    if (empty($worker_ids)) {
      echo json_encode(['success' => true, 'overtime_info' => []]);
      exit;
    }
    
    // Calculate overtime for each worker
    $overtime_info = [];
    $service_date = $order['service_date'];
    $hourly_rate = (float)$order['hourly_rate'];
    
    // Convert times to minutes for calculation
    $start_mins = hhmmToMin($start_time);
    $end_mins = hhmmToMin($end_time);
    $duration_mins = max(0, $end_mins - $start_mins);
    $duration_hours = round($duration_mins / 60, 2);
    
    foreach ($worker_ids as $worker_id) {
      // Get worker's daily capacity
      $capQ = $conn->prepare("SELECT COALESCE(daily_cap_hours,0) FROM workers WHERE id = ?");
      $capQ->execute([$worker_id]);
      $capacity = (float)$capQ->fetchColumn();
      
      if ($capacity > 0) {
        // Calculate total hours for this worker on this date (excluding current order)
        $sumQ = $conn->prepare("
          SELECT COALESCE(SUM(mo.hours / ow_count.cnt), 0)
          FROM make_order mo
          JOIN order_workers ow ON ow.order_id = mo.id
          JOIN (SELECT order_id, COUNT(*) as cnt FROM order_workers GROUP BY order_id) ow_count ON ow_count.order_id = mo.id
          WHERE ow.worker_id = ?
            AND mo.svc_date_calc = ?
            AND COALESCE(mo.status, '') <> 'cancelled'
            AND mo.id != ?
        ");
        $sumQ->execute([$worker_id, $service_date, $order_id]);
        $already_worked = (float)$sumQ->fetchColumn();
        
        $total_hours = $already_worked + $duration_hours;
        if ($total_hours > $capacity + 0.0001) {
          $overtime_hours = $total_hours - $capacity;
          $overtime_amount = round($overtime_hours * 10.00, 2); // Fixed overtime rate
          
          // Get worker name
          $wn = $conn->prepare("SELECT nickname FROM workers WHERE id = ?");
          $wn->execute([$worker_id]);
          $worker_name = $wn->fetchColumn() ?: "Worker #{$worker_id}";
          
          $overtime_info[] = [
            'worker_id' => $worker_id,
            'worker_name' => $worker_name,
            'hours' => round($overtime_hours, 2),
            'amount' => $overtime_amount
          ];
        }
      }
    }
    
    echo json_encode(['success' => true, 'overtime_info' => $overtime_info]);
    exit;
  }

  /* ---------- EDIT (status/driver/rate/vat/remark/workers/times) ---------- */
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit') {
    $order_id = (int)($_POST['order_id'] ?? 0);
    if ($order_id <= 0) throw new Exception('Missing order id');

    $status       = $_POST['status']        ?? null;
    $driver_id_in = $_POST['driver_id']     ?? null;  // '' to clear
    $hourly_rate  = isset($_POST['hourly_rate']) ? (float)$_POST['hourly_rate'] : null;
    $vat_included = $_POST['vat_included']  ?? null;  // 'yes'|'no'|null
    $remark       = $_POST['remark']        ?? null;
    $need_materials_in = $_POST['need_materials'] ?? null; // '0'|'1' or null (no change)
    $materials_note_in = array_key_exists('materials_note', $_POST) ? (string)$_POST['materials_note'] : null;
    
    // Service pricing (optional - alternative to hours × rate)
    $svc_ids       = $_POST['svc_service_id'] ?? [];
    $svc_qtys      = $_POST['svc_qty'] ?? [];
    $svc_units     = $_POST['svc_unit'] ?? [];
    $svc_prices    = $_POST['svc_unit_price'] ?? [];
    
    // New fields for time and worker updates
    $start_time   = $_POST['start_time'] ?? null;
    $end_time     = $_POST['end_time']   ?? null;
    $worker_ids   = isset($_POST['worker_ids']) && is_array($_POST['worker_ids']) 
                   ? array_values(array_filter(array_map('intval', $_POST['worker_ids'])))
                   : null;
    
    // Service date (for moving orders to different dates)
    $service_date = isset($_POST['service_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['service_date'])
                   ? $_POST['service_date']
                   : null;
    $service_category_in = isset($_POST['service_category_id']) && $_POST['service_category_id'] !== ''
                   ? (int)$_POST['service_category_id']
                   : null;

    $st = $conn->prepare("
      SELECT mo.*, c.default_vat_rate, c.rate AS client_rate
      FROM make_order mo
      LEFT JOIN client c ON c.id = mo.client_id
      WHERE mo.id = ?
    ");
    $st->execute([$order_id]);
    $mo = $st->fetch(PDO::FETCH_ASSOC);
    if (!$mo) throw new Exception('Order not found');

    if (wo_ops_is_locked($conn, $mo)) {
      throw new Exception(wo_ops_lock_reason($conn, $mo));
    }

    $oldStatusForCancellation = strtolower((string)($mo['status'] ?? ''));
    $newStatusForCancellation = $status !== null ? strtolower((string)$status) : $oldStatusForCancellation;
    $isNewCancellation = ($oldStatusForCancellation !== 'cancelled' && $newStatusForCancellation === 'cancelled');
    $cancellationCategory = '';
    $cancellationDetails = '';
    $cancellationSummary = '';
    if ($isNewCancellation) {
      [$canCancel, $cancelBlock] = wo_ops_can_direct_cancel($conn, $order_id);
      if (!$canCancel) {
        throw new Exception($cancelBlock !== '' ? $cancelBlock : 'Direct cancel is not allowed for this work order.');
      }
      $cancellationCategory = cleaning_order_cancel_normalize_category((string)($_POST['cancellation_category'] ?? ''));
      $cancellationDetails = trim((string)($_POST['cancellation_details'] ?? ''));
      if ($cancellationCategory === '') {
        throw new Exception('Cancellation category is required.');
      }
      if ($cancellationDetails === '') {
        throw new Exception('Cancellation details are required.');
      }
      $cancellationSummary = cleaning_order_cancel_summary($cancellationCategory, $cancellationDetails);
    }

    $driver_id = ($driver_id_in === '' ? null : ($driver_id_in !== null ? (int)$driver_id_in : null));
    $driver_name = null;
    if ($driver_id) {
      $dn = $conn->prepare("SELECT nickname FROM driver WHERE id=?");
      $dn->execute([$driver_id]);
      $driver_name = (string)$dn->fetchColumn() ?: null;
    }

    // Handle time, date, and worker updates
    $times_changed = false;
    $date_changed = false;
    $workers_changed = false;
    
    // Check if date is being updated
    // Note: svc_date_calc is a GENERATED column, so we only update service_date
    if ($service_date !== null && $service_date !== $mo['service_date']) {
      $date_changed = true;
      $mo['service_date'] = $service_date;
      // Don't set svc_date_calc - it's auto-generated from service_date
    }
    
    // Check if times are being updated
    if ($start_time !== null || $end_time !== null) {
      $new_start = $start_time ?: $mo['start_time'];
      $new_end = $end_time ?: $mo['end_time'];
      
      if ($new_start !== $mo['start_time'] || $new_end !== $mo['end_time']) {
        $times_changed = true;
        $mo['start_time'] = $new_start;
        $mo['end_time'] = $new_end;
      }
    }
    
    // Check if workers are being updated
    if ($worker_ids !== null) {
      $current_workers = $conn->prepare("SELECT worker_id FROM order_workers WHERE order_id = ? ORDER BY id");
      $current_workers->execute([$order_id]);
      $current_worker_ids = array_map('intval', $current_workers->fetchAll(PDO::FETCH_COLUMN));
      
      if (array_diff($current_worker_ids, $worker_ids) || array_diff($worker_ids, $current_worker_ids)) {
        $workers_changed = true;
      }
    }

    // Recalc totals with updated times/workers if changed
    // Use NEW worker count if workers are being updated, otherwise use current count from database
    if ($worker_ids !== null && count($worker_ids) > 0) {
      $num_cleaners = max(1, count($worker_ids));
    } else {
      $wq = $conn->prepare("SELECT COUNT(*) FROM order_workers WHERE order_id=?");
      $wq->execute([$order_id]);
      $num_cleaners = max(1,(int)$wq->fetchColumn());
    }

    $mins = max(0, hhmmToMin($mo['end_time']) - hhmmToMin($mo['start_time']));
    if ($mins===0 && $mo['time']) { [$s2,$e2] = parseLegacyRange($mo['time']); $mins=max(0, hhmmToMin((string)$e2)-hhmmToMin((string)$s2)); }
    $durPerCleaner = round($mins/60, 2);
    $hoursBooking  = round($durPerCleaner * $num_cleaners, 2);

      $resolved = wo_resolve_order_rate($mo);
      $rate = $hourly_rate !== null && $hourly_rate > 0
        ? $hourly_rate
        : $resolved['rate'];
      $rateIsFlat = ($hourly_rate === null || $hourly_rate <= 0) && !empty($resolved['flat']);

      $vr = (float)($mo['vat_rate'] ?: $mo['default_vat_rate'] ?: 5.0);

      // Prefer posted VAT mode, then stored vat_included (never infer from totals — see wo_resolve_vat_mode).
      $mode = wo_resolve_vat_mode($mo, $vat_included);

      $bookingCategoryIds = wo_pricing_parse_booking_categories($conn, $_POST);
      if (!$bookingCategoryIds && $service_category_in) {
          $bookingCategoryIds = [$service_category_in];
      }
      if (!$bookingCategoryIds && !empty($mo['service_category_id'])) {
          $bookingCategoryIds = sm_resolve_booking_category_ids(
              $conn,
              $mo['booking_categories'] ?? null,
              (int)$mo['service_category_id']
          );
      }
      $cleaningCat = sm_get_category_by_code($conn, 'cleaning');
      $cleaningCatId = $cleaningCat ? (int)$cleaningCat['id'] : 0;
      $includeCleaning = $cleaningCatId > 0 && in_array($cleaningCatId, $bookingCategoryIds, true);
      $catalogLines = wo_pricing_parse_catalog_lines($conn, $_POST);
      $hasCatalog = !empty($catalogLines);
      $needMaterialsVal = ($need_materials_in === null)
          ? (int)($mo['need_materials'] ?? 0)
          : (int)$need_materials_in;

      if ($includeCleaning) {
          if ($rate <= 0) {
              throw new Exception('Hourly rate is required for cleaning.');
          }
      }
      if (!$includeCleaning && !$hasCatalog && empty($svc_ids)) {
          // Legacy edit: hours × rate only (single cleaning order)
          $includeCleaning = true;
      }
      if (!$includeCleaning && !$hasCatalog) {
          throw new Exception('Select at least one service and add catalog items, or include Cleaning with a valid hourly rate.');
      }

      $materialsRate = 0.0;
      if ($includeCleaning && $cleaningCat) {
          $materialsRate = round((float)($cleaningCat['materials_rate_per_hour'] ?? 0), 2);
      }

      $pricing = wo_pricing_compute_booking([
          'vat_rate' => $vr,
          'vat_included' => $mode,
          'hourly_rate' => $rate,
          // Flat-fee WOs (ARS): fee_charged is the job total, not an hourly rate.
          'hours_booking' => !empty($rateIsFlat) ? 1.0 : $hoursBooking,
          'need_materials' => (bool)$needMaterialsVal,
          'materials_rate_per_hour' => $materialsRate,
          'include_cleaning' => $includeCleaning,
          'catalog_lines' => $catalogLines,
      ]);

      $sub = $pricing['subtotal'];
      $vat = $pricing['vat_amount'];
      $tot = $pricing['grand_total'] ?? ($pricing['total'] ?? ($sub + $vat));

      // ARS / flat fee_charged: never overwrite money from hourly quote math.
      if (!empty($rateIsFlat)) {
        $sub = round((float)($mo['total'] ?? 0), 2);
        if ($sub <= 0.009) {
          $sub = round($rate, 2);
        }
        $vat = round((float)($mo['vat_amount'] ?? 0), 2);
        $tot = round((float)($mo['grand_total'] ?? 0), 2);
        if ($tot <= 0.009) {
          $tot = round($sub + $vat, 2);
        }
        if ($tot <= 0.009) {
          $tot = $sub;
        }
        if (!empty($mo['ars_booking_id']) && $rate > 0.009
            && $tot > ($rate + 0.02) && abs($sub - $rate) < 0.02) {
          $sub = round($rate, 2);
          $vat = 0.0;
          $tot = $sub;
        }
      }
      $orderServiceRows = $pricing['order_service_rows'];
      $useServices = !empty($orderServiceRows);
      $resolvedPrimaryCat = sm_resolve_primary_category_id($conn, $bookingCategoryIds)
          ?: ($service_category_in ? sm_resolve_service_category_id($conn, $service_category_in) : null);
      $bookingCategoriesJson = $bookingCategoryIds
          ? json_encode(array_values($bookingCategoryIds), JSON_THROW_ON_ERROR)
          : null;
      
      if (wo_financial_is_locked($conn, $order_id) && !$isNewCancellation) {
        $moneyWouldChange =
          abs((float)$mo['grand_total'] - $tot) > 0.02
          || abs((float)$mo['total'] - $sub) > 0.02
          || ($hourly_rate !== null && abs($hourly_rate - wo_resolve_order_rate($mo)['rate']) > 0.009)
          || $useServices;
        // Schedule / worker / date changes are operational — allowed while money is locked.
        if ($moneyWouldChange) {
          throw new Exception(wo_financial_lock_reason($conn, $order_id) ?: 'This work order is financially locked. Financial changes require an Accounts adjustment.');
        }
        // Keep posted invoice amounts; still allow ops schedule fields to update below.
        $sub = (float)$mo['total'];
        $vat = (float)$mo['vat_amount'];
        $tot = (float)$mo['grand_total'];
        $orderServiceRows = [];
        $useServices = false;
        $hourly_rate = null;
        $vat_included = null;
      }

      // 🔴 CREDIT CONTROL (EDIT transition)
      $override = !empty($_POST['override_credit_limit']);
      $oldConfirmed = (strtolower((string)$mo['status']) === 'confirmed');
      $newConfirmed = ($status !== null && strtolower((string)$status) === 'confirmed');

      if (!$oldConfirmed && $newConfirmed && !$override) {
        $clientId = (int)$mo['client_id'];
        // exclude this order from outstanding to avoid double count if it already has an invoice
          $snap = credit_snapshot($conn, $clientId, $order_id);
          if (credit_would_exceed_available($snap['available'], (float)$tot)) {
            http_response_code(400);
            echo json_encode([
              'success'=>false,
              'error'=>sprintf(
                'Credit limit warning: outstanding %.2f, unapplied %.2f, available %.2f; this order %.2f would exceed the limit of %.2f.',
                $snap['outstanding'], $snap['unapplied'], $snap['available'], (float)$tot, $snap['credit_limit']
              )
            ]);
            exit;
          }
      }

      // Check if vat_included column exists
      $hasVatIncluded = false;
      try {
        $chk = $conn->query("SHOW COLUMNS FROM make_order LIKE 'vat_included'");
        $hasVatIncluded = ($chk && $chk->rowCount() > 0);
      } catch (Exception $e) {
        $hasVatIncluded = false;
      }
      
      // Build dynamic SET list so we don't clobber driver fields with NULLs
      $setParts = [
        'status = COALESCE(:status, status)',
        'remark = COALESCE(:remark, remark)',
        'need_materials = COALESCE(:need_materials, need_materials)',
        'materials_note = COALESCE(:materials_note, materials_note)',
        'hourly_rate = COALESCE(:rate, hourly_rate)',
        'hours = :hours',
        'net_hours = :net_hours',
        'net_amount = :net_amount',
        'total = :sub',
        'amount_afc = :sub',
        'vat_rate = :vr',
        'vat_amount = :vat',
        'grand_total = :tot',
      ];
      
      // Only update vat_included if column exists
      if ($hasVatIncluded) {
        $setParts[] = 'vat_included = COALESCE(:vat_included, vat_included)';
      }
      
      // Add time fields if they're being updated
      if ($times_changed) {
        $setParts[] = 'start_time = :start_time';
        $setParts[] = 'end_time = :end_time';
        $setParts[] = 'time = :legacy_time';
      }
      
      // Add date fields if date is being updated
      // Note: Only update service_date; svc_date_calc is GENERATED and auto-updates
      if ($date_changed) {
        $setParts[] = 'service_date = :service_date';
      }

      if ($isNewCancellation) {
        $setParts[] = 'cancel_reason = :cancel_reason';
        $setParts[] = 'cancellation_category = :cancellation_category';
        $setParts[] = 'cancellation_details = :cancellation_details';
        $setParts[] = 'cancelled_at = NOW()';
        $setParts[] = 'cancelled_by = :cancelled_by';
      }

      if ($resolvedPrimaryCat && sm_make_order_has_service_category($conn)) {
        $setParts[] = 'service_category_id = :service_category_id';
      }
      if ($bookingCategoriesJson && sm_make_order_has_booking_categories($conn)) {
        $setParts[] = 'booking_categories = :booking_categories';
      }

      // Only touch driver fields if driver_id was actually provided in POST
      $touchDriver = array_key_exists('driver_id', $_POST);

      $params = [
        ':status'         => $status,
        ':remark'         => $remark,
        ':need_materials' => ($need_materials_in === null ? null : (int)$need_materials_in),
        ':materials_note' => ($materials_note_in === null ? null : trim($materials_note_in)),
        ':rate'           => $hourly_rate,
        ':hours'          => $hoursBooking,
        ':net_hours'      => $durPerCleaner,
        ':net_amount'     => round($durPerCleaner * $rate, 2),
        ':sub'            => $sub,
        ':vr'             => $vr,
        ':vat'            => $vat,
        ':tot'            => $tot,
        ':id'             => $order_id,
      ];
      
      // Only add vat_included param if column exists
      if ($hasVatIncluded) {
        $params[':vat_included'] = $vat_included;
      }
      
      // Add time parameters if times are being updated
      if ($times_changed) {
        $params[':start_time'] = $mo['start_time'];
        $params[':end_time'] = $mo['end_time'];
        $params[':legacy_time'] = timeToLegacy($mo['start_time']) . ' To ' . timeToLegacy($mo['end_time']);
      }
      
      // Add date parameters if date is being updated
      if ($date_changed) {
        $params[':service_date'] = $mo['service_date'];
      }
      if ($isNewCancellation) {
        $params[':cancel_reason'] = $cancellationSummary;
        $params[':cancellation_category'] = $cancellationCategory;
        $params[':cancellation_details'] = $cancellationDetails;
        $params[':cancelled_by'] = current_user_id();
      }
      if ($resolvedPrimaryCat && sm_make_order_has_service_category($conn)) {
        $params[':service_category_id'] = $resolvedPrimaryCat;
      }
      if ($bookingCategoriesJson && sm_make_order_has_booking_categories($conn)) {
        $params[':booking_categories'] = $bookingCategoriesJson;
      }

      if ($touchDriver) {
        // If driver_id = '' (clear), keep NULL in id but set name to empty string (NOT NULL-safe)
        // If driver_id is a real id, we already looked up $driver_name above
        $driver_name_for_update = ($driver_id === null ? '' : (string)$driver_name);

        $setParts[] = 'driver_id = :driver_id';
        $setParts[] = 'driver_name = :driver_name';
        $params[':driver_id']   = $driver_id;                 // NULL allowed in column? (yes)
        $params[':driver_name'] = $driver_name_for_update;    // never NULL (avoids 1048)
      }

      $sql = "UPDATE make_order SET " . implode(', ', $setParts) . " WHERE id = :id";
      $up = $conn->prepare($sql);
      $up->execute($params);

      // Sync booking status if status changed and order is linked to a booking
      $oldStatusForSync = $mo['status'] ?? null;
      if ($status !== null && strtolower((string)$status) !== strtolower((string)$oldStatusForSync)) {
        try {
          $bookingStmt = $conn->prepare("
            SELECT id, status 
            FROM online_bookings 
            WHERE work_order_id = ?
          ");
          $bookingStmt->execute([$order_id]);
          $booking = $bookingStmt->fetch(PDO::FETCH_ASSOC);
          
          if ($booking) {
            // Map order status to booking status
            $bookingStatusMap = [
              'draft' => 'pending',
              'scheduled' => 'confirmed',
              'confirmed' => 'assigned',
              'in_progress' => 'in_progress',
              'completed' => 'completed',
              'invoiced' => 'completed',
              'cancelled' => 'cancelled'
            ];
            
            $newBookingStatus = $bookingStatusMap[strtolower((string)$status)] ?? $booking['status'];
            
            // Only update if status actually changed
            if ($newBookingStatus !== $booking['status']) {
              $updateBookingStmt = $conn->prepare("
                UPDATE online_bookings 
                SET status = ?, updated_at = NOW()
                WHERE id = ?
              ");
              $updateBookingStmt->execute([$newBookingStatus, $booking['id']]);
              
              // Log booking status change
              $eventStmt = $conn->prepare("
                INSERT INTO booking_events (booking_id, event_type, event_data, user_id)
                VALUES (?, 'status_changed', ?, ?)
              ");
              $eventStmt->execute([
                $booking['id'],
                json_encode([
                  'old_status' => $booking['status'],
                  'new_status' => $newBookingStatus,
                  'triggered_by' => 'order_status_change',
                  'order_id' => $order_id,
                  'order_status' => $status
                ]),
                current_user_id()
              ]);
            }
          }
        } catch (\Throwable $e) {
          // Don't fail order update if booking sync fails
          error_log("Warning: Failed to sync booking status - " . $e->getMessage());
        }
      }

      // 🔴 INVOICE & GL UPDATE: If date changed, update invoice and GL postings
      if ($date_changed) {
        $invoice_id = (int)($mo['invoice_id'] ?? 0);
        if ($invoice_id > 0) {
          // Get invoice details
          $invSt = $conn->prepare("SELECT * FROM invoices WHERE id = ?");
          $invSt->execute([$invoice_id]);
          $invoice = $invSt->fetch(PDO::FETCH_ASSOC);
          
          if ($invoice) {
            // Get client payment terms to calculate new due date
            $clientSt = $conn->prepare("SELECT terms FROM client WHERE id = ?");
            $clientSt->execute([$mo['client_id']]);
            $client = $clientSt->fetch(PDO::FETCH_ASSOC);
            
            $terms = $client['terms'] ?? 'cash';
            
            // Calculate new due date based on new service date and client payment terms
            $new_issue_date = $mo['service_date'];
            $new_due_date = $new_issue_date;
            
            // Handle terms like '15d', '30d', '45d', '60d'
            if (preg_match('/^(\d+)d$/', $terms, $m)) {
              $days = (int)$m[1];
              $new_due_date = date('Y-m-d', strtotime($new_issue_date . " +{$days} days"));
            } elseif ($terms === 'prepaid') {
              // Prepaid - due date same as issue date
              $new_due_date = $new_issue_date;
            } else {
              // cash or other - immediate payment, same day
              $new_due_date = $new_issue_date;
            }
            
            // Update invoice dates
            $updateInvoice = $conn->prepare("
              UPDATE invoices 
              SET issue_date = :new_issue_date, 
                  due_date = :new_due_date
              WHERE id = :invoice_id
            ");
            $updateInvoice->execute([
              ':new_issue_date' => $new_issue_date,
              ':new_due_date' => $new_due_date,
              ':invoice_id' => $invoice_id
            ]);
            
            // Update GL journal entries if they exist
            require_once __DIR__ . '/../includes/gl_posting.php';
            $jids = gl_find_invoice_journals($conn, $invoice_id);
            foreach ($jids as $jid) {
              $conn->prepare("UPDATE gl_journals SET journal_date = ? WHERE id = ?")->execute([$new_issue_date, $jid]);
            }
          }
        }
      }

      // 🔴 INVOICE VOIDING: If status changed to 'cancelled', void the invoice
      $oldStatusForInvoice = strtolower((string)$mo['status']);
      $newStatusForInvoice = $status ? strtolower((string)$status) : $oldStatusForInvoice;
      
      if ($oldStatusForInvoice !== 'cancelled' && $newStatusForInvoice === 'cancelled') {
        // Defense in depth: only void non-activity invoices (direct cancel already gated).
        $invSt = $conn->prepare("SELECT * FROM invoices WHERE order_id=? AND status <> 'void' LIMIT 1");
        $invSt->execute([$order_id]);
        $inv = $invSt->fetch(PDO::FETCH_ASSOC);

        if ($inv) {
          $inv_id = (int)$inv['id'];
          $inv_status = strtolower((string)($inv['status'] ?? ''));
          if (in_array($inv_status, ['issued', 'paid', 'partially_paid'], true)) {
            throw new Exception("Invoice {$inv['invoice_no']} is {$inv_status}. Use Accounts void/credit/refund — not Operations cancel.");
          }

          $allocAmt = (float)$conn->query("SELECT COALESCE(SUM(amount_applied),0) FROM receipt_allocations WHERE invoice_id={$inv_id}")
                              ->fetchColumn();

          if ($allocAmt > 0) {
            throw new Exception("Invoice {$inv['invoice_no']} has payments/allocations. Unallocate/refund first.");
          }

          $voidInv = $conn->prepare("UPDATE invoices SET status='void', updated_at=NOW() WHERE id=?");
          $voidInv->execute([$inv_id]);
          ar_post_or_repost_invoice($conn, $inv_id);
        }
      }

      // Audit Log: Track order update via API
      require_once __DIR__ . '/../includes/AuditService.php';
      $actorId = current_user_id();
      AuditService::logUpdate('make_order', $order_id, $mo, [
        'status' => $status,
        'driver_id' => $driver_id,
        'hourly_rate' => $hourly_rate,
        'remark' => $remark,
        'start_time' => $start_time,
        'end_time' => $end_time,
        'service_date' => $service_date,
        'cancellation_category' => $isNewCancellation ? $cancellationCategory : null,
        'cancellation_details' => $isNewCancellation ? $cancellationDetails : null
      ], "Updated order #{$order_id} via API", $actorId ? (int)$actorId : null);

      // Update workers if changed
      if ($workers_changed && $worker_ids !== null) {
        $conn->prepare("DELETE FROM order_workers WHERE order_id = ?")->execute([$order_id]);
        $insOW = $conn->prepare("INSERT INTO order_workers (order_id, worker_id) VALUES (?, ?)");
        foreach ($worker_ids as $wid) {
          $insOW->execute([$order_id, $wid]);
        }
        
        // Update worker_name field with comma-separated list of worker nicknames
        if (!empty($worker_ids)) {
          $placeholders = implode(',', array_fill(0, count($worker_ids), '?'));
          $wn = $conn->prepare("SELECT nickname FROM workers WHERE id IN ($placeholders) ORDER BY id");
          $wn->execute($worker_ids);
          $worker_names = $wn->fetchAll(PDO::FETCH_COLUMN);
          $worker_name_str = implode(' , ', $worker_names);
          
          $upWorkerName = $conn->prepare("UPDATE make_order SET worker_name = ? WHERE id = ?");
          $upWorkerName->execute([$worker_name_str, $order_id]);
        }
      }
      

      // Handle overtime recalculation if times, date, or workers changed
      $overtime_entries_updated = [];
      $overtime_remark = '';
      if (($times_changed || $workers_changed || $date_changed) && !empty($worker_ids)) {
        // Delete existing overtime entries for this order
        $conn->prepare("DELETE FROM overtime_entries WHERE notes LIKE ?")->execute(["%Order #{$order_id}%"]);
        
        // Recalculate overtime for all workers
        $service_date = $mo['service_date'];
        $duration_hours = $durPerCleaner;
        
        foreach ($worker_ids as $worker_id) {
          // Get worker's daily capacity
          $capQ = $conn->prepare("SELECT COALESCE(daily_cap_hours,0) FROM workers WHERE id = ?");
          $capQ->execute([$worker_id]);
          $capacity = (float)$capQ->fetchColumn();
          
          if ($capacity > 0) {
            // Calculate total hours for this worker on this date (excluding current order)
            $sumQ = $conn->prepare("
              SELECT COALESCE(SUM(mo.hours / ow_count.cnt), 0)
              FROM make_order mo
              JOIN order_workers ow ON ow.order_id = mo.id
              JOIN (SELECT order_id, COUNT(*) as cnt FROM order_workers GROUP BY order_id) ow_count ON ow_count.order_id = mo.id
              WHERE ow.worker_id = ?
                AND mo.svc_date_calc = ?
                AND COALESCE(mo.status, '') <> 'cancelled'
                AND mo.id != ?
            ");
            $sumQ->execute([$worker_id, $service_date, $order_id]);
            $already_worked = (float)$sumQ->fetchColumn();
            
            $total_hours = $already_worked + $duration_hours;
            if ($total_hours > $capacity + 0.0001) {
              $overtime_hours = $total_hours - $capacity;
              
              $overtime_entry = createOvertimeEntry(
                $conn, 
                $worker_id, 
                $service_date, 
                $mo['start_time'], 
                $mo['end_time'], 
                $overtime_hours, 
                $rate, 
                $order_id, 
                current_user_id()
              );
              
              if ($overtime_entry) {
                $overtime_entries_updated[] = $overtime_entry;
                
                // Build overtime remark
                if ($overtime_remark) $overtime_remark .= ' ';
                $overtime_remark .= "[OVERTIME: {$overtime_entry['hours']}h]";
              }
            }
          }
        }
        
        // Update remark with overtime information if not manually set
        if ($overtime_remark && $remark === null) {
          $current_remark = $mo['remark'] ?? '';
          // Remove old overtime info if it exists
          $current_remark = preg_replace('/\s*\[OVERTIME:[^\]]+\]/', '', $current_remark);
          $new_remark = trim($current_remark . ' ' . $overtime_remark);
          
          $upRemark = $conn->prepare("UPDATE make_order SET remark = ? WHERE id = ?");
          $upRemark->execute([$new_remark, $order_id]);
        }
      }

      // Sync order_services for invoice line items at finalize
      if (!empty($orderServiceRows)) {
        $conn->prepare("DELETE FROM order_services WHERE order_id = ?")->execute([$order_id]);
        $insSvc = $conn->prepare("
          INSERT INTO order_services (order_id, service_id, service_name, description, qty, unit, unit_price, vat_rate)
          VALUES (?,?,?,?,?,?,?,?)
        ");
        foreach ($orderServiceRows as $sr) {
          $insSvc->execute([
            $order_id,
            $sr['service_id'] ?? null,
            $sr['service_name'],
            $sr['description'] ?? null,
            $sr['qty'],
            $sr['unit'],
            $sr['unit_price'],
            $sr['vat_rate']
          ]);
        }
      }

      // Invoice/GL sync if money changed
      $invoice_id = null;
      $recalcChangedMoney =
        (float)$mo['total']       !== (float)$sub ||
        (float)$mo['vat_amount']  !== (float)$vat ||
        (float)$mo['grand_total'] !== (float)$tot;

      if (!$isNewCancellation && ($recalcChangedMoney || $date_changed || empty($mo['invoice_id']))) {
        try {
          $invoice_id = wo_maybe_sync_invoice_after_order_change($conn, $order_id, current_user_id(), true);
          if ($invoice_id) {
            $conn->prepare("UPDATE make_order SET invoice_id = :iid WHERE id = :id")
                 ->execute([':iid'=>$invoice_id, ':id'=>$order_id]);
          }
        } catch (Throwable $glx) {
          if (strpos($glx->getMessage(), 'Duplicate entry') === false) { throw $glx; }
        }
      }
      if ($status) {
        wo_sync_ops_status_column($conn, $order_id, (string)$status);
      }
      
      $actor = current_user_id();
      audit_order($conn, $order_id, $actor, 'edit', $mo, [
        'status'=>$status, 'driver_id'=>$driver_id, 'hourly_rate'=>$hourly_rate, 'remark'=>$remark,
        'recalc'=>['sub'=>$sub,'vat'=>$vat,'tot'=>$tot]
      ]);

      if ($status === 'confirmed') {
        nt_notify_driver_for_order($conn, $order_id, 'driver.order.confirmed');
      } else {
        nt_notify_driver_for_order($conn, $order_id, 'driver.order.changed');
      }

    echo json_encode([
      'success'=>true,
      'order_id'=>$order_id,
      'invoice_id'=>$invoice_id,
      'overtime_entries_updated'=>$overtime_entries_updated,
      'times_changed'=>$times_changed,
      'workers_changed'=>$workers_changed
    ]); exit;
  }

  /* ---------- UPDATE (resize/move/reassign) ---------- */
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
    $order_id     = (int)($_POST['order_id'] ?? 0);
    if ($order_id <= 0) throw new Exception('Missing order id');

    $start_time   = $_POST['start_time'] ?? null;
    $end_time     = $_POST['end_time']   ?? null;
    $status       = $_POST['status']     ?? null;
    $new_workerId = isset($_POST['new_worker_id']) ? (int)$_POST['new_worker_id'] : 0;

    $st = $conn->prepare("
      SELECT mo.*, c.default_vat_rate, c.rate AS client_rate
      FROM make_order mo
      LEFT JOIN client c ON c.id = mo.client_id
      WHERE mo.id = ?
    ");
    $st->execute([$order_id]);
    $mo = $st->fetch(PDO::FETCH_ASSOC);
    if (!$mo) throw new Exception('Order not found');

    if (wo_ops_is_locked($conn, $mo)) {
      throw new Exception(wo_ops_lock_reason($conn, $mo) ?: 'This work order is finalized and locked. Cannot move or resize on the calendar.');
    }

    $svcDate  = (string)($mo['service_date'] ?: $mo['date']);
    $newStart = $start_time ?: $mo['start_time'];
    $newEnd   = $end_time   ?: $mo['end_time'];
    if (!$newStart || !$newEnd) { [$s2,$e2] = parseLegacyRange($mo['time'] ?? ''); $newStart = $newStart ?: $s2; $newEnd = $newEnd ?: $e2; }
    if (!$newStart || !$newEnd) throw new Exception('Invalid time values');

    // Overlap checks / unavailability / travel-gap
    $workersToCheck = [];
    if ($new_workerId > 0) {
      $workersToCheck = [$new_workerId];
    } else {
      $wk = $conn->prepare("SELECT worker_id FROM order_workers WHERE order_id=?");
      $wk->execute([$order_id]);
      $workersToCheck = array_map('intval', $wk->fetchAll(PDO::FETCH_COLUMN));
    }

    foreach ($workersToCheck as $wid) {
      $conf = findOverlap($conn, $wid, $svcDate, $newStart, $newEnd, $order_id);
      if ($conf) {
        $cStart = $conf['start_time'] ?: '?';
        $cEnd   = $conf['end_time']   ?: '?';
        $oid    = (int)$conf['order_id'];
        throw new Exception("Overlap: {$newStart}–{$newEnd} conflicts with Order #{$oid} ({$cStart}–{$cEnd}).");
      }
      if (hasUnavailability($conn, $wid, $svcDate, $newStart, $newEnd)) {
        throw new Exception("Worker #{$wid} is unavailable during {$newStart}–{$newEnd}.");
      }
      if (violatesTravelGap($conn, $wid, $svcDate, $newStart, $newEnd, $order_id)) {
        throw new Exception("Worker #{$wid}: not enough travel buffer before/after this job.");
      }
    }

    // recalc hours/totals
    $wq = $conn->prepare("SELECT COUNT(*) FROM order_workers WHERE order_id=?");
    $wq->execute([$order_id]);
    $num_cleaners = max(1,(int)$wq->fetchColumn());

    $mins = max(0, hhmmToMin($newEnd) - hhmmToMin($newStart));
    $durPerCleaner = round($mins/60, 2);
    $hoursBooking  = round($durPerCleaner * $num_cleaners, 2);

      $feeInfo = wo_resolve_order_rate($mo);
      $fee    = $feeInfo['rate'];
      $vatRate= (float)($mo['vat_rate'] ?: $mo['default_vat_rate'] ?: 5.0);

      // Use stored vat_included — do not infer from totals (that flips inclusive → add VAT).
      $mode = wo_resolve_vat_mode($mo);

      if (!empty($feeInfo['flat'])) {
        // Flat-fee jobs (ARS checkout cleaning): fee_charged is the job amount.
        // Preserve stored money — do not invent VAT or multiply by duration.
        // (Hourly cleaning jobs continue to use the branches below.)
        $sub = round((float)($mo['total'] ?? 0), 2);
        if ($sub <= 0.009) {
          $sub = round($fee, 2);
        }
        $vat = round((float)($mo['vat_amount'] ?? 0), 2);
        $tot = round((float)($mo['grand_total'] ?? 0), 2);
        if ($tot <= 0.009) {
          $tot = round($sub + $vat, 2);
        }
        if ($tot <= 0.009) {
          $tot = $sub;
        }
        // ARS: fee_charged is the agreed amount. Snap back if grand was inflated (e.g. 50→52.50).
        if (!empty($mo['ars_booking_id']) && $fee > 0.009
            && $tot > ($fee + 0.02) && abs($sub - $fee) < 0.02) {
          $sub = round($fee, 2);
          $vat = 0.0;
          $tot = $sub;
        }
      } elseif ($mode === 'yes') {
        $gross   = round($fee * $hoursBooking, 2);
        $netRate = ($vatRate > 0) ? round($fee / (1 + $vatRate/100), 4) : $fee;
        $sub     = round($netRate * $hoursBooking, 2);
        $vat     = round($gross - $sub, 2);
        $tot     = $gross;
      } else {
        $sub = round($fee * $hoursBooking, 2);
        $vat = round($sub * ($vatRate/100), 2);
        $tot = round($sub + $vat, 2);
      }

    // Get old status before update
    $oldStatus = $mo['status'] ?? null;
    $newStatus = $status ?: $oldStatus;
    $isUpdateCancellation = strtolower((string)$oldStatus) !== 'cancelled' && strtolower((string)$newStatus) === 'cancelled';

    if ($isUpdateCancellation) {
      [$canCancel, $cancelBlock] = wo_ops_can_direct_cancel($conn, $order_id);
      if (!$canCancel) {
        throw new Exception($cancelBlock !== '' ? $cancelBlock : 'Direct cancel is not allowed for this work order.');
      }
    }

    $financialLockedUpdate = wo_financial_is_locked($conn, $order_id);
    if ($financialLockedUpdate && !$isUpdateCancellation) {
      // Ops may move/resize/reassign; freeze money columns to the existing invoice amounts.
      $sub = (float)$mo['total'];
      $vat = (float)$mo['vat_amount'];
      $tot = (float)$mo['grand_total'];
    }

    $updateCancellationCategory = '';
    $updateCancellationDetails = '';
    $updateCancellationSummary = '';
    if ($isUpdateCancellation) {
      $updateCancellationCategory = cleaning_order_cancel_normalize_category((string)($_POST['cancellation_category'] ?? ''));
      $updateCancellationDetails = trim((string)($_POST['cancellation_details'] ?? ''));
      if ($updateCancellationCategory === '') throw new Exception('Cancellation category is required.');
      if ($updateCancellationDetails === '') throw new Exception('Cancellation details are required.');
      $updateCancellationSummary = cleaning_order_cancel_summary($updateCancellationCategory, $updateCancellationDetails);
    }
    
    // apply update
    $cancelSql = $isUpdateCancellation
      ? ", cancel_reason = :cancel_reason, cancellation_category = :cancellation_category, cancellation_details = :cancellation_details, cancelled_at = NOW(), cancelled_by = :cancelled_by"
      : "";
    $up = $conn->prepare("
      UPDATE make_order SET
        start_time = :s, end_time = :e, `time` = :legacy,
        status = COALESCE(:st,status),
        hours = :hours, net_hours = :net_hours, net_amount = :net_amount,
        total = :sub, amount_afc = :sub, vat_rate = :vr, vat_amount = :vat, grand_total = :tot
        {$cancelSql}
      WHERE id = :id
    ");
    $updateParams = [
      ':s'=>$newStart, ':e'=>$newEnd, ':legacy'=>timeToLegacy($newStart).' To '.timeToLegacy($newEnd),
      ':st'=>$status, ':hours'=>$hoursBooking, ':net_hours'=>$durPerCleaner, ':net_amount'=>round($durPerCleaner * $fee, 2),
      ':sub'=>$sub, ':vr'=>$vatRate, ':vat'=>$vat, ':tot'=>$tot, ':id'=>$order_id
    ];
    if ($isUpdateCancellation) {
      $updateParams[':cancel_reason'] = $updateCancellationSummary;
      $updateParams[':cancellation_category'] = $updateCancellationCategory;
      $updateParams[':cancellation_details'] = $updateCancellationDetails;
      $updateParams[':cancelled_by'] = current_user_id();
    }
    $up->execute($updateParams);

    // Sync booking status if status changed and order is linked to a booking
    if ($newStatus !== $oldStatus) {
      try {
        $bookingStmt = $conn->prepare("
          SELECT id, status 
          FROM online_bookings 
          WHERE work_order_id = ?
        ");
        $bookingStmt->execute([$order_id]);
        $booking = $bookingStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($booking) {
          // Map order status to booking status
          $bookingStatusMap = [
            'draft' => 'pending',
            'scheduled' => 'confirmed',
            'confirmed' => 'assigned',
            'in_progress' => 'in_progress',
            'completed' => 'completed',
            'invoiced' => 'completed',
            'cancelled' => 'cancelled'
          ];
          
          $newBookingStatus = $bookingStatusMap[strtolower((string)$newStatus)] ?? $booking['status'];
          
          // Only update if status actually changed
          if ($newBookingStatus !== $booking['status']) {
            $updateBookingStmt = $conn->prepare("
              UPDATE online_bookings 
              SET status = ?, updated_at = NOW()
              WHERE id = ?
            ");
            $updateBookingStmt->execute([$newBookingStatus, $booking['id']]);
            
            // Log booking status change
            $eventStmt = $conn->prepare("
              INSERT INTO booking_events (booking_id, event_type, event_data, user_id)
              VALUES (?, 'status_changed', ?, ?)
            ");
            $eventStmt->execute([
              $booking['id'],
              json_encode([
                'old_status' => $booking['status'],
                'new_status' => $newBookingStatus,
                'triggered_by' => 'order_status_change',
                'order_id' => $order_id,
                'order_status' => $newStatus
              ]),
              current_user_id()
            ]);
          }
        }
      } catch (\Throwable $e) {
        // Don't fail order update if booking sync fails
        error_log("Warning: Failed to sync booking status - " . $e->getMessage());
      }
    }

    if ($isUpdateCancellation) {
      $invSt = $conn->prepare("SELECT * FROM invoices WHERE order_id=? LIMIT 1");
      $invSt->execute([$order_id]);
      $inv = $invSt->fetch(PDO::FETCH_ASSOC);
      if ($inv) {
        $inv_id = (int)$inv['id'];
        $allocAmt = (float)$conn->query("SELECT COALESCE(SUM(amount_applied),0) FROM receipt_allocations WHERE invoice_id={$inv_id}")->fetchColumn();
        if (in_array($inv['status'], ['paid','partially_paid'], true) || $allocAmt > 0) {
          throw new Exception("Invoice {$inv['invoice_no']} has payments/allocations. Unallocate/refund first.");
        }
        $conn->prepare("UPDATE invoices SET status='void', updated_at=NOW() WHERE id=?")->execute([$inv_id]);
        ar_post_or_repost_invoice($conn, $inv_id);
      }
    }

    // Audit Log: Track order time change via API
    require_once __DIR__ . '/../includes/AuditService.php';
    $actorId = current_user_id();
    AuditService::logUpdate('make_order', $order_id, $mo, [
      'start_time' => $newStart,
      'end_time' => $newEnd,
      'status' => $status,
      'hours' => $hoursBooking,
      'total' => $tot,
      'cancellation_category' => $isUpdateCancellation ? $updateCancellationCategory : null,
      'cancellation_details' => $isUpdateCancellation ? $updateCancellationDetails : null
    ], "Changed time for order #{$order_id} via API", $actorId ? (int)$actorId : null);

    if ($new_workerId > 0) {
      $conn->prepare("DELETE FROM order_workers WHERE order_id=?")->execute([$order_id]);
      $conn->prepare("INSERT INTO order_workers (order_id, worker_id) VALUES (?, ?)")->execute([$order_id, $new_workerId]);
    }

    // invoice/GL sync if money changed
      $invoice_id = null;
      $recalcChangedMoney =
        (float)$mo['total']       !== (float)$sub ||
        (float)$mo['vat_amount']  !== (float)$vat ||
        (float)$mo['grand_total'] !== (float)$tot;

      if (!$isUpdateCancellation && ($recalcChangedMoney || empty($mo['invoice_id']))) {
        try {
          $invoice_id = wo_maybe_sync_invoice_after_order_change($conn, $order_id, current_user_id(), true);
          if ($invoice_id) {
            $conn->prepare("UPDATE make_order SET invoice_id = :iid WHERE id = :id")
                 ->execute([':iid'=>$invoice_id, ':id'=>$order_id]);
          }
        } catch (Throwable $glx) {
          if (strpos($glx->getMessage(), 'Duplicate entry') === false) { throw $glx; }
        }
      }
      if ($status) {
        wo_sync_ops_status_column($conn, $order_id, (string)$status);
      }
      
      $actor = current_user_id();
      audit_order($conn, $order_id, $actor, 'time_changed', null, [
        'start'=>$newStart, 'end'=>$newEnd, 'status'=>$status
      ]);

      nt_notify_driver_for_order($conn, $order_id, 'driver.order.changed');

    echo json_encode([
      'success'=>true,
      'order_id'=>$order_id,
      'start_time'=>$newStart,
      'end_time'=>$newEnd,
      'hours'=>$hoursBooking,
      'subtotal'=>$sub,
      'vat'=>$vat,
      'total'=>$tot,
      'invoice_id'=>$invoice_id,
    ]); exit;
  }

  /* ---------- DAY view (GET) ---------- */
  $day   = (isset($_GET['day']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['day'])) ? $_GET['day'] : date('Y-m-d');
  $startWin = isset($_GET['start']) && preg_match('/^\d{2}:\d{2}$/', $_GET['start']) ? $_GET['start'] : '07:00';
  $endWin   = isset($_GET['end'])   && preg_match('/^\d{2}:\d{2}$/', $_GET['end'])   ? $_GET['end']   : '22:00';

  $allWorkers = $conn->query("
    SELECT w.id, w.nickname, w.daily_cap_hours 
    FROM workers w 
    LEFT JOIN employees e ON w.emp_num = e.employee_code 
    WHERE e.status IS NULL OR e.status != 'Inactive'
    ORDER BY w.nickname
  ")->fetchAll(PDO::FETCH_ASSOC);

  $bookingCatCol = sm_make_order_has_booking_categories($conn) ? 'mo.booking_categories, mo.service_category_id,' : 'mo.service_category_id,';
  $st = $conn->prepare("
    SELECT
      mo.id AS order_id, ow.worker_id AS worker_id, mo.status AS status,
      " . mo_is_finalized_select($conn) . ",
      COALESCE(mo.service_date, mo.`date`) AS svc_date,
      mo.start_time, mo.end_time, mo.`time` AS time_text,
      {$bookingCatCol}
      c.client_name AS client_name,
      COALESCE(mo.driver_name, d.nickname) AS driver_name
    FROM make_order mo
    JOIN order_workers ow ON ow.order_id = mo.id
    LEFT JOIN client c ON c.id = mo.client_id
    LEFT JOIN driver d ON d.id = mo.driver_id
    WHERE mo.svc_date_calc = :d
      AND COALESCE(mo.status,'') <> 'cancelled'
  ");
  $st->execute([':d'=>$day]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  $orderCategoryLabels = [];
  foreach ($rows as $r) {
    $oid = (int)$r['order_id'];
    if (!isset($orderCategoryLabels[$oid])) {
      $orderCategoryLabels[$oid] = sm_order_category_labels(
          $conn,
          $r['booking_categories'] ?? null,
          (int)($r['service_category_id'] ?? 0) ?: null
      );
    }
  }

  // Get services for all orders
  $order_ids = array_column($rows, 'order_id');
  $order_services_map = [];
  if (!empty($order_ids)) {
    $placeholders = implode(',', array_fill(0, count($order_ids), '?'));
    $svc_q = $conn->prepare("SELECT order_id, service_name, qty, unit FROM order_services WHERE order_id IN ($placeholders) ORDER BY order_id, id");
    $svc_q->execute($order_ids);
    while ($svc_row = $svc_q->fetch(PDO::FETCH_ASSOC)) {
      $oid = (int)$svc_row['order_id'];
      if (!isset($order_services_map[$oid])) {
        $order_services_map[$oid] = [];
      }
      $order_services_map[$oid][] = $svc_row;
    }
  }

  $events = [];
  $winStartDT = $day . ' ' . $startWin . ':00';
  $winEndDT   = $day . ' ' . $endWin   . ':00';

  foreach ($rows as $r) {
    $s = $r['start_time']; $e = $r['end_time'];
    if (!$s || !$e) { [$s2,$e2] = parseLegacyRange($r['time_text'] ?? ''); $s = $s ?: $s2; $e = $e ?: $e2; }
    if (!$s || !$e) continue;

    $startDT = $r['svc_date'] . ' ' . $s . ':00';
    $endDT   = $r['svc_date'] . ' ' . $e . ':00';
    if (!($endDT > $winStartDT && $startDT < $winEndDT)) continue;

    // Build services text if available
    $services = $order_services_map[$r['order_id']] ?? [];
    $services_text = '';
    if (!empty($services)) {
      $svc_items = [];
      foreach ($services as $svc) {
        $svc_items[] = $svc['service_name'] . ' (' . number_format((float)$svc['qty'], 1) . ' ' . $svc['unit'] . ')';
      }
      $services_text = ' • ' . implode(' + ', $svc_items);
    }

    $events[] = [
      'order_id'   => (int)$r['order_id'],
      'worker_id'  => (int)$r['worker_id'],
      'status'     => (string)$r['status'],
      'is_finalized' => (int)($r['is_finalized'] ?? 0) === 1,
      'start_time' => $s,
      'end_time'   => $e,
      'client'     => $r['client_name'] ?? null,
      'driver'     => $r['driver_name'] ?? null,
      'services'   => $services_text,
      'category_labels' => $orderCategoryLabels[(int)$r['order_id']] ?? '',
    ];
  }

  // Totals widget payload (headline + breakdowns)
  $dayParam = $day;

  // A) Headline totals
  $totalsStmt = $conn->prepare("
    SELECT
      COUNT(*) AS orders_count,
      SUM((TIMESTAMPDIFF(MINUTE, mo.start_time, mo.end_time) / 60) * GREATEST(1, owc.cnt)) AS hours_total,
      SUM(COALESCE(mo.total, 0))       AS subtotal_total,
      SUM(COALESCE(mo.vat_amount, 0))  AS vat_total,
      SUM(COALESCE(mo.grand_total, 0)) AS grand_total
    FROM make_order mo
    JOIN (SELECT order_id, COUNT(*) AS cnt FROM order_workers GROUP BY order_id) owc
      ON owc.order_id = mo.id
    WHERE mo.svc_date_calc = :d
      AND COALESCE(mo.status, '') <> 'cancelled'
      AND mo.start_time IS NOT NULL
      AND mo.end_time   IS NOT NULL
  ");
  $totalsStmt->execute([':d' => $dayParam]);
  $head = $totalsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

  $orders_count = (int)($head['orders_count'] ?? 0);
  $hours_total  = (float)($head['hours_total']  ?? 0);
  $subtotal     = (float)($head['subtotal_total'] ?? 0);
  $vat_total    = (float)($head['vat_total'] ?? 0);
  $grand_total  = (float)($head['grand_total'] ?? 0);
  $avg_hourly   = $hours_total > 0 ? round($subtotal / $hours_total, 2) : null;

  // B) status counts
  $stStmt = $conn->prepare("
    SELECT s.status, COUNT(*) AS cnt
    FROM (
      SELECT COALESCE(mo.status, '') AS status
      FROM make_order mo
      WHERE mo.svc_date_calc = :d
        AND COALESCE(mo.status, '') <> 'cancelled'
    ) s
    GROUP BY s.status
    ORDER BY cnt DESC, s.status
  ");
  $stStmt->execute([':d' => $dayParam]);
  $statusRows = $stStmt->fetchAll(PDO::FETCH_ASSOC);
  $status_breakdown = [];
  foreach ($statusRows as $r) {
    $status_breakdown[(string)$r['status']] = (int)$r['cnt'];
  }

  // C) per-worker
  $pwStmt = $conn->prepare("
    SELECT
      w.id AS worker_id,
      w.nickname,
      COUNT(*) AS bookings,
      SUM(TIMESTAMPDIFF(MINUTE, mo.start_time, mo.end_time) / 60) AS hours
    FROM order_workers ow
    JOIN workers w      ON w.id = ow.worker_id
    JOIN make_order mo  ON mo.id = ow.order_id
    WHERE mo.svc_date_calc = :d
      AND COALESCE(mo.status, '') <> 'cancelled'
      AND mo.start_time IS NOT NULL
      AND mo.end_time   IS NOT NULL
    GROUP BY w.id, w.nickname
    ORDER BY w.nickname
  ");
  $pwStmt->execute([':d' => $dayParam]);
  $per_worker = [];
  $scheduled_minutes_sum = 0;
  while ($r = $pwStmt->fetch(PDO::FETCH_ASSOC)) {
    $h = (float)$r['hours'];
    $per_worker[] = [
      'worker_id' => (int)$r['worker_id'],
      'name'      => (string)$r['nickname'],
      'bookings'  => (int)$r['bookings'],
      'hours'     => $h
    ];
    $scheduled_minutes_sum += (int)round($h * 60);
  }

  // D) per-driver
  $pdStmt = $conn->prepare("
    SELECT
      d.id AS driver_id,
      d.nickname,
      COUNT(*) AS bookings
    FROM make_order mo
    LEFT JOIN driver d ON d.id = mo.driver_id
    WHERE mo.svc_date_calc = :d
      AND COALESCE(mo.status, '') <> 'cancelled'
    GROUP BY d.id, d.nickname
    ORDER BY bookings DESC, d.nickname
  ");
  $pdStmt->execute([':d' => $dayParam]);
  $per_driver = [];
  while ($r = $pdStmt->fetch(PDO::FETCH_ASSOC)) {
    $per_driver[] = [
      'driver_id' => $r['driver_id'] !== null ? (int)$r['driver_id'] : null,
      'name'      => $r['nickname'] ?? null,
      'bookings'  => (int)$r['bookings']
    ];
  }

  // E) top clients
  $tcStmt = $conn->prepare("
    SELECT
      c.id AS client_id,
      c.client_name,
      COUNT(*) AS orders,
      SUM(COALESCE(mo.grand_total, 0)) AS revenue
    FROM make_order mo
    LEFT JOIN client c ON c.id = mo.client_id
    WHERE mo.svc_date_calc = :d
      AND COALESCE(mo.status, '') <> 'cancelled'
    GROUP BY c.id, c.client_name
    ORDER BY revenue DESC, c.client_name
    LIMIT 5
  ");
  $tcStmt->execute([':d' => $dayParam]);
  $top_clients = [];
  while ($r = $tcStmt->fetch(PDO::FETCH_ASSOC)) {
    $top_clients[] = [
      'client_id' => $r['client_id'] !== null ? (int)$r['client_id'] : null,
      'name'      => $r['client_name'] ?? null,
      'orders'    => (int)$r['orders'],
      'revenue'   => (float)$r['revenue']
    ];
  }

  // F) utilization
  $waStmt = $conn->prepare("
    SELECT COUNT(DISTINCT ow.worker_id) AS workers_active
    FROM order_workers ow
    JOIN make_order mo ON mo.id = ow.order_id
    WHERE mo.svc_date_calc = :d
      AND COALESCE(mo.status, '') <> 'cancelled'
      AND mo.start_time IS NOT NULL
      AND mo.end_time   IS NOT NULL
  ");
  $waStmt->execute([':d' => $dayParam]);
  $workers_active = (int)($waStmt->fetchColumn() ?: 0);

  $spanMinutes = 0;
  if (isset($startWin, $endWin) && preg_match('/^\d{2}:\d{2}$/', $startWin) && preg_match('/^\d{2}:\d{2}$/', $endWin)) {
    [$sh,$sm] = array_map('intval', explode(':',$startWin));
    [$eh,$em] = array_map('intval', explode(':',$endWin));
    $spanMinutes = max(0, ($eh*60+$em) - ($sh*60+$sm));
  }
  $den = $workers_active * $spanMinutes;
  $coverage = ($den > 0) ? round(($scheduled_minutes_sum / $den) * 100, 1) : null;

  $totalsPayload = [
    'orders_count'     => $orders_count,
    'status_breakdown' => $status_breakdown,
    'hours_total'      => (float)round($hours_total, 2),
    'money'            => [
      'subtotal'        => (float)round($subtotal, 2),
      'vat'             => (float)round($vat_total, 2),
      'grand_total'     => (float)round($grand_total, 2),
      'avg_hourly_rate' => $avg_hourly,
    ],
    'per_worker'       => $per_worker,
    'per_driver'       => $per_driver,
    'top_clients'      => $top_clients,
    'utilization'      => [
      'workers_active' => $workers_active,
      'span_minutes'   => $spanMinutes,
      'scheduled_minutes_per_worker_sum' => $scheduled_minutes_sum,
      'window_coverage_pct' => $coverage
    ]
  ];

  /* unavailability blocks for the window (all workers) */
  $stU = $conn->prepare("
    SELECT wu.id, wu.worker_id, wu.start_time, wu.end_time, wu.reason, wu.absence_type, wu.attendance_id
    FROM worker_unavailability wu
    WHERE wu.`date` = :d
  ");
  $stU->execute([':d'=>$day]);
  $unavailability = [];
  foreach ($stU->fetchAll(PDO::FETCH_ASSOC) as $u) {
    $s = $u['start_time']; $e = $u['end_time'];
    if (!($e > $startWin && $s < $endWin)) continue;
    $unavailability[] = [
      'id'         => (int)$u['id'],
      'worker_id'  => (int)$u['worker_id'],
      'start_time' => $s,
      'end_time'   => $e,
      'reason'     => $u['reason'] ?? null,
      'absence_type' => $u['absence_type'] ?? 'time_off',
      'attendance_id' => isset($u['attendance_id']) ? (int)$u['attendance_id'] : null,
    ];
  }

  echo json_encode([
    'success'=>true,
    'day'=>$day,
    'window'=>['start'=>$startWin,'end'=>$endWin],
    'events'=>$events,
    'workers'=>$allWorkers,
    'totals'    => $totalsPayload,
    'unavailability' => $unavailability
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['success'=>false,'error'=>'Availability Error: '.$e->getMessage()]);
}
