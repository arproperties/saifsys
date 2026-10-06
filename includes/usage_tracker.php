<?php
/**
 * Usage tracker — which pages and which staff-app actions are really used.
 *
 * Loaded from db_connect.php, so every page that opens the database is counted
 * without being touched. One row per day, per page, per person in
 * usage_page_hits (migrations/usage_page_hits.sql); the same page opened 200
 * times in a day is still one row with a count. Read by admin/usage_report.php.
 *
 * What is stored: the page file, the user id, the day, how many opens (GET) and
 * how many saves (POST and the like). Nothing a person typed, no query string.
 *
 * Rules this file must keep:
 *   - it never breaks a page. Everything is wrapped; a missing table, a closed
 *     connection or a page that died mid-transaction just loses one count;
 *   - one small query per page file, written after the page has finished its
 *     own work (nearly always exactly one);
 *   - cron and command-line runs are not counted — they are not people.
 *
 * Switch it off without uploading code: settings key usage_tracking_off = 1
 * (the report page has the button).
 */

if (!function_exists('usage_track_boot')) {

    /** @return array{conn:?PDO,booted:bool,channel:?string,page:?string,user_id:?int} */
    function &usage_track_state(): array
    {
        static $state = ['conn' => null, 'booted' => false, 'channel' => null, 'page' => null, 'user_id' => null];
        return $state;
    }

    /** Called once from db_connect.php with the connection it just opened. */
    function usage_track_boot($conn): void
    {
        $state =& usage_track_state();
        if ($state['booted'] || !($conn instanceof PDO) || PHP_SAPI === 'cli') {
            return;
        }
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
            return;
        }
        $state['booted'] = true;
        $state['conn'] = $conn;
        register_shutdown_function('usage_track_flush');
    }

    /**
     * For the token APIs, where one index.php serves every action and the
     * person comes from a PIN token, not a session. Call it once the token is
     * verified:  usage_track_identify('cleaning', $route, $user['id']);
     *
     * Ids in the route are folded ("jobs/412/finish" -> "jobs/{id}/finish") so
     * an action is one line in the report however many jobs it was used on.
     */
    function usage_track_identify(string $app, string $route, int $userId): void
    {
        $state =& usage_track_state();
        $route = preg_replace('#(?<=/|^)\d+(?=/|$)#', '{id}', trim($route, '/'));
        $state['channel'] = usage_track_app_client();
        $state['page'] = $app . '/' . ($route === '' ? '(none)' : $route);
        $state['user_id'] = $userId;
    }

    /**
     * Which app the call came from. The web apps run in a browser, the APKs do
     * not, and the old Driver web app lives under /driver/.
     */
    function usage_track_app_client(): string
    {
        $agent = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        if (stripos($agent, 'Mozilla') !== 0) {
            return 'apk';
        }
        $referer = (string)(parse_url((string)($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_PATH) ?: '');
        return strpos($referer, '/driver/') !== false ? 'driver_web' : 'staff_web';
    }

    /**
     * The pages this request ran, as paths from the project root: the script
     * itself ("hr/payroll.php") first, then any other page file it pulled in.
     *
     * The second part matters for tabbed screens — account.php shows
     * accounts/batch_invoices.php by including it, so that file is never
     * requested on its own and would look dead for ever. Helper folders are
     * left out; they are not features.
     *
     * @return string[]
     */
    function usage_track_request_pages(): array
    {
        $root = realpath(dirname(__DIR__));
        $script = realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
        $relative = static function ($file) use ($root): ?string {
            if ($root === false || !is_string($file) || strpos($file, $root . DIRECTORY_SEPARATOR) !== 0) {
                return null;
            }
            return str_replace('\\', '/', substr($file, strlen($root) + 1));
        };

        $pages = [$relative($script) ?? ltrim((string)($_SERVER['SCRIPT_NAME'] ?? ''), '/')];
        foreach (get_included_files() as $file) {
            $page = $file === $script ? null : $relative($file);
            if ($page === null || preg_match('#(^|/)(includes|partials|vendor|lib|node_modules)/#', $page)) {
                continue;
            }
            $pages[] = $page;
            if (count($pages) >= 12) {
                break;
            }
        }
        return $pages;
    }

    function usage_track_flush(): void
    {
        try {
            $state = usage_track_state();
            $conn = $state['conn'];
            // A page that stopped inside its own transaction is left alone.
            if (!($conn instanceof PDO) || $conn->inTransaction()) {
                return;
            }

            if ($state['page'] !== null) {
                $channel = (string)$state['channel'];
                $pages = [$state['page']];
                $userId = (int)$state['user_id'];
            } else {
                $channel = 'web';
                $pages = usage_track_request_pages();
                $userId = (int)($_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? 0);
                // Nobody signed in: count it only if the page really answered.
                // A bot bounced to the login screen has not used anything.
                $code = (int)http_response_code();
                if ($userId <= 0 && ($code < 200 || $code >= 300)) {
                    return;
                }
            }

            $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
            $isSave = !in_array($method, ['GET', 'HEAD'], true);
            // Dubai time whatever the server clock says — live runs on UTC.
            $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Dubai'));

            // The off switch is read in the same statement, so a normal page
            // costs one round trip.
            $stmt = $conn->prepare("
                INSERT INTO usage_page_hits (hit_date, channel, page, user_id, views, saves, last_at)
                SELECT ?, ?, ?, ?, ?, ?, ? FROM DUAL
                WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'usage_tracking_off' AND `value` = '1')
                ON DUPLICATE KEY UPDATE
                    views = views + VALUES(views),
                    saves = saves + VALUES(saves),
                    last_at = VALUES(last_at)
            ");
            foreach ($pages as $page) {
                $page = substr(preg_replace('/[^\x20-\x7E]/', '?', (string)$page), 0, 190);
                if ($page === '') {
                    continue;
                }
                $stmt->execute([
                    $now->format('Y-m-d'),
                    $channel,
                    $page,
                    $userId,
                    $isSave ? 0 : 1,
                    $isSave ? 1 : 0,
                    $now->format('Y-m-d H:i:s'),
                ]);
            }
        } catch (Throwable $e) {
            // Counting is never worth an error on someone's screen.
        }
    }
}
