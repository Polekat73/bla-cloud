<?php if ($kind === 'invite'): ?>
  <p class="auth__lead"><strong>Welcome, <?= e($row['display_name'] ?: $row['username']) ?>!</strong><br>
    Choose a password to finish setting up your account.</p>
<?php else: ?>
  <p class="auth__lead">Choose a new password for <strong><?= e($row['username']) ?></strong>.</p>
<?php endif; ?>
<form method="post" action="<?= e(url($kind, ['t' => $token])) ?>" class="form">
  <?= csrf_field() ?>
  <label class="field"><span>Username</span>
    <input value="<?= e($row['username']) ?>" autocomplete="username" readonly></label>
  <label class="field"><span>New password</span>
    <input name="password" type="password" required minlength="12" autocomplete="new-password" autofocus data-strength></label>
  <div class="meter" data-meter aria-hidden="true"><span></span></div>
  <label class="field"><span>Confirm password</span>
    <input name="password_confirm" type="password" required minlength="12" autocomplete="new-password"></label>
  <p class="hint">At least 12 characters. A phrase of 3–4 words is strong and easy to remember.</p>
  <button class="btn btn--primary btn--block" type="submit" data-busy="Saving…"><?= $kind === 'invite' ? 'Create my account' : 'Save new password' ?></button>
</form>
