<?php
declare(strict_types=1);

namespace BlaCloud;

use BlaCloud\Controllers\AccountController;
use BlaCloud\Controllers\AdminController;
use BlaCloud\Controllers\AdminSettingsController;
use BlaCloud\Controllers\BackupController;
use BlaCloud\Controllers\EncryptionController;
use BlaCloud\Controllers\LinkController;
use BlaCloud\Controllers\ShareController;
use BlaCloud\Controllers\UsersController;
use BlaCloud\Controllers\AuthController;
use BlaCloud\Controllers\CalendarController;
use BlaCloud\Controllers\ContactsController;
use BlaCloud\Controllers\DavController;
use BlaCloud\Controllers\FilesController;
use BlaCloud\Controllers\SettingsController;
use BlaCloud\Controllers\SetupController;
use BlaCloud\Controllers\SyncController;
use BlaCloud\Controllers\TrashController;

final class App
{
    /** route => [controller, method] */
    private const ROUTES = [
        'login'          => [AuthController::class, 'login'],
        'logout'         => [AuthController::class, 'logout'],
        '2fa'            => [AuthController::class, 'twoFactor'],
        '2fa-setup'      => [AuthController::class, 'twoFactorSetup'],
        'recovery-codes' => [AuthController::class, 'recoveryCodes'],
        'files'          => [FilesController::class, 'index'],
        'files.upload'   => [FilesController::class, 'upload'],
        'files.mkdir'    => [FilesController::class, 'mkdir'],
        'files.rename'   => [FilesController::class, 'rename'],
        'files.delete'   => [FilesController::class, 'delete'],
        'files.download' => [FilesController::class, 'download'],
        'files.view'     => [FilesController::class, 'view'],
        'files.thumb'    => [FilesController::class, 'thumb'],
        'files.move'     => [FilesController::class, 'move'],
        'files.copy'     => [FilesController::class, 'copy'],
        'files.folders'  => [FilesController::class, 'folders'],
        'files.zip'      => [FilesController::class, 'zip'],
        'files.versions' => [FilesController::class, 'versions'],
        'version.download' => [FilesController::class, 'versionDownload'],
        'version.restore'  => [FilesController::class, 'versionRestore'],
        'version.delete'   => [FilesController::class, 'versionDelete'],
        'trash'          => [TrashController::class, 'index'],
        'trash.restore'  => [TrashController::class, 'restore'],
        'trash.purge'    => [TrashController::class, 'purge'],
        'trash.empty'    => [TrashController::class, 'empty'],
        'settings'       => [SettingsController::class, 'index'],
        'settings.password' => [SettingsController::class, 'password'],
        'settings.2fa'   => [SettingsController::class, 'twoFactor'],
        'admin'          => [AdminController::class, 'status'],
        'admin.settings' => [AdminSettingsController::class, 'index'],
        'admin.testmail' => [AdminSettingsController::class, 'testMail'],
        'admin.backups'          => [BackupController::class, 'index'],
        'admin.backups.enable'   => [BackupController::class, 'enable'],
        'admin.backups.disable'  => [BackupController::class, 'disable'],
        'admin.backups.rotate'   => [BackupController::class, 'rotatePassphrase'],
        'admin.backups.run'      => [BackupController::class, 'runNow'],
        'admin.backups.download' => [BackupController::class, 'download'],
        'admin.backups.verify'   => [BackupController::class, 'verify'],
        'admin.backups.restore'  => [BackupController::class, 'restore'],
        'admin.backups.delete'   => [BackupController::class, 'delete'],
        'admin.encryption'          => [EncryptionController::class, 'index'],
        'admin.encryption.enable'   => [EncryptionController::class, 'enable'],
        'admin.encryption.pause'    => [EncryptionController::class, 'pause'],
        'admin.encryption.resume'   => [EncryptionController::class, 'resume'],
        'admin.encryption.migrate-encrypt' => [EncryptionController::class, 'migrateEncrypt'],
        'admin.encryption.migrate-decrypt' => [EncryptionController::class, 'migrateDecrypt'],
        'users'          => [UsersController::class, 'index'],
        'users.create'   => [UsersController::class, 'create'],
        'users.action'   => [UsersController::class, 'action'],
        'user'           => [UsersController::class, 'edit'],
        'forgot'         => [AccountController::class, 'forgot'],
        'reset'          => [AccountController::class, 'reset'],
        'invite'         => [AccountController::class, 'invite'],
        'share.info'     => [ShareController::class, 'info'],
        'share.user'     => [ShareController::class, 'withUser'],
        'share.link'     => [ShareController::class, 'link'],
        'share.email'    => [ShareController::class, 'emailLink'],
        'share.delete'   => [ShareController::class, 'delete'],
        'share.leave'    => [ShareController::class, 'leave'],
        'shared'         => [ShareController::class, 'sharedWithMe'],
        'shared-by-me'   => [ShareController::class, 'sharedByMe'],
        's'              => [LinkController::class, 'open'],
        'sync'           => [SyncController::class, 'index'],
        'sync.apppasswords.create' => [SyncController::class, 'createAppPassword'],
        'sync.apppasswords.delete' => [SyncController::class, 'deleteAppPassword'],
        'calendar'          => [CalendarController::class, 'index'],
        'calendar.event.save'   => [CalendarController::class, 'saveEvent'],
        'calendar.event.delete' => [CalendarController::class, 'deleteEvent'],
        'calendar.new'      => [CalendarController::class, 'newCalendar'],
        'contacts'        => [ContactsController::class, 'index'],
        'contacts.save'   => [ContactsController::class, 'save'],
        'contacts.delete' => [ContactsController::class, 'delete'],
    ];

    public static function run(): void
    {
        $route = Request::get('r', '');

        if (!Config::isInstalled()) {
            Session::start();
            (new SetupController())->handle();
            return;
        }

        Schema::ensureUpToDate();

        // WebDAV/CalDAV/CardDAV: separate, stateless entry point (no cookies, HTTP Basic + app passwords).
        $path = Request::path();
        if ($path === '/.well-known/caldav' || $path === '/.well-known/carddav') {
            header('Location: ' . Request::basePath() . '/dav/', true, 302);
            return;
        }
        if ($path === '/dav' || str_starts_with($path, '/dav/')) {
            (new DavController())->handle();
            return;
        }

        Session::start();
        if (random_int(1, 100) === 1) {
            Maintenance::run();
        }

        if ($route === 'setup') {
            View::redirect('login');
        }
        if ($route === '') {
            View::redirect(Auth::user() ? 'files' : 'login');
        }
        if (!isset(self::ROUTES[$route])) {
            http_response_code(404);
            View::render('error', ['title' => 'Page not found', 'message' => 'That page does not exist.']);
            return;
        }
        [$class, $method] = self::ROUTES[$route];
        (new $class())->$method();
    }
}
