<?php $kindLabel = ['auto' => 'Scheduled', 'manual' => 'Manual', 'safety' => 'Pre-restore safety']; ?>
<div class="page" data-backups>
  <p class="eyebrow">Administration</p>
  <h1>Backups</h1>

  <?php if ($newPassphrase): ?>
  <section class="card">
    <div class="card__head">
      <span class="card__icon is-ok"><?= icon('key') ?></span>
      <div><h2>Backup passphrase</h2><p class="muted">Save it somewhere safe now — a password manager, or printed with
        important papers. It's needed to restore a backup, and it is <strong>never</strong> shown again.</p></div>
    </div>
    <div class="codes" data-codes><code><?= e(\BlaCloud\Backup::format($newPassphrase)) ?></code></div>
    <div class="actions actions--left">
      <button type="button" class="btn btn--ghost" data-copy="<?= e($newPassphrase) ?>"><?= icon('copy') ?> Copy</button>
    </div>
  </section>
  <?php endif; ?>

  <?php if (!$enabled): ?>
  <section class="card">
    <h2>Turn on backups</h2>
    <p class="muted">An encrypted snapshot of the database and everyone's files, saved to a folder on this server.
      Scheduled backups run as a side effect of the site being visited (like other housekeeping) — for a more
      reliable schedule on hosts that allow it, wire up <code>tools/cron.php</code> (see docs/INSTALL.md).</p>
    <form method="post" action="<?= e(url('admin.backups.enable')) ?>" class="form">
      <?= csrf_field() ?>
      <label class="field"><span>Save backups to</span>
        <input name="dir" required placeholder="/home/youruser/haven-backups" class="mono" spellcheck="false"></label>
      <p class="hint">Ideally outside both the website folder and the data folder, so one lost folder can't take out your backups too.</p>
      <div class="grid-2">
        <label class="field"><span>How often</span>
          <select name="frequency">
            <option value="daily">Daily</option>
            <option value="weekly">Weekly</option>
          </select></label>
        <label class="field"><span>Keep how many</span><input name="retention" inputmode="numeric" value="7"></label>
      </div>
      <label class="field"><span>Passphrase <small>(leave blank to have one generated for you)</small></span>
        <input name="passphrase" type="password" minlength="12" autocomplete="new-password" placeholder="At least 12 characters"></label>
      <p class="hint">This is separate from your account password and is never stored anywhere in the clear.
        Without it, a backup file can't be restored — not even by an administrator.</p>
      <div class="actions actions--left"><button class="btn btn--primary" type="submit">Turn on backups</button></div>
    </form>
  </section>

  <?php else: ?>
  <section class="card">
    <div class="card__head">
      <span class="card__icon is-ok"><?= icon('archive') ?></span>
      <div><h2>Backups are on</h2>
        <p class="muted"><?= e($s['backup_frequency'] === 'weekly' ? 'Weekly' : 'Daily') ?>,
          keeping the newest <?= (int) $s['backup_retention'] ?>, saved to <code class="mono"><?= e($s['backup_dir']) ?></code></p>
      </div>
    </div>
    <?php if ($insideDataDir): ?>
      <div class="note note--warn"><?= icon('alert') ?>
        <span>This folder is inside your data folder. If you ever lose the data folder, you'd lose these backups
          too — consider moving it outside.</span></div>
    <?php endif; ?>
    <div class="actions actions--left">
      <form method="post" action="<?= e(url('admin.backups.run')) ?>"><?= csrf_field() ?>
        <button class="btn btn--primary" type="submit" data-busy="Backing up…"><?= icon('upload') ?> Back up now</button></form>
      <button type="button" class="btn btn--ghost" data-open="dlg-rotate"><?= icon('key') ?> New passphrase</button>
      <form method="post" action="<?= e(url('admin.backups.disable')) ?>"
            data-confirm="Turn off backups? Existing backup files are kept on disk.">
        <?= csrf_field() ?><button class="btn btn--ghost" type="submit">Turn off</button></form>
    </div>
  </section>

  <?php if (!$backups): ?>
  <section class="card">
    <p class="muted">No backups yet. Use “Back up now” above, or wait for the next scheduled one.</p>
  </section>
  <?php else: ?>
  <section class="card card--flush">
      <div class="table-wrap">
        <table class="file-table">
          <thead><tr><th>When (UTC)</th><th>Kind</th><th>Size</th><th>Status</th><th class="col-actions"></th></tr></thead>
          <tbody>
          <?php foreach ($backups as $b): ?>
            <tr>
              <td class="nowrap"><?= e(substr($b['created_at'], 0, 16)) ?></td>
              <td><?= e($kindLabel[$b['kind']] ?? $b['kind']) ?></td>
              <td class="col-size"><?= $b['status'] === 'ok' ? e(\BlaCloud\View::bytes((int) $b['size_bytes'])) : '—' ?></td>
              <td><?= $b['status'] === 'ok'
                    ? '<span class="ok-text">' . icon('check') . ' OK</span>'
                    : '<span class="bad-text">Failed' . ($b['error'] !== '' ? ': ' . e($b['error']) : '') . '</span>' ?></td>
              <td class="col-actions">
                <?php if ($b['status'] === 'ok'): ?>
                  <a class="icon-btn" href="<?= e(url('admin.backups.download', ['id' => $b['id']])) ?>" title="Download"><?= icon('download') ?></a>
                  <button type="button" class="icon-btn" title="Verify (restore drill)" data-backup
                    data-id="<?= (int) $b['id'] ?>" data-name="<?= e($b['filename']) ?>" data-action="verify"><?= icon('shield') ?></button>
                  <button type="button" class="icon-btn" title="Restore" data-backup
                    data-id="<?= (int) $b['id'] ?>" data-name="<?= e($b['filename']) ?>" data-action="restore"><?= icon('undo') ?></button>
                <?php endif; ?>
                <form method="post" action="<?= e(url('admin.backups.delete')) ?>" class="inline-form"
                      data-confirm="Delete this backup file? This cannot be undone.">
                  <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
                  <button class="icon-btn icon-btn--danger" type="submit" title="Delete"><?= icon('trash') ?></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
  </section>
  <?php endif; ?>
  <?php endif; ?>
</div>

<dialog id="dlg-rotate" class="dialog">
  <form method="post" action="<?= e(url('admin.backups.rotate')) ?>" class="form">
    <?= csrf_field() ?>
    <h2>New backup passphrase</h2>
    <p class="hint">Backups already made still need the old passphrase to restore. Only backups made after this apply.</p>
    <label class="field"><span>New passphrase <small>(leave blank to generate one)</small></span>
      <input name="passphrase" type="password" minlength="12" autocomplete="new-password"></label>
    <div class="actions"><button type="button" class="btn btn--ghost" data-close>Cancel</button>
      <button class="btn btn--primary" type="submit">Set passphrase</button></div>
  </form>
</dialog>

<dialog id="dlg-verify-backup" class="dialog">
  <form method="post" action="<?= e(url('admin.backups.verify')) ?>" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="id" data-f="id">
    <h2>Verify a backup</h2>
    <p class="hint">Decrypts and checks <span data-backup-name></span> without touching anything live — a "restore drill".</p>
    <label class="field"><span>Passphrase</span><input name="passphrase" type="password" required autocomplete="off"></label>
    <div class="actions"><button type="button" class="btn btn--ghost" data-close>Cancel</button>
      <button class="btn btn--primary" type="submit" data-busy="Checking…">Verify</button></div>
  </form>
</dialog>

<dialog id="dlg-restore-backup" class="dialog">
  <form method="post" action="<?= e(url('admin.backups.restore')) ?>" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="id" data-f="id">
    <h2>Restore <span data-backup-name></span></h2>
    <p class="hint"><strong>This replaces the database and everyone's files with what's in this backup.</strong>
      What's here now is saved as a safety backup first, in case you need to undo this.</p>
    <label class="field"><span>Passphrase</span><input name="passphrase" type="password" required autocomplete="off"></label>
    <label class="field"><span>Type RESTORE to confirm</span><input name="confirm" required autocomplete="off"></label>
    <div class="actions"><button type="button" class="btn btn--ghost" data-close>Cancel</button>
      <button class="btn btn--danger" type="submit" data-busy="Restoring…">Restore</button></div>
  </form>
</dialog>
