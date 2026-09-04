<?php
/**
 * Helpers for employee document file paths.
 *
 * Older HR profile uploads stored only a basename like doc_87_x.jpg.
 * Newer document uploads store uploads/docs/EMP/file.pdf. These helpers
 * normalize both formats for browser links and filesystem operations.
 */

function hr_document_file_relative_path(?string $filePath): string
{
    $path = trim((string)$filePath);
    if ($path === '' || preg_match('#^https?://#i', $path)) {
        return $path;
    }

    $path = str_replace('\\', '/', $path);
    $path = ltrim($path, '/');

    if (strpos($path, 'uploads/docs/') === 0) {
        return $path;
    }

    if (strpos($path, 'uploads/') === 0) {
        return $path;
    }

    return 'uploads/docs/' . basename($path);
}

function hr_document_file_url(?string $filePath): string
{
    $path = trim((string)$filePath);
    if ($path === '') {
        return '';
    }

    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }

    $relative = hr_document_file_relative_path($path);
    $parts = array_map('rawurlencode', explode('/', $relative));

    return '../' . implode('/', $parts);
}

function hr_document_file_absolute_path(?string $filePath): string
{
    $path = trim((string)$filePath);
    if ($path === '' || preg_match('#^https?://#i', $path)) {
        return '';
    }

    return dirname(__DIR__, 2) . '/' . hr_document_file_relative_path($path);
}
