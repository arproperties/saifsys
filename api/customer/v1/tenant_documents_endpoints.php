<?php
/**
 * Tenant Documents — lease-scoped library, contracts, invoices, notices, receipts (read-only).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../tenant_portal/includes/tenancy_contract_scan_helper.php';
require_once __DIR__ . '/../../../tenant_portal/includes/renewal_portal_helper.php';

/**
 * @return list<string>
 */
function customer_api_tenant_document_categories(): array {
    return ['contract', 'invoice', 'notice', 'receipt', 'other'];
}

function customer_api_tenant_document_category_label(string $category): string {
    return match ($category) {
        'contract' => 'Contracts',
        'invoice' => 'Invoices',
        'notice' => 'Notices',
        'receipt' => 'Receipts',
        default => 'Other',
    };
}

function customer_api_tenant_document_source_label(string $source): string {
    return match ($source) {
        'library' => 'Property document',
        'tenant_scan' => 'Signed contract scan',
        'lease_contract' => 'Tenancy contract',
        'lease_ejari' => 'Ejari',
        'invoice' => 'Invoice',
        'renewal_notice' => 'Renewal notice',
        'payment_receipt' => 'Payment receipt',
        default => 'Document',
    };
}

function customer_api_tenant_document_category_for_type(string $documentType, string $source): string {
    if (in_array($source, ['invoice'], true)) {
        return 'invoice';
    }
    if (in_array($source, ['renewal_notice'], true)) {
        return 'notice';
    }
    if (in_array($source, ['payment_receipt'], true)) {
        return 'receipt';
    }
    if (in_array($source, ['tenant_scan', 'lease_contract', 'lease_ejari'], true)) {
        return 'contract';
    }
    $t = strtolower($documentType);
    if (in_array($t, ['lease_agreement', 'ejari', 'tenant_signed_tenancy_scan', 'tenant_signed_scan'], true)) {
        return 'contract';
    }
    if (in_array($t, ['tenant_id', 'tenant_passport', 'tenant_visa', 'noc', 'insurance'], true)) {
        return 'other';
    }
    return 'other';
}

function customer_api_tenant_document_project_root(): string {
    return dirname(__DIR__, 3);
}

/**
 * @return array{0:string,1:string}|null [absolutePath, root]
 */
function customer_api_tenant_document_resolve_relative_path(string $relativePath): ?array {
    $rel = ltrim(str_replace('\\', '/', $relativePath), '/');
    if ($rel === '' || str_contains($rel, '..')) {
        return null;
    }
    $basePath = customer_api_tenant_document_project_root();
    $abs = realpath($basePath . '/' . $rel);
    $root = realpath($basePath);
    if ($abs === false || $root === false || !is_readable($abs) || !is_file($abs)) {
        return null;
    }
    if (strpos($abs, $root) !== 0) {
        return null;
    }
    return [$abs, $root];
}

function customer_api_tenant_document_guess_mime(string $absolutePath, ?string $fallback = null): string {
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $detected = finfo_file($finfo, $absolutePath) ?: null;
            finfo_close($finfo);
            if ($detected) {
                return $detected;
            }
        }
    }
    $ext = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));
    return match ($ext) {
        'pdf' => 'application/pdf',
        'png' => 'image/png',
        'jpg', 'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        default => $fallback ?? 'application/octet-stream',
    };
}

/**
 * URL path segment for document source (hyphens — route regex does not allow underscores).
 */
function customer_api_tenant_document_route_segment(string $source): string {
    return str_replace('_', '-', strtolower(trim($source)));
}

function customer_api_tenant_document_download_path(int $leaseId, string $source, int $refId): string {
    $ref = $refId > 0 ? (string)$refId : '0';
    $segment = customer_api_tenant_document_route_segment($source);
    return "tenant/leases/{$leaseId}/documents/{$segment}/{$ref}/download";
}

function customer_api_tenant_document_doc_key(string $source, int $refId): string {
    return $refId > 0 ? "{$source}:{$refId}" : $source;
}

/**
 * @param array<string,mixed> $overrides
 * @return array<string,mixed>
 */
function customer_api_tenant_document_format_item(
    int $leaseId,
    string $source,
    int $refId,
    string $category,
    string $title,
    array $overrides = []
): array {
    $available = ($overrides['available'] ?? true) === true;
    $item = [
        'doc_key' => (string)($overrides['doc_key'] ?? customer_api_tenant_document_doc_key($source, $refId)),
        'source' => $source,
        'ref_id' => $refId,
        'category' => $category,
        'category_label' => customer_api_tenant_document_category_label($category),
        'source_label' => (string)($overrides['source_label'] ?? customer_api_tenant_document_source_label($source)),
        'document_type' => (string)($overrides['document_type'] ?? $source),
        'title' => $title,
        'subtitle' => $overrides['subtitle'] ?? null,
        'document_date' => $overrides['document_date'] ?? null,
        'issue_date' => $overrides['issue_date'] ?? null,
        'expiry_date' => $overrides['expiry_date'] ?? null,
        'status' => (string)($overrides['status'] ?? 'active'),
        'mime_type' => $overrides['mime_type'] ?? 'application/pdf',
        'available' => $available,
        'unavailable_reason' => $available ? null : (string)($overrides['unavailable_reason'] ?? 'Not available'),
        'download_path' => $available
            ? (string)($overrides['download_path'] ?? customer_api_tenant_document_download_path($leaseId, $source, $refId))
            : null,
    ];
    return $item;
}

/**
 * @return list<string>
 */
function customer_api_tenant_document_library_columns(PDO $conn): array {
    static $cached = null;
    if (is_array($cached)) {
        return $cached;
    }
    $cols = ['id', 'document_type', 'file_name', 'file_path', 'created_at'];
    foreach (['document_name', 'document_number', 'issue_date', 'expiry_date', 'status', 'mime_type'] as $c) {
        try {
            $conn->query("SELECT {$c} FROM re_documents LIMIT 1");
            $cols[] = $c;
        } catch (Throwable $e) {
            // optional column
        }
    }
    $cached = array_values(array_unique($cols));
    return $cached;
}

/**
 * @param array{lease_id:int,tenant_id:int,unit_id:int,company_id:int} $leaseCtx
 * @return list<array<string,mixed>>
 */
function customer_api_tenant_documents_collect(PDO $conn, array $leaseCtx): array {
    $leaseId = $leaseCtx['lease_id'];
    $unitId = $leaseCtx['unit_id'];
    $companyId = $leaseCtx['company_id'];
    $items = [];

    try {
        $selectCols = implode(', ', customer_api_tenant_document_library_columns($conn));
        $stmt = $conn->prepare("
            SELECT {$selectCols}
            FROM re_documents
            WHERE company_id = ?
              AND (
                (related_type = 'lease' AND related_id = ?)
                OR (related_type = 'unit' AND related_id = ?)
              )
              AND file_path IS NOT NULL AND file_path <> ''
            ORDER BY created_at DESC, id DESC
            LIMIT 200
        ");
        $stmt->execute([$companyId, $leaseId, $unitId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $docId = (int)($row['id'] ?? 0);
            $docType = (string)($row['document_type'] ?? 'other');
            $title = (string)(($row['document_name'] ?? '') ?: ($row['file_name'] ?? 'Document'));
            $hasFile = customer_api_tenant_document_resolve_relative_path((string)($row['file_path'] ?? '')) !== null;
            $items[] = customer_api_tenant_document_format_item(
                $leaseId,
                'library',
                $docId,
                customer_api_tenant_document_category_for_type($docType, 'library'),
                $title,
                [
                    'document_type' => $docType,
                    'subtitle' => !empty($row['document_number']) ? (string)$row['document_number'] : null,
                    'document_date' => $row['created_at'] !== null ? (string)$row['created_at'] : null,
                    'issue_date' => !empty($row['issue_date']) ? (string)$row['issue_date'] : null,
                    'expiry_date' => !empty($row['expiry_date']) ? (string)$row['expiry_date'] : null,
                    'status' => (string)($row['status'] ?? 'active'),
                    'mime_type' => !empty($row['mime_type']) ? (string)$row['mime_type'] : 'application/pdf',
                    'available' => $hasFile,
                    'unavailable_reason' => $hasFile ? null : 'File is not available on the server',
                ]
            );
        }
    } catch (Throwable $e) {
        // re_documents may be missing on older DBs
    }

    if (tenancy_contract_scan_table_exists($conn)) {
        try {
            $ts = $conn->prepare("
                SELECT id, original_filename, stored_path, uploaded_at
                FROM re_tenancy_contract_tenant_uploads
                WHERE company_id = ? AND lease_id = ? AND superseded_at IS NULL
                ORDER BY uploaded_at DESC
            ");
            $ts->execute([$companyId, $leaseId]);
            foreach ($ts->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $scanId = (int)($row['id'] ?? 0);
                $hasFile = customer_api_tenant_document_resolve_relative_path((string)($row['stored_path'] ?? '')) !== null;
                $items[] = customer_api_tenant_document_format_item(
                    $leaseId,
                    'tenant_scan',
                    $scanId,
                    'contract',
                    'Your signed tenancy contract (scan)',
                    [
                        'document_type' => 'tenant_signed_scan',
                        'subtitle' => (string)($row['original_filename'] ?? ''),
                        'document_date' => $row['uploaded_at'] !== null ? (string)$row['uploaded_at'] : null,
                        'available' => $hasFile,
                        'unavailable_reason' => $hasFile ? null : 'File is not available',
                    ]
                );
            }
        } catch (Throwable $e) {
        }
    }

    try {
        $leaseStmt = $conn->prepare("
            SELECT generated_contract_path, ejari_document_path
            FROM re_leases
            WHERE id = ? AND company_id = ?
            LIMIT 1
        ");
        $leaseStmt->execute([$leaseId, $companyId]);
        $leaseRow = $leaseStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $contractRel = trim((string)($leaseRow['generated_contract_path'] ?? ''));
        if ($contractRel !== '') {
            $hasFile = customer_api_tenant_document_resolve_relative_path($contractRel) !== null;
            $items[] = customer_api_tenant_document_format_item(
                $leaseId,
                'lease_contract',
                0,
                'contract',
                'Tenancy contract',
                [
                    'doc_key' => 'lease_contract',
                    'document_type' => 'lease_agreement',
                    'subtitle' => 'Generated contract PDF',
                    'available' => $hasFile,
                    'unavailable_reason' => $hasFile ? null : 'Contract file is not available yet',
                    'download_path' => customer_api_tenant_document_download_path($leaseId, 'lease-contract', 0),
                ]
            );
        }

        $ejariRel = trim((string)($leaseRow['ejari_document_path'] ?? ''));
        if ($ejariRel !== '') {
            $hasFile = customer_api_tenant_document_resolve_relative_path($ejariRel) !== null;
            $items[] = customer_api_tenant_document_format_item(
                $leaseId,
                'lease_ejari',
                0,
                'contract',
                'Ejari certificate',
                [
                    'doc_key' => 'lease_ejari',
                    'document_type' => 'ejari',
                    'subtitle' => 'Ejari registration document',
                    'available' => $hasFile,
                    'unavailable_reason' => $hasFile ? null : 'Ejari file is not available',
                    'download_path' => customer_api_tenant_document_download_path($leaseId, 'lease-ejari', 0),
                ]
            );
        }
    } catch (Throwable $e) {
    }

    try {
        $inv = $conn->prepare("
            SELECT id, invoice_number, invoice_date, due_date, total_amount, status
            FROM re_invoices
            WHERE lease_id = ?
            ORDER BY invoice_date DESC, id DESC
        ");
        $inv->execute([$leaseId]);
        foreach ($inv->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $invId = (int)($row['id'] ?? 0);
            $num = (string)($row['invoice_number'] ?? '');
            $items[] = customer_api_tenant_document_format_item(
                $leaseId,
                'invoice',
                $invId,
                'invoice',
                $num !== '' ? "Invoice {$num}" : 'Invoice',
                [
                    'subtitle' => isset($row['total_amount'])
                        ? number_format((float)$row['total_amount'], 2, '.', '') . ' AED'
                        : null,
                    'document_date' => !empty($row['invoice_date']) ? (string)$row['invoice_date'] : null,
                    'issue_date' => !empty($row['invoice_date']) ? (string)$row['invoice_date'] : null,
                    'status' => (string)($row['status'] ?? ''),
                    'mime_type' => 'application/pdf',
                    'available' => true,
                ]
            );
        }
    } catch (Throwable $e) {
    }

    try {
        // Prefer centralized payment history (mode-aware). Do not hardcode status=paid.
        if (!function_exists('re_lease_payment_history')) {
            require_once __DIR__ . '/../../../modules/realestate/includes/lease_financial_summary_service.php';
        }
        $hist = re_lease_payment_history($conn, $companyId, $leaseId, 100);
        foreach ($hist['receipts'] ?? [] as $row) {
            $payId = (int)($row['payment_id'] ?? 0);
            $receiptNo = trim((string)($row['receipt_number'] ?? ''));
            $ref = trim((string)($row['reference_number'] ?? ''));
            $label = $receiptNo !== '' ? "Receipt {$receiptNo}" : ($ref !== '' ? "Payment {$ref}" : 'Payment receipt');
            $docStatus = (string)($row['display_status'] ?? '');
            if ($docStatus === '') {
                $docStatus = (string)($row['allocation_status'] ?? 'recorded');
            }
            $items[] = customer_api_tenant_document_format_item(
                $leaseId,
                'payment_receipt',
                $payId,
                'receipt',
                $label,
                [
                    'subtitle' => (string)($row['amount'] ?? '0.00') . ' AED',
                    'document_date' => !empty($row['payment_date']) ? (string)$row['payment_date'] : null,
                    'status' => $docStatus,
                    'mime_type' => 'application/pdf',
                    'available' => true,
                ]
            );
        }
    } catch (Throwable $e) {
    }

    try {
        $rw = $conn->prepare("
            SELECT rw.id, rw.status, rw.initiated_date, rw.notice_sent_at, rw.renewal_notice_pdf_path
            FROM re_lease_renewal_workflows rw
            INNER JOIN re_leases l ON l.id = rw.lease_id AND l.company_id = ?
            WHERE (rw.lease_id = ? OR rw.new_lease_id = ?)
              AND rw.status <> 'initiated'
              AND rw.renewal_notice_pdf_path IS NOT NULL
              AND TRIM(rw.renewal_notice_pdf_path) <> ''
            ORDER BY rw.id DESC
        ");
        $rw->execute([$companyId, $leaseId, $leaseId]);
        foreach ($rw->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $wfId = (int)($row['id'] ?? 0);
            $rel = trim((string)($row['renewal_notice_pdf_path'] ?? ''));
            $hasFile = $rel !== '' && customer_api_tenant_document_resolve_relative_path($rel) !== null;
            $statusLabel = function_exists('tenant_renewal_status_label')
                ? tenant_renewal_status_label((string)($row['status'] ?? ''))
                : (string)($row['status'] ?? '');
            $items[] = customer_api_tenant_document_format_item(
                $leaseId,
                'renewal_notice',
                $wfId,
                'notice',
                'Lease renewal notice',
                [
                    'subtitle' => $statusLabel,
                    'document_date' => !empty($row['notice_sent_at'])
                        ? (string)$row['notice_sent_at']
                        : (!empty($row['initiated_date']) ? (string)$row['initiated_date'] : null),
                    'status' => (string)($row['status'] ?? ''),
                    'available' => $hasFile,
                    'unavailable_reason' => $hasFile ? null : 'Notice PDF is not available',
                ]
            );
        }
    } catch (Throwable $e) {
    }

    usort($items, static function (array $a, array $b): int {
        $ta = strtotime((string)($a['document_date'] ?? '1970-01-01')) ?: 0;
        $tb = strtotime((string)($b['document_date'] ?? '1970-01-01')) ?: 0;
        return $tb <=> $ta;
    });

    return $items;
}

function customer_api_tenant_dompdf_render(string $html): ?string {
    // Customer API front controller does not always load Composer — ensure Dompdf is available.
    if (!class_exists('\Dompdf\Dompdf')) {
        $autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
        if (is_file($autoload)) {
            require_once $autoload;
        }
    }
    if (!class_exists('\Dompdf\Dompdf')) {
        return null;
    }
    try {
        $options = new \Dompdf\Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $out = $dompdf->output();
        return is_string($out) && $out !== '' ? $out : null;
    } catch (Throwable $e) {
        error_log('customer_api_tenant_dompdf_render: ' . $e->getMessage());
        return null;
    }
}

/**
 * @return array{0:string,1:string}|null
 */
function customer_api_tenant_document_load_invoice(PDO $conn, int $invoiceId, int $leaseId, int $companyId): ?array {
    $stmt = $conn->prepare("
        SELECT i.*, t.first_name, t.last_name, u.unit_number, b.name AS building_name
        FROM re_invoices i
        JOIN re_leases l ON l.id = i.lease_id
        JOIN re_tenants t ON t.id = l.tenant_id
        JOIN re_units u ON u.id = l.unit_id
        JOIN re_buildings b ON b.id = u.building_id
        WHERE i.id = ? AND i.lease_id = ? AND i.company_id = ?
        LIMIT 1
    ");
    $stmt->execute([$invoiceId, $leaseId, $companyId]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$invoice) {
        return null;
    }

    $itemsStmt = $conn->prepare('SELECT * FROM re_invoice_items WHERE invoice_id = ? ORDER BY display_order, id');
    $itemsStmt->execute([$invoiceId]);
    $lines = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    $rowsHtml = '';
    foreach ($lines as $item) {
        $rowsHtml .= '<tr><td>' . htmlspecialchars((string)($item['item_name'] ?? '')) . '</td>';
        $rowsHtml .= '<td style="text-align:right">' . number_format((float)($item['quantity'] ?? 0), 2) . '</td>';
        $rowsHtml .= '<td style="text-align:right">' . number_format((float)($item['unit_price'] ?? 0), 2) . ' AED</td>';
        $rowsHtml .= '<td style="text-align:right">' . number_format((float)($item['line_total'] ?? 0), 2) . ' AED</td></tr>';
    }

    $tenantName = htmlspecialchars(trim((string)($invoice['first_name'] ?? '') . ' ' . (string)($invoice['last_name'] ?? '')));
    $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>
        body{font-family:DejaVu Sans,Arial,sans-serif;font-size:12px;padding:24px}
        h1{font-size:20px} table{width:100%;border-collapse:collapse;margin:16px 0}
        th,td{border-bottom:1px solid #ddd;padding:8px} th{background:#f5f5f5;text-align:left}
        .right{text-align:right}
    </style></head><body>
    <h1>INVOICE</h1>
    <p><strong>Invoice #:</strong> ' . htmlspecialchars((string)($invoice['invoice_number'] ?? '')) . '</p>
    <p><strong>Date:</strong> ' . htmlspecialchars((string)($invoice['invoice_date'] ?? '')) . '</p>
    <p><strong>Due:</strong> ' . htmlspecialchars((string)($invoice['due_date'] ?? '')) . '</p>
    <p><strong>Bill to:</strong> ' . $tenantName . '<br>'
        . htmlspecialchars((string)($invoice['building_name'] ?? '')) . ' — Unit '
        . htmlspecialchars((string)($invoice['unit_number'] ?? '')) . '</p>
    <table><thead><tr><th>Description</th><th class="right">Qty</th><th class="right">Unit</th><th class="right">Total</th></tr></thead><tbody>'
        . $rowsHtml . '</tbody></table>
    <p class="right"><strong>Total: ' . number_format((float)($invoice['total_amount'] ?? 0), 2) . ' AED</strong></p>
    </body></html>';

    $filename = 'Invoice_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', (string)($invoice['invoice_number'] ?? (string)$invoiceId)) . '.pdf';
    return [$html, $filename];
}

/**
 * @return array{0:string,1:string}|null
 */
function customer_api_tenant_document_load_payment_receipt(PDO $conn, int $paymentId, int $leaseId, int $companyId): ?array {
    $stmt = $conn->prepare("
        SELECT p.*, t.first_name, t.last_name, u.unit_number, b.name AS building_name, l.lease_number
        FROM re_payments p
        JOIN re_leases l ON l.id = p.lease_id
        JOIN re_tenants t ON t.id = l.tenant_id
        JOIN re_units u ON u.id = l.unit_id
        JOIN re_buildings b ON b.id = u.building_id
        WHERE p.id = ? AND p.lease_id = ? AND p.company_id = ?
        LIMIT 1
    ");
    $stmt->execute([$paymentId, $leaseId, $companyId]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$payment) {
        return null;
    }

    $tenantName = htmlspecialchars(trim((string)($payment['first_name'] ?? '') . ' ' . (string)($payment['last_name'] ?? '')));
    $receiptNo = htmlspecialchars((string)($payment['receipt_number'] ?? ('PAY-' . $paymentId)));
    $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>
        body{font-family:DejaVu Sans,Arial,sans-serif;font-size:12px;padding:24px}
        h1{font-size:20px}
    </style></head><body>
    <h1>PAYMENT RECEIPT</h1>
    <p><strong>Receipt #:</strong> ' . $receiptNo . '</p>
    <p><strong>Date:</strong> ' . htmlspecialchars((string)($payment['payment_date'] ?? '')) . '</p>
    <p><strong>Lease:</strong> ' . htmlspecialchars((string)($payment['lease_number'] ?? '')) . '</p>
    <p><strong>Received from:</strong> ' . $tenantName . '<br>'
        . htmlspecialchars((string)($payment['building_name'] ?? '')) . ' — Unit '
        . htmlspecialchars((string)($payment['unit_number'] ?? '')) . '</p>
    <p><strong>Amount:</strong> ' . number_format((float)($payment['amount'] ?? 0), 2) . ' AED</p>
    <p><strong>Method:</strong> ' . htmlspecialchars((string)($payment['payment_method'] ?? '')) . '</p>
    <p style="margin-top:24px;color:#666">Computer-generated receipt.</p>
    </body></html>';

    $filename = 'Receipt_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', (string)($payment['receipt_number'] ?? (string)$paymentId)) . '.pdf';
    return [$html, $filename];
}

function customer_api_tenant_document_stream_generated_pdf(string $html, string $filename): void {
    $pdf = customer_api_tenant_dompdf_render($html);
    if ($pdf !== null && $pdf !== '') {
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . addslashes($filename) . '"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
        exit;
    }
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: inline; filename="' . addslashes(preg_replace('/\.pdf$/i', '.html', $filename)) . '"');
    echo $html;
    exit;
}

function customer_api_tenant_document_stream_file(
    string $absolutePath,
    string $filename,
    ?string $mime = null,
    bool $inline = true
): void {
    $mime = $mime ?? customer_api_tenant_document_guess_mime($absolutePath);
    $disposition = $inline ? 'inline' : 'attachment';
    header('Content-Type: ' . $mime);
    header('Content-Disposition: ' . $disposition . '; filename="' . addslashes($filename) . '"');
    header('Content-Length: ' . filesize($absolutePath));
    readfile($absolutePath);
    exit;
}

/**
 * Normalise route source segment to internal key.
 */
function customer_api_tenant_document_normalise_source(string $source): string {
    return str_replace('-', '_', strtolower(trim($source)));
}

function customer_api_tenant_handle_documents_list(PDO $conn, int $leaseId): void {
    $ctx = customer_api_tenant_require_access($conn);
    $leaseCtx = customer_api_tenant_services_lease_context($conn, $ctx, $leaseId);
    if ($leaseCtx === null) {
        customer_api_send_error('not_found', 'Lease not found', 404);
    }

    $documents = customer_api_tenant_documents_collect($conn, $leaseCtx);
    customer_api_send_ok([
        'lease_id' => $leaseId,
        'documents' => $documents,
        'categories' => array_map(
            static fn (string $c) => [
                'key' => $c,
                'label' => customer_api_tenant_document_category_label($c),
            ],
            customer_api_tenant_document_categories()
        ),
    ]);
}

function customer_api_tenant_handle_document_detail(
    PDO $conn,
    int $leaseId,
    string $source,
    int $refId
): void {
    $ctx = customer_api_tenant_require_access($conn);
    $leaseCtx = customer_api_tenant_services_lease_context($conn, $ctx, $leaseId);
    if ($leaseCtx === null) {
        customer_api_send_error('not_found', 'Lease not found', 404);
    }

    $sourceKey = customer_api_tenant_document_normalise_source($source);
    $documents = customer_api_tenant_documents_collect($conn, $leaseCtx);
    foreach ($documents as $doc) {
        if ((string)($doc['source'] ?? '') === $sourceKey && (int)($doc['ref_id'] ?? 0) === $refId) {
            customer_api_send_ok(['document' => $doc]);
        }
    }
    customer_api_send_error('not_found', 'Document not found', 404);
}

function customer_api_tenant_handle_document_download(
    PDO $conn,
    int $leaseId,
    string $source,
    int $refId
): void {
    $ctx = customer_api_tenant_require_access($conn);
    $leaseCtx = customer_api_tenant_services_lease_context($conn, $ctx, $leaseId);
    if ($leaseCtx === null) {
        customer_api_send_error('not_found', 'Lease not found', 404);
    }

    $companyId = $leaseCtx['company_id'];
    $sourceKey = customer_api_tenant_document_normalise_source($source);
    $inline = strtolower((string)($_GET['disposition'] ?? 'inline')) !== 'attachment';

    switch ($sourceKey) {
        case 'library':
            if ($refId <= 0) {
                customer_api_send_error('validation_error', 'Invalid document id', 400);
            }
            $stmt = $conn->prepare("
                SELECT id, file_name, file_path, mime_type
                FROM re_documents
                WHERE id = ? AND company_id = ?
                  AND (
                    (related_type = 'lease' AND related_id = ?)
                    OR (related_type = 'unit' AND related_id = ?)
                  )
                LIMIT 1
            ");
            $stmt->execute([$refId, $companyId, $leaseId, $leaseCtx['unit_id']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row || empty($row['file_path'])) {
                customer_api_send_error('not_found', 'Document not found', 404);
            }
            $resolved = customer_api_tenant_document_resolve_relative_path((string)$row['file_path']);
            if ($resolved === null) {
                customer_api_send_error('not_found', 'File not found', 404);
            }
            [$abs] = $resolved;
            $name = (string)(($row['file_name'] ?? '') ?: basename((string)$row['file_path']));
            customer_api_tenant_document_stream_file(
                $abs,
                $name,
                !empty($row['mime_type']) ? (string)$row['mime_type'] : null,
                $inline
            );
            break;

        case 'tenant_scan':
            if ($refId <= 0 || !tenancy_contract_scan_table_exists($conn)) {
                customer_api_send_error('not_found', 'Document not found', 404);
            }
            $st = $conn->prepare("
                SELECT stored_path, original_filename
                FROM re_tenancy_contract_tenant_uploads
                WHERE id = ? AND company_id = ? AND lease_id = ? AND superseded_at IS NULL
                LIMIT 1
            ");
            $st->execute([$refId, $companyId, $leaseId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row || empty($row['stored_path'])) {
                customer_api_send_error('not_found', 'Document not found', 404);
            }
            $resolved = customer_api_tenant_document_resolve_relative_path((string)$row['stored_path']);
            if ($resolved === null) {
                customer_api_send_error('not_found', 'File not found', 404);
            }
            [$abs] = $resolved;
            $name = (string)(($row['original_filename'] ?? '') ?: 'signed_contract.pdf');
            customer_api_tenant_document_stream_file($abs, $name, 'application/pdf', $inline);
            break;

        case 'lease_contract':
            $st = $conn->prepare('SELECT generated_contract_path FROM re_leases WHERE id = ? AND company_id = ? LIMIT 1');
            $st->execute([$leaseId, $companyId]);
            $rel = trim((string)($st->fetchColumn() ?: ''));
            if ($rel === '') {
                customer_api_send_error('not_found', 'Contract not found', 404);
            }
            $resolved = customer_api_tenant_document_resolve_relative_path($rel);
            if ($resolved === null) {
                customer_api_send_error('not_found', 'File not found', 404);
            }
            [$abs] = $resolved;
            customer_api_tenant_document_stream_file(
                $abs,
                'tenancy_contract_' . $leaseId . '.pdf',
                'application/pdf',
                $inline
            );
            break;

        case 'lease_ejari':
            $st = $conn->prepare('SELECT ejari_document_path FROM re_leases WHERE id = ? AND company_id = ? LIMIT 1');
            $st->execute([$leaseId, $companyId]);
            $rel = trim((string)($st->fetchColumn() ?: ''));
            if ($rel === '') {
                customer_api_send_error('not_found', 'Ejari document not found', 404);
            }
            $resolved = customer_api_tenant_document_resolve_relative_path($rel);
            if ($resolved === null) {
                customer_api_send_error('not_found', 'File not found', 404);
            }
            [$abs] = $resolved;
            customer_api_tenant_document_stream_file($abs, 'ejari_' . $leaseId . '.pdf', 'application/pdf', $inline);
            break;

        case 'invoice':
            if ($refId <= 0) {
                customer_api_send_error('validation_error', 'Invalid invoice id', 400);
            }
            $built = customer_api_tenant_document_load_invoice($conn, $refId, $leaseId, $companyId);
            if ($built === null) {
                customer_api_send_error('not_found', 'Invoice not found', 404);
            }
            customer_api_tenant_document_stream_generated_pdf($built[0], $built[1]);
            break;

        case 'payment_receipt':
            if ($refId <= 0) {
                customer_api_send_error('validation_error', 'Invalid payment id', 400);
            }
            $built = customer_api_tenant_document_load_payment_receipt($conn, $refId, $leaseId, $companyId);
            if ($built === null) {
                customer_api_send_error('not_found', 'Payment not found', 404);
            }
            customer_api_tenant_document_stream_generated_pdf($built[0], $built[1]);
            break;

        case 'renewal_notice':
            if ($refId <= 0) {
                customer_api_send_error('validation_error', 'Invalid renewal workflow id', 400);
            }
            $wf = tenant_renewal_fetch_workflow($conn, $refId, $leaseId, $companyId);
            $rel = trim((string)($wf['renewal_notice_pdf_path'] ?? ''));
            if ($rel === '') {
                customer_api_send_error('not_found', 'Renewal notice not found', 404);
            }
            $resolved = customer_api_tenant_document_resolve_relative_path($rel);
            if ($resolved === null) {
                customer_api_send_error('not_found', 'File not found', 404);
            }
            [$abs] = $resolved;
            customer_api_tenant_document_stream_file($abs, 'renewal_notice_' . $refId . '.pdf', 'application/pdf', $inline);
            break;

        default:
            customer_api_send_error('validation_error', 'Unknown document source', 400);
    }
}
