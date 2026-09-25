<form method="post" action="<?= e(url('login')) ?>" class="form">
  <?= csrf_field() ?>
  <label class="field"><span>Username or email</span>
    <input name="username" required autofocus value="<?= e($username ?? '') ?>" autocomplete="username" autocapitalize="none" spellcheck="false"></label>
  <label class="field"><span>Password</span>
    <input name="password" type="password" required autocomplete="current-password"></label>
  <button class="btn btn--primary btn--block" type="submit" data-busy="Signing in…">Sign in</button>
</form>
<p class="auth__alt"><a href="<?= e(url('forgot')) ?>">Forgot your password?</a></p>
