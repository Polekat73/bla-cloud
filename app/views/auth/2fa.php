<p class="auth__lead">Open your authenticator app and enter the 6-digit code for <?= e(\BlaCloud\Config::get('instance_name', 'Haven')) ?>.</p>
<form method="post" action="<?= e(url('2fa')) ?>" class="form">
  <?= csrf_field() ?>
  <label class="field"><span>Verification code</span>
    <input name="code" required autofocus inputmode="numeric" autocomplete="one-time-code"
           class="code-input" maxlength="11" placeholder="123 456"></label>
  <button class="btn btn--primary btn--block" type="submit" data-busy="Checking…">Verify</button>
</form>
<details class="advanced">
  <summary>Lost your phone?</summary>
  <p class="hint">Type one of your recovery codes (like <code>abcde-fghij</code>) in the box above instead. Each code works once.</p>
</details>
<p class="auth__alt"><a href="<?= e(url('login')) ?>">Start over</a></p>
