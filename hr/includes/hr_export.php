<?php
/**
 * Shared HR export helpers.
 *
 * Streams a result set as a download and stops the request, so callers must
 * dispatch before any layout output. Headers are keyed by row column name:
 * ['column' => 'Label'], which fixes both the column order and the labels.
 */

function hr_export_csv(string $filename, array $headers, array $rows): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    // BOM helps Excel open UTF-8 CSV with Arabic / special characters correctly.
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, $headers);
    foreach ($rows as $row) {
        $line = [];
        foreach ($headers as $key => $label) {
            $line[] = $row[$key] ?? '';
        }
        fputcsv($out, $line);
    }
    fclose($out);
    exit;
}

function hr_export_excel(string $filename, array $headers, array $rows): void
{
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "\xEF\xBB\xBF";
    echo "<table border=\"1\"><thead><tr>";
    foreach ($headers as $label) {
        echo '<th>' . htmlspecialchars((string)$label, ENT_QUOTES, 'UTF-8') . '</th>';
    }
    echo "</tr></thead><tbody>";
    foreach ($rows as $row) {
        echo "<tr>";
        foreach ($headers as $key => $label) {
            echo '<td>' . htmlspecialchars((string)($row[$key] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>';
        }
        echo "</tr>";
    }
    echo "</tbody></table>";
    exit;
}
