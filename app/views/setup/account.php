<?php $f = $setup['account_form'] ?? []; ?>
<h2>Create your administrator account</h2>
<p class="lead">This is the main account for your cloud. You'll add two-step verification right after.</p>

<form method="post" class="form" autocomplete="on">
  <?= csrf_field() ?>
  <div class="grid-2">
    <label class="field"><span>Username</span>
      <input name="username" required minlength="3" maxlength="32" pattern="[A-Za-z0-9._\-]{3,32}"
             value="<?= e($f['username'] ?? '') ?>" autocomplete="username" autocapitalize="none" spellcheck="false"></label>
    <label class="field"><span>Your name <small>(optional)</small></span>
      <input name="display_name" maxlength="128" value="<?= e($f['display'] ?? '') ?>" autocomplete="name"></label>
  </div>
  <label class="field"><span>Email <small>(optional, for sign-in and future notifications)</small></span>
    <input name="email" type="email" value="<?= e($f['email'] ?? '') ?>" autocomplete="email"></label>
  <div class="grid-2">
    <label class="field"><span>Password</span>
      <input name="password" type="password" required minlength="12" autocomplete="new-password" data-strength></label>
    <label class="field"><span>Confirm password</span>
      <input name="password_confirm" type="password" required minlength="12" autocomplete="new-password"></label>
  </div>
  <div class="meter" data-meter aria-hidden="true"><span></span></div>
  <p class="hint">At least 12 characters. A phrase like <em>"quiet river morning bread"</em> is strong and easy to remember.</p>

  <div class="actions">
    <a class="btn btn--ghost" href="?step=storage">Back</a>
    <button class="btn btn--primary" type="submit" data-busy="Installing…">Install BLA-Cloud <?= icon('check') ?></button>
  </div>
</form>
