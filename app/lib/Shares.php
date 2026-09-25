<?php
declare(strict_types=1);

namespace BlaCloud;

/**
 * Sharing a file or folder with another account, or through a public link.
 *
 * Permissions:
 *   view    – open, preview and download
 *   upload  – view + add files (links only)
 *   drop    – add files only, can't see what's there ("file request", links to folders only)
 *   edit    – view + add, rename, create folders, delete (deletes go to the owner's trash)
 */
final class Shares
{
    public const PERMS_USER = ['view', 'edit'];
    public const PERMS_LINK = ['view', 'upload', 'drop', 'edit'];
    public const LABELS = [
        'view'   => 'Can view',
        'upload' => 'Can view and upload',
        'drop'   => 'Upload only (file request)',
        'edit'   => 'Can edit',
    ];

    public static function can(string $perms, string $action): bool
    {
        return match ($action) {
            'list', 'view' => in_array($perms, ['view', 'upload', 'edit'], true),
            'upload'       => in_array($perms, ['upload', 'drop', 'edit'], true),
            'edit'         => $perms === 'edit',
            default        => false,
        };
    }

    // ---------- Creating ----------

    public static function shareWithUser(Storage $fs, string $rel, int $recipientId, string $perms): array
    {
        $rel = Storage::normalize($rel);
        if ($rel === '') {
            throw new StorageException('Share a specific folder rather than all your files.');
        }
        $abs = $fs->abs($rel);
        if (!in_array($perms, self::PERMS_USER, true)) {
            throw new StorageException('Unknown permission.');
        }
        if ($recipientId === $fs->userId()) {
            throw new StorageException('That\'s you!');
        }
        $to = Database::one('SELECT * FROM bla_users WHERE id = ? AND is_active = 1', [$recipientId]);
        if (!$to) {
            throw new StorageException('That person was not found.');
        }
        $existing = Database::one("SELECT id FROM bla_shares WHERE owner_id = ? AND path = ? AND share_type = 'user' AND recipient_id = ?",
            [$fs->userId(), $rel, $recipientId]);
        if ($existing) {
            Database::run('UPDATE bla_shares SET perms = ? WHERE id = ?', [$perms, $existing['id']]);
            return [(int) $existing['id'], $to, false];
        }
        $id = Database::insert("INSERT INTO bla_shares (owner_id, path, is_dir, share_type, recipient_id, perms, created_at)
            VALUES (?, ?, ?, 'user', ?, ?, ?)", [$fs->userId(), $rel, is_dir($abs) ? 1 : 0, $recipientId, $perms, Database::now()]);
        return [$id, $to, true];
    }

    /** Create a public link. Returns [id, token]. */
    public static function createLink(Storage $fs, string $rel, string $perms, ?string $password, ?string $expires, string $label): array
    {
        if (!Settings::get('links_enabled')) {
            throw new StorageException('Public links are turned off by the administrator.');
        }
        $rel = Storage::normalize($rel);
        if ($rel === '') {
            throw new StorageException('Share a specific folder rather than all your files.');
        }
        $abs = $fs->abs($rel);
        $isDir = is_dir($abs);
        if (!in_array($perms, self::PERMS_LINK, true) || (!$isDir && $perms !== 'view')) {
            throw new StorageException($isDir ? 'Unknown permission.' : 'Links to a single file are view-only.');
        }
        $password = $password === null ? null : trim($password);
        if ($password === '') {
            $password = null;
        }
        if ($password === null && Settings::get('links_require_password')) {
            throw new StorageException('Links must have a password on this cloud.');
        }
        if ($password !== null && mb_strlen($password) < 6) {
            throw new StorageException('Use a link password of at least 6 characters.');
        }
        $expiresAt = self::parseExpiry($expires);
        $token = Security::token(24);
        $id = Database::insert("INSERT INTO bla_shares (owner_id, path, is_dir, share_type, perms, token_hash, token_enc, password_hash, expires_at, label, created_at)
            VALUES (?, ?, ?, 'link', ?, ?, ?, ?, ?, ?, ?)", [
            $fs->userId(), $rel, $isDir ? 1 : 0, $perms, Tokens::hash($token), Security::encrypt($token),
            $password === null ? null : Security::hashPassword($password), $expiresAt,
            mb_substr(trim($label), 0, 128), Database::now(),
        ]);
        return [$id, $token];
    }

    /** "2026-10-01" -> end of that day (UTC). Enforces the admin's maximum lifetime. */
    public static function parseExpiry(?string $date): ?string
    {
        $max = (int) Settings::get('links_max_days');
        $date = trim((string) $date);
        if ($date === '') {
            if ($max > 0) {
                throw new StorageException("Links must expire within $max days on this cloud. Please pick a date.");
            }
            return null;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date . ' 23:59:59 UTC')) {
            throw new StorageException('Please pick a valid expiry date.');
        }
        $ts = (int) strtotime($date . ' 23:59:59 UTC');
        if ($ts < time()) {
            throw new StorageException('The expiry date is in the past.');
        }
        if ($max > 0 && $ts > time() + ($max + 1) * 86400) {
            throw new StorageException("Links can last at most $max days on this cloud.");
        }
        return gmdate('Y-m-d H:i:s', $ts);
    }

    // ---------- Reading ----------

    public static function linkUrl(array $share): string
    {
        return Settings::absoluteUrl('s', ['t' => Security::decrypt((string) $share['token_enc'])]);
    }

    /** All shares of one item (for the share dialog). */
    public static function forItem(int $ownerId, string $rel): array
    {
        $rows = Database::all('SELECT s.*, u.username, u.display_name FROM bla_shares s
            LEFT JOIN bla_users u ON u.id = s.recipient_id
            WHERE s.owner_id = ? AND s.path = ? ORDER BY s.share_type DESC, s.id', [$ownerId, Storage::normalize($rel)]);
        return array_map([self::class, 'present'], $rows);
    }

    public static function byOwner(int $ownerId): array
    {
        $rows = Database::all('SELECT s.*, u.username, u.display_name FROM bla_shares s
            LEFT JOIN bla_users u ON u.id = s.recipient_id WHERE s.owner_id = ? ORDER BY s.path, s.id', [$ownerId]);
        return array_map([self::class, 'present'], $rows);
    }

    public static function withUser(int $userId): array
    {
        return Database::all("SELECT s.*, o.username AS owner_username, o.display_name AS owner_name FROM bla_shares s
            JOIN bla_users o ON o.id = s.owner_id
            WHERE s.share_type = 'user' AND s.recipient_id = ? AND o.is_active = 1 ORDER BY s.created_at DESC", [$userId]);
    }

    /** Set of paths the owner has shared (to mark them in the file list). */
    public static function sharedPaths(int $ownerId): array
    {
        $out = [];
        foreach (Database::all('SELECT path, share_type FROM bla_shares WHERE owner_id = ?', [$ownerId]) as $r) {
            $out[$r['path']][$r['share_type']] = true;
        }
        return $out;
    }

    private static function present(array $r): array
    {
        $r['expired'] = $r['expires_at'] !== null && strtotime($r['expires_at'] . ' UTC') < time();
        $r['has_password'] = $r['password_hash'] !== null;
        $r['url'] = $r['share_type'] === 'link' ? self::linkUrl($r) : null;
        unset($r['password_hash'], $r['token_hash'], $r['token_enc']);
        return $r;
    }

    public static function delete(int $ownerId, int $id): ?array
    {
        $row = Database::one('SELECT * FROM bla_shares WHERE id = ? AND owner_id = ?', [$id, $ownerId]);
        if ($row) {
            Database::run('DELETE FROM bla_shares WHERE id = ?', [$id]);
        }
        return $row;
    }

    /** Recipients can remove a share from their own list. */
    public static function leave(int $recipientId, int $id): bool
    {
        return Database::run("DELETE FROM bla_shares WHERE id = ? AND share_type = 'user' AND recipient_id = ?", [$id, $recipientId])->rowCount() === 1;
    }

    // ---------- Keeping shares in step with the owner's files ----------

    public static function movePrefix(int $ownerId, string $old, string $new): void
    {
        foreach (Database::all('SELECT id, path FROM bla_shares WHERE owner_id = ?', [$ownerId]) as $r) {
            if (Storage::isWithin($r['path'], $old)) {
                Database::run('UPDATE bla_shares SET path = ? WHERE id = ?', [$new . substr($r['path'], strlen($old)), $r['id']]);
            }
        }
    }

    /** When something is deleted, its shares (and shares inside it) stop. */
    public static function removeUnder(int $ownerId, string $rel): void
    {
        foreach (Database::all('SELECT id, path FROM bla_shares WHERE owner_id = ?', [$ownerId]) as $r) {
            if (Storage::isWithin($r['path'], $rel)) {
                Database::run('DELETE FROM bla_shares WHERE id = ?', [$r['id']]);
            }
        }
    }

    // ---------- Resolving a share for a visitor ----------

    /** A user-share visible to $userId, or null. */
    public static function findForRecipient(int $id, int $userId): ?array
    {
        return Database::one("SELECT s.*, o.username AS owner_username, o.display_name AS owner_name FROM bla_shares s
            JOIN bla_users o ON o.id = s.owner_id
            WHERE s.id = ? AND s.share_type = 'user' AND s.recipient_id = ? AND o.is_active = 1", [$id, $userId]);
    }

    /** A live public link for a token, or null (expired, disabled owner, links turned off). */
    public static function findLink(string $token): ?array
    {
        if (!Settings::get('links_enabled') || !preg_match('/^[A-Za-z0-9_-]{20,64}$/', $token)) {
            return null;
        }
        $r = Database::one("SELECT s.*, o.username AS owner_username, o.display_name AS owner_name FROM bla_shares s
            JOIN bla_users o ON o.id = s.owner_id
            WHERE s.token_hash = ? AND s.share_type = 'link' AND o.is_active = 1", [Tokens::hash($token)]);
        if (!$r || ($r['expires_at'] !== null && strtotime($r['expires_at'] . ' UTC') < time())) {
            return null;
        }
        return $r;
    }

    public static function touch(int $id): void
    {
        Database::run('UPDATE bla_shares SET access_count = access_count + 1, last_access_at = ? WHERE id = ?', [Database::now(), $id]);
    }
}
