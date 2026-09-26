<?php
declare(strict_types=1);

namespace BlaCloud\Controllers;

use BlaCloud\Audit;
use BlaCloud\Auth;
use BlaCloud\Config;
use BlaCloud\Database;
use BlaCloud\Mailer;
use BlaCloud\Request;
use BlaCloud\Scope;
use BlaCloud\Security;
use BlaCloud\Session;
use BlaCloud\Settings;
use BlaCloud\Shares;
use BlaCloud\Storage;
use BlaCloud\StorageException;
use BlaCloud\View;

/** Owner side of sharing: the share dialog (JSON), "Shared with me" and "Shared by me" pages. */
final class ShareController
{
    /** JSON: everything the share dialog needs for one item. */
    public function info(): void
    {
        $u = Auth::requireUser();
        $fs = new Storage((int) $u['id']);
        try {
            $rel = Storage::normalize(Request::get('path'));
            $isDir = is_dir($fs->abs($rel));
        } catch (StorageException $e) {
            View::json(['ok' => false, 'error' => $e->getMessage()], 400);
        }
        $people = array_map(static fn ($p) => ['id' => (int) $p['id'], 'name' => $p['display_name'] ?: $p['username'], 'username' => $p['username']],
            Database::all('SELECT id, username, display_name FROM bla_users WHERE id <> ? AND is_active = 1 ORDER BY LOWER(display_name), LOWER(username)', [$u['id']]));
        $default = (int) Settings::get('links_default_days');
        View::json([
            'ok'       => true,
            'name'     => basename($rel),
            'isDir'    => $isDir,
            'people'   => $people,
            'shares'   => array_map([$this, 'forJson'], Shares::forItem((int) $u['id'], $rel)),
            'links'    => (bool) Settings::get('links_enabled'),
            'needPass' => (bool) Settings::get('links_require_password'),
            'maxDays'  => (int) Settings::get('links_max_days'),
            'defaultExpiry' => $default > 0 ? gmdate('Y-m-d', time() + $default * 86400) : '',
            'mail'     => Mailer::enabled(),
            'labels'   => Shares::LABELS,
        ]);
    }

    private function forJson(array $s): array
    {
        return [
            'id'       => (int) $s['id'],
            'type'     => $s['share_type'],
            'perms'    => $s['perms'],
            'permLabel' => Shares::LABELS[$s['perms']] ?? $s['perms'],
            'who'      => $s['share_type'] === 'user' ? ($s['display_name'] ?: $s['username']) : ($s['label'] ?: 'Public link'),
            'url'      => $s['url'],
            'password' => $s['has_password'],
            'expires'  => $s['expires_at'] ? substr($s['expires_at'], 0, 10) : null,
            'expired'  => $s['expired'],
            'opens'    => (int) $s['access_count'],
        ];
    }

    public function withUser(): void
    {
        $u = $this->guard();
        $fs = new Storage((int) $u['id']);
        $rel = Request::post('path');
        try {
            [$id, $to, $new] = Shares::shareWithUser($fs, $rel, (int) Request::post('recipient'), Request::post('perms'));
        } catch (StorageException $e) {
            View::json(['ok' => false, 'error' => $e->getMessage()], 400);
        }
        Audit::log((int) $u['id'], 'share.user', Storage::normalize($rel) . ' -> ' . $to['username'] . ' (' . Request::post('perms') . ')');
        $mailed = false;
        if ($new && Settings::get('share_notify') && $to['email'] !== '' && Mailer::enabled()) {
            $from = $u['display_name'] ?: $u['username'];
            $what = basename(Storage::normalize($rel));
            $cloud = (string) Config::get('instance_name', 'Haven');
            $mailed = Mailer::send($to['email'], "$from shared “{$what}” with you", "$from shared something with you", [
                "Hi " . ($to['display_name'] ?: $to['username']) . ',',
                "$from shared “{$what}” with you on $cloud (" . (Shares::LABELS[Request::post('perms')] ?? '') . ').',
                'You can find it under “Shared with me” after signing in.',
            ], 'Open ' . $cloud, Settings::absoluteUrl('shared')) === null;
        }
        View::json(['ok' => true, 'message' => $new ? 'Shared with ' . ($to['display_name'] ?: $to['username']) . ($mailed ? ' — we emailed them.' : '.') : 'Permission updated.']);
    }

    public function link(): void
    {
        $u = $this->guard();
        $fs = new Storage((int) $u['id']);
        $rel = Request::post('path');
        try {
            [$id, $token] = Shares::createLink($fs, $rel, Request::post('perms', 'view'),
                Request::post('password'), Request::post('expires'), Request::post('label'));
        } catch (StorageException $e) {
            View::json(['ok' => false, 'error' => $e->getMessage()], 400);
        }
        Audit::log((int) $u['id'], 'share.link', Storage::normalize($rel) . ' (' . Request::post('perms', 'view') . ')');
        View::json(['ok' => true, 'url' => Settings::absoluteUrl('s', ['t' => $token]), 'message' => 'Link created.']);
    }

    public function emailLink(): void
    {
        $u = $this->guard();
        $row = Database::one("SELECT * FROM bla_shares WHERE id = ? AND owner_id = ? AND share_type = 'link'", [(int) Request::post('id'), $u['id']]);
        $to = trim(Request::post('to'));
        if (!$row || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            View::json(['ok' => false, 'error' => 'Please enter a valid email address.'], 400);
        }
        $from = $u['display_name'] ?: $u['username'];
        $what = basename($row['path']);
        $p = ["$from shared “{$what}” with you."];
        if ($row['password_hash'] !== null) {
            $p[] = "It's protected with a password — $from will give it to you separately.";
        }
        if ($row['expires_at']) {
            $p[] = 'The link works until ' . date('F j, Y', (int) strtotime($row['expires_at'] . ' UTC')) . '.';
        }
        $err = Mailer::send($to, "$from shared “{$what}” with you", "A file for you", $p, 'Open', Shares::linkUrl($row));
        if ($err) {
            View::json(['ok' => false, 'error' => 'Email failed: ' . $err], 400);
        }
        Audit::log((int) $u['id'], 'share.email', $row['path'] . ' -> ' . $to);
        View::json(['ok' => true, 'message' => "Link emailed to $to."]);
    }

    public function delete(): void
    {
        $u = $this->guard();
        $row = Shares::delete((int) $u['id'], (int) Request::post('id'));
        if ($row) {
            Audit::log((int) $u['id'], 'share.delete', $row['path'] . ' (' . $row['share_type'] . ')');
        }
        if (Request::wantsJson()) {
            View::json(['ok' => (bool) $row, 'message' => 'Sharing stopped.']);
        }
        Session::flash('success', 'Sharing stopped.');
        View::redirect('shared-by-me');
    }

    // ---------- Pages ----------

    public function sharedWithMe(): void
    {
        $u = Auth::requireUser();
        $items = Shares::withUser((int) $u['id']);
        foreach ($items as &$it) {
            $it['type'] = (int) $it['is_dir'] ? 'folder' : Storage::kind($it['path']);
        }
        View::render('files/shared', ['title' => 'Shared with me', 'nav' => 'shared', 'user' => $u, 'items' => $items]);
    }

    public function sharedByMe(): void
    {
        $u = Auth::requireUser();
        View::render('files/shared-by-me', ['title' => 'Shared by me', 'nav' => 'shared-by-me', 'user' => $u,
            'items' => Shares::byOwner((int) $u['id'])]);
    }

    public function leave(): void
    {
        $u = Auth::requireUser();
        if (Request::isPost()) {
            Security::requireCsrf();
            if (Shares::leave((int) $u['id'], (int) Request::post('id'))) {
                Audit::log((int) $u['id'], 'share.leave', (string) Request::post('id'));
                Session::flash('success', 'Removed from your list.');
            }
        }
        View::redirect('shared');
    }

    private function guard(): array
    {
        $u = Auth::requireUser();
        if (!Request::isPost()) {
            View::json(['ok' => false, 'error' => 'Method not allowed'], 405);
        }
        Security::requireCsrf();
        return $u;
    }
}
