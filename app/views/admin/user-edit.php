<?php
$isMe = (int) $u['id'] === (int) $user['id'];
$statusLabel = ['active' => 'Active', 'invited' => 'Invited — hasn\'t chosen a password yet', 'disabled' => 'Disabled'];
$quotaGb = (int) $u['quota_bytes'] > 0 ? round((int) $u['quota_bytes'] / 1024 ** 3, 2) : 0;
?>
<div class="page">
  <p class="eyebrow"><a href="<?= e(url('users')) ?>">People</a></p>
  <div class="page-head">
    <h1><?= e($u['display_name'] ?: $u['username']) ?></h1>
    <span class="pill pill--<?= e($status) ?>"><?= e($statusLabel[$status]) ?></span>
  </div>

  <?php include __DIR__ . '/../partials/new-link.php'; ?>

  <div class="cards">
    <section class="card">
      <h2>Account</h2>
      <form method="post" action="<?= e(url('user', ['id' => $u['id']])) ?>" class="form">
        <?= csrf_field() ?>
        <label class="field"><span>Username</span><input value="<?= e($u['username']) ?>" readonly></label>
        <label class="field"><span>Name</span><input name="display_name" maxlength="128" value="<?= e($u['display_name']) ?>"></label>
        <label class="field"><span>Email</span><input name="email" type="email" value="<?= e($u['email']) ?>"></label>
        <label class="field"><span>Storage limit (GB, 0 = no limit)</span><input name="quota_gb" inputmode="decimal" value="<?= e((string) $quotaGb) ?>"></label>
        <p class="muted">Using <?= e(\BlaCloud\View::bytes($usage)) ?><?= $quotaGb ? ' of ' . e((string) $quotaGb) . ' GB' : '' ?>.</p>
        <label class="check-row"><input type="checkbox" name="is_admin" value="1" <?= (int) $u['is_admin'] ? 'checked' : '' ?> <?= $isMe ? 'disabled' : '' ?>>
          <span>Administrator</span></label>
        <label class="check-row"><input type="checkbox" name="is_active" value="1" <?= (int) $u['is_active'] ? 'checked' : '' ?> <?= $isMe ? 'disabled' : '' ?>>
          <span>Account enabled <small>(untick to block sign-in and their share links)</small></span></label>
        <?php if ($isMe): ?>
          <input type="hidden" name="is_admin" value="1"><input type="hidden" name="is_active" value="1">
          <p class="hint">This is you — you can't remove your own administrator rights.</p>
        <?php endif; ?>
        <button class="btn btn--primary" type="submit">Save</button>
      </form>
    </section>

    <section class="card">
      <h2>Access</h2>
      <?php if ($status === 'invited'): ?>
        <p><?= $invite ? 'Invitation valid until ' . e(date('M j, H:i', (int) strtotime($invite['expires_at'] . ' UTC'))) . '.' : 'The invitation has expired.' ?></p>
        <form method="post" action="<?= e(url('users.action')) ?>" class="actions actions--left">
          <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
          <button class="btn btn--secondary" name="action" value="invite"><?= icon('key') ?> <?= $mail && $u['email'] ? 'Email a new invitation' : 'New invitation link' ?></button>
        </form>
      <?php else: ?>
        <p>Two-step verification: <strong><?= (int) $u['totp_enabled'] ? 'On' : 'Off' ?></strong></p>
        <form method="post" action="<?= e(url('users.action')) ?>" class="stack">
          <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $u['id'] ?>"><input type="hidden" name="action" value="reset">
          <div class="actions actions--left">
            <?php if ($mail && $u['email']): ?>
              <button class="btn btn--secondary" name="how" value="email"><?= icon('key') ?> Email a password reset</button>
            <?php endif; ?>
            <button class="btn btn--ghost" name="how" value="link">Create a reset link</button>
          </div>
        </form>
        <?php if ((int) $u['totp_enabled'] && !$isMe): ?>
          <form method="post" action="<?= e(url('users.action')) ?>" class="stack">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
            <button class="btn btn--ghost" name="action" value="reset2fa" data-confirm="Turn off two-step verification for <?= e($u['username']) ?>? Only do this if they lost their phone and recovery codes.">
              Reset two-step verification</button>
            <p class="hint">For when they've lost their phone and their recovery codes.</p>
          </form>
        <?php endif; ?>
      <?php endif; ?>
    </section>
  </div>

  <section class="card">
    <h2>Recent activity</h2>
    <?php $activity = $activity ?? []; include __DIR__ . '/../partials/activity.php'; ?>
  </section>

  <?php if (!$isMe): ?>
  <section class="card card--danger">
    <h2>Delete account</h2>
    <p>Deletes <strong><?= e($u['username']) ?></strong> and <strong>all of their files, trash, versions and shares</strong>. This cannot be undone.</p>
    <form method="post" action="<?= e(url('users.action')) ?>" class="form form--inline">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
      <input name="confirm" placeholder="Type <?= e($u['username']) ?> to confirm" autocomplete="off" required aria-label="Type the username to confirm">
      <button class="btn btn--danger" name="action" value="delete">Delete forever</button>
    </form>
  </section>
  <?php endif; ?>
</div>
