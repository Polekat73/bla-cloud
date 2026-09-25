<?php
/**
 * BLA-Cloud — private cloud by Best Life Apps
 * Front controller. Every request goes through this file.
 */
declare(strict_types=1);

if (PHP_VERSION_ID < 80200) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "BLA-Cloud needs PHP 8.2 or newer. This server runs PHP " . PHP_VERSION . ".\n"
       . "Ask your hosting provider (or your control panel) to switch this site to PHP 8.2+.";
    exit;
}

require __DIR__ . '/app/bootstrap.php';

BlaCloud\App::run();
