<?php
/**
 * Fleet moved from HR to the Operations module. The old hr/ addresses stay as
 * redirects so nothing bookmarked or linked breaks — and so the old pages, which
 * let the HR role in, are no longer there to open.
 */
require_once dirname(__DIR__, 2) . '/includes/url_helper.php';

function hr_fleet_moved(string $page): void
{
    $qs = (string)($_SERVER['QUERY_STRING'] ?? '');
    header('Location: ' . get_application_web_root() . '/modules/operations/' . $page . ($qs !== '' ? '?' . $qs : ''));
    exit;
}
