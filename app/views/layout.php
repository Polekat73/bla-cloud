<?php
/** Main app shell: sidebar + content. */
$me = null;
if (\BlaCloud\Config::isInstalled()) {
    try {
        $me = \BlaCloud\Auth::user();
    } catch (\Throwable) {
        $me = null;
    }
}
$nav = $nav ?? '';
include __DIR__ . '/partials/head.php';
?>
<body class="app" data-csrf="<?= e(\BlaCloud\Security::csrfToken()) ?>">
<?php include __DIR__ . '/partials/icons.php'; ?>
<a class="skip" href="#main">Skip to content</a>

<header class="topbar">
  <button class="icon-btn topbar__menu" type="button" data-toggle-nav aria-label="Menu"><?= icon('menu') ?></button>
  <a class="brand" href="<?= e(url('files')) ?>">
    <img src="<?= e(asset('img/logo.webp')) ?>" alt="" width="36" height="36">
    <span class="brand__name">Haven</span>
  </a>
  <?php if ($me): ?>
  <div class="topbar__user">
    <span class="avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($me['display_name'] ?: $me['username'], 0, 1))) ?></span>
    <span class="topbar__name"><?= e($me['display_name'] ?: $me['username']) ?></span>
  </div>
  <?php endif; ?>
</header>

<div class="shell">
  <?php if ($me): ?>
  <nav class="sidebar" aria-label="Main">
    <a class="nav-link <?= $nav === 'files' ? 'is-active' : '' ?>" href="<?= e(url('files')) ?>"><?= icon('folder') ?> My files</a>
    <?php foreach (\BlaCloud\Apps::navItems() as $item): ?>
    <a class="nav-link <?= $nav === $item['route'] ? 'is-active' : '' ?>" href="<?= e(url($item['route'])) ?>"><?= icon($item['icon']) ?> <?= e($item['label']) ?></a>
    <?php endforeach; ?>
    <a class="nav-link <?= $nav === 'shared' ? 'is-active' : '' ?>" href="<?= e(url('shared')) ?>"><?= icon('users') ?> Shared with me</a>
    <a class="nav-link <?= $nav === 'shared-by-me' ? 'is-active' : '' ?>" href="<?= e(url('shared-by-me')) ?>"><?= icon('link') ?> Shared by me</a>
    <a class="nav-link <?= $nav === 'trash' ? 'is-active' : '' ?>" href="<?= e(url('trash')) ?>"><?= icon('bin') ?> Trash</a>
    <a class="nav-link <?= $nav === 'sync' ? 'is-active' : '' ?>" href="<?= e(url('sync')) ?>"><?= icon('key') ?> Sync</a>
    <a class="nav-link <?= $nav === 'settings' ? 'is-active' : '' ?>" href="<?= e(url('settings')) ?>"><?= icon('shield') ?> Security</a>
    <?php if ((int) $me['is_admin'] === 1): ?>
      <span class="nav-group">Administration</span>
      <a class="nav-link <?= $nav === 'users' ? 'is-active' : '' ?>" href="<?= e(url('users')) ?>"><?= icon('users') ?> People</a>
      <a class="nav-link <?= $nav === 'admin.settings' ? 'is-active' : '' ?>" href="<?= e(url('admin.settings')) ?>"><?= icon('gear') ?> Settings</a>
      <a class="nav-link <?= $nav === 'admin' ? 'is-active' : '' ?>" href="<?= e(url('admin')) ?>"><?= icon('gauge') ?> System status</a>
      <a class="nav-link <?= $nav === 'admin.backups' ? 'is-active' : '' ?>" href="<?= e(url('admin.backups')) ?>"><?= icon('archive') ?> Backups</a>
      <a class="nav-link <?= $nav === 'admin.encryption' ? 'is-active' : '' ?>" href="<?= e(url('admin.encryption')) ?>"><?= icon('lock') ?> Encryption</a>
      <a class="nav-link <?= $nav === 'admin.apps' ? 'is-active' : '' ?>" href="<?= e(url('admin.apps')) ?>"><?= icon('puzzle') ?> Apps</a>
    <?php endif; ?>
    <form class="sidebar__logout" method="post" action="<?= e(url('logout')) ?>">
      <?= csrf_field() ?>
      <button class="nav-link" type="submit"><?= icon('logout') ?> Sign out</button>
    </form>
  </nav>
  <?php endif; ?>

  <main id="main" class="main">
    <?php include __DIR__ . '/partials/flash.php'; ?>
    <?= $content ?>
  </main>
</div>

<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
