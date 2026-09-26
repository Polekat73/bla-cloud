<div class="done">
  <div class="done__icon"><?= icon('check') ?></div>
  <h2>Haven is installed</h2>
  <p class="lead">Next, sign in with the account you just created. You'll then connect an authenticator app
    (like Google Authenticator, Microsoft Authenticator or Authy) — it only takes a minute.</p>
  <ul class="tips">
    <li><?= icon('shield') ?> Keep <code>config/config.php</code> private and backed up — it holds your encryption key.</li>
    <li><?= icon('key') ?> Have your phone ready for two-step verification.</li>
  </ul>
  <div class="actions actions--center">
    <a class="btn btn--primary" href="<?= e(url('login')) ?>">Sign in <?= icon('chevron') ?></a>
  </div>
</div>
