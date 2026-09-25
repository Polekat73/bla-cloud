<?php $on = (int) $user['totp_enabled'] === 1; ?>
<div class="page">
  <p class="eyebrow">Your account</p>
  <h1>Security</h1>

  <div class="cards">
    <section class="card">
      <div class="card__head">
        <span class="card__icon <?= $on ? 'is-ok' : 'is-warn' ?>"><?= icon('shield') ?></span>
        <div>
          <h2>Two-step verification</h2>
          <p class="muted"><?= $on ? 'On — a code from your phone is needed to sign in.' : 'Off — only your password protects this account.' ?></p>
        </div>
      </div>
      <?php if ($on): ?>
        <p>Recovery codes left: <strong><?= (int) $remaining ?></strong> of 10<?= $remaining < 4 ? ' — consider making new ones.' : '' ?></p>
        <form method="post" action="<?= e(url('settings.2fa')) ?>" class="form">
          <?= csrf_field() ?>
          <label class="field"><span>Confirm with your password</span>
            <input type="password" name="current_password" required autocomplete="current-password"></label>
          <div class="actions actions--left">
            <button class="btn btn--secondary" name="action" value="regenerate" type="submit"><?= icon('key') ?> New recovery codes</button>
            <?php if (!$required): ?>
              <button class="btn btn--danger" name="action" value="disable" type="submit">Turn off</button>
            <?php endif; ?>
          </div>
          <?php if ($required): ?><p class="hint">Required for administrators, so it can't be turned off.</p><?php endif; ?>
        </form>
      <?php else: ?>
        <a class="btn btn--primary" href="<?= e(url('2fa-setup')) ?>"><?= icon('shield') ?> Turn on</a>
      <?php endif; ?>
    </section>

    <section class="card">
      <div class="card__head">
        <span class="card__icon"><?= icon('key') ?></span>
        <div><h2>Password</h2><p class="muted">Changing it signs out your other devices.</p></div>
      </div>
      <form method="post" action="<?= e(url('settings.password')) ?>" class="form">
        <?= csrf_field() ?>
        <input type="text" name="username" value="<?= e($user['username']) ?>" autocomplete="username" hidden>
        <label class="field"><span>Current password</span>
          <input type="password" name="current_password" required autocomplete="current-password"></label>
        <label class="field"><span>New password</span>
          <input type="password" name="new_password" required minlength="12" autocomplete="new-password" data-strength></label>
        <div class="meter" data-meter aria-hidden="true"><span></span></div>
        <label class="field"><span>Confirm new password</span>
          <input type="password" name="new_password_confirm" required minlength="12" autocomplete="new-password"></label>
        <button class="btn btn--primary" type="submit">Change password</button>
      </form>
    </section>
  </div>

  <section class="card">
    <h2>Recent activity</h2>
    <?php include __DIR__ . '/../partials/activity.php'; ?>
  </section>
</div>
