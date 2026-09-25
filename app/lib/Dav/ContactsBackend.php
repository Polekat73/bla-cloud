<?php
declare(strict_types=1);

namespace BlaCloud\Dav;

use BlaCloud\Controllers\DavController;
use BlaCloud\Database;

/**
 * CardDAV, mounted at /dav/addressbooks/{username}/. Same approach as CalendarBackend:
 * contacts are stored as raw vCard text and handed back unchanged.
 */
final class ContactsBackend
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
        $this->baseHref = DavController::baseHref() . '/addressbooks/' . rawurlencode($this->username);
        $bookUri = $segments[0] ?? null;
        $objUri = $segments[1] ?? null;

        if ($bookUri === null) {
            $this->handleHome($method);
            return;
        }
        $book = Database::one('SELECT * FROM bla_addressbooks WHERE user_id = ? AND uri = ?', [$this->user['id'], $bookUri]);
        if (!$book) {
            http_response_code(404);
            return;
        }
        if ($objUri === null) {
            match ($method) {
                'PROPFIND'  => $this->propfindCollection($book),
                'REPORT'    => $this->report($book),
                default     => http_response_code(405),
            };
            return;
        }
        match ($method) {
            'GET', 'HEAD' => $this->getObject($book, $objUri, $method === 'HEAD'),
            'PUT'         => $this->putObject($book, $objUri),
            'DELETE'      => $this->deleteObject($book, $objUri),
            default       => http_response_code(405),
        };
    }

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
            '{DAV:}resourcetype' => new Raw('<d:collection/>'),
            '{DAV:}displayname'  => 'Address books',
        ]);
        if ($depth !== '0') {
            foreach (Database::all('SELECT * FROM bla_addressbooks WHERE user_id = ? ORDER BY id', [$this->user['id']]) as $book) {
                $this->addBookProps($ms, $book, $requested);
            }
        }
        $ms->send();
    }

    private function propfindCollection(array $book): void
    {
        $depth = $_SERVER['HTTP_DEPTH'] ?? '1';
        $requested = Xml::propfindProps(Xml::body());
        $ms = new Xml();
        $this->addBookProps($ms, $book, $requested);
        if ($depth !== '0') {
            foreach (Database::all('SELECT * FROM bla_contacts WHERE addressbook_id = ? ORDER BY id', [$book['id']]) as $c) {
                $this->addContactProps($ms, $book, $c, $requested);
            }
        }
        $ms->send();
    }

    private function addBookProps(Xml $ms, array $book, ?array $requested): void
    {
        $href = $this->baseHref . '/' . rawurlencode($book['uri']) . '/';
        $all = [
            '{DAV:}resourcetype'    => new Raw('<d:collection/><card:addressbook/>'),
            '{DAV:}displayname'     => $book['display_name'],
            '{urn:ietf:params:xml:ns:carddav}addressbook-description' => '',
            '{urn:ietf:params:xml:ns:carddav}supported-address-data'
                => new Raw('<card:address-data-type content-type="text/vcard" version="3.0"/>'),
            '{http://calendarserver.org/ns/}getctag' => (string) $book['ctag'],
        ];
        $this->emit($ms, $href, $all, $requested);
    }

    private function addContactProps(Xml $ms, array $book, array $c, ?array $requested): void
    {
        $href = $this->baseHref . '/' . rawurlencode($book['uri']) . '/' . rawurlencode($c['uri']);
        $all = [
            '{DAV:}resourcetype'    => new Raw(''),
            '{DAV:}getcontenttype'  => 'text/vcard; charset=utf-8',
            '{DAV:}getetag'         => '"' . $c['etag'] . '"',
            '{DAV:}getlastmodified' => gmdate('D, d M Y H:i:s', strtotime($c['updated_at'] . ' UTC')) . ' GMT',
            '{urn:ietf:params:xml:ns:carddav}address-data' => $c['data'],
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

    private function getObject(array $book, string $uri, bool $headOnly): void
    {
        $c = Database::one('SELECT * FROM bla_contacts WHERE addressbook_id = ? AND uri = ?', [$book['id'], $uri]);
        if (!$c) {
            http_response_code(404);
            return;
        }
        $etag = '"' . $c['etag'] . '"';
        header('ETag: ' . $etag);
        header('Content-Type: text/vcard; charset=utf-8');
        if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
            http_response_code(304);
            return;
        }
        header('Content-Length: ' . strlen($c['data']));
        http_response_code(200);
        if (!$headOnly) {
            echo $c['data'];
        }
    }

    private function putObject(array $book, string $uri): void
    {
        $data = (string) file_get_contents('php://input');
        if (!str_contains($data, 'BEGIN:VCARD')) {
            http_response_code(400);
            echo "Expected a vCard.\n";
            return;
        }
        $existing = Database::one('SELECT * FROM bla_contacts WHERE addressbook_id = ? AND uri = ?', [$book['id'], $uri]);
        if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === '*' && $existing) {
            http_response_code(412);
            return;
        }
        $ifMatch = $_SERVER['HTTP_IF_MATCH'] ?? '';
        if ($ifMatch !== '' && $existing && $ifMatch !== '"' . $existing['etag'] . '"') {
            http_response_code(412);
            return;
        }
        $uid = Vobject::extractUid($data) ?: $uri;
        $etag = md5($data);
        if ($existing) {
            Database::run('UPDATE bla_contacts SET uid = ?, etag = ?, data = ?, updated_at = ? WHERE id = ?',
                [$uid, $etag, $data, Database::now(), $existing['id']]);
        } else {
            Database::run('INSERT INTO bla_contacts (addressbook_id, uri, uid, etag, data, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$book['id'], $uri, $uid, $etag, $data, Database::now(), Database::now()]);
        }
        Database::run('UPDATE bla_addressbooks SET ctag = ctag + 1 WHERE id = ?', [$book['id']]);
        header('ETag: "' . $etag . '"');
        http_response_code($existing ? 204 : 201);
    }

    private function deleteObject(array $book, string $uri): void
    {
        $c = Database::one('SELECT id FROM bla_contacts WHERE addressbook_id = ? AND uri = ?', [$book['id'], $uri]);
        if (!$c) {
            http_response_code(404);
            return;
        }
        Database::run('DELETE FROM bla_contacts WHERE id = ?', [$c['id']]);
        Database::run('UPDATE bla_addressbooks SET ctag = ctag + 1 WHERE id = ?', [$book['id']]);
        http_response_code(204);
    }

    private function report(array $book): void
    {
        $doc = Xml::body();
        $name = Xml::reportName($doc);
        $requested = Xml::propfindProps($doc);
        $ms = new Xml();
        if ($name === 'addressbook-multiget' && $doc) {
            foreach (Xml::multigetHrefs($doc) as $href) {
                $uri = rawurldecode(basename(rtrim($href, '/')));
                $c = Database::one('SELECT * FROM bla_contacts WHERE addressbook_id = ? AND uri = ?', [$book['id'], $uri]);
                if ($c) {
                    $this->addContactProps($ms, $book, $c, $requested);
                } else {
                    $ms->addStatus($href, 404);
                }
            }
        } else {
            foreach (Database::all('SELECT * FROM bla_contacts WHERE addressbook_id = ? ORDER BY id', [$book['id']]) as $c) {
                $this->addContactProps($ms, $book, $c, $requested);
            }
        }
        $ms->send();
    }
}
