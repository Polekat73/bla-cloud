<div class="page">
  <p class="eyebrow">Administration</p>
  <h1>Settings</h1>

  <form method="post" action="<?= e(url('admin.settings')) ?>" class="stack-lg">
    <?= csrf_field() ?>
    <div class="cards">
      <section class="card">
        <h2>Email</h2>
        <p class="muted">Used for invitations, password resets and share notifications.
          <?= $mailOn ? '<span class="ok-text">' . icon('check') . ' Ready</span>' : '' ?></p>
        <fieldset class="choice-group choice-group--row">
          <legend class="sr-only">How to send email</legend>
          <?php foreach (['off' => 'Off', 'smtp' => 'SMTP server (recommended)', 'php' => 'Server default (mail())'] as $v => $l): ?>
            <label class="choice choice--sm"><input type="radio" name="mail_mode" value="<?= $v ?>" <?= $s['mail_mode'] === $v ? 'checked' : '' ?>>
              <span class="choice__body"><strong><?= e($l) ?></strong></span></label>
          <?php endforeach; ?>
        </fieldset>
        <div class="grid-2">
          <label class="field"><span>From address</span><input name="mail_from" type="email" value="<?= e($s['mail_from']) ?>" placeholder="cloud@yourdomain.com"></label>
          <label class="field"><span>From name</span><input name="mail_from_name" value="<?= e($s['mail_from_name']) ?>"></label>
        </div>
        <div data-smtp-fields>
          <div class="grid-2">
            <label class="field"><span>SMTP server</span><input name="smtp_host" value="<?= e($s['smtp_host']) ?>" placeholder="smtp.yourhost.com" autocomplete="off"></label>
            <label class="field"><span>Port</span><input name="smtp_port" inputmode="numeric" value="<?= (int) $s['smtp_port'] ?>"></label>
          </div>
          <label class="field"><span>Security</span>
            <select name="smtp_security">
              <?php foreach (['starttls' => 'STARTTLS (port 587)', 'ssl' => 'SSL/TLS (port 465)', 'none' => 'None (local relay only)'] as $v => $l): ?>
                <option value="<?= $v ?>" <?= $s['smtp_security'] === $v ? 'selected' : '' ?>><?= e($l) ?></option>
              <?php endforeach; ?>
            </select></label>
          <div class="grid-2">
            <label class="field"><span>Username</span><input name="smtp_user" value="<?= e($s['smtp_user']) ?>" autocomplete="off"></label>
            <label class="field"><span>Password</span><input name="smtp_pass" type="password" autocomplete="new-password"
              placeholder="<?= $s['smtp_pass'] !== '' ? '•••••••• (saved — leave blank to keep)' : '' ?>"></label>
          </div>
          <?php if ($s['smtp_pass'] !== ''): ?>
            <label class="check-row"><input type="checkbox" name="smtp_pass_clear" value="1"><span>Remove the saved password</span></label>
          <?php endif; ?>
          <p class="hint">Your email provider or host lists these as “SMTP settings”. The password is stored encrypted.</p>
        </div>
      </section>

      <section class="card">
        <h2>Web address</h2>
        <label class="field"><span>Public address of this cloud</span>
          <input name="base_url" value="<?= e($s['base_url']) ?>" placeholder="https://cloud.example.com" class="mono" spellcheck="false"></label>
        <p class="hint">Used in email links and share links. Password reset emails are only sent when this is set.</p>

        <h2 class="mt">Security</h2>
        <label class="check-row"><input type="checkbox" name="require_2fa_all" value="1" <?= $s['require_2fa_all'] ? 'checked' : '' ?>>
          <span>Require two-step verification for <strong>everyone</strong> <small>(always required for administrators)</small></span></label>

        <h2 class="mt">Storage</h2>
        <div class="grid-2">
          <label class="field"><span>Keep trash for (days)</span><input name="trash_days" inputmode="numeric" value="<?= (int) $s['trash_days'] ?>"></label>
          <label class="field"><span>Default limit for new people (GB, 0 = none)</span><input name="default_quota_gb" inputmode="numeric" value="<?= (int) $s['default_quota_gb'] ?>"></label>
          <label class="field"><span>Versions kept per file</span><input name="versions_keep" inputmode="numeric" value="<?= (int) $s['versions_keep'] ?>"></label>
          <label class="field"><span>Delete versions older than (days)</span><input name="versions_max_days" inputmode="numeric" value="<?= (int) $s['versions_max_days'] ?>"></label>
        </div>
      </section>

      <section class="card">
        <h2>Sharing</h2>
        <label class="check-row"><input type="checkbox" name="links_enabled" value="1" <?= $s['links_enabled'] ? 'checked' : '' ?>>
          <span>Allow public share links <small>(anyone with the link can open it)</small></span></label>
        <label class="check-row"><input type="checkbox" name="links_require_password" value="1" <?= $s['links_require_password'] ? 'checked' : '' ?>>
          <span>Every link must have a password</span></label>
        <div class="grid-2">
          <label class="field"><span>Suggested link expiry (days, 0 = none)</span><input name="links_default_days" inputmode="numeric" value="<?= (int) $s['links_default_days'] ?>"></label>
          <label class="field"><span>Maximum link lifetime (days, 0 = no limit)</span><input name="links_max_days" inputmode="numeric" value="<?= (int) $s['links_max_days'] ?>"></label>
        </div>
        <label class="check-row"><input type="checkbox" name="share_notify" value="1" <?= $s['share_notify'] ? 'checked' : '' ?>>
          <span>Email people when something is shared with them</span></label>
      </section>
    </div>
    <div class="actions actions--left"><button class="btn btn--primary" type="submit">Save settings</button></div>
  </form>

  <section class="card">
    <h2>Send a test email</h2>
    <form method="post" action="<?= e(url('admin.testmail')) ?>" class="form form--inline">
      <?= csrf_field() ?>
      <input name="to" type="email" value="<?= e($user['email']) ?>" placeholder="you@example.com" aria-label="Send test to">
      <button class="btn btn--secondary" type="submit" <?= $mailOn ? '' : 'disabled' ?> data-busy="Sending…">Send test</button>
    </form>
    <?php if (!$mailOn): ?><p class="hint">Save your email settings first.</p><?php endif; ?>
  </section>
</div>
