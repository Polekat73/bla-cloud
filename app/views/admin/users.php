<?php $statusLabel = ['active' => 'Active', 'invited' => 'Invited', 'disabled' => 'Disabled']; ?>
<div class="page">
  <div class="page-head">
    <div><p class="eyebrow">Administration</p><h1>People</h1></div>
    <button type="button" class="btn btn--primary" data-open="dlg-adduser"><?= icon('plus') ?> Add person</button>
  </div>

  <?php include __DIR__ . '/../partials/new-link.php'; ?>

  <section class="card card--flush">
    <div class="table-wrap">
    <table class="file-table user-table">
      <thead><tr><th>Name</th><th>Status</th><th>Role</th><th>2-step</th><th>Storage</th><th class="col-date">Last sign-in</th></tr></thead>
      <tbody>
      <?php foreach ($users as $u): ?>
        <tr>
          <td><a class="file-link" href="<?= e(url('user', ['id' => $u['id']])) ?>">
            <span class="avatar avatar--sm" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($u['display_name'] ?: $u['username'], 0, 1))) ?></span>
            <span><span class="fname"><?= e($u['display_name'] ?: $u['username']) ?></span><br>
              <small><?= e($u['username']) ?><?= $u['email'] !== '' ? ' · ' . e($u['email']) : '' ?></small></span></a></td>
          <td><span class="pill pill--<?= e($u['status']) ?>"><?= e($statusLabel[$u['status']]) ?></span></td>
          <td><?= (int) $u['is_admin'] ? 'Administrator' : 'Member' ?></td>
          <td><?= (int) $u['totp_enabled'] ? '<span class="ok-text">' . icon('check') . ' On</span>' : '<span class="muted">Off</span>' ?></td>
          <td class="nowrap">
            <?= e(\BlaCloud\View::bytes($u['usage'])) ?>
            <?php if ((int) $u['quota_bytes'] > 0): $pct = min(100, $u['usage'] / (int) $u['quota_bytes'] * 100); ?>
              <span class="muted">of <?= e(\BlaCloud\View::bytes((int) $u['quota_bytes'])) ?></span>
              <div class="quota"><span class="<?= $pct > 90 ? 'is-high' : '' ?>" data-width="<?= round($pct) ?>"></span></div>
            <?php endif; ?>
          </td>
          <td class="col-date"><?= $u['last_login_at'] ? e(human_time((int) strtotime($u['last_login_at'] . ' UTC'))) : '<span class="muted">Never</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </section>
</div>

<dialog id="dlg-adduser" class="dialog dialog--wide">
  <form method="post" action="<?= e(url('users.create')) ?>" class="form" data-adduser>
    <?= csrf_field() ?>
    <h2>Add a person</h2>
    <div class="grid-2">
      <label class="field"><span>Username</span>
        <input name="username" required pattern="[A-Za-z0-9._\-]{3,32}" maxlength="32" autocomplete="off" autocapitalize="none" spellcheck="false"></label>
      <label class="field"><span>Name</span><input name="display_name" maxlength="128" autocomplete="off"></label>
    </div>
    <label class="field"><span>Email <small>(needed for email invitations and password resets)</small></span>
      <input name="email" type="email" autocomplete="off"></label>
    <div class="grid-2">
      <label class="field"><span>Storage limit (GB, 0 = no limit)</span>
        <input name="quota_gb" inputmode="decimal" value="<?= (int) $quotaGb ?>"></label>
      <label class="check-row check-row--field"><input type="checkbox" name="is_admin" value="1"><span>Administrator (can manage people and settings)</span></label>
    </div>
    <fieldset class="choice-group">
      <legend class="field-legend">How will they get in?</legend>
      <label class="choice <?= $mail ? '' : 'is-disabled' ?>"><input type="radio" name="mode" value="email" <?= $mail ? 'checked' : 'disabled' ?>>
        <span class="choice__body"><strong>Email them an invitation</strong><span><?= $mail ? 'They choose their own password.' : 'Set up email in Settings to use this.' ?></span></span></label>
      <label class="choice"><input type="radio" name="mode" value="link" <?= $mail ? '' : 'checked' ?>>
        <span class="choice__body"><strong>Give me an invitation link</strong><span>You send it to them yourself. They choose their own password.</span></span></label>
      <label class="choice"><input type="radio" name="mode" value="password">
        <span class="choice__body"><strong>Set a password now</strong><span>You tell them the password privately.</span></span></label>
    </fieldset>
    <label class="field" data-password-field hidden><span>Password</span>
      <input name="password" type="password" minlength="12" autocomplete="new-password" data-strength></label>
    <div class="meter" data-meter aria-hidden="true" hidden><span></span></div>
    <div class="actions"><button type="button" class="btn btn--ghost" data-close>Cancel</button>
      <button class="btn btn--primary" type="submit" data-busy="Creating…">Add person</button></div>
  </form>
</dialog>
