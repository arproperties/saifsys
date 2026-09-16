<?php
/**
 * Airbnb notification email parser.
 *
 * Turns one raw RFC822 message from automated@airbnb.com into a flat array.
 * Pure: no database, no network. Formats were taken from real mails in
 * ars.holidayhomes@gmail.com (May 2025 – Sep 2026).
 *
 * Types:
 *   confirmed       "Reservation confirmed - <Full Name> arrives <Mon d>"   code, listing, dates, guests, payout
 *   guest_cancel    "Canceled: Reservation HMXXXXXXXX for <dates>"          code, listing id
 *   host_cancel     "Reservation canceled: <dates>"                        dates only — no code, no listing
 *   updated         "Reservation updated" / "Your reservation change was accepted"   code only, no new dates
 *   change_request  "<Name> wants to change their reservation"             listing title, original + requested dates
 *   ignored         everything else (inquiries, reviews, payouts, reminders…)
 */

declare(strict_types=1);

/** Split headers/body and return [headers(lowercase => raw string), body]. */
function airbnb_mail_split(string $raw): array {
    $raw = str_replace("\r\n", "\n", $raw);
    $pos = strpos($raw, "\n\n");
    $head = $pos === false ? $raw : substr($raw, 0, $pos);
    $body = $pos === false ? '' : substr($raw, $pos + 2);
    $head = preg_replace("/\n[ \t]+/", ' ', $head); // unfold
    $headers = [];
    foreach (explode("\n", (string)$head) as $line) {
        $c = strpos($line, ':');
        if ($c === false) continue;
        $name = strtolower(trim(substr($line, 0, $c)));
        if (!isset($headers[$name])) {
            $headers[$name] = trim(substr($line, $c + 1));
        }
    }
    return [$headers, $body];
}

function airbnb_mail_decode_header(string $value): string {
    if (strpos($value, '=?') === false) return $value;
    $out = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
    return $out === false ? $value : $out;
}

function airbnb_mail_header_param(string $header, string $param): string {
    if (preg_match('/' . preg_quote($param, '/') . '\s*=\s*"?([^";]+)"?/i', $header, $m)) {
        return trim($m[1]);
    }
    return '';
}

function airbnb_mail_decode_part(string $body, string $encoding, string $charset): string {
    $encoding = strtolower(trim($encoding));
    if ($encoding === 'quoted-printable') {
        $body = quoted_printable_decode($body);
    } elseif ($encoding === 'base64') {
        $body = (string)base64_decode(preg_replace('/\s+/', '', $body));
    }
    $charset = strtolower($charset ?: 'utf-8');
    if ($charset !== 'utf-8' && $charset !== 'us-ascii') {
        $conv = @iconv($charset, 'UTF-8//IGNORE', $body);
        if ($conv !== false) $body = $conv;
    }
    return $body;
}

/**
 * Walk the MIME tree and collect decoded leaf parts.
 * @return list<array{type:string, text:string}>
 */
function airbnb_mail_leaf_parts(array $headers, string $body, int $depth = 0): array {
    $ctype = $headers['content-type'] ?? 'text/plain';
    $type = strtolower(trim(explode(';', $ctype)[0]));
    if (strpos($type, 'multipart/') === 0 && $depth < 5) {
        $boundary = airbnb_mail_header_param($ctype, 'boundary');
        if ($boundary === '') return [];
        $parts = [];
        $chunks = explode('--' . $boundary, $body);
        array_shift($chunks); // preamble
        foreach ($chunks as $chunk) {
            if (strpos($chunk, '--') === 0) break; // closing delimiter
            $chunk = ltrim($chunk, "\n");
            [$h, $b] = airbnb_mail_split($chunk);
            foreach (airbnb_mail_leaf_parts($h, $b, $depth + 1) as $p) $parts[] = $p;
        }
        return $parts;
    }
    return [[
        'type' => $type,
        'text' => airbnb_mail_decode_part($body, $headers['content-transfer-encoding'] ?? '', airbnb_mail_header_param($ctype, 'charset')),
    ]];
}

/** Readable text: prefer text/plain, fall back to stripped HTML. Normalises spaces. */
function airbnb_mail_text(array $parts): string {
    $text = null;
    foreach ($parts as $p) {
        if ($p['type'] === 'text/plain') { $text = $p['text']; break; }
    }
    if ($text === null) {
        foreach ($parts as $p) {
            if ($p['type'] === 'text/html') {
                $h = preg_replace('#<(style|script)\b.*?</\1>#is', '', $p['text']);
                $h = preg_replace('#<(br|/p|/div|/tr|/h\d|/td)\b[^>]*>#i', "\n", (string)$h);
                $text = html_entity_decode(strip_tags((string)$h), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                break;
            }
        }
    }
    $text = (string)$text;
    // NBSP, narrow NBSP, thin space, en space, invisible joiners/soft hyphen
    $text = str_replace(["\u{00A0}", "\u{202F}", "\u{2009}", "\u{2002}", "\u{2003}"], ' ', $text);
    $text = str_replace(["\u{034F}", "\u{00AD}", "\u{200B}", "\u{200C}", "\u{200D}", "\u{FEFF}"], '', $text);
    return str_replace("\r\n", "\n", $text);
}

/** Non-empty trimmed lines, bare URLs dropped. */
function airbnb_mail_lines(string $text): array {
    $out = [];
    foreach (explode("\n", $text) as $l) {
        $l = trim($l);
        if ($l === '' || preg_match('#^\[?https?://#', $l)) continue;
        $out[] = $l;
    }
    return $out;
}

function airbnb_month_num(string $m): int {
    $m = strtolower(substr(trim($m), 0, 3));
    $map = ['jan'=>1,'feb'=>2,'mar'=>3,'apr'=>4,'may'=>5,'jun'=>6,'jul'=>7,'aug'=>8,'sep'=>9,'oct'=>10,'nov'=>11,'dec'=>12];
    return $map[$m] ?? 0;
}

/**
 * Airbnb often omits the year ("Sun, Sep 13"). Pick the year that puts the date
 * closest after the email was sent — a stay can't start long before its own email.
 */
function airbnb_infer_date(int $month, int $day, ?int $year, DateTimeImmutable $sent): ?string {
    if ($month < 1 || $month > 12 || $day < 1 || $day > 31) {
        return null;
    }
    if ($year !== null) {
        return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : null;
    }
    $floor = $sent->modify('-60 days')->format('Y-m-d');
    foreach ([(int)$sent->format('Y') - 1, (int)$sent->format('Y'), (int)$sent->format('Y') + 1, (int)$sent->format('Y') + 2] as $y) {
        if (!checkdate($month, $day, $y)) continue;
        $d = sprintf('%04d-%02d-%02d', $y, $month, $day);
        if ($d >= $floor) return $d;
    }
    return null;
}

/**
 * Parse a range such as "Aug 30 – Sep 29, 2026", "Nov 7 – 12, 2026",
 * "Dec 22, 2025 – Jan 3, 2026", "September 18 – 26", "Aug 16, 2026 - Aug 24, 2026".
 * @return array{0:?string,1:?string}
 */
function airbnb_parse_date_range(string $s, DateTimeImmutable $sent): array {
    $s = str_replace(['–', '—'], '-', $s);
    $re = '/([A-Za-z]{3,9})\.?\s+(\d{1,2})(?:,\s*(\d{4}))?\s*-\s*(?:([A-Za-z]{3,9})\.?\s+)?(\d{1,2})(?:,\s*(\d{4}))?/';
    if (!preg_match($re, $s, $m)) return [null, null];
    $m1 = airbnb_month_num($m[1]);
    $m2 = ($m[4] ?? '') !== '' ? airbnb_month_num($m[4]) : $m1;
    if ($m1 === 0 || $m2 === 0) return [null, null];
    $y2 = ($m[6] ?? '') !== '' ? (int)$m[6] : null;
    $y1 = ($m[3] ?? '') !== '' ? (int)$m[3] : ($y2 !== null ? ($m2 < $m1 ? $y2 - 1 : $y2) : null);
    $start = airbnb_infer_date($m1, (int)$m[2], $y1, $sent);
    if ($start === null) return [null, null];
    if ($y2 === null) {
        $y2 = (int)substr($start, 0, 4);
        if ($m2 < $m1) $y2++;
    }
    $end = airbnb_infer_date($m2, (int)$m[5], $y2, $sent);
    if ($end !== null && $end <= $start) $end = null;
    return [$start, $end];
}

/** "ﺩ.ﺇ 1,070.00" → 1070.00 */
function airbnb_parse_amount(string $s): ?float {
    if (!preg_match('/(\d{1,3}(?:,\d{3})*(?:\.\d{1,2})?|\d+(?:\.\d{1,2})?)/', $s, $m)) return null;
    return round((float)str_replace(',', '', $m[1]), 2);
}

/** "Ronald John Montero" → ["Ronald John", "Montero"]; single word → [word, ""] */
function airbnb_split_name(string $full): array {
    $full = trim(preg_replace('/\s+/u', ' ', $full));
    if ($full === '') return ['', ''];
    $pos = mb_strrpos($full, ' ');
    if ($pos === false) return [$full, ''];
    return [mb_substr($full, 0, $pos), mb_substr($full, $pos + 1)];
}

/**
 * Classify by subject alone, so the sync only downloads bodies it needs.
 * Must stay in step with the branches in airbnb_parse_email().
 */
function airbnb_subject_type(string $subject): string {
    $subject = trim(preg_replace('/\s+/u', ' ', str_replace(["\u{2009}", "\u{202F}", "\u{00A0}"], ' ', $subject)));
    if (preg_match('/^Reservation confirmed - .+ arrives\b/u', $subject)) return 'confirmed';
    if (preg_match('/^Canceled: Reservation HM[A-Z0-9]{8} for /u', $subject)) return 'guest_cancel';
    if (preg_match('/^Reservation canceled: /u', $subject)) return 'host_cancel';
    if ($subject === 'Reservation updated' || $subject === 'Your reservation change was accepted') return 'updated';
    if (preg_match('/ wants to change their reservation$/u', $subject)) return 'change_request';
    return 'ignored';
}

/** Subject from a raw header block (as returned by an IMAP HEADER.FIELDS fetch). */
function airbnb_header_subject(string $rawHeaders): string {
    [$headers] = airbnb_mail_split($rawHeaders . "\n\n");
    return airbnb_mail_decode_header($headers['subject'] ?? '');
}

/**
 * @return array<string,mixed> keys: type, subject, message_id, sent_at, from,
 *   confirmation_code, listing_id, listing_title, guest_name, guest_first_name, guest_last_name,
 *   check_in, check_out, num_guests, guests_text, payout_amount, guest_paid_amount,
 *   requested_check_in, requested_check_out, errors(list)
 */
function airbnb_parse_email(string $raw): array {
    [$headers, $body] = airbnb_mail_split($raw);
    $parts = airbnb_mail_leaf_parts($headers, $body);
    $text = airbnb_mail_text($parts);
    $lines = airbnb_mail_lines($text);
    $allBodies = implode("\n", array_column($parts, 'text'));

    $subject = trim(preg_replace('/\s+/u', ' ', str_replace(["\u{2009}", "\u{202F}", "\u{00A0}"], ' ', airbnb_mail_decode_header($headers['subject'] ?? ''))));
    $sentTs = isset($headers['date']) ? strtotime(preg_replace('/\s*\([^)]*\)\s*$/', '', $headers['date'])) : false;
    $sent = (new DateTimeImmutable('@' . ($sentTs ?: time())))->setTimezone(new DateTimeZone('Asia/Dubai'));

    $r = [
        'type' => 'ignored',
        'subject' => $subject,
        'message_id' => trim($headers['message-id'] ?? '', " <>"),
        'from' => airbnb_mail_decode_header($headers['from'] ?? ''),
        'sent_at' => $sent->format('Y-m-d H:i:s'),
        'confirmation_code' => null,
        'listing_id' => null,
        'listing_title' => null,
        'guest_name' => null,
        'guest_first_name' => null,
        'guest_last_name' => null,
        'check_in' => null,
        'check_out' => null,
        'num_guests' => null,
        'guests_text' => null,
        'payout_amount' => null,
        'guest_paid_amount' => null,
        'requested_check_in' => null,
        'requested_check_out' => null,
        'errors' => [],
    ];

    $firstCode = static function () use ($allBodies): ?string {
        return preg_match('/\b(HM[A-Z0-9]{8})\b/', $allBodies, $m) ? $m[1] : null;
    };

    // ── Reservation confirmed ─────────────────────────────────────────
    if (preg_match('/^Reservation confirmed - (.+?) arrives\b/u', $subject, $m)) {
        $r['type'] = 'confirmed';
        $r['guest_name'] = trim($m[1]);
        [$r['guest_first_name'], $r['guest_last_name']] = airbnb_split_name($r['guest_name']);

        foreach ($lines as $i => $l) {
            if ($l === 'CONFIRMATION CODE' && isset($lines[$i + 1]) && preg_match('/^HM[A-Z0-9]{8}$/', $lines[$i + 1])) {
                $r['confirmation_code'] = $lines[$i + 1];
            }
            if (preg_match('/^Check-in\s+Checkout$/i', $l) && isset($lines[$i + 1])
                && preg_match('/^[A-Za-z]{3},\s*([A-Za-z]{3,9})\s+(\d{1,2})(?:,\s*(\d{4}))?\s+[A-Za-z]{3},\s*([A-Za-z]{3,9})\s+(\d{1,2})(?:,\s*(\d{4}))?$/', $lines[$i + 1], $dm)) {
                $in = airbnb_infer_date(airbnb_month_num($dm[1]), (int)$dm[2], ($dm[3] ?? '') !== '' ? (int)$dm[3] : null, $sent);
                $outYear = ($dm[6] ?? '') !== '' ? (int)$dm[6] : ($in ? (int)substr($in, 0, 4) + (airbnb_month_num($dm[4]) < airbnb_month_num($dm[1]) ? 1 : 0) : null);
                $out = $outYear ? airbnb_infer_date(airbnb_month_num($dm[4]), (int)$dm[5], $outYear, $sent) : null;
                $r['check_in'] = $in;
                $r['check_out'] = ($in && $out && $out > $in) ? $out : null;
            }
            if ($l === 'GUESTS' && isset($lines[$i + 1])) {
                $r['guests_text'] = $lines[$i + 1];
                $n = 0;
                if (preg_match_all('/(\d+)\s+(adults?|child(?:ren)?)/i', $lines[$i + 1], $gm)) {
                    foreach ($gm[1] as $x) $n += (int)$x;
                }
                $r['num_guests'] = max(1, $n);
            }
            if (preg_match('/^YOU EARN\s+(.+)$/i', $l, $am)) {
                $r['payout_amount'] = airbnb_parse_amount($am[1]);
            }
            if (preg_match('/^TOTAL \([A-Z]{3}\)\s+(.+)$/', $l, $am)) {
                $r['guest_paid_amount'] = airbnb_parse_amount($am[1]);
            }
            if (preg_match('/^(Entire home\/apt|Private room|Shared room|Hotel room)$/i', $l) && $i > 0 && $r['listing_title'] === null) {
                $r['listing_title'] = mb_convert_case($lines[$i - 1], MB_CASE_TITLE, 'UTF-8');
            }
        }
        if (preg_match('#/rooms/(\d{6,})#', $allBodies, $lm)) {
            $r['listing_id'] = $lm[1];
        }
        $r['confirmation_code'] = $r['confirmation_code'] ?? $firstCode();

        foreach (['confirmation_code', 'listing_id', 'check_in', 'check_out', 'payout_amount'] as $k) {
            if ($r[$k] === null) $r['errors'][] = "Could not read {$k}";
        }
        if ($r['payout_amount'] !== null && $r['payout_amount'] <= 0) $r['errors'][] = 'Payout is zero';
        return $r;
    }

    // ── Guest cancelled (has code + listing id) ───────────────────────
    if (preg_match('/^Canceled: Reservation (HM[A-Z0-9]{8}) for (.+)$/u', $subject, $m)) {
        $r['type'] = 'guest_cancel';
        $r['confirmation_code'] = $m[1];
        [$r['check_in'], $r['check_out']] = airbnb_parse_date_range($m[2], $sent);
        if (preg_match('/Listing #(\d{6,})/', $text, $lm)) $r['listing_id'] = $lm[1];
        if (preg_match('/your guest (.+?) had to cancel/su', $text, $gm)) {
            $r['guest_name'] = trim(preg_replace('/\s+/u', ' ', $gm[1]));
        }
        return $r;
    }

    // ── Host cancelled from Airbnb (dates only) ───────────────────────
    if (preg_match('/^Reservation canceled: (.+)$/u', $subject, $m)) {
        $r['type'] = 'host_cancel';
        [$r['check_in'], $r['check_out']] = airbnb_parse_date_range($m[1], $sent);
        if (preg_match('/([\d,]+\.\d{2}) will be deducted/u', $text, $am)) {
            $r['payout_amount'] = airbnb_parse_amount($am[1]); // penalty, informational
        }
        if ($r['check_in'] === null || $r['check_out'] === null) $r['errors'][] = 'Could not read cancelled dates';
        return $r;
    }

    // ── Reservation changed (code only, new dates not in the email) ───
    if ($subject === 'Reservation updated' || $subject === 'Your reservation change was accepted') {
        $r['type'] = 'updated';
        $r['confirmation_code'] = $firstCode();
        foreach ($lines as $i => $l) {
            if (preg_match('/^(?:YOUR RESERVATION WITH (.+) HAS BEEN UPDATED|(.+) AGREED TO CHANGE THEIR RESERVATION)$/u', $l, $nm)) {
                $r['guest_name'] = trim(($nm[1] ?? '') !== '' ? $nm[1] : ($nm[2] ?? ''));
                // guest name, location, listing title follow
                $r['listing_title'] = $lines[$i + 3] ?? null;
            }
        }
        if ($r['confirmation_code'] === null) $r['errors'][] = 'Could not read confirmation_code';
        return $r;
    }

    // ── Change request (dates, no code) ───────────────────────────────
    if (preg_match('/^(.+) wants to change their reservation$/u', $subject, $m)) {
        $r['type'] = 'change_request';
        $r['guest_name'] = trim($m[1]);
        foreach ($lines as $i => $l) {
            if ($l === 'ORIGINAL DATES' && isset($lines[$i + 1])) {
                [$r['check_in'], $r['check_out']] = airbnb_parse_date_range($lines[$i + 1], $sent);
                $r['listing_title'] = preg_replace('/^\S+\s·\s/u', '', (string)($lines[$i - 1] ?? ''));
            }
            if ($l === 'REQUESTED DATES' && isset($lines[$i + 1])) {
                [$r['requested_check_in'], $r['requested_check_out']] = airbnb_parse_date_range($lines[$i + 1], $sent);
            }
        }
        return $r;
    }

    return $r;
}
