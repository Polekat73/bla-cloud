<?php
declare(strict_types=1);

namespace BlaCloud\Controllers;

use BlaCloud\Audit;
use BlaCloud\Auth;
use BlaCloud\Backup;
use BlaCloud\Encryption;
use BlaCloud\Request;
use BlaCloud\Security;
use BlaCloud\Session;
use BlaCloud\StorageException;
use BlaCloud\View;

/** Admin: encryption at rest for user files (separate from account passwords and backups). */
final class EncryptionController
{
    public function index(): void
    {
        $me = Auth::requireAdmin();
        $newPassphrase = $_SESSION['new_encrypt_passphrase'] ?? null;
        unset($_SESSION['new_encrypt_passphrase']);
        View::render('admin/encryption', [
            'title'    => 'Encryption',
            'nav'      => 'admin.encryption',
            'user'     => $me,
            'enabled'  => Encryption::enabled(),
            'hasKey'   => Encryption::hasKey(),
            'backupsOn' => Backup::enabled(),
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
                $passphrase = Encryption::generatePassphrase();
            }
            Encryption::enable($passphrase);
            $_SESSION['new_encrypt_passphrase'] = $passphrase;
            Audit::log((int) $me['id'], 'encryption.enabled');
            Session::flash('success', 'Encryption is on for new files.' . ($generated ? ' A passphrase was generated for you — save it now, it will not be shown again.' : ''));
        } catch (StorageException $e) {
            Session::flash('error', $e->getMessage());
        }
        View::redirect('admin.encryption');
    }

    public function pause(): void
    {
        $me = Auth::requireAdmin();
        Security::requireCsrf();
        Encryption::pause();
        Audit::log((int) $me['id'], 'encryption.paused');
        Session::flash('warning', 'New files are no longer encrypted. Files already encrypted stay that way — the passphrase is still needed to read them.');
        View::redirect('admin.encryption');
    }

    public function resume(): void
    {
        $me = Auth::requireAdmin();
        Security::requireCsrf();
        try {
            Encryption::resume();
            Audit::log((int) $me['id'], 'encryption.enabled');
            Session::flash('success', 'Encryption is on for new files again.');
        } catch (StorageException $e) {
            Session::flash('error', $e->getMessage());
        }
        View::redirect('admin.encryption');
    }

    public function migrateEncrypt(): void
    {
        $me = Auth::requireAdmin();
        Security::requireCsrf();
        if (!Backup::enabled()) {
            Session::flash('error', 'Set up backups first (Backups page) — a safety backup is taken before a bulk change like this.');
            View::redirect('admin.encryption');
        }
        try {
            Backup::run('safety');
            $r = Encryption::encryptExistingFiles();
            Audit::log((int) $me['id'], 'encryption.migrated_encrypt', "{$r['converted']} encrypted, {$r['skipped']} already were, " . count($r['errors']) . ' error(s)');
            Session::flash($r['errors'] ? 'warning' : 'success',
                "Encrypted {$r['converted']} file(s); {$r['skipped']} were already encrypted." . ($r['errors'] ? ' ' . count($r['errors']) . ' could not be converted — see the activity log.' : ''));
        } catch (StorageException $e) {
            Session::flash('error', $e->getMessage());
        }
        View::redirect('admin.encryption');
    }

    public function migrateDecrypt(): void
    {
        $me = Auth::requireAdmin();
        Security::requireCsrf();
        if (!Backup::enabled()) {
            Session::flash('error', 'Set up backups first (Backups page) — a safety backup is taken before a bulk change like this.');
            View::redirect('admin.encryption');
        }
        try {
            Backup::run('safety');
            $r = Encryption::decryptExistingFiles();
            Audit::log((int) $me['id'], 'encryption.migrated_decrypt', "{$r['converted']} decrypted, {$r['skipped']} already were, " . count($r['errors']) . ' error(s)');
            Session::flash($r['errors'] ? 'warning' : 'success',
                "Decrypted {$r['converted']} file(s); {$r['skipped']} were already plain." . ($r['errors'] ? ' ' . count($r['errors']) . ' could not be converted — see the activity log.' : ''));
        } catch (StorageException $e) {
            Session::flash('error', $e->getMessage());
        }
        View::redirect('admin.encryption');
    }
}
