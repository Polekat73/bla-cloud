<?php
declare(strict_types=1);

namespace BlaCloud;

/** Single-use links (invitations, password resets). The database only ever stores a hash. */
final class Tokens
{
    public const INVITE_HOURS = 24 * 7;
    public const RESET_HOURS  = 1;

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Create a token for a user; any older unused token of the same kind is cancelled. */
    public static function create(int $userId, string $kind, int $hours): string
    {
        Database::run('UPDATE bla_tokens SET used_at = ? WHERE user_id = ? AND kind = ? AND used_at IS NULL',
            [Database::now(), $userId, $kind]);
        $token = Security::token(32);
        Database::run('INSERT INTO bla_tokens (user_id, kind, token_hash, expires_at, created_at) VALUES (?, ?, ?, ?, ?)',
            [$userId, $kind, self::hash($token), gmdate('Y-m-d H:i:s', time() + $hours * 3600), Database::now()]);
        return $token;
    }

    /** The valid (unused, unexpired) token row with its user, or null. */
    public static function find(string $token, string $kind): ?array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{20,100}$/', $token)) {
            return null;
        }
        $row = Database::one('SELECT t.*, u.username, u.display_name, u.email, u.is_active, u.is_admin
            FROM bla_tokens t JOIN bla_users u ON u.id = t.user_id
            WHERE t.token_hash = ? AND t.kind = ? AND t.used_at IS NULL AND t.expires_at > ?',
            [self::hash($token), $kind, Database::now()]);
        return ($row && (int) $row['is_active'] === 1) ? $row : null;
    }

    public static function consume(int $id): bool
    {
        return Database::run('UPDATE bla_tokens SET used_at = ? WHERE id = ? AND used_at IS NULL', [Database::now(), $id])->rowCount() === 1;
    }

    public static function pendingInvite(int $userId): ?array
    {
        return Database::one("SELECT * FROM bla_tokens WHERE user_id = ? AND kind = 'invite' AND used_at IS NULL AND expires_at > ?
            ORDER BY id DESC LIMIT 1", [$userId, Database::now()]);
    }
}
