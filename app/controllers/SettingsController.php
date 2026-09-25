<?php
declare(strict_types=1);

namespace BlaCloud\Controllers;

use BlaCloud\Audit;
use BlaCloud\Auth;
use BlaCloud\Database;
use BlaCloud\RecoveryCodes;
use BlaCloud\Request;
use BlaCloud\Security;
use BlaCloud\Session;
use BlaCloud\View;

final class SettingsController
{
    public function index(): void
    {
        $u = Auth::requireUser();
        View::render('settings/index', [
            'title'     => 'Security settings',
            'nav'       => 'settings',
            'user'      => $u,
            'remaining' => RecoveryCodes::remaining((int) $u['id']),
            'activity'  => Audit::recent(15, (int) $u['id']),
            'required'  => Auth::twoFactorRequired($u),
        ]);
    }

    public function password(): void
    {
        $u = Auth::requireUser();
        if (!Request::isPost()) {
            View::redirect('settings');
        }
        Security::requireCsrf();
        if (Request::post('new_password') !== Request::post('new_password_confirm')) {
            Session::flash('error', 'The two new passwords do not match.');
            View::redirect('settings');
        }
        $err = Auth::changePassword($u, Request::post('current_password'), Request::post('new_password'));
        Session::flash($err ? 'error' : 'success', $err ?? 'Your password was changed. Other devices have been signed out.');
        View::redirect('settings');
    }

    public function twoFactor(): void
    {
        $u = Auth::requireUser();
        if (!Request::isPost()) {
            View::redirect('settings');
        }
        Security::requireCsrf();
        // Sensitive actions need the current password again.
        if (!password_verify(Request::post('current_password'), $u['password_hash'])) {
            Session::flash('error', 'Your current password is not correct.');
            View::redirect('settings');
        }
        $action = Request::post('action');
        if ($action === 'regenerate' && (int) $u['totp_enabled'] === 1) {
            $_SESSION['new_recovery_codes'] = RecoveryCodes::regenerate((int) $u['id']);
            View::redirect('recovery-codes');
        }
        if ($action === 'disable' && (int) $u['totp_enabled'] === 1) {
            if (Auth::twoFactorRequired($u)) {
                Session::flash('error', 'Two-step verification is required for administrators and cannot be turned off.');
                View::redirect('settings');
            }
            Database::run('UPDATE bla_users SET totp_enabled = 0, totp_secret = NULL, totp_last_step = 0 WHERE id = ?', [$u['id']]);
            Database::run('DELETE FROM bla_recovery_codes WHERE user_id = ?', [$u['id']]);
            Audit::log((int) $u['id'], '2fa.disabled');
            Session::flash('warning', 'Two-step verification is now off. We recommend turning it back on.');
        }
        View::redirect('settings');
    }
}
