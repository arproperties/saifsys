<?php
/**
 * Building Inventory — shared helpers.
 *
 * saifsys only draws the screens. The inventory itself — the tables, the field rules,
 * the change log, the photos, and who keeps which building — lives in the Reem app.
 * Every read and every change on these pages is one call to Reem, through its door for
 * saifsys (server/fromSaifsys.js there): /api/from-saifsys/inventory.
 *
 * Nothing is stored in the saifsys database and no rule is decided here. What Reem
 * refuses, it refuses with a sentence, and that sentence is what the page shows.
 *
 * includes/config.php needs two lines:
 *   define('REEM_URL', 'https://...');        the Reem app's address, no path
 *   define('REEM_DOOR_KEY', '...');           the same value as SAIFSYS_DOOR_KEY in Reem's .env
 *
 * Reem is told who is at the screen by their HR employee code; the master links that
 * code to the person's Reem account in Reem. A login with no employee record, or a
 * code nobody linked, gets Reem's "no" on the page.
 *
 * Standalone on purpose: nothing here reads the inventory module, inv_* or ops_items.
 */

if (!function_exists('h')) {
    function h($s) {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}

const BINV_NAME_MAX = 80;   // input lengths only, so the box stops where Reem would cut
const BINV_NOTES_MAX = 500;
const BINV_PHOTO_MAX_BYTES = 8388608; // 8 MB, Reem's own limit
const BINV_PHOTO_LONG_SIDE = 1600;
const BINV_PHOTO_MAX_PIXELS = 50000000;
const BINV_REEM_PATH = '/api/from-saifsys/inventory';

/** Reem said no, or did not answer. The message is fit to show; $status is Reem's HTTP code. */
class BinvError extends RuntimeException
{
    public int $status;

    public function __construct(string $message, int $status = 400)
    {
        parent::__construct($message);
        $this->status = $status;
    }
}

// ---------------------------------------------------------------------------
// The line to Reem
// ---------------------------------------------------------------------------

function binv_reem_configured(): bool
{
    return defined('REEM_URL') && REEM_URL !== '' && defined('REEM_DOOR_KEY') && REEM_DOOR_KEY !== '';
}

/** The HR employee code of a saifsys login, or '' when it has no employee record. */
function binv_employee_code(PDO $conn, int $userId): string
{
    $stmt = $conn->prepare('SELECT e.employee_code FROM `user` u
        JOIN employees e ON e.id = u.employee_id OR e.user_id = u.id
        WHERE u.id = ? ORDER BY (e.id = u.employee_id) DESC LIMIT 1');
    $stmt->execute([$userId]);
    return trim((string)($stmt->fetchColumn() ?: ''));
}

/**
 * One call to Reem's inventory, as the person with this employee code.
 *
 * @param string     $path  '' for the building list, '/12', '/12/items/7/photo' ...
 * @param array|null $json  sent as the JSON body
 * @param array|null $photo ['path' => file on this server, 'mime' => its type] — sent as the upload "photo"
 * @param bool       $bytes true: answer [the raw body, its Content-Type] instead of decoded JSON
 * @param int        $timeout seconds
 * @param string     $base  the Reem route this module talks to; other screens-only modules pass their own
 * @return mixed
 */
function binv_reem(string $code, string $method, string $path = '', ?array $json = null, ?array $photo = null, bool $bytes = false, int $timeout = 20, string $base = BINV_REEM_PATH)
{
    if (!binv_reem_configured()) {
        throw new BinvError('Building Inventory is not connected to Reem yet: REEM_URL and REEM_DOOR_KEY are missing from config.php.', 503);
    }
    $headers = ['X-Saifsys-Key: ' . REEM_DOOR_KEY, 'X-Saifsys-Employee: ' . $code, 'Accept: application/json'];
    $ch = curl_init(rtrim(REEM_URL, '/') . $base . $path);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    if ($photo !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, ['photo' => new CURLFile($photo['path'], $photo['mime'], 'photo')]);
    } elseif ($json !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json, JSON_UNESCAPED_UNICODE));
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $response = curl_exec($ch);
    if ($response === false) {
        error_log('building inventory: Reem did not answer: ' . curl_error($ch));
        throw new BinvError('Reem did not answer. Try again in a moment.', 502);
    }
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $type = strtolower(trim(explode(';', (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE))[0]));
    $body = (string)substr($response, (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE));

    if ($status >= 200 && $status < 300 && $bytes) {
        return [$body, $type];
    }
    $data = json_decode($body, true);
    if ($status < 200 || $status >= 300) {
        $message = is_array($data) && is_string($data['error'] ?? null) && $data['error'] !== ''
            ? $data['error']
            : 'Reem answered with an error (HTTP ' . $status . ').';
        if ($status === 404 && $message === 'Not found') {
            $message = 'That could not be found, or it is not in a building you keep.';
        }
        throw new BinvError($message, $status);
    }
    if (!is_array($data)) {
        throw new BinvError('Reem sent an answer saifsys could not read.', 502);
    }
    return $data;
}

/**
 * Whether the launcher should offer this module to a login: true when Reem says they
 * keep at least one building. Remembered in the session for ten minutes, asked with a
 * short wait, and any trouble at all means no — the launcher must never hang or break
 * because Reem is slow.
 */
function binv_launcher_access(PDO $conn, int $userId): bool
{
    if ($userId <= 0 || !binv_reem_configured() || session_status() !== PHP_SESSION_ACTIVE) {
        return false;
    }
    $kept = $_SESSION['binv_launcher'] ?? null;
    if (is_array($kept) && (int)($kept['user'] ?? 0) === $userId && time() - (int)($kept['at'] ?? 0) < 600) {
        return !empty($kept['ok']);
    }
    $ok = false;
    try {
        $code = binv_employee_code($conn, $userId);
        $ok = $code !== '' && count(binv_reem($code, 'GET', '', null, null, false, 4)) > 0;
    } catch (Throwable $e) {
        $ok = false;
    }
    $_SESSION['binv_launcher'] = ['user' => $userId, 'at' => time(), 'ok' => $ok];
    return $ok;
}

// ---------------------------------------------------------------------------
// The pages
// ---------------------------------------------------------------------------

/**
 * Every page starts here, after require_login(): who is at the screen, as Reem will
 * know them. Whether they may see a building is Reem's answer to the page's own call.
 *
 * @return array{user_id:int,code:string}
 */
function binv_boot(PDO $conn): array
{
    $userId = (int)current_user_id();
    return ['user_id' => $userId, 'code' => binv_employee_code($conn, $userId)];
}

/** A page that cannot go on: Reem's reason, in a plain block, and stop. */
function binv_stop(BinvError $e): void
{
    http_response_code(in_array($e->status, [401, 403, 404], true) ? 403 : 503);
    $back = function_exists('get_application_web_root') ? get_application_web_root() . '/select-module.php' : 'javascript:history.back()';
    echo '<div style="font-family:system-ui;padding:32px;max-width:640px">
            <h3>Building Inventory</h3>
            <p>' . h($e->getMessage()) . '</p>
            <p><a href="' . h($back) . '">Back to modules</a></p>
          </div>';
    exit;
}

/** 2 shows as 2, 1.50 as 1.5. */
function binv_qty($qty): string
{
    return rtrim(rtrim(number_format((float)$qty, 2, '.', ''), '0'), '.') ?: '0';
}

/** "2 pcs", or "2" when the item is not counted in anything. */
function binv_qty_label($qty, ?string $countedIn): string
{
    return trim(binv_qty($qty) . ' ' . (string)$countedIn);
}

function binv_condition_color(string $condition): string
{
    return ['good' => 'success', 'damaged' => 'warning', 'missing' => 'danger'][$condition] ?? 'secondary';
}

/** A unix time as the office reads it. Dubai, whatever the server's clock is set to. */
function binv_when($ts): string
{
    $ts = (int)$ts;
    if ($ts <= 0) {
        return '';
    }
    return (new DateTime('@' . $ts))->setTimezone(new DateTimeZone('Asia/Dubai'))->format('d M Y, H:i');
}

/** Flash message — survives the redirect after every POST. */
function binv_flash(string $message, string $type = 'success'): void
{
    $_SESSION['binv_flash'] = ['message' => $message, 'type' => $type];
}

function binv_take_flash(): ?array
{
    if (empty($_SESSION['binv_flash'])) {
        return null;
    }
    $flash = $_SESSION['binv_flash'];
    unset($_SESSION['binv_flash']);
    return $flash;
}

// ---------------------------------------------------------------------------
// Photos. Reem stores them. saifsys only checks that the upload really is a picture
// and makes it small enough to send, then hands it over.
// ---------------------------------------------------------------------------

/** Why an upload did not arrive, in words. Null when it did. */
function binv_upload_error(array $file): ?string
{
    $code = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($code === UPLOAD_ERR_OK) {
        return is_uploaded_file((string)($file['tmp_name'] ?? '')) ? null : 'Upload failed.';
    }
    return [
        UPLOAD_ERR_INI_SIZE   => 'That photo is bigger than the server allows.',
        UPLOAD_ERR_FORM_SIZE  => 'That photo is too large.',
        UPLOAD_ERR_PARTIAL    => 'The photo did not upload completely. Please try again.',
        UPLOAD_ERR_NO_TMP_DIR => 'Server upload folder is missing.',
        UPLOAD_ERR_CANT_WRITE => 'Server could not save the photo.',
        UPLOAD_ERR_EXTENSION  => 'The upload was blocked by the server.',
    ][$code] ?? 'Upload failed.';
}

/**
 * Get an uploaded photo ready to send to Reem.
 *
 * The bytes decide what it is (finfo), not the name. With GD it becomes a JPEG, turned
 * the way the phone held it and no longer than 1600 px; without GD, or when GD cannot
 * read that format, the original goes as it is.
 *
 * @return array{path:string,mime:string,temp:bool} temp: the caller unlinks path afterwards
 */
function binv_photo_prepare(string $src): array
{
    $size = is_file($src) ? (int)filesize($src) : 0;
    if ($size <= 0) {
        throw new BinvError('The photo is empty.');
    }
    if ($size > BINV_PHOTO_MAX_BYTES) {
        throw new BinvError('The photo must be 8 MB or smaller.');
    }
    $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($src);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        throw new BinvError('Only JPG, PNG or WebP photos are allowed.');
    }
    $asIs = ['path' => $src, 'mime' => $mime, 'temp' => false];
    if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
        return $asIs;
    }
    $info = @getimagesize($src);
    if (!$info || $info[0] < 1 || $info[1] < 1) {
        throw new BinvError('That file is not a photo.');
    }
    if ($info[0] * $info[1] > BINV_PHOTO_MAX_PIXELS) {
        throw new BinvError('That photo is too large. Use one under 50 megapixels.');
    }
    $im = @imagecreatefromstring((string)file_get_contents($src));
    if (!$im) {
        return $asIs;
    }

    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data($src);
        $turned = null;
        switch ((int)($exif['Orientation'] ?? 1)) {
            case 2: imageflip($im, IMG_FLIP_HORIZONTAL); break;
            case 3: $turned = imagerotate($im, 180, 0); break;
            case 4: imageflip($im, IMG_FLIP_VERTICAL); break;
            case 5: $turned = imagerotate($im, -90, 0); if ($turned) { imageflip($turned, IMG_FLIP_HORIZONTAL); } break;
            case 6: $turned = imagerotate($im, -90, 0); break;
            case 7: $turned = imagerotate($im, 90, 0); if ($turned) { imageflip($turned, IMG_FLIP_HORIZONTAL); } break;
            case 8: $turned = imagerotate($im, 90, 0); break;
        }
        if ($turned) {
            imagedestroy($im);
            $im = $turned;
        }
    }

    $w = imagesx($im);
    $hgt = imagesy($im);
    $scale = min(1, BINV_PHOTO_LONG_SIDE / max($w, $hgt));
    $nw = max(1, (int)round($w * $scale));
    $nh = max(1, (int)round($hgt * $scale));
    // Onto white, so a see-through PNG does not come out black as a JPEG.
    $out = imagecreatetruecolor($nw, $nh);
    imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
    imagecopyresampled($out, $im, 0, 0, 0, 0, $nw, $nh, $w, $hgt);
    imagedestroy($im);
    $tmp = tempnam(sys_get_temp_dir(), 'binv');
    $ok = $tmp !== false && imagejpeg($out, $tmp, 82);
    imagedestroy($out);
    if (!$ok) {
        if ($tmp !== false) {
            @unlink($tmp);
        }
        return $asIs;
    }
    return ['path' => $tmp, 'mime' => 'image/jpeg', 'temp' => true];
}

/** Check an upload and give it to an item in Reem. */
function binv_send_photo(string $code, int $buildingId, int $itemId, string $src): void
{
    $photo = binv_photo_prepare($src);
    try {
        binv_reem($code, 'POST', '/' . $buildingId . '/items/' . $itemId . '/photo', null, $photo, false, 60);
    } finally {
        if ($photo['temp']) {
            @unlink($photo['path']);
        }
    }
}
