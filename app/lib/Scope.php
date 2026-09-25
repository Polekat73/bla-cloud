<?php
declare(strict_types=1);

namespace BlaCloud;

/**
 * What the current visitor is looking at: their own files, a folder someone shared with them,
 * or a public link. Paths from the browser ("sub-paths") are always relative to the scope's root,
 * and are translated to the owner's real paths here, so a share can never reach outside itself.
 */
final class Scope
{
    private function __construct(
        public readonly string $kind,         // own | user | link
        public readonly Storage $fs,          // the OWNER's storage
        public readonly string $base,         // owner path of the shared item ('' for own files)
        public readonly bool $isFile,         // the shared item is a single file
        public readonly string $perms,        // 'all' for own, else view/upload/drop/edit
        public readonly array $params,        // extra URL params (share id or link token)
        public readonly ?array $share,
        public readonly int $actorId,         // who to record in the activity log
        public readonly string $rootName,
    ) {
    }

    public static function own(array $user): self
    {
        return new self('own', new Storage((int) $user['id']), '', false, 'all', [], null, (int) $user['id'], 'My files');
    }

    public static function forShare(array $share, array $viewer): self
    {
        return new self('user', new Storage((int) $share['owner_id']), $share['path'], !(int) $share['is_dir'], $share['perms'],
            ['share' => (int) $share['id']], $share, (int) $viewer['id'], basename($share['path']));
    }

    public static function forLink(array $share, string $token): self
    {
        return new self('link', new Storage((int) $share['owner_id']), $share['path'], !(int) $share['is_dir'], $share['perms'],
            ['t' => $token], $share, (int) $share['owner_id'], basename($share['path']));
    }

    public function isOwn(): bool
    {
        return $this->kind === 'own';
    }

    public function can(string $action): bool
    {
        return $this->isOwn() || Shares::can($this->perms, $action);
    }

    public function require(string $action): void
    {
        if (!$this->can($action)) {
            throw new StorageException('You don\'t have permission to do that here.', 403);
        }
    }

    /** Browser sub-path -> owner's real relative path (throws if it would leave the share). */
    public function toOwner(string $sub): string
    {
        $sub = Storage::normalize($sub);
        if ($this->isFile) {
            if ($sub !== '') {
                throw new StorageException('Invalid path.');
            }
            return $this->base;
        }
        $rel = Storage::normalize($this->base . $sub);
        if (!Storage::isWithin($rel, $this->base)) {
            throw new StorageException('Invalid path.');
        }
        return $rel;
    }

    public function toSub(string $ownerRel): string
    {
        if ($this->isFile) {
            return '';
        }
        return $this->base === '' ? $ownerRel : (string) substr($ownerRel, strlen($this->base));
    }

    /** List a folder in this scope, with paths rewritten to sub-paths. */
    public function list(string $sub): array
    {
        if ($this->isFile) {
            $abs = $this->fs->abs($this->base);
            return [[
                'name' => basename($this->base), 'path' => '', 'dir' => false, 'size' => (int) filesize($abs),
                'mtime' => (int) filemtime($abs), 'type' => Storage::kind(basename($this->base)),
            ]];
        }
        $items = $this->fs->list($this->toOwner($sub));
        foreach ($items as &$it) {
            $it['path'] = $this->toSub($it['path']);
        }
        return $items;
    }

    public function search(string $q): array
    {
        if ($this->isFile) {
            return [];
        }
        $items = $this->fs->search($q, 200, $this->base);
        $out = [];
        foreach ($items as $it) {
            if (Storage::isWithin($it['path'], $this->base) && $it['path'] !== $this->base) {
                $it['path'] = $this->toSub($it['path']);
                $out[] = $it;
            }
        }
        return $out;
    }

    /** A short note for the activity log, e.g. "(shared by anna)". */
    public function logNote(): string
    {
        return match ($this->kind) {
            'user' => ' (in ' . ($this->share['owner_username'] ?? '?') . '\'s share)',
            'link' => ' (via public link)',
            default => '',
        };
    }
}
