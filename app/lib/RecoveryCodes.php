<?php
declare(strict_types=1);

namespace BlaCloud;

/** One-time backup codes for when the authenticator app is lost. Stored as keyed hashes only. */
final class RecoveryCodes
{
    private const ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789'; // no look-alikes (0/o, 1/l/i)

    private static function hash(string $code): string
    {
        $norm = strtolower(preg_replace('/[^a-z0-9]/i', '', $code) ?? '');
        return hash_hmac('sha256', $norm, (string) Config::get('app_key'));
    }

    /** Replace all codes; returns the new plain codes (shown to the user exactly once). */
    public static function regenerate(int $userId, int $count = 10): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $c = '';
            for ($j = 0; $j < 10; $j++) {
                $c .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            $codes[] = substr($c, 0, 5) . '-' . substr($c, 5);
        }
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        Database::run('DELETE FROM bla_recovery_codes WHERE user_id = ?', [$userId]);
        foreach ($codes as $c) {
            Database::run('INSERT INTO bla_recovery_codes (user_id, code_hash) VALUES (?, ?)', [$userId, self::hash($c)]);
        }
        $pdo->commit();
        Audit::log($userId, '2fa.recovery_codes_generated');
        return $codes;
    }

    public static function consume(int $userId, string $code): bool
    {
        $row = Database::one('SELECT id FROM bla_recovery_codes WHERE user_id = ? AND code_hash = ? AND used_at IS NULL',
            [$userId, self::hash($code)]);
        if (!$row) {
            return false;
        }
        $st = Database::run('UPDATE bla_recovery_codes SET used_at = ? WHERE id = ? AND used_at IS NULL', [Database::now(), $row['id']]);
        return $st->rowCount() === 1;
    }

    public static function remaining(int $userId): int
    {
        return (int) Database::one('SELECT COUNT(*) AS n FROM bla_recovery_codes WHERE user_id = ? AND used_at IS NULL', [$userId])['n'];
    }
}
