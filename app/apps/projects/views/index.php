<div class="page">
  <div class="page-head">
    <div><p class="eyebrow">Your account</p><h1>Projects</h1></div>
    <div class="files__actions">
      <button type="button" class="btn btn--primary" data-open="dlg-new-project"><?= icon('plus') ?> New project</button>
    </div>
  </div>

  <?php if (!$projects): ?>
    <div class="empty">
      <div class="empty__icon"><?= icon('board') ?></div>
      <h2>No projects yet</h2>
      <p class="muted">Create one to start tracking tasks with a Kanban board.</p>
    </div>
  <?php else: ?>
    <div class="project-grid">
      <?php foreach ($projects as $p): ?>
        <a class="project-card" href="<?= e(url('projects.show', ['id' => $p['id']])) ?>">
          <h2><?= e($p['name']) ?></h2>
          <p class="muted"><?= e($p['description'] !== '' ? $p['description'] : 'No description.') ?></p>
          <div class="project-card__meta">
            <span><?= icon('board') ?> <?= (int) $p['task_count'] ?> task<?= (int) $p['task_count'] === 1 ? '' : 's' ?></span>
            <?php if (!$p['is_owner']): ?><span class="muted">Shared with you</span><?php endif; ?>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<dialog id="dlg-new-project" class="dialog">
  <form method="post" action="<?= e(url('projects.create')) ?>" class="form">
    <?= csrf_field() ?>
    <h2>New project</h2>
    <label class="field"><span>Name</span><input name="name" required maxlength="128" autofocus></label>
    <label class="field"><span>Description</span><textarea name="description" rows="2"></textarea></label>
    <div class="actions"><button type="button" class="btn btn--ghost" data-close>Cancel</button>
      <button class="btn btn--primary" type="submit">Create</button></div>
  </form>
</dialog>
