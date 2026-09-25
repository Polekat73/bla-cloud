<?php
declare(strict_types=1);

namespace BlaCloud\Dav;

use BlaCloud\Controllers\DavController;
use BlaCloud\Database;
use BlaCloud\Storage;
use BlaCloud\StorageException;
use BlaCloud\Versions;

/**
 * WebDAV for a user's files, mounted at /dav/files/{username}/... — mirrors what the
 * web file manager can already do (Storage/Versions/Trash), just over the WebDAV verbs.
 *
 * Locking (LOCK/UNLOCK) is implemented so Windows Explorer and macOS Finder allow saving,
 * but tokens aren't actually enforced on writes: every device authenticates as the same
 * person via their own app password, so a real lock conflict would only ever be between
 * that person's own devices.
 */
final class FilesBackend
{
    private Storage $fs;
    private string $username;
    private string $baseHref;

    public function __construct(private array $user)
    {
        $this->username = $user['username'];
    }

    public function handle(string $method, array $segments): void
    {
        $who = array_shift($segments) ?? '';
        if ($who === '' || strcasecmp($who, $this->username) !== 0) {
            http_response_code(403);
            return;
        }
        $this->fs = new Storage((int) $this->user['id']);
        $this->baseHref = DavController::baseHref() . '/files/' . rawurlencode($this->username);
        $rel = Storage::normalize(implode('/', $segments));

        match ($method) {
            'PROPFIND'  => $this->propfind($rel),
            'GET', 'HEAD' => $this->get($rel, $method === 'HEAD'),
            'PUT'       => $this->put($rel),
            'DELETE'    => $this->delete($rel),
            'MKCOL'     => $this->mkcol($rel),
            'MOVE'      => $this->moveOrCopy($rel, true),
            'COPY'      => $this->moveOrCopy($rel, false),
            'LOCK'      => $this->lock($rel),
            'UNLOCK'    => $this->unlock($rel),
            'PROPPATCH' => $this->proppatch($rel),
            default     => http_response_code(405),
        };
    }

    // ---------- PROPFIND ----------

    private function propfind(string $rel): void
    {
        $depth = $_SERVER['HTTP_DEPTH'] ?? '1';
        if ($depth === 'infinity') {
            http_response_code(403);
            echo "Depth: infinity is not supported here.\n";
            return;
        }
        try {
            $abs = $this->fs->abs($rel);
        } catch (StorageException) {
            http_response_code(404);
            return;
        }
        $isDir = is_dir($abs);
        $requested = Xml::propfindProps(Xml::body());

        $ms = new Xml();
        $this->addResource($ms, $rel, $abs, $isDir, $requested);
        if ($isDir && $depth !== '0') {
            foreach ($this->fs->list($rel) as $item) {
                $this->addResource($ms, $item['path'], $this->fs->abs($item['path']), $item['dir'], $requested);
            }
        }
        $ms->send();
    }

    private function addResource(Xml $ms, string $rel, string $abs, bool $isDir, ?array $requested): void
    {
        $href = $this->hrefFor($rel, $isDir);
        $all = $this->allProps($rel, $abs, $isDir);
        if ($requested === null) {
            $ms->addFound($href, $all);
            return;
        }
        $found = [];
        $missing = [];
        foreach ($requested as $name) {
            if (array_key_exists($name, $all)) {
                $found[$name] = $all[$name];
            } else {
                $missing[] = $name;
            }
        }
        $ms->addFoundMissing($href, $found, $missing);
    }

    private function allProps(string $rel, string $abs, bool $isDir): array
    {
        $props = [
            '{DAV:}resourcetype'    => $isDir ? new Raw('<d:collection/>') : new Raw(''),
            '{DAV:}displayname'     => $rel === '' ? $this->username : basename($rel),
            '{DAV:}getlastmodified' => gmdate('D, d M Y H:i:s', filemtime($abs)) . ' GMT',
            '{DAV:}creationdate'    => gmdate('Y-m-d\TH:i:s\Z', filemtime($abs)),
        ];
        if ($isDir) {
            $props['{DAV:}supported-report-set'] = new Raw('');
        } else {
            $size = (int) filesize($abs);
            $props['{DAV:}getcontentlength'] = (string) $size;
            $props['{DAV:}getcontenttype']   = self::mimeType($abs);
            $props['{DAV:}getetag']          = self::etag($abs);
        }
        if ($rel === '') {
            $used  = $this->fs->usage();
            $quota = (int) ($this->user['quota_bytes'] ?? 0);
            $props['{DAV:}quota-used-bytes']      = (string) $used;
            $props['{DAV:}quota-available-bytes'] = (string) ($quota > 0 ? max(0, $quota - $used) : 1_000_000_000_000);
        }
        return $props;
    }

    private function hrefFor(string $rel, bool $isDir): string
    {
        $encoded = $rel === '' ? '' : implode('/', array_map('rawurlencode', explode('/', ltrim($rel, '/'))));
        return $this->baseHref . ($encoded === '' ? '/' : '/' . $encoded . ($isDir ? '/' : ''));
    }

    private static function etag(string $abs): string
    {
        return '"' . md5(filemtime($abs) . ':' . filesize($abs)) . '"';
    }

    private static function mimeType(string $abs): string
    {
        return @mime_content_type($abs) ?: 'application/octet-stream';
    }

    // ---------- GET / HEAD ----------

    private function get(string $rel, bool $headOnly): void
    {
        try {
            $abs = $this->fs->abs($rel);
        } catch (StorageException) {
            http_response_code(404);
            return;
        }
        if (is_dir($abs)) {
            http_response_code(404);
            return;
        }
        $etag = self::etag($abs);
        header('ETag: ' . $etag);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', filemtime($abs)) . ' GMT');
        header('Content-Type: ' . self::mimeType($abs));
        header('Accept-Ranges: bytes');
        if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
            http_response_code(304);
            return;
        }
        header('Content-Length: ' . filesize($abs));
        http_response_code(200);
        if (!$headOnly) {
            readfile($abs);
        }
    }

    // ---------- PUT ----------

    private function put(string $rel): void
    {
        if ($rel === '') {
            http_response_code(405);
            return;
        }
        try {
            $parentAbs = $this->fs->abs(Storage::parent($rel));
        } catch (StorageException) {
            http_response_code(409);
            echo "The parent folder does not exist.\n";
            return;
        }
        if (!is_dir($parentAbs)) {
            http_response_code(409);
            return;
        }

        $existingAbs = $parentAbs . '/' . basename($rel);
        $exists = file_exists($existingAbs);
        if ($exists && is_dir($existingAbs)) {
            http_response_code(409);
            echo "A folder already exists with that name.\n";
            return;
        }
        if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === '*' && $exists) {
            http_response_code(412);
            return;
        }
        $ifMatch = $_SERVER['HTTP_IF_MATCH'] ?? '';
        if ($ifMatch !== '' && $exists && $ifMatch !== self::etag($existingAbs)) {
            http_response_code(412);
            return;
        }

        $len = (int) ($_SERVER['CONTENT_LENGTH'] ?? -1);
        try {
            if ($len >= 0) {
                $this->fs->assertSpace($len);
            }
        } catch (StorageException $e) {
            http_response_code(507);
            echo $e->getMessage() . "\n";
            return;
        }

        $tmp = $this->fs->internalDir('uploads') . '/' . bin2hex(random_bytes(16)) . '.davput';
        $in = fopen('php://input', 'rb');
        $out = fopen($tmp, 'wb');
        if (!$in || !$out) {
            http_response_code(500);
            return;
        }
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);

        if ($exists) {
            (new Versions($this->fs))->saveCurrent($rel);
        }
        $dest = $this->fs->abs($rel, false);
        if (!@rename($tmp, $dest)) {
            @unlink($tmp);
            http_response_code(500);
            echo "Could not save the file.\n";
            return;
        }
        @chmod($dest, 0640);
        clearstatcache(true, $dest);
        header('ETag: ' . self::etag($dest));
        http_response_code($exists ? 204 : 201);
    }

    // ---------- DELETE / MKCOL ----------

    private function delete(string $rel): void
    {
        if ($rel === '') {
            http_response_code(403);
            return;
        }
        try {
            $this->fs->abs($rel);
        } catch (StorageException) {
            http_response_code(404);
            return;
        }
        $this->fs->delete($rel); // to the trash, like the file manager
        http_response_code(204);
    }

    private function mkcol(string $rel): void
    {
        if ($rel === '') {
            http_response_code(405);
            return;
        }
        try {
            $parentAbs = $this->fs->abs(Storage::parent($rel));
        } catch (StorageException) {
            http_response_code(409);
            return;
        }
        if (!is_dir($parentAbs)) {
            http_response_code(409);
            return;
        }
        if (file_exists($parentAbs . '/' . basename($rel))) {
            http_response_code(405);
            return;
        }
        try {
            $this->fs->mkdir(Storage::parent($rel), basename($rel));
        } catch (StorageException $e) {
            http_response_code(500);
            echo $e->getMessage() . "\n";
            return;
        }
        http_response_code(201);
    }

    // ---------- MOVE / COPY ----------

    private function destinationRel(): ?string
    {
        $dest = $_SERVER['HTTP_DESTINATION'] ?? '';
        if ($dest === '') {
            return null;
        }
        $path = (string) parse_url($dest, PHP_URL_PATH);
        $prefix = $this->baseHref;
        if (!str_starts_with($path, $prefix)) {
            return null; // cross-server or wrong user: not supported
        }
        $rel = substr($path, strlen($prefix));
        $rel = implode('/', array_map('rawurldecode', array_filter(explode('/', $rel), fn ($s) => $s !== '')));
        try {
            return Storage::normalize($rel);
        } catch (StorageException) {
            return null;
        }
    }

    private function moveOrCopy(string $rel, bool $isMove): void
    {
        if ($rel === '') {
            http_response_code(403);
            return;
        }
        $destRel = $this->destinationRel();
        if ($destRel === null || $destRel === '') {
            http_response_code(502);
            echo "Bad or unsupported Destination header.\n";
            return;
        }
        try {
            $this->fs->abs($rel);
        } catch (StorageException) {
            http_response_code(404);
            return;
        }
        $destDir = Storage::parent($destRel);
        $destName = basename($destRel);
        $overwrite = ($_SERVER['HTTP_OVERWRITE'] ?? 'T') !== 'F';

        try {
            $destParentAbs = $this->fs->abs($destDir);
        } catch (StorageException) {
            http_response_code(409);
            return;
        }
        if (!is_dir($destParentAbs)) {
            http_response_code(409);
            return;
        }
        $targetAbs = $destParentAbs . '/' . $destName;
        $existed = file_exists($targetAbs);
        if ($existed && !$overwrite) {
            http_response_code(412);
            return;
        }
        if ($existed) {
            Storage::removeTree($targetAbs); // WebDAV overwrite replaces outright, unlike the trash-based delete
        }

        try {
            if (Storage::parent($rel) === $destDir) {
                $this->fs->rename($rel, $destName);
            } else {
                $newRel = $isMove ? $this->fs->move($rel, $destDir) : $this->fs->copy($rel, $destDir);
                if (basename($newRel) !== $destName) {
                    $this->fs->rename($newRel, $destName);
                }
            }
        } catch (StorageException $e) {
            http_response_code(500);
            echo $e->getMessage() . "\n";
            return;
        }
        http_response_code($existed ? 204 : 201);
    }

    // ---------- LOCK / UNLOCK (minimal, see class docblock) ----------

    private function lock(string $rel): void
    {
        $owner = '';
        $body = Xml::body();
        if ($body) {
            $ownerNode = $body->getElementsByTagNameNS(Xml::NS_DAV, 'owner')->item(0);
            $owner = $ownerNode ? trim($ownerNode->textContent) : '';
        }

        // Refreshing an existing lock: no body, an If header naming the token.
        $ifHeader = $_SERVER['HTTP_IF'] ?? '';
        if ($body === null && preg_match('/<(urn:uuid:[0-9a-f-]+)>/', $ifHeader, $m)) {
            $row = Database::one('SELECT * FROM bla_dav_locks WHERE token = ? AND user_id = ?', [$m[1], $this->user['id']]);
            if ($row) {
                Database::run('UPDATE bla_dav_locks SET expires_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s', time() + 300), $row['id']]);
                $this->sendLockResponse($m[1], $row['owner']);
                return;
            }
        }

        $existsAlready = true;
        try {
            $this->fs->abs($rel);
        } catch (StorageException) {
            $existsAlready = false; // "lock-null" resource: locking ahead of a PUT is allowed
        }
        $conflict = Database::one('SELECT id FROM bla_dav_locks WHERE user_id = ? AND path = ? AND expires_at > ?',
            [$this->user['id'], $rel, Database::now()]);
        if ($conflict) {
            http_response_code(423);
            return;
        }
        $token = self::newToken();
        Database::run('INSERT INTO bla_dav_locks (user_id, path, token, owner, depth, expires_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$this->user['id'], $rel, $token, $owner, $_SERVER['HTTP_DEPTH'] ?? '0', gmdate('Y-m-d H:i:s', time() + 300), Database::now()]);
        $this->sendLockResponse($token, $owner, $existsAlready ? 200 : 201);
    }

    private function unlock(string $rel): void
    {
        $token = trim($_SERVER['HTTP_LOCK_TOKEN'] ?? '', "<> \t");
        if ($token === '') {
            http_response_code(400);
            return;
        }
        Database::run('DELETE FROM bla_dav_locks WHERE user_id = ? AND token = ?', [$this->user['id'], $token]);
        http_response_code(204);
    }

    private static function newToken(): string
    {
        $d = random_bytes(16);
        $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
        $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
        $hex = bin2hex($d);
        return 'urn:uuid:' . implode('-', [
            substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12),
        ]);
    }

    private function sendLockResponse(string $token, string $owner, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/xml; charset=utf-8');
        header('Lock-Token: <' . $token . '>');
        echo '<?xml version="1.0" encoding="utf-8"?>' . "\n"
           . '<d:prop xmlns:d="DAV:"><d:lockdiscovery><d:activelock>'
           . '<d:locktype><d:write/></d:locktype><d:lockscope><d:exclusive/></d:lockscope><d:depth>0</d:depth>'
           . ($owner !== '' ? '<d:owner>' . Xml::e($owner) . '</d:owner>' : '')
           . '<d:timeout>Second-300</d:timeout><d:locktoken><d:href>' . Xml::e($token) . '</d:href></d:locktoken>'
           . '</d:activelock></d:lockdiscovery></d:prop>';
    }

    // ---------- PROPPATCH ----------

    /** We don't store custom dead properties, but clients expect a polite 200 for whatever they tried to set. */
    private function proppatch(string $rel): void
    {
        try {
            $abs = $this->fs->abs($rel);
        } catch (StorageException) {
            http_response_code(404);
            return;
        }
        $names = [];
        $doc = Xml::body();
        if ($doc) {
            foreach ($doc->getElementsByTagNameNS(Xml::NS_DAV, 'prop') as $propNode) {
                foreach ($propNode->childNodes as $node) {
                    if ($node instanceof \DOMElement) {
                        $names[] = '{' . $node->namespaceURI . '}' . $node->localName;
                    }
                }
            }
        }
        $ms = new Xml();
        $found = [];
        foreach ($names as $n) {
            $found[$n] = new Raw('');
        }
        $ms->addFound($this->hrefFor($rel, is_dir($abs)), $found);
        $ms->send();
    }
}
