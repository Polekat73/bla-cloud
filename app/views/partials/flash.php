<?php
$flashes = \BlaCloud\Session::takeFlashes();
if (!empty($errors)) {
    foreach ((array) $errors as $err) {
        $flashes[] = ['type' => 'error', 'message' => $err];
    }
}
if (!empty($error)) {
    $flashes[] = ['type' => 'error', 'message' => $error];
}
$iconFor = ['success' => 'check', 'error' => 'alert', 'warning' => 'alert', 'info' => 'shield'];
?>
<?php if ($flashes): ?>
<div class="flashes" role="status" aria-live="polite">
  <?php foreach ($flashes as $f): ?>
    <div class="flash flash--<?= e($f['type']) ?>">
      <?= icon($iconFor[$f['type']] ?? 'shield') ?>
      <span><?= e($f['message']) ?></span>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
