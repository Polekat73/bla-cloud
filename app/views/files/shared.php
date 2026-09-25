<?php
$kindIcon = ['folder' => 'folder', 'image' => 'image', 'video' => 'video', 'audio' => 'audio', 'pdf' => 'pdf',
             'doc' => 'doc', 'sheet' => 'sheet', 'slides' => 'slides', 'archive' => 'archive', 'file' => 'file'];
?>
<div class="page">
  <p class="eyebrow">From other people</p>
  <h1 class="page-title"><?= icon('users') ?> Shared with me</h1>
  <div class="dropzone dropzone--static">
  <?php if (!$items): ?>
    <div class="empty">
      <div class="empty__icon"><?= icon('users') ?></div>
      <h2>Nothing shared with you yet</h2>
      <p>When someone on this cloud shares a file or folder with you, it appears here.</p>
    </div>
  <?php else: ?>
    <table class="file-table">
      <thead><tr><th>Name</th><th>Shared by</th><th>You can</th><th class="col-date">Since</th><th class="col-actions"><span class="sr-only">Actions</span></th></tr></thead>
      <tbody>
      <?php foreach ($items as $it): ?>
        <tr>
          <td class="col-name"><a class="file-link" href="<?= e(url('files', ['share' => $it['id']])) ?>">
            <span class="ficon ficon--<?= e($it['type']) ?>"><?= icon($kindIcon[$it['type']] ?? 'file') ?></span>
            <span class="fname"><?= e(basename($it['path'])) ?></span></a></td>
          <td><?= e($it['owner_name'] ?: $it['owner_username']) ?></td>
          <td><span class="pill pill--<?= $it['perms'] === 'edit' ? 'active' : 'invited' ?>"><?= $it['perms'] === 'edit' ? 'Edit' : 'View' ?></span></td>
          <td class="col-date"><?= e(human_time((int) strtotime($it['created_at'] . ' UTC'))) ?></td>
          <td class="col-actions">
            <form method="post" action="<?= e(url('share.leave')) ?>" class="inline-form">
              <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $it['id'] ?>">
              <button class="icon-btn" type="submit" title="Remove from my list" aria-label="Remove <?= e(basename($it['path'])) ?> from my list"
                      data-confirm="Remove “<?= e(basename($it['path'])) ?>” from your list? The owner keeps it."><?= icon('x') ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
  </div>
</div>
