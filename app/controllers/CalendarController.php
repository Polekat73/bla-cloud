<?php
declare(strict_types=1);

namespace BlaCloud\Controllers;

use BlaCloud\Audit;
use BlaCloud\Auth;
use BlaCloud\Dav\CalendarBackend;
use BlaCloud\Dav\Ical;
use BlaCloud\Database;
use BlaCloud\Request;
use BlaCloud\Security;
use BlaCloud\Session;
use BlaCloud\StorageException;
use BlaCloud\View;

/** The built-in calendar: month/week/day/agenda views over the same data CalDAV syncs. */
final class CalendarController
{
    public function index(): void
    {
        $u = Auth::requireUser();
        $view = in_array(Request::get('view'), ['month', 'week', 'day', 'agenda'], true) ? Request::get('view') : 'month';
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', Request::get('date'), new \DateTimeZone('UTC')) ?: new \DateTimeImmutable('today', new \DateTimeZone('UTC'));

        $calendars = Database::all('SELECT * FROM bla_calendars WHERE user_id = ? ORDER BY id', [(int) $u['id']]);
        $calIds = array_column($calendars, 'id');
        [$rangeStart, $rangeEnd, $grid] = match ($view) {
            'week'   => $this->weekRange($date),
            'day'    => [$date, $date->modify('+1 day'), null],
            'agenda' => [$date, $date->modify('+60 days'), null],
            default  => $this->monthGrid($date),
        };
        $events = $calIds ? $this->eventsBetween($calIds, $rangeStart, $rangeEnd) : [];

        View::render('calendar/index', [
            'title'      => 'Calendar',
            'nav'        => 'calendar',
            'view'       => $view,
            'date'       => $date,
            'rangeStart' => $rangeStart,
            'rangeEnd'   => $rangeEnd,
            'grid'       => $grid,
            'events'     => $events,
            'calendars'  => $calendars,
            'today'      => new \DateTimeImmutable('today', new \DateTimeZone('UTC')),
        ]);
    }

    private function weekRange(\DateTimeImmutable $date): array
    {
        $start = $date->modify('monday this week');
        return [$start, $start->modify('+7 days'), null];
    }

    /** Returns [gridStart, gridEnd, weeks] where weeks is a list of 7-day DateTimeImmutable rows covering $date's month. */
    private function monthGrid(\DateTimeImmutable $date): array
    {
        $first = $date->modify('first day of this month');
        $gridStart = $first->modify('monday this week');
        $last = $date->modify('last day of this month');
        $gridEnd = $last->modify('sunday this week')->modify('+1 day');
        $weeks = [];
        $cursor = $gridStart;
        while ($cursor < $gridEnd) {
            $week = [];
            for ($i = 0; $i < 7; $i++) {
                $week[] = $cursor;
                $cursor = $cursor->modify('+1 day');
            }
            $weeks[] = $week;
        }
        return [$gridStart, $gridEnd, $weeks];
    }

    /** Events overlapping [start, end), across $calIds, each with its calendar's name/color attached. */
    private function eventsBetween(array $calIds, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $ph = implode(',', array_fill(0, count($calIds), '?'));
        $rows = Database::all("SELECT o.*, c.display_name AS cal_name, c.color AS cal_color FROM bla_calendar_objects o
            JOIN bla_calendars c ON c.id = o.calendar_id
            WHERE o.calendar_id IN ($ph) AND o.start_at IS NOT NULL AND o.start_at < ? AND o.end_at > ?
            ORDER BY o.all_day DESC, o.start_at",
            [...$calIds, $end->format('Y-m-d H:i:s'), $start->format('Y-m-d H:i:s')]);
        foreach ($rows as &$r) {
            $r['start_dt'] = new \DateTimeImmutable($r['start_at'], new \DateTimeZone('UTC'));
            $r['end_dt'] = new \DateTimeImmutable($r['end_at'], new \DateTimeZone('UTC'));
        }
        return $rows;
    }

    private function ownedCalendar(int $userId, int $calendarId): array
    {
        $cal = Database::one('SELECT * FROM bla_calendars WHERE id = ? AND user_id = ?', [$calendarId, $userId]);
        if (!$cal) {
            throw new StorageException('That calendar no longer exists.');
        }
        return $cal;
    }

    public function saveEvent(): void
    {
        $u = Auth::requireUser();
        Security::requireCsrf();
        try {
            $cal = $this->ownedCalendar((int) $u['id'], (int) Request::post('calendar_id'));
            $objectId = (int) Request::post('object_id');
            $title = trim(Request::post('title'));
            if ($title === '') {
                throw new StorageException('Please enter a title.');
            }
            $allDay = Request::post('all_day') === '1';
            $tz = new \DateTimeZone('UTC');
            $startDate = Request::post('date');
            $endDate = Request::post('end_date') ?: $startDate;
            if ($allDay) {
                $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $startDate, $tz);
                $end = \DateTimeImmutable::createFromFormat('!Y-m-d', $endDate, $tz);
                $end = $end ? $end->modify('+1 day') : null; // DTEND is exclusive for all-day events
            } else {
                $start = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $startDate . ' ' . Request::post('start_time', '09:00'), $tz);
                $end = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $endDate . ' ' . Request::post('end_time', '10:00'), $tz);
            }
            if (!$start || !$end) {
                throw new StorageException('Please enter a valid date and time.');
            }
            if ($end <= $start) {
                $end = $start->modify($allDay ? '+1 day' : '+1 hour');
            }

            $existingRow = $objectId ? Database::one('SELECT * FROM bla_calendar_objects WHERE id = ? AND calendar_id = ?', [$objectId, $cal['id']]) : null;
            $uri = $existingRow['uri'] ?? (bin2hex(random_bytes(12)) . '.ics');
            $uid = $existingRow['uid'] ?? null;
            $remind = Request::post('remind');
            $ics = Ical::buildEvent([
                'uid' => $uid, 'summary' => $title, 'description' => trim(Request::post('description')),
                'location' => trim(Request::post('location')), 'allDay' => $allDay, 'start' => $start, 'end' => $end,
                'remindMinutesBefore' => $remind !== '' ? (int) $remind : null,
            ]);
            CalendarBackend::writeObject((int) $cal['id'], $uri, $ics);
            Audit::log((int) $u['id'], $existingRow ? 'calendar.event_updated' : 'calendar.event_created', $title);
            Session::flash('success', $existingRow ? 'Event updated.' : 'Event created.');
        } catch (StorageException $e) {
            Session::flash('error', $e->getMessage());
        }
        $this->backToView();
    }

    public function deleteEvent(): void
    {
        $u = Auth::requireUser();
        Security::requireCsrf();
        try {
            $cal = $this->ownedCalendar((int) $u['id'], (int) Request::post('calendar_id'));
            $row = Database::one('SELECT uri, uid FROM bla_calendar_objects WHERE id = ? AND calendar_id = ?',
                [(int) Request::post('object_id'), $cal['id']]);
            if ($row) {
                CalendarBackend::deleteObjectByUri((int) $cal['id'], $row['uri']);
                Audit::log((int) $u['id'], 'calendar.event_deleted');
            }
            Session::flash('success', 'Event deleted.');
        } catch (StorageException $e) {
            Session::flash('error', $e->getMessage());
        }
        $this->backToView();
    }

    public function newCalendar(): void
    {
        $u = Auth::requireUser();
        Security::requireCsrf();
        $name = mb_substr(trim(Request::post('name')), 0, 128);
        if ($name === '') {
            Session::flash('error', 'Please enter a name.');
            $this->backToView();
        }
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', Request::post('color')) ? Request::post('color') : '#c9a227';
        $uri = 'cal-' . bin2hex(random_bytes(6));
        Database::run('INSERT INTO bla_calendars (user_id, uri, display_name, color, ctag, created_at) VALUES (?, ?, ?, ?, 1, ?)',
            [(int) $u['id'], $uri, $name, $color, Database::now()]);
        Audit::log((int) $u['id'], 'calendar.created', $name);
        Session::flash('success', 'Calendar created.');
        $this->backToView();
    }

    private function backToView(): never
    {
        View::redirect('calendar', array_filter([
            'view' => Request::post('view') ?: Request::get('view'),
            'date' => Request::post('date_view') ?: Request::get('date'),
        ]));
    }
}
