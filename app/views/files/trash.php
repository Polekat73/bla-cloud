<?php
$kindIcon = ['folder' => 'folder', 'image' => 'image', 'video' => 'video', 'audio' => 'audio', 'pdf' => 'pdf',
             'doc' => 'doc', 'sheet' => 'sheet', 'slides' => 'slides', 'archive' => 'archive', 'file' => 'file'];
?>
<div class="files" data-trash>
  <div class="files__head">
    <div>
      <p class="eyebrow">Deleted items</p>
      <h1 class="page-title"><?= icon('bin') ?> Trash</h1>
    </div>
    <?php if ($items): ?>
    <div class="files__actions">
      <button type="button" class="btn btn--danger" data-open="dlg-empty"><?= icon('trash') ?> Empty trash</button>
    </div>
    <?php endif; ?>
  </div>
  <p class="muted">Items stay here for <?= (int) $days ?> days, then they're deleted automatically.
    <?= $items ? 'Using ' . e(\BlaCloud\View::bytes($size)) . '.' : '' ?></p>

  <form method="post" class="selection-bar" data-selection-bar hidden>
    <?= csrf_field() ?>
    <div data-selection-inputs></div>
    <span class="selection-bar__count" data-selection-count>0 selected</span>
    <button type="submit" class="btn btn--secondary btn--sm" formaction="<?= e(url('trash.restore')) ?>"><?= icon('undo') ?> Restore</button>
    <button type="submit" class="btn btn--danger btn--sm" formaction="<?= e(url('trash.purge')) ?>" data-confirm="Delete the selected items forever? This cannot be undone."><?= icon('x') ?> Delete forever</button>
    <button type="button" class="btn btn--ghost btn--sm" data-clear-selection>Clear</button>
  </form>

  <div class="dropzone dropzone--static">
    <?php if (!$items): ?>
      <div class="empty">
        <div class="empty__icon"><?= icon('bin') ?></div>
        <h2>The trash is empty</h2>
        <p>When you delete something, it waits here first so you can change your mind.</p>
      </div>
    <?php else: ?>
      <table class="file-table">
        <thead><tr>
          <th class="col-check"><input type="checkbox" data-select-all aria-label="Select all"></th>
          <th>Name</th><th class="col-where">Was in</th><th class="col-size">Size</th><th class="col-date">Deleted</th>
          <th class="col-actions"><span class="sr-only">Actions</span></th>
        </tr></thead>
        <tbody>
        <?php foreach ($items as $it): ?>
          <?php $was = \BlaCloud\Storage::parent($it['original_path']); ?>
          <tr data-id="<?= (int) $it['id'] ?>">
            <td class="col-check"><input type="checkbox" data-select value="<?= (int) $it['id'] ?>" aria-label="Select <?= e($it['name']) ?>"></td>
            <td class="col-name"><span class="file-link file-link--static">
              <span class="ficon ficon--<?= e($it['type']) ?>"><?= icon($kindIcon[$it['type']] ?? 'file') ?></span>
              <span class="fname"><?= e($it['name']) ?></span></span></td>
            <td class="col-where"><?= e($was === '' ? 'My files' : ltrim($was, '/')) ?></td>
            <td class="col-size"><?= e(\BlaCloud\View::bytes((int) $it['size'])) ?></td>
            <td class="col-date" title="Deleted forever on <?= e(date('M j, Y', $it['expires'])) ?>"><?= e(human_time((int) strtotime($it['deleted_at'] . ' UTC'))) ?></td>
            <td class="col-actions">
              <form method="post" action="<?= e(url('trash.restore')) ?>" class="inline-form">
                <?= csrf_field() ?><input type="hidden" name="ids[]" value="<?= (int) $it['id'] ?>">
                <button class="icon-btn" type="submit" title="Restore" aria-label="Restore <?= e($it['name']) ?>"><?= icon('undo') ?></button>
              </form>
              <form method="post" action="<?= e(url('trash.purge')) ?>" class="inline-form">
                <?= csrf_field() ?><input type="hidden" name="ids[]" value="<?= (int) $it['id'] ?>">
                <button class="icon-btn icon-btn--danger" type="submit" title="Delete forever" aria-label="Delete <?= e($it['name']) ?> forever"
                        data-confirm="Delete “<?= e($it['name']) ?>” forever? This cannot be undone."><?= icon('x') ?></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>

<dialog id="dlg-empty" class="dialog">
  <form method="post" action="<?= e(url('trash.empty')) ?>">
    <?= csrf_field() ?>
    <h2>Empty the trash?</h2>
    <p>Everything in the trash (<?= count($items) ?> item<?= count($items) === 1 ? '' : 's' ?>) will be deleted forever.</p>
    <div class="note note--warn"><?= icon('alert') ?><span>This cannot be undone.</span></div>
    <div class="actions"><button type="button" class="btn btn--ghost" data-close>Cancel</button>
      <button class="btn btn--danger" type="submit">Empty trash</button></div>
  </form>
</dialog>
