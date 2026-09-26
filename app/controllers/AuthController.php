<?php
declare(strict_types=1);

namespace BlaCloud\Controllers;

use BlaCloud\Auth;
use BlaCloud\Config;
use BlaCloud\RecoveryCodes;
use BlaCloud\Request;
use BlaCloud\Security;
use BlaCloud\Session;
use BlaCloud\Totp;
use BlaCloud\View;

final class AuthController
{
    public function login(): void
    {
        if (Auth::user()) {
            View::redirect('files');
        }
        $error = null;
        $username = '';
        if (Request::isPost()) {
            Security::requireCsrf();
            $username = Request::post('username');
            $result = Auth::attemptPassword($username, Request::post('password'));
            match ($result) {
                'ok'     => View::redirect('files'),
                '2fa'    => View::redirect('2fa'),
                'enroll' => View::redirect('2fa-setup'),
                default  => null,
            };
            $error = $result === 'throttled'
                ? 'Too many sign-in attempts. Please wait 15 minutes and try again.'
                : 'That username or password is not correct.';
            http_response_code($result === 'throttled' ? 429 : 401);
        }
        View::render('auth/login', ['title' => 'Sign in', 'error' => $error, 'username' => $username], 'layout-auth');
    }

    public function logout(): void
    {
        if (!Request::isPost()) {
            View::redirect('files');
        }
        Security::requireCsrf();
        Auth::logout();
        Session::start();
        Session::flash('success', 'You have been signed out.');
        View::redirect('login');
    }

    /** Second step of sign-in: authenticator code or recovery code. */
    public function twoFactor(): void
    {
        $u = Auth::pendingUser('pending_2fa');
        if (!$u) {
            Session::flash('warning', 'Please sign in again.');
            View::redirect('login');
        }
        $error = null;
        if (Request::isPost()) {
            Security::requireCsrf();
            $r = Auth::attemptSecondFactor(Request::post('code'));
            if ($r === 'ok') {
                View::redirect('files');
            }
            $error = $r === 'throttled'
                ? 'Too many attempts. Please wait 15 minutes and try again.'
                : 'That code did not work. Check the time on your phone is correct, or use a recovery code.';
        }
        View::render('auth/2fa', ['title' => 'Two-step verification', 'error' => $error], 'layout-auth');
    }

    /** Set up an authenticator app. Used both for forced setup (admins) and from Settings. */
    public function twoFactorSetup(): void
    {
        $forced = false;
        $u = Auth::user();
        if (!$u) {
            $u = Auth::pendingUser('pending_enroll');
            $forced = true;
        }
        if (!$u) {
            View::redirect('login');
        }
        if ((int) $u['totp_enabled'] === 1 && !$forced) {
            Session::flash('info', 'Two-step verification is already on.');
            View::redirect('settings');
        }
        if (empty($_SESSION['enroll_secret']) || ($_SESSION['enroll_uid'] ?? 0) !== (int) $u['id']) {
            $_SESSION['enroll_secret'] = Totp::generateSecret();
            $_SESSION['enroll_uid'] = (int) $u['id'];
        }
        $secret = $_SESSION['enroll_secret'];
        $error = null;

        if (Request::isPost()) {
            Security::requireCsrf();
            if (Auth::enableTotp($u, $secret, Request::post('code'))) {
                unset($_SESSION['enroll_secret'], $_SESSION['enroll_uid']);
                $_SESSION['new_recovery_codes'] = RecoveryCodes::regenerate((int) $u['id']);
                View::redirect('recovery-codes');
            }
            $error = 'That code did not match. Make sure you scanned the new code, then enter the 6 digits shown right now.';
        }

        $issuer = (string) Config::get('instance_name', 'Haven');
        View::render('auth/2fa-setup', [
            'title'  => 'Set up two-step verification',
            'secret' => $secret,
            'uri'    => Totp::uri($secret, $u['username'], $issuer),
            'forced' => $forced,
            'error'  => $error,
        ], $forced ? 'layout-auth' : 'layout');
    }

    /** Shows freshly generated recovery codes exactly once. */
    public function recoveryCodes(): void
    {
        Auth::requireUser();
        $codes = $_SESSION['new_recovery_codes'] ?? null;
        if (Request::isPost()) {
            Security::requireCsrf();
            unset($_SESSION['new_recovery_codes']);
            Session::flash('success', 'Two-step verification is on. Your account is protected.');
            View::redirect('files');
        }
        if (!$codes) {
            View::redirect('settings');
        }
        View::render('auth/recovery-codes', ['title' => 'Save your recovery codes', 'codes' => $codes]);
    }
}
