<?php
declare(strict_types=1);

namespace BlaCloud;

/**
 * Sends email through SMTP (written from scratch: SSL/STARTTLS, AUTH LOGIN/PLAIN)
 * or through PHP's mail() function on hosts that provide it.
 */
final class Mailer
{
    public static function enabled(): bool
    {
        $mode = (string) Settings::get('mail_mode');
        return ($mode === 'php' || ($mode === 'smtp' && Settings::get('smtp_host') !== ''))
            && filter_var((string) Settings::get('mail_from'), FILTER_VALIDATE_EMAIL);
    }

    /** Send a branded email. Returns null on success or an error message. */
    public static function send(string $to, string $subject, string $heading, array $paragraphs,
                                ?string $buttonText = null, ?string $buttonUrl = null): ?string
    {
        if (!self::enabled()) {
            return 'Email is not set up.';
        }
        if (!filter_var($to, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $to . $subject)) {
            return 'Invalid recipient.';
        }
        [$text, $html] = self::render($heading, $paragraphs, $buttonText, $buttonUrl);
        try {
            Settings::get('mail_mode') === 'smtp'
                ? self::smtp($to, $subject, $text, $html)
                : self::phpMail($to, $subject, $text, $html);
            return null;
        } catch (\Throwable $e) {
            error_log('[BLA-Cloud] mail to ' . $to . ' failed: ' . $e->getMessage());
            return $e->getMessage();
        }
    }

    // ---------- Message building ----------

    public static function render(string $heading, array $paragraphs, ?string $buttonText, ?string $buttonUrl): array
    {
        $name = (string) Config::get('instance_name', 'BLA-Cloud');
        $text = $heading . "\n\n" . implode("\n\n", $paragraphs);
        if ($buttonText && $buttonUrl) {
            $text .= "\n\n" . $buttonText . ":\n" . $buttonUrl;
        }
        $text .= "\n\n— " . $name . " · Best Life Apps\n";

        $e = static fn (string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $ps = '';
        foreach ($paragraphs as $p) {
            $ps .= '<p style="margin:0 0 16px;font:17px/1.55 Georgia,serif;color:#D8C9A3">' . nl2br($e($p)) . '</p>';
        }
        $btn = '';
        if ($buttonText && $buttonUrl) {
            $btn = '<p style="margin:28px 0"><a href="' . $e($buttonUrl) . '" style="display:inline-block;background:#BA8D35;color:#07070F;'
                 . 'text-decoration:none;font:600 16px Georgia,serif;letter-spacing:1px;padding:13px 26px;border-radius:8px">' . $e($buttonText) . '</a></p>'
                 . '<p style="margin:0 0 16px;font:13px/1.5 Georgia,serif;color:#8A7E5C">Or copy this link: <span style="color:#BA8D35;word-break:break-all">' . $e($buttonUrl) . '</span></p>';
        }
        $html = '<!DOCTYPE html><html><body style="margin:0;padding:0;background:#07070F">'
              . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#07070F;padding:32px 12px"><tr><td align="center">'
              . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;background:#0F0F1A;border:1px solid #3a2f18;border-radius:14px">'
              . '<tr><td style="padding:28px 32px 8px;font:600 13px Georgia,serif;letter-spacing:4px;color:#8A7E5C;text-transform:uppercase">Best Life Apps · ' . $e($name) . '</td></tr>'
              . '<tr><td style="padding:8px 32px 8px"><h1 style="margin:0 0 18px;font:600 24px Georgia,serif;color:#BA8D35;letter-spacing:1px">' . $e($heading) . '</h1>'
              . $ps . $btn . '</td></tr>'
              . '<tr><td style="padding:16px 32px 28px;border-top:1px solid #2a2415;font:13px/1.5 Georgia,serif;color:#8A7E5C">'
              . 'You received this because of your account at ' . $e($name) . '. If you weren\'t expecting it, you can ignore this email.</td></tr>'
              . '</table></td></tr></table></body></html>';
        return [$text, $html];
    }

    private static function encodeHeader(string $s): string
    {
        return preg_match('/[^\x20-\x7E]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
    }

    private static function fromHeader(): string
    {
        $name = str_replace(['"', "\r", "\n"], '', (string) Settings::get('mail_from_name'));
        return self::encodeHeader($name) . ' <' . Settings::get('mail_from') . '>';
    }

    /** Full MIME message (headers + body) with plain-text and HTML parts. */
    public static function build(string $to, string $subject, string $text, string $html): array
    {
        $boundary = 'bla-' . bin2hex(random_bytes(12));
        $domain = substr(strrchr((string) Settings::get('mail_from'), '@') ?: '@localhost', 1);
        $headers = [
            'From: ' . self::fromHeader(),
            'To: <' . $to . '>',
            'Subject: ' . self::encodeHeader($subject),
            'Date: ' . date(DATE_RFC2822),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            'Auto-Submitted: auto-generated',
        ];
        $body = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
              . chunk_split(base64_encode($text))
              . "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
              . chunk_split(base64_encode($html))
              . "--$boundary--\r\n";
        return [$headers, $body];
    }

    private static function phpMail(string $to, string $subject, string $text, string $html): void
    {
        [$headers, $body] = self::build($to, $subject, $text, $html);
        // mail() takes To and Subject separately.
        $extra = array_values(array_filter($headers, static fn ($h) => !str_starts_with($h, 'To:') && !str_starts_with($h, 'Subject:')));
        if (!@mail($to, self::encodeHeader($subject), $body, implode("\r\n", $extra), '-f' . Settings::get('mail_from'))) {
            throw new \RuntimeException('The server\'s mail() function refused the message. Try SMTP instead.');
        }
    }

    // ---------- SMTP ----------

    private static function smtp(string $to, string $subject, string $text, string $html): void
    {
        $host = (string) Settings::get('smtp_host');
        $port = (int) Settings::get('smtp_port');
        $sec  = (string) Settings::get('smtp_security');
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
        $remote = ($sec === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $fp = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) {
            throw new \RuntimeException("Could not connect to $host:$port ($errstr).");
        }
        stream_set_timeout($fp, 20);
        try {
            self::expect($fp, 220);
            $ehlo = 'EHLO ' . (preg_replace('/[^A-Za-z0-9.\-]/', '', (string) ($_SERVER['SERVER_NAME'] ?? 'localhost')) ?: 'localhost');
            $caps = self::cmd($fp, $ehlo, 250);
            if ($sec === 'starttls') {
                self::cmd($fp, 'STARTTLS', 220);
                if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                    throw new \RuntimeException('Could not start an encrypted connection (STARTTLS).');
                }
                $caps = self::cmd($fp, $ehlo, 250);
            }
            $user = (string) Settings::get('smtp_user');
            if ($user !== '') {
                $pass = Settings::get('smtp_pass') !== '' ? Security::decrypt((string) Settings::get('smtp_pass')) : '';
                if ($sec === 'none' && !in_array($host, ['localhost', '127.0.0.1'], true)) {
                    throw new \RuntimeException('Refusing to send your mail password over an unencrypted connection. Choose STARTTLS or SSL.');
                }
                if (stripos($caps, 'PLAIN') !== false) {
                    self::cmd($fp, 'AUTH PLAIN ' . base64_encode("\0" . $user . "\0" . $pass), 235);
                } else {
                    self::cmd($fp, 'AUTH LOGIN', 334);
                    self::cmd($fp, base64_encode($user), 334);
                    self::cmd($fp, base64_encode($pass), 235);
                }
            }
            self::cmd($fp, 'MAIL FROM:<' . Settings::get('mail_from') . '>', 250);
            self::cmd($fp, 'RCPT TO:<' . $to . '>', [250, 251]);
            self::cmd($fp, 'DATA', 354);
            [$headers, $body] = self::build($to, $subject, $text, $html);
            $data = implode("\r\n", $headers) . "\r\n\r\n" . $body;
            $data = preg_replace('/^\./m', '..', str_replace(["\r\n", "\r"], "\n", $data)); // dot-stuffing
            fwrite($fp, str_replace("\n", "\r\n", $data) . "\r\n.\r\n");
            self::expect($fp, 250);
            @fwrite($fp, "QUIT\r\n");
        } finally {
            fclose($fp);
        }
    }

    private static function cmd($fp, string $line, int|array $expect): string
    {
        fwrite($fp, $line . "\r\n");
        return self::expect($fp, $expect);
    }

    private static function expect($fp, int|array $codes): string
    {
        $codes = (array) $codes;
        $all = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $all .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        $code = (int) substr($all, 0, 3);
        if (!in_array($code, $codes, true)) {
            $msg = trim(preg_replace('/\s+/', ' ', $all) ?? '');
            throw new \RuntimeException('Mail server said: ' . ($msg !== '' ? mb_substr($msg, 0, 200) : 'no response'));
        }
        return $all;
    }
}
