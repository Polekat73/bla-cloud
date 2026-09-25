<?php
declare(strict_types=1);

namespace BlaCloud;

/**
 * Deleted items go here first. Each item lives in trash/{key}/{name} so names never clash,
 * and its versions stay linked (via bla_versions.trash_id) so restoring brings them back too.
 */
final class Trash
{
    public const DEFAULT_DAYS = 30;

    public function __construct(private Storage $fs)
    {
    }

    public static function retentionDays(): int
    {
        return max(1, (int) (Settings::get('trash_days') ?: self::DEFAULT_DAYS));
    }

    private function dir(): string
    {
        return $this->fs->internalDir('trash');
    }

    private function itemPath(array $row): string
    {
        if (!preg_match('/^[a-f0-9]{16,40}$/', (string) $row['trash_key'])) {
            throw new StorageException('Invalid trash item.');
        }
        return $this->dir() . '/' . $row['trash_key'] . '/' . $row['name'];
    }

    public function put(string $rel): void
    {
        $rel = Storage::normalize($rel);
        if ($rel === '') {
            throw new StorageException('The top folder cannot be deleted.');
        }
        $abs = $this->fs->abs($rel);
        $isDir = is_dir($abs);
        $size = Storage::dirSize($abs);
        $key = bin2hex(random_bytes(10));
        $holder = $this->dir() . '/' . $key;
        if (!@mkdir($holder, 0750)) {
            throw new StorageException('Could not move to the trash.');
        }
        $name = basename($rel);
        if (!@rename($abs, $holder . '/' . $name)) {
            @rmdir($holder);
            throw new StorageException('Could not move "' . $name . '" to the trash.');
        }
        $id = Database::insert(
            'INSERT INTO bla_trash (user_id, trash_key, original_path, name, is_dir, size, deleted_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$this->fs->userId(), $key, $rel, $name, $isDir ? 1 : 0, $size, Database::now()]);
        (new Versions($this->fs))->attachToTrash($rel, $id);
        Shares::removeUnder($this->fs->userId(), $rel);
    }

    public function list(): array
    {
        $rows = Database::all('SELECT * FROM bla_trash WHERE user_id = ? ORDER BY deleted_at DESC, id DESC', [$this->fs->userId()]);
        $days = self::retentionDays();
        foreach ($rows as &$r) {
            $r['type'] = (int) $r['is_dir'] ? 'folder' : Storage::kind($r['name']);
            $r['expires'] = strtotime($r['deleted_at'] . ' UTC') + $days * 86400;
        }
        return $rows;
    }

    public function find(int $id): array
    {
        $row = Database::one('SELECT * FROM bla_trash WHERE id = ? AND user_id = ?', [$id, $this->fs->userId()]);
        if (!$row) {
            throw new StorageException('That item is no longer in the trash.');
        }
        return $row;
    }

    /** Put an item back where it was (re-creating missing folders). Returns its new path. */
    public function restore(int $id): string
    {
        $row = $this->find($id);
        $src = $this->itemPath($row);
        if (!file_exists($src)) {
            $this->forget($row);
            throw new StorageException('That item is missing from the trash storage.');
        }
        $parent = Storage::parent($row['original_path']);
        // Re-create the original folder path if it was deleted or moved meanwhile.
        $acc = '';
        foreach (array_filter(explode('/', $parent), 'strlen') as $seg) {
            $acc .= '/' . $seg;
            $abs = $this->fs->abs($acc, false);
            if (!file_exists($abs)) {
                @mkdir($abs, 0750);
            } elseif (!is_dir($abs)) {
                $parent = ''; // a file now sits where a folder was: restore to the top folder instead
                break;
            }
        }
        $name = $this->fs->uniqueName($parent, $row['name']);
        $newRel = Storage::join($parent, $name);
        if (!@rename($src, $this->fs->abs($newRel, false))) {
            throw new StorageException('Could not restore "' . $row['name'] . '".');
        }
        @rmdir(dirname($src));
        (new Versions($this->fs))->detachFromTrash((int) $row['id'], $newRel);
        Database::run('DELETE FROM bla_trash WHERE id = ?', [$row['id']]);
        return $newRel;
    }

    /** Delete one item forever (including its versions). */
    public function purge(int $id): void
    {
        $row = $this->find($id);
        Storage::removeTree(dirname($this->itemPath($row)));
        $this->forget($row);
    }

    private function forget(array $row): void
    {
        (new Versions($this->fs))->deleteForTrash((int) $row['id']);
        Database::run('DELETE FROM bla_trash WHERE id = ?', [$row['id']]);
    }

    public function empty(): int
    {
        $n = 0;
        foreach (Database::all('SELECT id FROM bla_trash WHERE user_id = ?', [$this->fs->userId()]) as $r) {
            $this->purge((int) $r['id']);
            $n++;
        }
        return $n;
    }

    public function size(): int
    {
        return (int) (Database::one('SELECT COALESCE(SUM(size), 0) AS s FROM bla_trash WHERE user_id = ?', [$this->fs->userId()])['s'] ?? 0);
    }

    /** Remove items older than the retention period. */
    public function purgeExpired(): int
    {
        $cut = gmdate('Y-m-d H:i:s', time() - self::retentionDays() * 86400);
        $n = 0;
        foreach (Database::all('SELECT id FROM bla_trash WHERE user_id = ? AND deleted_at < ?', [$this->fs->userId(), $cut]) as $r) {
            $this->purge((int) $r['id']);
            $n++;
        }
        return $n;
    }
}
