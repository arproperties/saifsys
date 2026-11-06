<?php
/**
 * Letterhead (header + footer images) for the inspection form PDFs.
 *
 * Per company in re_form_letterheads (migrations/re_form_letterheads.sql),
 * edited on form_letterhead.php. Falls back to the contract letterhead,
 * uploads/contracts/header.png and footer.png.
 */

const RFL_DEFAULT_HEADER = 'uploads/contracts/header.png';
const RFL_DEFAULT_FOOTER = 'uploads/contracts/footer.png';
const RFL_UPLOAD_DIR = 'uploads/form_letterheads';

function rfl_project_root(): string
{
    return dirname(__DIR__, 3);
}

function rfl_ready(PDO $conn): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $conn->query("SELECT 1 FROM re_form_letterheads LIMIT 1");
            $ready = true;
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/**
 * Current letterhead: ['header' => [...], 'footer' => [...]], each
 * ['path' => project-relative path or null, 'custom' => bool].
 */
function rfl_get(PDO $conn, int $companyId): array
{
    $row = [];
    if (rfl_ready($conn)) {
        $st = $conn->prepare("SELECT header_path, footer_path FROM re_form_letterheads WHERE company_id = ?");
        $st->execute([$companyId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    }
    $root = rfl_project_root();
    $pick = static function (?string $custom, string $default) use ($root): array {
        if ($custom && is_readable($root . '/' . $custom)) {
            return ['path' => $custom, 'custom' => true];
        }
        return ['path' => is_readable($root . '/' . $default) ? $default : null, 'custom' => false];
    };
    return [
        'header' => $pick($row['header_path'] ?? null, RFL_DEFAULT_HEADER),
        'footer' => $pick($row['footer_path'] ?? null, RFL_DEFAULT_FOOTER),
    ];
}

/**
 * Save an uploaded header or footer image ($part = 'header' | 'footer').
 * Returns the stored project-relative path; throws with a message for the user.
 */
function rfl_save_upload(PDO $conn, int $companyId, string $part, array $file, ?int $userId): string
{
    if (!in_array($part, ['header', 'footer'], true)) {
        throw new InvalidArgumentException('Unknown letterhead part.');
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Choose an image to upload.');
    }
    if ($file['size'] > 5 * 1024 * 1024) {
        throw new RuntimeException('The image is too large (max 5MB).');
    }
    $info = @getimagesize($file['tmp_name']);
    $types = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg'];
    if (!$info || !isset($types[$info[2]])) {
        throw new RuntimeException('Upload a PNG or JPG image.');
    }
    $dirRel = RFL_UPLOAD_DIR . '/' . $companyId;
    $dir = rfl_project_root() . '/' . $dirRel;
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        throw new RuntimeException('The server cannot write to ' . $dirRel . '. Make that folder writable and try again.');
    }
    $rel = $dirRel . '/' . $part . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(3)) . '.' . $types[$info[2]];
    if (!move_uploaded_file($file['tmp_name'], rfl_project_root() . '/' . $rel)) {
        throw new RuntimeException('The image could not be saved.');
    }
    $old = rfl_get($conn, $companyId)[$part];
    $col = $part . '_path';
    $conn->prepare("INSERT INTO re_form_letterheads (company_id, {$col}, updated_by) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE {$col} = VALUES({$col}), updated_by = VALUES(updated_by)")
        ->execute([$companyId, $rel, $userId]);
    if ($old['custom']) {
        rfl_delete_file($companyId, (string)$old['path']);
    }
    return $rel;
}

/** Go back to the default image for one part. */
function rfl_reset(PDO $conn, int $companyId, string $part, ?int $userId): void
{
    if (!in_array($part, ['header', 'footer'], true)) {
        throw new InvalidArgumentException('Unknown letterhead part.');
    }
    $old = rfl_get($conn, $companyId)[$part];
    $col = $part . '_path';
    $conn->prepare("UPDATE re_form_letterheads SET {$col} = NULL, updated_by = ? WHERE company_id = ?")->execute([$userId, $companyId]);
    if ($old['custom']) {
        rfl_delete_file($companyId, (string)$old['path']);
    }
}

/** Remove a replaced custom image (only inside this company's letterhead folder). */
function rfl_delete_file(int $companyId, string $rel): void
{
    if (strpos($rel, RFL_UPLOAD_DIR . '/' . $companyId . '/') === 0 && strpos($rel, '..') === false) {
        @unlink(rfl_project_root() . '/' . $rel);
    }
}

/**
 * Copy of a letterhead image without blank (white or transparent) rows at the
 * top and bottom, so the banner sits close to the page edge. Cached in $tempDir
 * by file and modification time. Returns the original path when GD cannot read it.
 */
function rfl_trimmed(string $absPath, string $tempDir): string
{
    $info = @getimagesize($absPath);
    if (!$info || !function_exists('imagecreatetruecolor') || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
        return $absPath;
    }
    $cache = $tempDir . '/rfl_' . md5($absPath . '|' . @filemtime($absPath)) . '.png';
    if (is_file($cache)) {
        return $cache;
    }
    $im = $info[2] === IMAGETYPE_PNG ? @imagecreatefrompng($absPath) : @imagecreatefromjpeg($absPath);
    if (!$im) {
        return $absPath;
    }
    [$w, $h] = [imagesx($im), imagesy($im)];
    $blank = static function (int $y) use ($im, $w): bool {
        for ($x = 0; $x < $w; $x += 2) {
            $c = imagecolorsforindex($im, imagecolorat($im, $x, $y));
            if ($c['alpha'] < 100 && ($c['red'] < 235 || $c['green'] < 235 || $c['blue'] < 235)) {
                return false;
            }
        }
        return true;
    };
    $top = 0;
    while ($top < $h - 1 && $blank($top)) {
        $top++;
    }
    $bottom = $h - 1;
    while ($bottom > $top && $blank($bottom)) {
        $bottom--;
    }
    if ($top === 0 && $bottom === $h - 1) {
        imagedestroy($im);
        return $absPath;
    }
    $nh = $bottom - $top + 1;
    $out = imagecreatetruecolor($w, $nh);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    imagecopy($out, $im, 0, 0, 0, $top, $w, $nh);
    $ok = @imagepng($out, $cache);
    imagedestroy($im);
    imagedestroy($out);
    return $ok ? $cache : $absPath;
}

/**
 * New A4 mPDF document with the company letterhead on every page.
 * Header and footer are printed full width (190mm); height follows the image,
 * capped at 50mm (header) and 30mm (footer) so the page keeps room for content.
 */
function rfl_mpdf(PDO $conn, int $companyId, string $tempDir, string $title): \Mpdf\Mpdf
{
    require_once rfl_project_root() . '/vendor/autoload.php';
    if (!class_exists(\Mpdf\Mpdf::class)) {
        throw new RuntimeException('mPDF is not installed (vendor/mpdf).');
    }
    $root = rfl_project_root();
    $lh = rfl_get($conn, $companyId);
    $header = $lh['header']['path'] ? rfl_trimmed($root . '/' . $lh['header']['path'], $tempDir) : null;
    $footer = $lh['footer']['path'] ? rfl_trimmed($root . '/' . $lh['footer']['path'], $tempDir) : null;

    $headerMm = 0;
    if ($header && ($hs = @getimagesize($header))) {
        $headerMm = min(50, (int)ceil(190 * $hs[1] / max(1, $hs[0])));
    }
    $footerMm = 0;
    if ($footer && ($fs = @getimagesize($footer))) {
        $footerMm = min(30, (int)ceil(190 * $fs[1] / max(1, $fs[0])));
    }

    $mpdf = new \Mpdf\Mpdf([
        'mode' => 'utf-8',
        'format' => 'A4',
        'tempDir' => $tempDir,
        'default_font' => 'dejavusans',
        'margin_left' => 10,
        'margin_right' => 10,
        'margin_top' => $headerMm ? $headerMm + 7 : 12,
        'margin_bottom' => $footerMm ? $footerMm + 10 : 14,
        'margin_header' => 3,
        'margin_footer' => 3,
        // Keep text at its size: a block kept together moves to the next page instead of shrinking
        'shrink_tables_to_fit' => 0,
    ]);
    $mpdf->SetTitle($title);
    $e = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    if ($headerMm) {
        $mpdf->SetHTMLHeader('<div style="text-align:center"><img src="' . $e($header) . '" style="width:190mm;height:' . $headerMm . 'mm" /></div>');
    }
    $foot = '<div style="text-align:right;font-size:7pt;color:#6b7280">Page {PAGENO} of {nbpg}</div>';
    if ($footerMm) {
        $foot = '<div style="text-align:center"><img src="' . $e($footer) . '" style="width:190mm;height:' . $footerMm . 'mm" /></div>' . $foot;
    }
    $mpdf->SetHTMLFooter($foot);
    return $mpdf;
}
