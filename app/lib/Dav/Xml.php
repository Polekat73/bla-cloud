<?php
declare(strict_types=1);

namespace BlaCloud\Dav;

/**
 * Minimal WebDAV/CalDAV/CardDAV XML plumbing: reading a request body and writing
 * a <multistatus> response, without pulling in a library.
 */
final class Xml
{
    public const NS_DAV      = 'DAV:';
    public const NS_CALDAV   = 'urn:ietf:params:xml:ns:caldav';
    public const NS_CARDDAV  = 'urn:ietf:params:xml:ns:carddav';
    public const NS_CS       = 'http://calendarserver.org/ns/';

    /** Parse the request body as XML. Returns null if there isn't one (allprop). */
    public static function body(): ?\DOMDocument
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return null;
        }
        $doc = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $ok = $doc->loadXML($raw, LIBXML_NONET);
        libxml_use_internal_errors($prev);
        return $ok ? $doc : null;
    }

    /**
     * The list of "{namespace}local-name" properties a PROPFIND asked for, or null for
     * allprop / an empty body (meaning: send everything we have).
     */
    public static function propfindProps(?\DOMDocument $doc): ?array
    {
        if ($doc === null) {
            return null;
        }
        $prop = $doc->getElementsByTagNameNS(self::NS_DAV, 'prop')->item(0);
        if (!$prop) {
            return null; // allprop or propname
        }
        $props = [];
        foreach ($prop->childNodes as $node) {
            if ($node instanceof \DOMElement) {
                $props[] = '{' . $node->namespaceURI . '}' . $node->localName;
            }
        }
        return $props;
    }

    /** hrefs requested by a calendar-multiget / addressbook-multiget REPORT. */
    public static function multigetHrefs(\DOMDocument $doc): array
    {
        $out = [];
        foreach ($doc->getElementsByTagNameNS(self::NS_DAV, 'href') as $node) {
            $out[] = trim($node->textContent);
        }
        return $out;
    }

    public static function reportName(?\DOMDocument $doc): ?string
    {
        if ($doc === null || !$doc->documentElement) {
            return null;
        }
        return $doc->documentElement->localName;
    }

    // ---------- Building a <multistatus> response ----------

    private array $responses = [];

    public function addFound(string $href, array $props): void
    {
        $this->responses[] = ['href' => $href, 'found' => $props, 'missing' => []];
    }

    public function addFoundMissing(string $href, array $found, array $missingNames): void
    {
        $this->responses[] = ['href' => $href, 'found' => $found, 'missing' => $missingNames];
    }

    public function addStatus(string $href, int $status): void
    {
        $this->responses[] = ['href' => $href, 'status' => $status];
    }

    public function send(int $httpStatus = 207): void
    {
        http_response_code($httpStatus);
        header('Content-Type: application/xml; charset=utf-8');
        header('DAV: 1, 2, 3, extended-mkcol, calendar-access, addressbook');
        echo '<?xml version="1.0" encoding="utf-8"?>' . "\n";
        echo '<d:multistatus xmlns:d="' . self::NS_DAV . '" xmlns:cal="' . self::NS_CALDAV
           . '" xmlns:card="' . self::NS_CARDDAV . '" xmlns:cs="' . self::NS_CS . '">' . "\n";
        foreach ($this->responses as $r) {
            echo '<d:response><d:href>' . self::e($r['href']) . '</d:href>';
            if (isset($r['status'])) {
                echo '<d:status>HTTP/1.1 ' . $r['status'] . ' ' . self::reason($r['status']) . '</d:status>';
            } else {
                if ($r['found']) {
                    echo '<d:propstat><d:prop>';
                    foreach ($r['found'] as $name => $value) {
                        echo self::propTag($name, $value);
                    }
                    echo '</d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat>';
                }
                if ($r['missing']) {
                    echo '<d:propstat><d:prop>';
                    foreach ($r['missing'] as $name) {
                        echo self::emptyTag($name);
                    }
                    echo '</d:prop><d:status>HTTP/1.1 404 Not Found</d:status></d:propstat>';
                }
            }
            echo '</d:response>' . "\n";
        }
        echo '</d:multistatus>';
    }

    /** "{ns}local" -> the matching "d:"/"cal:"/"card:"/"cs:" tag prefix, falling back to a raw namespace decl. */
    private static function prefixFor(string $ns): string
    {
        return match ($ns) {
            self::NS_DAV     => 'd',
            self::NS_CALDAV  => 'cal',
            self::NS_CARDDAV => 'card',
            self::NS_CS      => 'cs',
            default          => 'x',
        };
    }

    private static function splitName(string $name): array
    {
        preg_match('/^\{(.*)\}(.*)$/', $name, $m);
        return [$m[1] ?? self::NS_DAV, $m[2] ?? $name];
    }

    private static function emptyTag(string $name): string
    {
        [$ns, $local] = self::splitName($name);
        $p = self::prefixFor($ns);
        return "<$p:$local" . ($p === 'x' ? ' xmlns:x="' . self::e($ns) . '"' : '') . "/>";
    }

    /** $value can be a raw string (escaped) or a Raw instance (already-valid inner XML). */
    private static function propTag(string $name, mixed $value): string
    {
        [$ns, $local] = self::splitName($name);
        $p = self::prefixFor($ns);
        $xmlns = $p === 'x' ? ' xmlns:x="' . self::e($ns) . '"' : '';
        $inner = $value instanceof Raw ? $value->xml : self::e((string) $value);
        return "<$p:$local$xmlns>$inner</$p:$local>";
    }

    public static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private static function reason(int $status): string
    {
        return match ($status) {
            200 => 'OK', 201 => 'Created', 204 => 'No Content', 207 => 'Multi-Status',
            400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden', 404 => 'Not Found',
            409 => 'Conflict', 412 => 'Precondition Failed', 423 => 'Locked', 507 => 'Insufficient Storage',
            default => 'Status',
        };
    }
}
