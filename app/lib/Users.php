<?php
declare(strict_types=1);

namespace BlaCloud;

/** Account management used by the admin pages. Throws StorageException with friendly messages. */
final class Users
{
    public const NO_PASSWORD = '!';   // invited accounts that haven't chosen a password yet

    public static function all(): array
    {
        return Database::all('SELECT * FROM bla_users ORDER BY is_admin DESC, LOWER(username)');
    }

    public static function find(int $id): ?array
    {
        return Database::one('SELECT * FROM bla_users WHERE id = ?', [$id]);
    }

    public static function status(array $u): string
    {
        if (!(int) $u['is_active']) {
            return 'disabled';
        }
        return $u['password_hash'] === self::NO_PASSWORD ? 'invited' : 'active';
    }

    public static function validateUsername(string $username): void
    {
        if (!preg_match('/^[A-Za-z0-9._-]{3,32}$/', $username)) {
            throw new StorageException('Username: 3–32 characters, using letters, numbers, dots, dashes or underscores.');
        }
        if (Database::one('SELECT id FROM bla_users WHERE LOWER(username) = LOWER(?)', [$username])) {
            throw new StorageException('That username is already taken.');
        }
    }

    public static function validateEmail(string $email, ?int $exceptId = null): void
    {
        if ($email === '') {
            return;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new StorageException('Please enter a valid email address.');
        }
        $other = Database::one('SELECT id FROM bla_users WHERE LOWER(email) = LOWER(?)', [$email]);
        if ($other && (int) $other['id'] !== $exceptId) {
            throw new StorageException('Another account already uses that email address.');
        }
    }

    /** Create an account. With $password = null the user gets an invitation instead. */
    public static function create(string $username, string $display, string $email, bool $admin,
                                  int $quotaBytes, ?string $password): int
    {
        $username = trim($username);
        $email = trim($email);
        self::validateUsername($username);
        self::validateEmail($email);
        if ($password !== null && ($p = Security::passwordProblem($password, $username))) {
            throw new StorageException($p);
        }
        $id = Database::insert('INSERT INTO bla_users (username, display_name, email, password_hash, is_admin, quota_bytes, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?)', [
            $username, mb_substr(trim($display), 0, 128) ?: $username, $email,
            $password === null ? self::NO_PASSWORD : Security::hashPassword($password),
            $admin ? 1 : 0, max(0, $quotaBytes), Database::now(),
        ]);
        Dav\Provisioning::seedDefaults($id);
        return $id;
    }

    public static function activeAdminCount(): int
    {
        return (int) Database::one('SELECT COUNT(*) AS n FROM bla_users WHERE is_admin = 1 AND is_active = 1')['n'];
    }

    /** Guard rails so the last administrator can't lock everyone out. */
    public static function assertNotLastAdmin(array $u, string $what): void
    {
        if ((int) $u['is_admin'] === 1 && (int) $u['is_active'] === 1 && self::activeAdminCount() <= 1) {
            throw new StorageException("You can't $what the only administrator. Make someone else an administrator first.");
        }
    }

    public static function update(array $u, array $in, int $actingId): void
    {
        $email = trim((string) ($in['email'] ?? $u['email']));
        self::validateEmail($email, (int) $u['id']);
        $admin = !empty($in['is_admin']);
        $active = !empty($in['is_active']);
        if ((int) $u['id'] === $actingId && (!$admin || !$active)) {
            throw new StorageException("You can't remove your own administrator rights or disable your own account.");
        }
        if (!$admin && (int) $u['is_admin'] === 1) {
            self::assertNotLastAdmin($u, 'demote');
        }
        if (!$active && (int) $u['is_active'] === 1) {
            self::assertNotLastAdmin($u, 'disable');
        }
        Database::run('UPDATE bla_users SET display_name = ?, email = ?, is_admin = ?, is_active = ?, quota_bytes = ? WHERE id = ?', [
            mb_substr(trim((string) ($in['display_name'] ?? '')), 0, 128) ?: $u['username'],
            $email, $admin ? 1 : 0, $active ? 1 : 0, max(0, (int) ($in['quota_bytes'] ?? 0)), $u['id'],
        ]);
    }

    public static function resetTwoFactor(array $u): void
    {
        Database::run('UPDATE bla_users SET totp_enabled = 0, totp_secret = NULL, totp_last_step = 0 WHERE id = ?', [$u['id']]);
        Database::run('DELETE FROM bla_recovery_codes WHERE user_id = ?', [$u['id']]);
    }

    /** Delete an account and ALL of its files, trash, versions and shares. */
    public static function delete(array $u, int $actingId): void
    {
        if ((int) $u['id'] === $actingId) {
            throw new StorageException("You can't delete your own account.");
        }
        self::assertNotLastAdmin($u, 'delete');
        $dir = rtrim((string) Config::get('data_dir'), '/') . '/users/' . (int) $u['id'];
        Database::run('DELETE FROM bla_shares WHERE owner_id = ? OR recipient_id = ?', [$u['id'], $u['id']]);
        foreach (['bla_versions', 'bla_trash', 'bla_tokens', 'bla_recovery_codes',
                  'bla_app_passwords', 'bla_dav_locks', 'bla_calendars', 'bla_addressbooks'] as $t) {
            Database::run("DELETE FROM $t WHERE user_id = ?", [$u['id']]);
        }
        Database::run('DELETE FROM bla_users WHERE id = ?', [$u['id']]);
        if (is_dir($dir) && preg_match('~/users/\d+$~', $dir)) {
            Storage::removeTree($dir);
        }
    }

    /** Files-folder size (cheap enough for small teams; cached per request). */
    public static function usage(int $id): int
    {
        static $cache = [];
        if (!isset($cache[$id])) {
            $dir = rtrim((string) Config::get('data_dir'), '/') . '/users/' . $id . '/files';
            $cache[$id] = Storage::dirSize($dir);
        }
        return $cache[$id];
    }

    /** Send (or create) an invitation. Returns [link, emailed?, error]. */
    public static function invite(array $u, array $by, bool $email = true): array
    {
        $token = Tokens::create((int) $u['id'], 'invite', Tokens::INVITE_HOURS);
        $link = Settings::absoluteUrl('invite', ['t' => $token]);
        $mailed = false;
        $err = null;
        if ($email && $u['email'] !== '' && Mailer::enabled()) {
            $inviter = $by['display_name'] ?: $by['username'];
            $name = (string) Config::get('instance_name', 'BLA-Cloud');
            $err = Mailer::send($u['email'], "You're invited to $name", "Welcome to $name", [
                "Hi {$u['display_name']},",
                "$inviter has created an account for you on $name — a private cloud for your files. Your username is: {$u['username']}",
                'Choose your password using the button below. The invitation is valid for 7 days.',
            ], 'Set up my account', $link);
            $mailed = $err === null;
        }
        return [$link, $mailed, $err];
    }

    public static function sendReset(array $u, bool $email = true): array
    {
        $token = Tokens::create((int) $u['id'], 'reset', Tokens::RESET_HOURS);
        $link = Settings::absoluteUrl('reset', ['t' => $token]);
        $mailed = false;
        $err = null;
        if ($email && $u['email'] !== '' && Mailer::enabled()) {
            $name = (string) Config::get('instance_name', 'BLA-Cloud');
            $err = Mailer::send($u['email'], "Reset your $name password", 'Reset your password', [
                "Hi {$u['display_name']},",
                "Someone (hopefully you) asked to reset the password for {$u['username']} on $name.",
                'The link below works once and expires in 1 hour. If you didn\'t ask for this, you can ignore this email — your password stays the same.',
            ], 'Choose a new password', $link);
            $mailed = $err === null;
        }
        return [$link, $mailed, $err];
    }
}
