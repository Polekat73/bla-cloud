<div class="page">
  <p class="eyebrow">Administration</p>
  <h1>Encryption</h1>

  <?php if ($newPassphrase): ?>
  <section class="card">
    <div class="card__head">
      <span class="card__icon is-ok"><?= icon('key') ?></span>
      <div><h2>Encryption passphrase</h2><p class="muted">Save it somewhere safe now — a password manager, or printed with
        important papers. It's needed to read your files if config.php and the database are ever lost, and it is
        <strong>never</strong> shown again.</p></div>
    </div>
    <div class="codes" data-codes><code><?= e(\BlaCloud\Encryption::format($newPassphrase)) ?></code></div>
    <div class="actions actions--left">
      <button type="button" class="btn btn--ghost" data-copy="<?= e($newPassphrase) ?>"><?= icon('copy') ?> Copy</button>
    </div>
  </section>
  <?php endif; ?>

  <?php if (!$hasKey): ?>
  <section class="card">
    <h2>Turn on encryption at rest</h2>
    <p class="muted">Encrypts file <strong>contents</strong> as they're saved, so anyone who only gets the raw
      data folder (a stolen disk, a misconfigured backup, a curious host) can't read them without the passphrase.
      File and folder <strong>names</strong> stay as they are — only the bytes inside each file are protected.</p>
    <p class="muted">Turning this on only affects files saved from now on. Anything already there stays as it is
      until you run "Encrypt existing files now" below.</p>
    <form method="post" action="<?= e(url('admin.encryption.enable')) ?>" class="form">
      <?= csrf_field() ?>
      <label class="field"><span>Passphrase <small>(leave blank to have one generated for you)</small></span>
        <input name="passphrase" type="password" minlength="12" autocomplete="new-password" placeholder="At least 12 characters"></label>
      <p class="hint">Separate from your account password and the app's own encryption key, and never stored
        anywhere in the clear. There's no way to change it later without decrypting and re-encrypting every file —
        choose carefully and store it safely.</p>
      <div class="actions actions--left"><button class="btn btn--primary" type="submit">Turn on encryption</button></div>
    </form>
  </section>

  <?php else: ?>
  <section class="card">
    <div class="card__head">
      <span class="card__icon <?= $enabled ? 'is-ok' : 'is-warn' ?>"><?= icon('lock') ?></span>
      <div><h2>Encryption is <?= $enabled ? 'on' : 'off' ?></h2>
        <p class="muted"><?= $enabled
          ? 'New files are encrypted as they\'re saved.'
          : 'New files are saved as plain files. Anything already encrypted stays encrypted — the passphrase is still needed to read it.' ?></p>
      </div>
    </div>
    <div class="actions actions--left">
      <?php if ($enabled): ?>
        <form method="post" action="<?= e(url('admin.encryption.pause')) ?>"
              data-confirm="Turn off encryption for new files? Files already encrypted stay that way.">
          <?= csrf_field() ?><button class="btn btn--ghost" type="submit">Turn off</button></form>
      <?php else: ?>
        <form method="post" action="<?= e(url('admin.encryption.resume')) ?>">
          <?= csrf_field() ?>
          <button class="btn btn--primary" type="submit"><?= icon('lock') ?> Turn on</button>
        </form>
      <?php endif; ?>
    </div>
  </section>

  <section class="card">
    <h2>Existing files</h2>
    <p class="muted">These walk every account's files, converting each one and skipping anything already in the
      target state. Safe to stop and re-run — each file is changed atomically, so nothing is ever left half-done.
      Only covers the main files area for now, not trash or version history (see docs/ROADMAP.md).</p>
    <?php if (!$backupsOn): ?>
      <div class="note note--warn"><?= icon('alert') ?>
        <span><a href="<?= e(url('admin.backups')) ?>">Set up backups</a> first — a safety backup is taken
          automatically before either of these runs.</span></div>
    <?php endif; ?>
    <div class="actions actions--left">
      <form method="post" action="<?= e(url('admin.encryption.migrate-encrypt')) ?>"
            data-confirm="Encrypt every existing file that isn't already encrypted? A safety backup is taken first.">
        <?= csrf_field() ?>
        <button class="btn btn--primary" type="submit" data-busy="Encrypting…" <?= $backupsOn ? '' : 'disabled' ?>><?= icon('lock') ?> Encrypt existing files now</button>
      </form>
      <form method="post" action="<?= e(url('admin.encryption.migrate-decrypt')) ?>"
            data-confirm="Decrypt every existing encrypted file back to plain? A safety backup is taken first.">
        <?= csrf_field() ?>
        <button class="btn btn--ghost" type="submit" data-busy="Decrypting…" <?= $backupsOn ? '' : 'disabled' ?>>Decrypt existing files</button>
      </form>
    </div>
  </section>
  <?php endif; ?>
</div>
