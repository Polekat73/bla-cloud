<?php $labels = ['ok' => 'OK', 'warn' => 'Check', 'fail' => 'Problem']; ?>
<div class="page">
  <p class="eyebrow">Administration</p>
  <h1>System status</h1>

  <div class="cards">
    <section class="card">
      <h2>Health checks</h2>
      <ul class="checks checks--compact">
        <?php foreach ($checks as $c): ?>
          <li class="check check--<?= e($c['status']) ?>">
            <span class="check__badge"><?= icon($c['status'] === 'ok' ? 'check' : ($c['status'] === 'fail' ? 'x' : 'alert')) ?></span>
            <span class="check__text"><strong><?= e($c['label']) ?></strong><span><?= e($c['detail']) ?></span></span>
            <span class="check__tag"><?= e($labels[$c['status']]) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>
    <section class="card">
      <h2>About this server</h2>
      <dl class="info">
        <?php foreach ($info as $k => $v): ?>
          <dt><?= e($k) ?></dt><dd><?= e($v) ?></dd>
        <?php endforeach; ?>
      </dl>
    </section>
  </div>

  <section class="card">
    <h2>Security activity (all users)</h2>
    <?php $showUser = true; include __DIR__ . '/../partials/activity.php'; ?>
  </section>
</div>
