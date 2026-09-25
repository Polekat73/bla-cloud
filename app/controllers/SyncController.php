<?php
declare(strict_types=1);

namespace BlaCloud\Controllers;

use BlaCloud\AppPasswords;
use BlaCloud\Auth;
use BlaCloud\Request;
use BlaCloud\Security;
use BlaCloud\Session;
use BlaCloud\View;

/** Settings > Sync: WebDAV/CalDAV/CardDAV URLs and per-device app passwords. */
final class SyncController
{
    public function index(): void
    {
        $u = Auth::requireUser();
        $newPassword = $_SESSION['new_app_password'] ?? null;
        unset($_SESSION['new_app_password']);
        View::render('sync/index', [
            'title'    => 'Sync',
            'nav'      => 'sync',
            'user'     => $u,
            'passwords' => AppPasswords::forUser((int) $u['id']),
            'newPassword' => $newPassword,
            'base'     => rtrim(Request::basePath(), '/'),
            'scheme'   => Request::isHttps() ? 'https' : 'http',
            'host'     => $_SERVER['HTTP_HOST'] ?? 'localhost',
        ]);
    }

    public function createAppPassword(): void
    {
        $u = Auth::requireUser();
        Security::requireCsrf();
        [$id, $secret] = AppPasswords::create((int) $u['id'], Request::post('label'));
        $_SESSION['new_app_password'] = ['id' => $id, 'secret' => $secret, 'username' => $u['username']];
        View::redirect('sync');
    }

    public function deleteAppPassword(): void
    {
        $u = Auth::requireUser();
        Security::requireCsrf();
        AppPasswords::revoke((int) $u['id'], (int) Request::post('id'));
        Session::flash('success', 'That app password no longer works.');
        View::redirect('sync');
    }
}
