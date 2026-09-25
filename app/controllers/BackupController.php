<?php
declare(strict_types=1);

namespace BlaCloud\Controllers;

use BlaCloud\Audit;
use BlaCloud\Auth;
use BlaCloud\Backup;
use BlaCloud\Database;
use BlaCloud\FileSender;
use BlaCloud\Request;
use BlaCloud\Security;
use BlaCloud\Session;
use BlaCloud\Settings;
use BlaCloud\StorageException;
use BlaCloud\View;

/** Admin: encrypted backups — set up, run on demand, verify (restore drill), restore, download. */
final class BackupController
{
    public function index(): void
    {
        $me = Auth::requireAdmin();
        $newPassphrase = $_SESSION['new_backup_passphrase'] ?? null;
        unset($_SESSION['new_backup_passphrase']);
        View::render('admin/backups', [
            'title'    => 'Backups',
            'nav'      => 'admin.backups',
            'user'     => $me,
            's'        => Settings::all(),
            'enabled'  => Backup::enabled(),
            'backups'  => Backup::enabled() ? Backup::list() : [],
            'insideDataDir' => Backup::enabled() && Backup::dirInsideDataDir(),
            'newPassphrase' => $newPassphrase,
        ]);
    }

    public function enable(): void
    {
        $me = Auth::requireAdmin();
        Security::requireCsrf();
        try {
            $passphrase = trim(Request::post('passphrase'));
            $generated = $passphrase === '';
            if ($generated) {
                $passphrase = Backup::generatePassphrase();
            }
            Backup::enable(Request::post('dir'), Request::post('frequency'), (int) Request::post('retention', '7'), $passphrase);
            $_SESSION['new_backup_passphrase'] = $passphrase;
            Audit::log((int) $me['id'], 'backup.enabled');
            Session::flash('success', 'Backups are turned on.' . ($generated ? ' A passphrase was generated for you — save it now, it will not be shown again.' : ''));
        } catch (StorageException $e) {
            Session::flash('error', $e->getMessage());
        }
        View::redirect('admin.backups');
    }

    public function disable(): void
    {
        $me = Auth::requireAdmin();
        Security::requireCsrf();
        Backup::disable();
        Audit::log((int) $me['id'], 'backup.disabled');
        Session::flash('warning', 'Backups are turned off. Existing backup files on disk were not deleted.');
        View::redirect('admin.backups');
    }

    public function rotatePassphrase(): void
    {
        $me = Auth::requireAdmin();
        Security::requireCsrf();
        try {
            $passphrase = trim(Request::post('passphrase')) ?: Backup::generatePassphrase();
            Backup::rotatePassphrase($passphrase);
            $_SESSION['new_backup_passphrase'] = $passphrase;
            Audit::log((int) $me['id'], 'backup.passphrase_rotated');
            Session::flash('success', 'New passphrase set. Backups made before now still need the old one.');
        } catch (StorageException $e) {
            Session::flash('error', $e->getMessage());
        }
        View::redirect('admin.backups');
    }

    public function runNow(): void
    {
        $me = Auth::requireAdmin();
        Security::requireCsrf();
        try {
            $r = Backup::run('manual');
            Session::flash('success', 'Backup created: ' . $r['filename']);
        } catch (\Throwable $e) {
            Session::flash('error', 'Backup failed: ' . $e->getMessage());
        }
        View::redirect('admin.backups');
    }

    public function download(): void
    {
        Auth::requireAdmin();
        $row = Database::one('SELECT * FROM bla_backups WHERE id = ?', [(int) Request::get('id')]);
        $path = $row ? Backup::dir() . '/' . $row['filename'] : null;
        if (!$row || $row['filename'] === '' || !$path || !is_file($path)) {
            http_response_code(404);
            View::render('error', ['title' => 'Not found', 'message' => 'That backup file is missing.']);
            return;
        }
        FileSender::download($path, $row['filename']);
    }

    public function verify(): void
    {
        Auth::requireAdmin();
        Security::requireCsrf();
        try {
            $r = Backup::verify((int) Request::post('id'), Request::post('passphrase'));
            Session::flash('success', $r['detail']);
        } catch (StorageException $e) {
            Session::flash('error', $e->getMessage());
        }
        View::redirect('admin.backups');
    }

    public function restore(): void
    {
        $me = Auth::requireAdmin();
        Security::requireCsrf();
        if (Request::post('confirm') !== 'RESTORE') {
            Session::flash('error', 'Please type RESTORE to confirm — this replaces everything currently on this cloud.');
            View::redirect('admin.backups');
        }
        try {
            Backup::restore((int) Request::post('id'), Request::post('passphrase'));
            Audit::log((int) $me['id'], 'backup.restored');
            Session::flash('success', 'Restored. A safety backup of what was here before was made first, in case you need to undo this.');
        } catch (StorageException $e) {
            Session::flash('error', $e->getMessage());
        } catch (\Throwable $e) {
            Session::flash('error', 'Restore failed: ' . $e->getMessage());
        }
        View::redirect('admin.backups');
    }

    public function delete(): void
    {
        $me = Auth::requireAdmin();
        Security::requireCsrf();
        Backup::deleteFile((int) Request::post('id'));
        Audit::log((int) $me['id'], 'backup.deleted');
        Session::flash('success', 'Backup deleted.');
        View::redirect('admin.backups');
    }
}
