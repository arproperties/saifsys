<?php
/**
 * Tenant digital signature for the move-in / move-out inspection forms
 * (migrations/re_tenant_signatures.sql).
 *
 * The tenant signs on a pad in a pop-up (re_sig_modal_html()); the PNG is
 * saved next to the move-in / move-out files and its path kept on the record
 * (tenant_signature_path, tenant_signed_name, tenant_signed_at). The forms put
 * it in the Tenant signature box (re_sig_cell_html()).
 */

function re_sig_ready(PDO $conn, string $table): bool
{
    static $ready = [];
    if (!isset($ready[$table])) {
        try {
            $ready[$table] = in_array($table, ['re_move_ins', 're_move_outs'], true)
                && (bool)$conn->query("SHOW COLUMNS FROM `$table` LIKE 'tenant_signature_path'")->fetch();
        } catch (Throwable $e) {
            $ready[$table] = false;
        }
    }
    return $ready[$table];
}

/**
 * Save a signature sent by the pad (data:image/png;base64,...) into
 * uploads/<$relDir>. Returns the path relative to the project root.
 * @throws RuntimeException when the image is missing, too large or not a PNG
 */
function re_sig_save_png(string $dataUrl, string $relDir): string
{
    if (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', trim($dataUrl), $m)) {
        throw new RuntimeException('The signature is missing. Please sign in the box.');
    }
    $png = base64_decode($m[1], true);
    if ($png === false || strlen($png) < 100 || strlen($png) > 2 * 1024 * 1024) {
        throw new RuntimeException('The signature could not be read. Please sign again.');
    }
    $info = @getimagesizefromstring($png);
    if (!$info || ($info['mime'] ?? '') !== 'image/png' || $info[0] > 4000 || $info[1] > 2000) {
        throw new RuntimeException('The signature could not be read. Please sign again.');
    }
    $root = dirname(__DIR__, 3);
    $dir = $root . '/uploads/' . trim($relDir, '/');
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        throw new RuntimeException('The server cannot save files to uploads/' . trim($relDir, '/') . '. Ask your administrator to make that folder writable.');
    }
    $name = 'tenant_signature_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.png';
    if (file_put_contents($dir . '/' . $name, $png) === false) {
        throw new RuntimeException('The signature could not be saved.');
    }
    return 'uploads/' . trim($relDir, '/') . '/' . $name;
}

/** Signer name from the pop-up, else the tenant's name. */
function re_sig_name(string $posted, string $fallback): string
{
    $name = trim(preg_replace('/\s+/', ' ', $posted));
    return mb_substr($name !== '' ? $name : $fallback, 0, 150);
}

/**
 * Contents of the Tenant "Signature:" box for mPDF: the signature image when
 * the record was signed digitally, else just the label to sign on paper.
 */
function re_sig_cell_html(array $record): string
{
    $rel = (string)($record['tenant_signature_path'] ?? '');
    $abs = $rel !== '' ? dirname(__DIR__, 3) . '/' . ltrim($rel, '/') : '';
    if ($abs === '' || !is_file($abs)) {
        return '<span class="muted">Signature:</span>';
    }
    return '<span class="muted">Signature:</span><br><img src="' . htmlspecialchars($abs, ENT_QUOTES, 'UTF-8') . '" style="height:15mm;max-width:75mm" />'
        . '<div class="muted" style="font-size:6.5pt">Signed digitally' . (!empty($record['tenant_signed_at']) ? ' on ' . date('d M Y H:i', strtotime($record['tenant_signed_at'])) : '') . '</div>';
}

/** Date for the Tenant "Date:" box once signed digitally. */
function re_sig_date(array $record): string
{
    return !empty($record['tenant_signature_path']) && !empty($record['tenant_signed_at'])
        ? date('d M Y', strtotime($record['tenant_signed_at'])) : '';
}

/**
 * The signing pop-up (Bootstrap modal + a canvas pad, no library).
 * Open it with reSigOpen({title, name, onSave: (dataUrl, name, btn) => ...}).
 */
function re_sig_modal_html(): string
{
    return <<<'HTML'
<div class="modal fade" id="reSigModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-pen"></i> <span id="reSigTitle">Tenant signature</span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <label class="form-label" for="reSigName">Tenant name</label>
        <input type="text" id="reSigName" class="form-control mb-3" maxlength="150">
        <div class="d-flex justify-content-between align-items-end mb-1">
          <label class="form-label mb-0">Sign in the box with a finger, stylus or mouse</label>
          <button type="button" class="btn btn-sm btn-link text-decoration-none p-0" id="reSigClear"><i class="bi bi-eraser"></i> Clear</button>
        </div>
        <div style="border:2px dashed #94a3b8;border-radius:10px;background:#fff;position:relative">
          <canvas id="reSigPad" style="width:100%;height:220px;display:block;touch-action:none;cursor:crosshair"></canvas>
          <div id="reSigHint" style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:#cbd5e1;font-size:1.4rem;pointer-events:none">Sign here</div>
        </div>
        <div class="form-text">The signature is placed in the Tenant signature box of the form, and the signed form is saved as the signed copy.</div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-success" id="reSigSave"><i class="bi bi-check-circle"></i> Sign &amp; save</button>
      </div>
    </div>
  </div>
</div>
<script>
(function () {
    const modalEl = document.getElementById('reSigModal');
    const canvas = document.getElementById('reSigPad');
    const ctx = canvas.getContext('2d');
    const hint = document.getElementById('reSigHint');
    let drawing = false, strokes = 0, last = null, onSave = null;

    function resize() {
        const r = canvas.getBoundingClientRect();
        const dpr = window.devicePixelRatio || 1;
        canvas.width = Math.round(r.width * dpr);
        canvas.height = Math.round(r.height * dpr);
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.lineWidth = 2.4; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#0b1f4d';
    }
    function clear() { resize(); strokes = 0; hint.style.display = ''; }
    function pos(e) { const r = canvas.getBoundingClientRect(); return { x: e.clientX - r.left, y: e.clientY - r.top }; }

    canvas.addEventListener('pointerdown', e => {
        e.preventDefault(); canvas.setPointerCapture(e.pointerId);
        drawing = true; last = pos(e); strokes++; hint.style.display = 'none';
        ctx.beginPath(); ctx.arc(last.x, last.y, 1.1, 0, Math.PI * 2); ctx.fillStyle = '#0b1f4d'; ctx.fill();
    });
    canvas.addEventListener('pointermove', e => {
        if (!drawing) return;
        const p = pos(e);
        ctx.beginPath(); ctx.moveTo(last.x, last.y); ctx.lineTo(p.x, p.y); ctx.stroke();
        last = p;
    });
    ['pointerup', 'pointercancel', 'pointerleave'].forEach(t => canvas.addEventListener(t, () => { drawing = false; }));
    document.getElementById('reSigClear').addEventListener('click', clear);
    modalEl.addEventListener('shown.bs.modal', clear);

    document.getElementById('reSigSave').addEventListener('click', function () {
        if (strokes === 0) { alert('Please sign in the box first.'); return; }
        const name = document.getElementById('reSigName').value.trim();
        if (!name) { alert('Enter the tenant name.'); return; }
        if (onSave) onSave(canvas.toDataURL('image/png'), name, this);
    });

    window.reSigOpen = function (opts) {
        document.getElementById('reSigTitle').textContent = opts.title || 'Tenant signature';
        document.getElementById('reSigName').value = opts.name || '';
        onSave = opts.onSave;
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    };
})();
</script>
HTML;
}
