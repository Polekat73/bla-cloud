<?php
declare(strict_types=1);

namespace BlaCloud;

final class Request
{
    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public static function isPost(): bool
    {
        return self::method() === 'POST';
    }

    public static function get(string $key, string $default = ''): string
    {
        $v = $_GET[$key] ?? $default;
        return is_string($v) ? $v : $default;
    }

    public static function post(string $key, string $default = ''): string
    {
        $v = $_POST[$key] ?? $default;
        return is_string($v) ? $v : $default;
    }

    public static function wantsJson(): bool
    {
        return str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
            || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
    }

    /** Is the direct peer one of the configured trusted reverse proxies? */
    private static function fromTrustedProxy(): bool
    {
        $remote  = $_SERVER['REMOTE_ADDR'] ?? '';
        $trusted = (array) Config::get('trusted_proxies', []);
        foreach ($trusted as $cidr) {
            if (self::ipInRange($remote, (string) $cidr)) {
                return true;
            }
        }
        return false;
    }

    public static function clientIp(): string
    {
        $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        if (self::fromTrustedProxy() && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            // Walk right-to-left, skipping our own proxies.
            $chain = array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']));
            for ($i = count($chain) - 1; $i >= 0; $i--) {
                $ip = $chain[$i];
                if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                    continue;
                }
                $isProxy = false;
                foreach ((array) Config::get('trusted_proxies', []) as $cidr) {
                    if (self::ipInRange($ip, (string) $cidr)) {
                        $isProxy = true;
                        break;
                    }
                }
                if (!$isProxy) {
                    return $ip;
                }
            }
        }
        return $remote;
    }

    public static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if (($_SERVER['SERVER_PORT'] ?? '') === '443') {
            return true;
        }
        if (self::fromTrustedProxy()) {
            return strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        }
        return false;
    }

    public static function isLocalhost(): bool
    {
        $host = strtolower(explode(':', $_SERVER['HTTP_HOST'] ?? '')[0]);
        return in_array($host, ['localhost', '127.0.0.1', '[::1]', '::1'], true)
            || str_ends_with($host, '.localhost');
    }

    /** Base URL path of the app, e.g. "" or "/cloud". */
    public static function basePath(): string
    {
        $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
        $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
        return $dir === '.' ? '' : $dir;
    }

    public static function ipInRange(string $ip, string $cidr): bool
    {
        if (!str_contains($cidr, '/')) {
            return $ip === $cidr;
        }
        [$subnet, $bits] = explode('/', $cidr, 2);
        $ipBin  = @inet_pton($ip);
        $netBin = @inet_pton($subnet);
        if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
            return false;
        }
        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        $rem   = $bits % 8;
        if (substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
            return false;
        }
        if ($rem === 0) {
            return true;
        }
        $mask = chr((0xFF << (8 - $rem)) & 0xFF);
        return (($ipBin[$bytes] & $mask) === ($netBin[$bytes] & $mask));
    }
}
