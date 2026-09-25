<div class="auth__card auth__card--center">
  <div class="empty__icon"><?= icon('lock') ?></div>
  <h1 class="auth__title"><?= e(basename($share['path'])) ?></h1>
  <p class="auth__lead">This link is protected. Enter the password <?= e($share['owner_name'] ?: $share['owner_username']) ?> gave you.</p>
  <form method="post" action="<?= e(url('s', ['t' => $token])) ?>" class="form">
    <?= csrf_field() ?>
    <label class="field"><span>Password</span>
      <input type="password" name="password" required autofocus autocomplete="off"></label>
    <button class="btn btn--primary btn--block" type="submit" data-busy="Checking…">Open</button>
  </form>
</div>
