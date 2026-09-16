<?php
/**
 * Minimal read-only IMAP client over TLS.
 *
 * Written against plain sockets so it does not need the PHP imap extension
 * (removed from core in PHP 8.4 and not guaranteed on shared hosting).
 * Only what the Airbnb sync needs: LOGIN, EXAMINE, UID SEARCH, UID FETCH BODY.PEEK[].
 * EXAMINE opens the mailbox read-only, and BODY.PEEK never sets \Seen,
 * so staff still see Airbnb mails as unread in Gmail.
 */

declare(strict_types=1);

final class AirbnbImapClient
{
    /** @var resource|null */
    private $sock = null;
    private int $tagNo = 0;

    public function connect(string $host, int $port = 993, int $timeout = 30): void
    {
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
        $sock = @stream_socket_client("ssl://{$host}:{$port}", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$sock) {
            throw new RuntimeException("IMAP connect failed: {$errstr} ({$errno})");
        }
        stream_set_timeout($sock, $timeout);
        $this->sock = $sock;
        $greeting = $this->readLine();
        if (strpos($greeting, '* OK') !== 0) {
            throw new RuntimeException('IMAP greeting not OK');
        }
    }

    public function login(string $user, string $pass): void
    {
        try {
            $this->command('LOGIN ' . $this->quote($user) . ' ' . $this->quote($pass));
        } catch (RuntimeException $e) {
            // Never echo the command back — it carries the password.
            throw new RuntimeException('IMAP login failed for ' . $user . ' — check the Gmail App Password');
        }
    }

    /** Open read-only. Returns UIDVALIDITY. */
    public function examine(string $mailbox): int
    {
        $lines = $this->command('EXAMINE ' . $this->quote($mailbox));
        foreach ($lines as $l) {
            if (preg_match('/UIDVALIDITY (\d+)/i', $l, $m)) {
                return (int)$m[1];
            }
        }
        return 0;
    }

    /** @return list<int> */
    public function uidSearch(string $criteria): array
    {
        $uids = [];
        foreach ($this->command('UID SEARCH ' . $criteria) as $l) {
            if (preg_match('/^\* SEARCH\b(.*)$/i', $l, $m)) {
                foreach (preg_split('/\s+/', trim($m[1])) as $u) {
                    if ($u !== '') $uids[] = (int)$u;
                }
            }
        }
        sort($uids);
        return $uids;
    }

    /**
     * Header block per UID (cheap; lets the caller skip mails it doesn't need).
     * @param list<int> $uids
     * @return array<int,string> uid => raw header text
     */
    public function uidFetchHeaders(array $uids, string $fields = 'SUBJECT DATE MESSAGE-ID'): array
    {
        $out = [];
        foreach (array_chunk($uids, 100) as $chunk) {
            $items = $this->command('UID FETCH ' . implode(',', $chunk) . ' (UID BODY.PEEK[HEADER.FIELDS (' . $fields . ')])', true);
            foreach ($items as $item) {
                if (is_array($item) && $item['literal'] !== null && preg_match('/\bUID (\d+)/i', $item['line'], $m)) {
                    $out[(int)$m[1]] = $item['literal'];
                }
            }
        }
        return $out;
    }

    /** Full raw RFC822 message without touching flags. */
    public function uidFetchRaw(int $uid): ?string
    {
        foreach ($this->command('UID FETCH ' . $uid . ' (UID BODY.PEEK[])', true) as $item) {
            if (is_array($item) && $item['literal'] !== null) {
                return $item['literal'];
            }
        }
        return null;
    }

    public function logout(): void
    {
        if (!$this->sock) return;
        try { $this->command('LOGOUT'); } catch (Throwable $ignored) {}
        @fclose($this->sock);
        $this->sock = null;
    }

    public function quote(string $s): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $s) . '"';
    }

    /**
     * Send a command and collect untagged responses until the tagged reply.
     * With $withLiterals each response is ['line' => string, 'literal' => ?string].
     */
    private function command(string $cmd, bool $withLiterals = false): array
    {
        if (!$this->sock) throw new RuntimeException('IMAP not connected');
        $tag = 'A' . (++$this->tagNo);
        if (fwrite($this->sock, $tag . ' ' . $cmd . "\r\n") === false) {
            throw new RuntimeException('IMAP write failed');
        }
        $out = [];
        while (true) {
            $line = $this->readLine();
            $literal = null;
            if (preg_match('/\{(\d+)\}$/', $line, $m)) {
                $literal = $this->readBytes((int)$m[1]);
                $line .= $this->readLine(); // rest of the response after the literal, e.g. ")"
            }
            if (strpos($line, $tag . ' ') === 0) {
                if (!preg_match('/^' . $tag . ' OK\b/i', $line)) {
                    $verb = strtok($cmd, ' ');
                    throw new RuntimeException('IMAP ' . $verb . ' failed: ' . trim(substr($line, strlen($tag) + 1)));
                }
                return $out;
            }
            $out[] = $withLiterals ? ['line' => $line, 'literal' => $literal] : $line;
        }
    }

    private function readLine(): string
    {
        $line = fgets($this->sock);
        if ($line === false) {
            $meta = stream_get_meta_data($this->sock);
            throw new RuntimeException(!empty($meta['timed_out']) ? 'IMAP read timed out' : 'IMAP connection closed');
        }
        return rtrim($line, "\r\n");
    }

    private function readBytes(int $n): string
    {
        // fread() on an ssl:// stream returns short/empty reads between TLS records;
        // stream_get_contents() blocks until $n bytes arrive, EOF, or the timeout.
        $buf = '';
        $empty = 0;
        while (strlen($buf) < $n) {
            $chunk = stream_get_contents($this->sock, $n - strlen($buf));
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($this->sock);
                if (!empty($meta['timed_out']) || ++$empty > 3) {
                    throw new RuntimeException('IMAP literal read failed');
                }
                continue;
            }
            $empty = 0;
            $buf .= $chunk;
        }
        return $buf;
    }
}
