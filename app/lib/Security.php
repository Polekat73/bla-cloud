<?php
declare(strict_types=1);

namespace BlaCloud;

final class Security
{
    /** Security headers sent with every HTML/JSON response. */
    public static function sendHeaders(): void
    {
        if (headers_sent()) {
            return;
        }
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data: blob:; "
             . "font-src 'self'; connect-src 'self'; media-src 'self' blob:; frame-src 'self'; object-src 'none'; frame-ancestors 'none'; "
             . "base-uri 'self'; form-action 'self'");
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('X-Robots-Tag: noindex, nofollow');
        if (Request::isHttps()) {
            header('Strict-Transport-Security: max-age=31536000');
        }
    }

    public static function e(?string $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function token(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    // ---------- CSRF ----------

    public static function csrfToken(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = self::token();
        }
        return $_SESSION['_csrf'];
    }

    public static function csrfField(): string
    {
        return '<input type="hidden" name="_csrf" value="' . self::e(self::csrfToken()) . '">';
    }

    /** Verify the CSRF token from the form field or X-CSRF-Token header. Aborts on failure. */
    public static function requireCsrf(): void
    {
        $sent = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        $good = $_SESSION['_csrf'] ?? '';
        if (!is_string($sent) || $good === '' || !hash_equals($good, $sent)) {
            http_response_code(403);
            if (Request::wantsJson()) {
                View::json(['ok' => false, 'error' => 'Your session expired. Please reload the page and try again.'], 403);
            }
            View::render('error', [
                'title'   => 'Session expired',
                'message' => 'For your security this form expired. Please go back, reload the page and try again.',
            ]);
            exit;
        }
    }

    // ---------- Secret-at-rest encryption (libsodium) ----------

    private static function key(): string
    {
        $key = base64_decode((string) Config::get('app_key', ''), true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException('Missing or invalid app_key in config.');
        }
        return $key;
    }

    public static function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return 'v1:' . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, self::key()));
    }

    public static function decrypt(string $blob): string
    {
        if (!str_starts_with($blob, 'v1:')) {
            throw new \RuntimeException('Unknown secret format.');
        }
        $raw   = base64_decode(substr($blob, 3), true);
        $nonce = substr((string) $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $box   = substr((string) $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($box, $nonce, self::key());
        if ($plain === false) {
            throw new \RuntimeException('Could not decrypt a stored secret (wrong app_key?).');
        }
        return $plain;
    }

    // ---------- Passwords ----------

    public static function hashPassword(string $password): string
    {
        if (defined('PASSWORD_ARGON2ID')) {
            return password_hash($password, PASSWORD_ARGON2ID);
        }
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    /** Returns an error message, or null if the password is acceptable. */
    public static function passwordProblem(string $password, string $username = ''): ?string
    {
        if (mb_strlen($password) < 12) {
            return 'Use at least 12 characters. A short phrase of 3–4 words works well.';
        }
        if (mb_strlen($password) > 256) {
            return 'That password is too long (256 characters max).';
        }
        if ($username !== '' && stripos($password, $username) !== false) {
            return 'Your password should not contain your username.';
        }
        $common = ['password', '123456', 'qwerty', 'letmein', 'welcome', 'admin', 'iloveyou', 'nextcloud', 'haven'];
        foreach ($common as $c) {
            if (stripos($password, $c) !== false && mb_strlen($password) < 16) {
                return 'That password is too easy to guess. Try a longer phrase.';
            }
        }
        if (count(array_unique(mb_str_split($password))) < 5) {
            return 'That password repeats too few characters. Try a phrase instead.';
        }
        return null;
    }
}
