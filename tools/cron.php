<?php
/**
 * Optional real cron entry point, for hosts that allow it — runs the same housekeeping
 * (trash/version cleanup, calendar reminders, scheduled backups) that otherwise only happens
 * as a side effect of someone visiting the site. Wire it up with, for example:
 *
 *   crontab -e, then add (runs every 15 minutes):
 *   0,15,30,45 * * * * php /path/to/bla-cloud/tools/cron.php
 *
 * Each of those jobs already limits itself to running at most once an hour (or once a day/week
 * for backups), so calling this more often than that is harmless — it just checks and exits.
 * See docs/INSTALL.md for details.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

use BlaCloud\Config;
use BlaCloud\Maintenance;
use BlaCloud\Schema;

if (!Config::isInstalled()) {
    fwrite(STDERR, "BLA-Cloud is not installed yet — nothing to do.\n");
    exit(0);
}

Schema::ensureUpToDate();
Maintenance::run();
