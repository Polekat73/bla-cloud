<?php
declare(strict_types=1);

namespace BlaCloud\Dav;

use BlaCloud\Controllers\DavController;
use BlaCloud\Database;
use BlaCloud\StorageException;

/**
 * CalDAV, mounted at /dav/calendars/{username}/. Each calendar is a flat collection of
 * .ics objects (events/todos) stored as raw iCalendar text — we don't parse or understand
 * recurrence rules etc., we just keep what the client sent and hand it back unchanged.
 *
 * Sync uses getctag (bumped on every write) rather than the newer sync-collection REPORT,
 * which every mainstream CalDAV client (Apple Calendar, Thunderbird/Lightning, DAVx5,
 * Outlook via add-in) still falls back to happily.
 */
final class CalendarBackend
{
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
        $this->baseHref = DavController::baseHref() . '/calendars/' . rawurlencode($this->username);
        $calUri = $segments[0] ?? null;
        $objUri = $segments[1] ?? null;

        if ($calUri === null) {
            $this->handleHome($method);
            return;
        }
        $cal = Database::one('SELECT * FROM bla_calendars WHERE user_id = ? AND uri = ?', [$this->user['id'], $calUri]);
        if (!$cal && $method !== 'MKCALENDAR') {
            http_response_code(404);
            return;
        }
        if ($objUri === null) {
            match ($method) {
                'PROPFIND'   => $this->propfindCollection($cal),
                'PROPPATCH'  => $this->proppatch($cal),
                'MKCALENDAR' => $this->mkcalendar($calUri),
                'REPORT'     => $this->report($cal),
                'DELETE'     => $this->deleteCalendar($cal),
                default      => http_response_code(405),
            };
            return;
        }
        match ($method) {
            'GET', 'HEAD' => $this->getObject($cal, $objUri, $method === 'HEAD'),
            'PUT'         => $this->putObject($cal, $objUri),
            'DELETE'      => $this->deleteObject($cal, $objUri),
            default       => http_response_code(405),
        };
    }

    // ---------- Home collection: /dav/calendars/{username}/ ----------

    private function handleHome(string $method): void
    {
        if ($method !== 'PROPFIND') {
            http_response_code(405);
            return;
        }
        $depth = $_SERVER['HTTP_DEPTH'] ?? '1';
        $requested = Xml::propfindProps(Xml::body());
        $ms = new Xml();
        $ms->addFound($this->baseHref . '/', [
            '{DAV:}resourcetype'    => new Raw('<d:collection/>'),
            '{DAV:}displayname'     => 'Calendars',
        ]);
        if ($depth !== '0') {
            foreach (Database::all('SELECT * FROM bla_calendars WHERE user_id = ? ORDER BY id', [$this->user['id']]) as $cal) {
                $this->addCalendarProps($ms, $cal, $requested);
            }
        }
        $ms->send();
    }

    // ---------- One calendar collection ----------

    private function propfindCollection(array $cal): void
    {
        $depth = $_SERVER['HTTP_DEPTH'] ?? '1';
        $requested = Xml::propfindProps(Xml::body());
        $ms = new Xml();
        $this->addCalendarProps($ms, $cal, $requested);
        if ($depth !== '0') {
            foreach (Database::all('SELECT * FROM bla_calendar_objects WHERE calendar_id = ? ORDER BY id', [$cal['id']]) as $obj) {
                $this->addObjectProps($ms, $cal, $obj, $requested);
            }
        }
        $ms->send();
    }

    private function addCalendarProps(Xml $ms, array $cal, ?array $requested): void
    {
        $href = $this->baseHref . '/' . rawurlencode($cal['uri']) . '/';
        $all = [
            '{DAV:}resourcetype'         => new Raw('<d:collection/><cal:calendar/>'),
            '{DAV:}displayname'          => $cal['display_name'],
            '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set'
                => new Raw('<cal:comp name="VEVENT"/><cal:comp name="VTODO"/>'),
            '{urn:ietf:params:xml:ns:caldav}calendar-description' => '',
            '{http://calendarserver.org/ns/}getctag' => (string) $cal['ctag'],
            '{DAV:}sync-token' => 'https://haven/ns/sync/' . $cal['ctag'],
        ];
        $this->emit($ms, $href, $all, $requested);
    }

    private function addObjectProps(Xml $ms, array $cal, array $obj, ?array $requested): void
    {
        $href = $this->baseHref . '/' . rawurlencode($cal['uri']) . '/' . rawurlencode($obj['uri']);
        $all = [
            '{DAV:}resourcetype'         => new Raw(''),
            '{DAV:}getcontenttype'       => 'text/calendar; charset=utf-8; component=VEVENT',
            '{DAV:}getetag'              => '"' . $obj['etag'] . '"',
            '{DAV:}getlastmodified'      => gmdate('D, d M Y H:i:s', strtotime($obj['updated_at'] . ' UTC')) . ' GMT',
            '{urn:ietf:params:xml:ns:caldav}calendar-data' => $obj['data'],
        ];
        $this->emit($ms, $href, $all, $requested);
    }

    private function emit(Xml $ms, string $href, array $all, ?array $requested): void
    {
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

    private function proppatch(array $cal): void
    {
        $doc = Xml::body();
        $name = $cal['display_name'];
        $color = $cal['color'];
        if ($doc) {
            $dn = $doc->getElementsByTagNameNS(Xml::NS_DAV, 'displayname')->item(0);
            if ($dn) {
                $name = mb_substr(trim($dn->textContent), 0, 128) ?: $name;
            }
        }
        Database::run('UPDATE bla_calendars SET display_name = ?, color = ? WHERE id = ?', [$name, $color, $cal['id']]);
        $ms = new Xml();
        $ms->addFound($this->baseHref . '/' . rawurlencode($cal['uri']) . '/', ['{DAV:}displayname' => new Raw('')]);
        $ms->send();
    }

    private function mkcalendar(string $calUri): void
    {
        if (!preg_match('/^[A-Za-z0-9._-]{1,64}$/', $calUri)) {
            http_response_code(400);
            return;
        }
        if (Database::one('SELECT id FROM bla_calendars WHERE user_id = ? AND uri = ?', [$this->user['id'], $calUri])) {
            http_response_code(405);
            return;
        }
        $name = $calUri;
        $doc = Xml::body();
        if ($doc) {
            $dn = $doc->getElementsByTagNameNS(Xml::NS_DAV, 'displayname')->item(0);
            if ($dn) {
                $name = mb_substr(trim($dn->textContent), 0, 128) ?: $name;
            }
        }
        Database::run('INSERT INTO bla_calendars (user_id, uri, display_name, color, ctag, created_at) VALUES (?, ?, ?, ?, 1, ?)',
            [$this->user['id'], $calUri, $name, '#c9a227', Database::now()]);
        http_response_code(201);
    }

    private function deleteCalendar(array $cal): void
    {
        if ($cal['uri'] === 'personal') {
            http_response_code(403);
            echo "The default calendar can't be deleted over sync.\n";
            return;
        }
        Database::run('DELETE FROM bla_calendars WHERE id = ?', [$cal['id']]);
        http_response_code(204);
    }

    // ---------- Objects ----------

    private function getObject(array $cal, string $uri, bool $headOnly): void
    {
        $obj = Database::one('SELECT * FROM bla_calendar_objects WHERE calendar_id = ? AND uri = ?', [$cal['id'], $uri]);
        if (!$obj) {
            http_response_code(404);
            return;
        }
        $etag = '"' . $obj['etag'] . '"';
        header('ETag: ' . $etag);
        header('Content-Type: text/calendar; charset=utf-8');
        if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
            http_response_code(304);
            return;
        }
        header('Content-Length: ' . strlen($obj['data']));
        http_response_code(200);
        if (!$headOnly) {
            echo $obj['data'];
        }
    }

    private function putObject(array $cal, string $uri): void
    {
        $data = (string) file_get_contents('php://input');
        if (!str_contains($data, 'BEGIN:VCALENDAR')) {
            http_response_code(400);
            echo "Expected an iCalendar object.\n";
            return;
        }
        $existing = Database::one('SELECT * FROM bla_calendar_objects WHERE calendar_id = ? AND uri = ?', [$cal['id'], $uri]);
        if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === '*' && $existing) {
            http_response_code(412);
            return;
        }
        $ifMatch = $_SERVER['HTTP_IF_MATCH'] ?? '';
        if ($ifMatch !== '' && $existing && $ifMatch !== '"' . $existing['etag'] . '"') {
            http_response_code(412);
            return;
        }
        $existed = self::writeObject((int) $cal['id'], $uri, $data, $etag);
        header('ETag: "' . $etag . '"');
        http_response_code($existed ? 204 : 201);
    }

    private function deleteObject(array $cal, string $uri): void
    {
        if (!self::deleteObjectByUri((int) $cal['id'], $uri)) {
            http_response_code(404);
            return;
        }
        http_response_code(204);
    }

    /**
     * Store one VEVENT (from CalDAV PUT or the web calendar app). $etag is filled in with the
     * new content hash. Returns true if this replaced an existing object, false if it was new.
     */
    public static function writeObject(int $calendarId, string $uri, string $data, ?string &$etag = null): bool
    {
        $existing = Database::one('SELECT id FROM bla_calendar_objects WHERE calendar_id = ? AND uri = ?', [$calendarId, $uri]);
        $uid = Vobject::extractUid($data) ?: $uri;
        $etag = md5($data);
        $parsed = Ical::parseEvent($data);
        $startAt = $parsed ? $parsed['start']->format('Y-m-d H:i:s') : null;
        $endAt = $parsed ? $parsed['end']->format('Y-m-d H:i:s') : null;
        $allDay = $parsed && $parsed['allDay'] ? 1 : 0;
        $remindAt = $parsed && $parsed['remindAt'] ? $parsed['remindAt']->format('Y-m-d H:i:s') : null;
        if ($existing) {
            Database::run('UPDATE bla_calendar_objects SET uid = ?, etag = ?, data = ?, updated_at = ?,
                start_at = ?, end_at = ?, all_day = ?, remind_at = ?, reminder_sent_at = NULL WHERE id = ?',
                [$uid, $etag, $data, Database::now(), $startAt, $endAt, $allDay, $remindAt, $existing['id']]);
        } else {
            Database::run('INSERT INTO bla_calendar_objects
                (calendar_id, uri, uid, etag, data, created_at, updated_at, start_at, end_at, all_day, remind_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$calendarId, $uri, $uid, $etag, $data, Database::now(), Database::now(), $startAt, $endAt, $allDay, $remindAt]);
        }
        Database::run('UPDATE bla_calendars SET ctag = ctag + 1 WHERE id = ?', [$calendarId]);
        return (bool) $existing;
    }

    public static function deleteObjectByUri(int $calendarId, string $uri): bool
    {
        $obj = Database::one('SELECT id FROM bla_calendar_objects WHERE calendar_id = ? AND uri = ?', [$calendarId, $uri]);
        if (!$obj) {
            return false;
        }
        Database::run('DELETE FROM bla_calendar_objects WHERE id = ?', [$obj['id']]);
        Database::run('UPDATE bla_calendars SET ctag = ctag + 1 WHERE id = ?', [$calendarId]);
        return true;
    }

    // ---------- REPORT: calendar-query, calendar-multiget ----------

    private function report(array $cal): void
    {
        $doc = Xml::body();
        $name = Xml::reportName($doc);
        $requested = Xml::propfindProps($doc);
        $ms = new Xml();
        if ($name === 'calendar-multiget' && $doc) {
            foreach (Xml::multigetHrefs($doc) as $href) {
                $uri = rawurldecode(basename(rtrim($href, '/')));
                $obj = Database::one('SELECT * FROM bla_calendar_objects WHERE calendar_id = ? AND uri = ?', [$cal['id'], $uri]);
                if ($obj) {
                    $this->addObjectProps($ms, $cal, $obj, $requested);
                } else {
                    $ms->addStatus($href, 404);
                }
            }
        } else {
            // calendar-query (and anything else): we don't filter by time-range, just hand back everything.
            foreach (Database::all('SELECT * FROM bla_calendar_objects WHERE calendar_id = ? ORDER BY id', [$cal['id']]) as $obj) {
                $this->addObjectProps($ms, $cal, $obj, $requested);
            }
        }
        $ms->send();
    }
}
