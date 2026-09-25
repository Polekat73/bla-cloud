<?php
declare(strict_types=1);

namespace BlaCloud\Controllers;

use BlaCloud\Audit;
use BlaCloud\Database;
use BlaCloud\Mailer;
use BlaCloud\Request;
use BlaCloud\Security;
use BlaCloud\Session;
use BlaCloud\Settings;
use BlaCloud\Tokens;
use BlaCloud\Users;
use BlaCloud\View;

/** Pages reached from emails or links while signed out: forgot password, reset, accept invitation. */
final class AccountController
{
    public function forgot(): void
    {
        $sent = false;
        if (Request::isPost()) {
            Security::requireCsrf();
            $who = trim(Request::post('who'));
            $ip = Request::clientIp();
            $recent = (int) Database::one("SELECT COUNT(*) AS n FROM bla_login_attempts WHERE ip = ? AND kind = 'reset' AND created_at > ?",
                [$ip, gmdate('Y-m-d H:i:s', time() - 3600)])['n'];
            Database::run("INSERT INTO bla_login_attempts (ip, username, kind, success, created_at) VALUES (?, ?, 'reset', 0, ?)",
                [$ip, mb_substr(mb_strtolower($who), 0, 64), Database::now()]);
            // Only when a fixed public address is configured (stops spoofed-Host attacks on reset links).
            if ($recent < 5 && $who !== '' && Mailer::enabled() && Settings::get('base_url') !== '') {
                $u = Database::one('SELECT * FROM bla_users WHERE is_active = 1 AND email <> \'\' AND (LOWER(username) = LOWER(?) OR LOWER(email) = LOWER(?))', [$who, $who]);
                if ($u && $u['password_hash'] !== Users::NO_PASSWORD) {
                    Users::sendReset($u);
                    Audit::log((int) $u['id'], 'password.reset_requested');
                }
            }
            usleep(random_int(300000, 600000)); // same timing whether or not the account exists
            $sent = true;
        }
        View::render('auth/forgot', ['title' => 'Forgot password', 'sent' => $sent, 'mail' => Mailer::enabled()], 'layout-auth');
    }

    public function reset(): void
    {
        $this->choosePassword('reset');
    }

    public function invite(): void
    {
        $this->choosePassword('invite');
    }

    private function choosePassword(string $kind): void
    {
        $token = Request::get('t');
        $row = Tokens::find($token, $kind);
        if (!$row) {
            http_response_code(410);
            View::render('auth/link-expired', ['title' => 'Link expired', 'kind' => $kind], 'layout-auth');
            return;
        }
        $error = null;
        if (Request::isPost()) {
            Security::requireCsrf();
            $p1 = Request::post('password');
            if ($p1 !== Request::post('password_confirm')) {
                $error = 'The two passwords do not match.';
            } elseif ($problem = Security::passwordProblem($p1, $row['username'])) {
                $error = $problem;
            } elseif (Tokens::consume((int) $row['id'])) {
                Database::run('UPDATE bla_users SET password_hash = ? WHERE id = ?', [Security::hashPassword($p1), $row['user_id']]);
                Database::run('UPDATE bla_tokens SET used_at = ? WHERE user_id = ? AND kind = ? AND used_at IS NULL',
                    [Database::now(), $row['user_id'], $kind]);
                Audit::log((int) $row['user_id'], $kind === 'invite' ? 'invite.accepted' : 'password.reset');
                Session::regenerate();
                Session::flash('success', $kind === 'invite'
                    ? 'Your account is ready. Sign in with your new password.'
                    : 'Your password was changed and all your devices were signed out. Sign in with your new password.');
                View::redirect('login');
            } else {
                $error = 'This link was just used. Please request a new one.';
            }
        }
        View::render('auth/choose-password', [
            'title' => $kind === 'invite' ? 'Welcome' : 'Choose a new password',
            'kind'  => $kind,
            'row'   => $row,
            'token' => $token,
            'error' => $error,
        ], 'layout-auth');
    }
}
