<?php
/**
 * Web Push (RFC 8030 / 8291 / 8292) with nothing but PHP's own OpenSSL.
 *
 * No Composer package: the three things a push needs are all in core PHP —
 *   VAPID   the site's key pair; requests are signed with it (ES256 JWT)
 *   ECE     the message is encrypted for the one browser (aes128gcm: ECDH on
 *           P-256, HKDF-SHA256, AES-128-GCM)
 *   send    one HTTPS POST to the browser's push service (curl)
 *
 * The key pair is made on first use and kept in `settings` as two short
 * base64url strings (the PEM would not fit varchar(255)); the PEM is rebuilt
 * from them when signing. Changing the keys would silence every phone until it
 * subscribes again, so they are never rotated here.
 */

/** base64url without padding. */
function web_push_b64u_encode(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function web_push_b64u_decode(string $s): string
{
    $s = strtr(trim($s), '-_', '+/');
    $pad = strlen($s) % 4;
    if ($pad) {
        $s .= str_repeat('=', 4 - $pad);
    }
    return (string)base64_decode($s, true);
}

/** A P-256 public key PEM from its 65-byte uncompressed point. */
function web_push_public_pem(string $point): string
{
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $point;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

/** A P-256 private key PEM (SEC1) from its 32-byte scalar and 65-byte public point. */
function web_push_private_pem(string $d, string $point): string
{
    $der = hex2bin('30770201010420') . $d . hex2bin('a00a06082a8648ce3d030107a144034200') . $point;
    return "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END EC PRIVATE KEY-----\n";
}

/** A fresh P-256 key pair: [private scalar (32), public point (65)]. */
function web_push_new_keypair(): array
{
    $opts = ['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC];
    $key = openssl_pkey_new($opts);
    if (!$key) {
        // Windows PHP (XAMPP) ships without a findable openssl.cnf; try the usual places.
        $iniDir = dirname((string)(php_ini_loaded_file() ?: PHP_BINARY));
        foreach (array_filter([getenv('OPENSSL_CONF') ?: null, $iniDir . '/extras/ssl/openssl.cnf', dirname($iniDir) . '/apache/conf/openssl.cnf']) as $cnf) {
            if (is_file($cnf) && ($key = openssl_pkey_new($opts + ['config' => $cnf]))) {
                break;
            }
        }
    }
    while (openssl_error_string() !== false) {
        // clear OpenSSL's error queue so later calls report their own errors
    }
    if (!$key) {
        throw new RuntimeException('OpenSSL could not create a P-256 key.');
    }
    $ec = openssl_pkey_get_details($key)['ec'];
    $pad = static fn(string $b): string => str_pad($b, 32, "\0", STR_PAD_LEFT);
    return [$pad($ec['d']), "\x04" . $pad($ec['x']) . $pad($ec['y'])];
}

/**
 * The site's VAPID keys, made and saved on first use.
 *
 * @return array{public:string, private_pem:string} public is base64url (for the browser)
 */
function web_push_vapid(PDO $conn): array
{
    static $cache = null;
    if ($cache) {
        return $cache;
    }
    $read = static function () use ($conn): array {
        $st = $conn->query("SELECT `key`, `value` FROM settings WHERE `key` IN ('web_push_vapid_public', 'web_push_vapid_private')");
        return $st ? $st->fetchAll(PDO::FETCH_KEY_PAIR) : [];
    };
    $rows = $read();
    if (empty($rows['web_push_vapid_public']) || empty($rows['web_push_vapid_private'])) {
        [$d, $point] = web_push_new_keypair();
        // INSERT IGNORE: if two requests race, the first pair wins and both read it back.
        $ins = $conn->prepare("INSERT IGNORE INTO settings (`key`, `value`) VALUES (?, ?)");
        $ins->execute(['web_push_vapid_private', web_push_b64u_encode($d)]);
        $ins->execute(['web_push_vapid_public', web_push_b64u_encode($point)]);
        $rows = $read();
    }
    $point = web_push_b64u_decode((string)$rows['web_push_vapid_public']);
    $d = web_push_b64u_decode((string)$rows['web_push_vapid_private']);
    return $cache = [
        'public' => (string)$rows['web_push_vapid_public'],
        'private_pem' => web_push_private_pem($d, $point),
    ];
}

/** An ES256 JWT for one push service origin (RFC 8292). */
function web_push_jwt(string $endpoint, string $privatePem, string $subject): string
{
    $parts = parse_url($endpoint);
    $aud = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    $signing = web_push_b64u_encode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']))
        . '.' . web_push_b64u_encode(json_encode(['aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => $subject], JSON_UNESCAPED_SLASHES));
    if (!openssl_sign($signing, $der, $privatePem, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Could not sign the push request.');
    }
    return $signing . '.' . web_push_b64u_encode(web_push_der_to_raw($der));
}

/** OpenSSL gives a DER ECDSA signature; JWT wants r||s, 32 bytes each. */
function web_push_der_to_raw(string $der): string
{
    $pos = 2; // SEQUENCE tag + length (always short form for P-256)
    $out = '';
    for ($i = 0; $i < 2; $i++) {
        $len = ord($der[$pos + 1]);
        $int = substr($der, $pos + 2, $len);
        $pos += 2 + $len;
        $int = ltrim($int, "\0");
        $out .= str_pad($int, 32, "\0", STR_PAD_LEFT);
    }
    return $out;
}

/**
 * Encrypt a payload for one browser (RFC 8291, aes128gcm, one record).
 *
 * @param string $p256dh base64url browser public key (65 bytes)
 * @param string $auth   base64url browser auth secret (16 bytes)
 */
function web_push_encrypt(string $payload, string $p256dh, string $auth): string
{
    $uaPublic = web_push_b64u_decode($p256dh);
    $authSecret = web_push_b64u_decode($auth);
    if (strlen($uaPublic) !== 65 || $uaPublic[0] !== "\x04" || strlen($authSecret) !== 16) {
        throw new InvalidArgumentException('Bad subscription keys.');
    }
    [$asD, $asPublic] = web_push_new_keypair();
    // P-256 ECDH always gives 32 bytes; PHP 8.5 deprecates passing a length.
    $shared = openssl_pkey_derive(web_push_public_pem($uaPublic), web_push_private_pem($asD, $asPublic));
    if ($shared === false || strlen($shared) !== 32) {
        throw new RuntimeException('Key agreement failed.');
    }
    $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0" . $uaPublic . $asPublic, $authSecret);
    $salt = random_bytes(16);
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
    $cipher = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
    if ($cipher === false) {
        throw new RuntimeException('Encryption failed.');
    }
    return $salt . pack('N', 4096) . chr(65) . $asPublic . $cipher . $tag;
}

/**
 * Send to many browsers at once (curl_multi). Each target is
 * ['endpoint','p256dh','auth', ...anything else is passed back].
 *
 * @return list<array{target:array, status:int, gone:bool, error:string}>
 *         gone = the browser has dropped this subscription (404/410): delete it
 */
function web_push_send_many(PDO $conn, array $targets, array $message, int $ttl = 43200): array
{
    if (!$targets) {
        return [];
    }
    $vapid = web_push_vapid($conn);
    $subject = web_push_subject();
    $payload = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $results = [];
    $prepared = [];
    foreach ($targets as $i => $t) {
        try {
            $prepared[$i] = [
                'body' => web_push_encrypt($payload, (string)$t['p256dh'], (string)$t['auth']),
                'headers' => [
                    'Authorization: vapid t=' . web_push_jwt((string)$t['endpoint'], $vapid['private_pem'], $subject) . ', k=' . $vapid['public'],
                    'Content-Encoding: aes128gcm',
                    'Content-Type: application/octet-stream',
                    'TTL: ' . $ttl,
                    'Urgency: high',
                ],
            ];
        } catch (Throwable $e) {
            $results[$i] = ['target' => $t, 'status' => 0, 'gone' => false, 'error' => $e->getMessage()];
        }
    }
    if (!function_exists('curl_multi_init')) {
        // No curl in this PHP: plain HTTP streams, one at a time.
        foreach ($prepared as $i => $p) {
            $ctx = stream_context_create(['http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $p['headers']) . "\r\nContent-Length: " . strlen($p['body']),
                'content' => $p['body'],
                'timeout' => 15,
                'ignore_errors' => true,
            ]]);
            $resp = @file_get_contents((string)$targets[$i]['endpoint'], false, $ctx);
            $code = 0;
            foreach ($http_response_header ?? [] as $line) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                    $code = (int)$m[1];
                }
            }
            $results[$i] = [
                'target' => $targets[$i],
                'status' => $code,
                'gone' => in_array($code, [404, 410], true),
                'error' => $code >= 200 && $code < 300 ? '' : ($resp === false ? 'connection failed' : substr((string)$resp, 0, 200)),
            ];
        }
        ksort($results);
        return array_values($results);
    }
    $multi = curl_multi_init();
    $handles = [];
    foreach ($prepared as $i => $p) {
        $ch = curl_init((string)$targets[$i]['endpoint']);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $p['body'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER => $p['headers'],
        ]);
        curl_multi_add_handle($multi, $ch);
        $handles[$i] = $ch;
    }
    do {
        $status = curl_multi_exec($multi, $running);
        if ($running) {
            curl_multi_select($multi, 1.0);
        }
    } while ($running && $status === CURLM_OK);
    foreach ($handles as $i => $ch) {
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $results[$i] = [
            'target' => $targets[$i],
            'status' => $code,
            'gone' => in_array($code, [404, 410], true),
            'error' => $code >= 200 && $code < 300 ? '' : (curl_error($ch) ?: substr((string)curl_multi_getcontent($ch), 0, 200)),
        ];
        curl_multi_remove_handle($multi, $ch);
        curl_close($ch);
    }
    curl_multi_close($multi);
    ksort($results);
    return array_values($results);
}

/** Who push services may contact about these messages (RFC 8292 "sub"). */
function web_push_subject(): string
{
    if (defined('APP_BASE_URL') && preg_match('#^https://#i', (string)APP_BASE_URL)) {
        return rtrim((string)APP_BASE_URL, '/');
    }
    $host = preg_replace('/[^a-z0-9.\-]/i', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
    return 'mailto:hr@' . ($host !== '' ? $host : 'localhost');
}
