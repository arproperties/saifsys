<?php
/**
 * Employee lifecycle helpers.
 *
 * Former employees stay in the system for history, payroll, and documents, but
 * default HR operations should focus on the current workforce.
 */

function hr_employee_current_statuses(): array
{
    return ['active', 'on_leave', 'notice_period'];
}

function hr_employee_left_statuses(): array
{
    return ['resigned', 'terminated', 'not_renewed', 'inactive'];
}

function hr_employee_status_options(): array
{
    return [
        'active' => 'Active',
        'on_leave' => 'On leave',
        'notice_period' => 'Notice period',
        'resigned' => 'Resigned',
        'terminated' => 'Terminated',
        'not_renewed' => 'Not renewed',
        'inactive' => 'Inactive',
    ];
}

function hr_employee_exit_type_options(): array
{
    return [
        '' => '—',
        'resigned' => 'Resigned',
        'terminated' => 'Terminated',
        'not_renewed' => 'Not renewed',
        'other' => 'Other',
    ];
}

function hr_employee_settlement_options(): array
{
    return [
        'not_started' => 'Not started',
        'in_progress' => 'In progress',
        'completed' => 'Completed',
        'not_applicable' => 'Not applicable',
    ];
}

function hr_employee_status_label(?string $status): string
{
    $options = hr_employee_status_options();
    return $options[$status ?: 'inactive'] ?? ucfirst(str_replace('_', ' ', (string)$status));
}

function hr_employee_status_badge(?string $status): string
{
    $status = $status ?: 'inactive';
    $map = [
        'active' => 'success',
        'on_leave' => 'info',
        'notice_period' => 'warning text-dark',
        'resigned' => 'secondary',
        'terminated' => 'dark',
        'not_renewed' => 'secondary',
        'inactive' => 'secondary',
    ];
    return $map[$status] ?? 'secondary';
}

function hr_employee_status_in_sql(array $statuses): string
{
    return implode(',', array_fill(0, count($statuses), '?'));
}

function hr_employee_is_current_status(?string $status): bool
{
    return in_array($status ?: '', hr_employee_current_statuses(), true);
}

function hr_employee_is_employed_for_period_sql(string $alias = 'e'): string
{
    return "
        ({$alias}.date_joined IS NULL OR {$alias}.date_joined = '0000-00-00' OR {$alias}.date_joined <= ?)
        AND (
            {$alias}.status IN (" . hr_employee_status_in_sql(hr_employee_current_statuses()) . ")
            OR (
                {$alias}.exit_date IS NOT NULL
                AND {$alias}.exit_date <> '0000-00-00'
                AND {$alias}.exit_date >= ?
            )
        )
    ";
}
