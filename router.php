<?php
/**
 * Dev-only router for `php -S` (the built-in server doesn't read .htaccess).
 * Apache/Nginx/Caddy use their own config in production — see docs/INSTALL.md.
 */
declare(strict_types=1);

$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Let the built-in server serve real files directly (assets, etc.), except our protected folders.
$blocked = ['/app/', '/config/', '/data/', '/tests/', '/docs/'];
foreach ($blocked as $b) {
    if (str_starts_with($path, $b)) {
        require __DIR__ . '/index.php';
        return true;
    }
}
$file = __DIR__ . $path;
if ($path !== '/' && is_file($file)) {
    return false;
}

require __DIR__ . '/index.php';
