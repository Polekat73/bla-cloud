<?php
declare(strict_types=1);

namespace BlaCloud\Dav;

/**
 * Just enough iCalendar reading/writing for the built-in calendar app: a single VEVENT with a
 * title, time range, location, description and (at most) one email reminder. No recurrence, no
 * timezone database — all times are the server's own clock, stored and shown as-is (see docs/ROADMAP.md).
 */
final class Ical
{
    /** Parse the first VEVENT in $ics. Returns null if there's nothing usable in it. */
    public static function parseEvent(string $ics): ?array
    {
        if (!preg_match('/BEGIN:VEVENT(.*?)END:VEVENT/s', $ics, $m)) {
            return null;
        }
        $block = preg_replace("/\r?\n[ \t]/", '', $m[1]) ?? $m[1];

        $start = self::parseDate(self::prop($block, 'DTSTART'));
        if (!$start) {
            return null;
        }
        $end = self::parseDate(self::prop($block, 'DTEND'));
        if (!$end) {
            $end = ['dt' => $start['dt']->modify($start['allDay'] ? '+1 day' : '+1 hour'), 'allDay' => $start['allDay']];
        }

        $remindAt = null;
        if (preg_match('/BEGIN:VALARM.*?END:VALARM/s', $block, $vm)
            && preg_match('/TRIGGER(?:;[^:]*)?:(-?)P(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?/', $vm[0], $tm)) {
            $seconds = ((int) ($tm[2] ?? 0)) * 86400 + ((int) ($tm[3] ?? 0)) * 3600 + ((int) ($tm[4] ?? 0)) * 60 + (int) ($tm[5] ?? 0);
            $delta = ($tm[1] === '-' ? -1 : 1) * $seconds;
            $remindAt = $start['dt']->modify(($delta >= 0 ? '+' : '-') . abs($delta) . ' seconds');
        }

        return [
            'uid'         => self::prop($block, 'UID')['value'] ?? null,
            'summary'     => self::unescape(self::prop($block, 'SUMMARY')['value'] ?? ''),
            'description' => self::unescape(self::prop($block, 'DESCRIPTION')['value'] ?? ''),
            'location'    => self::unescape(self::prop($block, 'LOCATION')['value'] ?? ''),
            'allDay'      => $start['allDay'],
            'start'       => $start['dt'],
            'end'         => $end['dt'],
            'remindAt'    => $remindAt,
        ];
    }

    /**
     * Build a full VCALENDAR/VEVENT. $f: uid, summary, description, location, allDay,
     * start/end (DateTimeImmutable), remindMinutesBefore (int|null).
     */
    public static function buildEvent(array $f): string
    {
        $uid = ($f['uid'] ?? '') ?: bin2hex(random_bytes(16)) . '@haven';
        $fmt = $f['allDay'] ? 'Ymd' : 'Ymd\THis\Z';
        $lines = [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Best Life Apps//Haven//EN', 'BEGIN:VEVENT',
            'UID:' . self::escape($uid),
            'DTSTAMP:' . gmdate('Ymd\THis\Z'),
            ($f['allDay'] ? 'DTSTART;VALUE=DATE:' : 'DTSTART:') . $f['start']->format($fmt),
            ($f['allDay'] ? 'DTEND;VALUE=DATE:' : 'DTEND:') . $f['end']->format($fmt),
            'SUMMARY:' . self::escape((string) $f['summary']),
        ];
        if (!empty($f['location'])) {
            $lines[] = 'LOCATION:' . self::escape($f['location']);
        }
        if (!empty($f['description'])) {
            $lines[] = 'DESCRIPTION:' . self::escape($f['description']);
        }
        if (!empty($f['remindMinutesBefore']) && (int) $f['remindMinutesBefore'] > 0) {
            $lines[] = 'BEGIN:VALARM';
            $lines[] = 'ACTION:DISPLAY';
            $lines[] = 'DESCRIPTION:Reminder';
            $lines[] = 'TRIGGER:-PT' . (int) $f['remindMinutesBefore'] . 'M';
            $lines[] = 'END:VALARM';
        }
        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';
        return implode("\r\n", $lines) . "\r\n";
    }

    // ---------- Line-level helpers ----------

    private static function prop(string $block, string $name): ?array
    {
        if (!preg_match('/^' . preg_quote($name, '/') . '(;[^:]*)?:(.*)$/mi', $block, $m)) {
            return null;
        }
        $params = [];
        foreach (explode(';', trim($m[1] ?? '', ';')) as $p) {
            if (str_contains($p, '=')) {
                [$k, $v] = explode('=', $p, 2);
                $params[strtoupper($k)] = $v;
            }
        }
        return ['params' => $params, 'value' => trim($m[2])];
    }

    /** Returns ['dt' => DateTimeImmutable (UTC), 'allDay' => bool] or null. */
    private static function parseDate(?array $p): ?array
    {
        if (!$p || $p['value'] === '') {
            return null;
        }
        $val = $p['value'];
        if (($p['params']['VALUE'] ?? '') === 'DATE' || preg_match('/^\d{8}$/', $val)) {
            $dt = \DateTimeImmutable::createFromFormat('!Ymd', substr($val, 0, 8), new \DateTimeZone('UTC'));
            return $dt ? ['dt' => $dt, 'allDay' => true] : null;
        }
        $val = rtrim($val, 'Z');
        $dt = \DateTimeImmutable::createFromFormat('!Ymd\THis', $val, new \DateTimeZone('UTC'));
        return $dt ? ['dt' => $dt, 'allDay' => false] : null;
    }

    private static function escape(string $s): string
    {
        return str_replace(["\\", "\n", ",", ";"], ["\\\\", '\\n', '\\,', '\\;'], $s);
    }

    private static function unescape(string $s): string
    {
        return str_replace(['\\n', '\\N', '\\,', '\\;', '\\\\'], ["\n", "\n", ',', ';', '\\'], $s);
    }
}
