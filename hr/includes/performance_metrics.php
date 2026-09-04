<?php
/**
 * Performance page metrics loader.
 *
 * Expects: $conn, $view, $from, $to, $prevFrom, $prevTo, $monthsFactor,
 *          $selectedCompanyId, $departmentId, $workerA, $employeeA, $dateExpr
 * Sets:    $rows, $prev, $totals, $kpiTarget, $kpiAchv,
 *          $chartLabels, $chartHours, $chartRev, $chartOt, $exportQs, $companyScopeLabel
 * May exit on CSV export.
 */

declare(strict_types=1);

$rows = [];
$prev = [];
$totals = [
    'jobs' => 0,
    'hours' => 0.0,
    'revenue' => 0.0,
    'cost' => 0.0,
    'net' => 0.0,
    'target' => 0.0,
    'present' => 0,
    'absent' => 0,
    'ot_hours' => 0.0,
    'employees' => 0,
];
$kpiTarget = null;
$kpiAchv = null;
$chartLabels = [];
$chartHours = [];
$chartRev = [];
$chartOt = [];

if ($view === 'cleaning') {
    $params = [':from' => $from->format('Y-m-d'), ':to' => $to->format('Y-m-d')];
    $workerClause = '';
    if ($workerA > 0) {
        $workerClause = ' AND ow.worker_id=:wid ';
        $params[':wid'] = $workerA;
    }
    $companyClause = '';
    if ($selectedCompanyId > 0) {
        $companyClause = " AND EXISTS (
            SELECT 1 FROM employees e
            WHERE e.company_id = :cid
              AND " . perf_worker_employee_join_sql('w', 'e') . "
        )";
        $params[':cid'] = $selectedCompanyId;
    }

    $sqlCore = "
    SELECT
      w.id AS worker_id,
      COALESCE(NULLIF(w.nickname,''), CONCAT('Worker #', w.id)) AS worker_name,
      COALESCE(w.weekly_cap_hours,0) AS weekly_cap_hours,
      COALESCE(w.daily_cap_hours,0)  AS daily_cap_hours,
      COALESCE(w.total_salary, COALESCE(w.basic_salary,0)+COALESCE(w.allowance,0)+COALESCE(w.bonus,0)) AS monthly_salary,
      COUNT(DISTINCT mo.id) AS jobs,
      ROUND(SUM(COALESCE(mo.net_hours,0)),2) AS hours_worked,
      ROUND(SUM(CASE WHEN owc.cnt>0 THEN mo.grand_total/owc.cnt ELSE 0 END),2) AS revenue_incl_vat
    FROM order_workers ow
    JOIN workers w     ON w.id=ow.worker_id
    JOIN make_order mo ON mo.id=ow.order_id
    JOIN (SELECT order_id,COUNT(*) cnt FROM order_workers GROUP BY order_id) owc ON owc.order_id=mo.id
    WHERE mo.status IN('confirmed','completed')
      AND {$dateExpr} BETWEEN :from AND :to
      $workerClause
      $companyClause
    GROUP BY w.id, worker_name, weekly_cap_hours, daily_cap_hours, monthly_salary
    ORDER BY hours_worked DESC, worker_name
    ";
    $st = $conn->prepare($sqlCore);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $workerIds = array_map(static fn($r) => (int)$r['worker_id'], $rows);
    if (!$workerIds && $workerA > 0) {
        $workerIds = [$workerA];
    }

    $attendanceMap = [];
    if ($workerIds) {
        $ph = implode(',', array_fill(0, count($workerIds), '?'));
        $m = $conn->prepare("
          SELECT w.id wid, e.id eid
          FROM workers w
          LEFT JOIN employees e ON " . perf_worker_employee_join_sql('w', 'e') . "
          WHERE w.id IN ($ph)
        ");
        $m->execute($workerIds);
        $w2e = [];
        $empIds = [];
        foreach ($m->fetchAll(PDO::FETCH_ASSOC) as $x) {
            if ($x['eid']) {
                $w2e[(int)$x['wid']] = (int)$x['eid'];
                $empIds[] = (int)$x['eid'];
            }
        }

        if ($empIds) {
            $phE = implode(',', array_fill(0, count($empIds), '?'));
            $a = $conn->prepare("
              SELECT employee_id,
                     SUM(CASE WHEN status='approved' THEN 1 ELSE 0 END) AS p,
                     SUM(CASE WHEN status IN('absent','on_leave','half') THEN 1 ELSE 0 END) AS a,
                     ROUND(SUM(CASE WHEN status='approved' AND hours>8 THEN (hours-8) ELSE 0 END),2) AS ot_h
              FROM attendance
              WHERE work_date BETWEEN ? AND ?
                AND employee_id IN ($phE)
              GROUP BY employee_id
            ");
            $bind = array_merge([$from->format('Y-m-d'), $to->format('Y-m-d')], $empIds);
            $a->execute($bind);
            $A = [];
            foreach ($a->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $A[(int)$r['employee_id']] = [
                    'present' => (int)$r['p'],
                    'absent' => (int)$r['a'],
                    'ot_hours' => (float)$r['ot_h'],
                ];
            }

            $o = $conn->prepare("
              SELECT employee_id, ROUND(SUM(pay_hours),2) pay_h, ROUND(SUM(pay_amount),2) pay_amt
              FROM overtime_entries
              WHERE status='approved' AND ot_date BETWEEN ? AND ? AND employee_id IN ($phE)
              GROUP BY employee_id
            ");
            $o->execute($bind);
            $O = [];
            foreach ($o->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $O[(int)$r['employee_id']] = [
                    'pay_h' => (float)$r['pay_h'],
                    'pay_amt' => (float)$r['pay_amt'],
                ];
            }

            foreach ($w2e as $wid => $eid) {
                $att = $A[$eid] ?? ['present' => 0, 'absent' => 0, 'ot_hours' => 0.0];
                $ot = $O[$eid] ?? ['pay_h' => 0.0, 'pay_amt' => 0.0];
                $attendanceMap[$wid] = $att + ['ot_pay' => $ot['pay_amt']];
            }
        }
    }

    $globalTarget = null;
    $stGT = $conn->prepare("SELECT hours_target FROM perf_month_targets
                            WHERE period_from<=:to AND period_to>=:from
                            ORDER BY period_from DESC LIMIT 1");
    $stGT->execute([':from' => $from->format('Y-m-d'), ':to' => $to->format('Y-m-d')]);
    if ($r = $stGT->fetch(PDO::FETCH_ASSOC)) {
        $globalTarget = (float)$r['hours_target'];
    }

    $targetsByWorker = [];
    $stWT = $conn->prepare("SELECT worker_id, hours_target FROM perf_worker_targets
                            WHERE period_from<=:to AND period_to>=:from");
    $stWT->execute([':from' => $from->format('Y-m-d'), ':to' => $to->format('Y-m-d')]);
    foreach ($stWT->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $targetsByWorker[(int)$t['worker_id']] = (float)$t['hours_target'];
    }

    foreach ($rows as &$r) {
        $hours = (float)$r['hours_worked'];
        $revenue = (float)$r['revenue_incl_vat'];
        $weekly = (float)$r['weekly_cap_hours'];
        $daily = (float)$r['daily_cap_hours'];
        $cap = $weekly > 0 ? $weekly * 4.33 : ($daily > 0 ? $daily * 26 : 0.0);
        $util = $cap > 0 ? 100.0 * $hours / $cap : null;
        $salary = max(0.0, (float)$r['monthly_salary']);
        $baseCost = round($salary * $monthsFactor, 2);
        $att = $attendanceMap[(int)$r['worker_id']] ?? [
            'present' => 0,
            'absent' => 0,
            'ot_hours' => 0.0,
            'ot_pay' => 0.0,
        ];
        $cost = $baseCost + (float)$att['ot_pay'];
        $net = $revenue - $cost;
        $target = $targetsByWorker[(int)$r['worker_id']] ?? null;
        $achv = ($target && $target > 0) ? (100.0 * $hours / $target) : null;
        $revHr = $hours > 0 ? $revenue / $hours : null;
        $netHr = $hours > 0 ? $net / $hours : null;
        $margin = $revenue > 0 ? (100.0 * $net / $revenue) : null;

        $r['capacity_month'] = round($cap, 2);
        $r['utilization'] = $util !== null ? round($util, 1) : null;
        $r['present_days'] = (int)$att['present'];
        $r['absent_days'] = (int)$att['absent'];
        $r['ot_hours'] = (float)$att['ot_hours'];
        $r['ot_pay'] = (float)$att['ot_pay'];
        $r['cost_prorated'] = round($cost, 2);
        $r['net_contrib'] = round($net, 2);
        $r['target_hours'] = $target !== null ? (float)$target : null;
        $r['achv_pct'] = $achv !== null ? round($achv, 1) : null;
        $r['rev_per_hr'] = $revHr !== null ? round($revHr, 2) : null;
        $r['net_per_hr'] = $netHr !== null ? round($netHr, 2) : null;
        $r['margin_pct'] = $margin !== null ? round($margin, 1) : null;

        $totals['jobs'] += (int)$r['jobs'];
        $totals['hours'] += $hours;
        $totals['revenue'] += $revenue;
        $totals['cost'] += $cost;
        $totals['net'] += $net;
        if ($r['target_hours'] !== null) {
            $totals['target'] += (float)$r['target_hours'];
        }
    }
    unset($r);

    $kpiTarget = $totals['target'] > 0 ? $totals['target'] : ($globalTarget ?? null);
    $kpiAchv = ($kpiTarget && $kpiTarget > 0) ? (100.0 * $totals['hours'] / $kpiTarget) : null;

    if ($rows && $workerIds) {
        $ph = implode(',', array_fill(0, count($workerIds), '?'));
        $q = $conn->prepare("
          SELECT ow.worker_id,
                 ROUND(SUM(COALESCE(mo.net_hours,0)),2) AS h,
                 ROUND(SUM(CASE WHEN owc.cnt>0 THEN mo.grand_total/owc.cnt ELSE 0 END),2) AS r
          FROM order_workers ow
          JOIN make_order mo ON mo.id=ow.order_id
          JOIN (SELECT order_id,COUNT(*) cnt FROM order_workers GROUP BY order_id) owc ON owc.order_id=mo.id
          WHERE mo.status IN('confirmed','completed')
            AND {$dateExpr} BETWEEN ? AND ?
            AND ow.worker_id IN ($ph)
          GROUP BY ow.worker_id
        ");
        $bind = array_merge([$prevFrom->format('Y-m-d'), $prevTo->format('Y-m-d')], $workerIds);
        $q->execute($bind);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $x) {
            $prev[(int)$x['worker_id']] = ['h' => (float)$x['h'], 'r' => (float)$x['r']];
        }
    }

    $chartLabels = array_map(static fn($r) => $r['worker_name'], $rows);
    $chartHours = array_map(static fn($r) => (float)$r['hours_worked'], $rows);
    $chartRev = array_map(static fn($r) => (float)$r['revenue_incl_vat'], $rows);

    if (isset($_GET['export']) && $_GET['export'] === 'csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="cleaner_performance_' . $from->format('Ymd') . '_to_' . $to->format('Ymd') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Worker', 'Jobs', 'Hours', 'Capacity(month)', 'Util%', 'Present', 'Absent', 'OT(h)', 'Target(h)', 'Achiev%', 'Rev/hr', 'Net/hr', 'Margin%', 'Revenue', 'Cost', 'Net']);
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['worker_name'],
                (int)$r['jobs'],
                number_format((float)$r['hours_worked'], 2, '.', ''),
                $r['capacity_month'] ?: '',
                $r['utilization'] !== null ? number_format($r['utilization'], 1, '.', '') : '',
                (int)$r['present_days'],
                (int)$r['absent_days'],
                number_format((float)$r['ot_hours'], 2, '.', ''),
                $r['target_hours'] !== null ? number_format((float)$r['target_hours'], 1, '.', '') : '',
                $r['achv_pct'] !== null ? number_format((float)$r['achv_pct'], 1, '.', '') : '',
                $r['rev_per_hr'] !== null ? number_format((float)$r['rev_per_hr'], 2, '.', '') : '',
                $r['net_per_hr'] !== null ? number_format((float)$r['net_per_hr'], 2, '.', '') : '',
                $r['margin_pct'] !== null ? number_format((float)$r['margin_pct'], 1, '.', '') : '',
                number_format((float)$r['revenue_incl_vat'], 2, '.', ''),
                number_format((float)$r['cost_prorated'], 2, '.', ''),
                number_format((float)$r['net_contrib'], 2, '.', ''),
            ]);
        }
        fclose($out);
        exit;
    }
} else {
    $wfWhere = ['e.status IN (' . hr_employee_status_in_sql(hr_employee_current_statuses()) . ')'];
    $wfParams = hr_employee_current_statuses();
    if ($selectedCompanyId > 0) {
        $wfWhere[] = 'e.company_id = ?';
        $wfParams[] = $selectedCompanyId;
    }
    if ($departmentId > 0) {
        $wfWhere[] = 'e.department_id = ?';
        $wfParams[] = $departmentId;
    }
    if ($employeeA > 0) {
        $wfWhere[] = 'e.id = ?';
        $wfParams[] = $employeeA;
    }

    $sqlWf = "
      SELECT
        e.id AS employee_id,
        e.full_name AS worker_name,
        e.employee_code,
        e.company_id,
        COALESCE(c.name, '—') AS company_name,
        COALESCE(d.name, '—') AS department_name,
        COALESCE(e.total_salary, COALESCE(e.basic_salary,0) + COALESCE(e.allowance,0) + COALESCE(e.bonus,0)) AS monthly_salary,
        COALESCE(att.present_days, 0) AS present_days,
        COALESCE(att.absent_days, 0) AS absent_days,
        COALESCE(att.hours_worked, 0) AS hours_worked,
        COALESCE(ot.ot_hours, 0) AS ot_hours,
        COALESCE(ot.ot_pay, 0) AS ot_pay
      FROM employees e
      LEFT JOIN companies c ON c.id = e.company_id
      LEFT JOIN departments d ON d.id = e.department_id
      LEFT JOIN (
        SELECT employee_id,
               SUM(CASE WHEN status='approved' THEN 1 ELSE 0 END) AS present_days,
               SUM(CASE WHEN status IN('absent','on_leave','half') THEN 1 ELSE 0 END) AS absent_days,
               ROUND(SUM(COALESCE(hours,0)), 2) AS hours_worked
        FROM attendance
        WHERE work_date BETWEEN ? AND ?
        GROUP BY employee_id
      ) att ON att.employee_id = e.id
      LEFT JOIN (
        SELECT employee_id,
               ROUND(SUM(pay_hours), 2) AS ot_hours,
               ROUND(SUM(pay_amount), 2) AS ot_pay
        FROM overtime_entries
        WHERE status='approved' AND ot_date BETWEEN ? AND ?
        GROUP BY employee_id
      ) ot ON ot.employee_id = e.id
      WHERE " . implode(' AND ', $wfWhere) . "
      ORDER BY hours_worked DESC, e.full_name
    ";
    $wfBind = array_merge(
        [$from->format('Y-m-d'), $to->format('Y-m-d'), $from->format('Y-m-d'), $to->format('Y-m-d')],
        $wfParams
    );
    $stWf = $conn->prepare($sqlWf);
    $stWf->execute($wfBind);
    $rows = $stWf->fetchAll(PDO::FETCH_ASSOC);

    $empIds = array_map(static fn($r) => (int)$r['employee_id'], $rows);
    if ($empIds) {
        $ph = implode(',', array_fill(0, count($empIds), '?'));
        $qPrev = $conn->prepare("
          SELECT employee_id, ROUND(SUM(COALESCE(hours,0)),2) AS h
          FROM attendance
          WHERE work_date BETWEEN ? AND ?
            AND employee_id IN ($ph)
          GROUP BY employee_id
        ");
        $qPrev->execute(array_merge([$prevFrom->format('Y-m-d'), $prevTo->format('Y-m-d')], $empIds));
        foreach ($qPrev->fetchAll(PDO::FETCH_ASSOC) as $x) {
            $prev[(int)$x['employee_id']] = ['h' => (float)$x['h'], 'r' => 0.0];
        }
    }

    foreach ($rows as &$r) {
        $hours = (float)$r['hours_worked'];
        $salary = max(0.0, (float)$r['monthly_salary']);
        $cost = round($salary * $monthsFactor + (float)$r['ot_pay'], 2);
        $attendedSpan = max(0, (int)$r['present_days'] + (int)$r['absent_days']);
        $expectedHours = 8.0 * max(1, $attendedSpan);
        // Soft attendance-density signal only — not a Confirmed commercial target.
        $util = $attendedSpan > 0 ? round(100.0 * $hours / $expectedHours, 1) : null;

        $r['jobs'] = 0;
        $r['worker_id'] = 0;
        $r['revenue_incl_vat'] = 0.0;
        $r['capacity_month'] = null;
        $r['utilization'] = $util;
        $r['cost_prorated'] = $cost;
        $r['net_contrib'] = null;
        $r['target_hours'] = null;
        $r['achv_pct'] = null;
        $r['rev_per_hr'] = null;
        $r['net_per_hr'] = null;
        $r['margin_pct'] = null;

        $totals['employees'] += 1;
        $totals['hours'] += $hours;
        $totals['cost'] += $cost;
        $totals['present'] += (int)$r['present_days'];
        $totals['absent'] += (int)$r['absent_days'];
        $totals['ot_hours'] += (float)$r['ot_hours'];
    }
    unset($r);

    $top = array_slice($rows, 0, 25);
    $chartLabels = array_map(static fn($r) => $r['worker_name'], $top);
    $chartHours = array_map(static fn($r) => (float)$r['hours_worked'], $top);
    $chartOt = array_map(static fn($r) => (float)$r['ot_hours'], $top);
    $chartRev = array_fill(0, count($chartLabels), 0);

    if (isset($_GET['export']) && $_GET['export'] === 'csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="workforce_performance_' . $from->format('Ymd') . '_to_' . $to->format('Ymd') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Employee', 'Code', 'Company', 'Department', 'Present', 'Absent', 'Hours', 'ΔH', 'OT(h)', 'OT pay', 'Est. cost (prorated)']);
        foreach ($rows as $r) {
            $p = $prev[(int)$r['employee_id']] ?? ['h' => 0];
            $dH = (float)$r['hours_worked'] - (float)$p['h'];
            fputcsv($out, [
                $r['worker_name'],
                $r['employee_code'],
                $r['company_name'],
                $r['department_name'],
                (int)$r['present_days'],
                (int)$r['absent_days'],
                number_format((float)$r['hours_worked'], 2, '.', ''),
                number_format($dH, 2, '.', ''),
                number_format((float)$r['ot_hours'], 2, '.', ''),
                number_format((float)$r['ot_pay'], 2, '.', ''),
                number_format((float)$r['cost_prorated'], 2, '.', ''),
            ]);
        }
        fclose($out);
        exit;
    }
}

$companyScopeLabel = hr_company_scope_label($companies, $selectedCompanyId);
$exportQs = http_build_query([
    'view' => $view,
    'from' => $from->format('Y-m-d'),
    'to' => $to->format('Y-m-d'),
    'company_id' => $selectedCompanyId,
    'department_id' => $departmentId,
    'worker_id' => $workerA,
    'worker_id_b' => $workerB,
    'employee_id' => $employeeA,
    'export' => 'csv',
]);
