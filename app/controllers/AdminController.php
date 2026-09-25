<?php
declare(strict_types=1);

namespace BlaCloud\Controllers;

use BlaCloud\Audit;
use BlaCloud\Auth;
use BlaCloud\Config;
use BlaCloud\Database;
use BlaCloud\Installer;
use BlaCloud\Request;
use BlaCloud\View;

/** Admin: system health and recent security activity. */
final class AdminController
{
    public function status(): void
    {
        $u = Auth::requireAdmin();
        $dataDir = (string) Config::get('data_dir');
        $free  = @disk_free_space($dataDir);
        $total = @disk_total_space($dataDir);

        $checks = array_values(array_filter(Installer::checks(), static fn ($c) => $c['label'] !== 'Config folder writable'));
        $checks[] = [
            'label'  => 'Data folder',
            'status' => is_writable($dataDir) ? (Installer::isInsideWebRoot($dataDir) ? 'warn' : 'ok') : 'fail',
            'detail' => is_writable($dataDir)
                ? (Installer::isInsideWebRoot($dataDir)
                    ? 'Inside the public web folder. Protected by .htaccess on Apache — on Nginx add the rule from docs/INSTALL.md, or move it.'
                    : 'Outside the public web folder')
                : 'Not writable!',
        ];
        $checks[] = [
            'label'  => 'Config file protection',
            'status' => is_writable(Config::path()) ? 'warn' : 'ok',
            'detail' => is_writable(Config::path())
                ? 'config/config.php is still writable. After setup you can make it read-only (chmod 440).'
                : 'Read-only',
        ];
        $checks[] = [
            'label'  => 'Disk space',
            'status' => ($free !== false && $free < 1024 ** 3) ? 'warn' : 'ok',
            'detail' => $free !== false ? View::bytes($free) . ' free of ' . View::bytes((float) $total) : 'Unknown',
        ];
        try {
            Database::one('SELECT 1 AS ok');
            $dbOk = true;
        } catch (\Throwable) {
            $dbOk = false;
        }
        $checks[] = ['label' => 'Database', 'status' => $dbOk ? 'ok' : 'fail',
            'detail' => ($dbOk ? 'Connected' : 'Not reachable') . ' (' . (Database::driver() === 'mysql' ? 'MySQL/MariaDB' : 'SQLite') . ')'];

        $checks[] = ['label' => 'Email', 'status' => \BlaCloud\Mailer::enabled() ? 'ok' : 'warn',
            'detail' => \BlaCloud\Mailer::enabled() ? 'Set up (' . \BlaCloud\Settings::get('mail_mode') . ')'
                : 'Not set up — invitations and password resets must be sent by hand. Configure it in Settings.'];
        $checks[] = ['label' => 'Public web address', 'status' => \BlaCloud\Settings::get('base_url') !== '' ? 'ok' : 'warn',
            'detail' => \BlaCloud\Settings::get('base_url') !== '' ? (string) \BlaCloud\Settings::get('base_url')
                : 'Not set — needed for password reset emails. Set it in Settings.'];
        $admins2fa = Database::one('SELECT COUNT(*) AS n FROM bla_users WHERE is_admin = 1 AND totp_enabled = 0');
        $checks[] = ['label' => 'Admin two-step verification', 'status' => ((int) $admins2fa['n'] === 0) ? 'ok' : 'warn',
            'detail' => ((int) $admins2fa['n'] === 0) ? 'All administrators use two-step verification'
                : $admins2fa['n'] . ' administrator(s) have not finished setting it up'];

        View::render('admin/status', [
            'title'    => 'System status',
            'nav'      => 'admin',
            'user'     => $u,
            'checks'   => $checks,
            'activity' => Audit::recent(40),
            'info'     => [
                'BLA-Cloud version' => BLA_VERSION,
                'PHP'               => PHP_VERSION . ' (' . PHP_SAPI . ')',
                'Server'            => (string) ($_SERVER['SERVER_SOFTWARE'] ?? 'unknown'),
                'Behind proxy'      => Config::get('trusted_proxies') ? 'Yes (' . implode(', ', (array) Config::get('trusted_proxies')) . ')' : 'No',
                'Your IP'           => Request::clientIp(),
                'Installed'         => (string) Config::get('installed_at', ''),
            ],
        ]);
    }
}
