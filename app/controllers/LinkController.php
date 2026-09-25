<?php
declare(strict_types=1);

namespace BlaCloud\Controllers;

use BlaCloud\Audit;
use BlaCloud\Database;
use BlaCloud\Request;
use BlaCloud\Security;
use BlaCloud\Shares;
use BlaCloud\View;

/** Public link entry point: /index.php?r=s&t=TOKEN (asks for the password when there is one). */
final class LinkController
{
    public const MAX_TRIES_PER_IP = 20;
    public const MAX_TRIES_PER_LINK = 10;

    public function open(): void
    {
        $token = Request::get('t');
        $share = Shares::findLink($token);
        if (!$share) {
            http_response_code(404);
            View::render('error', ['title' => 'Link not available', 'message' => 'This link has expired or was removed. Ask the person who sent it for a new one.'], 'layout-public');
            return;
        }
        $id = (int) $share['id'];
        if ($share['password_hash'] === null || !empty($_SESSION['links_ok'][$id])) {
            // Hand over to the file browser in "public link" mode.
            $_GET['r'] = 'files';
            (new FilesController())->index();
            return;
        }
        $error = null;
        if (Request::isPost()) {
            Security::requireCsrf();
            if ($this->throttled($id)) {
                $error = 'Too many attempts. Please wait 15 minutes.';
                http_response_code(429);
            } elseif (password_verify(Request::post('password'), $share['password_hash'])) {
                $this->record($id, true);
                session_regenerate_id(true);
                $_SESSION['links_ok'][$id] = true;
                Audit::log((int) $share['owner_id'], 'share.link_unlocked', $share['path']);
                View::redirect('s', ['t' => $token]);
            } else {
                $this->record($id, false);
                usleep(random_int(300000, 600000));
                $error = 'That password is not right.';
                http_response_code(401);
            }
        }
        View::render('files/link-password', [
            'title' => 'Protected link',
            'share' => $share,
            'token' => $token,
            'error' => $error,
        ], 'layout-public');
    }

    private function since(): string
    {
        return gmdate('Y-m-d H:i:s', time() - 15 * 60);
    }

    private function throttled(int $shareId): bool
    {
        $byIp = (int) Database::one("SELECT COUNT(*) AS n FROM bla_login_attempts WHERE ip = ? AND kind = 'link' AND success = 0 AND created_at > ?",
            [Request::clientIp(), $this->since()])['n'];
        $byLink = (int) Database::one("SELECT COUNT(*) AS n FROM bla_login_attempts WHERE username = ? AND kind = 'link' AND success = 0 AND created_at > ?",
            ['link:' . $shareId, $this->since()])['n'];
        return $byIp >= self::MAX_TRIES_PER_IP || $byLink >= self::MAX_TRIES_PER_LINK;
    }

    private function record(int $shareId, bool $ok): void
    {
        Database::run("INSERT INTO bla_login_attempts (ip, username, kind, success, created_at) VALUES (?, ?, 'link', ?, ?)",
            [Request::clientIp(), 'link:' . $shareId, $ok ? 1 : 0, Database::now()]);
    }
}
