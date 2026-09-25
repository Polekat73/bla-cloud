<?php
declare(strict_types=1);

/* Small helpers used inside view templates. */

use BlaCloud\Security;
use BlaCloud\View;

function e(?string $s): string
{
    return Security::e($s);
}

function url(string $route, array $query = []): string
{
    return View::url($route, $query);
}

function asset(string $path): string
{
    return View::asset($path);
}

function csrf_field(): string
{
    return Security::csrfField();
}

function icon(string $name, string $class = ''): string
{
    return '<svg class="icon ' . e($class) . '" aria-hidden="true" focusable="false"><use href="#i-' . e($name) . '"></use></svg>';
}

function human_time(int $ts): string
{
    $d = time() - $ts;
    if ($d < 60) {
        return 'just now';
    }
    if ($d < 3600) {
        return intdiv($d, 60) . ' min ago';
    }
    if ($d < 86400) {
        return intdiv($d, 3600) . ' h ago';
    }
    if ($d < 86400 * 7) {
        return intdiv($d, 86400) . ' d ago';
    }
    return date('M j, Y', $ts);
}
