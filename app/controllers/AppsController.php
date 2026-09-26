<?php
declare(strict_types=1);

namespace BlaCloud\Controllers;

use BlaCloud\Apps;
use BlaCloud\Audit;
use BlaCloud\Auth;
use BlaCloud\Request;
use BlaCloud\Security;
use BlaCloud\Session;
use BlaCloud\StorageException;
use BlaCloud\View;

/** Admin page: enable or disable the apps found in app/apps/ (see app/apps/README.md to add more). */
final class AppsController
{
    public function index(): void
    {
        Auth::requireAdmin();
        View::render('admin/apps', [
            'title' => 'Apps', 'nav' => 'admin.apps',
            'apps'  => Apps::all(),
        ]);
    }

    public function toggle(): void
    {
        $u = Auth::requireAdmin();
        Security::requireCsrf();
        $id = Request::post('id');
        $on = Request::post('enabled') === '1';
        try {
            Apps::setEnabled($id, $on);
            Audit::log((int) $u['id'], $on ? 'apps.enabled' : 'apps.disabled', $id);
            Session::flash('success', ($on ? 'Enabled' : 'Disabled') . ' ' . $id . '.');
        } catch (StorageException $e) {
            Session::flash('error', $e->getMessage());
        }
        View::redirect('admin.apps');
    }
}
