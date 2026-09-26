<?php
declare(strict_types=1);

namespace BlaCloud\Controllers;

use BlaCloud\AppPasswords;
use BlaCloud\Dav\CalendarBackend;
use BlaCloud\Dav\ContactsBackend;
use BlaCloud\Dav\FilesBackend;
use BlaCloud\Dav\Raw;
use BlaCloud\Dav\Xml;
use BlaCloud\Request;
use BlaCloud\StorageException;

/**
 * Entry point for /dav/... — WebDAV (files), CalDAV (calendars) and CardDAV (address books).
 * Stateless: every request authenticates itself with HTTP Basic auth using an app password
 * (see Settings > Sync), never the account password or a session cookie.
 */
final class DavController
{
    public function handle(): void
    {
        $method = Request::method();
        $segments = self::segments();

        if ($method === 'OPTIONS' && $segments === []) {
            self::sendOptions();
            return;
        }

        $user = $this->authenticate();
        if (!$user) {
            header('WWW-Authenticate: Basic realm="BLA-Cloud"');
            http_response_code(401);
            header('Content-Type: text/plain; charset=utf-8');
            echo "Authentication required. Use an app password (Settings > Sync), not your account password.\n";
            return;
        }

        if ($method === 'OPTIONS') {
            self::sendOptions();
            return;
        }

        try {
            $top = $segments[0] ?? '';
            $rest = array_slice($segments, 1);
            match ($top) {
                ''             => $this->rootCollection(),
                'principals'   => $this->principal($rest, $user),
                'files'        => (new FilesBackend($user))->handle($method, $rest),
                'calendars'    => (new CalendarBackend($user))->handle($method, $rest),
                'addressbooks' => (new ContactsBackend($user))->handle($method, $rest),
                default        => self::notFound(),
            };
        } catch (StorageException $e) {
            http_response_code($e->status());
            header('Content-Type: text/plain; charset=utf-8');
            echo $e->getMessage() . "\n";
        }
    }

    /** Path segments after "/dav/", url-decoded, no empty parts. */
    private static function segments(): array
    {
        $path = Request::path(); // "/dav/files/admin/Photos"
        $path = preg_replace('~^/dav/?~', '', $path) ?? '';
        if ($path === '') {
            return [];
        }
        return array_map(static fn ($s) => rawurldecode($s), explode('/', trim($path, '/')));
    }

    public static function baseHref(): string
    {
        return rtrim(Request::basePath(), '/') . '/dav';
    }

    private function authenticate(): ?array
    {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (!str_starts_with($authHeader, 'Basic ')) {
            return null;
        }
        $decoded = base64_decode(substr($authHeader, 6), true);
        if ($decoded === false || !str_contains($decoded, ':')) {
            return null;
        }
        [$username, $secret] = explode(':', $decoded, 2);
        return AppPasswords::verify($username, $secret);
    }

    private static function sendOptions(): void
    {
        http_response_code(200);
        header('DAV: 1, 2, 3, extended-mkcol, calendar-access, addressbook');
        header('Allow: OPTIONS, GET, HEAD, PUT, DELETE, PROPFIND, PROPPATCH, MKCOL, MKCALENDAR, MOVE, COPY, LOCK, UNLOCK, REPORT');
        header('MS-Author-Via: DAV');
        header('Content-Length: 0');
    }

    private static function notFound(): void
    {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Not found.\n";
    }

    /** GET/PROPFIND on "/dav/" itself: just enough for clients probing before they know the username. */
    private function rootCollection(): void
    {
        if (Request::method() !== 'PROPFIND') {
            http_response_code(405);
            return;
        }
        $ms = new Xml();
        $ms->addFound(self::baseHref() . '/', [
            '{DAV:}resourcetype'         => new Raw('<d:collection/>'),
            '{DAV:}displayname'          => 'BLA-Cloud',
            '{DAV:}current-user-principal' => new Raw('<d:href>' . Xml::e(self::baseHref() . '/principals/current-user/') . '</d:href>'),
        ]);
        $ms->send();
    }

    /** The principal collection for one user — where CalDAV/CardDAV clients discover their home sets. */
    private function principal(array $rest, array $user): void
    {
        if (Request::method() !== 'PROPFIND') {
            http_response_code(405);
            return;
        }
        $who = $rest[0] ?? 'current-user';
        if ($who !== 'current-user' && strcasecmp($who, $user['username']) !== 0) {
            http_response_code(403);
            return;
        }
        $base = self::baseHref();
        $me = rawurlencode($user['username']);
        $ms = new Xml();
        $ms->addFound(Request::path(), [
            '{DAV:}resourcetype'         => new Raw('<d:principal/>'),
            '{DAV:}displayname'          => $user['display_name'] ?: $user['username'],
            '{DAV:}current-user-principal' => new Raw('<d:href>' . Xml::e("$base/principals/$me/") . '</d:href>'),
            '{DAV:}principal-URL'        => new Raw('<d:href>' . Xml::e("$base/principals/$me/") . '</d:href>'),
            '{urn:ietf:params:xml:ns:caldav}calendar-home-set'
                => new Raw('<d:href>' . Xml::e("$base/calendars/$me/") . '</d:href>'),
            '{urn:ietf:params:xml:ns:carddav}addressbook-home-set'
                => new Raw('<d:href>' . Xml::e("$base/addressbooks/$me/") . '</d:href>'),
        ]);
        $ms->send();
    }
}
