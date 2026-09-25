<?php
declare(strict_types=1);

namespace BlaCloud\Controllers;

use BlaCloud\Audit;
use BlaCloud\Auth;
use BlaCloud\FileSender;
use BlaCloud\Installer;
use BlaCloud\Request;
use BlaCloud\Scope;
use BlaCloud\Security;
use BlaCloud\Session;
use BlaCloud\Settings;
use BlaCloud\Shares;
use BlaCloud\Storage;
use BlaCloud\StorageException;
use BlaCloud\Thumbnails;
use BlaCloud\Trash;
use BlaCloud\Versions;
use BlaCloud\View;
use BlaCloud\Zipper;

/**
 * The file browser. Works on three kinds of "scope": your own files, a folder someone shared
 * with you (?share=ID), or a public link (?t=TOKEN). Permissions are checked on every action.
 */
final class FilesController
{
    private static function param(string $k): string
    {
        return Request::get($k) !== '' ? Request::get($k) : Request::post($k);
    }

    /** Work out who may do what, or stop the request. */
    public static function scope(): Scope
    {
        $token = self::param('t');
        if ($token !== '') {
            $share = Shares::findLink($token);
            if (!$share) {
                self::deny(404, 'This link has expired or was removed.');
            }
            if ($share['password_hash'] !== null && empty($_SESSION['links_ok'][(int) $share['id']])) {
                if (Request::wantsJson()) {
                    View::json(['ok' => false, 'error' => 'Please enter the link password again.'], 401);
                }
                View::redirect('s', ['t' => $token]);
            }
            return Scope::forLink($share, $token);
        }
        $user = Auth::requireUser();
        $shareId = (int) self::param('share');
        if ($shareId > 0) {
            $share = Shares::findForRecipient($shareId, (int) $user['id']);
            if (!$share) {
                self::deny(404, 'This share is no longer available.');
            }
            return Scope::forShare($share, $user);
        }
        return Scope::own($user);
    }

    private static function deny(int $status, string $message): never
    {
        http_response_code($status);
        if (Request::wantsJson()) {
            View::json(['ok' => false, 'error' => $message], $status);
        }
        View::render('error', ['title' => $status === 404 ? 'Not available' : 'Not allowed', 'message' => $message],
            self::param('t') !== '' ? 'layout-public' : 'layout');
        exit;
    }

    /** Add viewer / thumbnail / shared hints for the front-end. */
    private static function decorate(array $items, Scope $scope): array
    {
        $shared = $scope->isOwn() ? Shares::sharedPaths($scope->fs->userId()) : [];
        foreach ($items as &$it) {
            $it['viewer'] = $it['dir'] ? null : FileSender::viewer($it['name']);
            $it['thumb'] = !$it['dir'] && Thumbnails::supports($it['name']);
            $it['shared'] = $shared[$it['path']] ?? [];
        }
        return $items;
    }

    private static function crumbs(string $sub, Scope $scope): array
    {
        $crumbs = [['name' => $scope->rootName, 'path' => '']];
        $acc = '';
        foreach (array_filter(explode('/', $sub), 'strlen') as $seg) {
            $acc .= '/' . $seg;
            $crumbs[] = ['name' => $seg, 'path' => $acc];
        }
        return $crumbs;
    }

    public function index(): void
    {
        $scope = self::scope();
        $query = trim(Request::get('q'));
        try {
            $path = Storage::normalize(Request::get('path'));
            $drop = !$scope->can('list');
            $items = $drop ? [] : ($query !== '' ? $scope->search($query) : $scope->list($path));
        } catch (StorageException $e) {
            Session::flash('error', $e->getMessage());
            View::redirect('files', $scope->params);
        }
        if ($scope->kind === 'link' && $path === '' && $query === '') {
            Shares::touch((int) $scope->share['id']);
        }
        $vars = [
            'title'     => $query !== '' ? 'Search' : ($path === '' ? $scope->rootName : basename($path)),
            'nav'       => $scope->kind === 'user' ? 'shared' : 'files',
            'scope'     => $scope,
            'path'      => $path,
            'query'     => $query,
            'items'     => self::decorate($items, $scope),
            'crumbs'    => self::crumbs($path, $scope),
            'chunkSize' => Installer::chunkSize(),
            'zip'       => Zipper::available(),
        ];
        if ($scope->isOwn()) {
            $vars['usage'] = $scope->fs->usage();
            $vars['free'] = @disk_free_space($scope->fs->root()) ?: null;
        }
        View::render('files/index', $vars, $scope->kind === 'link' ? 'layout-public' : 'layout');
    }

    // ---------- Upload ----------

    public function upload(): void
    {
        if (!Request::isPost()) {
            View::json(['ok' => false, 'error' => 'Method not allowed'], 405);
        }
        Security::requireCsrf();
        $scope = self::scope();
        $file = $_FILES['chunk'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            $code = is_array($file) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
            $msg = in_array($code, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? 'The server rejected this piece of the upload as too large.'
                : 'The upload did not arrive. Please try again.';
            View::json(['ok' => false, 'error' => $msg], 400);
        }
        try {
            $scope->require('upload');
            if ($scope->isFile && (!$scope->can('edit') || Request::post('name') !== basename($scope->base))) {
                // A shared single file can only be replaced by a new version of itself.
                throw new StorageException('Only a new version of “' . basename($scope->base) . '” can be uploaded here.', 403);
            }
            // Visitors who can't edit may add files, but never overwrite existing ones.
            $res = $scope->fs->receiveChunk(
                Request::post('upload_id'),
                (int) Request::post('offset', '0'),
                $file['tmp_name'],
                Request::post('final') === '1',
                $scope->isFile ? Storage::parent($scope->base) : $scope->toOwner(Request::post('dir')),
                Request::post('name'),
                (int) Request::post('total', '0'),
                $scope->can('edit'),
            );
        } catch (StorageException $e) {
            View::json(['ok' => false, 'error' => $e->getMessage()], $e->status());
        }
        if ($res !== null) {
            Audit::log($scope->actorId, $res['replaced'] ? 'file.replace' : 'file.upload', $res['path'] . $scope->logNote());
            $res['path'] = $scope->toSub($res['path']);
        }
        View::json(['ok' => true, 'done' => $res !== null, 'path' => $res['path'] ?? null, 'replaced' => $res['replaced'] ?? false]);
    }

    // ---------- Simple actions ----------

    public function mkdir(): void
    {
        $scope = $this->postGuard();
        $dir = Request::post('dir');
        try {
            $scope->require('edit');
            $rel = $scope->fs->mkdir($scope->toOwner($dir), Request::post('name'));
            Audit::log($scope->actorId, 'folder.create', $rel . $scope->logNote());
            Session::flash('success', 'Folder created.');
        } catch (StorageException $e) {
            Session::flash('error', $e->getMessage());
        }
        $this->back($scope, $dir);
    }

    public function rename(): void
    {
        $scope = $this->postGuard();
        $target = Request::post('target');
        try {
            $scope->require('edit');
            if (!$scope->isOwn() && Storage::normalize($target) === '') {
                throw new StorageException('The shared item itself can only be renamed by its owner.');
            }
            $old = $scope->toOwner($target);
            $rel = $scope->fs->rename($old, Request::post('name'));
            Audit::log($scope->actorId, 'file.rename', $old . ' -> ' . $rel . $scope->logNote());
            Session::flash('success', 'Renamed.');
        } catch (StorageException $e) {
            Session::flash('error', $e->getMessage());
        }
        $this->back($scope, Request::post('return'));
    }

    /** Moves the selected items to the (owner's) trash. */
    public function delete(): void
    {
        $scope = $this->postGuard();
        $done = 0;
        try {
            $scope->require('edit');
            foreach ($this->targets() as $t) {
                try {
                    if (!$scope->isOwn() && Storage::normalize($t) === '') {
                        throw new StorageException('The shared item itself can only be deleted by its owner.');
                    }
                    $rel = $scope->toOwner($t);
                    $scope->fs->delete($rel);
                    Audit::log($scope->actorId, 'file.trash', $rel . $scope->logNote());
                    $done++;
                } catch (StorageException $e) {
                    Session::flash('error', $e->getMessage());
                }
            }
        } catch (StorageException $e) {
            Session::flash('error', $e->getMessage());
        }
        if ($done) {
            Session::flash('success', ($done === 1 ? 'Moved 1 item' : "Moved $done items") . ' to the trash'
                . ($scope->isOwn() ? '. You can restore it from Trash for ' . Trash::retentionDays() . ' days.' : ' of its owner, who can restore it.'));
        }
        $this->back($scope, Request::post('dir'));
    }

    public function move(): void
    {
        $this->moveOrCopy('move');
    }

    public function copy(): void
    {
        $this->moveOrCopy('copy');
    }

    private function moveOrCopy(string $op): void
    {
        $scope = $this->postGuard();
        $fs = $scope->fs;
        $done = 0;
        try {
            if (!$scope->isOwn()) {
                throw new StorageException('Moving and copying works in your own files.', 403);
            }
            $dest = Storage::normalize(Request::post('dest'));
            foreach ($this->targets() as $t) {
                try {
                    $new = $op === 'move' ? $fs->move($t, $dest) : $fs->copy($t, $dest);
                    Audit::log($scope->actorId, "file.$op", $t . ' -> ' . $new);
                    $done++;
                } catch (StorageException $e) {
                    Session::flash('error', $e->getMessage());
                }
            }
        } catch (StorageException $e) {
            Session::flash('error', $e->getMessage());
        }
        if ($done) {
            $where = ($dest ?? '') === '' ? 'My files' : basename($dest);
            Session::flash('success', ($op === 'move' ? 'Moved ' : 'Copied ') . ($done === 1 ? '1 item' : "$done items") . " to “{$where}”.");
        }
        $this->back($scope, Request::post('dir'));
    }

    /** JSON: sub-folders of a folder, for the move/copy picker (own files only). */
    public function folders(): void
    {
        $u = Auth::requireUser();
        $fs = new Storage((int) $u['id']);
        try {
            $path = Storage::normalize(Request::get('path'));
            $folders = array_map(static fn ($f) => ['name' => $f['name'], 'path' => $f['path']], $fs->listFolders($path));
        } catch (StorageException $e) {
            View::json(['ok' => false, 'error' => $e->getMessage()], 400);
        }
        View::json(['ok' => true, 'path' => $path, 'crumbs' => self::crumbs($path, Scope::own($u)), 'folders' => $folders]);
    }

    // ---------- Viewing & downloading ----------

    public function download(): void
    {
        $scope = self::scope();
        [$rel, $abs] = $this->resolveFile($scope);
        Audit::log($scope->actorId, 'file.download', $rel . $scope->logNote());
        FileSender::download($abs, basename($abs), (int) filemtime($abs));
    }

    public function view(): void
    {
        $scope = self::scope();
        [, $abs] = $this->resolveFile($scope);
        FileSender::inline($abs, basename($abs));
    }

    public function thumb(): void
    {
        $scope = self::scope();
        try {
            $scope->require('view');
            [$file, $mime] = (new Thumbnails($scope->fs))->get($scope->toOwner(Request::get('path')), (int) Request::get('s', '256'));
        } catch (StorageException $e) {
            http_response_code(in_array($e->status(), [400, 403], true) ? 404 : $e->status());
            exit;
        }
        FileSender::thumbnail($file, $mime);
    }

    /** Zip download of one or more files/folders (form POST). */
    public function zip(): void
    {
        $scope = $this->postGuard();
        try {
            $scope->require('view');
            $paths = array_map(fn ($t) => $scope->toOwner($t), $this->targets());
            [$tmp, $name] = Zipper::build($scope->fs, $paths);
        } catch (StorageException $e) {
            Session::flash('error', $e->getMessage());
            $this->back($scope, Request::post('dir'));
        }
        Audit::log($scope->actorId, 'file.zip', implode(', ', array_slice($paths, 0, 10)) . $scope->logNote());
        Zipper::send($tmp, $name);
    }

    // ---------- Versions (own files only) ----------

    private function ownVersions(): array
    {
        $u = Auth::requireUser();
        return [$u, new Versions(new Storage((int) $u['id']))];
    }

    public function versions(): void
    {
        [, $v] = $this->ownVersions();
        try {
            $list = $v->list(Storage::normalize(Request::get('path')));
        } catch (StorageException $e) {
            View::json(['ok' => false, 'error' => $e->getMessage()], 400);
        }
        View::json(['ok' => true, 'keep' => Versions::keep(), 'versions' => array_map(static fn ($x) => [
            'id'       => (int) $x['id'],
            'size'     => View::bytes((int) $x['size']),
            'modified' => date('M j, Y H:i', (int) $x['file_mtime']),
            'saved'    => $x['created_at'],
        ], $list)]);
    }

    public function versionDownload(): void
    {
        [, $v] = $this->ownVersions();
        try {
            [$row, $blob] = $v->blobFor((int) Request::get('id'));
        } catch (StorageException $e) {
            http_response_code(404);
            View::render('error', ['title' => 'Not found', 'message' => $e->getMessage()]);
            return;
        }
        $ext = pathinfo($row['path'], PATHINFO_EXTENSION);
        $name = pathinfo($row['path'], PATHINFO_FILENAME) . ' (' . date('Y-m-d H.i', (int) $row['file_mtime']) . ')' . ($ext !== '' ? ".$ext" : '');
        FileSender::download($blob, $name, (int) $row['file_mtime']);
    }

    public function versionRestore(): void
    {
        $this->versionAction('restore');
    }

    public function versionDelete(): void
    {
        $this->versionAction('delete');
    }

    private function versionAction(string $what): void
    {
        [$u, $v] = $this->ownVersions();
        if (!Request::isPost()) {
            View::redirect('files');
        }
        Security::requireCsrf();
        try {
            $row = $v->find((int) Request::post('id'));
            $what === 'restore' ? $v->restore((int) $row['id']) : $v->delete((int) $row['id']);
            Audit::log((int) $u['id'], "version.$what", $row['path']);
            $msg = $what === 'restore' ? 'That version is back. The one it replaced was kept as a version too.' : 'Version deleted.';
            $this->jsonOrBack(true, $msg, Storage::parent($row['path']));
        } catch (StorageException $e) {
            $this->jsonOrBack(false, $e->getMessage(), Request::post('dir'));
        }
    }

    // ---------- Helpers ----------

    private function resolveFile(Scope $scope): array
    {
        try {
            $scope->require('view');
            $rel = $scope->toOwner(Request::get('path'));
            $abs = $scope->fs->abs($rel);
        } catch (StorageException $e) {
            self::deny($e->status() === 403 ? 403 : 404, $e->getMessage());
        }
        if (!is_file($abs)) {
            self::deny(400, 'Use “Download as zip” for folders.');
        }
        return [$rel, $abs];
    }

    /** Selected paths from a form (targets[]). */
    private function targets(): array
    {
        $t = $_POST['targets'] ?? [];
        return array_slice(is_array($t) ? array_values(array_filter($t, 'is_string')) : [], 0, 500);
    }

    private function postGuard(): Scope
    {
        if (!Request::isPost()) {
            View::redirect('files');
        }
        Security::requireCsrf();
        return self::scope();
    }

    private function jsonOrBack(bool $ok, string $message, string $dir): never
    {
        if (Request::wantsJson()) {
            View::json(['ok' => $ok, 'message' => $message, 'error' => $ok ? null : $message], $ok ? 200 : 400);
        }
        Session::flash($ok ? 'success' : 'error', $message);
        View::redirect('files', $dir === '' ? [] : ['path' => $dir]);
    }

    private function back(Scope $scope, string $dir): never
    {
        try {
            $dir = Storage::normalize($dir);
        } catch (StorageException) {
            $dir = '';
        }
        View::redirect('files', ($dir === '' ? [] : ['path' => $dir]) + $scope->params);
    }
}
