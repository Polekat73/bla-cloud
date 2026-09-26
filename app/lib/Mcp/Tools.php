<?php
declare(strict_types=1);

namespace BlaCloud\Mcp;

use BlaCloud\Apps\Projects\ProjectsData;
use BlaCloud\Audit;
use BlaCloud\Database;
use BlaCloud\Dav\CalendarBackend;
use BlaCloud\Dav\ContactsBackend;
use BlaCloud\Dav\Ical;
use BlaCloud\Dav\Vcard;
use BlaCloud\Encryption;
use BlaCloud\Storage;
use BlaCloud\StorageException;

/**
 * Tools exposed over MCP (see McpController). Every method here runs scoped to the one user whose
 * AI token authenticated the request — there is no user_id parameter on any tool, deliberately: the
 * caller can never ask to act as anyone else. Cross-project/cross-account checks are the same ones
 * the web UI uses (ProjectsData, Storage's path containment, etc.), not a separate, easier-to-get-wrong
 * copy of them.
 */
final class Tools
{
    /** name => [description, inputSchema (JSON Schema)]. Sent to the client from tools/list. */
    public static function definitions(): array
    {
        $str = static fn (string $desc) => ['type' => 'string', 'description' => $desc];
        return [
            'files_list' => ['Lists the files and folders inside a folder in your BLA-Cloud files.', [
                'type' => 'object', 'properties' => ['path' => $str('Folder path, e.g. "/Photos". Empty or omitted means the top level.')],
            ]],
            'files_read' => ['Reads a text file\'s contents. Files over 256 KB or that are not text are rejected.', [
                'type' => 'object', 'required' => ['path'],
                'properties' => ['path' => $str('File path, e.g. "/Notes/todo.txt".')],
            ]],
            'files_write' => ['Creates or overwrites a text file. Missing parent folders are created automatically. Overwriting keeps the previous version in Version history.', [
                'type' => 'object', 'required' => ['path', 'content'],
                'properties' => ['path' => $str('File path, e.g. "/Notes/todo.txt".'), 'content' => $str('The full text content to write.')],
            ]],
            'files_delete' => ['Moves a file or folder to the trash.', [
                'type' => 'object', 'required' => ['path'], 'properties' => ['path' => $str('Path to delete.')],
            ]],
            'files_mkdir' => ['Creates a new folder.', [
                'type' => 'object', 'required' => ['path'], 'properties' => ['path' => $str('Full path of the new folder, e.g. "/Projects/2026".')],
            ]],
            'files_move' => ['Moves a file or folder into a different folder.', [
                'type' => 'object', 'required' => ['path', 'to_folder'],
                'properties' => ['path' => $str('Item to move.'), 'to_folder' => $str('Destination folder path (must already exist).')],
            ]],
            'calendar_list_calendars' => ['Lists your calendars.', ['type' => 'object', 'properties' => new \stdClass()]],
            'calendar_list_events' => ['Lists events between two dates, across all your calendars.', [
                'type' => 'object', 'required' => ['start', 'end'],
                'properties' => ['start' => $str('Start date, YYYY-MM-DD.'), 'end' => $str('End date, YYYY-MM-DD (exclusive).')],
            ]],
            'calendar_create_event' => ['Creates a calendar event.', [
                'type' => 'object', 'required' => ['title', 'start', 'end'],
                'properties' => [
                    'calendar_id' => ['type' => 'integer', 'description' => 'Calendar id (from calendar_list_calendars). Defaults to your first calendar.'],
                    'title' => $str('Event title.'), 'description' => $str('Details.'), 'location' => $str('Location.'),
                    'start' => $str('Start, "YYYY-MM-DD HH:MM" or "YYYY-MM-DD" if all_day.'),
                    'end' => $str('End, same format as start.'),
                    'all_day' => ['type' => 'boolean', 'description' => 'True for an all-day event.'],
                    'remind_minutes' => ['type' => 'integer', 'description' => 'Email reminder this many minutes before the start (omit for no reminder).'],
                ],
            ]],
            'calendar_update_event' => ['Updates a calendar event. Only object_id and calendar_id are required; other fields replace the current value.', [
                'type' => 'object', 'required' => ['object_id', 'calendar_id', 'title', 'start', 'end'],
                'properties' => [
                    'object_id' => ['type' => 'integer', 'description' => 'Event id, from calendar_list_events.'],
                    'calendar_id' => ['type' => 'integer'], 'title' => $str('Title.'), 'description' => $str('Details.'),
                    'location' => $str('Location.'), 'start' => $str('Start.'), 'end' => $str('End.'),
                    'all_day' => ['type' => 'boolean'], 'remind_minutes' => ['type' => 'integer'],
                ],
            ]],
            'calendar_delete_event' => ['Deletes a calendar event.', [
                'type' => 'object', 'required' => ['object_id', 'calendar_id'],
                'properties' => ['object_id' => ['type' => 'integer'], 'calendar_id' => ['type' => 'integer']],
            ]],
            'contacts_list' => ['Lists (optionally searches) your contacts.', [
                'type' => 'object', 'properties' => ['query' => $str('Search text matched against the name (omit to list everyone).')],
            ]],
            'contacts_create' => ['Creates a contact.', [
                'type' => 'object',
                'properties' => [
                    'given' => $str('First name.'), 'family' => $str('Last name.'),
                    'phones' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['type' => $str('cell/home/work/other'), 'value' => $str('Number.')]]],
                    'emails' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['type' => $str('home/work/other'), 'value' => $str('Address.')]]],
                    'street' => $str('Street.'), 'city' => $str('City.'), 'region' => $str('State/region.'), 'postal' => $str('Postal code.'), 'country' => $str('Country.'),
                    'note' => $str('Notes.'),
                ],
            ]],
            'contacts_update' => ['Updates a contact. Fields not given are cleared, same as editing the contact form.', [
                'type' => 'object', 'required' => ['id'],
                'properties' => [
                    'id' => ['type' => 'integer'], 'given' => $str('First name.'), 'family' => $str('Last name.'),
                    'phones' => ['type' => 'array', 'items' => ['type' => 'object']], 'emails' => ['type' => 'array', 'items' => ['type' => 'object']],
                    'street' => $str('Street.'), 'city' => $str('City.'), 'region' => $str('State/region.'), 'postal' => $str('Postal code.'), 'country' => $str('Country.'),
                    'note' => $str('Notes.'),
                ],
            ]],
            'contacts_delete' => ['Deletes a contact.', ['type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['type' => 'integer']]]],
            'projects_list' => ['Lists your projects (owned or shared with you).', ['type' => 'object', 'properties' => new \stdClass()]],
            'projects_get' => ['Gets one project: its columns, tasks and members.', [
                'type' => 'object', 'required' => ['project_id'], 'properties' => ['project_id' => ['type' => 'integer']],
            ]],
            'projects_create' => ['Creates a project with default columns (To do / In progress / Done).', [
                'type' => 'object', 'required' => ['name'], 'properties' => ['name' => $str('Project name.'), 'description' => $str('Description.')],
            ]],
            'projects_create_task' => ['Creates a task in a project.', [
                'type' => 'object', 'required' => ['project_id', 'column_id', 'title'],
                'properties' => [
                    'project_id' => ['type' => 'integer'], 'column_id' => ['type' => 'integer', 'description' => 'From projects_get.'],
                    'title' => $str('Task title.'), 'description' => $str('Details.'),
                    'assignee_username' => $str('Username of a project member to assign to (omit to leave unassigned).'),
                    'due_at' => $str('Due date, YYYY-MM-DD (omit for none).'),
                ],
            ]],
            'projects_update_task' => ['Updates a task\'s title, description, assignee or due date.', [
                'type' => 'object', 'required' => ['task_id', 'title'],
                'properties' => [
                    'task_id' => ['type' => 'integer'], 'title' => $str('Title.'), 'description' => $str('Details.'),
                    'assignee_username' => $str('Username to assign to (omit to unassign).'), 'due_at' => $str('YYYY-MM-DD (omit for none).'),
                ],
            ]],
            'projects_move_task' => ['Moves a task to a different column (e.g. marking it done).', [
                'type' => 'object', 'required' => ['task_id', 'column_id'],
                'properties' => ['task_id' => ['type' => 'integer'], 'column_id' => ['type' => 'integer']],
            ]],
            'projects_delete_task' => ['Deletes a task.', ['type' => 'object', 'required' => ['task_id'], 'properties' => ['task_id' => ['type' => 'integer']]]],
            'projects_add_comment' => ['Adds a comment to a task.', [
                'type' => 'object', 'required' => ['task_id', 'body'], 'properties' => ['task_id' => ['type' => 'integer'], 'body' => $str('Comment text.')],
            ]],
            'projects_add_member' => ['Adds another BLA-Cloud user to a project you own.', [
                'type' => 'object', 'required' => ['project_id', 'username'],
                'properties' => ['project_id' => ['type' => 'integer'], 'username' => $str('Their username.')],
            ]],
        ];
    }

    /** Dispatches a tool call. Returns plain data (McpController wraps it as MCP tool-result content). */
    public static function call(string $name, array $args, array $user): array
    {
        if (!isset(self::definitions()[$name])) {
            throw new StorageException("Unknown tool: $name", 404);
        }
        $uid = (int) $user['id'];
        Audit::log($uid, 'mcp.tool_call', $name);
        return match ($name) {
            'files_list'   => self::filesList($uid, (string) ($args['path'] ?? '')),
            'files_read'   => self::filesRead($uid, (string) ($args['path'] ?? '')),
            'files_write'  => self::filesWrite($uid, (string) ($args['path'] ?? ''), (string) ($args['content'] ?? '')),
            'files_delete' => self::filesDelete($uid, (string) ($args['path'] ?? '')),
            'files_mkdir'  => self::filesMkdir($uid, (string) ($args['path'] ?? '')),
            'files_move'   => self::filesMove($uid, (string) ($args['path'] ?? ''), (string) ($args['to_folder'] ?? '')),
            'calendar_list_calendars' => self::calendarListCalendars($uid),
            'calendar_list_events'    => self::calendarListEvents($uid, (string) ($args['start'] ?? ''), (string) ($args['end'] ?? '')),
            'calendar_create_event'   => self::calendarSaveEvent($uid, null, $args),
            'calendar_update_event'   => self::calendarSaveEvent($uid, (int) ($args['object_id'] ?? 0), $args),
            'calendar_delete_event'   => self::calendarDeleteEvent($uid, (int) ($args['calendar_id'] ?? 0), (int) ($args['object_id'] ?? 0)),
            'contacts_list'   => self::contactsList($uid, (string) ($args['query'] ?? '')),
            'contacts_create' => self::contactsSave($uid, null, $args),
            'contacts_update' => self::contactsSave($uid, (int) ($args['id'] ?? 0), $args),
            'contacts_delete' => self::contactsDelete($uid, (int) ($args['id'] ?? 0)),
            'projects_list'         => ['projects' => ProjectsData::forUser($uid)],
            'projects_get'          => self::projectsGet($uid, (int) ($args['project_id'] ?? 0)),
            'projects_create'       => ['id' => ProjectsData::create($uid, (string) ($args['name'] ?? ''), (string) ($args['description'] ?? ''))],
            'projects_create_task'  => self::projectsCreateTask($uid, $args),
            'projects_update_task'  => self::projectsUpdateTask($uid, $args),
            'projects_move_task'    => self::void(fn () => ProjectsData::moveTask($uid, (int) ($args['task_id'] ?? 0), (int) ($args['column_id'] ?? 0))),
            'projects_delete_task'  => self::void(fn () => ProjectsData::deleteTask($uid, (int) ($args['task_id'] ?? 0))),
            'projects_add_comment'  => ['id' => ProjectsData::addComment($uid, (int) ($args['task_id'] ?? 0), (string) ($args['body'] ?? ''))],
            'projects_add_member'   => self::void(fn () => ProjectsData::addMember($uid, (int) ($args['project_id'] ?? 0), (string) ($args['username'] ?? ''))),
            default => throw new StorageException("Unknown tool: $name", 404),
        };
    }

    private static function void(callable $fn): array
    {
        $fn();
        return ['ok' => true];
    }

    // ---------- Files ----------

    private static function filesList(int $uid, string $path): array
    {
        return ['items' => (new Storage($uid))->list(Storage::normalize($path))];
    }

    private const MAX_READ_BYTES = 256 * 1024;

    private static function filesRead(int $uid, string $path): array
    {
        $fs = new Storage($uid);
        $abs = $fs->abs(Storage::normalize($path));
        if (is_dir($abs)) {
            throw new StorageException('That is a folder, not a file.');
        }
        $size = Encryption::contentSize($abs);
        if ($size > self::MAX_READ_BYTES) {
            throw new StorageException('That file is too large to read here (over 256 KB).');
        }
        $plain = Encryption::resolvePlaintext($abs);
        $content = file_get_contents($plain);
        if ($content === false || !mb_check_encoding($content, 'UTF-8')) {
            throw new StorageException('That file is not readable as text (binary or non-UTF-8 content).');
        }
        return ['path' => $path, 'content' => $content];
    }

    private static function filesWrite(int $uid, string $path, string $content): array
    {
        $fs = new Storage($uid);
        $rel = Storage::normalize($path);
        $dir = Storage::parent($rel);
        $name = basename($rel);
        if ($name === '') {
            throw new StorageException('Please give the file a name.');
        }
        // Create any missing parent folders, one level at a time (mirrors what a person would do by
        // hand — mkdir refuses if a segment already exists as a file, which is the behaviour we want).
        $built = '';
        foreach (array_filter(explode('/', $dir)) as $seg) {
            try {
                $fs->mkdir($built, $seg);
            } catch (StorageException) {
                // already exists — fine, keep descending
            }
            $built = Storage::normalize($built . '/' . $seg);
        }
        $tmp = tempnam(sys_get_temp_dir(), 'mcpw');
        file_put_contents($tmp, $content);
        try {
            $res = $fs->receiveChunk(bin2hex(random_bytes(10)), 0, $tmp, true, $dir, $name, strlen($content));
        } finally {
            @unlink($tmp);
        }
        Audit::log($uid, 'file.upload', $res['path'] ?? $rel);
        return ['path' => $res['path'] ?? $rel, 'replaced' => $res['replaced'] ?? false];
    }

    private static function filesDelete(int $uid, string $path): array
    {
        (new Storage($uid))->delete(Storage::normalize($path));
        Audit::log($uid, 'file.delete', $path);
        return ['ok' => true];
    }

    private static function filesMkdir(int $uid, string $path): array
    {
        $rel = Storage::normalize($path);
        (new Storage($uid))->mkdir(Storage::parent($rel), basename($rel));
        return ['ok' => true, 'path' => $rel];
    }

    private static function filesMove(int $uid, string $path, string $toFolder): array
    {
        $newPath = (new Storage($uid))->move(Storage::normalize($path), Storage::normalize($toFolder));
        return ['ok' => true, 'path' => $newPath];
    }

    // ---------- Calendar ----------

    private static function calendarListCalendars(int $uid): array
    {
        return ['calendars' => Database::all('SELECT id, display_name, color FROM bla_calendars WHERE user_id = ? ORDER BY id', [$uid])];
    }

    private static function calendarListEvents(int $uid, string $start, string $end): array
    {
        $tz = new \DateTimeZone('UTC');
        $s = \DateTimeImmutable::createFromFormat('!Y-m-d', $start, $tz);
        $e = \DateTimeImmutable::createFromFormat('!Y-m-d', $end, $tz);
        if (!$s || !$e) {
            throw new StorageException('Dates must be YYYY-MM-DD.');
        }
        $calIds = array_column(Database::all('SELECT id FROM bla_calendars WHERE user_id = ?', [$uid]), 'id');
        if (!$calIds) {
            return ['events' => []];
        }
        $ph = implode(',', array_fill(0, count($calIds), '?'));
        $rows = Database::all(
            "SELECT o.id, o.calendar_id, o.start_at, o.end_at, o.all_day, o.data FROM bla_calendar_objects o
             WHERE o.calendar_id IN ($ph) AND o.start_at IS NOT NULL AND o.start_at < ? AND o.end_at > ? ORDER BY o.start_at",
            [...$calIds, $e->format('Y-m-d H:i:s'), $s->format('Y-m-d H:i:s')]
        );
        $events = [];
        foreach ($rows as $r) {
            $parsed = Ical::parseEvent($r['data']) ?? [];
            $events[] = [
                'object_id' => (int) $r['id'], 'calendar_id' => (int) $r['calendar_id'],
                'title' => $parsed['summary'] ?? '', 'location' => $parsed['location'] ?? '', 'description' => $parsed['description'] ?? '',
                'start' => $r['start_at'], 'end' => $r['end_at'], 'all_day' => (bool) $r['all_day'],
            ];
        }
        return ['events' => $events];
    }

    /** Shared by create (object_id null) and update. */
    private static function calendarSaveEvent(int $uid, ?int $objectId, array $args): array
    {
        $calendarId = (int) ($args['calendar_id'] ?? 0) ?: (int) (Database::one('SELECT id FROM bla_calendars WHERE user_id = ? ORDER BY id', [$uid])['id'] ?? 0);
        $cal = Database::one('SELECT * FROM bla_calendars WHERE id = ? AND user_id = ?', [$calendarId, $uid]);
        if (!$cal) {
            throw new StorageException('That calendar does not exist.');
        }
        $title = trim((string) ($args['title'] ?? ''));
        if ($title === '') {
            throw new StorageException('Please give the event a title.');
        }
        $allDay = (bool) ($args['all_day'] ?? false);
        $tz = new \DateTimeZone('UTC');
        $start = $allDay
            ? \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $args['start'], $tz)
            : \DateTimeImmutable::createFromFormat('Y-m-d H:i', (string) $args['start'], $tz);
        $end = $allDay
            ? \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $args['end'], $tz)?->modify('+1 day')
            : \DateTimeImmutable::createFromFormat('Y-m-d H:i', (string) $args['end'], $tz);
        if (!$start || !$end) {
            throw new StorageException('start/end must be "YYYY-MM-DD HH:MM" (or "YYYY-MM-DD" if all_day is true).');
        }
        $existingRow = $objectId ? Database::one('SELECT * FROM bla_calendar_objects WHERE id = ? AND calendar_id = ?', [$objectId, $cal['id']]) : null;
        if ($objectId && !$existingRow) {
            throw new StorageException('That event does not exist.');
        }
        $uri = $existingRow['uri'] ?? (bin2hex(random_bytes(12)) . '.ics');
        $remind = $args['remind_minutes'] ?? null;
        $ics = Ical::buildEvent([
            'uid' => $existingRow['uid'] ?? null, 'summary' => $title,
            'description' => trim((string) ($args['description'] ?? '')), 'location' => trim((string) ($args['location'] ?? '')),
            'allDay' => $allDay, 'start' => $start, 'end' => $end,
            'remindMinutesBefore' => $remind !== null ? (int) $remind : null,
        ]);
        CalendarBackend::writeObject((int) $cal['id'], $uri, $ics);
        $row = Database::one('SELECT id FROM bla_calendar_objects WHERE calendar_id = ? AND uri = ?', [$cal['id'], $uri]);
        Audit::log($uid, $existingRow ? 'calendar.event_updated' : 'calendar.event_created', $title);
        return ['object_id' => (int) $row['id'], 'calendar_id' => (int) $cal['id']];
    }

    private static function calendarDeleteEvent(int $uid, int $calendarId, int $objectId): array
    {
        $cal = Database::one('SELECT * FROM bla_calendars WHERE id = ? AND user_id = ?', [$calendarId, $uid]);
        if (!$cal) {
            throw new StorageException('That calendar does not exist.');
        }
        $row = Database::one('SELECT uri FROM bla_calendar_objects WHERE id = ? AND calendar_id = ?', [$objectId, $calendarId]);
        if ($row) {
            CalendarBackend::deleteObjectByUri($calendarId, $row['uri']);
            Audit::log($uid, 'calendar.event_deleted');
        }
        return ['ok' => true];
    }

    // ---------- Contacts ----------

    private static function book(int $uid): array
    {
        $book = Database::one('SELECT * FROM bla_addressbooks WHERE user_id = ? AND uri = ?', [$uid, 'contacts']);
        if (!$book) {
            throw new StorageException('Your address book is missing.');
        }
        return $book;
    }

    private static function contactsList(int $uid, string $query): array
    {
        $book = self::book($uid);
        $rows = $query !== ''
            ? Database::all("SELECT * FROM bla_contacts WHERE addressbook_id = ? AND fn LIKE ? ESCAPE '\\' ORDER BY fn",
                [$book['id'], '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $query) . '%'])
            : Database::all('SELECT * FROM bla_contacts WHERE addressbook_id = ? ORDER BY fn', [$book['id']]);
        return ['contacts' => array_map(static fn ($r) => ['id' => (int) $r['id']] + Vcard::parseContact($r['data']), $rows)];
    }

    private static function pairs(array $list): array
    {
        $out = [];
        foreach ($list as $p) {
            $v = trim((string) ($p['value'] ?? ''));
            if ($v !== '') {
                $out[] = ['type' => (string) ($p['type'] ?? 'other'), 'value' => $v];
            }
        }
        return $out;
    }

    /** Shared by create (id null) and update. */
    private static function contactsSave(int $uid, ?int $id, array $args): array
    {
        $book = self::book($uid);
        $given = trim((string) ($args['given'] ?? ''));
        $family = trim((string) ($args['family'] ?? ''));
        if ($given === '' && $family === '') {
            throw new StorageException('Please give the contact at least a name.');
        }
        $existing = $id ? Database::one('SELECT * FROM bla_contacts WHERE id = ? AND addressbook_id = ?', [$id, $book['id']]) : null;
        if ($id && !$existing) {
            throw new StorageException('That contact does not exist.');
        }
        $uri = $existing['uri'] ?? (bin2hex(random_bytes(12)) . '.vcf');
        $existingPhoto = $existing ? Vcard::parseContact($existing['data'])['photo'] : null;
        $vcf = Vcard::buildContact([
            'uid' => $existing['uid'] ?? null, 'given' => $given, 'family' => $family,
            'phones' => self::pairs((array) ($args['phones'] ?? [])), 'emails' => self::pairs((array) ($args['emails'] ?? [])),
            'address' => [
                'street' => trim((string) ($args['street'] ?? '')), 'city' => trim((string) ($args['city'] ?? '')),
                'region' => trim((string) ($args['region'] ?? '')), 'postal' => trim((string) ($args['postal'] ?? '')),
                'country' => trim((string) ($args['country'] ?? '')),
            ],
            'note' => trim((string) ($args['note'] ?? '')),
            'photoBase64' => $existingPhoto && preg_match('/base64,(.+)$/s', $existingPhoto, $m) ? $m[1] : null,
            'photoType' => $existingPhoto && preg_match('/^data:image\/(\w+);/', $existingPhoto, $m) ? strtoupper($m[1]) : null,
        ]);
        ContactsBackend::writeObject((int) $book['id'], $uri, $vcf);
        $row = Database::one('SELECT id FROM bla_contacts WHERE addressbook_id = ? AND uri = ?', [$book['id'], $uri]);
        Audit::log($uid, $existing ? 'contact.updated' : 'contact.created', trim("$given $family"));
        return ['id' => (int) $row['id']];
    }

    private static function contactsDelete(int $uid, int $id): array
    {
        $book = self::book($uid);
        $row = Database::one('SELECT uri FROM bla_contacts WHERE id = ? AND addressbook_id = ?', [$id, $book['id']]);
        if ($row) {
            ContactsBackend::deleteObjectByUri((int) $book['id'], $row['uri']);
            Audit::log($uid, 'contact.deleted');
        }
        return ['ok' => true];
    }

    // ---------- Projects ----------

    private static function projectsGet(int $uid, int $projectId): array
    {
        $project = ProjectsData::requireMember($uid, $projectId);
        return [
            'project' => $project,
            'columns' => ProjectsData::columns($projectId),
            'tasks' => ProjectsData::tasks($projectId),
            'members' => ProjectsData::members($projectId),
        ];
    }

    /** Requires the caller to already be a member before it will even read the member list — otherwise
     *  the "is/isn't a member" error below becomes an oracle for probing a project you have no access to. */
    private static function resolveAssignee(int $uid, int $projectId, ?string $username): ?int
    {
        ProjectsData::requireMember($uid, $projectId);
        if ($username === null || $username === '') {
            return null;
        }
        foreach (ProjectsData::members($projectId) as $m) {
            if (strcasecmp($m['username'], $username) === 0) {
                return (int) $m['id'];
            }
        }
        throw new StorageException("\"$username\" is not a member of this project.");
    }

    private static function projectsCreateTask(int $uid, array $args): array
    {
        $projectId = (int) ($args['project_id'] ?? 0);
        $assignee = self::resolveAssignee($uid, $projectId, $args['assignee_username'] ?? null);
        $id = ProjectsData::createTask(
            $uid, $projectId, (int) ($args['column_id'] ?? 0), (string) ($args['title'] ?? ''),
            (string) ($args['description'] ?? ''), $assignee, ($args['due_at'] ?? null) ?: null
        );
        return ['task_id' => $id];
    }

    private static function projectsUpdateTask(int $uid, array $args): array
    {
        $taskId = (int) ($args['task_id'] ?? 0);
        $projectId = ProjectsData::projectIdOfTask($taskId);
        $assignee = self::resolveAssignee($uid, $projectId, $args['assignee_username'] ?? null);
        ProjectsData::updateTask($uid, $taskId, (string) ($args['title'] ?? ''), (string) ($args['description'] ?? ''), $assignee, ($args['due_at'] ?? null) ?: null);
        return ['ok' => true];
    }
}
