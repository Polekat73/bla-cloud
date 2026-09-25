<?php
$checks = \BlaCloud\Installer::checks();
$blocked = \BlaCloud\Installer::hasBlockingProblem($checks);
$labels = ['ok' => 'Ready', 'warn' => 'Heads-up', 'fail' => 'Needs fixing'];
?>
<h2>Let's get your private cloud running</h2>
<p class="lead">This takes about three minutes. First, a quick check that your server has everything BLA-Cloud needs.</p>

<ul class="checks">
  <?php foreach ($checks as $c): ?>
    <li class="check check--<?= e($c['status']) ?>">
      <span class="check__badge"><?= icon($c['status'] === 'ok' ? 'check' : ($c['status'] === 'fail' ? 'x' : 'alert')) ?></span>
      <span class="check__text">
        <strong><?= e($c['label']) ?></strong>
        <span><?= e($c['detail']) ?></span>
      </span>
      <span class="check__tag"><?= e($labels[$c['status']]) ?></span>
    </li>
  <?php endforeach; ?>
</ul>

<form method="post" class="actions">
  <?= csrf_field() ?>
  <?php if ($blocked): ?>
    <p class="hint">Fix the red items (your hosting control panel usually has a "PHP settings" page), then reload.</p>
    <a class="btn btn--secondary" href="?step=welcome">Check again</a>
  <?php else: ?>
    <button class="btn btn--primary" type="submit">Continue <?= icon('chevron') ?></button>
  <?php endif; ?>
</form>
