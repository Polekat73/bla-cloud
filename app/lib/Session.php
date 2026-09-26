<?php
declare(strict_types=1);

namespace BlaCloud;

final class Session
{
    public const IDLE_TIMEOUT = 60 * 60 * 8;   // 8 hours of inactivity
    public const MAX_LIFETIME = 60 * 60 * 24 * 7; // 7 days absolute

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.gc_maxlifetime', (string) self::IDLE_TIMEOUT);

        // Keep sessions inside our own data folder when possible (shared hosts share /tmp).
        $dir = Config::get('data_dir');
        if (is_string($dir) && is_dir($dir)) {
            $sessDir = $dir . '/.sessions';
            if (!is_dir($sessDir)) {
                @mkdir($sessDir, 0700, true);
            }
            if (is_dir($sessDir) && is_writable($sessDir)) {
                session_save_path($sessDir);
            }
        }

        $secure = Request::isHttps();
        session_name($secure ? '__Host-haven' : 'haven');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();

        $now = time();
        $created = $_SESSION['_created'] ?? $now;
        $last    = $_SESSION['_last'] ?? $now;
        if ($now - $last > self::IDLE_TIMEOUT || $now - $created > self::MAX_LIFETIME) {
            self::destroy();
            session_start();
            $created = $now;
        }
        $_SESSION['_created'] = $created;
        $_SESSION['_last'] = $now;
    }

    /** New session id after privilege change (login, 2FA) to prevent fixation. */
    public static function regenerate(): void
    {
        session_regenerate_id(true);
        $_SESSION['_created'] = time();
        unset($_SESSION['_csrf']);
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000, 'path' => $p['path'], 'secure' => $p['secure'],
                'httponly' => true, 'samesite' => $p['samesite'] ?: 'Lax',
            ]);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    public static function flash(string $type, string $message): void
    {
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }

    public static function takeFlashes(): array
    {
        $f = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $f;
    }
}
