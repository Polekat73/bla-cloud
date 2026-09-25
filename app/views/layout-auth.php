<?php include __DIR__ . '/partials/head.php'; ?>
<body class="auth">
<?php include __DIR__ . '/partials/icons.php'; ?>
<main class="auth__wrap">
  <div class="auth__card">
    <div class="auth__brand">
      <img src="<?= e(asset('img/logo.webp')) ?>" alt="Best Life Apps" width="104" height="104">
      <p class="eyebrow">Best Life Apps</p>
      <h1 class="auth__title">BLA-Cloud</h1>
    </div>
    <?php include __DIR__ . '/partials/flash.php'; ?>
    <?= $content ?>
  </div>
  <p class="auth__foot">Your private cloud · Encrypted in transit · Two-step verification</p>
</main>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
