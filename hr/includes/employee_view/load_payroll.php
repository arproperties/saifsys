<?php

function employee_view_load_payslips(PDO $conn, int $employeeId): array
{
    $stmt = $conn->prepare("
        SELECT pr.id AS run_id,
               pr.period_from,
               pr.period_to,
               pr.status,
               pr.created_at,
               ROUND(pi.net_pay, 2) AS net_pay,
               c.name AS company_name
        FROM payroll_runs pr
        JOIN payroll_items pi ON pi.payroll_run_id = pr.id
        JOIN employees e ON e.id = pi.employee_id
        LEFT JOIN companies c ON c.id = pr.company_id
        WHERE pi.employee_id = ?
          AND pr.status IN ('open', 'finalized', 'paid')
          AND (pr.company_id IS NULL OR pr.company_id = 0 OR pr.company_id = e.company_id)
        ORDER BY pr.period_from DESC, pr.id DESC
    ");
    $stmt->execute([$employeeId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
