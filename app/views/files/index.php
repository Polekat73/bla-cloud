<?php
/** @var \BlaCloud\Scope $scope */
$kindIcon = ['folder' => 'folder', 'image' => 'image', 'video' => 'video', 'audio' => 'audio', 'pdf' => 'pdf',
             'doc' => 'doc', 'sheet' => 'sheet', 'slides' => 'slides', 'archive' => 'archive', 'file' => 'file'];
$sp = $scope->params;
$su = static fn (string $route, array $q = []) => url($route, $q + $sp);
$own = $scope->isOwn();
$canEdit = $scope->can('edit');
$canUpload = $scope->can('upload') && (!$scope->isFile || $canEdit);
$canList = $scope->can('list');
$searching = $query !== '';
$here = $searching ? '' : $path;
$ownerName = $scope->share ? ($scope->share['owner_name'] ?: $scope->share['owner_username']) : '';
$fileIcon = static function (array $it, int $size) use ($kindIcon, $su): string {
    if ($it['thumb']) {
        return '<span class="ficon ficon--thumb"><img src="' . e($su('files.thumb', ['path' => $it['path'], 's' => $size]))
            . '" alt="" loading="lazy" decoding="async" data-thumb></span>';
    }
    return '<span class="ficon ficon--' . e($it['type']) . '">' . icon($kindIcon[$it['type']] ?? 'file') . '</span>';
};
$openAttrs = static function (array $it) use ($su): string {
    if ($it['dir']) {
        return 'href="' . e($su('files', ['path' => $it['path']])) . '"';
    }
    if ($it['viewer']) {
        return 'href="' . e($su('files.view', ['path' => $it['path']])) . '" data-open-viewer';
    }
    return 'href="' . e($su('files.download', ['path' => $it['path']])) . '" download';
};
$badges = static function (array $it): string {
    $b = '';
    if (!empty($it['shared']['user'])) {
        $b .= '<span class="badge-icon" title="Shared with people">' . icon('users') . '</span>';
    }
    if (!empty($it['shared']['link'])) {
        $b .= '<span class="badge-icon" title="Shared by link">' . icon('link') . '</span>';
    }
    return $b;
};
?>
<?php if (!$own): ?>
  <div class="share-banner">
    <?= icon($scope->kind === 'link' ? 'link' : 'users') ?>
    <span><?= $scope->kind === 'link' ? '' : 'Shared with you by ' ?><strong><?= e($ownerName) ?></strong>
      <?= $scope->kind === 'link' ? ' shared this with you' : '' ?> ·
      <?= e(\BlaCloud\Shares::LABELS[$scope->perms] ?? '') ?></span>
    <?php if ($scope->kind === 'user'): ?><a class="share-banner__back" href="<?= e(url('shared')) ?>">All shared items</a><?php endif; ?>
  </div>
<?php endif; ?>

<div class="files" data-files
     data-dir="<?= e($here) ?>"
     data-own="<?= $own ? '1' : '0' ?>"
     data-upload-url="<?= e($su('files.upload')) ?>"
     data-view-url="<?= e($su('files.view')) ?>"
     data-download-url="<?= e($su('files.download')) ?>"
     data-versions-url="<?= e(url('files.versions')) ?>"
     data-version-download-url="<?= e(url('version.download')) ?>"
     data-version-restore-url="<?= e(url('version.restore')) ?>"
     data-version-delete-url="<?= e(url('version.delete')) ?>"
     data-folders-url="<?= e(url('files.folders')) ?>"
     data-share-info-url="<?= e(url('share.info')) ?>"
     data-share-user-url="<?= e(url('share.user')) ?>"
     data-share-link-url="<?= e(url('share.link')) ?>"
     data-share-email-url="<?= e(url('share.email')) ?>"
     data-share-delete-url="<?= e(url('share.delete')) ?>"
     data-chunk="<?= (int) $chunkSize ?>">

  <div class="files__head">
    <?php if ($searching): ?>
      <div class="crumbs">
        <a href="<?= e($su('files')) ?>"><?= e($scope->rootName) ?></a><span class="crumbs__sep"><?= icon('chevron') ?></span>
        <span class="crumbs__current">Search: “<?= e($query) ?>”</span>
      </div>
    <?php else: ?>
    <nav class="crumbs" aria-label="Folder path">
      <?php foreach ($crumbs as $i => $c): ?>
        <?php if ($i > 0): ?><span class="crumbs__sep"><?= icon('chevron') ?></span><?php endif; ?>
        <?php if ($i === count($crumbs) - 1): ?>
          <span class="crumbs__current" aria-current="page"><?= e($c['name']) ?></span>
        <?php else: ?>
          <a href="<?= e($su('files', $c['path'] === '' ? [] : ['path' => $c['path']])) ?>"><?= e($c['name']) ?></a>
        <?php endif; ?>
      <?php endforeach; ?>
    </nav>
    <?php endif; ?>
    <div class="files__actions">
      <?php if ($canList && !$scope->isFile): ?>
      <form class="search" method="get" action="<?= e(url('')) ?>" role="search">
        <input type="hidden" name="r" value="files">
        <?php foreach ($sp as $k => $v): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e((string) $v) ?>"><?php endforeach; ?>
        <?= icon('search') ?>
        <input type="search" name="q" value="<?= e($query) ?>" placeholder="Search<?= $own ? ' all files' : '' ?>" aria-label="Search">
      </form>
      <div class="seg" role="group" aria-label="Layout">
        <button type="button" class="icon-btn" data-layout="list" aria-label="List view" title="List"><?= icon('list') ?></button>
        <button type="button" class="icon-btn" data-layout="grid" aria-label="Grid view" title="Grid"><?= icon('grid') ?></button>
      </div>
      <?php endif; ?>
      <?php if (!$searching && $canEdit && !$scope->isFile): ?>
      <button type="button" class="btn btn--secondary" data-open="dlg-mkdir"><?= icon('folder-plus') ?> <span>New folder</span></button>
      <?php endif; ?>
      <?php if (!$searching && $canUpload && $canList): ?>
      <label class="btn btn--primary">
        <?= icon('upload') ?> <span><?= $scope->isFile ? 'Upload new version' : 'Upload' ?></span>
        <input type="file" <?= $scope->isFile ? '' : 'multiple' ?> hidden data-upload-input>
      </label>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($canList): ?>
  <form class="selection-bar" data-selection-bar hidden method="post" action="<?= e($su('files.zip')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="dir" value="<?= e($here) ?>">
    <div data-selection-inputs></div>
    <span class="selection-bar__count" data-selection-count>0 selected</span>
    <?php if ($zip): ?>
      <button type="submit" class="btn btn--ghost btn--sm" data-zip-selected><?= icon('download') ?> <span>Download</span></button>
    <?php endif; ?>
    <?php if ($own): ?>
      <button type="button" class="btn btn--ghost btn--sm" data-move-selected="move"><?= icon('move') ?> <span>Move</span></button>
      <button type="button" class="btn btn--ghost btn--sm" data-move-selected="copy"><?= icon('copy') ?> <span>Copy</span></button>
    <?php endif; ?>
    <?php if ($canEdit): ?>
      <button type="button" class="btn btn--danger btn--sm" data-delete-selected><?= icon('trash') ?> <span>Delete</span></button>
    <?php endif; ?>
    <button type="button" class="btn btn--ghost btn--sm" data-clear-selection>Clear</button>
  </form>
  <?php endif; ?>

  <div class="dropzone" data-dropzone>
    <?php if (!$canList): ?>
      <div class="empty empty--drop">
        <div class="empty__icon"><?= icon('upload') ?></div>
        <h2>Send files to <?= e($ownerName) ?></h2>
        <p>Drag files here or choose them. They go straight into “<?= e($scope->rootName) ?>”. You won't see files others have sent.</p>
        <label class="btn btn--primary"><?= icon('upload') ?> Choose files<input type="file" multiple hidden data-upload-input></label>
      </div>
    <?php elseif (!$items): ?>
      <div class="empty">
        <?php if ($searching): ?>
          <div class="empty__icon"><?= icon('search') ?></div>
          <h2>Nothing found</h2>
          <p>No file or folder names contain “<?= e($query) ?>”.</p>
        <?php else: ?>
          <div class="empty__icon"><?= icon($path === '' && $own ? 'upload' : 'folder') ?></div>
          <h2><?= $path === '' && $own ? 'Your cloud is ready' : 'This folder is empty' ?></h2>
          <p><?= $canUpload ? 'Drag files here, or use <strong>Upload</strong>.' : 'There is nothing here yet.' ?><?= $own ? ' Everything stays on your own server.' : '' ?></p>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <table class="file-table" data-view="list">
        <thead>
          <tr>
            <th class="col-check"><input type="checkbox" data-select-all aria-label="Select all"></th>
            <th>Name</th>
            <?php if ($searching): ?><th class="col-where">Folder</th><?php endif; ?>
            <th class="col-size">Size</th>
            <th class="col-date">Modified</th>
            <th class="col-actions"><span class="sr-only">Actions</span></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($items as $it): ?>
          <tr data-path="<?= e($it['path']) ?>" data-name="<?= e($it['name']) ?>" data-dir="<?= $it['dir'] ? '1' : '0' ?>"
              <?= $it['viewer'] ? 'data-viewer="' . e($it['viewer']) . '"' : '' ?> data-size="<?= (int) ($it['size'] ?? 0) ?>">
            <td class="col-check"><input type="checkbox" data-select value="<?= e($it['path']) ?>" aria-label="Select <?= e($it['name']) ?>"></td>
            <td class="col-name">
              <a class="file-link" <?= $openAttrs($it) ?>><?= $fileIcon($it, 64) ?><span class="fname"><?= e($it['name']) ?></span><?= $badges($it) ?></a>
            </td>
            <?php if ($searching): ?>
              <?php $parent = \BlaCloud\Storage::parent($it['path']); ?>
              <td class="col-where"><a href="<?= e($su('files', $parent === '' ? [] : ['path' => $parent])) ?>"><?= e($parent === '' ? $scope->rootName : ltrim($parent, '/')) ?></a></td>
            <?php endif; ?>
            <td class="col-size"><?= $it['dir'] ? '—' : e(\BlaCloud\View::bytes((int) $it['size'])) ?></td>
            <td class="col-date" title="<?= e(date('Y-m-d H:i', $it['mtime'])) ?>"><?= e(human_time($it['mtime'])) ?></td>
            <td class="col-actions">
              <?php if ($own): ?>
                <button type="button" class="icon-btn icon-btn--accent" data-share title="Share" aria-label="Share <?= e($it['name']) ?>"><?= icon('share') ?></button>
              <?php endif; ?>
              <?php if (!$it['dir']): ?>
                <a class="icon-btn" href="<?= e($su('files.download', ['path' => $it['path']])) ?>" download title="Download" aria-label="Download <?= e($it['name']) ?>"><?= icon('download') ?></a>
              <?php endif; ?>
              <?php if ($own && !$it['dir']): ?>
                <button type="button" class="icon-btn" data-versions title="Previous versions" aria-label="Previous versions of <?= e($it['name']) ?>"><?= icon('clock') ?></button>
              <?php endif; ?>
              <?php if ($canEdit && $it['path'] !== ''): ?>
                <button type="button" class="icon-btn" data-rename title="Rename" aria-label="Rename <?= e($it['name']) ?>"><?= icon('pencil') ?></button>
              <?php endif; ?>
              <?php if ($own): ?>
                <button type="button" class="icon-btn" data-move-one title="Move or copy" aria-label="Move or copy <?= e($it['name']) ?>"><?= icon('move') ?></button>
              <?php endif; ?>
              <?php if ($canEdit && $it['path'] !== ''): ?>
                <button type="button" class="icon-btn icon-btn--danger" data-delete title="Delete" aria-label="Delete <?= e($it['name']) ?>"><?= icon('trash') ?></button>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

      <ul class="file-grid" data-view="grid">
        <?php foreach ($items as $it): ?>
          <li class="tile" data-path="<?= e($it['path']) ?>" data-name="<?= e($it['name']) ?>" data-dir="<?= $it['dir'] ? '1' : '0' ?>"
              <?= $it['viewer'] ? 'data-viewer="' . e($it['viewer']) . '"' : '' ?> data-size="<?= (int) ($it['size'] ?? 0) ?>">
            <a class="tile__link" <?= $openAttrs($it) ?>>
              <span class="tile__art"><?= $it['thumb'] ? '<img src="' . e($su('files.thumb', ['path' => $it['path'], 's' => 256])) . '" alt="" loading="lazy" decoding="async">' : icon($kindIcon[$it['type']] ?? 'file', 'tile__icon tile__icon--' . $it['type']) ?></span>
              <span class="tile__name"><?= e($it['name']) ?> <?= $badges($it) ?></span>
              <span class="tile__meta"><?= $it['dir'] ? 'Folder' : e(\BlaCloud\View::bytes((int) $it['size'])) ?></span>
            </a>
            <?php if ($own || ($canEdit && $it['path'] !== '')): ?>
            <div class="tile__actions">
              <?php if ($own): ?><button type="button" class="icon-btn" data-share title="Share" aria-label="Share <?= e($it['name']) ?>"><?= icon('share') ?></button><?php endif; ?>
              <?php if ($canEdit && $it['path'] !== ''): ?><button type="button" class="icon-btn" data-rename title="Rename" aria-label="Rename <?= e($it['name']) ?>"><?= icon('pencil') ?></button><?php endif; ?>
              <?php if ($own): ?><button type="button" class="icon-btn" data-move-one title="Move or copy" aria-label="Move or copy <?= e($it['name']) ?>"><?= icon('move') ?></button><?php endif; ?>
              <?php if ($canEdit && $it['path'] !== ''): ?><button type="button" class="icon-btn icon-btn--danger" data-delete title="Delete" aria-label="Delete <?= e($it['name']) ?>"><?= icon('trash') ?></button><?php endif; ?>
            </div>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <div class="drop-overlay" aria-hidden="true"><?= icon('upload') ?><span>Drop to upload</span></div>
  </div>

  <?php if ($own): ?>
  <p class="usage"><?= e(\BlaCloud\View::bytes($usage)) ?> used<?= $free ? ' · ' . e(\BlaCloud\View::bytes((float) $free)) . ' free on server' : '' ?>
    · <a href="<?= e(url('trash')) ?>">Trash</a></p>
  <?php endif; ?>
</div>

<!-- Upload progress -->
<aside class="uploads" data-uploads hidden aria-live="polite">
  <div class="uploads__head"><strong data-uploads-title>Uploading</strong>
    <button type="button" class="icon-btn" data-uploads-close aria-label="Close"><?= icon('x') ?></button></div>
  <ul class="uploads__list" data-uploads-list></ul>
</aside>

<!-- Viewer -->
<dialog id="viewer" class="viewer" aria-label="Preview">
  <header class="viewer__bar">
    <span class="viewer__name" data-viewer-name></span>
    <span class="viewer__count" data-viewer-count></span>
    <div class="viewer__tools">
      <?php if ($own): ?>
      <button type="button" class="icon-btn" data-viewer-versions title="Previous versions" aria-label="Previous versions"><?= icon('clock') ?></button>
      <?php endif; ?>
      <a class="icon-btn" data-viewer-download download title="Download" aria-label="Download"><?= icon('download') ?></a>
      <button type="button" class="icon-btn" data-viewer-close title="Close (Esc)" aria-label="Close"><?= icon('x') ?></button>
    </div>
  </header>
  <div class="viewer__stage" data-viewer-stage></div>
  <button type="button" class="viewer__nav viewer__nav--prev" data-viewer-prev aria-label="Previous"><?= icon('chevron-left') ?></button>
  <button type="button" class="viewer__nav viewer__nav--next" data-viewer-next aria-label="Next"><?= icon('chevron') ?></button>
</dialog>

<?php if ($own): ?>
<!-- Share -->
<dialog id="dlg-share" class="dialog dialog--wide dialog--share">
  <div class="dialog__body">
    <div class="share-head"><span class="card__icon"><?= icon('share') ?></span>
      <div><h2>Share “<span data-share-name></span>”</h2><p class="muted" data-share-kind></p></div></div>

    <section class="share-sec">
      <h3><?= icon('users') ?> People on this cloud</h3>
      <form class="share-row" data-share-user-form>
        <select name="recipient" data-share-people aria-label="Person" required></select>
        <select name="perms" aria-label="Permission"><option value="view">Can view</option><option value="edit">Can edit</option></select>
        <button class="btn btn--primary btn--sm" type="submit">Share</button>
      </form>
      <p class="hint" data-share-nopeople hidden>There are no other people on this cloud yet. An administrator can add them under People.</p>
    </section>

    <section class="share-sec" data-share-links>
      <h3><?= icon('link') ?> Public link</h3>
      <form class="form" data-share-link-form>
        <div class="grid-2">
          <label class="field"><span>Access</span><select name="perms" data-link-perms></select></label>
          <label class="field"><span>Expires <small>(optional)</small></span><input type="date" name="expires" data-link-expires></label>
          <label class="field"><span>Password <small data-link-pass-note>(optional)</small></span><input type="password" name="password" autocomplete="new-password" data-link-pass></label>
          <label class="field"><span>Name <small>(optional, e.g. “For the accountant”)</small></span><input name="label" maxlength="128"></label>
        </div>
        <div class="actions actions--left"><button class="btn btn--secondary btn--sm" type="submit"><?= icon('link') ?> Create link</button></div>
      </form>
    </section>
    <p class="hint" data-share-links-off hidden>Public links are turned off by your administrator.</p>

    <section class="share-sec">
      <h3>Who has access</h3>
      <ul class="share-list" data-share-list><li class="muted">Loading…</li></ul>
    </section>
    <p class="share-msg" data-share-msg role="status"></p>
    <div class="actions"><button type="button" class="btn btn--ghost" data-close>Done</button></div>
  </div>
</dialog>

<!-- Versions -->
<dialog id="dlg-versions" class="dialog dialog--wide">
  <div class="dialog__body">
    <h2>Previous versions</h2>
    <p class="muted" data-versions-file></p>
    <div data-versions-list><p class="muted">Loading…</p></div>
    <p class="hint">When you upload a file with the same name, the old one is kept here (up to <span data-versions-keep>10</span> versions per file).</p>
    <div class="actions"><button type="button" class="btn btn--ghost" data-close>Close</button></div>
  </div>
</dialog>

<!-- Move / copy -->
<dialog id="dlg-move" class="dialog dialog--wide">
  <form method="post" data-move-form>
    <?= csrf_field() ?>
    <input type="hidden" name="dir" value="<?= e($here) ?>">
    <input type="hidden" name="dest" data-move-dest>
    <div data-move-targets></div>
    <h2 data-move-title>Move or copy</h2>
    <p class="muted" data-move-summary></p>
    <nav class="crumbs crumbs--small" data-move-crumbs></nav>
    <ul class="folder-picker" data-move-list></ul>
    <div class="actions">
      <button type="button" class="btn btn--ghost" data-close>Cancel</button>
      <button class="btn btn--secondary" type="submit" formaction="<?= e(url('files.copy')) ?>" data-copy-here><?= icon('copy') ?> Copy here</button>
      <button class="btn btn--primary" type="submit" formaction="<?= e(url('files.move')) ?>" data-move-here><?= icon('move') ?> Move here</button>
    </div>
  </form>
</dialog>
<?php endif; ?>

<?php if ($canEdit): ?>
<!-- New folder / rename / delete -->
<dialog id="dlg-mkdir" class="dialog">
  <form method="post" action="<?= e($su('files.mkdir')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="dir" value="<?= e($here) ?>">
    <h2>New folder</h2>
    <label class="field"><span>Folder name</span><input name="name" required maxlength="255" autocomplete="off"></label>
    <div class="actions"><button type="button" class="btn btn--ghost" data-close>Cancel</button>
      <button class="btn btn--primary" type="submit">Create</button></div>
  </form>
</dialog>

<dialog id="dlg-rename" class="dialog">
  <form method="post" action="<?= e($su('files.rename')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="target" data-rename-target>
    <input type="hidden" name="return" value="<?= e($here) ?>">
    <h2>Rename</h2>
    <label class="field"><span>New name</span><input name="name" required maxlength="255" autocomplete="off" data-rename-name></label>
    <div class="actions"><button type="button" class="btn btn--ghost" data-close>Cancel</button>
      <button class="btn btn--primary" type="submit">Rename</button></div>
  </form>
</dialog>

<dialog id="dlg-delete" class="dialog">
  <form method="post" action="<?= e($su('files.delete')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="dir" value="<?= e($here) ?>">
    <div data-delete-targets></div>
    <h2>Move to trash?</h2>
    <p data-delete-summary></p>
    <p class="hint"><?= $own ? 'You can restore it from <strong>Trash</strong> for ' . (int) \BlaCloud\Trash::retentionDays() . ' days.'
        : 'It goes to ' . e($ownerName) . '\'s trash, where they can restore it.' ?></p>
    <div class="actions"><button type="button" class="btn btn--ghost" data-close>Cancel</button>
      <button class="btn btn--danger" type="submit"><?= icon('trash') ?> Move to trash</button></div>
  </form>
</dialog>
<?php endif; ?>
