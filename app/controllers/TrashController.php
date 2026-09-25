<?php
declare(strict_types=1);

namespace BlaCloud\Controllers;

use BlaCloud\Audit;
use BlaCloud\Auth;
use BlaCloud\Request;
use BlaCloud\Security;
use BlaCloud\Session;
use BlaCloud\Storage;
use BlaCloud\StorageException;
use BlaCloud\Trash;
use BlaCloud\View;

final class TrashController
{
    private function trash(array $u): Trash
    {
        return new Trash(new Storage((int) $u['id']));
    }

    public function index(): void
    {
        $u = Auth::requireUser();
        $t = $this->trash($u);
        View::render('files/trash', [
            'title' => 'Trash',
            'nav'   => 'trash',
            'user'  => $u,
            'items' => $t->list(),
            'size'  => $t->size(),
            'days'  => Trash::retentionDays(),
        ]);
    }

    public function restore(): void
    {
        $u = $this->guard();
        $t = $this->trash($u);
        $done = [];
        foreach ($this->ids() as $id) {
            try {
                $done[] = $t->restore($id);
                Audit::log((int) $u['id'], 'trash.restore', end($done));
            } catch (StorageException $e) {
                Session::flash('error', $e->getMessage());
            }
        }
        if (count($done) === 1) {
            Session::flash('success', 'Restored to “' . (Storage::parent($done[0]) === '' ? 'My files' : Storage::parent($done[0])) . '”.');
        } elseif ($done) {
            Session::flash('success', 'Restored ' . count($done) . ' items to where they were.');
        }
        View::redirect('trash');
    }

    public function purge(): void
    {
        $u = $this->guard();
        $t = $this->trash($u);
        $n = 0;
        foreach ($this->ids() as $id) {
            try {
                $row = $t->find($id);
                $t->purge($id);
                Audit::log((int) $u['id'], 'trash.purge', $row['original_path']);
                $n++;
            } catch (StorageException $e) {
                Session::flash('error', $e->getMessage());
            }
        }
        if ($n) {
            Session::flash('success', $n === 1 ? 'Deleted forever.' : "Deleted $n items forever.");
        }
        View::redirect('trash');
    }

    public function empty(): void
    {
        $u = $this->guard();
        $n = $this->trash($u)->empty();
        Audit::log((int) $u['id'], 'trash.empty', "$n item(s)");
        Session::flash('success', $n ? "Trash emptied ($n item" . ($n === 1 ? '' : 's') . ').' : 'The trash was already empty.');
        View::redirect('trash');
    }

    private function ids(): array
    {
        $ids = $_POST['ids'] ?? [];
        return array_slice(array_map('intval', is_array($ids) ? $ids : []), 0, 500);
    }

    private function guard(): array
    {
        $u = Auth::requireUser();
        if (!Request::isPost()) {
            View::redirect('trash');
        }
        Security::requireCsrf();
        return $u;
    }
}
