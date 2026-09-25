<?php
declare(strict_types=1);

namespace BlaCloud;

/**
 * A user's private file space. Every path from the browser is a *relative* path
 * like "/Photos/2026". It is normalised and checked so it can never escape the
 * user's own folder (no "..", no symlinks, no absolute paths).
 *
 * Layout on disk (inside the data folder):
 *   users/{id}/files     the user's files
 *   users/{id}/trash     deleted items (see Trash)
 *   users/{id}/versions  older copies of files (see Versions)
 *   users/{id}/thumbs    cached thumbnails (see Thumbnails)
 *   users/{id}/uploads   partial uploads
 *   users/{id}/tmp       temporary zip files
 */
final class Storage
{
    /** Names that could change server behaviour if the data folder is ever web-reachable. */
    private const RESERVED = ['.htaccess', '.htpasswd', '.user.ini', 'web.config', '.ds_store'];

    private string $root;
    private string $userDir;

    public function __construct(private int $userId)
    {
        $base = rtrim((string) Config::get('data_dir'), '/\\');
        if ($base === '' || !is_dir($base)) {
            throw new \RuntimeException('The data folder is missing. Check data_dir in config/config.php.');
        }
        $this->userDir = $base . '/users/' . $userId;
        $this->root = $this->userDir . '/files';
        if (!is_dir($this->root) && !@mkdir($this->root, 0750, true) && !is_dir($this->root)) {
            throw new \RuntimeException('Could not create your file folder.');
        }
        $this->root = (string) realpath($this->root);
        $this->userDir = (string) realpath($this->userDir);
    }

    public function userId(): int
    {
        return $this->userId;
    }

    public function root(): string
    {
        return $this->root;
    }

    /** A private working folder next to the user's files (trash, versions, thumbs, tmp…). */
    public function internalDir(string $name): string
    {
        if (!preg_match('/^[a-z]+$/', $name)) {
            throw new \InvalidArgumentException('bad internal dir');
        }
        $d = $this->userDir . '/' . $name;
        if (!is_dir($d)) {
            @mkdir($d, 0750, true);
        }
        return $d;
    }

    // ---------- Path safety ----------

    /** Turn user input into a clean relative path ("" = top folder). Throws on anything suspicious. */
    public static function normalize(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        if (str_contains($path, "\0")) {
            throw new StorageException('Invalid path.');
        }
        $parts = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                throw new StorageException('Invalid path.');
            }
            self::assertValidName($seg);
            $parts[] = $seg;
        }
        return $parts ? '/' . implode('/', $parts) : '';
    }

    public static function assertValidName(string $name): void
    {
        if ($name === '' || $name === '.' || $name === '..') {
            throw new StorageException('Please enter a name.');
        }
        if (strlen($name) > 255) {
            throw new StorageException('That name is too long.');
        }
        if (!mb_check_encoding($name, 'UTF-8') || preg_match('/[\x00-\x1F\x7F\/\\\\]/', $name)) {
            throw new StorageException('Names cannot contain slashes or control characters.');
        }
        if ($name !== trim($name)) {
            throw new StorageException('Names cannot start or end with a space.');
        }
        if (in_array(mb_strtolower($name), self::RESERVED, true)) {
            throw new StorageException('"' . $name . '" is a reserved name. Please choose another.');
        }
    }

    public static function isReserved(string $name): bool
    {
        return in_array(mb_strtolower($name), self::RESERVED, true);
    }

    /** Absolute path for a relative path; verifies existing targets are really inside the root. */
    public function abs(string $rel, bool $mustExist = true): string
    {
        $rel = self::normalize($rel);
        $abs = $this->root . $rel;
        if ($mustExist) {
            if (!file_exists($abs) || is_link($abs)) {
                throw new StorageException('That file or folder no longer exists.');
            }
            $real = realpath($abs);
            if ($real === false || !$this->inside($real, $this->root)) {
                throw new StorageException('Invalid path.');
            }
            return $real;
        }
        // For new items: the parent must exist and be inside the root.
        $parent = realpath(dirname($abs));
        if ($parent === false || !$this->inside($parent, $this->root)) {
            throw new StorageException('Invalid path.');
        }
        return $parent . '/' . basename($abs);
    }

    private function inside(string $real, string $base): bool
    {
        return $real === $base || str_starts_with($real, $base . DIRECTORY_SEPARATOR);
    }

    public static function join(string $dir, string $name): string
    {
        return self::normalize($dir . '/' . $name);
    }

    public static function parent(string $rel): string
    {
        $rel = self::normalize($rel);
        $p = dirname($rel);
        return ($p === '/' || $p === '.' || $p === '\\') ? '' : $p;
    }

    /** Is $rel equal to $ancestor or somewhere inside it? */
    public static function isWithin(string $rel, string $ancestor): bool
    {
        return $ancestor === '' || $rel === $ancestor || str_starts_with($rel, $ancestor . '/');
    }

    // ---------- Reading ----------

    public function list(string $rel): array
    {
        $dir = $this->abs($rel);
        if (!is_dir($dir)) {
            throw new StorageException('That is not a folder.');
        }
        $items = [];
        foreach (new \DirectoryIterator($dir) as $f) {
            if ($f->isDot() || $f->isLink() || self::isReserved($f->getFilename())) {
                continue;
            }
            $items[] = self::describe($f, self::normalize($rel . '/' . $f->getFilename()));
        }
        usort($items, static fn ($a, $b) => [$b['dir'], mb_strtolower($a['name'])] <=> [$a['dir'], mb_strtolower($b['name'])]);
        return $items;
    }

    private static function describe(\SplFileInfo $f, string $rel): array
    {
        $isDir = $f->isDir();
        $name = $f->getFilename();
        return [
            'name'  => $name,
            'path'  => $rel,
            'dir'   => $isDir,
            'size'  => $isDir ? null : $f->getSize(),
            'mtime' => $f->getMTime(),
            'type'  => $isDir ? 'folder' : self::kind($name),
        ];
    }

    /** Only folders, for the move/copy picker. */
    public function listFolders(string $rel): array
    {
        return array_values(array_filter($this->list($rel), static fn ($i) => $i['dir']));
    }

    public static function kind(string $name): string
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        return match (true) {
            in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'heif', 'bmp', 'svg', 'avif'], true) => 'image',
            in_array($ext, ['mp4', 'mov', 'mkv', 'webm', 'avi', 'm4v'], true) => 'video',
            in_array($ext, ['mp3', 'wav', 'flac', 'm4a', 'ogg', 'aac', 'opus'], true) => 'audio',
            in_array($ext, ['pdf'], true) => 'pdf',
            in_array($ext, ['doc', 'docx', 'odt', 'rtf', 'txt', 'md', 'pages'], true) => 'doc',
            in_array($ext, ['xls', 'xlsx', 'ods', 'csv', 'numbers'], true) => 'sheet',
            in_array($ext, ['ppt', 'pptx', 'odp', 'key'], true) => 'slides',
            in_array($ext, ['zip', 'rar', '7z', 'tar', 'gz', 'bz2', 'xz'], true) => 'archive',
            default => 'file',
        };
    }

    /** Recursively find files/folders whose name contains $query (case-insensitive). */
    public function search(string $query, int $limit = 200, string $within = ''): array
    {
        $q = mb_strtolower(trim($query));
        if (mb_strlen($q) < 1) {
            return [];
        }
        $start = $this->abs($within);
        if (!is_dir($start)) {
            return [];
        }
        $out = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($start, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $f) {
            if ($f->isLink() || self::isReserved($f->getFilename())) {
                continue;
            }
            if (str_contains(mb_strtolower($f->getFilename()), $q)) {
                $rel = substr($f->getPathname(), strlen($this->root));
                $out[] = self::describe($f, str_replace('\\', '/', $rel));
                if (count($out) >= $limit) {
                    break;
                }
            }
        }
        usort($out, static fn ($a, $b) => [$b['dir'], mb_strtolower($a['name'])] <=> [$a['dir'], mb_strtolower($b['name'])]);
        return $out;
    }

    public function usage(): int
    {
        return self::dirSize($this->root);
    }

    public static function dirSize(string $dir): int
    {
        if (!is_dir($dir)) {
            return is_file($dir) ? (int) filesize($dir) : 0;
        }
        $total = 0;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && !$f->isLink()) {
                $total += $f->getSize();
            }
        }
        return $total;
    }

    // ---------- Writing ----------

    public function mkdir(string $parentRel, string $name): string
    {
        $name = trim($name);
        self::assertValidName($name);
        $rel = self::join($parentRel, $name);
        $abs = $this->abs($rel, false);
        if (file_exists($abs)) {
            throw new StorageException('Something with that name already exists here.');
        }
        if (!@mkdir($abs, 0750)) {
            throw new StorageException('Could not create the folder.');
        }
        return $rel;
    }

    public function rename(string $rel, string $newName): string
    {
        $newName = trim($newName);
        self::assertValidName($newName);
        $rel = self::normalize($rel);
        if ($rel === '') {
            throw new StorageException('The top folder cannot be renamed.');
        }
        $from = $this->abs($rel);
        $newRel = self::join(self::parent($rel), $newName);
        $to = $this->abs($newRel, false);
        if ($from === $to) {
            return $newRel;
        }
        // Allow case-only renames on case-insensitive disks.
        if (file_exists($to) && strcasecmp($from, $to) !== 0) {
            throw new StorageException('Something with that name already exists here.');
        }
        if (!@rename($from, $to)) {
            throw new StorageException('Could not rename.');
        }
        (new Versions($this))->movePrefix($rel, $newRel);
        Shares::movePrefix($this->userId, $rel, $newRel);
        return $newRel;
    }

    /** Move an item into another folder. Returns its new path. */
    public function move(string $rel, string $destDir): string
    {
        $rel = self::normalize($rel);
        $destDir = self::normalize($destDir);
        if ($rel === '') {
            throw new StorageException('The top folder cannot be moved.');
        }
        if (self::isWithin($destDir, $rel)) {
            throw new StorageException('A folder cannot be moved into itself.');
        }
        if (self::parent($rel) === $destDir) {
            return $rel; // already there
        }
        $from = $this->abs($rel);
        if (!is_dir($this->abs($destDir))) {
            throw new StorageException('The destination is not a folder.');
        }
        $name = $this->uniqueName($destDir, basename($rel));
        $newRel = self::join($destDir, $name);
        if (!@rename($from, $this->abs($newRel, false))) {
            throw new StorageException('Could not move "' . basename($rel) . '".');
        }
        (new Versions($this))->movePrefix($rel, $newRel);
        Shares::movePrefix($this->userId, $rel, $newRel);
        return $newRel;
    }

    /** Copy an item (recursively) into another folder. Returns the path of the copy. */
    public function copy(string $rel, string $destDir): string
    {
        $rel = self::normalize($rel);
        $destDir = self::normalize($destDir);
        if ($rel === '') {
            throw new StorageException('The top folder cannot be copied.');
        }
        if (self::isWithin($destDir, $rel)) {
            throw new StorageException('A folder cannot be copied into itself.');
        }
        $from = $this->abs($rel);
        if (!is_dir($this->abs($destDir))) {
            throw new StorageException('The destination is not a folder.');
        }
        $this->assertSpace(self::dirSize($from));
        $name = $this->uniqueName($destDir, basename($rel));
        $newRel = self::join($destDir, $name);
        self::copyRecursive($from, $this->abs($newRel, false));
        return $newRel;
    }

    private static function copyRecursive(string $from, string $to): void
    {
        if (is_link($from)) {
            return;
        }
        if (is_dir($from)) {
            if (!@mkdir($to, 0750) && !is_dir($to)) {
                throw new StorageException('Could not create a folder while copying.');
            }
            foreach (new \DirectoryIterator($from) as $f) {
                if (!$f->isDot() && !$f->isLink() && !self::isReserved($f->getFilename())) {
                    self::copyRecursive($f->getPathname(), $to . '/' . $f->getFilename());
                }
            }
            return;
        }
        if (!@copy($from, $to)) {
            throw new StorageException('Could not copy "' . basename($from) . '".');
        }
        @touch($to, (int) filemtime($from));
    }

    /** Move to the trash bin (can be restored). */
    public function delete(string $rel): void
    {
        (new Trash($this))->put($rel);
    }

    /** Remove a file or folder from disk for good (used by the trash and cleanup). */
    public static function removeTree(string $abs): void
    {
        if (is_link($abs) || is_file($abs)) {
            @unlink($abs);
            return;
        }
        if (!is_dir($abs)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($abs, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            if ($f->isDir() && !$f->isLink()) {
                @rmdir($f->getPathname());
            } else {
                @unlink($f->getPathname());
            }
        }
        @rmdir($abs);
    }

    /** Pick a free name in a folder: "photo.jpg" -> "photo (2).jpg". */
    public function uniqueName(string $dirRel, string $name): string
    {
        $dirAbs = $this->abs($dirRel);
        if (!file_exists($dirAbs . '/' . $name)) {
            return $name;
        }
        $ext  = pathinfo($name, PATHINFO_EXTENSION);
        $base = ($ext === '' || str_starts_with($name, '.') && substr_count($name, '.') === 1)
            ? $name : substr($name, 0, -strlen($ext) - 1);
        $ext  = $base === $name ? '' : $ext;
        for ($i = 2; $i < 10000; $i++) {
            $try = $base . " ($i)" . ($ext === '' ? '' : ".$ext");
            if (!file_exists($dirAbs . '/' . $try)) {
                return $try;
            }
        }
        throw new StorageException('Too many files with that name.');
    }

    // ---------- Chunked uploads ----------

    /**
     * Append one chunk to an in-progress upload. When $final is true the file is moved into place.
     * If a file with the same name exists, the old one is kept as a previous version.
     * Returns ['path' => ..., 'replaced' => bool] when complete, otherwise null.
     */
    public function receiveChunk(string $uploadId, int $offset, string $tmpFile, bool $final,
                                 string $dirRel, string $fileName, int $totalSize, bool $replace = true): ?array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/', $uploadId)) {
            throw new StorageException('Invalid upload.');
        }
        self::assertValidName($fileName);
        if (!is_dir($this->abs($dirRel))) {
            throw new StorageException('The destination folder no longer exists.');
        }

        $part = $this->internalDir('uploads') . '/' . $uploadId . '.part';
        $have = is_file($part) ? (int) filesize($part) : 0;
        if ($offset !== $have) {
            throw new StorageException('Upload got out of order. Please try again.', 409);
        }
        if ($offset === 0) {
            $this->assertSpace($totalSize);
        }
        $in  = fopen($tmpFile, 'rb');
        $out = fopen($part, 'ab');
        if (!$in || !$out) {
            throw new StorageException('Could not save the upload.');
        }
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);
        clearstatcache(true, $part);

        if (!$final) {
            return null;
        }
        if ((int) filesize($part) !== $totalSize) {
            @unlink($part);
            throw new StorageException('The upload was incomplete. Please try again.');
        }
        $rel = self::join($dirRel, $fileName);
        $dest = $this->abs($rel, false);
        $replaced = false;
        if ($replace && is_file($dest) && !is_link($dest)) {
            (new Versions($this))->saveCurrent($rel);
            $replaced = true;
        } elseif (file_exists($dest)) {
            $rel = self::join($dirRel, $this->uniqueName($dirRel, $fileName));
            $dest = $this->abs($rel, false);
        }
        if (!@rename($part, $dest)) {
            @unlink($part);
            throw new StorageException('Could not finish the upload.');
        }
        @chmod($dest, 0640);
        return ['path' => $rel, 'replaced' => $replaced];
    }

    public function assertSpace(int $bytes): void
    {
        $free = @disk_free_space($this->root);
        if ($free !== false && $bytes > $free - 50 * 1024 * 1024) {
            throw new StorageException('Not enough free space on the server for this.');
        }
        $quota = (int) (Database::one('SELECT quota_bytes FROM bla_users WHERE id = ?', [$this->userId])['quota_bytes'] ?? 0);
        if ($quota > 0 && $this->usage() + $bytes > $quota) {
            throw new StorageException('This would go over your storage limit.');
        }
    }

    /** Remove abandoned partial uploads and temp files older than a day. */
    public function cleanupTemp(): void
    {
        foreach (['uploads', 'tmp'] as $d) {
            foreach (glob($this->internalDir($d) . '/*') ?: [] as $f) {
                if (is_file($f) && filemtime($f) < time() - 86400) {
                    @unlink($f);
                }
            }
        }
    }
}
