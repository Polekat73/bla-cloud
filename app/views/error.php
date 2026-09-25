<div class="empty">
  <div class="empty__icon"><?= icon('alert') ?></div>
  <h1><?= e($title ?? 'Something went wrong') ?></h1>
  <p><?= e($message ?? '') ?></p>
  <p><a class="btn btn--secondary" href="<?= e(\BlaCloud\Request::basePath() . '/index.php') ?>">Go back home</a></p>
</div>
