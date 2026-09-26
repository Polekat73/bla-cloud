<?php
declare(strict_types=1);

use BlaCloud\Apps\Projects\ChatController;
use BlaCloud\Apps\Projects\ProjectsController;

return [
    'id'          => 'projects',
    'name'        => 'Projects',
    'description' => 'Kanban-style project boards: tasks, columns, assignees, due dates and comments.',
    'version'     => '1.0.0',
    'icon'        => 'board',
    'nav'         => ['route' => 'projects', 'label' => 'Projects', 'order' => 25],
    'default_enabled' => true,
    'routes'      => [
        'projects'                => [ProjectsController::class, 'index'],
        'projects.show'           => [ProjectsController::class, 'show'],
        'projects.create'         => [ProjectsController::class, 'create'],
        'projects.delete'         => [ProjectsController::class, 'delete'],
        'projects.member.add'     => [ProjectsController::class, 'addMember'],
        'projects.member.remove'  => [ProjectsController::class, 'removeMember'],
        'projects.column.create'  => [ProjectsController::class, 'createColumn'],
        'projects.column.delete'  => [ProjectsController::class, 'deleteColumn'],
        'projects.task.create'    => [ProjectsController::class, 'createTask'],
        'projects.task.update'    => [ProjectsController::class, 'updateTask'],
        'projects.task.delete'    => [ProjectsController::class, 'deleteTask'],
        'projects.task.move'      => [ProjectsController::class, 'moveTask'],
        'projects.comment.create' => [ProjectsController::class, 'addComment'],
        'projects.chat'                 => [ChatController::class, 'show'],
        'projects.chat.messages'        => [ChatController::class, 'messages'],
        'projects.chat.post'            => [ChatController::class, 'post'],
        'projects.chat.message.delete'  => [ChatController::class, 'deleteMessage'],
        'projects.chat.channel.create'  => [ChatController::class, 'createChannel'],
        'projects.chat.channel.delete'  => [ChatController::class, 'deleteChannel'],
        'projects.chat.member.add'      => [ChatController::class, 'addMember'],
        'projects.chat.member.remove'   => [ChatController::class, 'removeMember'],
    ],
];
