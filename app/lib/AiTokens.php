<?php
declare(strict_types=1);

namespace BlaCloud;

/**
 * Per-user tokens for AI/MCP access (see McpController and app/lib/Mcp/Tools.php). Each token gives
 * an AI agent full read/write access to that ONE person's own data (files, calendar, contacts,
 * projects) — never another user's data, and never admin functions. Revocable at any time, same
 * spirit as app passwords, but the transport is a Bearer token (JSON-RPC over HTTP), not HTTP Basic.
 *
 * Unlike a password, a token here is already 256 bits of random entropy — the security comes from
 * that entropy, not from a slow hash, so it's looked up by a fast, indexed SHA-256 digest instead of
 * a per-row password_verify() loop. This is the same trade-off GitHub/Stripe-style API tokens make.
 */
final class AiTokens
{
    private const PREFIX = 'bla_ai_';

    public static function forUser(int $userId): array
    {
        return Database::all('SELECT id, label, created_at, last_used_at, last_used_ip FROM bla_ai_tokens
            WHERE user_id = ? ORDER BY id DESC', [$userId]);
    }

    /** Returns [id, token] — the token is shown once and never stored in the clear. */
    public static function create(int $userId, string $label): array
    {
        $label = mb_substr(trim($label), 0, 128) ?: 'Unnamed AI integration';
        $token = self::PREFIX . bin2hex(random_bytes(32));
        $id = Database::insert('INSERT INTO bla_ai_tokens (user_id, label, token_hash, created_at) VALUES (?, ?, ?, ?)',
            [$userId, $label, self::hash($token), Database::now()]);
        Audit::log($userId, 'aitoken.created', $label);
        return [$id, $token];
    }

    public static function revoke(int $userId, int $id): void
    {
        $row = Database::one('SELECT label FROM bla_ai_tokens WHERE id = ? AND user_id = ?', [$id, $userId]);
        if (!$row) {
            return;
        }
        Database::run('DELETE FROM bla_ai_tokens WHERE id = ? AND user_id = ?', [$id, $userId]);
        Audit::log($userId, 'aitoken.revoked', $row['label']);
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Verify a Bearer token from the Authorization header. Returns the user row, or null. */
    public static function verify(string $token): ?array
    {
        if (!str_starts_with($token, self::PREFIX)) {
            return null;
        }
        $row = Database::one('SELECT * FROM bla_ai_tokens WHERE token_hash = ?', [self::hash($token)]);
        if (!$row) {
            return null;
        }
        $u = Database::one('SELECT * FROM bla_users WHERE id = ? AND is_active = 1', [$row['user_id']]);
        if (!$u) {
            return null;
        }
        Database::run('UPDATE bla_ai_tokens SET last_used_at = ?, last_used_ip = ? WHERE id = ?',
            [Database::now(), Request::clientIp(), $row['id']]);
        return $u;
    }
}
