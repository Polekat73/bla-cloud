<?php
declare(strict_types=1);

namespace BlaCloud;

final class Auth
{
    // Brute-force limits (per 15 minutes)
    public const WINDOW_MIN        = 15;
    public const MAX_FAILS_PER_IP  = 20;
    public const MAX_FAILS_PER_USER = 8;

    private static ?array $user = null;

    // ---------- Current user ----------

    public static function user(): ?array
    {
        if (self::$user !== null) {
            return self::$user;
        }
        $id = $_SESSION['uid'] ?? null;
        if (!is_int($id)) {
            return null;
        }
        $u = Database::one('SELECT * FROM bla_users WHERE id = ? AND is_active = 1', [$id]);
        // Log out everywhere else if the password was changed.
        if (!$u || !hash_equals((string) ($_SESSION['pwfp'] ?? ''), self::fingerprint($u))) {
            unset($_SESSION['uid']);
            return null;
        }
        return self::$user = $u;
    }

    public static function id(): ?int
    {
        return self::user()['id'] ?? null;
    }

    public static function requireUser(): array
    {
        $u = self::user();
        if ($u) {
            return $u;
        }
        if (isset($_SESSION['pending_enroll'])) {
            View::redirect('2fa-setup');
        }
        if (Request::wantsJson()) {
            View::json(['ok' => false, 'error' => 'Please sign in again.'], 401);
        }
        View::redirect('login');
    }

    public static function requireAdmin(): array
    {
        $u = self::requireUser();
        if (!(int) $u['is_admin']) {
            http_response_code(403);
            View::render('error', ['title' => 'Not allowed', 'message' => 'This page is for administrators only.']);
            exit;
        }
        return $u;
    }

    private static function fingerprint(array $u): string
    {
        return substr(hash('sha256', $u['password_hash'] . '|' . $u['id']), 0, 24);
    }

    private static function complete(array $u): void
    {
        Session::regenerate();
        unset($_SESSION['pending_2fa'], $_SESSION['pending_enroll'], $_SESSION['pending_at']);
        $_SESSION['uid']  = (int) $u['id'];
        $_SESSION['pwfp'] = self::fingerprint($u);
        Database::run('UPDATE bla_users SET last_login_at = ? WHERE id = ?', [Database::now(), $u['id']]);
        self::$user = null;
    }

    public static function logout(): void
    {
        $id = self::id();
        if ($id) {
            Audit::log($id, 'logout');
        }
        Session::destroy();
    }

    // ---------- Rate limiting ----------

    private static function since(): string
    {
        return gmdate('Y-m-d H:i:s', time() - self::WINDOW_MIN * 60);
    }

    public static function isThrottled(string $username): bool
    {
        $ip = Request::clientIp();
        $byIp = (int) Database::one(
            'SELECT COUNT(*) AS n FROM bla_login_attempts WHERE ip = ? AND success = 0 AND created_at > ?',
            [$ip, self::since()])['n'];
        $byUser = $username === '' ? 0 : (int) Database::one(
            'SELECT COUNT(*) AS n FROM bla_login_attempts WHERE username = ? AND success = 0 AND created_at > ?',
            [mb_strtolower($username), self::since()])['n'];
        return $byIp >= self::MAX_FAILS_PER_IP || $byUser >= self::MAX_FAILS_PER_USER;
    }

    private static function recordAttempt(string $username, string $kind, bool $ok): void
    {
        Database::run(
            'INSERT INTO bla_login_attempts (ip, username, kind, success, created_at) VALUES (?, ?, ?, ?, ?)',
            [Request::clientIp(), mb_substr(mb_strtolower($username), 0, 64), $kind, $ok ? 1 : 0, Database::now()]);
        // Housekeeping: drop records older than a day.
        if (random_int(1, 50) === 1) {
            Database::run('DELETE FROM bla_login_attempts WHERE created_at < ?', [gmdate('Y-m-d H:i:s', time() - 86400)]);
        }
    }

    // ---------- Step 1: password ----------

    /**
     * Returns one of: 'ok', '2fa', 'enroll', 'invalid', 'throttled'.
     */
    public static function attemptPassword(string $username, string $password): string
    {
        $username = trim($username);
        if (self::isThrottled($username)) {
            Audit::log(null, 'login.throttled', $username);
            return 'throttled';
        }
        $u = Database::one('SELECT * FROM bla_users WHERE LOWER(username) = LOWER(?) OR (email <> \'\' AND LOWER(email) = LOWER(?))',
            [$username, $username]);

        // Always spend the same effort, so attackers can't tell which usernames exist.
        $dummy = defined('PASSWORD_ARGON2ID')
            ? '$argon2id$v=19$m=65536,t=4,p=1$T2U3YzVxTjExaW5mNnFPdw$jKhLQRVy+/klDWoXdVquN92hZW1U5K3mAxIeUwPT4Y8'
            : '$2y$12$Dz7N4KnEfVFwM9vOK3K88.KWWhukphFY2I/PrAvF.pjCHgfBta8G2';
        $hash = $u['password_hash'] ?? $dummy;
        $valid = password_verify($password, $hash) && $u && (int) $u['is_active'] === 1;

        if (!$valid) {
            self::recordAttempt($username, 'password', false);
            Audit::log($u['id'] ?? null, 'login.failed', $username);
            usleep(random_int(200000, 400000));
            return 'invalid';
        }
        self::recordAttempt($username, 'password', true);

        if (password_needs_rehash($u['password_hash'], defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT)) {
            Database::run('UPDATE bla_users SET password_hash = ? WHERE id = ?', [Security::hashPassword($password), $u['id']]);
        }

        Session::regenerate();
        $_SESSION['pending_at'] = time();
        if ((int) $u['totp_enabled'] === 1) {
            $_SESSION['pending_2fa'] = (int) $u['id'];
            return '2fa';
        }
        if (self::twoFactorRequired($u)) {
            $_SESSION['pending_enroll'] = (int) $u['id'];
            Audit::log((int) $u['id'], 'login.password_ok_enroll_required');
            return 'enroll';
        }
        self::complete($u);
        Audit::log((int) $u['id'], 'login.success');
        return 'ok';
    }

    public static function twoFactorRequired(array $u): bool
    {
        return (int) $u['is_admin'] === 1 || (bool) Settings::get('require_2fa_all');
    }

    /** User who has passed the password step and is waiting on 2FA (valid for 10 minutes). */
    public static function pendingUser(string $kind): ?array
    {
        $id = $_SESSION[$kind] ?? null;
        if (!is_int($id) || time() - (int) ($_SESSION['pending_at'] ?? 0) > 600) {
            return null;
        }
        return Database::one('SELECT * FROM bla_users WHERE id = ? AND is_active = 1', [$id]);
    }

    // ---------- Step 2: second factor ----------

    /** Returns 'ok', 'invalid' or 'throttled'. Accepts a 6-digit code or a recovery code. */
    public static function attemptSecondFactor(string $code): string
    {
        $u = self::pendingUser('pending_2fa');
        if (!$u) {
            return 'invalid';
        }
        if (self::isThrottled($u['username'])) {
            return 'throttled';
        }
        $code = trim($code);
        $ok = false;
        $how = 'totp';
        if (preg_match('/^\d[\d\s]{5,7}$/', $code)) {
            $step = Totp::verify(Security::decrypt((string) $u['totp_secret']), $code, (int) $u['totp_last_step']);
            if ($step !== null) {
                Database::run('UPDATE bla_users SET totp_last_step = ? WHERE id = ?', [$step, $u['id']]);
                $ok = true;
            }
        } else {
            $how = 'recovery';
            $ok = RecoveryCodes::consume((int) $u['id'], $code);
        }
        self::recordAttempt($u['username'], '2fa', $ok);
        if (!$ok) {
            Audit::log((int) $u['id'], '2fa.failed');
            usleep(random_int(200000, 400000));
            return 'invalid';
        }
        self::complete($u);
        Audit::log((int) $u['id'], 'login.success', $how === 'recovery' ? 'used a recovery code' : '2FA');
        if ($how === 'recovery') {
            $left = RecoveryCodes::remaining((int) $u['id']);
            Session::flash('warning', "You signed in with a recovery code. You have $left left — you can make new ones in Security settings.");
        }
        return 'ok';
    }

    /** Enable TOTP for a user after they proved they can generate a valid code. */
    public static function enableTotp(array $u, string $secretB32, string $code): bool
    {
        $step = Totp::verify($secretB32, $code);
        if ($step === null) {
            return false;
        }
        Database::run('UPDATE bla_users SET totp_secret = ?, totp_enabled = 1, totp_last_step = ? WHERE id = ?',
            [Security::encrypt($secretB32), $step, $u['id']]);
        Audit::log((int) $u['id'], '2fa.enabled');
        // If they were in the forced-enrollment state, this finishes their sign-in.
        if (($_SESSION['pending_enroll'] ?? null) === (int) $u['id']) {
            $fresh = Database::one('SELECT * FROM bla_users WHERE id = ?', [$u['id']]);
            self::complete($fresh);
            Audit::log((int) $u['id'], 'login.success', 'after 2FA setup');
        }
        return true;
    }

    public static function changePassword(array $u, string $current, string $new): ?string
    {
        if (!password_verify($current, $u['password_hash'])) {
            return 'Your current password is not correct.';
        }
        if ($problem = Security::passwordProblem($new, $u['username'])) {
            return $problem;
        }
        $hash = Security::hashPassword($new);
        Database::run('UPDATE bla_users SET password_hash = ? WHERE id = ?', [$hash, $u['id']]);
        $u['password_hash'] = $hash;
        $_SESSION['pwfp'] = self::fingerprint($u);
        self::$user = null;
        Audit::log((int) $u['id'], 'password.changed');
        return null;
    }
}
