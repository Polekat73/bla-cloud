<?php
/** @var array $project @var bool $isOwner @var array $columns @var array $tasks @var array $members @var array $me */
$tasksByColumn = [];
foreach ($tasks as $t) {
    $tasksByColumn[(int) $t['column_id']][] = $t;
}
$memberById = [];
foreach ($members as $m) {
    $memberById[(int) $m['id']] = $m;
}
$commentsByTask = [];
foreach ($comments as $c) {
    $commentsByTask[(int) $c['task_id']][] = [
        'name' => $c['display_name'] ?: $c['username'],
        'body' => $c['body'],
        'when' => human_time((int) strtotime($c['created_at'] . ' UTC')),
    ];
}
?>
<div class="page" data-project-board>
  <div class="page-head">
    <div><p class="eyebrow"><a href="<?= e(url('projects')) ?>">Projects</a></p><h1><?= e($project['name']) ?></h1></div>
    <div class="files__actions">
      <button type="button" class="btn btn--ghost" data-open="dlg-members"><?= icon('users') ?> Members</button>
      <?php if ($isOwner): ?>
      <form method="post" action="<?= e(url('projects.delete')) ?>" data-confirm="Delete “<?= e($project['name']) ?>” and all of its tasks? This cannot be undone.">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $project['id'] ?>">
        <button class="btn btn--ghost btn--danger" type="submit"><?= icon('trash') ?> Delete project</button>
      </form>
      <?php endif; ?>
    </div>
  </div>
  <?php if ($project['description'] !== ''): ?><p class="muted"><?= e($project['description']) ?></p><?php endif; ?>

  <div class="board" data-board data-project-id="<?= (int) $project['id'] ?>"
       data-move-url="<?= e(url('projects.task.move')) ?>"
       data-create-url="<?= e(url('projects.task.create')) ?>"
       data-update-url="<?= e(url('projects.task.update')) ?>">
    <?php foreach ($columns as $col): $colTasks = $tasksByColumn[(int) $col['id']] ?? []; ?>
      <div class="board-col">
        <div class="board-col__head">
          <h2><?= e($col['name']) ?></h2>
          <span class="board-col__count"><?= count($colTasks) ?></span>
          <?php if (count($columns) > 1): ?>
          <form method="post" action="<?= e(url('projects.column.delete')) ?>" class="board-col__del"
                data-confirm="Delete the “<?= e($col['name']) ?>” column? It must be empty first.">
            <?= csrf_field() ?>
            <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
            <input type="hidden" name="column_id" value="<?= (int) $col['id'] ?>">
            <button class="icon-btn" type="submit" title="Delete column"><?= icon('x') ?></button>
          </form>
          <?php endif; ?>
        </div>
        <div class="board-col__list" data-column-list data-column-id="<?= (int) $col['id'] ?>">
          <?php foreach ($colTasks as $t): ?>
            <div class="board-card" draggable="true" data-task
                 data-task-id="<?= (int) $t['id'] ?>"
                 data-column-id="<?= (int) $t['column_id'] ?>"
                 data-title="<?= e($t['title']) ?>"
                 data-description="<?= e($t['description']) ?>"
                 data-assignee-id="<?= (int) ($t['assignee_id'] ?? 0) ?>"
                 data-due-at="<?= e((string) $t['due_at']) ?>"
                 data-comments='<?= e(json_encode($commentsByTask[(int) $t['id']] ?? [], JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'>
              <div class="board-card__title"><?= e($t['title']) ?></div>
              <div class="board-card__meta">
                <?php if ($t['due_at']): ?><span class="board-card__due"><?= icon('clock') ?> <?= e($t['due_at']) ?></span><?php endif; ?>
                <?php if ($t['assignee_name'] || $t['assignee_username']): ?>
                  <span class="avatar avatar--sm" title="<?= e($t['assignee_name'] ?: $t['assignee_username']) ?>">
                    <?= e(mb_strtoupper(mb_substr($t['assignee_name'] ?: $t['assignee_username'], 0, 1))) ?></span>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
          <button type="button" class="board-add" data-add-task data-column-id="<?= (int) $col['id'] ?>"><?= icon('plus') ?> Add task</button>
        </div>
      </div>
    <?php endforeach; ?>
    <form method="post" action="<?= e(url('projects.column.create')) ?>" class="board-col board-col--new">
      <?= csrf_field() ?>
      <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
      <input name="name" maxlength="64" placeholder="New column" class="board-col__input">
      <button class="btn btn--ghost btn--sm" type="submit"><?= icon('plus') ?> Add</button>
    </form>
  </div>
</div>

<dialog id="dlg-task" class="dialog">
  <form method="post" action="<?= e(url('projects.task.create')) ?>" class="form" data-task-form>
    <?= csrf_field() ?>
    <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
    <input type="hidden" name="column_id" value="" data-f="column_id">
    <input type="hidden" name="task_id" value="" data-f="task_id">
    <h2 data-task-title>New task</h2>
    <label class="field"><span>Title</span><input name="title" required maxlength="255" data-f="title" autofocus></label>
    <label class="field"><span>Description</span><textarea name="description" rows="3" data-f="description"></textarea></label>
    <div class="grid-2">
      <label class="field"><span>Assignee</span>
        <select name="assignee_id" data-f="assignee_id">
          <option value="">Unassigned</option>
          <?php foreach ($members as $m): ?>
            <option value="<?= (int) $m['id'] ?>"><?= e($m['display_name'] ?: $m['username']) ?></option>
          <?php endforeach; ?>
        </select></label>
      <label class="field"><span>Due date</span><input type="date" name="due_at" data-f="due_at"></label>
    </div>
    <div class="actions">
      <button type="button" class="btn btn--danger" data-delete-task hidden><?= icon('trash') ?> Delete</button>
      <span class="actions-spacer"></span>
      <button type="button" class="btn btn--ghost" data-close>Cancel</button>
      <button class="btn btn--primary" type="submit">Save</button>
    </div>
  </form>
  <div data-task-comments hidden>
    <hr class="dialog-sep">
    <h3>Comments</h3>
    <ul class="task-comments" data-comment-list></ul>
    <form method="post" action="<?= e(url('projects.comment.create')) ?>" class="form form--inline">
      <?= csrf_field() ?>
      <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
      <input type="hidden" name="task_id" value="" data-f="comment_task_id">
      <label class="field"><span>Add a comment</span><textarea name="body" rows="2" required></textarea></label>
      <div class="actions actions--left"><button class="btn btn--ghost btn--sm" type="submit">Comment</button></div>
    </form>
  </div>
</dialog>
<form method="post" action="<?= e(url('projects.task.delete')) ?>" id="form-delete-task" hidden>
  <?= csrf_field() ?>
  <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
  <input type="hidden" name="task_id" data-df="task_id">
</form>

<dialog id="dlg-members" class="dialog">
  <h2>Members</h2>
  <ul class="member-list">
    <?php foreach ($members as $m): ?>
      <li>
        <span class="avatar avatar--sm"><?= e(mb_strtoupper(mb_substr($m['display_name'] ?: $m['username'], 0, 1))) ?></span>
        <span><?= e($m['display_name'] ?: $m['username']) ?><?php if ((int) $m['id'] === (int) $project['owner_id']): ?> <small class="muted">(owner)</small><?php endif; ?></span>
        <?php if ($isOwner && (int) $m['id'] !== (int) $project['owner_id']): ?>
          <form method="post" action="<?= e(url('projects.member.remove')) ?>" data-confirm="Remove <?= e($m['username']) ?> from this project?">
            <?= csrf_field() ?>
            <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
            <input type="hidden" name="user_id" value="<?= (int) $m['id'] ?>">
            <button class="icon-btn" type="submit" title="Remove"><?= icon('x') ?></button>
          </form>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php if ($isOwner): ?>
  <form method="post" action="<?= e(url('projects.member.add')) ?>" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
    <label class="field"><span>Add someone by username</span><input name="username" maxlength="64" required></label>
    <div class="actions"><button type="button" class="btn btn--ghost" data-close>Close</button>
      <button class="btn btn--primary" type="submit">Add</button></div>
  </form>
  <?php else: ?>
  <div class="actions"><button type="button" class="btn btn--ghost" data-close>Close</button></div>
  <?php endif; ?>
</dialog>
