<?php
declare(strict_types=1);

namespace BlaCloud;

/**
 * Housekeeping: empties old trash, trims old versions, removes stale temp files and thumbnails.
 * Runs occasionally during normal page loads (no cron needed). A later stage adds a real cron job.
 */
final class Maintenance
{
    public static function run(): void
    {
        $lock = rtrim((string) Config::get('data_dir'), '/') . '/.maintenance.lock';
        $fh = @fopen($lock, 'c');
        if (!$fh || !flock($fh, LOCK_EX | LOCK_NB)) {
            return; // another request is already doing it
        }
        try {
            // At most once an hour.
            $last = (int) (Database::one("SELECT meta_value FROM bla_meta WHERE meta_key = 'maintenance_at'")['meta_value'] ?? 0);
            if (time() - $last < 3600) {
                return;
            }
            $sql = Database::driver() === 'mysql'
                ? 'REPLACE INTO bla_meta (meta_key, meta_value) VALUES (?, ?)'
                : 'INSERT OR REPLACE INTO bla_meta (meta_key, meta_value) VALUES (?, ?)';
            Database::run($sql, ['maintenance_at', (string) time()]);

            @set_time_limit(120);
            foreach (Database::all('SELECT id FROM bla_users') as $u) {
                try {
                    $fs = new Storage((int) $u['id']);
                    $trashed = (new Trash($fs))->purgeExpired();
                    $versions = (new Versions($fs))->purgeOld();
                    $fs->cleanupTemp();
                    (new Thumbnails($fs))->cleanup();
                    if ($trashed || $versions) {
                        Audit::log((int) $u['id'], 'maintenance', "removed $trashed old trash item(s), $versions old version(s)");
                    }
                } catch (\Throwable $e) {
                    error_log('[BLA-Cloud] maintenance for user ' . $u['id'] . ': ' . $e->getMessage());
                }
            }
            Database::run('DELETE FROM bla_login_attempts WHERE created_at < ?', [gmdate('Y-m-d H:i:s', time() - 86400)]);
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }
}
