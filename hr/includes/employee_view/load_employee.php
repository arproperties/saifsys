<?php

function employee_view_load_employee(PDO $conn, string $employeeId): ?array
{
    $sql = "
        SELECT e.*, d.name AS dept_name, l.name AS loc_name, c.name AS company_name
        FROM employees e
        LEFT JOIN departments d ON d.id = e.department_id
        LEFT JOIN locations l ON l.id = e.location_id
        LEFT JOIN companies c ON c.id = e.company_id
        WHERE %s = ?
        LIMIT 1
    ";

    $lookupColumn = ctype_digit($employeeId) ? 'e.id' : 'e.employee_code';
    $stmt = $conn->prepare(sprintf($sql, $lookupColumn));
    $stmt->execute([$employeeId]);
    $employee = $stmt->fetch(PDO::FETCH_ASSOC);

    return $employee ?: null;
}
