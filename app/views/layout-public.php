<?php
/** Layout for people opening a public share link (no account needed). */
$share = isset($scope) && $scope instanceof \BlaCloud\Scope ? $scope->share : ($share ?? null);
include __DIR__ . '/partials/head.php';
?>
<body class="app app--public" data-csrf="<?= e(\BlaCloud\Security::csrfToken()) ?>">
<?php include __DIR__ . '/partials/icons.php'; ?>
<header class="topbar">
  <span class="brand">
    <img src="<?= e(asset('img/logo.webp')) ?>" alt="" width="36" height="36">
    <span class="brand__name">BLA<span class="brand__dash">-</span>Cloud</span>
  </span>
  <?php if ($share): ?>
  <div class="topbar__user public-meta">
    <span class="muted">Shared by <strong><?= e($share['owner_name'] ?: $share['owner_username']) ?></strong></span>
    <?php if (!empty($share['expires_at'])): ?>
      <span class="muted">· until <?= e(date('M j, Y', (int) strtotime($share['expires_at'] . ' UTC'))) ?></span>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</header>
<main id="main" class="main main--public">
  <?php include __DIR__ . '/partials/flash.php'; ?>
  <?= $content ?>
  <p class="public-foot">A private cloud by Best Life Apps · Files stay on their owner's server</p>
</main>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
