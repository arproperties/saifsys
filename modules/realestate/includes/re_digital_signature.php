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
        <div class="btn-group btn-group-sm mb-2" role="group" aria-label="How to add the signature">
          <input type="radio" class="btn-check" name="reSigMode" id="reSigModeDraw" value="draw" checked>
          <label class="btn btn-outline-secondary" for="reSigModeDraw"><i class="bi bi-pen"></i> Draw</label>
          <input type="radio" class="btn-check" name="reSigMode" id="reSigModeUpload" value="upload">
          <label class="btn btn-outline-secondary" for="reSigModeUpload"><i class="bi bi-image"></i> Upload image</label>
        </div>
        <div id="reSigDrawPane">
          <div class="d-flex justify-content-between align-items-end mb-1">
            <label class="form-label mb-0">Sign in the box with a finger, stylus or mouse</label>
            <button type="button" class="btn btn-sm btn-link text-decoration-none p-0" id="reSigClear"><i class="bi bi-eraser"></i> Clear</button>
          </div>
          <div style="border:2px dashed #94a3b8;border-radius:10px;background:#fff;position:relative">
            <canvas id="reSigPad" style="width:100%;height:220px;display:block;touch-action:none;cursor:crosshair"></canvas>
            <div id="reSigHint" style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:#cbd5e1;font-size:1.4rem;pointer-events:none">Sign here</div>
          </div>
        </div>
        <div id="reSigUploadPane" hidden>
          <label class="form-label" for="reSigFile">Photo or scan of the tenant's signature (JPG, PNG or WEBP)</label>
          <input type="file" id="reSigFile" class="form-control mb-2" accept="image/jpeg,image/png,image/webp">
          <div style="border:2px dashed #94a3b8;border-radius:10px;background:#fff;height:220px;display:flex;align-items:center;justify-content:center;padding:12px">
            <img id="reSigPreview" alt="Signature preview" style="max-width:100%;max-height:100%;display:none">
            <span id="reSigPreviewHint" style="color:#cbd5e1;font-size:1.1rem">The signature appears here</span>
          </div>
          <div class="form-text">Use a signature on plain white paper. The background is removed and the signature is cropped to fit.</div>
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
    let drawing = false, strokes = 0, last = null, onSave = null, uploaded = null;
    const drawPane = document.getElementById('reSigDrawPane');
    const uploadPane = document.getElementById('reSigUploadPane');
    const fileInp = document.getElementById('reSigFile');
    const preview = document.getElementById('reSigPreview');
    const previewHint = document.getElementById('reSigPreviewHint');
    function mode() { return document.getElementById('reSigModeUpload').checked ? 'upload' : 'draw'; }
    function setMode(m) {
        document.getElementById(m === 'upload' ? 'reSigModeUpload' : 'reSigModeDraw').checked = true;
        drawPane.hidden = m === 'upload';
        uploadPane.hidden = m !== 'upload';
        if (m === 'draw') resize();
    }
    function resetUpload() {
        uploaded = null; fileInp.value = '';
        preview.removeAttribute('src'); preview.style.display = 'none'; previewHint.style.display = '';
    }
    document.querySelectorAll('input[name="reSigMode"]').forEach(r => r.addEventListener('change', () => setMode(mode())));

    // Uploaded signature image -> transparent PNG cropped to the ink, like a drawn one
    fileInp.addEventListener('change', function () {
        uploaded = null;
        const f = this.files && this.files[0];
        if (!f) { resetUpload(); return; }
        if (!/^image\/(jpeg|png|webp)$/.test(f.type)) { alert('Choose a JPG, PNG or WEBP image of the signature.'); resetUpload(); return; }
        if (f.size > 10 * 1024 * 1024) { alert('The image is too large (max 10MB).'); resetUpload(); return; }
        const url = URL.createObjectURL(f);
        const img = new Image();
        img.onload = function () {
            URL.revokeObjectURL(url);
            const scale = Math.min(1, 1600 / img.naturalWidth, 1600 / img.naturalHeight);
            const w = Math.max(1, Math.round(img.naturalWidth * scale)), h = Math.max(1, Math.round(img.naturalHeight * scale));
            const c = document.createElement('canvas'); c.width = w; c.height = h;
            const cx = c.getContext('2d');
            cx.drawImage(img, 0, 0, w, h);
            const d = cx.getImageData(0, 0, w, h), px = d.data;
            let minX = w, minY = h, maxX = -1, maxY = -1;
            for (let y = 0; y < h; y++) {
                for (let x = 0; x < w; x++) {
                    const i = (y * w + x) * 4;
                    const lum = 0.299 * px[i] + 0.587 * px[i + 1] + 0.114 * px[i + 2];
                    // paper (light) -> transparent, ink fades in smoothly
                    const a = px[i + 3] === 0 ? 0 : Math.max(0, Math.min(255, Math.round((205 - lum) * 255 / 105)));
                    px[i + 3] = a;
                    if (a > 40) { if (x < minX) minX = x; if (x > maxX) maxX = x; if (y < minY) minY = y; if (y > maxY) maxY = y; }
                }
            }
            if (maxX < 0) { alert('No signature found in this image. Use a dark signature on white paper.'); resetUpload(); return; }
            cx.putImageData(d, 0, 0);
            const pad = 8;
            minX = Math.max(0, minX - pad); minY = Math.max(0, minY - pad);
            maxX = Math.min(w - 1, maxX + pad); maxY = Math.min(h - 1, maxY + pad);
            const cw = maxX - minX + 1, ch = maxY - minY + 1;
            const k = Math.min(1, 1200 / cw, 500 / ch);
            const out = document.createElement('canvas'); out.width = Math.max(1, Math.round(cw * k)); out.height = Math.max(1, Math.round(ch * k));
            out.getContext('2d').drawImage(c, minX, minY, cw, ch, 0, 0, out.width, out.height);
            uploaded = out.toDataURL('image/png');
            preview.src = uploaded; preview.style.display = ''; previewHint.style.display = 'none';
        };
        img.onerror = function () { URL.revokeObjectURL(url); alert('This image could not be read.'); resetUpload(); };
        img.src = url;
    });

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
    modalEl.addEventListener('shown.bs.modal', () => { clear(); if (mode() === 'upload' && !uploaded) fileInp.click(); });

    document.getElementById('reSigSave').addEventListener('click', function () {
        const m = mode();
        if (m === 'draw' && strokes === 0) { alert('Please sign in the box first.'); return; }
        if (m === 'upload' && !uploaded) { alert('Choose the signature image first.'); return; }
        const name = document.getElementById('reSigName').value.trim();
        if (!name) { alert('Enter the tenant name.'); return; }
        if (onSave) onSave(m === 'upload' ? uploaded : canvas.toDataURL('image/png'), name, this, m);
    });

    // opts.mode: 'draw' (default) or 'upload' (signature image)
    window.reSigOpen = function (opts) {
        document.getElementById('reSigTitle').textContent = opts.title || 'Tenant signature';
        document.getElementById('reSigName').value = opts.name || '';
        onSave = opts.onSave;
        resetUpload();
        setMode(opts.mode === 'upload' ? 'upload' : 'draw');
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    };
})();
</script>
HTML;
}
