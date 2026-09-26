<?php
declare(strict_types=1);

namespace BlaCloud\Apps\Projects;

use BlaCloud\Auth;
use BlaCloud\Request;
use BlaCloud\Security;
use BlaCloud\Session;
use BlaCloud\StorageException;
use BlaCloud\View;

/** Web UI for the Projects app: a project list and a Kanban board per project. */
final class ProjectsController
{
    public function index(): void
    {
        $u = Auth::requireUser();
        View::renderApp(__DIR__, 'index', [
            'title' => 'Projects', 'nav' => 'projects',
            'projects' => ProjectsData::forUser((int) $u['id']),
        ]);
    }

    public function show(): void
    {
        $u = Auth::requireUser();
        $projectId = (int) Request::get('id');
        try {
            $project = ProjectsData::requireMember((int) $u['id'], $projectId);
        } catch (StorageException $e) {
            Session::flash('error', $e->getMessage());
            View::redirect('projects');
        }
        View::renderApp(__DIR__, 'board', [
            'title' => $project['name'], 'nav' => 'projects',
            'me' => $u,
            'project' => $project,
            'isOwner' => (int) $project['owner_id'] === (int) $u['id'],
            'columns' => ProjectsData::columns($projectId),
            'tasks' => ProjectsData::tasks($projectId),
            'members' => ProjectsData::members($projectId),
            'comments' => ProjectsData::commentsForProject($projectId),
        ]);
    }

    public function create(): void
    {
        $u = Auth::requireUser();
        Security::requireCsrf();
        try {
            $id = ProjectsData::create((int) $u['id'], Request::post('name'), Request::post('description'));
            View::redirect('projects.show', ['id' => $id]);
        } catch (StorageException $e) {
            Session::flash('error', $e->getMessage());
            View::redirect('projects');
        }
    }

    public function delete(): void
    {
        $u = Auth::requireUser();
        Security::requireCsrf();
        try {
            ProjectsData::delete((int) $u['id'], (int) Request::post('id'));
            Session::flash('success', 'Project deleted.');
        } catch (StorageException $e) {
            Session::flash('error', $e->getMessage());
        }
        View::redirect('projects');
    }

    public function addMember(): void
    {
        $u = Auth::requireUser();
        Security::requireCsrf();
        $projectId = (int) Request::post('project_id');
        try {
            ProjectsData::addMember((int) $u['id'], $projectId, Request::post('username'));
        } catch (StorageException $e) {
            Session::flash('error', $e->getMessage());
        }
        View::redirect('projects.show', ['id' => $projectId]);
    }

    public function removeMember(): void
    {
        $u = Auth::requireUser();
        Security::requireCsrf();
        $projectId = (int) Request::post('project_id');
        try {
            ProjectsData::removeMember((int) $u['id'], $projectId, (int) Request::post('user_id'));
        } catch (StorageException $e) {
            Session::flash('error', $e->getMessage());
        }
        View::redirect('projects.show', ['id' => $projectId]);
    }

    public function createColumn(): void
    {
        $u = Auth::requireUser();
        Security::requireCsrf();
        $projectId = (int) Request::post('project_id');
        try {
            ProjectsData::createColumn((int) $u['id'], $projectId, Request::post('name'));
        } catch (StorageException $e) {
            Session::flash('error', $e->getMessage());
        }
        View::redirect('projects.show', ['id' => $projectId]);
    }

    public function deleteColumn(): void
    {
        $u = Auth::requireUser();
        Security::requireCsrf();
        $projectId = (int) Request::post('project_id');
        try {
            ProjectsData::deleteColumn((int) $u['id'], (int) Request::post('column_id'));
        } catch (StorageException $e) {
            Session::flash('error', $e->getMessage());
        }
        View::redirect('projects.show', ['id' => $projectId]);
    }

    public function createTask(): void
    {
        $u = Auth::requireUser();
        Security::requireCsrf();
        $projectId = (int) Request::post('project_id');
        try {
            $assignee = Request::post('assignee_id');
            ProjectsData::createTask(
                (int) $u['id'], $projectId, (int) Request::post('column_id'),
                Request::post('title'), Request::post('description'),
                $assignee !== '' ? (int) $assignee : null, Request::post('due_at') ?: null
            );
        } catch (StorageException $e) {
            Session::flash('error', $e->getMessage());
        }
        View::redirect('projects.show', ['id' => $projectId]);
    }

    public function updateTask(): void
    {
        $u = Auth::requireUser();
        Security::requireCsrf();
        $projectId = (int) Request::post('project_id');
        try {
            $assignee = Request::post('assignee_id');
            ProjectsData::updateTask(
                (int) $u['id'], (int) Request::post('task_id'),
                Request::post('title'), Request::post('description'),
                $assignee !== '' ? (int) $assignee : null, Request::post('due_at') ?: null
            );
        } catch (StorageException $e) {
            Session::flash('error', $e->getMessage());
        }
        View::redirect('projects.show', ['id' => $projectId]);
    }

    public function deleteTask(): void
    {
        $u = Auth::requireUser();
        Security::requireCsrf();
        $projectId = (int) Request::post('project_id');
        try {
            ProjectsData::deleteTask((int) $u['id'], (int) Request::post('task_id'));
        } catch (StorageException $e) {
            Session::flash('error', $e->getMessage());
        }
        View::redirect('projects.show', ['id' => $projectId]);
    }

    /** AJAX: drag-and-drop moves a task to a different column. Returns JSON. */
    public function moveTask(): void
    {
        $u = Auth::requireUser();
        Security::requireCsrf();
        try {
            ProjectsData::moveTask((int) $u['id'], (int) Request::post('task_id'), (int) Request::post('column_id'));
            View::json(['ok' => true]);
        } catch (StorageException $e) {
            View::json(['ok' => false, 'error' => $e->getMessage()], $e->status());
        }
    }

    public function addComment(): void
    {
        $u = Auth::requireUser();
        Security::requireCsrf();
        $projectId = (int) Request::post('project_id');
        try {
            ProjectsData::addComment((int) $u['id'], (int) Request::post('task_id'), Request::post('body'));
        } catch (StorageException $e) {
            Session::flash('error', $e->getMessage());
        }
        View::redirect('projects.show', ['id' => $projectId]);
    }
}
