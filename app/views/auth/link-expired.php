<div class="done">
  <div class="empty__icon"><?= icon('clock') ?></div>
  <h2>This link has expired</h2>
  <p class="auth__lead"><?= $kind === 'invite'
      ? 'Invitation links work once and last 7 days. Ask the person who invited you to send a new one.'
      : 'Reset links work once and last 1 hour. You can request a new one.' ?></p>
  <?php if ($kind === 'reset'): ?>
    <a class="btn btn--primary" href="<?= e(url('forgot')) ?>">Request a new link</a>
  <?php endif; ?>
</div>
<p class="auth__alt"><a href="<?= e(url('login')) ?>">Back to sign in</a></p>
