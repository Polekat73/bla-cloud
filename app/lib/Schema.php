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
    public const VERSION = 7;

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
            if ($v === 5) {
                self::backfillEventFields($pdo);
            }
        }
    }

    /** v5 adds denormalised columns (start/end/reminder time, contact display name) read from the
     *  raw iCalendar/vCard text — populate them for any objects that synced in before the upgrade. */
    private static function backfillEventFields(\PDO $pdo): void
    {
        $upd = $pdo->prepare('UPDATE bla_calendar_objects SET start_at = ?, end_at = ?, all_day = ?, remind_at = ? WHERE id = ?');
        foreach ($pdo->query('SELECT id, data FROM bla_calendar_objects WHERE start_at IS NULL')->fetchAll() as $row) {
            $e = Dav\Ical::parseEvent((string) $row['data']);
            if (!$e) {
                continue;
            }
            $upd->execute([
                $e['start']->format('Y-m-d H:i:s'), $e['end']->format('Y-m-d H:i:s'),
                $e['allDay'] ? 1 : 0, $e['remindAt']?->format('Y-m-d H:i:s'), $row['id'],
            ]);
        }
        $updC = $pdo->prepare('UPDATE bla_contacts SET fn = ? WHERE id = ?');
        foreach ($pdo->query("SELECT id, data FROM bla_contacts WHERE fn = ''")->fetchAll() as $row) {
            $c = Dav\Vcard::parseContact((string) $row['data']);
            if ($c['fn'] !== '') {
                $updC->execute([$c['fn'], $row['id']]);
            }
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
            4 => [
                // Per-device passwords for WebDAV/CalDAV/CardDAV (they can't do 2FA prompts).
                "CREATE TABLE IF NOT EXISTS bla_app_passwords (
                    id $id,
                    user_id $uid NOT NULL,
                    label VARCHAR(128) NOT NULL DEFAULT '',
                    password_hash VARCHAR(255) NOT NULL,
                    created_at DATETIME NOT NULL,
                    last_used_at DATETIME NULL,
                    last_used_ip VARCHAR(45) NOT NULL DEFAULT '',
                    FOREIGN KEY (user_id) REFERENCES bla_users(id) ON DELETE CASCADE
                )$tail",
                $idx . 'idx_app_passwords_user ON bla_app_passwords (user_id)',
                // Short-lived WebDAV write locks (LOCK/UNLOCK), mainly so Windows/macOS allow saving files.
                "CREATE TABLE IF NOT EXISTS bla_dav_locks (
                    id $id,
                    user_id $uid NOT NULL,
                    path $path NOT NULL,
                    token VARCHAR(64) NOT NULL UNIQUE,
                    owner VARCHAR(255) NOT NULL DEFAULT '',
                    depth VARCHAR(8) NOT NULL DEFAULT '0',
                    expires_at DATETIME NOT NULL,
                    created_at DATETIME NOT NULL,
                    FOREIGN KEY (user_id) REFERENCES bla_users(id) ON DELETE CASCADE
                )$tail",
                $idx . "idx_dav_locks_path ON bla_dav_locks $pidx",
                // Calendars (CalDAV) and their events/todos, stored as raw iCalendar text.
                "CREATE TABLE IF NOT EXISTS bla_calendars (
                    id $id,
                    user_id $uid NOT NULL,
                    uri VARCHAR(64) NOT NULL,
                    display_name VARCHAR(128) NOT NULL DEFAULT '',
                    color VARCHAR(7) NOT NULL DEFAULT '#c9a227',
                    ctag INT NOT NULL DEFAULT 1,
                    created_at DATETIME NOT NULL,
                    FOREIGN KEY (user_id) REFERENCES bla_users(id) ON DELETE CASCADE,
                    UNIQUE (user_id, uri)
                )$tail",
                "CREATE TABLE IF NOT EXISTS bla_calendar_objects (
                    id $id,
                    calendar_id $uid NOT NULL,
                    uri VARCHAR(255) NOT NULL,
                    uid VARCHAR(255) NOT NULL DEFAULT '',
                    etag VARCHAR(64) NOT NULL,
                    data TEXT NOT NULL,
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    FOREIGN KEY (calendar_id) REFERENCES bla_calendars(id) ON DELETE CASCADE,
                    UNIQUE (calendar_id, uri)
                )$tail",
                $idx . 'idx_calendar_objects_cal ON bla_calendar_objects (calendar_id)',
                // Address books (CardDAV) and their contacts, stored as raw vCard text.
                "CREATE TABLE IF NOT EXISTS bla_addressbooks (
                    id $id,
                    user_id $uid NOT NULL,
                    uri VARCHAR(64) NOT NULL,
                    display_name VARCHAR(128) NOT NULL DEFAULT '',
                    ctag INT NOT NULL DEFAULT 1,
                    created_at DATETIME NOT NULL,
                    FOREIGN KEY (user_id) REFERENCES bla_users(id) ON DELETE CASCADE,
                    UNIQUE (user_id, uri)
                )$tail",
                "CREATE TABLE IF NOT EXISTS bla_contacts (
                    id $id,
                    addressbook_id $uid NOT NULL,
                    uri VARCHAR(255) NOT NULL,
                    uid VARCHAR(255) NOT NULL DEFAULT '',
                    etag VARCHAR(64) NOT NULL,
                    data TEXT NOT NULL,
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    FOREIGN KEY (addressbook_id) REFERENCES bla_addressbooks(id) ON DELETE CASCADE,
                    UNIQUE (addressbook_id, uri)
                )$tail",
                $idx . 'idx_contacts_book ON bla_contacts (addressbook_id)',
                // Give every existing account its default calendar & address book (new ones get this at creation time).
                "INSERT INTO bla_calendars (user_id, uri, display_name, color, ctag, created_at)
                 SELECT id, 'personal', 'Personal', '#c9a227', 1, '" . gmdate('Y-m-d H:i:s') . "' FROM bla_users u
                 WHERE NOT EXISTS (SELECT 1 FROM bla_calendars c WHERE c.user_id = u.id AND c.uri = 'personal')",
                "INSERT INTO bla_addressbooks (user_id, uri, display_name, ctag, created_at)
                 SELECT id, 'contacts', 'Contacts', 1, '" . gmdate('Y-m-d H:i:s') . "' FROM bla_users u
                 WHERE NOT EXISTS (SELECT 1 FROM bla_addressbooks a WHERE a.user_id = u.id AND a.uri = 'contacts')",
            ],
            5 => [
                // Denormalised from the raw iCalendar text, so the calendar app can query a date
                // range (and due reminders) without re-parsing every event on every page load.
                "ALTER TABLE bla_calendar_objects ADD COLUMN start_at DATETIME NULL",
                "ALTER TABLE bla_calendar_objects ADD COLUMN end_at DATETIME NULL",
                "ALTER TABLE bla_calendar_objects ADD COLUMN all_day TINYINT NOT NULL DEFAULT 0",
                "ALTER TABLE bla_calendar_objects ADD COLUMN remind_at DATETIME NULL",
                "ALTER TABLE bla_calendar_objects ADD COLUMN reminder_sent_at DATETIME NULL",
                $idx . 'idx_calendar_objects_range ON bla_calendar_objects (calendar_id, start_at, end_at)',
                $idx . 'idx_calendar_objects_remind ON bla_calendar_objects (remind_at, reminder_sent_at)',
                // Denormalised from the raw vCard text, so the contacts list can sort/search without parsing.
                "ALTER TABLE bla_contacts ADD COLUMN fn VARCHAR(255) NOT NULL DEFAULT ''",
                $idx . 'idx_contacts_fn ON bla_contacts (addressbook_id, fn)',
            ],
            6 => [
                // Encrypted backup archives (see app/lib/Backup.php). The passphrase and settings live
                // in bla_meta's 'settings' blob alongside everything else Settings.php manages.
                "CREATE TABLE IF NOT EXISTS bla_backups (
                    id $id,
                    filename VARCHAR(255) NOT NULL DEFAULT '',
                    kind VARCHAR(16) NOT NULL DEFAULT 'manual',
                    size_bytes BIGINT NOT NULL DEFAULT 0,
                    status VARCHAR(16) NOT NULL DEFAULT 'ok',
                    error VARCHAR(512) NOT NULL DEFAULT '',
                    created_at DATETIME NOT NULL
                )$tail",
                $idx . 'idx_backups_created ON bla_backups (created_at)',
            ],
            7 => [
                // Project management app (app/apps/projects) — projects, their membership, Kanban
                // columns, tasks and comments. Any member can manage columns/tasks/comments; only the
                // owner can rename/delete the project or add/remove members (see ProjectsData.php).
                "CREATE TABLE IF NOT EXISTS bla_projects (
                    id $id,
                    owner_id $uid NOT NULL,
                    name VARCHAR(128) NOT NULL,
                    description TEXT NOT NULL DEFAULT '',
                    created_at DATETIME NOT NULL,
                    FOREIGN KEY (owner_id) REFERENCES bla_users(id) ON DELETE CASCADE
                )$tail",
                $idx . 'idx_projects_owner ON bla_projects (owner_id)',
                "CREATE TABLE IF NOT EXISTS bla_project_members (
                    id $id,
                    project_id $uid NOT NULL,
                    user_id $uid NOT NULL,
                    added_at DATETIME NOT NULL,
                    FOREIGN KEY (project_id) REFERENCES bla_projects(id) ON DELETE CASCADE,
                    FOREIGN KEY (user_id) REFERENCES bla_users(id) ON DELETE CASCADE,
                    UNIQUE (project_id, user_id)
                )$tail",
                $idx . 'idx_project_members_user ON bla_project_members (user_id)',
                "CREATE TABLE IF NOT EXISTS bla_project_columns (
                    id $id,
                    project_id $uid NOT NULL,
                    name VARCHAR(64) NOT NULL,
                    position INT NOT NULL DEFAULT 0,
                    created_at DATETIME NOT NULL,
                    FOREIGN KEY (project_id) REFERENCES bla_projects(id) ON DELETE CASCADE
                )$tail",
                $idx . 'idx_project_columns_project ON bla_project_columns (project_id, position)',
                "CREATE TABLE IF NOT EXISTS bla_project_tasks (
                    id $id,
                    project_id $uid NOT NULL,
                    column_id $uid NOT NULL,
                    title VARCHAR(255) NOT NULL,
                    description TEXT NOT NULL DEFAULT '',
                    assignee_id $uid NULL,
                    due_at DATE NULL,
                    position INT NOT NULL DEFAULT 0,
                    created_by $uid NOT NULL,
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    FOREIGN KEY (project_id) REFERENCES bla_projects(id) ON DELETE CASCADE,
                    FOREIGN KEY (column_id) REFERENCES bla_project_columns(id) ON DELETE CASCADE,
                    FOREIGN KEY (assignee_id) REFERENCES bla_users(id) ON DELETE SET NULL
                )$tail",
                $idx . 'idx_project_tasks_column ON bla_project_tasks (column_id, position)',
                $idx . 'idx_project_tasks_assignee ON bla_project_tasks (assignee_id)',
                "CREATE TABLE IF NOT EXISTS bla_project_comments (
                    id $id,
                    task_id $uid NOT NULL,
                    user_id $uid NOT NULL,
                    body TEXT NOT NULL,
                    created_at DATETIME NOT NULL,
                    FOREIGN KEY (task_id) REFERENCES bla_project_tasks(id) ON DELETE CASCADE,
                    FOREIGN KEY (user_id) REFERENCES bla_users(id) ON DELETE CASCADE
                )$tail",
                $idx . 'idx_project_comments_task ON bla_project_comments (task_id)',
                // Per-user AI access tokens (Bearer auth for /mcp — see app/lib/AiTokens.php). High-entropy
                // random tokens, so a fast indexed hash lookup is appropriate (unlike a human-chosen
                // password, which needs slow hashing); see AiTokens::verify().
                "CREATE TABLE IF NOT EXISTS bla_ai_tokens (
                    id $id,
                    user_id $uid NOT NULL,
                    label VARCHAR(128) NOT NULL DEFAULT '',
                    token_hash VARCHAR(64) NOT NULL UNIQUE,
                    created_at DATETIME NOT NULL,
                    last_used_at DATETIME NULL,
                    last_used_ip VARCHAR(45) NOT NULL DEFAULT '',
                    FOREIGN KEY (user_id) REFERENCES bla_users(id) ON DELETE CASCADE
                )$tail",
                $idx . 'idx_ai_tokens_user ON bla_ai_tokens (user_id)',
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
