<?php
declare(strict_types=1);

namespace BlaCloud\Controllers;

use BlaCloud\Audit;
use BlaCloud\Auth;
use BlaCloud\Mailer;
use BlaCloud\Request;
use BlaCloud\Security;
use BlaCloud\Session;
use BlaCloud\Settings;
use BlaCloud\View;

/** Admin: cloud-wide settings (email, security policy, sharing, storage). */
final class AdminSettingsController
{
    public function index(): void
    {
        $me = Auth::requireAdmin();
        if (Request::isPost()) {
            Security::requireCsrf();
            $err = $this->save();
            Session::flash($err ? 'error' : 'success', $err ?? 'Settings saved.');
            if (!$err) {
                Audit::log((int) $me['id'], 'settings.update');
            }
            View::redirect('admin.settings');
        }
        $s = Settings::all();
        if ($s['base_url'] === '') {
            $s['base_url'] = Settings::baseUrl(); // suggest the current address
        }
        View::render('admin/settings', [
            'title' => 'Settings',
            'nav'   => 'admin.settings',
            'user'  => $me,
            's'     => $s,
            'mailOn' => Mailer::enabled(),
        ]);
    }

    private function save(): ?string
    {
        $in = [];
        $base = rtrim(trim(Request::post('base_url')), '/');
        if ($base !== '' && !preg_match('#^https?://[A-Za-z0-9.\-]+(:\d+)?(/[A-Za-z0-9._~\-/]*)?$#', $base)) {
            return 'The web address should look like https://cloud.example.com';
        }
        $in['base_url'] = $base;
        foreach (['require_2fa_all', 'links_enabled', 'links_require_password', 'share_notify'] as $b) {
            $in[$b] = Request::post($b) === '1';
        }
        $in['trash_days'] = max(1, min(3650, (int) Request::post('trash_days', '30')));
        $in['versions_keep'] = max(1, min(100, (int) Request::post('versions_keep', '10')));
        $in['versions_max_days'] = max(1, min(3650, (int) Request::post('versions_max_days', '180')));
        $in['default_quota_gb'] = max(0, (int) Request::post('default_quota_gb', '0'));
        $in['links_max_days'] = max(0, min(3650, (int) Request::post('links_max_days', '0')));
        $in['links_default_days'] = max(0, min(3650, (int) Request::post('links_default_days', '14')));
        if ($in['links_max_days'] > 0 && ($in['links_default_days'] === 0 || $in['links_default_days'] > $in['links_max_days'])) {
            $in['links_default_days'] = $in['links_max_days'];
        }

        $mode = Request::post('mail_mode');
        $in['mail_mode'] = in_array($mode, ['off', 'php', 'smtp'], true) ? $mode : 'off';
        $from = trim(Request::post('mail_from'));
        if ($in['mail_mode'] !== 'off' && !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            return 'Please enter a valid “From” email address.';
        }
        $in['mail_from'] = $from;
        $in['mail_from_name'] = mb_substr(str_replace(["\r", "\n"], '', trim(Request::post('mail_from_name', 'BLA-Cloud'))), 0, 64);
        $host = trim(Request::post('smtp_host'));
        if ($host !== '' && !preg_match('/^[A-Za-z0-9.\-]+$/', $host)) {
            return 'The SMTP server name looks wrong.';
        }
        $in['smtp_host'] = $host;
        $in['smtp_port'] = max(1, min(65535, (int) Request::post('smtp_port', '587')));
        $sec = Request::post('smtp_security');
        $in['smtp_security'] = in_array($sec, ['starttls', 'ssl', 'none'], true) ? $sec : 'starttls';
        $in['smtp_user'] = trim(Request::post('smtp_user'));
        $pass = Request::post('smtp_pass');
        if ($pass !== '') {
            $in['smtp_pass'] = Security::encrypt($pass); // blank = keep the saved one
        }
        if (Request::post('smtp_pass_clear') === '1') {
            $in['smtp_pass'] = '';
        }
        Settings::save($in);
        return null;
    }

    public function testMail(): void
    {
        $me = Auth::requireAdmin();
        if (!Request::isPost()) {
            View::redirect('admin.settings');
        }
        Security::requireCsrf();
        $to = trim(Request::post('to')) ?: $me['email'];
        $err = Mailer::send($to, 'BLA-Cloud test email', 'Email works!', [
            'This is a test message from your BLA-Cloud. If you can read it, invitations, password resets and share notifications will reach people too.',
        ]);
        Session::flash($err ? 'error' : 'success', $err ? 'Test email failed: ' . $err : "Test email sent to $to. Check the inbox (and spam folder).");
        View::redirect('admin.settings');
    }
}
