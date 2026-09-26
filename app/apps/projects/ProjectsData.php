<?php
declare(strict_types=1);

namespace BlaCloud\Apps\Projects;

use BlaCloud\Audit;
use BlaCloud\Database;
use BlaCloud\StorageException;

/**
 * Data access + authorization for the Projects app. Used by both ProjectsController (the web UI)
 * and the MCP tools (app/lib/Mcp/Tools.php), so the access rules only live in one place.
 *
 * Any project member can create/edit/move/delete tasks, manage columns, and comment. Only the
 * owner can rename or delete the project itself, or add/remove members — a deliberate scope cut to
 * keep the permission model simple for v1 (no per-member roles beyond "owner" and "member").
 */
final class ProjectsData
{
    private const DEFAULT_COLUMNS = ['To do', 'In progress', 'Done'];

    // ---------- Access checks ----------

    public static function isMember(int $userId, int $projectId): bool
    {
        return (bool) Database::one(
            'SELECT 1 FROM bla_projects p LEFT JOIN bla_project_members m ON m.project_id = p.id AND m.user_id = ?
             WHERE p.id = ? AND (p.owner_id = ? OR m.user_id IS NOT NULL)',
            [$userId, $projectId, $userId]
        );
    }

    /** Returns the project row if $userId is a member (or owner), else throws. */
    public static function requireMember(int $userId, int $projectId): array
    {
        $project = Database::one('SELECT * FROM bla_projects WHERE id = ?', [$projectId]);
        if (!$project || !self::isMember($userId, $projectId)) {
            throw new StorageException('That project does not exist, or you are not a member of it.', 404);
        }
        return $project;
    }

    /** Returns the project row if $userId owns it, else throws. */
    public static function requireOwner(int $userId, int $projectId): array
    {
        $project = self::requireMember($userId, $projectId);
        if ((int) $project['owner_id'] !== $userId) {
            throw new StorageException('Only the project owner can do that.', 403);
        }
        return $project;
    }

    /** Message deliberately matches requireMember()'s wording — a caller shouldn't be able to tell
     *  "this column doesn't exist" apart from "it exists, but you can't see it" (see the note on
     *  requireMember() above; the same enumeration risk applies to task/column ids). */
    private static function projectIdOfColumn(int $columnId): int
    {
        $row = Database::one('SELECT project_id FROM bla_project_columns WHERE id = ?', [$columnId]);
        if (!$row) {
            throw new StorageException('That column does not exist, or you are not a member of its project.', 404);
        }
        return (int) $row['project_id'];
    }

    /** Message deliberately matches requireMember()'s wording — see the note on projectIdOfColumn(). */
    public static function projectIdOfTask(int $taskId): int
    {
        $row = Database::one('SELECT project_id FROM bla_project_tasks WHERE id = ?', [$taskId]);
        if (!$row) {
            throw new StorageException('That task does not exist, or you are not a member of its project.', 404);
        }
        return (int) $row['project_id'];
    }

    // ---------- Projects ----------

    public static function forUser(int $userId): array
    {
        return Database::all(
            'SELECT p.*, (p.owner_id = ?) AS is_owner,
                    (SELECT COUNT(*) FROM bla_project_tasks t WHERE t.project_id = p.id) AS task_count
             FROM bla_projects p LEFT JOIN bla_project_members m ON m.project_id = p.id AND m.user_id = ?
             WHERE p.owner_id = ? OR m.user_id IS NOT NULL
             ORDER BY p.created_at DESC',
            [$userId, $userId, $userId]
        );
    }

    /** Creates a project, adds the owner as a member, and seeds the default columns. Returns the new id. */
    public static function create(int $ownerId, string $name, string $description = ''): int
    {
        $name = mb_substr(trim($name), 0, 128);
        if ($name === '') {
            throw new StorageException('Please enter a project name.');
        }
        $now = Database::now();
        $id = Database::insert('INSERT INTO bla_projects (owner_id, name, description, created_at) VALUES (?, ?, ?, ?)',
            [$ownerId, $name, mb_substr(trim($description), 0, 2000), $now]);
        Database::run('INSERT INTO bla_project_members (project_id, user_id, added_at) VALUES (?, ?, ?)', [$id, $ownerId, $now]);
        foreach (self::DEFAULT_COLUMNS as $i => $colName) {
            Database::run('INSERT INTO bla_project_columns (project_id, name, position, created_at) VALUES (?, ?, ?, ?)',
                [$id, $colName, $i, $now]);
        }
        ChannelsData::createChannel($ownerId, $id, 'General');
        Audit::log($ownerId, 'project.created', $name);
        return $id;
    }

    public static function delete(int $userId, int $projectId): void
    {
        $project = self::requireOwner($userId, $projectId);
        Database::run('DELETE FROM bla_projects WHERE id = ?', [$projectId]);
        Audit::log($userId, 'project.deleted', $project['name']);
    }

    // ---------- Members ----------

    public static function members(int $projectId): array
    {
        return Database::all(
            'SELECT u.id, u.username, u.display_name FROM bla_project_members m
             JOIN bla_users u ON u.id = m.user_id WHERE m.project_id = ? ORDER BY LOWER(u.display_name), LOWER(u.username)',
            [$projectId]
        );
    }

    public static function addMember(int $userId, int $projectId, string $username): void
    {
        self::requireOwner($userId, $projectId);
        $target = Database::one('SELECT id, username FROM bla_users WHERE LOWER(username) = LOWER(?) AND is_active = 1', [$username]);
        if (!$target) {
            throw new StorageException('No active person with that username.');
        }
        if (Database::one('SELECT 1 FROM bla_project_members WHERE project_id = ? AND user_id = ?', [$projectId, $target['id']])) {
            throw new StorageException('That person is already a member.');
        }
        Database::run('INSERT INTO bla_project_members (project_id, user_id, added_at) VALUES (?, ?, ?)',
            [$projectId, $target['id'], Database::now()]);
        Audit::log($userId, 'project.member_added', $target['username']);
    }

    public static function removeMember(int $userId, int $projectId, int $memberId): void
    {
        $project = self::requireOwner($userId, $projectId);
        if ($memberId === (int) $project['owner_id']) {
            throw new StorageException('The owner cannot be removed from their own project.');
        }
        Database::run('DELETE FROM bla_project_members WHERE project_id = ? AND user_id = ?', [$projectId, $memberId]);
        Database::run('UPDATE bla_project_tasks SET assignee_id = NULL WHERE project_id = ? AND assignee_id = ?', [$projectId, $memberId]);
        Audit::log($userId, 'project.member_removed');
    }

    // ---------- Columns ----------

    public static function columns(int $projectId): array
    {
        return Database::all('SELECT * FROM bla_project_columns WHERE project_id = ? ORDER BY position, id', [$projectId]);
    }

    public static function createColumn(int $userId, int $projectId, string $name): int
    {
        self::requireMember($userId, $projectId);
        $name = mb_substr(trim($name), 0, 64);
        if ($name === '') {
            throw new StorageException('Please enter a column name.');
        }
        $next = (int) (Database::one('SELECT COALESCE(MAX(position), -1) + 1 AS n FROM bla_project_columns WHERE project_id = ?', [$projectId])['n']);
        return Database::insert('INSERT INTO bla_project_columns (project_id, name, position, created_at) VALUES (?, ?, ?, ?)',
            [$projectId, $name, $next, Database::now()]);
    }

    public static function renameColumn(int $userId, int $columnId, string $name): void
    {
        $projectId = self::projectIdOfColumn($columnId);
        self::requireMember($userId, $projectId);
        $name = mb_substr(trim($name), 0, 64);
        if ($name === '') {
            throw new StorageException('Please enter a column name.');
        }
        Database::run('UPDATE bla_project_columns SET name = ? WHERE id = ?', [$name, $columnId]);
    }

    public static function deleteColumn(int $userId, int $columnId): void
    {
        $projectId = self::projectIdOfColumn($columnId);
        self::requireMember($userId, $projectId);
        if ((int) Database::one('SELECT COUNT(*) AS n FROM bla_project_columns WHERE project_id = ?', [$projectId])['n'] <= 1) {
            throw new StorageException('A project needs at least one column.');
        }
        if (Database::one('SELECT 1 FROM bla_project_tasks WHERE column_id = ?', [$columnId])) {
            throw new StorageException('Move or delete the tasks in this column first.');
        }
        Database::run('DELETE FROM bla_project_columns WHERE id = ?', [$columnId]);
    }

    // ---------- Tasks ----------

    /** All tasks in a project, ordered for board rendering, with the assignee's name attached. */
    public static function tasks(int $projectId): array
    {
        return Database::all(
            'SELECT t.*, u.username AS assignee_username, u.display_name AS assignee_name
             FROM bla_project_tasks t LEFT JOIN bla_users u ON u.id = t.assignee_id
             WHERE t.project_id = ? ORDER BY t.column_id, t.position, t.id',
            [$projectId]
        );
    }

    public static function task(int $taskId): ?array
    {
        return Database::one(
            'SELECT t.*, u.username AS assignee_username, u.display_name AS assignee_name
             FROM bla_project_tasks t LEFT JOIN bla_users u ON u.id = t.assignee_id WHERE t.id = ?',
            [$taskId]
        );
    }

    private static function validateAssignee(int $projectId, ?int $assigneeId): void
    {
        if ($assigneeId !== null && !Database::one('SELECT 1 FROM bla_project_members WHERE project_id = ? AND user_id = ?', [$projectId, $assigneeId])) {
            throw new StorageException('The assignee must be a member of this project.');
        }
    }

    public static function createTask(
        int $userId, int $projectId, int $columnId, string $title, string $description = '',
        ?int $assigneeId = null, ?string $dueAt = null
    ): int {
        self::requireMember($userId, $projectId);
        if (self::projectIdOfColumn($columnId) !== $projectId) {
            throw new StorageException('That column is not part of this project.');
        }
        $title = mb_substr(trim($title), 0, 255);
        if ($title === '') {
            throw new StorageException('Please enter a task title.');
        }
        self::validateAssignee($projectId, $assigneeId);
        $next = (int) (Database::one('SELECT COALESCE(MAX(position), -1) + 1 AS n FROM bla_project_tasks WHERE column_id = ?', [$columnId])['n']);
        $now = Database::now();
        $id = Database::insert(
            'INSERT INTO bla_project_tasks (project_id, column_id, title, description, assignee_id, due_at, position, created_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$projectId, $columnId, $title, mb_substr(trim($description), 0, 5000), $assigneeId, $dueAt, $next, $userId, $now, $now]
        );
        Audit::log($userId, 'project.task_created', $title);
        return $id;
    }

    public static function updateTask(
        int $userId, int $taskId, string $title, string $description = '', ?int $assigneeId = null, ?string $dueAt = null
    ): void {
        $projectId = self::projectIdOfTask($taskId);
        self::requireMember($userId, $projectId);
        $title = mb_substr(trim($title), 0, 255);
        if ($title === '') {
            throw new StorageException('Please enter a task title.');
        }
        self::validateAssignee($projectId, $assigneeId);
        Database::run('UPDATE bla_project_tasks SET title = ?, description = ?, assignee_id = ?, due_at = ?, updated_at = ? WHERE id = ?',
            [$title, mb_substr(trim($description), 0, 5000), $assigneeId, $dueAt, Database::now(), $taskId]);
    }

    public static function moveTask(int $userId, int $taskId, int $columnId): void
    {
        $projectId = self::projectIdOfTask($taskId);
        self::requireMember($userId, $projectId);
        if (self::projectIdOfColumn($columnId) !== $projectId) {
            throw new StorageException('That column is not part of this project.');
        }
        $next = (int) (Database::one('SELECT COALESCE(MAX(position), -1) + 1 AS n FROM bla_project_tasks WHERE column_id = ?', [$columnId])['n']);
        Database::run('UPDATE bla_project_tasks SET column_id = ?, position = ?, updated_at = ? WHERE id = ?',
            [$columnId, $next, Database::now(), $taskId]);
    }

    public static function deleteTask(int $userId, int $taskId): void
    {
        $projectId = self::projectIdOfTask($taskId);
        self::requireMember($userId, $projectId);
        Database::run('DELETE FROM bla_project_tasks WHERE id = ?', [$taskId]);
        Audit::log($userId, 'project.task_deleted');
    }

    // ---------- Comments ----------

    public static function comments(int $taskId): array
    {
        return Database::all(
            'SELECT c.*, u.username, u.display_name FROM bla_project_comments c
             JOIN bla_users u ON u.id = c.user_id WHERE c.task_id = ? ORDER BY c.created_at, c.id',
            [$taskId]
        );
    }

    /** Every comment for a project's tasks in one query, for the board view to group by task_id. */
    public static function commentsForProject(int $projectId): array
    {
        return Database::all(
            'SELECT c.*, u.username, u.display_name FROM bla_project_comments c
             JOIN bla_users u ON u.id = c.user_id JOIN bla_project_tasks t ON t.id = c.task_id
             WHERE t.project_id = ? ORDER BY c.created_at, c.id',
            [$projectId]
        );
    }

    public static function addComment(int $userId, int $taskId, string $body): int
    {
        $projectId = self::projectIdOfTask($taskId);
        self::requireMember($userId, $projectId);
        $body = trim($body);
        if ($body === '') {
            throw new StorageException('Please enter a comment.');
        }
        return Database::insert('INSERT INTO bla_project_comments (task_id, user_id, body, created_at) VALUES (?, ?, ?, ?)',
            [$taskId, $userId, mb_substr($body, 0, 5000), Database::now()]);
    }
}
