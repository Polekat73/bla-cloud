<div class="<?= $forced ? '' : 'page page--narrow' ?>">
  <?php if (!$forced): ?>
    <p class="eyebrow">Security</p>
    <h1>Set up two-step verification</h1>
  <?php else: ?>
    <p class="auth__lead"><strong>One more step.</strong> Administrator accounts must use two-step verification, so a stolen password alone can't get in.</p>
  <?php endif; ?>

  <ol class="setup-steps">
    <li>
      <strong>Install an authenticator app</strong> on your phone — Google Authenticator, Microsoft Authenticator, Authy, 2FAS, Bitwarden or 1Password all work.
    </li>
    <li>
      <strong>Scan this code</strong> with the app (tap “+” or “Add account”).
      <div class="qr-box">
        <div class="qr" data-qr="<?= e($uri) ?>" role="img" aria-label="QR code for your authenticator app"></div>
        <div class="qr-manual">
          <span class="hint">Can't scan? Enter this key manually:</span>
          <code class="secret" data-copy-text="<?= e($secret) ?>"><?= e(trim(chunk_split($secret, 4, ' '))) ?></code>
          <button type="button" class="btn btn--ghost btn--sm" data-copy="<?= e($secret) ?>"><?= icon('copy') ?> Copy key</button>
        </div>
      </div>
    </li>
    <li>
      <strong>Enter the 6-digit code</strong> the app shows:
      <form method="post" action="<?= e(url('2fa-setup')) ?>" class="form form--inline">
        <?= csrf_field() ?>
        <input name="code" required inputmode="numeric" autocomplete="one-time-code" class="code-input"
               maxlength="7" placeholder="123456" aria-label="6-digit code" <?= $forced ? 'autofocus' : '' ?>>
        <button class="btn btn--primary" type="submit" data-busy="Checking…">Turn on</button>
      </form>
    </li>
  </ol>
</div>
