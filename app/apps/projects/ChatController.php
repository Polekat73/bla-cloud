<?php
declare(strict_types=1);

namespace BlaCloud\Apps\Projects;

use BlaCloud\Auth;
use BlaCloud\Request;
use BlaCloud\Security;
use BlaCloud\Session;
use BlaCloud\StorageException;
use BlaCloud\View;

/** Web UI for project chat: topic channels within a project, polled for new messages. */
final class ChatController
{
    public function show(): void
    {
        $u = Auth::requireUser();
        $projectId = (int) Request::get('project_id');
        try {
            $project = ProjectsData::requireMember((int) $u['id'], $projectId);
            $channels = ChannelsData::forProject($projectId);
            $channelId = (int) Request::get('channel_id') ?: (int) ($channels[0]['id'] ?? 0);
            $channel = $channelId ? ChannelsData::requireChannelMember((int) $u['id'], $channelId) : null;
            if ($channel && (int) $channel['project_id'] !== $projectId) {
                throw new StorageException('That channel does not belong to this project.', 404);
            }
        } catch (StorageException $e) {
            Session::flash('error', $e->getMessage());
            View::redirect('projects.show', ['id' => $projectId]);
        }
        View::renderApp(__DIR__, 'chat', [
            'title' => $project['name'] . ' · Chat', 'nav' => 'projects',
            'me' => $u, 'project' => $project, 'channels' => $channels, 'channel' => $channel,
            'messages' => $channel ? ChannelsData::messagesSince((int) $u['id'], $channelId) : [],
            'members' => $channel ? ChannelsData::members($channelId) : [],
            'projectMembers' => ProjectsData::members($projectId),
            'isOwner' => (int) $project['owner_id'] === (int) $u['id'],
        ]);
    }

    /** AJAX poll: messages newer than ?since_id=. Returns JSON. */
    public function messages(): void
    {
        $u = Auth::requireUser();
        $channelId = (int) Request::get('channel_id');
        $sinceId = (int) Request::get('since_id');
        try {
            $messages = ChannelsData::messagesSince((int) $u['id'], $channelId, $sinceId);
            View::json(['ok' => true, 'messages' => array_map(static fn ($m) => [
                'id' => (int) $m['id'], 'user_id' => (int) $m['user_id'],
                'name' => $m['display_name'] ?: $m['username'], 'body' => $m['body'],
                'when' => human_time((int) strtotime($m['created_at'] . ' UTC')),
            ], $messages)]);
        } catch (StorageException $e) {
            View::json(['ok' => false, 'error' => $e->getMessage()], $e->status());
        }
    }

    public function post(): void
    {
        $u = Auth::requireUser();
        Security::requireCsrf();
        $projectId = (int) Request::post('project_id');
        $channelId = (int) Request::post('channel_id');
        try {
            ChannelsData::postMessage((int) $u['id'], $channelId, Request::post('body'));
            if (Request::wantsJson()) {
                View::json(['ok' => true]);
            }
        } catch (StorageException $e) {
            if (Request::wantsJson()) {
                View::json(['ok' => false, 'error' => $e->getMessage()], $e->status());
            }
            Session::flash('error', $e->getMessage());
        }
        View::redirect('projects.chat', ['project_id' => $projectId, 'channel_id' => $channelId]);
    }

    public function deleteMessage(): void
    {
        $u = Auth::requireUser();
        Security::requireCsrf();
        ChannelsData::deleteMessage((int) $u['id'], (int) Request::post('message_id'));
        if (Request::wantsJson()) {
            View::json(['ok' => true]);
        }
        View::redirect('projects.chat', ['project_id' => (int) Request::post('project_id'), 'channel_id' => (int) Request::post('channel_id')]);
    }

    public function createChannel(): void
    {
        $u = Auth::requireUser();
        Security::requireCsrf();
        $projectId = (int) Request::post('project_id');
        try {
            $id = ChannelsData::createChannel((int) $u['id'], $projectId, Request::post('name'));
            View::redirect('projects.chat', ['project_id' => $projectId, 'channel_id' => $id]);
        } catch (StorageException $e) {
            Session::flash('error', $e->getMessage());
            View::redirect('projects.chat', ['project_id' => $projectId]);
        }
    }

    public function deleteChannel(): void
    {
        $u = Auth::requireUser();
        Security::requireCsrf();
        $projectId = (int) Request::post('project_id');
        try {
            ChannelsData::deleteChannel((int) $u['id'], (int) Request::post('channel_id'));
        } catch (StorageException $e) {
            Session::flash('error', $e->getMessage());
        }
        View::redirect('projects.chat', ['project_id' => $projectId]);
    }

    public function addMember(): void
    {
        $u = Auth::requireUser();
        Security::requireCsrf();
        $projectId = (int) Request::post('project_id');
        $channelId = (int) Request::post('channel_id');
        try {
            ChannelsData::addMember((int) $u['id'], $channelId, Request::post('username'));
        } catch (StorageException $e) {
            Session::flash('error', $e->getMessage());
        }
        View::redirect('projects.chat', ['project_id' => $projectId, 'channel_id' => $channelId]);
    }

    public function removeMember(): void
    {
        $u = Auth::requireUser();
        Security::requireCsrf();
        $projectId = (int) Request::post('project_id');
        $channelId = (int) Request::post('channel_id');
        try {
            ChannelsData::removeMember((int) $u['id'], $channelId, (int) Request::post('user_id'));
        } catch (StorageException $e) {
            Session::flash('error', $e->getMessage());
        }
        View::redirect('projects.chat', ['project_id' => $projectId, 'channel_id' => $channelId]);
    }
}
