<?php if ($sent): ?>
  <div class="done">
    <div class="done__icon"><?= icon('check') ?></div>
    <p class="auth__lead">If that account exists and has an email address, a reset link is on its way. It expires in 1 hour.</p>
    <p class="hint">No email after a few minutes? Check your spam folder, or ask your administrator to send you a reset link.</p>
  </div>
<?php else: ?>
  <p class="auth__lead">Enter your username or email and we'll send you a link to choose a new password.</p>
  <?php if (!$mail): ?>
    <div class="note note--warn"><?= icon('alert') ?><span>Email isn't set up on this cloud yet, so your administrator will need to reset your password for you.</span></div>
  <?php endif; ?>
  <form method="post" action="<?= e(url('forgot')) ?>" class="form">
    <?= csrf_field() ?>
    <label class="field"><span>Username or email</span>
      <input name="who" required autofocus autocomplete="username" autocapitalize="none" spellcheck="false"></label>
    <button class="btn btn--primary btn--block" type="submit" data-busy="Sending…">Send reset link</button>
  </form>
<?php endif; ?>
<p class="auth__alt"><a href="<?= e(url('login')) ?>">Back to sign in</a></p>
