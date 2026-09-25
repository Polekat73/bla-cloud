<?php
declare(strict_types=1);

namespace BlaCloud;

/**
 * Per-device passwords for WebDAV/CalDAV/CardDAV. Apps and OS sync clients can't answer a 2FA
 * prompt, so each device gets its own long random password instead, which can be revoked on its own.
 */
final class AppPasswords
{
    public static function forUser(int $userId): array
    {
        return Database::all('SELECT id, label, created_at, last_used_at, last_used_ip FROM bla_app_passwords
            WHERE user_id = ? ORDER BY id DESC', [$userId]);
    }

    /** Returns [id, secret] — the secret is shown once and never stored in the clear. */
    public static function create(int $userId, string $label): array
    {
        $label = mb_substr(trim($label), 0, 128) ?: 'Unnamed device';
        $secret = bin2hex(random_bytes(18)); // hex only, so chunk_split() in format() never collides with a dash
        $id = Database::insert('INSERT INTO bla_app_passwords (user_id, label, password_hash, created_at) VALUES (?, ?, ?, ?)',
            [$userId, $label, Security::hashPassword($secret), Database::now()]);
        Audit::log($userId, 'apppassword.created', $label);
        return [$id, $secret];
    }

    public static function revoke(int $userId, int $id): void
    {
        $row = Database::one('SELECT label FROM bla_app_passwords WHERE id = ? AND user_id = ?', [$id, $userId]);
        if (!$row) {
            return;
        }
        Database::run('DELETE FROM bla_app_passwords WHERE id = ? AND user_id = ?', [$id, $userId]);
        Audit::log($userId, 'apppassword.revoked', $row['label']);
    }

    /** Formatted for display when just created: "abcd-efgh-ijkl-...". */
    public static function format(string $secret): string
    {
        return trim(chunk_split($secret, 4, '-'), '-');
    }

    // ---------- Authenticating a WebDAV/CalDAV/CardDAV request ----------

    private static function since(): string
    {
        return gmdate('Y-m-d H:i:s', time() - Auth::WINDOW_MIN * 60);
    }

    private static function isThrottled(string $username): bool
    {
        $ip = Request::clientIp();
        $byIp = (int) Database::one(
            "SELECT COUNT(*) AS n FROM bla_login_attempts WHERE ip = ? AND kind = 'dav' AND success = 0 AND created_at > ?",
            [$ip, self::since()])['n'];
        $byUser = $username === '' ? 0 : (int) Database::one(
            "SELECT COUNT(*) AS n FROM bla_login_attempts WHERE username = ? AND kind = 'dav' AND success = 0 AND created_at > ?",
            [mb_strtolower($username), self::since()])['n'];
        return $byIp >= Auth::MAX_FAILS_PER_IP || $byUser >= Auth::MAX_FAILS_PER_USER;
    }

    private static function recordAttempt(string $username, bool $ok): void
    {
        Database::run('INSERT INTO bla_login_attempts (ip, username, kind, success, created_at) VALUES (?, ?, ?, ?, ?)',
            [Request::clientIp(), mb_substr(mb_strtolower($username), 0, 64), 'dav', $ok ? 1 : 0, Database::now()]);
    }

    /** Verify a "username" + app-password secret pair (HTTP Basic auth). Returns the user row, or null. */
    public static function verify(string $username, string $secret): ?array
    {
        $username = trim($username);
        if ($username === '' || $secret === '' || self::isThrottled($username)) {
            return null;
        }
        $u = Database::one('SELECT * FROM bla_users WHERE LOWER(username) = LOWER(?) AND is_active = 1', [$username]);
        if (!$u) {
            self::recordAttempt($username, false);
            usleep(random_int(100000, 200000));
            return null;
        }
        $rows = Database::all('SELECT id, password_hash FROM bla_app_passwords WHERE user_id = ?', [$u['id']]);
        foreach ($rows as $row) {
            if (password_verify($secret, $row['password_hash'])) {
                self::recordAttempt($username, true);
                Database::run('UPDATE bla_app_passwords SET last_used_at = ?, last_used_ip = ? WHERE id = ?',
                    [Database::now(), Request::clientIp(), $row['id']]);
                return $u;
            }
        }
        self::recordAttempt($username, false);
        usleep(random_int(100000, 200000));
        return null;
    }
}
