<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

try {
  require_once __DIR__ . '/../includes/auth.php';
  require_once __DIR__ . '/../includes/db_connect.php';
  require_once __DIR__ . '/../includes/ar_helpers.php';
  require_once __DIR__ . '/../includes/notify.php';
  require_once __DIR__ . '/../includes/work_order_batch_invoice_service.php';

  // ---------- small helpers ----------
  function today(): string { return date('Y-m-d'); }

    /**
     * Compute the next billable window [from, to] for a client,
     * fast-forwarding across empty cadence windows until we hit
     * a window that actually contains unbilled orders.
     *
     * Returns null if there is nothing left to bill.
     */
    function compute_next_range(PDO $conn, int $clientId, ?string $forceLastDate = null): ?array {
      if (sm_hybrid_batch_enabled($conn)) {
        return sm_compute_batch_next_range($conn, $clientId, $forceLastDate);
      }

      // Pull cadence, last billed, and overall unbilled bounds (legacy: no invoice yet)
      $q = $conn->prepare("
        SELECT s.first_date, s.last_date, c.payment AS cadence,
               cb.last_billed_to
        FROM client c
        LEFT JOIN client_billing cb ON cb.client_id=c.id
        LEFT JOIN v_client_unbilled_summary s ON s.client_id=c.id
        WHERE c.id=?");
      $q->execute([$clientId]);
      $r = $q->fetch(PDO::FETCH_ASSOC);
      if (!$r || !$r['last_date']) return null; // nothing unbilled at all

      $cad            = (string)($r['cadence'] ?? 'M');       // 'D','W','Bi-W','M'
      $lastBilledTo   = $r['last_billed_to'] ? (string)$r['last_billed_to'] : null;
      $firstUnbilled  = (string)$r['first_date'];
      $overallLast    = $forceLastDate ?: (string)$r['last_date'];

      // First candidate start = day after last billed, or very first unbilled
      $start = $lastBilledTo ? date('Y-m-d', strtotime($lastBilledTo . ' +1 day'))
                             : $firstUnbilled;

      // Stale last_billed_to can hide clients that still have unbilled orders.
      if ($start > $overallLast) {
        $start = $firstUnbilled;
      }

      // Safety: stop if start is already past the overall last unbilled day
      if ($start > $overallLast) return null;

      // Advance through windows until we find one that actually has unbilled rows
      // (cap iterations to avoid infinite loops in edge cases)
      for ($i = 0; $i < 24; $i++) {
        // Compute the cadence boundary end for this start
        if ($cad === 'D') {
          $end = $start; // single day window
        } elseif ($cad === 'W') {
          // End at Sunday of the week that begins at $start
          $weekEnd = new DateTime($start);
          $weekEnd->modify('next sunday'); // if start is Sunday → next Sunday (7-day span)
          $end = $weekEnd->format('Y-m-d');
        } elseif ($cad === 'Bi-W') {
          $end = date('Y-m-d', strtotime($start . ' +13 day')); // 14-day window
        } else { // 'M'
          $end = (new DateTime($start))->modify('last day of this month')->format('Y-m-d');
        }

        // Never go beyond the overall last unbilled day
        if ($end > $overallLast) $end = $overallLast;

        // If the computed end fell before start, we’re done
        if ($end < $start) return null;

        // Does this window have anything unbilled?
        $win = window_totals($conn, $clientId, $start, $end);
        if (($win['orders_cnt'] ?? 0) > 0 && ($win['grand_total'] ?? 0) > 0.0001) {
          return ['from' => $start, 'to' => $end];
        }

        // Otherwise jump to the next window: day after this window’s end
        $start = date('Y-m-d', strtotime($end . ' +1 day'));
        if ($start > $overallLast) return null; // no more windows to try
      }

      // Nothing found within reasonable jumps
      return null;
    }

  /** Credit info (safe fallback if v_client_ar_balance isn’t present) */
  function credit_info_simple(PDO $conn, int $clientId): array {
    $limit = (float)single_val($conn, "SELECT COALESCE(credit_limit,0) FROM client WHERE id=?", [$clientId]);
    $outstanding = 0.0;
    try {
      $outstanding = (float)single_val($conn,
        "SELECT COALESCE(outstanding,0) FROM v_client_ar_balance WHERE client_id=?", [$clientId]);
    } catch (Throwable $e) {
      // fallback to invoices table if you don't have the view
      $outstanding = (float)single_val($conn,
        "SELECT COALESCE(SUM(COALESCE(total,0) - COALESCE(amount_paid,0)),0)
           FROM invoices WHERE client_id=? AND COALESCE(status,'') <> 'void'", [$clientId]);
    }
    return ['limit'=>$limit, 'outstanding'=>$outstanding];
  }
    
    /** Sum orders for a client within a specific window (unbilled only). */
    function window_totals(PDO $conn, int $clientId, string $from, string $to): array {
      if (sm_hybrid_batch_enabled($conn)) {
        return sm_batch_window_totals($conn, $clientId, $from, $to);
      }
      $st = $conn->prepare("
        SELECT
          COUNT(*)                                     AS orders_cnt,
          COALESCE(SUM(hours),0)                       AS hours,
          COALESCE(SUM(total),0)                       AS subtotal,
          COALESCE(SUM(vat_amount),0)                  AS vat,
          COALESCE(SUM(grand_total),0)                 AS grand_total
        FROM make_order
        WHERE client_id = ?
          AND svc_date_calc BETWEEN ? AND ?
          AND (invoice_id IS NULL OR invoice_id = 0)
          AND COALESCE(status,'') <> 'cancelled'
      ");
      $st->execute([$clientId, $from, $to]);
      $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
      // normalize numbers
      return [
        'orders_cnt'  => (int)($row['orders_cnt'] ?? 0),
        'hours'       => round((float)($row['hours'] ?? 0), 2),
        'subtotal'    => round((float)($row['subtotal'] ?? 0), 2),
        'vat'         => round((float)($row['vat'] ?? 0), 2),
        'grand_total' => round((float)($row['grand_total'] ?? 0), 2),
      ];
    }

  // ---------- ROUTES ----------
  $method = $_SERVER['REQUEST_METHOD'];

    // A) WHO NEEDS BILLING NOW (queue) — optimized
    // A) WHO NEEDS BILLING NOW (queue) — optimized + range-accurate totals
    if ($method==='GET' && ($_GET['action']??'')==='due_clients') {
      // filters
      $cadence    = $_GET['cadence']  ?? '';                   // '', 'W','Bi-W','M','D'
      $minTotal   = (float)($_GET['min_total'] ?? 0);
      $dueOnly    = !empty($_GET['due_only']);
      $page       = max(1, (int)($_GET['page'] ?? 1));
      $pageSize   = max(10, min(200, (int)($_GET['page_size'] ?? 50)));
      $offset     = ($page - 1) * $pageSize;

      // Build WHERE fragment for cadence if provided
      $whereCad = '';
      $params   = [];
      if ($cadence !== '') {
        $whereCad = " AND c.payment = :cad ";
        $params[':cad'] = $cadence;
      }

      // ONE aggregate over unbilled orders (only clients with unbilled will appear)
      $hybrid = sm_hybrid_batch_enabled($conn);
      if ($hybrid) {
        $frag = sm_batch_eligible_sql_fragment($conn, false);
        $sql = "
        SELECT
          u.client_id,
          c.client_name,
          c.payment                 AS cadence,
          c.default_vat_rate,
          c.credit_limit,
          u.first_date,
          u.last_date,
          u.hours,
          u.subtotal,
          u.vat,
          u.grand_total,
          u.orders_cnt,
          cb.last_billed_to,
          cb.min_bill_total,
          cb.auto_bill,
          cb.email_to,
          COALESCE(ar.outstanding,0) AS outstanding_now
        FROM (
          SELECT
            mo.client_id,
            MIN(mo.svc_date_calc) AS first_date,
            MAX(mo.svc_date_calc) AS last_date,
            SUM(mo.hours) AS hours,
            SUM(ci.subtotal) AS subtotal,
            SUM(ci.vat_amount) AS vat,
            SUM(ci.total) AS grand_total,
            COUNT(*) AS orders_cnt
          FROM make_order mo
          {$frag['join']}
          WHERE 1=1 {$frag['where']}
          GROUP BY mo.client_id
        ) u
        JOIN client c ON c.id = u.client_id
        LEFT JOIN client_billing cb ON cb.client_id = u.client_id
        LEFT JOIN (
          SELECT
            i.client_id,
            ROUND(SUM(i.total) - SUM(COALESCE(a.applied,0)), 2) AS outstanding
          FROM invoices i
          LEFT JOIN (
            SELECT invoice_id, SUM(amount_applied) AS applied
            FROM receipt_allocations
            GROUP BY invoice_id
          ) a ON a.invoice_id = i.id
          WHERE COALESCE(i.status,'') <> 'void'
            AND COALESCE(i.is_batch_summary,0) = 0
          GROUP BY i.client_id
        ) ar ON ar.client_id = u.client_id
        WHERE 1=1
          $whereCad
          AND u.grand_total >= GREATEST(:min_total, COALESCE(cb.min_bill_total,0))
        ORDER BY c.client_name
        LIMIT :lim OFFSET :off
        ";
        $countSql = "
        SELECT COUNT(*) FROM (
          SELECT mo.client_id
          FROM make_order mo
          {$frag['join']}
          JOIN client c ON c.id = mo.client_id
          LEFT JOIN client_billing cb ON cb.client_id = c.id
          WHERE 1=1 {$frag['where']}
          " . ($cadence!=='' ? " AND c.payment = :cad " : "") . "
          GROUP BY mo.client_id
          HAVING SUM(ci.total) >= GREATEST(:min_total, COALESCE(MAX(cb.min_bill_total),0))
        ) x
        ";
      } else {
      $sql = "
        SELECT
          u.client_id,
          c.client_name,
          c.payment                 AS cadence,
          c.default_vat_rate,
          c.credit_limit,
          u.first_date,
          u.last_date,
          u.hours,
          u.subtotal,
          u.vat,
          u.grand_total,
          u.orders_cnt,
          cb.last_billed_to,
          cb.min_bill_total,
          cb.auto_bill,
          cb.email_to,
          COALESCE(ar.outstanding,0) AS outstanding_now
        FROM (
          /* Unbilled summary (only clients with unbilled orders) */
          SELECT
            mo.client_id,
            MIN(mo.svc_date_calc)                       AS first_date,
            MAX(mo.svc_date_calc)                       AS last_date,
            SUM(mo.hours)                                AS hours,
            SUM(mo.total)                                AS subtotal,
            SUM(mo.vat_amount)                           AS vat,
            SUM(mo.grand_total)                          AS grand_total,
            COUNT(*)                                     AS orders_cnt
          FROM make_order mo
          WHERE mo.invoice_id IS NULL
            AND COALESCE(mo.status,'') <> 'cancelled'
          GROUP BY mo.client_id
        ) u
        JOIN client c ON c.id = u.client_id
        /* Client prefs */
        LEFT JOIN client_billing cb ON cb.client_id = u.client_id
        /* Outstanding AR by client (invoices minus allocations, excluding void) */
        LEFT JOIN (
          SELECT
            i.client_id,
            ROUND(SUM(i.total) - SUM(COALESCE(a.applied,0)), 2) AS outstanding
          FROM invoices i
          LEFT JOIN (
            SELECT invoice_id, SUM(amount_applied) AS applied
            FROM receipt_allocations
            GROUP BY invoice_id
          ) a ON a.invoice_id = i.id
          WHERE COALESCE(i.status,'') <> 'void'
          GROUP BY i.client_id
        ) ar ON ar.client_id = u.client_id
        WHERE 1=1
          $whereCad
          /* server-side threshold against user input and per-client setting */
          AND u.grand_total >= GREATEST(:min_total, COALESCE(cb.min_bill_total,0))
        ORDER BY c.client_name
        LIMIT :lim OFFSET :off
      ";

      // Count for pagination (on the grouped set)
      $countSql = "
        SELECT COUNT(*) FROM (
          SELECT mo.client_id
          FROM make_order mo
          JOIN client c ON c.id = mo.client_id
          LEFT JOIN client_billing cb ON cb.client_id = c.id
          WHERE mo.invoice_id IS NULL
            AND COALESCE(mo.status,'') <> 'cancelled'
            " . ($cadence!=='' ? " AND c.payment = :cad " : "") . "
          GROUP BY mo.client_id
          HAVING SUM(mo.grand_total) >= GREATEST(:min_total, COALESCE(MAX(cb.min_bill_total),0))
        ) x
      ";
      }

      $params[':min_total'] = $minTotal;

      $stmt = $conn->prepare($sql);
      foreach ($params as $k=>$v) { $stmt->bindValue($k, $v); }
      $stmt->bindValue(':lim', $pageSize, PDO::PARAM_INT);
      $stmt->bindValue(':off', $offset,  PDO::PARAM_INT);
      $stmt->execute();
      $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

      // total rows (for UI pager)
      $cstmt = $conn->prepare($countSql);
      if ($cadence!=='') $cstmt->bindValue(':cad', $cadence);
      $cstmt->bindValue(':min_total', $minTotal);
      $cstmt->execute();
      $totalRows = (int)$cstmt->fetchColumn();

      // Map rows → response; compute next range, then recompute totals for THAT range,
      // credit-block flags, and finally skip empty ranges.
      $items = [];
      foreach ($rows as $r) {
        $clientId = (int)$r['client_id'];

        // compute the suggested next range (advances based on last_billed_to)
        $range = compute_next_range($conn, $clientId, $r['last_date']);
        // Hybrid fallback: if cadence math fails but SQL found eligible orders, use full span
        if (!$range && $hybrid && (int)($r['orders_cnt'] ?? 0) > 0 && !empty($r['first_date']) && !empty($r['last_date'])) {
          $range = ['from' => (string)$r['first_date'], 'to' => (string)$r['last_date']];
        }
        $status = $range ? 'due' : 'not-due';

        // if Due only, skip non-due
        if ($dueOnly && $status !== 'due') continue;

        // recompute totals strictly for the range we're going to bill
        if ($range) {
          $win = window_totals($conn, $clientId, $range['from'], $range['to']);
        } else {
          $win = ['orders_cnt'=>0, 'hours'=>0, 'subtotal'=>0, 'vat'=>0, 'grand_total'=>0];
        }

        // skip rows where the current range has no orders/total (prevents ghost rows)
        if ($win['orders_cnt'] === 0 || $win['grand_total'] <= 0.0001) {
          // if you want to still show NOT DUE rows with zero, remove this continue
          continue;
        }

        // credit flags (no extra queries)
        $limit     = (float)$r['credit_limit'];
        $outstNow  = (float)$r['outstanding_now'];
        $wouldBill = (float)$win['grand_total']; // range amount, not all unbilled
        $creditBlocked = ($limit > 0 && ($outstNow + $wouldBill) > $limit + 0.0001);

        $items[] = [
          'client_id'      => $clientId,
          'client_name'    => (string)$r['client_name'],
          'cadence'        => (string)$r['cadence'],
          // show the RANGE we’ll actually bill
          'first_date'     => $range ? $range['from'] : null,
          'last_date'      => $range ? $range['to']   : null,

          // only RANGE totals (match Batch Preview)
          'hours'          => $win['hours'],
          'subtotal'       => $win['subtotal'],
          'vat'            => $win['vat'],
          'grand_total'    => $win['grand_total'],
          'orders_cnt'     => $win['orders_cnt'],

          'last_billed_to' => $r['last_billed_to'],
          'range'          => $range,
          'status'         => $status, // 'due' | 'not-due'
          'credit'         => [
            'limit'        => round($limit,2),
            'outstanding'  => round($outstNow,2),
            'blocked'      => $creditBlocked,
          ],
          'auto_bill'      => (int)($r['auto_bill'] ?? 0),
          'email_to'       => $r['email_to'] ?? null,
        ];
      }

      echo json_encode([
        'success'    => true,
        'items'      => $items,
        'pagination' => [
          'page'       => $page,
          'page_size'  => $pageSize,
          'total_rows' => $totalRows,                 // grouped count (pre-filter)
          'total_pages'=> (int)ceil($totalRows / $pageSize),
        ]
      ]);
      exit;
    }
  // B) SUGGESTED RANGE FOR A CLIENT
  if ($method==='GET' && ($_GET['action']??'')==='next_range') {
    $cid = (int)($_GET['client_id'] ?? 0);
    if ($cid<=0) throw new Exception('Missing client_id');
    $range = compute_next_range($conn, $cid);
    echo json_encode(['success'=>true,'range'=>$range]); exit;
  }

  // C) GENERATE & (optionally) EMAIL
  if ($method==='POST' && ($_POST['action']??'')==='generate_and_email') {
    $clientId   = (int)($_POST['client_id'] ?? 0);
    $from       = $_POST['from'] ?? '';
    $to         = $_POST['to']   ?? '';
    $itemization= $_POST['itemization'] ?? 'per_order';
    $sendEmail  = !empty($_POST['send_email']);
    if ($clientId<=0 || !$from || !$to) throw new Exception('Missing params');

    // 1) generate
    $iid = ar_generate_batch_invoice($conn, $clientId, $from, $to, [], $itemization, $_SESSION['user_id'] ?? null);

    // 2) update last_billed_to
    $conn->prepare("
      INSERT INTO client_billing (client_id, last_billed_to) VALUES (?,?)
      ON DUPLICATE KEY UPDATE last_billed_to=VALUES(last_billed_to), updated_at=NOW()
    ")->execute([$clientId, $to]);

    // 3) email (enqueue)
    if ($sendEmail) {
      // You likely have notify/outbox already. Example:
      if (function_exists('nt_email_invoice')) {
        nt_email_invoice($conn, $iid);    // implement using your templating/outbox
      }
    }

    echo json_encode(['success'=>true,'invoice_id'=>$iid]); exit;
  }

  // D) UPDATE PREFS
  if ($method==='POST' && ($_POST['action']??'')==='update_prefs') {
    $cid    = (int)($_POST['client_id'] ?? 0);
    $minTot = (float)($_POST['min_bill_total'] ?? 0);
    $auto   = !empty($_POST['auto_bill']) ? 1 : 0;
    $email  = trim((string)($_POST['email_to'] ?? '')) ?: null;
    if ($cid<=0) throw new Exception('Missing client_id');

    $conn->prepare("
      INSERT INTO client_billing (client_id, min_bill_total, auto_bill, email_to)
      VALUES (?,?,?,?)
      ON DUPLICATE KEY UPDATE
        min_bill_total=VALUES(min_bill_total),
        auto_bill=VALUES(auto_bill),
        email_to=VALUES(email_to),
        updated_at=NOW()
    ")->execute([$cid, $minTot, $auto, $email]);

    echo json_encode(['success'=>true]); exit;
  }

  echo json_encode(['success'=>false,'error'=>'Unknown action']); exit;

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['success'=>false,'error'=>'Billing Queue Error: '.$e->getMessage()]);
}
