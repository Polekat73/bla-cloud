<?php
declare(strict_types=1);

namespace BlaCloud;

/**
 * Older copies of files. When a file is replaced (e.g. re-uploaded), the old content is kept here.
 * Blobs live in users/{id}/versions/{blob}; rows in bla_versions point at the file's current path.
 */
final class Versions
{
    public const DEFAULT_KEEP = 10;
    public const DEFAULT_MAX_DAYS = 180;

    public function __construct(private Storage $fs)
    {
    }

    public static function keep(): int
    {
        return max(1, (int) (Settings::get('versions_keep') ?: self::DEFAULT_KEEP));
    }

    private function dir(): string
    {
        return $this->fs->internalDir('versions');
    }

    private function blobPath(array $row): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/', (string) $row['blob_name'])) {
            throw new StorageException('Invalid version.');
        }
        return $this->dir() . '/' . $row['blob_name'];
    }

    /** Keep the file currently at $rel as a previous version (moves it away; caller writes the new one). */
    public function saveCurrent(string $rel): void
    {
        $abs = $this->fs->abs($rel);
        if (!is_file($abs)) {
            return;
        }
        $blob = bin2hex(random_bytes(16));
        $size = (int) filesize($abs);
        $mtime = (int) filemtime($abs);
        if (!@rename($abs, $this->dir() . '/' . $blob)) {
            throw new StorageException('Could not keep the previous version.');
        }
        Database::run('INSERT INTO bla_versions (user_id, path, blob_name, size, file_mtime, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$this->fs->userId(), $rel, $blob, $size, $mtime, Database::now()]);
        $this->prune($rel);
    }

    /** Versions of one file, newest first. */
    public function list(string $rel): array
    {
        return Database::all('SELECT id, size, file_mtime, created_at FROM bla_versions
            WHERE user_id = ? AND path = ? AND trash_id IS NULL ORDER BY id DESC', [$this->fs->userId(), Storage::normalize($rel)]);
    }

    public function find(int $id): array
    {
        $row = Database::one('SELECT * FROM bla_versions WHERE id = ? AND user_id = ? AND trash_id IS NULL', [$id, $this->fs->userId()]);
        if (!$row) {
            throw new StorageException('That version no longer exists.');
        }
        return $row;
    }

    public function blobFor(int $id): array
    {
        $row = $this->find($id);
        $p = $this->blobPath($row);
        if (!is_file($p)) {
            throw new StorageException('That version is missing from storage.');
        }
        return [$row, $p];
    }

    /** Bring an old version back. The current content becomes a version itself, so nothing is lost. */
    public function restore(int $id): void
    {
        [$row, $blob] = $this->blobFor($id);
        $rel = $row['path'];
        $abs = $this->fs->abs($rel, false);
        if (is_dir($abs)) {
            throw new StorageException('A folder now has that name, so the version cannot be restored there.');
        }
        // Take this version off the list first, so pruning below never counts it.
        Database::run('DELETE FROM bla_versions WHERE id = ?', [$row['id']]);
        try {
            if (is_file($abs)) {
                $this->saveCurrent($rel);
            }
            if (!@rename($blob, $abs)) {
                throw new StorageException('Could not restore this version.');
            }
        } catch (StorageException $e) {
            Database::run('INSERT INTO bla_versions (user_id, path, blob_name, size, file_mtime, created_at) VALUES (?, ?, ?, ?, ?, ?)',
                [$row['user_id'], $row['path'], $row['blob_name'], $row['size'], $row['file_mtime'], $row['created_at']]);
            throw $e;
        }
        @touch($abs, (int) $row['file_mtime'] ?: time());
    }

    public function delete(int $id): void
    {
        $row = $this->find($id);
        @unlink($this->blobPath($row));
        Database::run('DELETE FROM bla_versions WHERE id = ?', [$row['id']]);
    }

    /** Keep only the newest N versions of a file. */
    public function prune(string $rel): void
    {
        $rows = Database::all('SELECT * FROM bla_versions WHERE user_id = ? AND path = ? AND trash_id IS NULL ORDER BY id DESC',
            [$this->fs->userId(), $rel]);
        foreach (array_slice($rows, self::keep()) as $r) {
            @unlink($this->blobPath($r));
            Database::run('DELETE FROM bla_versions WHERE id = ?', [$r['id']]);
        }
    }

    /** Rows for a path and everything below it. */
    private function rowsUnder(string $rel, ?int $trashId = null): array
    {
        $uid = $this->fs->userId();
        $rows = $trashId === null
            ? Database::all('SELECT id, path FROM bla_versions WHERE user_id = ? AND trash_id IS NULL AND (path = ? OR SUBSTR(path, 1, ?) = ?)',
                [$uid, $rel, mb_strlen($rel) + 1, $rel . '/'])
            : Database::all('SELECT id, path FROM bla_versions WHERE user_id = ? AND trash_id = ?', [$uid, $trashId]);
        // Double-check in PHP (collation-proof).
        return array_values(array_filter($rows, static fn ($r) => $trashId !== null || Storage::isWithin($r['path'], $rel)));
    }

    /** After a rename or move: point versions at the new path. */
    public function movePrefix(string $old, string $new): void
    {
        foreach ($this->rowsUnder($old) as $r) {
            Database::run('UPDATE bla_versions SET path = ? WHERE id = ?', [$new . substr($r['path'], strlen($old)), $r['id']]);
        }
    }

    public function attachToTrash(string $rel, int $trashId): void
    {
        foreach ($this->rowsUnder($rel) as $r) {
            Database::run('UPDATE bla_versions SET trash_id = ?, path = ? WHERE id = ?',
                [$trashId, substr($r['path'], strlen($rel)), $r['id']]); // store path relative to the trashed item
        }
    }

    public function detachFromTrash(int $trashId, string $newRel): void
    {
        foreach ($this->rowsUnder('', $trashId) as $r) {
            Database::run('UPDATE bla_versions SET trash_id = NULL, path = ? WHERE id = ?', [$newRel . $r['path'], $r['id']]);
        }
    }

    public function deleteForTrash(int $trashId): void
    {
        $rows = Database::all('SELECT * FROM bla_versions WHERE user_id = ? AND trash_id = ?', [$this->fs->userId(), $trashId]);
        foreach ($rows as $r) {
            @unlink($this->blobPath($r));
        }
        Database::run('DELETE FROM bla_versions WHERE user_id = ? AND trash_id = ?', [$this->fs->userId(), $trashId]);
    }

    public function totalSize(): int
    {
        return (int) (Database::one('SELECT COALESCE(SUM(size), 0) AS s FROM bla_versions WHERE user_id = ?', [$this->fs->userId()])['s'] ?? 0);
    }

    /** Drop versions older than the maximum age (only for files not in the trash). */
    public function purgeOld(): int
    {
        $days = max(1, (int) (Settings::get('versions_max_days') ?: self::DEFAULT_MAX_DAYS));
        $cut = gmdate('Y-m-d H:i:s', time() - $days * 86400);
        $rows = Database::all('SELECT * FROM bla_versions WHERE user_id = ? AND trash_id IS NULL AND created_at < ?', [$this->fs->userId(), $cut]);
        foreach ($rows as $r) {
            @unlink($this->blobPath($r));
            Database::run('DELETE FROM bla_versions WHERE id = ?', [$r['id']]);
        }
        return count($rows);
    }
}
