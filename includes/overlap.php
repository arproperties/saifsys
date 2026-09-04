<?php
// includes/overlap.php

require_once __DIR__ . '/db_connect.php'; // if not already loaded by caller

// copy the tiny parser we already use in the API
function __pad2($n){ return str_pad((string)$n, 2, '0', STR_PAD_LEFT); }
function __parseLegacyRange(?string $txt): array {
  if (!$txt) return [null, null];
  $parts = preg_split('/\s*(?:to|\-|\–)\s*/i', trim($txt));
  if (!$parts || count($parts) < 2) return [null, null];
  $norm = static function (string $p): ?string {
    $p = trim($p);
    if (preg_match('/^(\d{1,2}):(\d{1,2})$/', $p, $m)) {
      return __pad2((int)$m[1]).':'.__pad2((int)$m[2]);
    }
    if (preg_match('/^(\d{1,2})(?:\.(\d+))?$/', $p, $m)) {
      $H=(int)$m[1]; $dec=$m[2]??''; $M=($dec==='3'||$dec==='30'||$dec==='5'||$dec==='50')?30:0;
      return __pad2(max(0,min(23,$H))).':'.__pad2($M);
    }
    return null;
  };
  return [$norm($parts[0]), $norm($parts[1])];
}

/**
 * Return a list of conflicting orders for a worker on a date.
 * Each row: id, client, start_time, end_time
 */
function findOverlap(PDO $conn, int $workerId, string $svcDate,
                     string $startHHMM, string $endHHMM, int $excludeOrderId = 0): array {

  $sql = "
    SELECT mo.id, mo.start_time, mo.end_time, mo.`time` AS time_text,
           c.client_name AS client
    FROM make_order mo
    JOIN order_workers ow ON ow.order_id = mo.id
    LEFT JOIN client c ON c.id = mo.client_id
    WHERE ow.worker_id = :wid
      AND COALESCE(mo.service_date, mo.`date`) = :d
      AND COALESCE(mo.status,'') <> 'cancelled'
      ".($excludeOrderId ? "AND mo.id <> :ex" : "")."
  ";
  $st = $conn->prepare($sql);
  $params = [':wid'=>$workerId, ':d'=>$svcDate];
  if ($excludeOrderId) $params[':ex'] = $excludeOrderId;
  $st->execute($params);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  $hits = [];
  foreach ($rows as $r) {
    $s = $r['start_time']; $e = $r['end_time'];
    if (!$s || !$e) {
      [$s2,$e2] = __parseLegacyRange($r['time_text'] ?? '');
      $s = $s ?: $s2; $e = $e ?: $e2;
    }
    if (!$s || !$e) continue;

    // overlap if existing.start < new.end AND existing.end > new.start
    if ($s < $endHHMM && $e > $startHHMM) {
      $hits[] = ['id'=>(int)$r['id'], 'client'=>$r['client'] ?? null,
                 'start_time'=>$s, 'end_time'=>$e];
    }
  }
  return $hits;
}
