<?php
declare(strict_types=1);

namespace BlaCloud;

/**
 * Sends files to the browser safely: downloads, inline previews (with HTTP Range support so
 * videos can be skipped through), thumbnails.
 */
final class FileSender
{
    /** Types that may be shown inside the browser. Everything else is always downloaded. */
    private const INLINE = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif',
        'webp' => 'image/webp', 'avif' => 'image/avif', 'bmp' => 'image/bmp', 'svg' => 'image/svg+xml',
        'pdf' => 'application/pdf',
        'mp4' => 'video/mp4', 'm4v' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime',
        'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'aac' => 'audio/aac', 'wav' => 'audio/wav',
        'ogg' => 'audio/ogg', 'opus' => 'audio/ogg', 'flac' => 'audio/flac',
        'txt' => 'text/plain', 'md' => 'text/plain', 'csv' => 'text/plain', 'log' => 'text/plain',
        'json' => 'text/plain', 'xml' => 'text/plain', 'yml' => 'text/plain', 'yaml' => 'text/plain',
        'ini' => 'text/plain', 'html' => 'text/plain', 'htm' => 'text/plain', 'css' => 'text/plain',
        'js' => 'text/plain', 'php' => 'text/plain', 'py' => 'text/plain', 'sh' => 'text/plain', 'sql' => 'text/plain',
    ];

    public static function inlineType(string $name): ?string
    {
        return self::INLINE[strtolower(pathinfo($name, PATHINFO_EXTENSION))] ?? null;
    }

    /** What kind of viewer the front-end should use, or null if none. */
    public static function viewer(string $name): ?string
    {
        $t = self::inlineType($name);
        return match (true) {
            $t === null => null,
            str_starts_with($t, 'image/') => 'image',
            $t === 'application/pdf' => 'pdf',
            str_starts_with($t, 'video/') => 'video',
            str_starts_with($t, 'audio/') => 'audio',
            default => 'text',
        };
    }

    public static function attachmentHeader(string $name): string
    {
        $translit = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) : false;
        $ascii = preg_replace('/[^\x20-\x7E]|["\\\\]/', '_', $translit !== false && $translit !== '' ? $translit : $name);
        return 'attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name);
    }

    private static function clean(): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close(); // don't block other tabs during long transfers
        }
    }

    public static function download(string $abs, string $name, ?int $mtime = null): never
    {
        self::clean();
        Security::sendHeaders();
        header("Content-Security-Policy: default-src 'none'; sandbox");
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: ' . self::attachmentHeader($name));
        header('Cache-Control: private, no-store');
        self::stream($abs, $mtime);
    }

    /** Show a file inside the page (viewer). Unknown types fall back to download. */
    public static function inline(string $abs, string $name): never
    {
        $type = self::inlineType($name);
        if ($type === null) {
            self::download($abs, $name);
        }
        self::clean();
        Security::sendHeaders();
        if ($type === 'application/pdf') {
            // The browser's PDF viewer can't run inside a sandbox; the page still can't run scripts of ours.
            header("Content-Security-Policy: default-src 'none'; object-src 'self'; frame-ancestors 'self'");
            header('X-Frame-Options: SAMEORIGIN');
            header('Cross-Origin-Opener-Policy: unsafe-none');
        } else {
            // Sandboxed: an uploaded HTML/SVG can never run script in your session.
            header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; media-src 'self'; style-src 'unsafe-inline'; sandbox; frame-ancestors 'self'");
            header('X-Frame-Options: SAMEORIGIN');
        }
        header('Content-Type: ' . $type . (str_starts_with($type, 'text/') ? '; charset=utf-8' : ''));
        header('Content-Disposition: inline; filename*=UTF-8\'\'' . rawurlencode($name));
        header('Cache-Control: private, max-age=0, must-revalidate');
        self::stream($abs, (int) filemtime($abs));
    }

    public static function thumbnail(string $abs, string $mime): never
    {
        self::clean();
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; sandbox");
        header('Content-Type: ' . $mime);
        header('Cache-Control: private, max-age=604800');
        header('Content-Length: ' . filesize($abs));
        readfile($abs);
        exit;
    }

    /** Stream a file with support for a single byte range (HTTP 206). */
    private static function stream(string $abs, ?int $mtime): never
    {
        $size = (int) filesize($abs);
        $start = 0;
        $end = $size - 1;
        header('Accept-Ranges: bytes');
        if ($mtime) {
            $etag = '"' . substr(hash('sha256', $abs . '|' . $size . '|' . $mtime), 0, 24) . '"';
            header('ETag: ' . $etag);
            header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
        }
        $range = $_SERVER['HTTP_RANGE'] ?? '';
        if ($range !== '' && $size > 0 && preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $m)) {
            if ($m[1] === '' && $m[2] !== '') {           // last N bytes
                $start = max(0, $size - (int) $m[2]);
            } elseif ($m[1] !== '') {
                $start = (int) $m[1];
                $end = $m[2] !== '' ? min((int) $m[2], $size - 1) : $size - 1;
            }
            if ($start > $end || $start >= $size) {
                http_response_code(416);
                header("Content-Range: bytes */$size");
                exit;
            }
            http_response_code(206);
            header("Content-Range: bytes $start-$end/$size");
        }
        $length = $size === 0 ? 0 : $end - $start + 1;
        header('Content-Length: ' . $length);
        header('X-Accel-Buffering: no');
        if (Request::method() === 'HEAD') {
            exit;
        }
        @set_time_limit(0);
        $fh = fopen($abs, 'rb');
        if ($fh) {
            fseek($fh, $start);
            $left = $length;
            while ($left > 0 && !feof($fh)) {
                $chunk = fread($fh, (int) min(1024 * 1024, $left));
                if ($chunk === false) {
                    break;
                }
                echo $chunk;
                $left -= strlen($chunk);
                flush();
                if (connection_aborted()) {
                    break;
                }
            }
            fclose($fh);
        }
        exit;
    }
}
