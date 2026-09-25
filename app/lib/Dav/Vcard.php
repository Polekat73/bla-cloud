<?php
declare(strict_types=1);

namespace BlaCloud\Dav;

/** Just enough vCard 3.0 reading/writing for the built-in contacts app. */
final class Vcard
{
    /** Always returns an array (never null) — missing fields just come back empty. */
    public static function parseContact(string $vcf): array
    {
        $unfolded = preg_replace("/\r?\n[ \t]/", '', $vcf) ?? $vcf;
        $lines = preg_split('/\r?\n/', $unfolded) ?: [];

        $fn = '';
        $given = '';
        $family = '';
        $note = '';
        $uid = '';
        $phones = [];
        $emails = [];
        $address = ['street' => '', 'city' => '', 'region' => '', 'postal' => '', 'country' => ''];
        $photo = null;

        foreach ($lines as $line) {
            if (!preg_match('/^([A-Za-z-]+)((?:;[^:]*)*):(.*)$/', $line, $m)) {
                continue;
            }
            [$_, $name, $paramStr, $value] = $m;
            $name = strtoupper($name);
            $params = [];
            foreach (explode(';', trim($paramStr, ';')) as $p) {
                if ($p === '') {
                    continue;
                }
                [$pk, $pv] = str_contains($p, '=') ? explode('=', $p, 2) : [$p, ''];
                $params[strtoupper($pk)] = strtoupper($pv);
            }
            switch ($name) {
                case 'FN':
                    $fn = self::unescape($value);
                    break;
                case 'N':
                    $parts = array_pad(explode(';', $value), 2, '');
                    $family = self::unescape($parts[0]);
                    $given = self::unescape($parts[1]);
                    break;
                case 'UID':
                    $uid = trim($value);
                    break;
                case 'NOTE':
                    $note = self::unescape($value);
                    break;
                case 'TEL':
                    $phones[] = ['type' => self::typeLabel($params, 'cell'), 'value' => trim($value)];
                    break;
                case 'EMAIL':
                    $emails[] = ['type' => self::typeLabel($params, 'home'), 'value' => trim($value)];
                    break;
                case 'ADR':
                    $parts = array_pad(explode(';', $value), 7, '');
                    // ADR: pobox;ext;street;city;region;postal;country
                    $address = [
                        'street' => self::unescape($parts[2]), 'city' => self::unescape($parts[3]),
                        'region' => self::unescape($parts[4]), 'postal' => self::unescape($parts[5]),
                        'country' => self::unescape($parts[6]),
                    ];
                    break;
                case 'PHOTO':
                    if (($params['ENCODING'] ?? '') === 'B' || ($params['VALUE'] ?? '') === 'BASE64') {
                        $type = strtolower($params['TYPE'] ?? 'jpeg');
                        $photo = 'data:image/' . ($type === 'jpg' ? 'jpeg' : $type) . ';base64,' . trim($value);
                    }
                    break;
            }
        }
        return [
            'uid' => $uid, 'fn' => $fn ?: trim("$given $family"), 'given' => $given, 'family' => $family,
            'phones' => $phones, 'emails' => $emails, 'address' => $address, 'note' => $note, 'photo' => $photo,
        ];
    }

    private static function typeLabel(array $params, string $default): string
    {
        foreach (['CELL', 'HOME', 'WORK', 'FAX', 'OTHER'] as $t) {
            if (($params['TYPE'] ?? '') === $t || isset($params[$t])) {
                return strtolower($t);
            }
        }
        return $default;
    }

    /**
     * $f: uid, given, family, phones (list of [type,value]), emails (list of [type,value]),
     * address ([street,city,region,postal,country]), note, photoBase64 + photoType (optional).
     */
    public static function buildContact(array $f): string
    {
        $uid = ($f['uid'] ?? '') ?: bin2hex(random_bytes(16)) . '@bla-cloud';
        $given = trim((string) ($f['given'] ?? ''));
        $family = trim((string) ($f['family'] ?? ''));
        $fn = trim("$given $family") ?: ($f['fn'] ?? 'New contact');
        $lines = [
            'BEGIN:VCARD', 'VERSION:3.0', 'UID:' . self::escape($uid),
            'N:' . self::escape($family) . ';' . self::escape($given) . ';;;',
            'FN:' . self::escape($fn),
        ];
        foreach ((array) ($f['phones'] ?? []) as $p) {
            if (trim((string) ($p['value'] ?? '')) !== '') {
                $lines[] = 'TEL;TYPE=' . strtoupper(self::safeType($p['type'] ?? 'cell')) . ':' . self::escape(trim($p['value']));
            }
        }
        foreach ((array) ($f['emails'] ?? []) as $e) {
            if (trim((string) ($e['value'] ?? '')) !== '') {
                $lines[] = 'EMAIL;TYPE=' . strtoupper(self::safeType($e['type'] ?? 'home')) . ':' . self::escape(trim($e['value']));
            }
        }
        $a = (array) ($f['address'] ?? []);
        if (array_filter($a)) {
            $lines[] = 'ADR;TYPE=HOME:;;' . self::escape($a['street'] ?? '') . ';' . self::escape($a['city'] ?? '') . ';'
                . self::escape($a['region'] ?? '') . ';' . self::escape($a['postal'] ?? '') . ';' . self::escape($a['country'] ?? '');
        }
        if (!empty($f['note'])) {
            $lines[] = 'NOTE:' . self::escape($f['note']);
        }
        if (!empty($f['photoBase64'])) {
            $type = strtoupper($f['photoType'] ?? 'JPEG');
            $lines[] = self::fold('PHOTO;ENCODING=b;TYPE=' . $type . ':' . $f['photoBase64']);
        }
        $lines[] = 'END:VCARD';
        return implode("\r\n", $lines) . "\r\n";
    }

    private static function safeType(string $t): string
    {
        return in_array(strtolower($t), ['cell', 'home', 'work', 'fax', 'other'], true) ? $t : 'other';
    }

    /** Fold a line to 75 octets per RFC 6350, continuation lines start with a space. */
    private static function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }
        $out = substr($line, 0, 75);
        $rest = substr($line, 75);
        while ($rest !== '') {
            $out .= "\r\n " . substr($rest, 0, 74);
            $rest = substr($rest, 74);
        }
        return $out;
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
