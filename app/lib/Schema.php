<?php
declare(strict_types=1);

namespace BlaCloud;

use PDO;

/**
 * Database tables, built up by numbered migrations. A fresh install runs them all;
 * an existing install runs only the ones it hasn't seen yet (automatically, on the next page load).
 */
final class Schema
{
    public const VERSION = 3;

    public static function create(PDO $pdo, string $driver): void
    {
        self::migrate($pdo, $driver, 0);
    }

    /** Run every migration newer than $from. */
    public static function migrate(PDO $pdo, string $driver, int $from): void
    {
        $mysql = $driver === 'mysql';
        $t = [
            'id'   => $mysql ? 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT',
            'uid'  => $mysql ? 'INT UNSIGNED' : 'INTEGER',
            'tail' => $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '',
            'idx'  => $mysql ? 'CREATE INDEX ' : 'CREATE INDEX IF NOT EXISTS ',
            // Long paths: MySQL can't index full TEXT, so we index a prefix there.
            'path' => $mysql ? 'VARCHAR(3000) COLLATE utf8mb4_bin' : 'TEXT',
            'pidx' => $mysql ? '(user_id, path(191))' : '(user_id, path)',
            'pidx_owner' => $mysql ? '(owner_id, path(191))' : '(owner_id, path)',
        ];
        for ($v = $from + 1; $v <= self::VERSION; $v++) {
            foreach (self::migration($v, $t) as $sql) {
                $pdo->exec($sql);
            }
            self::setVersion($pdo, $mysql, $v);
        }
    }

    private static function migration(int $v, array $t): array
    {
        ['id' => $id, 'uid' => $uid, 'tail' => $tail, 'idx' => $idx, 'path' => $path, 'pidx' => $pidx, 'pidx_owner' => $pidx_owner] = $t;
        return match ($v) {
            1 => [
                "CREATE TABLE IF NOT EXISTS bla_users (
                    id $id,
                    username VARCHAR(64) NOT NULL UNIQUE,
                    display_name VARCHAR(128) NOT NULL DEFAULT '',
                    email VARCHAR(255) NOT NULL DEFAULT '',
                    password_hash VARCHAR(255) NOT NULL,
                    is_admin TINYINT NOT NULL DEFAULT 0,
                    is_active TINYINT NOT NULL DEFAULT 1,
                    totp_secret TEXT NULL,
                    totp_enabled TINYINT NOT NULL DEFAULT 0,
                    totp_last_step BIGINT NOT NULL DEFAULT 0,
                    quota_bytes BIGINT NOT NULL DEFAULT 0,
                    created_at DATETIME NOT NULL,
                    last_login_at DATETIME NULL
                )$tail",
                "CREATE TABLE IF NOT EXISTS bla_recovery_codes (
                    id $id,
                    user_id $uid NOT NULL,
                    code_hash VARCHAR(255) NOT NULL,
                    used_at DATETIME NULL,
                    FOREIGN KEY (user_id) REFERENCES bla_users(id) ON DELETE CASCADE
                )$tail",
                "CREATE TABLE IF NOT EXISTS bla_login_attempts (
                    id $id,
                    ip VARCHAR(45) NOT NULL,
                    username VARCHAR(64) NOT NULL DEFAULT '',
                    kind VARCHAR(16) NOT NULL DEFAULT 'password',
                    success TINYINT NOT NULL DEFAULT 0,
                    created_at DATETIME NOT NULL
                )$tail",
                "CREATE TABLE IF NOT EXISTS bla_audit_log (
                    id $id,
                    user_id $uid NULL,
                    action VARCHAR(64) NOT NULL,
                    detail VARCHAR(512) NOT NULL DEFAULT '',
                    ip VARCHAR(45) NOT NULL DEFAULT '',
                    created_at DATETIME NOT NULL
                )$tail",
                "CREATE TABLE IF NOT EXISTS bla_meta (
                    meta_key VARCHAR(64) NOT NULL PRIMARY KEY,
                    meta_value TEXT NOT NULL
                )$tail",
                $idx . 'idx_attempts_ip ON bla_login_attempts (ip, created_at)',
                $idx . 'idx_attempts_user ON bla_login_attempts (username, created_at)',
                $idx . 'idx_audit_time ON bla_audit_log (created_at)',
            ],
            2 => [
                // Deleted items waiting in the trash bin.
                "CREATE TABLE IF NOT EXISTS bla_trash (
                    id $id,
                    user_id $uid NOT NULL,
                    trash_key VARCHAR(40) NOT NULL,
                    original_path $path NOT NULL,
                    name VARCHAR(255) NOT NULL,
                    is_dir TINYINT NOT NULL DEFAULT 0,
                    size BIGINT NOT NULL DEFAULT 0,
                    deleted_at DATETIME NOT NULL,
                    FOREIGN KEY (user_id) REFERENCES bla_users(id) ON DELETE CASCADE
                )$tail",
                $idx . 'idx_trash_user ON bla_trash (user_id, deleted_at)',
                // Older copies of files. trash_id is set while the file itself sits in the trash.
                "CREATE TABLE IF NOT EXISTS bla_versions (
                    id $id,
                    user_id $uid NOT NULL,
                    path $path NOT NULL,
                    blob_name VARCHAR(64) NOT NULL,
                    size BIGINT NOT NULL DEFAULT 0,
                    file_mtime BIGINT NOT NULL DEFAULT 0,
                    created_at DATETIME NOT NULL,
                    trash_id $uid NULL,
                    FOREIGN KEY (user_id) REFERENCES bla_users(id) ON DELETE CASCADE
                )$tail",
                $idx . "idx_versions_path ON bla_versions $pidx",
            ],
            3 => [
                // One-time links: invitations and password resets. Only a hash of the token is stored.
                "CREATE TABLE IF NOT EXISTS bla_tokens (
                    id $id,
                    user_id $uid NOT NULL,
                    kind VARCHAR(16) NOT NULL,
                    token_hash VARCHAR(64) NOT NULL UNIQUE,
                    expires_at DATETIME NOT NULL,
                    used_at DATETIME NULL,
                    created_at DATETIME NOT NULL,
                    FOREIGN KEY (user_id) REFERENCES bla_users(id) ON DELETE CASCADE
                )$tail",
                // Shares with other users (share_type = user) and public links (share_type = link).
                "CREATE TABLE IF NOT EXISTS bla_shares (
                    id $id,
                    owner_id $uid NOT NULL,
                    path $path NOT NULL,
                    is_dir TINYINT NOT NULL DEFAULT 0,
                    share_type VARCHAR(8) NOT NULL,
                    recipient_id $uid NULL,
                    perms VARCHAR(16) NOT NULL DEFAULT 'view',
                    token_hash VARCHAR(64) NULL UNIQUE,
                    token_enc TEXT NULL,
                    password_hash VARCHAR(255) NULL,
                    expires_at DATETIME NULL,
                    label VARCHAR(128) NOT NULL DEFAULT '',
                    access_count INT NOT NULL DEFAULT 0,
                    last_access_at DATETIME NULL,
                    created_at DATETIME NOT NULL,
                    FOREIGN KEY (owner_id) REFERENCES bla_users(id) ON DELETE CASCADE,
                    FOREIGN KEY (recipient_id) REFERENCES bla_users(id) ON DELETE CASCADE
                )$tail",
                $idx . "idx_shares_owner ON bla_shares $pidx_owner",
                $idx . 'idx_shares_recipient ON bla_shares (recipient_id)',
            ],
            default => [],
        };
    }

    private static function setVersion(PDO $pdo, bool $mysql, int $v): void
    {
        $st = $pdo->prepare($mysql
            ? 'REPLACE INTO bla_meta (meta_key, meta_value) VALUES (?, ?)'
            : 'INSERT OR REPLACE INTO bla_meta (meta_key, meta_value) VALUES (?, ?)');
        $st->execute(['schema_version', (string) $v]);
    }

    public static function currentVersion(PDO $pdo): int
    {
        try {
            $row = $pdo->query("SELECT meta_value FROM bla_meta WHERE meta_key = 'schema_version'")->fetch();
            return $row ? (int) $row['meta_value'] : 0;
        } catch (\PDOException) {
            return 0;
        }
    }

    /** Called on every request: cheap check, upgrades the database when new code was uploaded. */
    public static function ensureUpToDate(): void
    {
        if ((int) Config::get('schema_version', 1) >= self::VERSION && Config::get('version') === BLA_VERSION) {
            return;
        }
        $pdo = Database::pdo();
        $current = self::currentVersion($pdo);
        if ($current < self::VERSION) {
            $lock = rtrim((string) Config::get('data_dir'), '/') . '/.migrate.lock';
            $fh = @fopen($lock, 'c');
            if ($fh) {
                flock($fh, LOCK_EX);
            }
            try {
                $current = self::currentVersion($pdo); // re-check inside the lock
                if ($current < self::VERSION) {
                    self::migrate($pdo, Database::driver(), $current);
                    Audit::log(null, 'upgrade.database', "schema $current -> " . self::VERSION);
                }
            } finally {
                if ($fh) {
                    flock($fh, LOCK_UN);
                    fclose($fh);
                }
            }
        }
        // Remember it so we skip the check next time (fine if config is read-only).
        try {
            $cfg = Config::all();
            $old = (string) ($cfg['version'] ?? '?');
            $cfg['schema_version'] = self::VERSION;
            $cfg['version'] = BLA_VERSION;
            if (is_writable(Config::path())) {
                Config::write($cfg);
                if ($old !== BLA_VERSION) {
                    Audit::log(null, 'upgrade.code', "$old -> " . BLA_VERSION);
                }
            }
        } catch (\Throwable $e) {
            error_log('[BLA-Cloud] could not record schema version: ' . $e->getMessage());
        }
    }

    /** True if our tables already exist in this database (protects against overwriting an old install). */
    public static function exists(PDO $pdo): bool
    {
        try {
            $pdo->query('SELECT 1 FROM bla_users LIMIT 1');
            return true;
        } catch (\PDOException) {
            return false;
        }
    }
}
