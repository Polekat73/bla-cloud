<div class="page">
  <p class="eyebrow">Your shares</p>
  <h1 class="page-title"><?= icon('link') ?> Shared by me</h1>
  <p class="muted">Everything you've shared, in one place. Stopping a share takes effect immediately.</p>
  <div class="dropzone dropzone--static">
  <?php if (!$items): ?>
    <div class="empty">
      <div class="empty__icon"><?= icon('share') ?></div>
      <h2>You haven't shared anything yet</h2>
      <p>Use the <strong>share</strong> button next to any file or folder in My files.</p>
    </div>
  <?php else: ?>
    <div class="table-wrap">
    <table class="file-table">
      <thead><tr><th>Item</th><th>With</th><th>Access</th><th>Expires</th><th>Opened</th><th class="col-actions"><span class="sr-only">Actions</span></th></tr></thead>
      <tbody>
      <?php foreach ($items as $s): $parent = \BlaCloud\Storage::parent($s['path']); ?>
        <tr class="<?= $s['expired'] ? 'is-muted' : '' ?>">
          <td class="col-name"><a class="file-link" href="<?= e(url('files', $parent === '' ? [] : ['path' => $parent])) ?>">
            <span class="ficon ficon--<?= (int) $s['is_dir'] ? 'folder' : e(\BlaCloud\Storage::kind($s['path'])) ?>"><?= icon((int) $s['is_dir'] ? 'folder' : 'file') ?></span>
            <span class="fname"><?= e(ltrim($s['path'], '/')) ?></span></a></td>
          <td class="nowrap"><?= $s['share_type'] === 'user'
              ? icon('users') . ' ' . e($s['display_name'] ?: $s['username'])
              : icon('link') . ' ' . e($s['label'] ?: 'Public link') . ($s['has_password'] ? ' ' . icon('lock') : '') ?></td>
          <td><?= e(\BlaCloud\Shares::LABELS[$s['perms']] ?? $s['perms']) ?></td>
          <td class="nowrap"><?= $s['expires_at'] ? ($s['expired'] ? '<span class="bad-text">Expired</span>' : e(date('M j, Y', (int) strtotime($s['expires_at'] . ' UTC')))) : '<span class="muted">Never</span>' ?></td>
          <td><?= $s['share_type'] === 'link' ? (int) $s['access_count'] . '×' : '—' ?></td>
          <td class="col-actions">
            <?php if ($s['url']): ?>
              <button type="button" class="icon-btn" data-copy="<?= e($s['url']) ?>" title="Copy link" aria-label="Copy link"><?= icon('copy') ?></button>
            <?php endif; ?>
            <form method="post" action="<?= e(url('share.delete')) ?>" class="inline-form">
              <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
              <button class="icon-btn icon-btn--danger" type="submit" title="Stop sharing" aria-label="Stop sharing"
                      data-confirm="Stop sharing “<?= e(basename($s['path'])) ?>”<?= $s['share_type'] === 'link' ? ' via this link' : ' with ' . e($s['display_name'] ?: $s['username']) ?>?"><?= icon('x') ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
  </div>
</div>
