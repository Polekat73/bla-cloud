<?php
declare(strict_types=1);

namespace BlaCloud\Apps\Projects;

use BlaCloud\Audit;
use BlaCloud\Database;
use BlaCloud\StorageException;

/**
 * Topic channels and messages within a project. Channel membership is deliberately separate from
 * project membership: joining a project doesn't put you in every channel, so a project can have a
 * private topic without every member seeing it. An existing channel member invites another project
 * member in; only the channel's creator or the project owner can remove someone from it.
 *
 * No message editing, attachments, or read receipts in v1 — see docs/ROADMAP.md.
 */
final class ChannelsData
{
    public static function forProject(int $projectId): array
    {
        return Database::all('SELECT * FROM bla_project_channels WHERE project_id = ? ORDER BY id', [$projectId]);
    }

    public static function channel(int $channelId): ?array
    {
        return Database::one('SELECT * FROM bla_project_channels WHERE id = ?', [$channelId]);
    }

    public static function isMember(int $userId, int $channelId): bool
    {
        return (bool) Database::one('SELECT 1 FROM bla_channel_members WHERE channel_id = ? AND user_id = ?', [$channelId, $userId]);
    }

    /** Returns the channel row if $userId belongs to it, else throws. */
    public static function requireChannelMember(int $userId, int $channelId): array
    {
        $channel = self::channel($channelId);
        if (!$channel || !self::isMember($userId, $channelId)) {
            throw new StorageException('That channel does not exist, or you are not a member of it.', 404);
        }
        return $channel;
    }

    public static function createChannel(int $userId, int $projectId, string $name): int
    {
        ProjectsData::requireMember($userId, $projectId);
        $name = mb_substr(trim($name), 0, 64);
        if ($name === '') {
            throw new StorageException('Please enter a channel name.');
        }
        $now = Database::now();
        $id = Database::insert('INSERT INTO bla_project_channels (project_id, name, created_by, created_at) VALUES (?, ?, ?, ?)',
            [$projectId, $name, $userId, $now]);
        Database::run('INSERT INTO bla_channel_members (channel_id, user_id, added_at) VALUES (?, ?, ?)', [$id, $userId, $now]);
        Audit::log($userId, 'channel.created', $name);
        return $id;
    }

    /** Channel + project row, requiring only that the caller is a PROJECT member — not necessarily a
     *  channel member, since the project owner can moderate a channel they never personally joined. */
    private static function requireProjectMemberForChannel(int $userId, int $channelId): array
    {
        $channel = self::channel($channelId);
        if (!$channel) {
            throw new StorageException('That channel does not exist.', 404);
        }
        $project = ProjectsData::requireMember($userId, (int) $channel['project_id']);
        return [$channel, $project];
    }

    public static function deleteChannel(int $userId, int $channelId): void
    {
        [$channel, $project] = self::requireProjectMemberForChannel($userId, $channelId);
        if ((int) $channel['created_by'] !== $userId && (int) $project['owner_id'] !== $userId) {
            throw new StorageException('Only the channel creator or the project owner can delete it.', 403);
        }
        Database::run('DELETE FROM bla_project_channels WHERE id = ?', [$channelId]);
        Audit::log($userId, 'channel.deleted', $channel['name']);
    }

    public static function members(int $channelId): array
    {
        return Database::all(
            'SELECT u.id, u.username, u.display_name FROM bla_channel_members m
             JOIN bla_users u ON u.id = m.user_id WHERE m.channel_id = ? ORDER BY LOWER(u.display_name), LOWER(u.username)',
            [$channelId]
        );
    }

    public static function addMember(int $userId, int $channelId, string $username): void
    {
        $channel = self::requireChannelMember($userId, $channelId);
        $target = Database::one('SELECT id, username FROM bla_users WHERE LOWER(username) = LOWER(?) AND is_active = 1', [$username]);
        if (!$target) {
            throw new StorageException('No active person with that username.');
        }
        if (!ProjectsData::isMember((int) $target['id'], (int) $channel['project_id'])) {
            throw new StorageException('That person must be a project member before they can join a channel.');
        }
        if (self::isMember((int) $target['id'], $channelId)) {
            throw new StorageException('That person is already in this channel.');
        }
        Database::run('INSERT INTO bla_channel_members (channel_id, user_id, added_at) VALUES (?, ?, ?)',
            [$channelId, $target['id'], Database::now()]);
        Audit::log($userId, 'channel.member_added', $channel['name'] . ': ' . $target['username']);
    }

    public static function removeMember(int $userId, int $channelId, int $memberId): void
    {
        [$channel, $project] = self::requireProjectMemberForChannel($userId, $channelId);
        if ((int) $channel['created_by'] !== $userId && (int) $project['owner_id'] !== $userId) {
            throw new StorageException('Only the channel creator or the project owner can remove someone.', 403);
        }
        if ($memberId === (int) $channel['created_by']) {
            throw new StorageException('The channel creator cannot be removed.');
        }
        if (!self::isMember($memberId, $channelId)) {
            throw new StorageException('That person is not in this channel.');
        }
        Database::run('DELETE FROM bla_channel_members WHERE channel_id = ? AND user_id = ?', [$channelId, $memberId]);
        Audit::log($userId, 'channel.member_removed');
    }

    public static function leaveChannel(int $userId, int $channelId): void
    {
        $channel = self::requireChannelMember($userId, $channelId);
        if ((int) $channel['created_by'] === $userId) {
            throw new StorageException('The channel creator cannot leave — delete the channel instead.');
        }
        Database::run('DELETE FROM bla_channel_members WHERE channel_id = ? AND user_id = ?', [$channelId, $userId]);
    }

    // ---------- Messages ----------

    public static function postMessage(int $userId, int $channelId, string $body): int
    {
        self::requireChannelMember($userId, $channelId);
        $body = trim($body);
        if ($body === '') {
            throw new StorageException('Please enter a message.');
        }
        return Database::insert('INSERT INTO bla_channel_messages (channel_id, user_id, body, created_at) VALUES (?, ?, ?, ?)',
            [$channelId, $userId, mb_substr($body, 0, 8000), Database::now()]);
    }

    /** Messages with id > $sinceId, oldest first — for both the initial load and the poll loop. */
    public static function messagesSince(int $userId, int $channelId, int $sinceId = 0, int $limit = 500): array
    {
        self::requireChannelMember($userId, $channelId);
        return Database::all(
            'SELECT m.*, u.username, u.display_name FROM bla_channel_messages m
             JOIN bla_users u ON u.id = m.user_id WHERE m.channel_id = ? AND m.id > ? ORDER BY m.id LIMIT ' . (int) $limit,
            [$channelId, $sinceId]
        );
    }

    public static function deleteMessage(int $userId, int $messageId): void
    {
        $msg = Database::one('SELECT * FROM bla_channel_messages WHERE id = ?', [$messageId]);
        if (!$msg) {
            return;
        }
        [, $project] = self::requireProjectMemberForChannel($userId, (int) $msg['channel_id']);
        if ((int) $msg['user_id'] !== $userId && (int) $project['owner_id'] !== $userId) {
            throw new StorageException('You can only delete your own messages.', 403);
        }
        Database::run('DELETE FROM bla_channel_messages WHERE id = ?', [$messageId]);
    }
}
