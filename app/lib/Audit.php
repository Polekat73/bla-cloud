<?php
declare(strict_types=1);

namespace BlaCloud;

final class Audit
{
    public static function log(?int $userId, string $action, string $detail = ''): void
    {
        try {
            Database::run(
                'INSERT INTO bla_audit_log (user_id, action, detail, ip, created_at) VALUES (?, ?, ?, ?, ?)',
                [$userId, substr($action, 0, 64), mb_substr($detail, 0, 500), Request::clientIp(), Database::now()]
            );
        } catch (\Throwable $e) {
            error_log('[BLA-Cloud] audit log failed: ' . $e->getMessage());
        }
    }

    public static function recent(int $limit = 50, ?int $userId = null): array
    {
        $limit = max(1, min(500, $limit));
        if ($userId !== null) {
            return Database::all(
                "SELECT a.*, u.username FROM bla_audit_log a LEFT JOIN bla_users u ON u.id = a.user_id
                 WHERE a.user_id = ? ORDER BY a.id DESC LIMIT $limit", [$userId]);
        }
        return Database::all(
            "SELECT a.*, u.username FROM bla_audit_log a LEFT JOIN bla_users u ON u.id = a.user_id
             ORDER BY a.id DESC LIMIT $limit");
    }
}
