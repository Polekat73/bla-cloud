<?php
include __DIR__ . '/partials/head.php';
$labels = ['welcome' => 'Check', 'database' => 'Database', 'storage' => 'Storage', 'account' => 'Your account'];
$current = array_search($step ?? 'welcome', $steps ?? [], true);
?>
<body class="setup">
<?php include __DIR__ . '/partials/icons.php'; ?>
<main class="setup__wrap">
  <header class="setup__head">
    <img src="<?= e(asset('img/logo.webp')) ?>" alt="Best Life Apps" width="88" height="88">
    <div>
      <p class="eyebrow">Setup wizard</p>
      <h1>Welcome to Haven</h1>
    </div>
  </header>

  <?php if (($step ?? '') !== 'done'): ?>
  <ol class="stepper" aria-label="Setup progress">
    <?php foreach ($steps as $i => $s): ?>
      <li class="stepper__item <?= $i < $current ? 'is-done' : ($i === $current ? 'is-current' : '') ?>" <?= $i === $current ? 'aria-current="step"' : '' ?>>
        <span class="stepper__dot"><?= $i < $current ? icon('check') : $i + 1 ?></span>
        <span class="stepper__label"><?= e($labels[$s] ?? $s) ?></span>
      </li>
    <?php endforeach; ?>
  </ol>
  <?php endif; ?>

  <section class="card setup__card">
    <?php include __DIR__ . '/partials/flash.php'; ?>
    <?= $content ?>
  </section>
  <p class="setup__foot">Haven <?= e(BLA_VERSION) ?> · by Best Life Apps</p>
</main>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
