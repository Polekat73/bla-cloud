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
use BlaCloud\StorageException;
use BlaCloud\Tokens;
use BlaCloud\Users;
use BlaCloud\View;

/** Admin: people who can use this cloud. */
final class UsersController
{
    public function index(): void
    {
        $me = Auth::requireAdmin();
        $users = Users::all();
        foreach ($users as &$u) {
            $u['usage'] = Users::usage((int) $u['id']);
            $u['status'] = Users::status($u);
        }
        View::render('admin/users', [
            'title'   => 'People',
            'nav'     => 'users',
            'user'    => $me,
            'users'   => $users,
            'mail'    => Mailer::enabled(),
            'quotaGb' => (int) Settings::get('default_quota_gb'),
            'newLink' => $_SESSION['new_link'] ?? null,
        ]);
        unset($_SESSION['new_link']);
    }

    public function create(): void
    {
        $me = $this->guard();
        $mode = Request::post('mode'); // email | link | password
        $quotaGb = (float) Request::post('quota_gb', '0');
        try {
            $id = Users::create(
                Request::post('username'), Request::post('display_name'), Request::post('email'),
                Request::post('is_admin') === '1', (int) round($quotaGb * 1024 ** 3),
                $mode === 'password' ? Request::post('password') : null,
            );
        } catch (StorageException $e) {
            Session::flash('error', $e->getMessage());
            View::redirect('users');
        }
        $u = Users::find($id);
        Audit::log((int) $me['id'], 'user.create', $u['username']);
        if ($mode === 'password') {
            Session::flash('success', "Account “{$u['username']}” created. Share the password with them privately.");
        } else {
            [$link, $mailed, $err] = Users::invite($u, $me, $mode === 'email');
            if ($mode === 'email' && $mailed) {
                Session::flash('success', "Invitation emailed to {$u['email']}.");
            } else {
                if ($mode === 'email') {
                    Session::flash('warning', 'The invitation email could not be sent' . ($err ? " ($err)" : '') . '. Copy the link below and send it yourself.');
                }
                $_SESSION['new_link'] = ['label' => "Invitation link for {$u['username']} (valid 7 days)", 'url' => $link];
            }
        }
        View::redirect('users');
    }

    public function edit(): void
    {
        $me = Auth::requireAdmin();
        $u = Users::find((int) Request::get('id'));
        if (!$u) {
            Session::flash('error', 'That account no longer exists.');
            View::redirect('users');
        }
        if (Request::isPost()) {
            Security::requireCsrf();
            try {
                Users::update($u, [
                    'display_name' => Request::post('display_name'),
                    'email'        => Request::post('email'),
                    'is_admin'     => Request::post('is_admin') === '1',
                    'is_active'    => Request::post('is_active') === '1',
                    'quota_bytes'  => (int) round((float) Request::post('quota_gb', '0') * 1024 ** 3),
                ], (int) $me['id']);
                Audit::log((int) $me['id'], 'user.update', $u['username']);
                Session::flash('success', 'Saved.');
            } catch (StorageException $e) {
                Session::flash('error', $e->getMessage());
            }
            View::redirect('user', ['id' => $u['id']]);
        }
        View::render('admin/user-edit', [
            'title'   => $u['display_name'] ?: $u['username'],
            'nav'     => 'users',
            'user'    => $me,
            'u'       => $u,
            'status'  => Users::status($u),
            'usage'   => Users::usage((int) $u['id']),
            'mail'    => Mailer::enabled(),
            'invite'  => Tokens::pendingInvite((int) $u['id']),
            'newLink' => $_SESSION['new_link'] ?? null,
            'activity' => \BlaCloud\Audit::recent(15, (int) $u['id']),
        ]);
        unset($_SESSION['new_link']);
    }

    /** One-off actions from the user page. */
    public function action(): void
    {
        $me = $this->guard();
        $u = Users::find((int) Request::post('id'));
        if (!$u) {
            Session::flash('error', 'That account no longer exists.');
            View::redirect('users');
        }
        $name = $u['username'];
        try {
            switch (Request::post('action')) {
                case 'invite':
                    [$link, $mailed, $err] = Users::invite($u, $me);
                    Audit::log((int) $me['id'], 'user.invite', $name);
                    if ($mailed) {
                        Session::flash('success', "A new invitation was emailed to {$u['email']}.");
                    } else {
                        if ($err) {
                            Session::flash('warning', "Email failed ($err). Copy the link instead.");
                        }
                        $_SESSION['new_link'] = ['label' => "Invitation link for $name (valid 7 days)", 'url' => $link];
                    }
                    break;
                case 'reset':
                    [$link, $mailed, $err] = Users::sendReset($u, Request::post('how') === 'email');
                    Audit::log((int) $me['id'], 'user.reset_link', $name);
                    if ($mailed && Request::post('how') === 'email') {
                        Session::flash('success', "A password reset link was emailed to {$u['email']}.");
                    } else {
                        $_SESSION['new_link'] = ['label' => "Password reset link for $name (valid 1 hour, works once)", 'url' => $link];
                    }
                    break;
                case 'reset2fa':
                    Users::resetTwoFactor($u);
                    Audit::log((int) $me['id'], 'user.reset_2fa', $name);
                    Session::flash('success', "Two-step verification was turned off for $name. "
                        . (Auth::twoFactorRequired($u) ? 'They will set it up again at their next sign-in.' : ''));
                    break;
                case 'delete':
                    if (Request::post('confirm') !== $name) {
                        throw new StorageException('To delete, type the username exactly: ' . $name);
                    }
                    Users::delete($u, (int) $me['id']);
                    Audit::log((int) $me['id'], 'user.delete', $name);
                    Session::flash('success', "Deleted $name and all of their files.");
                    View::redirect('users');
            }
        } catch (StorageException $e) {
            Session::flash('error', $e->getMessage());
        }
        View::redirect('user', ['id' => $u['id']]);
    }

    private function guard(): array
    {
        $me = Auth::requireAdmin();
        if (!Request::isPost()) {
            View::redirect('users');
        }
        Security::requireCsrf();
        return $me;
    }
}
