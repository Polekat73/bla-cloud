<?php
declare(strict_types=1);

namespace BlaCloud;

/**
 * Encrypted backups of the database and everyone's files, on a schedule (or on demand).
 *
 * A backup is a zip (database dump + config.php + everyone's files/trash/versions) encrypted
 * with libsodium's secretstream, under a key derived from a separate passphrase the admin sets
 * and keeps somewhere safe — not the account password, not the app's own encryption key, so a
 * backup file is still restorable even if the server itself (and config.php) is lost.
 */
final class Backup
{
    private const MAGIC = "BLABKUP1";
    private const CHUNK = 1024 * 1024; // 1 MB plaintext chunks while encrypting/decrypting

    // ---------- Enable / configure ----------

    public static function enabled(): bool
    {
        return (bool) Settings::get('backup_enabled') && Settings::get('backup_passphrase_enc') !== '';
    }

    public static function dir(): string
    {
        return rtrim((string) Settings::get('backup_dir'), '/');
    }

    /** Is the backup folder inside the data folder? If so, one lost folder loses both. */
    public static function dirInsideDataDir(): bool
    {
        $dir = @realpath(self::dir());
        $data = @realpath((string) Config::get('data_dir'));
        return $dir !== false && $data !== false && ($dir === $data || str_starts_with($dir, $data . DIRECTORY_SEPARATOR));
    }

    public static function enable(string $dir, string $frequency, int $retention, string $passphrase): void
    {
        $dir = rtrim(trim($dir), '/\\');
        if ($dir === '') {
            throw new StorageException('Please enter a folder for backups to be saved in.');
        }
        if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
            throw new StorageException('Could not create that folder. Check the path and permissions.');
        }
        if (!is_writable($dir)) {
            throw new StorageException('That folder is not writable by the web server.');
        }
        if ($problem = Security::passwordProblem($passphrase)) {
            throw new StorageException($problem);
        }
        Settings::save([
            'backup_enabled'   => true,
            'backup_dir'       => $dir,
            'backup_frequency' => $frequency === 'weekly' ? 'weekly' : 'daily',
            'backup_retention' => max(1, min(60, $retention)),
            'backup_passphrase_enc' => Security::encrypt($passphrase),
        ]);
    }

    public static function disable(): void
    {
        Settings::save(['backup_enabled' => false]);
    }

    public static function rotatePassphrase(string $passphrase): void
    {
        if ($problem = Security::passwordProblem($passphrase)) {
            throw new StorageException($problem);
        }
        Settings::save(['backup_passphrase_enc' => Security::encrypt($passphrase)]);
    }

    private static function passphrase(): string
    {
        $enc = (string) Settings::get('backup_passphrase_enc');
        if ($enc === '') {
            throw new StorageException('Backups are not set up yet.');
        }
        return Security::decrypt($enc);
    }

    public static function generatePassphrase(): string
    {
        return bin2hex(random_bytes(18)); // hex only, so format() below never produces a doubled dash
    }

    public static function format(string $secret): string
    {
        return trim(chunk_split($secret, 4, '-'), '-');
    }

    // ---------- Scheduling (called from Maintenance, or tools/cron.php) ----------

    public static function maybeRun(): void
    {
        if (!self::enabled()) {
            return;
        }
        $due = Settings::get('backup_frequency') === 'weekly' ? 7 * 86400 : 86400;
        $last = (int) (Database::one("SELECT meta_value FROM bla_meta WHERE meta_key = 'backup_last_at'")['meta_value'] ?? 0);
        if (time() - $last < $due) {
            return;
        }
        try {
            self::run('auto');
        } catch (\Throwable $e) {
            error_log('[BLA-Cloud] scheduled backup failed: ' . $e->getMessage());
        }
    }

    // ---------- The backup itself ----------

    /** @return array{ok: bool, filename: string, size: int} */
    public static function run(string $kind = 'manual'): array
    {
        $dir = self::dir();
        if ($dir === '' || !self::enabled()) {
            throw new StorageException('Backups are not set up yet.');
        }
        @set_time_limit(600);
        $scratch = self::scratchDir();
        $name = 'bla-cloud-' . $kind . '-' . gmdate('Ymd-His') . '.bcbackup';
        try {
            $dumpPath = self::dumpDatabase($scratch);
            $zipPath = self::buildZip($scratch, $dumpPath);
            $finalPath = $dir . '/' . $name;
            self::encryptFile($zipPath, $finalPath, self::passphrase());
            $size = (int) filesize($finalPath);
            Database::run('INSERT INTO bla_backups (filename, kind, size_bytes, status, created_at) VALUES (?, ?, ?, ?, ?)',
                [$name, $kind, $size, 'ok', Database::now()]);
            self::touchLastRun();
            self::prune();
            Audit::log(null, 'backup.created', $name . ' (' . View::bytes($size) . ')');
            return ['ok' => true, 'filename' => $name, 'size' => $size];
        } catch (\Throwable $e) {
            Database::run('INSERT INTO bla_backups (filename, kind, size_bytes, status, error, created_at) VALUES (?, ?, ?, ?, ?, ?)',
                ['', $kind, 0, 'failed', mb_substr($e->getMessage(), 0, 500), Database::now()]);
            Audit::log(null, 'backup.failed', mb_substr($e->getMessage(), 0, 500));
            throw $e;
        } finally {
            Storage::removeTree($scratch);
        }
    }

    private static function touchLastRun(): void
    {
        $sql = Database::driver() === 'mysql'
            ? 'REPLACE INTO bla_meta (meta_key, meta_value) VALUES (?, ?)'
            : 'INSERT OR REPLACE INTO bla_meta (meta_key, meta_value) VALUES (?, ?)';
        Database::run($sql, ['backup_last_at', (string) time()]);
    }

    private static function prune(): void
    {
        $retention = max(1, (int) Settings::get('backup_retention'));
        $rows = Database::all("SELECT * FROM bla_backups WHERE status = 'ok' ORDER BY id DESC");
        foreach (array_slice($rows, $retention) as $r) {
            @unlink(self::dir() . '/' . $r['filename']);
            Database::run('DELETE FROM bla_backups WHERE id = ?', [$r['id']]);
        }
    }

    public static function list(): array
    {
        return Database::all('SELECT * FROM bla_backups ORDER BY id DESC LIMIT 100');
    }

    public static function deleteFile(int $id): void
    {
        $row = Database::one('SELECT * FROM bla_backups WHERE id = ?', [$id]);
        if (!$row) {
            return;
        }
        if ($row['filename'] !== '') {
            @unlink(self::dir() . '/' . $row['filename']);
        }
        Database::run('DELETE FROM bla_backups WHERE id = ?', [$id]);
    }

    // ---------- Restore drill: decrypt + sanity-check, without touching the live install ----------

    public static function verify(int $id, string $passphrase): array
    {
        $row = self::findOk($id);
        $scratch = self::scratchDir();
        try {
            $zipPath = $scratch . '/verify.zip';
            self::decryptFile(self::dir() . '/' . $row['filename'], $zipPath, $passphrase);
            $zip = new \ZipArchive();
            if ($zip->open($zipPath) !== true) {
                throw new StorageException('The backup archive is damaged.');
            }
            $hasConfig = $zip->locateName('config.php') !== false;
            $dbEntry = null;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $n = $zip->getNameIndex($i);
                if ($n !== false && str_starts_with($n, 'database/')) {
                    $dbEntry = $n;
                    break;
                }
            }
            if (!$hasConfig || !$dbEntry) {
                $zip->close();
                throw new StorageException('The backup is missing expected files.');
            }
            $detail = 'Looks good — the passphrase is correct and the archive has a database (' . basename($dbEntry) . ') and config.php.';
            if (str_ends_with($dbEntry, '.sqlite')) {
                $zip->extractTo($scratch, $dbEntry);
                $count = self::countUsersInSqlite($scratch . '/' . $dbEntry);
                if ($count !== null) {
                    $detail .= " It contains $count account(s).";
                }
            }
            $zip->close();
            Audit::log(null, 'backup.verified', $row['filename']);
            return ['ok' => true, 'detail' => $detail];
        } finally {
            Storage::removeTree($scratch);
        }
    }

    private static function countUsersInSqlite(string $path): ?int
    {
        try {
            $pdo = new \PDO('sqlite:' . $path);
            $n = $pdo->query('SELECT COUNT(*) FROM bla_users')->fetchColumn();
            $pdo = null;
            return (int) $n;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function findOk(int $id): array
    {
        $row = Database::one('SELECT * FROM bla_backups WHERE id = ?', [$id]);
        if (!$row || $row['filename'] === '' || !is_file(self::dir() . '/' . $row['filename'])) {
            throw new StorageException('That backup file is missing.');
        }
        return $row;
    }

    // ---------- Restore: the real, destructive thing ----------

    public static function restore(int $id, string $passphrase): void
    {
        $row = self::findOk($id);
        @set_time_limit(600);

        $scratch = self::scratchDir();
        try {
            // Decrypt and extract the backup we're restoring from *before* touching anything live —
            // in particular, before the safety backup below, whose own prune() could otherwise delete
            // this very file first (if it's the oldest one still within the retention count).
            $zipPath = $scratch . '/restore.zip';
            self::decryptFile(self::dir() . '/' . $row['filename'], $zipPath, $passphrase);
            $extractDir = $scratch . '/extracted';
            @mkdir($extractDir, 0700, true);
            $zip = new \ZipArchive();
            if ($zip->open($zipPath) !== true) {
                throw new StorageException('The backup archive is damaged.');
            }
            $zip->extractTo($extractDir);
            $zip->close();

            // Safety net: whatever is live right now becomes its own backup, so this can be undone.
            // Its bla_backups row lives in the database we're about to replace, so it has to be
            // re-added afterward, below, or it would quietly vanish even though the file is still on disk.
            $safety = self::run('safety');

            $dbFiles = glob($extractDir . '/database/*') ?: [];
            if (!$dbFiles) {
                throw new StorageException('That backup has no database in it.');
            }
            $dbFile = $dbFiles[0];
            if (Database::driver() === 'sqlite') {
                if (!str_ends_with($dbFile, '.sqlite')) {
                    throw new StorageException("This backup is from a MySQL install and can't be restored into a SQLite one.");
                }
                self::replaceSqlite($dbFile);
            } else {
                if (!str_ends_with($dbFile, '.sql')) {
                    throw new StorageException("This backup is from a SQLite install and can't be restored into a MySQL one.");
                }
                self::replaceMysql($dbFile);
            }

            // Move the live files out of the way (a rename, not a delete) rather than clearing the
            // folder up front — if the copy below is interrupted (time limit, disk full), the
            // original is still sitting right there under its .pre-restore name, recoverable with a
            // plain rename back, no need to fall through to decrypting the safety backup.
            $usersDir = rtrim((string) Config::get('data_dir'), '/') . '/users';
            $preRestoreDir = $usersDir . '.pre-restore-' . time();
            if (is_dir($usersDir) && !@rename($usersDir, $preRestoreDir)) {
                throw new StorageException('Could not move the current files out of the way to restore.');
            }
            @mkdir($usersDir, 0750, true);
            if (is_dir($extractDir . '/users')) {
                self::copyTree($extractDir . '/users', $usersDir);
            }
            if (is_dir($preRestoreDir)) {
                Storage::removeTree($preRestoreDir);
            }

            // Re-register the safety backup in the now-restored database (see the comment above).
            Database::run('INSERT INTO bla_backups (filename, kind, size_bytes, status, created_at) VALUES (?, ?, ?, ?, ?)',
                [$safety['filename'], 'safety', $safety['size'], 'ok', Database::now()]);
        } finally {
            Storage::removeTree($scratch);
        }
    }

    private static function replaceSqlite(string $newFile): void
    {
        $pdo = Database::pdo();
        try {
            $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        } catch (\Throwable) {
            // best effort
        }
        $dbPath = (string) Config::get('db.path');
        $aside = $dbPath . '.pre-restore-' . time();
        if (!@rename($dbPath, $aside)) {
            throw new StorageException('Could not replace the database file (is it writable?).');
        }
        foreach (['-wal', '-shm'] as $suffix) {
            @unlink($dbPath . $suffix);
        }
        if (!@rename($newFile, $dbPath)) {
            @rename($aside, $dbPath); // best-effort rollback
            throw new StorageException('Could not put the restored database in place.');
        }
        @unlink($aside);
        Database::disconnect(); // the old connection's file descriptor still points at the now-unlinked file
    }

    private static function replaceMysql(string $sqlFile): void
    {
        $pdo = Database::pdo();
        $tables = $pdo->query("SHOW TABLES LIKE 'bla\\_%'")->fetchAll(\PDO::FETCH_COLUMN);
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($tables as $t) {
                $pdo->exec('DROP TABLE IF EXISTS `' . $t . '`');
            }
            $sql = (string) file_get_contents($sqlFile);
            foreach (array_filter(array_map('trim', explode(";\n", $sql))) as $stmt) {
                $pdo->exec($stmt);
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    private static function copyTree(string $from, string $to): void
    {
        if (!is_dir($to) && !@mkdir($to, 0750, true)) {
            return;
        }
        foreach (new \DirectoryIterator($from) as $f) {
            if ($f->isDot() || $f->isLink()) {
                continue;
            }
            $target = $to . '/' . $f->getFilename();
            if ($f->isDir()) {
                self::copyTree($f->getPathname(), $target);
            } else {
                @copy($f->getPathname(), $target);
            }
        }
    }

    // ---------- Building the archive ----------

    private static function scratchDir(): string
    {
        $dir = rtrim((string) Config::get('data_dir'), '/') . '/backup-tmp/' . bin2hex(random_bytes(8));
        @mkdir($dir, 0700, true);
        return $dir;
    }

    private static function dumpDatabase(string $scratch): string
    {
        if (Database::driver() === 'sqlite') {
            $path = $scratch . '/database.sqlite';
            Database::pdo()->exec('VACUUM INTO ' . Database::pdo()->quote($path));
            return $path;
        }
        $path = $scratch . '/database.sql';
        $fh = fopen($path, 'wb');
        if (!$fh) {
            throw new StorageException('Could not write the database dump.');
        }
        $pdo = Database::pdo();
        $tables = $pdo->query("SHOW TABLES LIKE 'bla\\_%'")->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($tables as $t) {
            $create = $pdo->query('SHOW CREATE TABLE `' . $t . '`')->fetch()['Create Table'];
            fwrite($fh, "DROP TABLE IF EXISTS `$t`;\n$create;\n\n");
            $count = (int) $pdo->query('SELECT COUNT(*) FROM `' . $t . '`')->fetchColumn();
            for ($offset = 0; $offset < $count; $offset += 500) {
                $rows = $pdo->query("SELECT * FROM `$t` LIMIT 500 OFFSET $offset")->fetchAll(\PDO::FETCH_ASSOC);
                foreach ($rows as $row) {
                    $cols = '`' . implode('`, `', array_keys($row)) . '`';
                    $vals = implode(', ', array_map(static fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), $row));
                    fwrite($fh, "INSERT INTO `$t` ($cols) VALUES ($vals);\n");
                }
            }
            fwrite($fh, "\n");
        }
        fclose($fh);
        return $path;
    }

    private static function buildZip(string $scratch, string $dbDumpPath): string
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new StorageException('Backups need the PHP "zip" extension. Ask your host to enable it.');
        }
        $zipPath = $scratch . '/archive.zip';
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new StorageException('Could not create the backup archive.');
        }
        $zip->addFile($dbDumpPath, 'database/' . basename($dbDumpPath));
        $zip->addFile(Config::path(), 'config.php');

        $usersDir = rtrim((string) Config::get('data_dir'), '/') . '/users';
        foreach (glob($usersDir . '/*', GLOB_ONLYDIR) ?: [] as $userDir) {
            $uid = basename($userDir);
            foreach (['files', 'trash', 'versions'] as $sub) {
                $subPath = $userDir . '/' . $sub;
                if (!is_dir($subPath)) {
                    continue;
                }
                $zip->addEmptyDir("users/$uid/$sub");
                $it = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($subPath, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::SELF_FIRST);
                foreach ($it as $f) {
                    if ($f->isLink()) {
                        continue;
                    }
                    $inner = "users/$uid/$sub" . str_replace('\\', '/', substr($f->getPathname(), strlen($subPath)));
                    if ($f->isDir()) {
                        $zip->addEmptyDir($inner);
                    } else {
                        $zip->addFile($f->getPathname(), $inner);
                    }
                }
            }
        }
        $zip->close();
        return $zipPath;
    }

    // ---------- Encryption: see FileCrypto.php (shared with Encryption.php's per-file encryption) ----------

    public static function encryptFile(string $inPath, string $outPath, string $passphrase): void
    {
        FileCrypto::encryptFile($inPath, $outPath, $passphrase, self::MAGIC);
    }

    public static function decryptFile(string $inPath, string $outPath, string $passphrase): void
    {
        FileCrypto::decryptFile($inPath, $outPath, $passphrase, self::MAGIC);
    }
}
